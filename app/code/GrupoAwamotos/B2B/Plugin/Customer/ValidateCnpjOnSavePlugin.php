<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Customer;

use GrupoAwamotos\B2B\Helper\CnpjValidator;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\InputException;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;

class ValidateCnpjOnSavePlugin
{
    private CnpjValidator $cnpjValidator;
    private CustomerCollectionFactory $customerCollectionFactory;

    public function __construct(
        CnpjValidator $cnpjValidator,
        CustomerCollectionFactory $customerCollectionFactory
    ) {
        $this->cnpjValidator = $cnpjValidator;
        $this->customerCollectionFactory = $customerCollectionFactory;
    }

    public function beforeSave(
        CustomerRepositoryInterface $subject,
        CustomerInterface $customer,
        $passwordHash = null
    ): array {
        $rawCnpj = $this->extractCnpj($customer);
        if ($rawCnpj === '') {
            return [$customer, $passwordHash];
        }

        if (!$this->cnpjValidator->validateLocal($rawCnpj)) {
            throw new InputException(
                __('CNPJ inválido informado para o cliente.')
            );
        }

        $formattedCnpj = $this->cnpjValidator->format($rawCnpj);
        $this->assertCnpjIsUnique($customer, $rawCnpj, $formattedCnpj);

        $customer->setCustomAttribute('b2b_cnpj', $formattedCnpj);

        $taxvatDigits = $this->clean((string) $customer->getTaxvat());
        if ($taxvatDigits === '' || strlen($taxvatDigits) === 14) {
            $customer->setTaxvat($formattedCnpj);
        }

        return [$customer, $passwordHash];
    }

    private function extractCnpj(CustomerInterface $customer): string
    {
        $cnpjAttr = $customer->getCustomAttribute('b2b_cnpj');
        $cnpjValue = trim((string) ($cnpjAttr ? $cnpjAttr->getValue() : ''));

        if ($cnpjValue !== '') {
            return $cnpjValue;
        }

        if (!$this->hasB2bContext($customer)) {
            return '';
        }

        $taxvatDigits = $this->clean((string) $customer->getTaxvat());
        if (strlen($taxvatDigits) === 14) {
            return $taxvatDigits;
        }

        return '';
    }

    private function hasB2bContext(CustomerInterface $customer): bool
    {
        $attributeCodes = [
            'b2b_person_type',
            'b2b_razao_social',
            'b2b_nome_fantasia',
            'b2b_cnpj'
        ];

        foreach ($attributeCodes as $attributeCode) {
            $attribute = $customer->getCustomAttribute($attributeCode);
            if (!$attribute) {
                continue;
            }

            if (trim((string) $attribute->getValue()) !== '') {
                return true;
            }
        }

        return false;
    }

    private function clean(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    private function assertCnpjIsUnique(
        CustomerInterface $customer,
        string $rawCnpj,
        string $formattedCnpj
    ): void {
        $cnpjDigits = $this->clean($rawCnpj);
        if ($cnpjDigits === '') {
            return;
        }

        $customerId = (int) ($customer->getId() ?: 0);
        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToSelect(['email', 'taxvat', 'b2b_cnpj']);
        $collection->addAttributeToFilter(
            [
                ['attribute' => 'b2b_cnpj', 'eq' => $formattedCnpj],
                ['attribute' => 'b2b_cnpj', 'eq' => $cnpjDigits]
            ]
        );

        if ($customerId > 0) {
            $collection->addAttributeToFilter('entity_id', ['neq' => $customerId]);
        }

        $collection->setPageSize(20);

        foreach ($collection as $existingCustomer) {
            $existingCustomerId = (int) $existingCustomer->getId();
            if ($existingCustomerId <= 0 || $existingCustomerId === $customerId) {
                continue;
            }

            $existingB2bCnpj = $this->clean((string) $existingCustomer->getData('b2b_cnpj'));

            if ($existingB2bCnpj !== $cnpjDigits) {
                continue;
            }

            throw new InputException(
                __(
                    'O CNPJ informado já está vinculado a outro cliente (%1).',
                    $this->maskEmail((string) $existingCustomer->getData('email'))
                )
            );
        }
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || strpos($email, '@') === false) {
            return $email !== '' ? $email : (string) __('desconhecido');
        }

        [$localPart, $domain] = explode('@', $email, 2);
        if ($localPart === '') {
            return '***@' . $domain;
        }

        if (strlen($localPart) === 1) {
            return $localPart . '***@' . $domain;
        }

        return substr($localPart, 0, 1) . str_repeat('*', max(2, strlen($localPart) - 1)) . '@' . $domain;
    }
}
