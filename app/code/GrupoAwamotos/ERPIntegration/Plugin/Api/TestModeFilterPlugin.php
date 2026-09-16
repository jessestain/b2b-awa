<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Plugin\Api;

use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\WhatsApp\ZApiClient;
use Psr\Log\LoggerInterface;

/**
 * WhatsApp Test Mode Filter
 *
 * When test_mode is enabled, only phone numbers in the whitelist
 * are allowed to receive messages. All other numbers are silently
 * logged and blocked. This prevents accidental messages to real
 * customers during development or staging.
 */
class TestModeFilterPlugin
{
    public function __construct(
        private readonly Helper $helper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param ZApiClient $subject
     * @param callable $proceed
     * @param string $phoneNumber
     * @param string $message
     * @return array|null
     */
    public function aroundSendTextMessage(
        ZApiClient $subject,
        callable $proceed,
        string $phoneNumber,
        string $message
    ): ?array {
        if (!$this->isPhoneAllowed($phoneNumber)) {
            return null;
        }
        return $proceed($phoneNumber, $message);
    }

    /**
     * @param ZApiClient $subject
     * @param callable $proceed
     * @param string $phoneNumber
     * @param string $message
     * @param array $buttons
     * @param string|null $title
     * @param string|null $footer
     * @return array|null
     */
    public function aroundSendButtonMessage(
        ZApiClient $subject,
        callable $proceed,
        string $phoneNumber,
        string $message,
        array $buttons,
        ?string $title = null,
        ?string $footer = null
    ): ?array {
        if (!$this->isPhoneAllowed($phoneNumber)) {
            return null;
        }
        return $proceed($phoneNumber, $message, $buttons, $title, $footer);
    }

    /**
     * @param ZApiClient $subject
     * @param callable $proceed
     * @param string $phoneNumber
     * @param string $message
     * @param string $linkUrl
     * @param string|null $linkTitle
     * @param string|null $linkDescription
     * @return array|null
     */
    public function aroundSendLinkMessage(
        ZApiClient $subject,
        callable $proceed,
        string $phoneNumber,
        string $message,
        string $linkUrl,
        ?string $linkTitle = null,
        ?string $linkDescription = null
    ): ?array {
        if (!$this->isPhoneAllowed($phoneNumber)) {
            return null;
        }
        return $proceed($phoneNumber, $message, $linkUrl, $linkTitle, $linkDescription);
    }

    /**
     * @param ZApiClient $subject
     * @param callable $proceed
     * @param string $phoneNumber
     * @param string $imageUrl
     * @param string|null $caption
     * @return array|null
     */
    public function aroundSendImage(
        ZApiClient $subject,
        callable $proceed,
        string $phoneNumber,
        string $imageUrl,
        ?string $caption = null
    ): ?array {
        if (!$this->isPhoneAllowed($phoneNumber)) {
            return null;
        }
        return $proceed($phoneNumber, $imageUrl, $caption);
    }

    /**
     * @param ZApiClient $subject
     * @param callable $proceed
     * @param string $phoneNumber
     * @param string $documentUrl
     * @param string $fileName
     * @return array|null
     */
    public function aroundSendDocument(
        ZApiClient $subject,
        callable $proceed,
        string $phoneNumber,
        string $documentUrl,
        string $fileName
    ): ?array {
        if (!$this->isPhoneAllowed($phoneNumber)) {
            return null;
        }
        return $proceed($phoneNumber, $documentUrl, $fileName);
    }

    /**
     * Check if a phone number is allowed to receive messages.
     *
     * If test_mode is disabled, all numbers are allowed.
     * If test_mode is enabled, only numbers in the whitelist pass.
     *
     * Handles both formats: with or without Brazil country code (55).
     */
    private function isPhoneAllowed(string $phoneNumber): bool
    {
        if (!$this->helper->isWhatsAppTestModeEnabled()) {
            return true;
        }

        $whitelist = $this->helper->getWhatsAppTestWhitelist();

        if (empty($whitelist)) {
            $this->logger->warning('[Z-API TestMode] Message blocked — test mode active but whitelist is empty', [
                'phone' => $this->maskPhone($phoneNumber),
            ]);
            return false;
        }

        $normalizedInput = $this->normalizePhone($phoneNumber);
        $inputWithoutCountry = $this->stripBrazilCountryCode($normalizedInput);

        foreach ($whitelist as $allowedPhone) {
            $normalizedAllowed = $this->normalizePhone($allowedPhone);
            $allowedWithoutCountry = $this->stripBrazilCountryCode($normalizedAllowed);

            if ($normalizedInput === $normalizedAllowed
                || $inputWithoutCountry === $allowedWithoutCountry
            ) {
                return true;
            }
        }

        $this->logger->warning('[Z-API TestMode] Message blocked — phone not in whitelist', [
            'phone' => $this->maskPhone($phoneNumber),
        ]);

        return false;
    }

    private function stripBrazilCountryCode(string $phone): string
    {
        if (str_starts_with($phone, '55') && strlen($phone) > 11) {
            return substr($phone, 2);
        }
        return $phone;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) <= 4) {
            return '****';
        }
        return substr($digits, 0, 4) . str_repeat('*', strlen($digits) - 8) . substr($digits, -4);
    }
}
