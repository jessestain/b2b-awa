<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Cron;

use GrupoAwamotos\ERPIntegration\Model\Rfm\Calculator as RfmCalculator;
use GrupoAwamotos\ERPIntegration\Model\Coupon\Generator as CouponGenerator;
use GrupoAwamotos\SmartSuggestions\Api\WhatsappSenderInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron Job - Send WhatsApp Re-engagement Messages
 *
 * Sends WhatsApp messages with coupons to at-risk customers via the unified
 * SmartSuggestions WhatsappSenderInterface.
 */
class SendWhatsAppReengagement
{
    public function __construct(
        private readonly RfmCalculator $rfmCalculator,
        private readonly CouponGenerator $couponGenerator,
        private readonly WhatsappSenderInterface $whatsappSender,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Helper $helper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->helper->isWhatsAppReengagementEnabled()) {
            return;
        }

        try {
            $atRiskCustomers = $this->rfmCalculator->getAtRiskCustomers(15);

            if (empty($atRiskCustomers)) {
                $this->logger->info('[WhatsApp Cron] No at-risk customers found.');
                return;
            }

            $sentCount = 0;
            $errorCount = 0;
            $validDays = $this->helper->getCouponValidDays();

            foreach ($atRiskCustomers as $customerData) {
                try {
                    $phoneNumber = $this->getCustomerPhone($customerData);
                    if (!$phoneNumber) {
                        continue;
                    }

                    $magentoCustomerId = $customerData['magento_customer_id'] ?? null;
                    if (!$magentoCustomerId) {
                        continue;
                    }

                    $customer = $this->customerRepository->getById($magentoCustomerId);
                    $segment = $customerData['segment'] ?? 'at_risk';

                    // LGPD: re-engagement e conteudo de marketing — so envia com opt-in
                    // explicito de WhatsApp (whatsapp_optin=1), separado do consentimento
                    // de e-mail/newsletter.
                    if (!$this->hasWhatsAppOptIn($customer)) {
                        $this->logger->info(sprintf(
                            '[WhatsApp Cron] Skipped customer without WhatsApp opt-in (LGPD): %d',
                            (int) $magentoCustomerId
                        ));
                        continue;
                    }

                    $coupon = $this->couponGenerator->generateForCustomer(
                        $magentoCustomerId,
                        $segment,
                        null,
                        $validDays
                    );

                    if (!$coupon) {
                        continue;
                    }

                    $message = $this->buildReengagementMessage(
                        $customer->getFirstname(),
                        $coupon['coupon_code'],
                        $coupon['discount_percent'],
                        $validDays
                    );

                    $result = $this->whatsappSender->sendMessage($phoneNumber, $message);

                    if ($result['success'] ?? false) {
                        $sentCount++;
                    } else {
                        $errorCount++;
                    }

                    usleep(1000000); // 1s rate limiting
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->error(sprintf(
                        '[WhatsApp Cron] Error processing customer: %s',
                        $e->getMessage()
                    ));
                }
            }

            $this->logger->info(sprintf(
                '[WhatsApp Cron] Campaign completed: %d sent, %d errors',
                $sentCount,
                $errorCount
            ));
        } catch (\Exception $e) {
            $this->logger->error('[WhatsApp Cron] Campaign error: ' . $e->getMessage());
        }
    }

    private function buildReengagementMessage(
        string $firstName,
        string $couponCode,
        int $discountPercent,
        int $validDays
    ): string {
        return "Olá {$firstName}! 👋\n\n"
            . "Sentimos sua falta! Preparamos uma oferta especial exclusiva para você:\n\n"
            . "🎁 *{$discountPercent}% de desconto* em todo o site!\n"
            . "Use o cupom: *{$couponCode}*\n"
            . "Válido por {$validDays} dias.\n\n"
            . "Aproveite e volte a comprar com a gente! 🏍️";
    }

    private function getCustomerPhone(array $customerData): ?string
    {
        if (!empty($customerData['celular'])) {
            return $customerData['celular'];
        }
        if (!empty($customerData['telefone'])) {
            return $customerData['telefone'];
        }
        return null;
    }

    /**
     * LGPD: re-engagement is marketing content and requires explicit WhatsApp opt-in
     * (customer attribute whatsapp_optin), independent from e-mail consent.
     */
    public function hasWhatsAppOptIn(\Magento\Customer\Api\Data\CustomerInterface $customer): bool
    {
        $optin = $customer->getCustomAttribute('whatsapp_optin');

        return $optin !== null && (int) $optin->getValue() === 1;
    }
}
