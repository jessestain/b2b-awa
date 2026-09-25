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

            foreach ($relatorio['requer_conferencia'] as $pendente) {
                $this->logger->warning(sprintf(
                    '[ERP Reconcile] CONFERIR pedido %s (entity_id %d): existe no ERP como CODIGO=%d'
                    . ' mas a estrutura difere (%d linha(s) no Magento contra %d no ERP). Nada foi gravado.',
                    $pendente['increment_id'],
                    $pendente['entity_id'],
                    $pendente['erp_codigo'],
                    $pendente['linhas_magento'],
                    $pendente['linhas_erp']
                ));
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