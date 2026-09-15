<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Observer;

use GrupoAwamotos\B2B\Model\ApprovedCustomerErpSync;
use GrupoAwamotos\B2B\Observer\ErpApprovalSyncObserver;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \GrupoAwamotos\B2B\Observer\ErpApprovalSyncObserver
 */
class ErpApprovalSyncObserverTest extends TestCase
{
    private ApprovedCustomerErpSync&MockObject $approvedCustomerErpSync;
    private LoggerInterface&MockObject $logger;
    private ErpApprovalSyncObserver $observer;

    protected function setUp(): void
    {
        $this->approvedCustomerErpSync = $this->createMock(ApprovedCustomerErpSync::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new ErpApprovalSyncObserver($this->approvedCustomerErpSync, $this->logger);
    }

    public function testDelegatesApprovalToAutonomousSectraWorkflow(): void
    {
        $this->approvedCustomerErpSync->expects($this->once())
            ->method('syncApprovedCustomer')
            ->with(9107, 6)
            ->willReturn([
                'success' => true,
                'customer_id' => 9107,
                'cnpj' => '12345678000195',
                'cnpj_source' => 'b2b_cnpj',
                'erp_code' => 19195,
                'action' => ApprovedCustomerErpSync::ACTION_CREATED_ERP_PROSPECT,
                'erp_customer_sync_status' => 'customer_validated_in_erp',
                'message' => 'Prospect criado e validado.',
                'last_sync_at_updated' => true,
            ]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Sectra workflow completed'),
                $this->callback(static function (array $context): bool {
                    return $context['customer_id'] === 9107
                        && $context['erp_code'] === 19195
                        && $context['action'] === ApprovedCustomerErpSync::ACTION_CREATED_ERP_PROSPECT;
                })
            );

        $this->observer->execute($this->createObserver(9107, 6));
    }

    public function testErpFailureDoesNotEscapeApprovalObserver(): void
    {
        $this->approvedCustomerErpSync->expects($this->once())
            ->method('syncApprovedCustomer')
            ->with(9107, 6)
            ->willThrowException(new \RuntimeException('ERP indisponível'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('ERP indisponível'));

        $this->observer->execute($this->createObserver(9107, 6));
    }

    public function testMissingCustomerIdSkipsSectraWorkflow(): void
    {
        $this->approvedCustomerErpSync->expects($this->never())->method('syncApprovedCustomer');
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('ErpApprovalSyncObserver: No customer ID provided');

        $this->observer->execute($this->createObserver(0, 6));
    }

    private function createObserver(int $customerId, int $groupId): Observer
    {
        return new Observer([
            'event' => new Event([
                'customer_id' => $customerId,
                'new_group_id' => $groupId,
            ]),
        ]);
    }
}
