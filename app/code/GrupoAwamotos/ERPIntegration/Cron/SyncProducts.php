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
    private SyncOpenCartBridge $openCartBridge;

    public function __construct(
        ProductSyncInterface $productSync,
        Helper $helper,
        LoggerInterface $logger,
        SyncOpenCartBridge $openCartBridge
    ) {
        $this->productSync = $productSync;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->openCartBridge = $openCartBridge;
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
        $this->syncProductBridge();
    }

    private function syncProductBridge(): void
    {
        try {
            $changes = $this->openCartBridge->syncProductBridge();
            if ($changes > 0) {
                $this->logger->info('[ERP Cron] Product bridge synced.', ['changes' => $changes]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[ERP Cron] Product bridge sync failed: ' . $e->getMessage(), ['exception' => $e]);
        }
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

        $this->reconcilePublicationGate();
    }

    /**
     * Portao de publicacao: o lado da LOJA (o que esta habilitado e nao pode ficar).
     * Desligado por padrao; ligado e ainda em dry-run por padrao, entao a primeira
     * execucao so escreve no log o que faria.
     */
    private function reconcilePublicationGate(): void
    {
        if (!$this->helper->isPublicationGateEnabled()) {
            return;
        }

        try {
            $dryRun = $this->helper->isPublicationGateDryRun();
            $gate = $this->productSync->reconcilePublicationGate($dryRun);

            if (!empty($gate['skip_reason'])) {
                $this->logger->warning('[ERP Cron] Publication gate skipped.', [
                    'reason' => $gate['skip_reason'],
                    'candidates' => $gate['candidates'] ?? null,
                    'enabled_total' => $gate['enabled_total'] ?? null,
                ]);
                return;
            }

            $this->logger->info('[ERP Cron] Publication gate evaluated.', [
                'dry_run' => $dryRun,
                'candidates' => $gate['candidates'],
                'missing_from_erp' => $gate['missing_from_erp'],
                'placeholder_price' => $gate['placeholder_price'],
                'low_price_review' => $gate['low_price_review'],
                'disabled' => $gate['disabled'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[ERP Cron] Publication gate failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
