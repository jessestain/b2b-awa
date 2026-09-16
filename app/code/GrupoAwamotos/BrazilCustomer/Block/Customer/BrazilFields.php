<?php

declare(strict_types=1);

namespace GrupoAwamotos\BrazilCustomer\Block\Customer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class BrazilFields extends Template
{
    private CustomerSession $customerSession;
    private CustomerRepositoryInterface $customerRepository;
    private ?array $customerData = null;

    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
    }

    /**
     * Get current customer's custom attribute value
     */
    public function getCustomerAttributeValue(string $attributeCode): string
    {
        $data = $this->getCustomerData();
        return $data[$attributeCode] ?? '';
    }

    /**
     * Resolved person type for account edit UI.
     *
     * B2B attribute (b2b_person_type) and CNPJ evidence win over a stale
     * BrazilCustomer person_type=pf left from older registrations.
     */
    public function getResolvedPersonType(): string
    {
        $data = $this->getCustomerData();
        $b2b = strtolower(trim($data['b2b_person_type'] ?? ''));
        if ($b2b === 'pj') {
            return 'pj';
        }

        $brazil = strtolower(trim($data['person_type'] ?? ''));
        if ($brazil === 'pj' || $brazil === 'pf') {
            // Prefer evidence of CNPJ even when Brazil attr says pf
            if ($brazil === 'pf' && $this->hasCnpjEvidence($data)) {
                return 'pj';
            }
            return $brazil;
        }

        return $this->hasCnpjEvidence($data) ? 'pj' : 'pf';
    }

    private function hasCnpjEvidence(array $data): bool
    {
        foreach (['cnpj', 'b2b_cnpj', 'taxvat'] as $code) {
            $digits = preg_replace('/\D+/', '', (string) ($data[$code] ?? ''));
            if (is_string($digits) && strlen($digits) === 14) {
                return true;
            }
        }
        return false;
    }

    private function getCustomerData(): array
    {
        if ($this->customerData !== null) {
            return $this->customerData;
        }

        $this->customerData = [];

        try {
            $customerId = $this->customerSession->getCustomerId();
            if (!$customerId) {
                return $this->customerData;
            }

            $customer = $this->customerRepository->getById((int) $customerId);
            $attributes = [
                'person_type',
                'b2b_person_type',
                'cpf',
                'rg',
                'cnpj',
                'b2b_cnpj',
                'ie',
                'company_name',
                'trade_name',
                'b2b_razao_social',
                'taxvat',
            ];

            foreach ($attributes as $code) {
                $attr = $customer->getCustomAttribute($code);
                $this->customerData[$code] = $attr ? (string) $attr->getValue() : '';
            }

            // taxvat is a native customer field, not always a custom attribute
            if (($this->customerData['taxvat'] ?? '') === '' && method_exists($customer, 'getTaxvat')) {
                $this->customerData['taxvat'] = (string) ($customer->getTaxvat() ?? '');
            }

            // Bridge B2B attrs into BrazilCustomer form fields for display
            if (($this->customerData['cnpj'] ?? '') === '' && ($this->customerData['b2b_cnpj'] ?? '') !== '') {
                $this->customerData['cnpj'] = $this->customerData['b2b_cnpj'];
            }
            if (($this->customerData['cnpj'] ?? '') === '' && $this->hasCnpjEvidence($this->customerData)) {
                $this->customerData['cnpj'] = $this->customerData['taxvat'] ?? '';
            }
            if (($this->customerData['company_name'] ?? '') === '' && ($this->customerData['b2b_razao_social'] ?? '') !== '') {
                $this->customerData['company_name'] = $this->customerData['b2b_razao_social'];
            }
        } catch (\Exception $e) {
            $this->_logger->warning('[BrazilFields::getCustomerData] Exceção ignorada: ' . $e->getMessage());
        }

        return $this->customerData;
    }
}
