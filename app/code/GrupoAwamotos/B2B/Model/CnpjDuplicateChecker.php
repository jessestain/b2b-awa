<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model;

use GrupoAwamotos\B2B\Service\CnpjValidator as CnpjChecksum;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;

class CnpjDuplicateChecker
{
    private CustomerRepositoryInterface $customerRepository;
    private CustomerCollectionFactory $customerCollectionFactory;
    private CnpjChecksum $cnpjChecksum;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        CustomerCollectionFactory $customerCollectionFactory,
        ?CnpjChecksum $cnpjChecksum = null
    ) {
        $this->customerRepository = $customerRepository;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->cnpjChecksum = $cnpjChecksum ?? new CnpjChecksum();
    }

    /**
     * @return array{customer_id:int,email:string,cnpj:string}|null
     */
    public function findConflict(int $customerId): ?array
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Exception) {
            return null;
        }

        $cnpjAttr = $customer->getCustomAttribute('b2b_cnpj');
        if ($cnpjAttr === null) {
            return null;
        }

        $cnpjDigits = $this->normalizeDocument((string) $cnpjAttr->getValue());
        if ($cnpjDigits === '') {
            return null;
        }

        $formattedCnpj = $this->cnpjChecksum->format($cnpjDigits);

        $collection = $this->customerCollectionFactory->create();
        $collection->addAttributeToFilter('b2b_cnpj', ['in' => [$cnpjDigits, $formattedCnpj]]);
        $collection->addFieldToFilter('entity_id', ['neq' => $customerId]);
        $collection->setPageSize(1);

        foreach ($collection as $conflict) {
            $conflictCnpj = $this->normalizeDocument((string) $conflict->getData('b2b_cnpj'));
            if ($conflictCnpj !== $cnpjDigits) {
                continue;
            }

            return [
                'customer_id' => (int) $conflict->getId(),
                'email' => (string) $conflict->getEmail(),
                'cnpj' => $formattedCnpj,
            ];
        }

        return null;
    }

    public function hasDuplicate(int $customerId): bool
    {
        return $this->findConflict($customerId) !== null;
    }

    private function normalizeDocument(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
