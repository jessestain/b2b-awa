<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Cron;

use GrupoAwamotos\B2B\Helper\Config as B2bConfig;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ErpCustomerSyncStatus;
use GrupoAwamotos\B2B\Model\Customer\B2bGroupIds;
use GrupoAwamotos\B2B\Model\Sectra\OrderImportGate;
use GrupoAwamotos\B2B\Model\Sectra\ProspectPipeline;
use GrupoAwamotos\B2B\Model\Sectra\SectraImportStatus;
use GrupoAwamotos\B2B\Model\Sectra\StuckOrderCleanup;
use GrupoAwamotos\ERPIntegration\Helper\Data as ErpHelper;
use GrupoAwamotos\ERPIntegration\Model\CronFileLock;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Polls Sectra validador for pending B2B prospects and releases held orders.
 */
class SyncSectraProspectStatus
{
    private const LOCK_NAME = 'grupoawamotos_b2b_sync_sectra_prospect_status';
    private const OC_ORDER_ID_OFFSET = 200000;

    public function __construct(
        private readonly ErpHelper $erpHelper,
        private readonly B2bConfig $b2bConfig,
        private readonly ProspectPipeline $prospectPipeline,
        private readonly OrderImportGate $orderImportGate,
        private readonly StuckOrderCleanup $stuckOrderCleanup,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->erpHelper->isEnabled()) {
            return;
        }

        if (!$this->hasPendingWork()) {
            return;
        }

        $lockHandle = CronFileLock::acquire(self::LOCK_NAME);
        if ($lockHandle === null) {
            $this->logger->info('[B2B-Sectra] Cron validação prospect já em execução — pulando.');
            return;
        }

        try {
            $validation = $this->prospectPipeline->pollPendingValidations();
            $released = $this->orderImportGate->backfillOrderImportStatus();
            $dryRun = $this->b2bConfig->isSectraCancelStuckDryRun();
            $cancelResult = $this->stuckOrderCleanup->cancelOrdersForUnvalidatedCustomers($dryRun);
            $imported = $this->orderImportGate->syncImportedOrderFlags();

            if ($validation['validated'] > 0 || $validation['still_pending'] > 0 || $released > 0 || $imported > 0
                || $cancelResult['cancelled'] > 0 || count($cancelResult['candidates']) > 0
            ) {
                $this->logger->info('[B2B-Sectra] Cron validação prospect', [
                    'validated' => $validation['validated'],
                    'still_pending' => $validation['still_pending'],
                    'orders_released_for_import' => $released,
                    'cancel_dry_run' => $dryRun,
                    'cancel_candidates' => count($cancelResult['candidates']),
                    'orders_cancelled' => $cancelResult['cancelled'],
                    'orders_skipped' => count($cancelResult['skipped']),
                    'orders_marked_imported' => $imported,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[B2B-Sectra] Cron validação prospect falhou: ' . $e->getMessage());
        } finally {
            CronFileLock::release($lockHandle);
        }
    }

    private function hasPendingWork(): bool
    {
        $connection = $this->resourceConnection->getConnection();

        $pendingCustomers = (int) $connection->fetchOne(
            "SELECT COUNT(*)
             FROM customer_entity_varchar cev
             INNER JOIN eav_attribute ea ON ea.attribute_id = cev.attribute_id
                 AND ea.attribute_code = 'erp_customer_sync_status'
             INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
                 AND et.entity_type_code = 'customer'
             WHERE cev.value IN (?, ?)",
            [
                ErpCustomerSyncStatus::CUSTOMER_PENDING_ERP_VALIDATION,
                ErpCustomerSyncStatus::AWAITING_ERP_VALIDATION,
            ]
        );
        if ($pendingCustomers > 0) {
            return true;
        }

        $groupIn = B2bGroupIds::toSqlInList($this->resourceConnection);
        $stuckOrders = (int) $connection->fetchOne(
            "SELECT COUNT(*)
             FROM sales_order so
             INNER JOIN customer_entity ce ON ce.entity_id = so.customer_id
             WHERE ce.group_id IN ($groupIn)
               AND so.state NOT IN ('canceled', 'closed', 'complete')
               AND (so.sectra_import_status IS NULL
                    OR so.sectra_import_status IN (?, ?))",
            [
                SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
                SectraImportStatus::ORDER_BLOCKED_CUSTOMER_NOT_VALIDATED,
            ]
        );
        if ($stuckOrders > 0) {
            return true;
        }

        $importedFlags = (int) $connection->fetchOne(
            "SELECT COUNT(*)
             FROM oc_order_imported oi
             INNER JOIN sales_order so ON so.entity_id + " . self::OC_ORDER_ID_OFFSET . " = oi.order_id
             WHERE so.sectra_import_status IS NULL
                OR so.sectra_import_status IN (?, ?)",
            [
                SectraImportStatus::READY_FOR_IMPORT,
                SectraImportStatus::AWAITING_CUSTOMER_VALIDATION,
            ]
        );

        return $importedFlags > 0;
    }
}
