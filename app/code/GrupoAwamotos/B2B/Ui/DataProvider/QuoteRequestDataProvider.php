<?php

/**
 * Quote Request DataProvider
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\DataProvider;

use GrupoAwamotos\B2B\Model\ResourceModel\QuoteRequest\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class QuoteRequestDataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * Get data in the format expected by UI listings.
     *
     * A listing (Magento_Ui/js/grid/provider) reads data.items and data.totalRecords.
     * Returning a map keyed by id (form format) left data.items undefined, which made
     * the actions column fail with "Cannot read properties of undefined (reading 'map')".
     *
     * @return array{totalRecords: int, items: array<int, array<string, mixed>>}
     */
    public function getData()
    {
        $collection = $this->getCollection();
        if (!$collection->isLoaded()) {
            $collection->load();
        }

        $items = [];
        foreach ($collection->getItems() as $item) {
            $items[] = $item->getData();
        }

        return [
            'totalRecords' => (int) $collection->getSize(),
            'items' => $items,
        ];
    }
}
