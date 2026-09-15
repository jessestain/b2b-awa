<?php

/**
 * Observer para notificar sobre novos cadastros B2B
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\Helper\Config as B2BConfig;
use GrupoAwamotos\B2B\Helper\Data as B2BHelper;
use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\Notification\WhatsAppPublisher;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class CustomerRegistrationNotification implements ObserverInterface
{
    private ScopeConfigInterface $scopeConfig;
    private WhatsAppPublisher $whatsAppPublisher;
    private AttendantManager $attendantManager;
    private LoggerInterface $logger;
    private B2BConfig $b2bConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WhatsAppPublisher $whatsAppPublisher,
        AttendantManager $attendantManager,
        LoggerInterface $logger,
        B2BConfig $b2bConfig
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->whatsAppPublisher = $whatsAppPublisher;
        $this->attendantManager = $attendantManager;
        $this->logger = $logger;
        $this->b2bConfig = $b2bConfig;
    }

    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            $customer = $event->getCustomer();

            if (!$this->isCustomerObject($customer)) {
                return;
            }

            $eventName = (string) $event->getName();
            $context = $event->getData('registration_context');
            $context = is_array($context) ? $context : [];
            $channel = (string) ($context['register_channel'] ?? $event->getData('register_channel') ?? '');
            $isB2bForm = $eventName === 'grupoawamotos_b2b_registration_submitted'
                || $channel === 'b2b_register_form';

            $pendingGroupId = $this->b2bConfig->getPendingGroupId() ?: B2BHelper::GROUP_B2B_PENDENTE;
            if (!$isB2bForm && (int) $customer->getGroupId() !== (int) $pendingGroupId) {
                return;
            }

            $approvalStatus = (string) ($event->getData('approval_status')
                ?? $context['approval_status']
                ?? '');
            $isApproved = $approvalStatus === ApprovalStatus::STATUS_APPROVED;

            $customerData = [
                'customer_id' => $customer->getId(),
                'customer_name' => trim($customer->getFirstname() . ' ' . $customer->getLastname()),
                'email' => (string) ($event->getData('email') ?: $customer->getEmail()),
                'razao_social' => (string) ($event->getData('razao_social') ?? ''),
                'cnpj' => (string) ($event->getData('cnpj') ?? ''),
                'phone' => (string) ($event->getData('phone') ?? ''),
                'status_note' => $isApproved
                    ? '✅ Auto-aprovado. Conta B2B já está ativa.'
                    : '⏳ Aguardando aprovação no painel admin.',
            ];

            if (!$isApproved && $this->isAutoAssignEnabled()) {
                $attendantId = $this->attendantManager->autoAssignCustomer(
                    (int) $customer->getId()
                );

                if ($attendantId) {
                    $this->notifyAttendant($attendantId, $customerData);
                }
            }

            if ($this->isWhatsAppNotificationEnabled('notify_new_registration')) {
                $this->whatsAppPublisher->publish('new_registration', $customerData);
            }
        } catch (\Exception $e) {
            $this->logger->error('B2B Registration Notification Error: ' . $e->getMessage());
        }
    }

    private function isCustomerObject(mixed $customer): bool
    {
        if (!is_object($customer)
            || !method_exists($customer, 'getId')
            || !method_exists($customer, 'getGroupId')
        ) {
            return false;
        }

        return (int) $customer->getId() > 0;
    }

    private function isAutoAssignEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            'grupoawamotos_b2b/attendants/auto_assign',
            ScopeInterface::SCOPE_STORE
        );
    }

    private function isWhatsAppNotificationEnabled(string $type): bool
    {
        $enabled = $this->scopeConfig->getValue(
            'grupoawamotos_b2b/whatsapp/enabled',
            ScopeInterface::SCOPE_STORE
        );

        $typeEnabled = $this->scopeConfig->getValue(
            "grupoawamotos_b2b/whatsapp/{$type}",
            ScopeInterface::SCOPE_STORE
        );

        return $enabled && $typeEnabled;
    }

    private function notifyAttendant(int $attendantId, array $customerData): void
    {
        $attendant = $this->attendantManager->getAttendantById($attendantId);

        if (!$attendant || empty($attendant['whatsapp'])) {
            return;
        }

        $message = "🆕 *Novo Cliente Atribuído*\n\n" .
            "👤 *Nome:* {$customerData['customer_name']}\n" .
            "🏢 *Empresa:* {$customerData['razao_social']}\n" .
            "📧 *Email:* {$customerData['email']}\n" .
            "📱 *Telefone:* {$customerData['phone']}\n\n" .
            "Este cliente foi atribuído a você para atendimento.";

        $this->whatsAppPublisher->publishText($attendant['whatsapp'], $message);
    }
}
