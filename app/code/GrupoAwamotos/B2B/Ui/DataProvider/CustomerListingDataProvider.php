<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\DataProvider;

use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class CustomerListingDataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        private readonly CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return \Magento\Customer\Model\ResourceModel\Customer\Collection
     */
    public function getCollection()
    {
        if (!$this->collection) {
            $this->collection = $this->collectionFactory->create();
            $this->collection->addAttributeToSelect([
                'firstname',
                'lastname',
                'email',
                'b2b_approval_status',
                'b2b_cnpj',
                'b2b_razao_social',
                'b2b_person_type',
                'b2b_cnae_code',
                'b2b_cnae_description',
                'b2b_cnae_profile',
                'created_at',
            ]);
        }

        return $this->collection;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = [];
        foreach ($this->getCollection() as $customer) {
            $row = $customer->getData();
            unset(
                $row['password_hash'],
                $row['rp_token'],
                $row['rp_token_created_at']
            );
            $row['name'] = trim(
                (string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? '')
            );
            $items[] = $row;
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items' => $items,
        ];
    }
}
