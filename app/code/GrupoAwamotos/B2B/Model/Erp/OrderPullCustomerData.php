<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Erp;

use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use GrupoAwamotos\B2B\Model\CustomerCnpjResolver;
use GrupoAwamotos\B2B\Model\Sectra\ValidatorChecker;
use GrupoAwamotos\ERPIntegration\Api\B2bOrderPullCustomerDataInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Fiscal/customer payload for Sectra order pull — CNPJ via b2b_cnpj, no erp_code required.
 */
class OrderPullCustomerData implements B2bOrderPullCustomerDataInterface
{
    private const OC_CUSTOMER_ID_OFFSET = 200000;

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly CustomerCnpjResolver $cnpjResolver,
        private readonly ValidatorChecker $validatorChecker,
        private readonly RegionFactory $regionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function buildForOrder(OrderInterface $order): array
    {
        $customerId = (int) ($order->getCustomerId() ?? 0);
        if ($customerId <= 0) {
            return [];
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return [];
        }

        if (!$this->isApprovedB2bCustomer($customerId)) {
            return [];
        }

        $resolved = $this->cnpjResolver->resolveWithSource($customer);
        $cnpjDigits = $resolved['digits'] ?? '';
        $shipping = $order->getShippingAddress();
        $billing = $order->getBillingAddress();
        $address = $shipping ?? $billing;

        $erpCodeAttr = $customer->getCustomAttribute('erp_code');
        $erpCode = $erpCodeAttr && is_numeric($erpCodeAttr->getValue())
            ? (int) $erpCodeAttr->getValue()
            : 0;

        return [
            'magento_customer_id' => $customerId,
            'opencart_customer_id' => $customerId + self::OC_CUSTOMER_ID_OFFSET,
            'erp_code' => $erpCode,
            'integration_mode' => $erpCode > 0 ? 'existing_erp_customer' : 'pull_order_registration',
            'cnpj' => $cnpjDigits,
            'cnpj_formatted' => $this->getAttributeValue($customer, 'b2b_cnpj'),
            'cnpj_source' => $resolved['source'] ?? null,
            'razao_social' => $this->getAttributeValue($customer, 'b2b_razao_social')
                ?: ($address?->getCompany() ?? ''),
            'inscricao_estadual' => $this->getAttributeValue($customer, 'b2b_inscricao_estadual'),
            'email' => $order->getCustomerEmail() ?: $customer->getEmail(),
            'telephone' => $this->resolveTelephone($customer, $address),
            'firstname' => $customer->getFirstname(),
            'lastname' => $customer->getLastname(),
            'b2b_group_id' => (int) $customer->getGroupId(),
            'b2b_group_code' => $this->resolveGroupCode((int) $customer->getGroupId()),
            'b2b_approval_status' => ApprovalStatus::STATUS_APPROVED,
            'custom_field' => $this->buildCustomFieldJson($customer, $cnpjDigits),
            'billing_address' => $this->formatAddress($billing),
            'shipping_address' => $this->formatAddress($shipping),
        ];
    }

    public function isReadyForOrderPull(int $customerId): bool
    {
        return $this->validateCustomerData($customerId) === null;
    }

    public function validateOrderForPull(OrderInterface $order): ?string
    {
        $customerId = (int) ($order->getCustomerId() ?? 0);
        if ($customerId <= 0) {
            return null;
        }

        if (!$this->isApprovedB2bCustomer($customerId)) {
            return null;
        }

        $customerError = $this->validateCustomerData($customerId);
        if ($customerError !== null) {
            return $customerError;
        }

        $shipping = $order->getShippingAddress();
        if ($shipping === null) {
            return (string) __('Endereço de entrega é obrigatório para pedidos B2B.');
        }

        return $this->validateAddress($shipping);
    }

    public function isApprovedB2bCustomer(int $customerId): bool
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return false;
        }

        $status = $customer->getCustomAttribute('b2b_approval_status');

        return $status !== null
            && (string) $status->getValue() === ApprovalStatus::STATUS_APPROVED;
    }

    public function isCustomerErpValidatedForPurchase(int $customerId): bool
    {
        if (!$this->isApprovedB2bCustomer($customerId)) {
            return true;
        }

        return $this->validatorChecker->isCustomerValidatedInSectra($customerId);
    }

    private function validateCustomerData(int $customerId): ?string
    {
        if (!$this->isApprovedB2bCustomer($customerId)) {
            return null;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException) {
            return (string) __('Cliente não encontrado.');
        }

        $resolved = $this->cnpjResolver->resolveWithSource($customer);
        if ($resolved === null || !$this->cnpjResolver->isValidCnpj($resolved['digits'])) {
            return (string) __('CNPJ válido (b2b_cnpj) é obrigatório para pedidos B2B.');
        }

        $isLegacyValidated = $this->validatorChecker->isCustomerValidatedInSectra($customerId);

        $razao = trim($this->getAttributeValue($customer, 'b2b_razao_social'));
        if ($razao === '') {
            if ($isLegacyValidated) {
                $this->logLegacyFiscalGap($customerId, (string) $customer->getEmail(), 'b2b_razao_social');
            } else {
                return (string) __('Razão social é obrigatória para pedidos B2B.');
            }
        }

        $email = trim($customer->getEmail());
        if ($email === '') {
            return (string) __('E-mail é obrigatório para pedidos B2B.');
        }

        $phone = trim($this->getAttributeValue($customer, 'b2b_phone'));
        if ($phone === '') {
            if ($isLegacyValidated) {
                $this->logLegacyFiscalGap($customerId, (string) $customer->getEmail(), 'b2b_phone');
            } else {
                return (string) __('Telefone é obrigatório para pedidos B2B.');
            }
        }

        return null;
    }

    private function logLegacyFiscalGap(int $customerId, string $email, string $field): void
    {
        $this->logger->warning(
            sprintf(
                '[B2B] Cliente legado validado no ERP sem %s — checkout liberado; completar cadastro.',
                $field
            ),
            ['customer_id' => $customerId, 'email' => $email, 'field' => $field]
        );
    }

    private function validateAddress(?OrderAddressInterface $address): ?string
    {
        if ($address === null) {
            return (string) __('Endereço completo é obrigatório para pedidos B2B.');
        }

        $street = implode(' ', array_filter($address->getStreet() ?? []));
        if (trim($street) === '' || trim((string) $address->getCity()) === ''
            || trim((string) $address->getPostcode()) === ''
            || trim((string) $address->getRegion()) === ''
        ) {
            return (string) __('Endereço de entrega incompleto (rua, cidade, UF e CEP).');
        }

        return null;
    }

    private function resolveTelephone(CustomerInterface $customer, ?OrderAddressInterface $address): string
    {
        $phone = trim($this->getAttributeValue($customer, 'b2b_phone'));
        if ($phone !== '') {
            return $phone;
        }

        return trim((string) ($address?->getTelephone() ?? ''));
    }

    private function resolveGroupCode(int $groupId): string
    {
        try {
            return (string) $this->groupRepository->getById($groupId)->getCode();
        } catch (NoSuchEntityException) {
            return '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAddress(?OrderAddressInterface $address): array
    {
        if ($address === null) {
            return [];
        }

        return [
            'street' => implode(', ', $address->getStreet() ?? []),
            'neighborhood' => $this->extractBairro($address),
            'city' => (string) ($address->getCity() ?? ''),
            'region' => $this->resolveRegionCode($address),
            'postcode' => (string) ($address->getPostcode() ?? ''),
            'telephone' => (string) ($address->getTelephone() ?? ''),
            'company' => (string) ($address->getCompany() ?? ''),
        ];
    }

    private function extractBairro(OrderAddressInterface $address): string
    {
        foreach ($address->getStreet() ?? [] as $line) {
            if (preg_match('/^Bairro:\s*(.+)$/iu', (string) $line, $matches)) {
                return trim($matches[1]);
            }
        }

        return '';
    }

    private function resolveRegionCode(OrderAddressInterface $address): string
    {
        $countryId = $address->getCountryId() ?? 'BR';
        $regionName = $address->getRegion();
        if ($regionName) {
            $region = $this->regionFactory->create()->loadByName($regionName, $countryId);
            if ($region->getId()) {
                return (string) $region->getCode();
            }
        }

        return (string) ($address->getRegionCode() ?? '');
    }

    private function buildCustomFieldJson(CustomerInterface $customer, string $cnpjDigits): string
    {
        $razao = $this->getAttributeValue($customer, 'b2b_razao_social');
        $ie = preg_replace('/\D/', '', $this->getAttributeValue($customer, 'b2b_inscricao_estadual'));

        return (string) json_encode([
            '6' => $cnpjDigits,
            '2' => '',
            '3' => $ie,
            '1' => $razao,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function getAttributeValue(CustomerInterface $customer, string $code): string
    {
        $attr = $customer->getCustomAttribute($code);

        return $attr !== null ? trim((string) $attr->getValue()) : '';
    }
}
