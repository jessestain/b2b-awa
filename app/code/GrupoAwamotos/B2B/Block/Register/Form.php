<?php

/**
 * Block para formulário de cadastro B2B
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Register;

use GrupoAwamotos\B2B\Model\AuthLogoResolver;
use GrupoAwamotos\B2B\Model\Registration\AttributionResolver;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\ScopeInterface;
use Magento\Theme\Block\Html\Header\Logo;

class Form extends Template
{
    private const SESSION_FORM_DATA_KEY = 'b2b_register_form_data';

    /**
     * @var list<array{code: string, label: string}>
     */
    private const BRAZILIAN_UFS = [
        ['code' => 'AC', 'label' => 'AC'],
        ['code' => 'AL', 'label' => 'AL'],
        ['code' => 'AP', 'label' => 'AP'],
        ['code' => 'AM', 'label' => 'AM'],
        ['code' => 'BA', 'label' => 'BA'],
        ['code' => 'CE', 'label' => 'CE'],
        ['code' => 'DF', 'label' => 'DF'],
        ['code' => 'ES', 'label' => 'ES'],
        ['code' => 'GO', 'label' => 'GO'],
        ['code' => 'MA', 'label' => 'MA'],
        ['code' => 'MT', 'label' => 'MT'],
        ['code' => 'MS', 'label' => 'MS'],
        ['code' => 'MG', 'label' => 'MG'],
        ['code' => 'PA', 'label' => 'PA'],
        ['code' => 'PB', 'label' => 'PB'],
        ['code' => 'PR', 'label' => 'PR'],
        ['code' => 'PE', 'label' => 'PE'],
        ['code' => 'PI', 'label' => 'PI'],
        ['code' => 'RJ', 'label' => 'RJ'],
        ['code' => 'RN', 'label' => 'RN'],
        ['code' => 'RS', 'label' => 'RS'],
        ['code' => 'RO', 'label' => 'RO'],
        ['code' => 'RR', 'label' => 'RR'],
        ['code' => 'SC', 'label' => 'SC'],
        ['code' => 'SP', 'label' => 'SP'],
        ['code' => 'SE', 'label' => 'SE'],
        ['code' => 'TO', 'label' => 'TO'],
    ];

    /**
     * @var CustomerSession
     */
    private $customerSession;
    private Logo $logo;
    private AuthLogoResolver $authLogoResolver;

    /** @var array<string, string>|null */
    private ?array $persistedFormData = null;

    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        Logo $logo,
        AuthLogoResolver $authLogoResolver,
        array $data = []
    ) {
        $this->customerSession = $customerSession;
        $this->logo = $logo;
        $this->authLogoResolver = $authLogoResolver;
        parent::__construct($context, $data);
    }

    /**
     * Get minimum password length
     *
     * @return int
     */
    public function getMinimumPasswordLength(): int
    {
        return (int) $this->_scopeConfig->getValue(
            'customer/password/minimum_password_length',
            ScopeInterface::SCOPE_STORE
        ) ?: 8;
    }

    /**
     * Get required character classes number
     *
     * @return int
     */
    public function getRequiredCharacterClassesNumber(): int
    {
        return (int) $this->_scopeConfig->getValue(
            'customer/password/required_character_classes_number',
            ScopeInterface::SCOPE_STORE
        ) ?: 3;
    }

    /**
     * Check if customer is logged in
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * Get form action URL
     *
     * @return string
     */
    public function getFormAction(): string
    {
        return $this->getUrl('b2b/register/save');
    }

    /**
     * Get login URL
     *
     * @return string
     */
    public function getLoginUrl(): string
    {
        return $this->getUrl('b2b/account/login');
    }

    /**
     * Get customer dashboard URL
     *
     * @return string
     */
    public function getDashboardUrl(): string
    {
        return $this->getUrl('b2b/account/dashboard');
    }

    public function getLogoSrc(): string
    {
        $resolved = trim($this->authLogoResolver->getLogoSrc());
        return $resolved !== '' ? $resolved : $this->logo->getLogoSrc();
    }

    public function getLogoAlt(): string
    {
        $resolved = trim($this->authLogoResolver->getLogoAlt());
        return $resolved !== '' ? $resolved : $this->logo->getLogoAlt();
    }

    public function getHomeUrl(): string
    {
        return $this->getUrl('');
    }

    public function getCepLookupUrl(): string
    {
        return $this->getUrl('b2b/ajax/ceplookup');
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public function getBrazilianRegionOptions(): array
    {
        return self::BRAZILIAN_UFS;
    }

    public function getFormValue(string $field): string
    {
        $data = $this->getPersistedFormData();

        return isset($data[$field]) ? (string) $data[$field] : '';
    }

    public function isFormValueSelected(string $field, string $value): bool
    {
        return strtoupper($this->getFormValue($field)) === strtoupper($value);
    }

    public function getPasswordRequirementHint(): string
    {
        $minLength = $this->getMinimumPasswordLength();
        $classes = $this->getRequiredCharacterClassesNumber();

        if ($classes <= 2) {
            return (string) __('Mínimo %1 caracteres com letras e números.', $minLength);
        }

        return (string) __(
            'Mínimo %1 caracteres, combinando maiúsculas, minúsculas, números ou símbolos (%2 tipos).',
            $minLength,
            $classes
        );
    }

    public function getIdempotencyToken(): string
    {
        $existing = trim((string) $this->customerSession->getData('b2b_register_idempotency_token'));
        if ($existing !== '') {
            return $existing;
        }

        $token = bin2hex(random_bytes(16));
        $this->customerSession->setData('b2b_register_idempotency_token', $token);

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function getPersistedFormData(): array
    {
        if ($this->persistedFormData !== null) {
            return $this->persistedFormData;
        }

        $data = $this->customerSession->getData(self::SESSION_FORM_DATA_KEY);
        $this->customerSession->unsetData(self::SESSION_FORM_DATA_KEY);
        $this->persistedFormData = is_array($data) ? $data : [];

        return $this->persistedFormData;
    }

    /**
     * First-touch UTM/vendedor captured from the register URL and kept in session.
     *
     * @return array<string, string>
     */
    public function getAttributionFieldValues(): array
    {
        $resolver = new AttributionResolver();
        $stored = $this->customerSession->getData('b2b_attribution');
        $stored = is_array($stored) ? $stored : [];
        $incoming = $resolver->fromParams($this->getRequest()->getParams())->toRequestParams();
        $merged = $resolver->mergeFirstTouch($stored, $incoming);
        $this->customerSession->setData('b2b_attribution', $merged);

        if (trim((string) $this->customerSession->getData('b2b_entry_url')) === '') {
            $uri = (string) ($this->getRequest()->getServer('REQUEST_URI') ?? '');
            if ($uri !== '') {
                $this->customerSession->setData('b2b_entry_url', mb_substr($uri, 0, 512));
            }
        }

        return [
            'utm_source' => (string) ($merged['utm_source'] ?? ''),
            'utm_medium' => (string) ($merged['utm_medium'] ?? ''),
            'utm_campaign' => (string) ($merged['utm_campaign'] ?? ''),
            'utm_content' => (string) ($merged['utm_content'] ?? ''),
            'utm_term' => (string) ($merged['utm_term'] ?? ''),
            'cname' => (string) ($merged['cname'] ?? $merged['campaign'] ?? $merged['b2b_registration_campaign'] ?? ''),
            'attendant' => (string) ($merged['attendant'] ?? $merged['attendant_id'] ?? ''),
            'vendedor' => (string) ($merged['vendedor'] ?? $merged['erp_seller_code'] ?? ''),
        ];
    }
}
