<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class FooterData implements ArgumentInterface
{
    private const XML_PATH_MOBILE_MENU_ENABLE = 'themeoption/footer/footer_menu_mobile';
    private const XML_PATH_CONTACT_PHONE = 'grupoawamotos_theme/contact/phone';
    private const XML_PATH_CONTACT_EMAIL = 'grupoawamotos_theme/contact/email';
    private const XML_PATH_CONTACT_WHATSAPP = 'grupoawamotos_theme/contact/whatsapp_number';
    private const XML_PATH_STORE_PHONE = 'general/store_information/phone';
    private const XML_PATH_STORE_NAME = 'general/store_information/name';
    private const XML_PATH_STORE_CNPJ = 'general/store_information/merchant_vat_number';

    private const XML_PATH_STORE_STREET = 'general/store_information/street_line1';
    private const XML_PATH_STORE_CITY = 'general/store_information/city';
    private const XML_PATH_STORE_POSTCODE = 'general/store_information/postcode';
    private const XML_PATH_SOCIAL_INSTAGRAM = 'grupoawamotos_theme/social/instagram_url';
    private const XML_PATH_SOCIAL_FACEBOOK = 'grupoawamotos_theme/social/facebook_url';
    private const XML_PATH_SOCIAL_YOUTUBE = 'grupoawamotos_theme/social/youtube_url';
    private const XML_PATH_SOCIAL_PINTEREST = 'grupoawamotos_theme/social/pinterest_url';
    private const XML_PATH_FOOTER_EXPERIMENT_ENABLED = 'grupoawamotos_theme/footer_experiment/enabled';
    private const XML_PATH_FOOTER_EXPERIMENT_ROLLOUT = 'grupoawamotos_theme/footer_experiment/rollout_percentage';
    private const XML_PATH_FOOTER_EXPERIMENT_SEED = 'grupoawamotos_theme/footer_experiment/variant_seed';

    private const DEFAULT_PHONE = '(16) 3322-0000';
    private const DEFAULT_WHATSAPP = '(16) 99736-7588';
    private const DEFAULT_EMAIL = 'awamotos@awamotos.com.br';
    private const DEFAULT_STREET = 'Rua Castro Alves, 1234';
    private const DEFAULT_CITY = 'Araraquara-SP';
    private const DEFAULT_POSTCODE = '';
    private const DEFAULT_INSTAGRAM_URL = 'https://www.instagram.com/awamotos';
    private const DEFAULT_FACEBOOK_URL = 'https://www.facebook.com/awamotos';
    private const DEFAULT_YOUTUBE_URL = 'https://www.youtube.com/@awamotos';
    private const DEFAULT_PINTEREST_URL = 'https://br.pinterest.com/awamotos/';
    private const DEFAULT_FOOTER_EXPERIMENT_SEED = 'home5_footer_v1';

    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @var array<string, string>
     */
    private array $configValueCache = [];

    /**
     * @var array<string, bool>
     */
    private array $configFlagCache = [];

    private ?string $storeName = null;

    private ?string $phone = null;

    private ?string $merchantVatNumber = null;

    private ?string $phoneRaw = null;

    private ?string $phoneUrl = null;

    private ?string $whatsApp = null;

    private ?string $whatsAppUrl = null;

    private ?string $email = null;

    private ?string $emailUrl = null;

    private ?string $streetLine1 = null;

    private ?string $cityLabel = null;

    private ?string $postcode = null;

    private ?string $instagramUrl = null;

    private ?string $facebookUrl = null;

    private ?string $youtubeUrl = null;

    private ?string $pinterestUrl = null;

    private ?bool $mobileMenuEnabled = null;

    private ?bool $footerExperimentEnabled = null;

    private ?int $footerExperimentRolloutPercentage = null;

    private ?string $footerExperimentSeed = null;

    private ?string $formattedAddress = null;

    /**
     * Check if the mobile footer menu is enabled in theme configuration.
     *
     * @return bool
     */
    public function isMobileMenuEnabled(): bool
    {
        if ($this->mobileMenuEnabled !== null) {
            return $this->mobileMenuEnabled;
        }

        $this->mobileMenuEnabled = $this->getConfigFlag(self::XML_PATH_MOBILE_MENU_ENABLE);

        return $this->mobileMenuEnabled;
    }

    public function getStoreName(): string
    {
        if ($this->storeName !== null) {
            return $this->storeName;
        }

        $storeName = $this->getConfigValue(self::XML_PATH_STORE_NAME, '');
        $this->storeName = $storeName !== '' ? $storeName : 'AWA Motos';

        return $this->storeName;
    }

    public function getPhone(): string
    {
        if ($this->phone !== null) {
            return $this->phone;
        }

        $contactPhone = $this->getConfigValue(self::XML_PATH_CONTACT_PHONE, '');

        if ($contactPhone !== '') {
            $this->phone = $this->formatPhoneForDisplay($contactPhone);

            return $this->phone;
        }

        $storePhone = $this->getConfigValue(self::XML_PATH_STORE_PHONE, '');

        $this->phone = $storePhone !== '' ? $this->formatPhoneForDisplay($storePhone) : self::DEFAULT_PHONE;

        return $this->phone;
    }

    public function getMerchantVatNumber(): string
    {
        if ($this->merchantVatNumber !== null) {
            return $this->merchantVatNumber;
        }

        $this->merchantVatNumber = $this->getConfigValue(self::XML_PATH_STORE_CNPJ, '');

        return $this->merchantVatNumber;
    }

    public function getPhoneUrl(): string
    {
        if ($this->phoneUrl !== null) {
            return $this->phoneUrl;
        }

        $digits = $this->normalizePhoneForDial($this->getPhoneRaw());
        $this->phoneUrl = $digits !== '' ? 'tel:+' . $digits : '';

        return $this->phoneUrl;
    }

    public function getWhatsApp(): string
    {
        if ($this->whatsApp !== null) {
            return $this->whatsApp;
        }

        $whatsApp = $this->getConfigValue(self::XML_PATH_CONTACT_WHATSAPP, '');
        $this->whatsApp = $whatsApp !== '' ? $whatsApp : self::DEFAULT_WHATSAPP;

        return $this->whatsApp;
    }

    public function getWhatsAppUrl(): string
    {
        if ($this->whatsAppUrl !== null) {
            return $this->whatsAppUrl;
        }

        $digits = $this->normalizePhoneForDial($this->getWhatsApp());
        $this->whatsAppUrl = $digits !== '' ? 'https://wa.me/' . $digits : '';

        return $this->whatsAppUrl;
    }

    public function getEmail(): string
    {
        if ($this->email !== null) {
            return $this->email;
        }

        $email = $this->getConfigValue(self::XML_PATH_CONTACT_EMAIL, '');
        $this->email = $this->normalizeEmail($email !== '' ? $email : self::DEFAULT_EMAIL);

        return $this->email;
    }

    public function getEmailUrl(): string
    {
        if ($this->emailUrl !== null) {
            return $this->emailUrl;
        }

        $email = trim($this->getEmail());
        $this->emailUrl = $email !== '' ? 'mailto:' . $email : '';

        return $this->emailUrl;
    }

    public function getStreetLine1(): string
    {
        if ($this->streetLine1 !== null) {
            return $this->streetLine1;
        }

        $street = $this->getConfigValue(self::XML_PATH_STORE_STREET, '');
        $this->streetLine1 = $street !== '' ? $street : self::DEFAULT_STREET;

        return $this->streetLine1;
    }

    public function getCityLabel(): string
    {
        if ($this->cityLabel !== null) {
            return $this->cityLabel;
        }

        $city = $this->getConfigValue(self::XML_PATH_STORE_CITY, '');
        $this->cityLabel = $city !== '' ? $city : self::DEFAULT_CITY;

        return $this->cityLabel;
    }

    public function getPostcode(): string
    {
        if ($this->postcode !== null) {
            return $this->postcode;
        }

        $postcode = $this->getConfigValue(self::XML_PATH_STORE_POSTCODE, '');
        $this->postcode = $postcode !== '' ? $postcode : self::DEFAULT_POSTCODE;

        return $this->postcode;
    }

    public function getInstagramUrl(): string
    {
        if ($this->instagramUrl !== null) {
            return $this->instagramUrl;
        }

        $this->instagramUrl = $this->getConfigValue(self::XML_PATH_SOCIAL_INSTAGRAM, self::DEFAULT_INSTAGRAM_URL);

        return $this->instagramUrl;
    }

    public function getFacebookUrl(): string
    {
        if ($this->facebookUrl !== null) {
            return $this->facebookUrl;
        }

        $this->facebookUrl = $this->getConfigValue(self::XML_PATH_SOCIAL_FACEBOOK, self::DEFAULT_FACEBOOK_URL);

        return $this->facebookUrl;
    }

    public function getYoutubeUrl(): string
    {
        if ($this->youtubeUrl !== null) {
            return $this->youtubeUrl;
        }

        $this->youtubeUrl = $this->getConfigValue(self::XML_PATH_SOCIAL_YOUTUBE, self::DEFAULT_YOUTUBE_URL);

        return $this->youtubeUrl;
    }

    public function getPinterestUrl(): string
    {
        if ($this->pinterestUrl !== null) {
            return $this->pinterestUrl;
        }

        $this->pinterestUrl = $this->getConfigValue(self::XML_PATH_SOCIAL_PINTEREST, self::DEFAULT_PINTEREST_URL);

        return $this->pinterestUrl;
    }

    public function isFooterExperimentEnabled(): bool
    {
        if ($this->footerExperimentEnabled !== null) {
            return $this->footerExperimentEnabled;
        }

        $this->footerExperimentEnabled = $this->getConfigFlag(self::XML_PATH_FOOTER_EXPERIMENT_ENABLED);

        return $this->footerExperimentEnabled;
    }

    public function getFooterExperimentRolloutPercentage(): int
    {
        if ($this->footerExperimentRolloutPercentage !== null) {
            return $this->footerExperimentRolloutPercentage;
        }

        $value = (int) $this->getConfigValue(self::XML_PATH_FOOTER_EXPERIMENT_ROLLOUT, '0');
        $this->footerExperimentRolloutPercentage = $this->normalizePercentage($value);

        return $this->footerExperimentRolloutPercentage;
    }

    public function getFooterExperimentSeed(): string
    {
        if ($this->footerExperimentSeed !== null) {
            return $this->footerExperimentSeed;
        }

        $this->footerExperimentSeed = $this->getConfigValue(
            self::XML_PATH_FOOTER_EXPERIMENT_SEED,
            self::DEFAULT_FOOTER_EXPERIMENT_SEED
        );

        return $this->footerExperimentSeed;
    }

    public function getFormattedAddress(): string
    {
        if ($this->formattedAddress !== null) {
            return $this->formattedAddress;
        }

        $parts = [
            $this->getStreetLine1(),
            $this->getCityLabel(),
        ];

        $postcode = $this->getPostcode();
        if ($postcode !== '') {
            $parts[] = 'CEP: ' . $postcode;
        }

        $this->formattedAddress = implode(' - ', array_filter($parts));

        return $this->formattedAddress;
    }

    public function getMapsUrl(): string
    {
        $parts = array_filter([
            $this->getStreetLine1(),
            $this->getCityLabel(),
            $this->getPostcode() !== '' ? 'CEP ' . $this->getPostcode() : '',
        ]);

        if ($parts === []) {
            return '';
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(', ', $parts));
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
        $phone = trim($value);

        if ($phone === '') {
            return '';
        }

        if ((bool) preg_match('/\D+/', $phone)) {
            return $phone;
        }

        $digits = $this->normalizePhone($phone);

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

        return $phone;
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
            return self::DEFAULT_EMAIL;
        }

        if ((bool) preg_match('/^[^@\s]+@grupoawamotos\.com\.br$/i', $email)) {
            $email = (string) preg_replace('/@grupoawamotos\.com\.br$/i', '@awamotos.com.br', $email);
        }

        if ((bool) preg_match('/^[^@\s]+@awamotos\.com(?:\.br)?$/i', $email)) {
            $email = (string) preg_replace('/@awamotos\.com(?:\.br)?$/i', '@awamotos.com.br', $email);
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : self::DEFAULT_EMAIL;
    }

    private function getPhoneRaw(): string
    {
        if ($this->phoneRaw !== null) {
            return $this->phoneRaw;
        }

        $contactPhone = $this->getConfigValue(self::XML_PATH_CONTACT_PHONE, '');
        if ($contactPhone !== '') {
            $this->phoneRaw = $contactPhone;

            return $this->phoneRaw;
        }

        $storePhone = $this->getConfigValue(self::XML_PATH_STORE_PHONE, '');
        $this->phoneRaw = $storePhone !== '' ? $storePhone : self::DEFAULT_PHONE;

        return $this->phoneRaw;
    }

    private function getConfigValue(string $path, string $default): string
    {
        if (!array_key_exists($path, $this->configValueCache)) {
            $this->configValueCache[$path] = trim((string) $this->scopeConfig->getValue(
                $path,
                ScopeInterface::SCOPE_STORE
            ));
        }

        $value = $this->configValueCache[$path];

        return $value !== '' ? $value : $default;
    }

    private function getConfigFlag(string $path): bool
    {
        if (!array_key_exists($path, $this->configFlagCache)) {
            $this->configFlagCache[$path] = $this->scopeConfig->isSetFlag(
                $path,
                ScopeInterface::SCOPE_STORE
            );
        }

        return $this->configFlagCache[$path];
    }

    private function normalizePercentage(int $value): int
    {
        if ($value < 0) {
            return 0;
        }

        if ($value > 100) {
            return 100;
        }

        return $value;
    }
}
