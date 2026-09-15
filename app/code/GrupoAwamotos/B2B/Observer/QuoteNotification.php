<?php

/**
 * Observer para notificar sobre cotações B2B
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use GrupoAwamotos\B2B\Model\Notification\WhatsAppPublisher;
use GrupoAwamotos\B2B\Model\Attendant\AttendantManager;
use Psr\Log\LoggerInterface;

class QuoteNotification implements ObserverInterface
{
    private ScopeConfigInterface $scopeConfig;
    private WhatsAppPublisher $whatsAppPublisher;
    private AttendantManager $attendantManager;
    private CustomerRepositoryInterface $customerRepository;
    private LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WhatsAppPublisher $whatsAppPublisher,
        AttendantManager $attendantManager,
        CustomerRepositoryInterface $customerRepository,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->whatsAppPublisher = $whatsAppPublisher;
        $this->attendantManager = $attendantManager;
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $observer->getEvent()->getQuote();
            $eventType = $observer->getEvent()->getName();

            if (!$quote) {
                return;
            }

            // Determina o tipo de evento
            if (strpos($eventType, 'new') !== false || strpos($eventType, 'submit') !== false) {
                $this->handleNewQuote($quote);
            } elseif (strpos($eventType, 'response') !== false || strpos($eventType, 'replied') !== false) {
                $this->handleQuoteResponse($quote);
            }
        } catch (\Exception $e) {
            $this->logger->error('B2B Quote Notification Error: ' . $e->getMessage());
        }
    }

    private function handleNewQuote($quote): void
    {
        $quoteData = $this->buildQuoteData($quote);

        // Notifica equipe via WhatsApp
        if ($this->isWhatsAppNotificationEnabled('notify_new_quote')) {
            $this->whatsAppPublisher->publish('new_quote', $quoteData);
        }

        // Notifica atendente responsável
        if ($quote->getCustomerId() && $this->isAttendantEnabled()) {
            $this->notifyCustomerAttendant((int) $quote->getCustomerId(), $quoteData, 'new');
        }
    }

    private function handleQuoteResponse($quote): void
    {
        $quoteData = $this->buildQuoteData($quote);

        // Obtém telefone do cliente
        if ($quote->getCustomerId()) {
            try {
                $customer = $this->customerRepository->getById($quote->getCustomerId());
                $phoneAttr = $customer->getCustomAttribute('b2b_phone');
                $quoteData['customer_phone'] = $phoneAttr ? $phoneAttr->getValue() : '';
            } catch (\Exception $e) {
                $quoteData['customer_phone'] = '';
            }
        }

        // Notifica cliente via WhatsApp
        if ($this->isWhatsAppNotificationEnabled('notify_quote_response') && !empty($quoteData['customer_phone'])) {
            $this->whatsAppPublisher->publish('quote_response', $quoteData);
        }
    }

    private function buildQuoteData($quote): array
    {
        $customerId = $quote->getCustomerId();
        $customerName = $quote->getCustomerName() ?? 'Cliente';
        $company = '';

        if ($customerId) {
            try {
                $customer = $this->customerRepository->getById($customerId);
                $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();

                $razaoAttr = $customer->getCustomAttribute('b2b_razao_social');
                if ($razaoAttr) {
                    $company = $razaoAttr->getValue();
                }
            } catch (\Exception $e) {
                $this->logger->warning('[QuoteNotification::buildQuoteData] Exceção ignorada: ' . $e->getMessage());
                // usa valores padrão
            }
        }

        return [
            'quote_id' => $quote->getId(),
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_email' => $quote->getCustomerEmail() ?? '',
            'company' => $company,
            'items_count' => $quote->getItemsCount() ?? 0,
            'estimated_total' => 'R$ ' . number_format((float) ($quote->getGrandTotal() ?? 0), 2, ',', '.'),
            'quoted_total' => 'R$ ' . number_format((float) ($quote->getQuotedTotal() ?? 0), 2, ',', '.'),
            'expires_at' => $quote->getExpiresAt() ? date('d/m/Y', strtotime($quote->getExpiresAt())) : '',
            'status' => $quote->getStatus() ?? 'pending'
        ];
    }

    private function isWhatsAppNotificationEnabled(string $type): bool
    {
        $enabled = $this->scopeConfig->getValue(
            'grupoawamotos_b2b/whatsapp/enabled',
            ScopeInterface::SCOPE_STORE
        );

        // Para quote_response, usa notify_customer_approved como fallback
        if ($type === 'notify_quote_response') {
            $typeEnabled = $this->scopeConfig->getValue(
                "grupoawamotos_b2b/whatsapp/notify_customer_approved",
                ScopeInterface::SCOPE_STORE
            );
        } else {
            $typeEnabled = $this->scopeConfig->getValue(
                "grupoawamotos_b2b/whatsapp/{$type}",
                ScopeInterface::SCOPE_STORE
            );
        }

        return $enabled && $typeEnabled;
    }

    private function isAttendantEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            'grupoawamotos_b2b/attendants/enabled',
            ScopeInterface::SCOPE_STORE
        );
    }

    private function notifyCustomerAttendant(int $customerId, array $quoteData, string $type): void
    {
        $attendant = $this->attendantManager->getCustomerAttendant($customerId);

        if (!$attendant || empty($attendant['whatsapp'])) {
            return;
        }

        $message = "📋 *Nova Cotação do Seu Cliente*\n\n" .
            "🆔 *Cotação:* #{$quoteData['quote_id']}\n" .
            "👤 *Cliente:* {$quoteData['customer_name']}\n" .
            "🏢 *Empresa:* {$quoteData['company']}\n" .
            "📦 *Itens:* {$quoteData['items_count']}\n" .
            "💰 *Valor estimado:* {$quoteData['estimated_total']}\n\n" .
            "Responda pelo painel admin.";

        $this->whatsAppPublisher->publishText($attendant['whatsapp'], $message);
    }
}
