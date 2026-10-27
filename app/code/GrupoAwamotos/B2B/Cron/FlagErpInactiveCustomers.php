<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Cron;

use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\Customer\CustomerErpStatusFlagService;
use Psr\Log\LoggerInterface;

/**
 * Desligado por padrao (grupoawamotos_b2b/customer_erp_status/flag_enabled=0) e em
 * dry-run quando ligado (flag_dry_run=1) — mesmo padrao do portao de publicacao de
 * produto. Nunca bloqueia cliente, so marca em b2b_admin_notes para revisao humana.
 */
class FlagErpInactiveCustomers
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerErpStatusFlagService $service,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isCustomerErpStatusFlagEnabled()) {
            return;
        }

        try {
            $dryRun = $this->config->isCustomerErpStatusFlagDryRun();
            $maxRatio = $this->config->getCustomerErpStatusFlagMaxRatio();
            $r = $this->service->run($dryRun, $maxRatio);

            if (!empty($r['skip_reason'])) {
                $this->logger->warning('[B2B Cron] ErpStatusFlag skipped.', [
                    'reason' => $r['skip_reason'],
                    'candidates' => $r['candidates'] ?? null,
                ]);
                return;
            }

            $this->logger->info('[B2B Cron] ErpStatusFlag evaluated.', [
                'dry_run' => $dryRun,
                'total_checked' => $r['total_checked'],
                'candidates' => $r['candidates'],
                'flagged' => $r['flagged'],
                'already_flagged' => $r['already_flagged'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[B2B Cron] FlagErpInactiveCustomers failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
