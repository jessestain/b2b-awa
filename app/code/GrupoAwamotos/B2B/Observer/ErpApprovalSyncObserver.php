<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Model\ApprovedCustomerErpSync;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends an approved B2B customer through the autonomous Sectra workflow.
 */
class ErpApprovalSyncObserver implements ObserverInterface
{
    public function __construct(
        private readonly ApprovedCustomerErpSync $approvedCustomerErpSync,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $customerId = (int) $observer->getEvent()->getCustomerId();
        $groupId = $observer->getEvent()->getNewGroupId();
        $newGroupId = $groupId !== null ? (int) $groupId : null;

        if ($customerId <= 0) {
            $this->logger->warning('ErpApprovalSyncObserver: No customer ID provided');
            return;
        }

        try {
            $result = $this->approvedCustomerErpSync->syncApprovedCustomer($customerId, $newGroupId);
            $logContext = [
                'customer_id' => $customerId,
                'action' => $result['action'],
                'erp_code' => $result['erp_code'],
                'erp_customer_sync_status' => $result['erp_customer_sync_status'],
            ];

            if (!$result['success']) {
                $this->logger->warning(
                    'ErpApprovalSyncObserver: Sectra workflow did not complete - ' . $result['message'],
                    $logContext
                );
                return;
            }

            $this->logger->info(
                'ErpApprovalSyncObserver: Sectra workflow completed - ' . $result['message'],
                $logContext
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'ErpApprovalSyncObserver: Error syncing customer %d to ERP - %s',
                $customerId,
                $e->getMessage()
            ));
            // ERP failures must not roll back the commercial approval.
        }
    }
}
