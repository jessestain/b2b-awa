<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Cron;

use GrupoAwamotos\ERPIntegration\Api\ProductSyncInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Psr\Log\LoggerInterface;

class SyncProducts
{
    private ProductSyncInterface $productSync;
    private Helper $helper;
    private LoggerInterface $logger;

    public function __construct(
        ProductSyncInterface $productSync,
        Helper $helper,
        LoggerInterface $logger
    ) {
        $this->productSync = $productSync;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->helper->isProductSyncEnabled()) {
            return;
        }

        try {
            $result = $this->productSync->syncDelta();
            $this->logger->info('[ERP Cron] Product sync finished.', $result);
        } catch (\Throwable $e) {
            $this->logger->error('[ERP Cron] SyncProducts failed: ' . $e->getMessage(), ['exception' => $e]);
            return;
        }

        $this->reconcileScope();
    }

    /**
     * Reconciliacao do escopo — OS DOIS LADOS.
     *
     * BUG FECHADO EM 06/10/2026: o cron chamava apenas `syncDelta()`. Nenhum dos
     * lados do escopo rodava, entao produto que saia do Sectra continuava a venda e
     * produto que voltava nunca voltava. Foi assim que 27 itens ficaram fora da loja
     * com o Sectra dizendo CCKATIVO='S' AND CKCOMERCIALIZA='S'. O comando de console
     * tinha `--deactivate-stale` desde sempre, mas dependia de alguem lembrar de roda-lo.
     *
     * Os dois lados rodam na MESMA passada, de proposito: separa-los foi o que criou
     * a assimetria. Cada lado tem sua guarda (ERP vazio => nao faz nada; preco de
     * placeholder => nao reativa), entao uma falha de conectividade nao esvazia nem
     * enche a loja.
     */
    private function reconcileScope(): void
    {
        try {
            $out = $this->productSync->reconcileInactiveProducts(false);
            if (!empty($out['skip_reason'])) {
                $this->logger->warning('[ERP Cron] Deactivation skipped.', [
                    'reason' => $out['skip_reason'],
                    'all_erp' => $out['all_erp'] ?? null,
                    'active_erp' => $out['active_erp'] ?? null,
                ]);
            } elseif ((int) ($out['candidates'] ?? 0) > 0) {
                $this->logger->info('[ERP Cron] Out-of-scope products disabled.', [
                    'candidates' => $out['candidates'],
                    'deactivated' => $out['deactivated'],
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[ERP Cron] Deactivation failed: ' . $e->getMessage(), ['exception' => $e]);
        }

        try {
            $in = $this->productSync->reconcileReactivation(false);
            if (!empty($in['skip_reason'])) {
                $this->logger->warning('[ERP Cron] Reactivation skipped.', [
                    'reason' => $in['skip_reason'],
                    'all_erp' => $in['all_erp'] ?? null,
                    'active_erp' => $in['active_erp'] ?? null,
                ]);
            } else {
                $this->logger->info('[ERP Cron] Scope reactivation evaluated.', [
                    'candidates' => $in['candidates'],
                    'reactivated' => $in['reactivated'],
                    'blocked_by_price' => $in['blocked_by_price'],
                    // Visivel de proposito: sao itens que o Sectra manda vender e a loja
                    // nao vende. Sem isto, o numero fica escondido no banco.
                    'blocked_sample' => array_slice($in['blocked_skus'], 0, 20),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[ERP Cron] Reactivation failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
