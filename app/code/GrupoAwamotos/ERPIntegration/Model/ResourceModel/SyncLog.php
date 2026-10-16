<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SyncLog extends AbstractDb
{
    /**
     * The sync log stores families of entity_type values, e.g. order_pull /
     * order_status / sectra_b2b_order for orders and price / price_delta for
     * prices. The console and the admin dashboard address one logical name per
     * family. Without this map a lookup of "order" matches no row at all, so a
     * running integration is reported as "never ran".
     */
    public const TYPE_FAMILIES = [
        'order' => ['order_pull', 'order_status', 'sectra_b2b_order'],
        'price' => ['price', 'price_delta'],
        'customer' => ['customer', 'customer_auto_link', 'sectra_b2b_customer'],
    ];

    /**
     * Expand a logical sync type into the concrete entity_type values stored in the log.
     *
     * @param string|array<int,string>|null $entityType
     * @return array<int,string>|null null means "no filter"
     */
    public function expandTypes($entityType): ?array
    {
        if ($entityType === null || $entityType === '' || $entityType === []) {
            return null;
        }

        $requested = is_array($entityType) ? $entityType : [$entityType];
        $expanded = [];

        foreach ($requested as $type) {
            if (isset(self::TYPE_FAMILIES[$type])) {
                $expanded = array_merge($expanded, self::TYPE_FAMILIES[$type]);
                continue;
            }

            $expanded[] = $type;
        }

        return array_values(array_unique($expanded));
    }

    protected function _construct(): void
    {
        $this->_init('grupoawamotos_erp_sync_log', 'log_id');
    }

    public function addLog(
        string $entityType,
        string $direction,
        string $status,
        string $message = '',
        ?string $erpCode = null,
        ?int $magentoId = null,
        ?int $recordsProcessed = null
    ): void {
        $this->getConnection()->insert(
            $this->getMainTable(),
            [
                'entity_type' => $entityType,
                'direction' => $direction,
                'status' => $status,
                'message' => $message,
                'erp_code' => $erpCode,
                'magento_id' => $magentoId,
                'records_processed' => $recordsProcessed,
            ]
        );
    }

    public function getEntityMap(string $entityType, string $erpCode): ?int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from('grupoawamotos_erp_entity_map', 'magento_entity_id')
            ->where('entity_type = ?', $entityType)
            ->where('erp_code = ?', $erpCode);

        $result = $connection->fetchOne($select);
        return $result ? (int) $result : null;
    }

    public function setEntityMap(
        string $entityType,
        string $erpCode,
        int $magentoEntityId,
        ?string $syncHash = null
    ): void {
        if ($magentoEntityId <= 0) {
            return;
        }

        $connection = $this->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Check if exact mapping already exists
        $existing = $connection->fetchOne(
            $connection->select()
                ->from('grupoawamotos_erp_entity_map', 'map_id')
                ->where('entity_type = ?', $entityType)
                ->where('erp_code = ?', $erpCode)
        );

        if ($existing) {
            // Update existing mapping
            $connection->update(
                'grupoawamotos_erp_entity_map',
                [
                    'magento_entity_id' => $magentoEntityId,
                    'last_sync_at' => $now,
                    'sync_hash' => $syncHash,
                ],
                [
                    'entity_type = ?' => $entityType,
                    'erp_code = ?' => $erpCode,
                ]
            );
        } else {
            $connection->insert(
                'grupoawamotos_erp_entity_map',
                [
                    'entity_type' => $entityType,
                    'erp_code' => $erpCode,
                    'magento_entity_id' => $magentoEntityId,
                    'last_sync_at' => $now,
                    'sync_hash' => $syncHash,
                ]
            );
        }
    }

    public function getErpCodeByMagentoId(string $entityType, int $magentoEntityId): ?string
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from('grupoawamotos_erp_entity_map', 'erp_code')
            ->where('entity_type = ?', $entityType)
            ->where('magento_entity_id = ?', $magentoEntityId)
            ->order('last_sync_at DESC')
            ->limit(1);

        $result = $connection->fetchOne($select);
        return $result ?: null;
    }

    /**
     * Get sync hash for entity to detect changes
     */
    public function getEntityMapHash(string $entityType, string $erpCode): ?string
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from('grupoawamotos_erp_entity_map', 'sync_hash')
            ->where('entity_type = ?', $entityType)
            ->where('erp_code = ?', $erpCode);

        $result = $connection->fetchOne($select);
        return $result ?: null;
    }

    /**
     * Get recent sync logs
     */
    public function getRecentLogs(int $limit = 100, $entityType = null): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->order('created_at DESC')
            ->limit($limit);

        $types = $this->expandTypes($entityType);
        if ($types) {
            $select->where('entity_type IN (?)', $types);
        }

        return $connection->fetchAll($select);
    }

    /**
     * Get sync statistics for a time period
     */
    public function getSyncStats($entityType, int $days = 7): array
    {
        $types = $this->expandTypes($entityType);
        if (!$types) {
            return [];
        }

        $connection = $this->getConnection();
        $fromDate = (new \DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($this->getMainTable(), [
                'status',
                'total' => 'COUNT(*)',
                'total_records' => 'SUM(records_processed)',
            ])
            ->where('entity_type IN (?)', $types)
            ->where('created_at >= ?', $fromDate)
            ->group('status');

        return $connection->fetchAll($select);
    }

    /**
     * Clean old logs
     */
    public function cleanOldLogs(int $daysToKeep = 30): int
    {
        $connection = $this->getConnection();
        $cutoffDate = (new \DateTime())->modify("-{$daysToKeep} days")->format('Y-m-d H:i:s');

        return $connection->delete(
            $this->getMainTable(),
            ['created_at < ?' => $cutoffDate]
        );
    }
}
