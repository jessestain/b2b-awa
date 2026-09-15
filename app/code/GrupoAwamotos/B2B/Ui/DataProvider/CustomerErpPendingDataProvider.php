<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\DataProvider;

use GrupoAwamotos\B2B\Model\Customer\ErpPendingQueueResolver;
use GrupoAwamotos\B2B\Model\Ui\CustomerGridEnricher;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\DataProvider\AbstractDataProvider;

class CustomerErpPendingDataProvider extends AbstractDataProvider
{
    private ResourceConnection $resourceConnection;

    private RequestInterface $request;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        ResourceConnection $resourceConnection,
        private readonly CustomerGridEnricher $gridEnricher,
        RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->request = $request;

        $collection = $collectionFactory->create();
        $collection->addAttributeToSelect([
            'firstname',
            'lastname',
            'email',
            'b2b_cnpj',
            'b2b_razao_social',
            'b2b_approval_status',
            'erp_customer_sync_status',
            'b2b_receita_situacao',
            'b2b_registration_campaign',
            'b2b_utm_source',
            'b2b_utm_medium',
            'b2b_utm_campaign',
            'b2b_origin_host',
            'b2b_last_erp_sync_at',
            'created_at',
        ]);

        $this->applyErpPendingFilter($collection);
        $this->applyRequestFilters($collection);

        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collection;
    }

    /**
     * @param \Magento\Customer\Model\ResourceModel\Customer\Collection $collection
     */
    private function applyErpPendingFilter($collection): void
    {
        $connection = $this->resourceConnection->getConnection();
        $mapTable = $this->resourceConnection->getTableName('oc_customer_id_map');
        $confirmedTable = $this->resourceConnection->getTableName('oc_customer_b2b_confirmed');
        $varcharTable = $this->resourceConnection->getTableName('customer_entity_varchar');

        $erpAttrId = $this->resolveAttributeId('erp_customer_sync_status');
        $approvalAttrId = $this->resolveAttributeId('b2b_approval_status');
        $cnpjAttrId = $this->resolveAttributeId('b2b_cnpj');
        $erpCodeAttrId = $this->resolveAttributeId('erp_code');

        $pendingStatuses = ErpPendingQueueResolver::ERP_PENDING_STATUSES;
        $validatedStatuses = ErpPendingQueueResolver::VALIDATED_STATUSES;

        $collection->getSelect()->joinLeft(
            ['b2b_map' => $mapTable],
            'b2b_map.magento_customer_id = e.entity_id',
            []
        )->joinLeft(
            ['erp_code_attr' => $varcharTable],
            'erp_code_attr.entity_id = e.entity_id AND erp_code_attr.attribute_id = ' . $erpCodeAttrId
                . " AND erp_code_attr.value REGEXP '^[0-9]+$'",
            []
        )->joinLeft(
            ['b2b_conf' => $confirmedTable],
            'b2b_conf.customer_id = COALESCE(NULLIF(CAST(erp_code_attr.value AS UNSIGNED), 0), b2b_map.old_oc_customer_id)',
            ['erp_confirmed' => 'b2b_conf.customer_id']
        )->joinLeft(
            ['erp_status' => $varcharTable],
            'erp_status.entity_id = e.entity_id AND erp_status.attribute_id = ' . $erpAttrId,
            []
        )->joinLeft(
            ['approval_status' => $varcharTable],
            'approval_status.entity_id = e.entity_id AND approval_status.attribute_id = ' . $approvalAttrId,
            []
        )->joinLeft(
            ['b2b_cnpj_attr' => $varcharTable],
            'b2b_cnpj_attr.entity_id = e.entity_id AND b2b_cnpj_attr.attribute_id = ' . $cnpjAttrId,
            []
        );

        $pendingIn = $connection->quoteInto('erp_status.value IN (?)', $pendingStatuses);

        $collection->getSelect()->where('b2b_conf.customer_id IS NULL');
        $collection->getSelect()->where($pendingIn);
        $collection->getSelect()->group('e.entity_id');
    }

    /**
     * @param \Magento\Customer\Model\ResourceModel\Customer\Collection $collection
     */
    private function applyRequestFilters($collection): void
    {
        $filters = $this->request->getParam('filters', []);
        if (!is_array($filters)) {
            return;
        }

        $map = [
            'entity_id' => 'entity_id',
            'email' => 'email',
            'b2b_cnpj' => 'b2b_cnpj',
            'b2b_razao_social' => 'b2b_razao_social',
            'erp_customer_sync_status' => 'erp_customer_sync_status',
            'b2b_receita_situacao' => 'b2b_receita_situacao',
            'b2b_registration_campaign' => 'b2b_registration_campaign',
            'b2b_origin_host' => 'b2b_origin_host',
            'b2b_utm_source' => 'b2b_utm_source',
            'b2b_utm_medium' => 'b2b_utm_medium',
            'b2b_utm_campaign' => 'b2b_utm_campaign',
            'attendant_name' => 'attendant_name',
        ];

        foreach ($map as $filterKey => $attribute) {
            if (!isset($filters[$filterKey]) || $filters[$filterKey] === '') {
                continue;
            }
            $value = $filters[$filterKey];
            if ($filterKey === 'entity_id' && is_string($value) && str_contains($value, '-')) {
                [$from, $to] = array_map('trim', explode('-', $value, 2));
                if ($from !== '') {
                    $collection->addFieldToFilter('entity_id', ['gteq' => (int) $from]);
                }
                if ($to !== '') {
                    $collection->addFieldToFilter('entity_id', ['lteq' => (int) $to]);
                }
                continue;
            }
            if ($attribute === 'entity_id') {
                $collection->addFieldToFilter('entity_id', ['eq' => (int) $value]);
                continue;
            }
            if ($filterKey === 'attendant_name') {
                continue;
            }
            $collection->addAttributeToFilter($attribute, ['like' => '%' . $value . '%']);
        }

        if (!empty($filters['created_at']['from'])) {
            $collection->addAttributeToFilter('created_at', ['gteq' => $filters['created_at']['from']]);
        }
        if (!empty($filters['created_at']['to'])) {
            $collection->addAttributeToFilter('created_at', ['lteq' => $filters['created_at']['to']]);
        }
    }

    public function getData(): array
    {
        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $attendantFilter = '';
        $requestFilters = $this->request->getParam('filters', []);
        if (is_array($requestFilters) && !empty($requestFilters['attendant_name'])) {
            $attendantFilter = mb_strtolower((string) $requestFilters['attendant_name']);
        }

        $rows = [];
        foreach ($this->getCollection() as $item) {
            $row = $item->getData();
            unset(
                $row['password_hash'],
                $row['rp_token'],
                $row['rp_token_created_at']
            );
            $rows[] = $row;
        }

        $items = $this->gridEnricher->enrichRows($rows);

        if ($attendantFilter !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $row): bool => str_contains(
                    mb_strtolower((string) ($row['attendant_name'] ?? '')),
                    $attendantFilter
                )
            ));
        }

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items' => $items,
        ];
    }

    private function resolveAttributeId(string $code): int
    {
        $connection = $this->resourceConnection->getConnection();

        return (int) $connection->fetchOne(
            "SELECT ea.attribute_id FROM eav_attribute ea
             INNER JOIN eav_entity_type et ON et.entity_type_id = ea.entity_type_id
             WHERE ea.attribute_code = ? AND et.entity_type_code = 'customer'",
            [$code]
        );
    }
}
