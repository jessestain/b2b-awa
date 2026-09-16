<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;

class ContactInfo implements ArgumentInterface
{
    private const XML_PATH_WHATSAPP_ENABLED = 'grupoawamotos_theme/contact/whatsapp_enabled';
    private const XML_PATH_WHATSAPP_NUMBER = 'grupoawamotos_theme/contact/whatsapp_number';
    private const XML_PATH_WHATSAPP_MESSAGE = 'grupoawamotos_theme/contact/whatsapp_message';
    private const XML_PATH_WHATSAPP_HIDE_CHECKOUT = 'grupoawamotos_theme/contact/whatsapp_hide_checkout';
    private const XML_PATH_WHATSAPP_HIDE_ACCOUNT = 'grupoawamotos_theme/contact/whatsapp_hide_account';
    private const XML_PATH_QUOTE_FAB_ENABLED = 'grupoawamotos_theme/contact/quote_fab_enabled';
    private const XML_PATH_PHONE = 'grupoawamotos_theme/contact/phone';
    private const XML_PATH_EMAIL = 'grupoawamotos_theme/contact/email';
    private const XML_PATH_HOURS = 'general/store_information/hours';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RequestInterface $request,
    ) {
    }

    public function getWhatsAppNumber(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP_NUMBER,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function hasWhatsApp(): bool
    {
        return $this->getWhatsAppDigits() !== '';
    }

    public function shouldShowWhatsAppButton(): bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_WHATSAPP_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        if (!$this->hasWhatsApp()) {
            return false;
        }

        $moduleName = $this->request->getModuleName();

        if ($moduleName === 'checkout'
            && $this->scopeConfig->isSetFlag(self::XML_PATH_WHATSAPP_HIDE_CHECKOUT, ScopeInterface::SCOPE_STORE)
        ) {
            return false;
        }

        if ($moduleName === 'customer'
            && $this->scopeConfig->isSetFlag(self::XML_PATH_WHATSAPP_HIDE_ACCOUNT, ScopeInterface::SCOPE_STORE)
        ) {
            return false;
        }

        return true;
    }

    public function getWhatsAppDigits(): string
    {
        return $this->normalizePhoneForDial($this->getWhatsAppNumber());
    }

    public function getWhatsAppUrl(): string
    {
        $digits = $this->getWhatsAppDigits();
        if ($digits === '') {
            return '';
        }

        $query = trim($this->getWhatsAppMessage()) !== ''
            ? '?text=' . rawurlencode($this->getWhatsAppMessage())
            : '';

        return 'https://wa.me/' . $digits . $query;
    }

    public function getWhatsAppMessage(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP_MESSAGE,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getPhone(): string
    {
        $phone = trim($this->getPhoneRaw());

        if ($phone === '') {
            return '';
        }

        return $this->formatPhoneForDisplay($phone);
    }

    public function hasPhone(): bool
    {
        return $this->getPhoneDigits() !== '';
    }

    public function getPhoneDigits(): string
    {
        return $this->normalizePhone($this->getPhoneRaw());
    }

    public function getPhoneUrl(): string
    {
        $digits = $this->normalizePhoneForDial($this->getPhoneRaw());

        return $digits !== '' ? 'tel:+' . $digits : '';
    }

    public function getEmail(): string
    {
        return $this->normalizeEmail($this->getEmailRaw());
    }

    public function hasEmail(): bool
    {
        return trim($this->getEmail()) !== '';
    }

    public function getEmailUrl(): string
    {
        $email = trim($this->getEmail());

        return $email !== '' ? 'mailto:' . $email : '';
    }

    public function getWhatsAppDisplay(): string
    {
        $raw = $this->getWhatsAppNumber();

        return $raw !== '' ? $this->formatPhoneForDisplay($raw) : '';
    }

    public function getHours(): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_HOURS,
            ScopeInterface::SCOPE_STORE
        ));
    }

    public function hasAnyContact(): bool
    {
        return $this->hasWhatsApp() || $this->hasPhone() || $this->hasEmail();
    }

    private function normalizePhone(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private function normalizePhoneForDial(string $value): string
    {
        $digits = $this->normalizePhone($value);

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '55')) {
            return $digits;
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55' . $digits;
        }

        return $digits;
    }

    private function formatPhoneForDisplay(string $value): string
    {
        $digits = $this->normalizePhone($value);

        if (str_starts_with($digits, '55') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '55') && strlen($digits) === 13) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7, 4));
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6, 4));
        }

        return $value;
    }

    private function normalizeEmail(string $value): string
    {
        $email = trim(strtolower($value));

        if ($email === '') {
            return '';
        }

        if ((bool) preg_match(
            '/^(?:contato|atacado|privacidade|suporte|entregas|sac|falecom|teste|oportunidades|admin|rh|vendas|noreply|monitoring|awamotos)@(?:awamotos\.com\.br|awamotos\.com|grupoawamotos\.com\.br)$/i',
            $email
        )) {
            return 'awamotos@awamotos.com.br';
        }

        if ((bool) preg_match('/^[^@\s]+@grupoawamotos\.com\.br$/i', $email)) {
            $email = (string) preg_replace('/@grupoawamotos\.com\.br$/i', '@awamotos.com.br', $email);
        }

        if ((bool) preg_match('/^[^@\s]+@awamotos\.com(?:\.br)?$/i', $email)) {
            $email = (string) preg_replace('/@awamotos\.com(?:\.br)?$/i', '@awamotos.com.br', $email);
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function getPhoneRaw(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PHONE,
            ScopeInterface::SCOPE_STORE
        );
    }

    private function getEmailRaw(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_EMAIL,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isQuoteFabEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_QUOTE_FAB_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }
}
