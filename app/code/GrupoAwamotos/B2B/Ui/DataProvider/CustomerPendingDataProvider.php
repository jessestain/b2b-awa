<?php

/**
 * Customer Pending Data Provider
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Ui\DataProvider;

use GrupoAwamotos\B2B\Model\CnaeClassifier;
use GrupoAwamotos\B2B\Model\Customer\Attribute\Source\ApprovalStatus;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class CustomerPendingDataProvider extends AbstractDataProvider
{
    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collectionFactory = $collectionFactory;
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * Get collection
     *
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
                'b2b_phone',
                'b2b_person_type',
                'b2b_receita_situacao',
                'erp_customer_sync_status',
                'b2b_registration_campaign',
                'b2b_origin_host',
                'b2b_utm_source',
                'b2b_utm_medium',
                'b2b_utm_campaign',
                'b2b_cnae_profile',
                'b2b_approval_score',
                'b2b_last_erp_sync_at',
            ]);
            $this->collection->addFieldToSelect('created_at');
            $this->collection->addAttributeToFilter(
                'b2b_approval_status',
                ['eq' => ApprovalStatus::STATUS_PENDING]
            );
        }

        return $this->collection;
    }

    /**
     * Get data
     *
     * @return array
     */
    public function getData()
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
            $row['created_at_display'] = $this->formatBrDate((string) ($row['created_at'] ?? ''));
            $cnae = (string) ($row['b2b_cnae_profile'] ?? '');
            if ($cnae !== '') {
                $row['b2b_cnae_profile'] = $this->cnaeLabel($cnae);
            }
            $items[] = $row;
        }

        $items = $this->attachAttendants($items);
        $items = $this->attachLastErpSync($items);

        return [
            'totalRecords' => $this->getCollection()->getSize(),
            'items' => $items,
        ];
    }

    private function cnaeLabel(string $code): string
    {
        return match ($code) {
            CnaeClassifier::PROFILE_DIRECT => 'Perfil Direto (Motos)',
            CnaeClassifier::PROFILE_ADJACENT => 'Perfil Adjacente (Automotivo)',
            CnaeClassifier::PROFILE_OFF => 'Fora do Perfil',
            default => $code,
        };
    }

    private function formatBrDate(string $createdAt): string
    {
        if ($createdAt === '' || $createdAt === '0000-00-00 00:00:00') {
            return '';
        }

        $ts = strtotime($createdAt);
        if ($ts === false) {
            return $createdAt;
        }

        return date('d/m/Y H:i', $ts);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function attachAttendants(array $items): array
    {
        $ids = $this->extractCustomerIds($items);
        if ($ids === []) {
            return $items;
        }

        $connection = $this->getCollection()->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    ['ca' => $connection->getTableName('grupoawamotos_b2b_customer_attendant')],
                    ['customer_id', 'attendant_id']
                )
                ->join(
                    ['att' => $connection->getTableName('grupoawamotos_b2b_attendants')],
                    'att.attendant_id = ca.attendant_id',
                    ['name']
                )
                ->where('ca.customer_id IN (?)', $ids)
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['customer_id']] = (string) $row['name'];
        }

        foreach ($items as &$item) {
            $item['attendant_name'] = $map[(int) ($item['entity_id'] ?? 0)] ?? '';
        }
        unset($item);

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function attachLastErpSync(array $items): array
    {
        $ids = $this->extractCustomerIds($items);
        if ($ids === []) {
            return $items;
        }

        $connection = $this->getCollection()->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($connection->getTableName('grupoawamotos_b2b_sectra_sync_log'), [
                    'customer_id',
                    'last_at' => new \Magento\Framework\DB\Sql\Expression('MAX(created_at)'),
                ])
                ->where('customer_id IN (?)', $ids)
                ->group('customer_id')
        );

        foreach ($items as &$item) {
            $customerId = (int) ($item['entity_id'] ?? 0);
            $raw = (string) ($item['b2b_last_erp_sync_at'] ?? ($pairs[$customerId] ?? ''));
            $item['last_erp_sync_display'] = $this->formatBrDate($raw);
        }
        unset($item);

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return int[]
     */
    private function extractCustomerIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = (int) ($item['entity_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
