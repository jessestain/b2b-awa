<?php
declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Cron;

use GrupoAwamotos\ERPIntegration\Model\ErpOrderReconciler;
use Psr\Log\LoggerInterface;

/**
 * Rede de seguranca da importacao de pedidos.
 *
 * O ACK depende de um script que roda no Windows Server do Sectra. Quando ele
 * para -- ou quando so o ACK falha -- o Magento nao percebe nada: o pedido fica
 * em /pending para sempre e ninguem e avisado. Foi assim que 8 pedidos ficaram
 * presos entre 04/09 e 24/09/2026.
 *
 * Este cron fecha o ciclo a cada 30 minutos usando o ERP como fonte da verdade.
 *
 * Nunca forca: se a estrutura de itens do pedido do ERP difere da do Magento,
 * o pedido e apenas sinalizado no log para conferencia humana. Auto-reconciliar
 * esse caso seria afirmar que os dois pedidos sao o mesmo sem prova.
 */
class ReconcileOrders
{
    private ErpOrderReconciler $reconciler;
    private LoggerInterface $logger;

    public function __construct(
        ErpOrderReconciler $reconciler,
        LoggerInterface $logger
    ) {
        $this->reconciler = $reconciler;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $relatorio = $this->reconciler->reconciliar(true, 200, false);

            if ($relatorio['reconciliados']) {
                $this->logger->info(sprintf(
                    '[ERP Reconcile] %d pedido(s) reconciliado(s) automaticamente pelo cron: %s',
                    count($relatorio['reconciliados']),
                    implode(', ', array_column($relatorio['reconciliados'], 'increment_id'))
                ));
            }

            /* ALERTA COM DEDUPLICACAO (04/10/2026). Este cron roda a cada 30 minutos e
               sinalizava os MESMOS pedidos em toda execucao: 3 pedidos x 2 mensagens x 48
               execucoes = ~288 linhas/dia de ruido para um caso que, por definicao do proprio
               reconciler, aguarda CONFERENCIA HUMANA e pode demorar dias. Ruido nesse volume
               faz o alerta real se perder no log. Agora: avisa quando o conjunto MUDA
               (imediato) e, sem mudanca, lembra uma vez por dia. A primeira ocorrencia SEMPRE
               avisa -- nada e escondido, e o alerta diario mantem o caso visivel.
               O estado fica em arquivo sob var/ (nao em cache injetado) DE PROPOSITO: um
               argumento novo no construtor exigiria setup:di:compile, e o DI compilado
               quebraria a instanciacao do cron. Se o arquivo falhar, cai no comportamento
               antigo e avisa sempre -- nunca perder alerta por causa do mecanismo. */
            $pendentes = $relatorio['requer_conferencia'] ?? [];
            if ($pendentes) {
                $assinatura = sha1((string) json_encode(array_map(
                    static fn (array $p): array => [
                        (string) ($p['increment_id'] ?? ''),
                        (int) ($p['entity_id'] ?? 0),
                        (int) ($p['erp_codigo'] ?? 0),
                        (int) ($p['linhas_magento'] ?? 0),
                        (int) ($p['linhas_erp'] ?? 0),
                    ],
                    $pendentes
                )));

                $mudou = true;
                $quandoAnterior = 0;
                $arquivo = BP . '/var/awa-reconcile-estado.json';
                $bruto = @file_get_contents($arquivo);
                if (is_string($bruto) && $bruto !== '') {
                    $anterior = json_decode($bruto, true);
                    if (is_array($anterior)) {
                        $quandoAnterior = (int) ($anterior['quando'] ?? 0);
                        $mudou = $assinatura !== (string) ($anterior['assinatura'] ?? '');
                    }
                }
                $passouLembrete = $quandoAnterior <= 0 || (time() - $quandoAnterior) >= 86400;

                if ($mudou || $passouLembrete) {
                    $this->logger->warning(sprintf(
                        '[ERP Reconcile] CONFERIR %d pedido(s) -- %s. Existe(m) no ERP mas a estrutura'
                        . ' de itens difere; NADA foi gravado. Conferencia humana necessaria.',
                        count($pendentes),
                        $mudou ? 'conjunto mudou desde a ultima verificacao' : 'lembrete diario (sem mudanca)'
                    ));
                    foreach ($pendentes as $pendente) {
                        $this->logger->warning(sprintf(
                            '[ERP Reconcile]   pedido %s (entity_id %d): ERP CODIGO=%d,'
                            . ' %d linha(s) no Magento contra %d no ERP.',
                            $pendente['increment_id'],
                            $pendente['entity_id'],
                            $pendente['erp_codigo'],
                            $pendente['linhas_magento'],
                            $pendente['linhas_erp']
                        ));
                    }
                } else {
                    $this->logger->info(sprintf(
                        '[ERP Reconcile] %d pedido(s) aguardando conferencia humana; sem mudanca'
                        . ' desde %s e alerta ja emitido.',
                        count($pendentes),
                        date('Y-m-d H:i', $quandoAnterior)
                    ));
                }

                @file_put_contents(
                    $arquivo,
                    (string) json_encode(['assinatura' => $assinatura, 'quando' => time(), 'pedidos' => array_column($pendentes, 'increment_id')]),
                    LOCK_EX
                );
            }

            foreach ($relatorio['erros'] as $erro) {
                $this->logger->error(sprintf(
                    '[ERP Reconcile] falha ao reconciliar %s: %s',
                    $erro['increment_id'],
                    (string) $erro['erro']
                ));
            }
        } catch (\Throwable $e) {
            // Um erro da integracao nunca deve derrubar a fila de crons.
            $this->logger->error('[ERP Reconcile] cron falhou: ' . $e->getMessage());
        }
    }
}