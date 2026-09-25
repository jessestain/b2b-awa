<?php
declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Api\OrderPullInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Reconcilia pedidos que JA existem no Sectra mas continuam pendentes no Magento.
 *
 * Incidente de referencia: 04/09 a 24/09/2026. Oito pedidos presos em
 * GET /V1/erp/orders/pending; quatro deles com pedido correspondente ja criado
 * no ERP.
 *
 * Por que existe
 * --------------
 * A unica coisa que retira um pedido de /pending e o ACK (POST
 * /V1/erp/orders/:id/ack), enviado pelo Sync-MagentoOrders.ps1, que roda no
 * Windows Server do Sectra. Quando ele nao roda -- ou quando apenas o ACK
 * falha/nao e enviado -- o pedido ja esta no ERP e o Magento nunca fica
 * sabendo, entao /pending devolve o mesmo pedido para sempre.
 *
 * CHAVE DE CORRESPONDENCIA (medida, nao suposta)
 * ----------------------------------------------
 * VE_PEDIDO.PEDIDOWEB NAO guarda o increment_id do Magento. Ele guarda a
 * convencao do proprio ERP: 200000 + entity_id -- a mesma de oc_order_imported
 * ("order_id = entity_id + 200000"). Comprovado item a item: o pedido ERP
 * CODIGO=196799 (PEDIDOWEB='200108') tem exatamente os 6 itens e as 51 unidades
 * do pedido Magento entity_id=108 (increment 000000120).
 *
 * Comparar PEDIDOWEB com increment_id nao casa nada, e foi o que me deu um
 * falso "nada a reconciliar" na primeira versao deste reconciliador.
 *
 * VALOR NAO SERVE COMO CRITERIO
 * -----------------------------
 * VLRTOTAL do ERP e sempre maior que o total do Magento -- as linhas medidas
 * ficaram todas em fator 1,1299 (ex.: 20 x 78,66 = 1.573,20 no Magento contra
 * 1.777,51 no ERP). O ERP carrega imposto/condicao comercial na linha. Usar
 * igualdade de valor como gate reprovaria 100% dos casos legitimos. O sinal
 * confiavel e ESTRUTURAL: quantidade de linhas e de unidades.
 *
 * Fonte de verdade
 * ----------------
 * Leitura READ-ONLY no ERP. A escrita acontece apenas no Magento, e pelo mesmo
 * caminho que o ACK usa (OrderPullInterface::acknowledgeOrder), para nao existir
 * uma segunda nocao de "importado" dentro do sistema.
 */
class ErpOrderReconciler
{
    /**
     * Diferenca tolerada entre a contagem de linhas do Magento e a do ERP.
     * Zero e o valor honesto: o ERP pode agrupar/desdobrar itens, mas quando
     * agrupa isso e exatamente o caso que queremos que um humano olhe.
     */
    public const TOLERANCIA_LINHAS = 0;

    /** Deslocamento da convencao do ERP: PEDIDOWEB = OFFSET + entity_id. */
    public const DESLOCAMENTO_ENTITY_ID = 200000;

    private ConnectionInterface $erp;
    private OrderPullInterface $orderPull;
    private ResourceConnection $resource;
    private LoggerInterface $logger;

    public function __construct(
        ConnectionInterface $erp,
        OrderPullInterface $orderPull,
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->erp = $erp;
        $this->orderPull = $orderPull;
        $this->resource = $resource;
        $this->logger = $logger;
    }

    /**
     * Pedidos que o ERP ja registra, indexados pelas referencias web aceitas.
     *
     * Como PEDIDOWEB e varchar(50) e recebeu historico de mais de um formato
     * (com e sem zeros a esquerda), guardamos duas formas por linha: o valor
     * cru e o valor sem zeros a esquerda.
     *
     * @return array<string, array{codigo:int, valor:float|null}>
     */
    public function pedidosNoErp(): array
    {
        $sql = 'SELECT CODIGO, PEDIDOWEB, VLRTOTAL FROM VE_PEDIDO'
            . " WHERE PEDIDOWEB IS NOT NULL AND PEDIDOWEB <> ''";

        $mapa = [];
        foreach ($this->erp->query($sql) as $linha) {
            // O driver do SQL Server nao garante o case do nome da coluna.
            $linha = array_change_key_case($linha, CASE_UPPER);

            $referencia = trim((string) ($linha['PEDIDOWEB'] ?? ''));
            if ($referencia === '') {
                continue;
            }

            $codigo = (int) ($linha['CODIGO'] ?? 0);
            $valor = ($linha['VLRTOTAL'] ?? null) === null ? null : (float) $linha['VLRTOTAL'];
            $registro = ['codigo' => $codigo, 'valor' => $valor];

            foreach ([$referencia, ltrim($referencia, '0')] as $chave) {
                if ($chave === '') {
                    continue;
                }
                // Mesmo pedido em duas linhas do ERP: a de maior CODIGO e a
                // mais recente, e e a que o ACK teria referenciado.
                if (!isset($mapa[$chave]) || $codigo > $mapa[$chave]['codigo']) {
                    $mapa[$chave] = $registro;
                }
            }
        }

        return $mapa;
    }

    /**
     * Referencias pelas quais este pedido do Magento pode aparecer no ERP.
     *
     * @return string[]
     */
    private function referenciasDoPedido(string $incrementId, int $entityId): array
    {
        $referencias = [];
        if ($entityId > 0) {
            $referencias[] = (string) (self::DESLOCAMENTO_ENTITY_ID + $entityId);
        }
        if ($incrementId !== '') {
            $referencias[] = $incrementId;
            $semZeros = ltrim($incrementId, '0');
            if ($semZeros !== '') {
                $referencias[] = $semZeros;
            }
        }

        return array_values(array_unique($referencias));
    }

    /**
     * Estrutura dos itens exatamente como o importador recebe do Magento.
     *
     * O Sync-MagentoOrders.ps1 grava item.sku em VE_PEDIDOITENS.MATERIAL e
     * item.qty em QTDE. Agregamos SKU repetido para que uma mesma peça
     * dividida em linhas continue comparável, mas nunca aceitamos estrutura vazia.
     *
     * @return array<string, float> SKU normalizado => quantidade
     */
    private function estruturaMagento(array $pedido): array
    {
        $estrutura = [];
        foreach (($pedido['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sku = trim((string) ($item['sku'] ?? ''));
            $quantidade = (float) ($item['qty'] ?? 0);
            if ($sku === '' || $quantidade <= 0) {
                continue;
            }
            $chave = strtoupper($sku);
            $estrutura[$chave] = ($estrutura[$chave] ?? 0.0) + $quantidade;
        }
        ksort($estrutura);

        return $estrutura;
    }

    /**
     * @return array<string, float> MATERIAL normalizado => quantidade
     */
    private function estruturaErp(int $codigo): array
    {
        $estrutura = [];
        foreach ($this->erp->query(
            'SELECT MATERIAL, QTDE FROM VE_PEDIDOITENS WHERE PEDIDO = ?',
            [$codigo]
        ) as $linha) {
            $linha = array_change_key_case($linha, CASE_UPPER);
            $material = trim((string) ($linha['MATERIAL'] ?? ''));
            $quantidade = (float) ($linha['QTDE'] ?? 0);
            if ($material === '' || $quantidade <= 0) {
                continue;
            }
            $chave = strtoupper($material);
            $estrutura[$chave] = ($estrutura[$chave] ?? 0.0) + $quantidade;
        }
        ksort($estrutura);

        return $estrutura;
    }

    /**
     * Comparação estrutural fail-closed: SKU/MATERIAL e quantidade precisam bater.
     */
    private function estruturasConferem(array $magento, array $erp): bool
    {
        if ($magento === [] || $erp === [] || array_keys($magento) !== array_keys($erp)) {
            return false;
        }
        foreach ($magento as $sku => $quantidade) {
            if (abs($quantidade - $erp[$sku]) > 0.0001) {
                return false;
            }
        }

        return true;
    }

    private function quantidadeTotal(array $estrutura): float
    {
        return array_sum($estrutura);
    }

    /**
     * @return array<string, mixed> relatorio estruturado, usado pela CLI e pelo cron
     */
    public function reconciliar(bool $aplicar = false, int $limite = 200, bool $forcar = false): array
    {
        $relatorio = [
            'aplicado' => $aplicar,
            'forcado' => $forcar,
            'pendentes_no_magento' => 0,
            'pendentes_que_ja_estao_no_erp' => 0,
            'a_reconciliar' => [],
            'reconciliados' => [],
            'requer_conferencia' => [],
            'erros' => [],
        ];

        $noErp = $this->pedidosNoErp();
        $pendentes = $this->pedidosPendentes($limite);
        $relatorio['pendentes_no_magento'] = count($pendentes);

        foreach ($pendentes as $pedido) {
            $incrementId = (string) ($pedido['increment_id'] ?? '');
            $entityId = (int) ($pedido['magento_id'] ?? 0);
            if ($incrementId === '' && $entityId <= 0) {
                continue;
            }

            $achado = null;
            $referencia = '';
            foreach ($this->referenciasDoPedido($incrementId, $entityId) as $candidata) {
                if (isset($noErp[$candidata])) {
                    $achado = $noErp[$candidata];
                    $referencia = $candidata;
                    break;
                }
            }
            if ($achado === null) {
                continue;
            }

            $relatorio['pendentes_que_ja_estao_no_erp']++;

            $codigoErp = (int) $achado['codigo'];
            $estruturaMagento = $this->estruturaMagento($pedido);
            $estruturaErp = $this->estruturaErp($codigoErp);
            $linhasMagento = count($estruturaMagento);
            $linhasErp = count($estruturaErp);
            $quantidadeMagento = $this->quantidadeTotal($estruturaMagento);
            $quantidadeErp = $this->quantidadeTotal($estruturaErp);
            $divergenciaEstrutura = !$this->estruturasConferem($estruturaMagento, $estruturaErp);

            $item = [
                'increment_id' => $incrementId,
                'entity_id' => $entityId,
                'referencia_erp' => $referencia,
                'erp_codigo' => $codigoErp,
                'valor_magento' => isset($pedido['grand_total']) ? (float) $pedido['grand_total'] : null,
                'valor_erp' => $achado['valor'],
                'linhas_magento' => $linhasMagento,
                'linhas_erp' => $linhasErp,
                'quantidade_magento' => $quantidadeMagento,
                'quantidade_erp' => $quantidadeErp,
                'estrutura_magento' => $estruturaMagento,
                'estrutura_erp' => $estruturaErp,
                'divergencia_estrutura' => $divergenciaEstrutura,
            ];

            // Estrutura diferente = o pedido do ERP pode ser um agrupamento
            // comercial, nao necessariamente este pedido. Nao decidimos isso
            // sozinhos: fica para conferencia humana, salvo --forcar.
            if ($divergenciaEstrutura && !$forcar) {
                $relatorio['requer_conferencia'][] = $item;
                $this->logger->warning(sprintf(
                    '[ERP Reconcile] pedido %s (entity_id %d) casa com ERP CODIGO=%d mas a estrutura difere:'
                    . ' %d linha(s)/%s no Magento contra %d no ERP. Nao reconciliado.',
                    $incrementId,
                    $entityId,
                    $codigoErp,
                    $linhasMagento,
                    $incrementId,
                    $linhasErp
                ));
                continue;
            }

            if (!$aplicar) {
                $relatorio['a_reconciliar'][] = $item;
                continue;
            }

            $comentario = sprintf(
                '[ERP] Reconciliacao automatica: o pedido ja existia no ERP (VE_PEDIDO.CODIGO=%d,'
                . ' PEDIDOWEB=%s) e o ACK nunca retornou. Importacao confirmada pelo proprio ERP.',
                $codigoErp,
                $referencia
            );
            if ($divergenciaEstrutura) {
                $comentario .= sprintf(
                    ' Reconciliado com --forcar: a estrutura difere (%d linha(s) no Magento contra %d no ERP).',
                    $linhasMagento,
                    $linhasErp
                );
            }

            try {
                $this->orderPull->acknowledgeOrder($incrementId, (string) $codigoErp, $comentario);
                $item['comentario'] = $comentario;
                $relatorio['reconciliados'][] = $item;
                $this->logger->info(
                    '[ERP Reconcile] pedido ' . $incrementId . ' reconciliado (CODIGO=' . $codigoErp . ')'
                );
            } catch (\Throwable $e) {
                $item['erro'] = $e->getMessage();
                $relatorio['erros'][] = $item;
                $this->logger->error(
                    '[ERP Reconcile] falha ao reconciliar ' . $incrementId . ': ' . $e->getMessage()
                );
            }
        }

        return $relatorio;
    }

    /**
     * Pedidos que o Magento ainda considera pendentes -- a MESMA definicao que
     * /V1/erp/orders/pending usa (respeita held, cancelado e registro B2B do
     * cliente). Essa regra nao deve ser reimplementada aqui.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pedidosPendentes(int $limite): array
    {
        $retorno = $this->orderPull->getPendingOrders($limite);

        // O contrato devolve um unico bloco dentro de uma lista, para o
        // ServiceOutputProcessor do Magento preservar as chaves nomeadas.
        $bloco = (isset($retorno[0]) && is_array($retorno[0])) ? $retorno[0] : $retorno;

        return (isset($bloco['orders']) && is_array($bloco['orders'])) ? $bloco['orders'] : [];
    }
}