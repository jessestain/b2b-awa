<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Observer;

use GrupoAwamotos\B2B\Helper\Config as B2BConfig;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\Notification\WhatsAppPublisher;
use GrupoAwamotos\B2B\Observer\CustomerRegistrationNotification;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \GrupoAwamotos\B2B\Observer\CustomerRegistrationNotification
 */
class CustomerRegistrationNotificationTest extends TestCase
{
    private CustomerRegistrationNotification $observer;
    private WhatsAppPublisher&MockObject $whatsAppPublisher;
    private AttendantManager&MockObject $attendantManager;
    private B2BConfig&MockObject $b2bConfig;
    private ScopeConfigInterface&MockObject $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->whatsAppPublisher = $this->createMock(WhatsAppPublisher::class);
        $this->attendantManager = $this->createMock(AttendantManager::class);
        $this->b2bConfig = $this->createMock(B2BConfig::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->b2bConfig->method('getPendingGroupId')->willReturn(0);

        $this->observer = new CustomerRegistrationNotification(
            $this->scopeConfig,
            $this->whatsAppPublisher,
            $this->attendantManager,
            $logger,
            $this->b2bConfig
        );
    }

    public function testB2bFormApprovedNotifiesTeamAndSkipsAutoAssign(): void
    {
        $this->enableWhatsApp();

        $this->attendantManager->expects($this->never())->method('autoAssignCustomer');
        $this->whatsAppPublisher->expects($this->once())
            ->method('publish')
            ->with(
                'new_registration',
                $this->callback(static function (array $payload): bool {
                    return ($payload['status_note'] ?? '') === '✅ Auto-aprovado. Conta B2B já está ativa.'
                        && ($payload['cnpj'] ?? '') === '68.597.598/0001-81';
                })
            );

        $this->observer->execute($this->createObserver(
            'grupoawamotos_b2b_registration_submitted',
            4,
            ApprovalStatus::STATUS_APPROVED,
            [
                'register_channel' => 'b2b_register_form',
                'approval_status' => ApprovalStatus::STATUS_APPROVED,
            ]
        ));
    }

    public function testB2bFormPendingAutoAssignsAndNotifiesTeam(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                return in_array($path, [
                    'grupoawamotos_b2b/whatsapp/enabled',
                    'grupoawamotos_b2b/whatsapp/notify_new_registration',
                    'grupoawamotos_b2b/attendants/auto_assign',
                ], true) ? '1' : null;
            }
        );

        $this->attendantManager->expects($this->once())
            ->method('autoAssignCustomer')
            ->with(42)
            ->willReturn(9);
        $this->attendantManager->expects($this->once())
            ->method('getAttendantById')
            ->with(9)
            ->willReturn(['whatsapp' => '5516999999999']);
        $this->whatsAppPublisher->expects($this->once())->method('publishText');
        $this->whatsAppPublisher->expects($this->once())->method('publish');

        $this->observer->execute($this->createObserver(
            'grupoawamotos_b2b_registration_submitted',
            B2BHelper::GROUP_B2B_PENDENTE,
            ApprovalStatus::STATUS_PENDING,
            [
                'register_channel' => 'b2b_register_form',
                'approval_status' => ApprovalStatus::STATUS_PENDING,
            ]
        ));
    }

    public function testMagentoNativeSkipsNonPendingGroup(): void
    {
        $this->enableWhatsApp();
        $this->whatsAppPublisher->expects($this->never())->method('publish');
        $this->attendantManager->expects($this->never())->method('autoAssignCustomer');

        $this->observer->execute($this->createObserver(
            'customer_register_success',
            4,
            '',
            []
        ));
    }

    private function enableWhatsApp(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                return in_array($path, [
                    'grupoawamotos_b2b/whatsapp/enabled',
                    'grupoawamotos_b2b/whatsapp/notify_new_registration',
                ], true) ? '1' : null;
            }
        );
    }

    /**
     * @param array<string, string> $context
     */
    private function createObserver(
        string $eventName,
        int $groupId,
        string $approvalStatus,
        array $context
    ): Observer {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getGroupId')->willReturn($groupId);
        $customer->method('getFirstname')->willReturn('William');
        $customer->method('getLastname')->willReturn('Santos');
        $customer->method('getEmail')->willReturn('loja@example.com');

        $event = new Event([
            'customer' => $customer,
            'approval_status' => $approvalStatus,
            'registration_context' => $context,
            'razao_social' => 'William Pecas',
            'cnpj' => '68.597.598/0001-81',
            'phone' => '16999999999',
            'email' => 'loja@example.com',
        ]);
        $event->setName($eventName);

        return new Observer(['event' => $event]);
    }
}
