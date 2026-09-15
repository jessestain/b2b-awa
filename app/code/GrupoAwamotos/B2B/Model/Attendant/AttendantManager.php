<?php

/**
 * Gerenciador de Atendentes B2B
 * Sistema de distribuição e gestão de clientes por atendente
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Attendant;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class AttendantManager
{
    private const TABLE_ATTENDANTS = 'grupoawamotos_b2b_attendants';
    private const TABLE_CUSTOMER_ATTENDANT = 'grupoawamotos_b2b_customer_attendant';
    private const TABLE_ATTENDANT_LOG = 'grupoawamotos_b2b_attendant_log';

    private ScopeConfigInterface $scopeConfig;
    private ResourceConnection $resource;
    private LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->resource = $resource;
        $this->logger = $logger;
    }

    /**
     * Obtém todos os atendentes ativos
     */
    public function getActiveAttendants(?string $department = null): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_ATTENDANTS);

        $select = $connection->select()
            ->from($table)
            ->where('is_active = ?', 1)
            ->order('name ASC');

        if ($department) {
            $select->where('department = ?', $department);
        }

        return $connection->fetchAll($select);
    }

    /**
     * Obtém atendente por ID
     */
    public function getAttendantById(int $attendantId): ?array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_ATTENDANTS);

        $select = $connection->select()
            ->from($table)
            ->where('attendant_id = ?', $attendantId);

        $result = $connection->fetchRow($select);
        return $result ?: null;
    }

    /**
     * Resolve attendant from a B2B registration referral (URL id or ERP VENDPREF).
     */
    public function resolveAttendantIdFromReferral(?int $attendantId, ?int $erpSellerCode): ?int
    {
        if ($attendantId !== null && $attendantId > 0) {
            $row = $this->getAttendantById($attendantId);
            if (is_array($row) && (int) ($row['is_active'] ?? 0) === 1) {
                return (int) $row['attendant_id'];
            }
        }

        if ($erpSellerCode !== null && $erpSellerCode > 0) {
            $row = $this->getActiveAttendantByErpSellerCode($erpSellerCode);
            if (is_array($row)) {
                return (int) $row['attendant_id'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveAttendantByErpSellerCode(int $erpSellerCode): ?array
    {
        if ($erpSellerCode <= 0) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_ATTENDANTS);
        $select = $connection->select()
            ->from($table)
            ->where('erp_seller_code = ?', $erpSellerCode)
            ->where('is_active = ?', 1)
            ->where('name NOT LIKE ?', '%SMOKE%')
            ->where('name NOT LIKE ?', '%UAT%')
            ->order('attendant_id ASC')
            ->limit(1);

        $result = $connection->fetchRow($select);

        return $result ?: null;
    }

    /**
     * Obtém atendente responsável por um cliente
     */
    public function getCustomerAttendant(int $customerId): ?array
    {
        $connection = $this->resource->getConnection();
        $tableMap = $this->resource->getTableName(self::TABLE_CUSTOMER_ATTENDANT);
        $tableAttendant = $this->resource->getTableName(self::TABLE_ATTENDANTS);

        $select = $connection->select()
            ->from(['ca' => $tableMap])
            ->join(
                ['a' => $tableAttendant],
                'ca.attendant_id = a.attendant_id',
                ['name', 'email', 'phone', 'whatsapp', 'department', 'is_active',
                 'chatwoot_agent_id', 'erp_seller_code', 'customer_count', 'max_customers']
            )
            ->where('ca.customer_id = ?', $customerId)
            ->where('a.is_active = ?', 1);

        $result = $connection->fetchRow($select);
        return $result ?: null;
    }

    /**
     * Atribui cliente a um atendente
     */
    public function assignCustomerToAttendant(int $customerId, int $attendantId, ?string $reason = null): bool
    {
        $connection = $this->resource->getConnection();
        $tableMap = $this->resource->getTableName(self::TABLE_CUSTOMER_ATTENDANT);
        $tableLog = $this->resource->getTableName(self::TABLE_ATTENDANT_LOG);

        try {
            // Remove atribuição anterior se existir
            $oldAttendant = $this->getCustomerAttendant($customerId);

            $connection->insertOnDuplicate($tableMap, [
                'customer_id' => $customerId,
                'attendant_id' => $attendantId,
                'assigned_at' => date('Y-m-d H:i:s')
            ], ['attendant_id', 'assigned_at']);

            // Registra log
            $connection->insert($tableLog, [
                'customer_id' => $customerId,
                'attendant_id' => $attendantId,
                'previous_attendant_id' => $oldAttendant['attendant_id'] ?? null,
                'action' => 'assigned',
                'reason' => $reason,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Atualiza contador do atendente
            $this->updateAttendantCustomerCount($attendantId);
            if ($oldAttendant) {
                $this->updateAttendantCustomerCount((int) $oldAttendant['attendant_id']);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error assigning customer to attendant: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Atribui cliente automaticamente ao atendente com menos clientes
     * Respeita max_customers — não atribui a atendentes que já atingiram o limite
     */
    public function autoAssignCustomer(int $customerId, ?string $department = null): ?int
    {
        // Verificar se já tem atendente
        $existing = $this->getCustomerAttendant($customerId);
        if ($existing) {
            return (int) $existing['attendant_id'];
        }

        $attendant = $this->getAttendantWithLeastCustomers($department);

        if ($attendant) {
            $this->assignCustomerToAttendant(
                $customerId,
                (int) $attendant['attendant_id'],
                'Atribuição automática - distribuição equilibrada'
            );
            return (int) $attendant['attendant_id'];
        }

        $this->logger->warning('[AttendantManager] No available attendant for auto-assign (all at max capacity)');
        return null;
    }

    /**
     * Obtém atendente com menos clientes ativos
     * Respeita max_customers — só retorna atendentes abaixo do limite.
     * Exclui contas SMOKE/UAT da distribuição automática de produção.
     */
    public function getAttendantWithLeastCustomers(?string $department = null): ?array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_ATTENDANTS);

        $select = $connection->select()
            ->from($table)
            ->where('is_active = ?', 1)
            ->where('customer_count < max_customers')
            ->where('name NOT LIKE ?', '%SMOKE%')
            ->where('name NOT LIKE ?', '%UAT%')
            ->order('customer_count ASC')
            ->limit(1);

        if ($department) {
            $select->where('department = ?', $department);
        }

        $result = $connection->fetchRow($select);
        return $result ?: null;
    }

    /**
     * Obtém clientes de um atendente com dados do customer
     */
    public function getAttendantCustomers(int $attendantId, int $limit = 100, int $offset = 0): array
    {
        $connection = $this->resource->getConnection();
        $tableMap = $this->resource->getTableName(self::TABLE_CUSTOMER_ATTENDANT);

        $select = $connection->select()
            ->from(['ca' => $tableMap])
            ->joinLeft(
                ['ce' => $this->resource->getTableName('customer_entity')],
                'ca.customer_id = ce.entity_id',
                ['firstname', 'lastname', 'email' => 'ce.email']
            )
            ->where('ca.attendant_id = ?', $attendantId)
            ->order('ca.assigned_at DESC')
            ->limit($limit, $offset);

        return $connection->fetchAll($select);
    }

    /**
     * Conta clientes de um atendente
     */
    public function countAttendantCustomers(int $attendantId): int
    {
        $connection = $this->resource->getConnection();
        $tableMap = $this->resource->getTableName(self::TABLE_CUSTOMER_ATTENDANT);

        $select = $connection->select()
            ->from($tableMap, ['count' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
            ->where('attendant_id = ?', $attendantId);

        return (int) $connection->fetchOne($select);
    }

    /**
     * Atualiza contador de clientes do atendente
     */
    public function updateAttendantCustomerCount(int $attendantId): void
    {
        $count = $this->countAttendantCustomers($attendantId);

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_ATTENDANTS);

        $connection->update(
            $table,
            ['customer_count' => $count, 'updated_at' => date('Y-m-d H:i:s')],
            ['attendant_id = ?' => $attendantId]
        );
    }

    /**
     * Recalcula contadores de TODOS os atendentes de uma vez
     */
    public function recalculateAllCounts(): array
    {
        $connection = $this->resource->getConnection();
        $tableAttendants = $this->resource->getTableName(self::TABLE_ATTENDANTS);
        $tableMap = $this->resource->getTableName(self::TABLE_CUSTOMER_ATTENDANT);

        // Capture old counts
        $oldCounts = $connection->fetchPairs(
            $connection->select()->from($tableAttendants, ['attendant_id', 'customer_count'])
        );

        // Single query: UPDATE with subquery
        $connection->query(
            "UPDATE {$tableAttendants} a SET customer_count = (
                SELECT COUNT(*) FROM {$tableMap} ca WHERE ca.attendant_id = a.attendant_id
            )"
        );

        // Return summary with old vs new
        $select = $connection->select()
            ->from($tableAttendants, ['attendant_id', 'name', 'customer_count', 'max_customers'])
            ->where('is_active = ?', 1)
            ->order('customer_count DESC');

        $rows = $connection->fetchAll($select);
        $result = [];
        foreach ($rows as $row) {
            $id = $row['attendant_id'];
            $result[] = [
                'attendant_id' => $id,
                'name' => $row['name'],
                'old_count' => (int) ($oldCounts[$id] ?? 0),
                'real_count' => (int) $row['customer_count'],
                'max_customers' => (int) $row['max_customers'],
            ];
        }

        return $result;
    }

    /**
     * Cria ou atualiza atendente
     */
    public function saveAttendant(array $data): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_ATTENDANTS);

        $chatwootAgentId = null;
        if (isset($data['chatwoot_agent_id']) && $data['chatwoot_agent_id'] !== '') {
            if (!is_numeric($data['chatwoot_agent_id'])) {
                throw new LocalizedException(__('O ID do agente Chatwoot deve ser numérico.'));
            }
            $chatwootAgentId = (int) $data['chatwoot_agent_id'];
        }

        $maxCustomers = 100;
        if (isset($data['max_customers']) && $data['max_customers'] !== '') {
            if (!is_numeric($data['max_customers'])) {
                throw new LocalizedException(__('O limite máximo de clientes deve ser numérico.'));
            }
            $maxCustomers = max(0, (int) $data['max_customers']);
        }

        $adminUserId = null;
        if (isset($data['admin_user_id']) && $data['admin_user_id'] !== '') {
            if (!is_numeric($data['admin_user_id'])) {
                throw new LocalizedException(__('O usuário administrador informado é inválido.'));
            }
            $adminUserId = (int) $data['admin_user_id'];
        }

        $attendantData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'department' => $data['department'] ?? 'sales',
            'is_active' => $data['is_active'] ?? 1,
            'max_customers' => $maxCustomers,
            'chatwoot_agent_id' => $chatwootAgentId,
            'erp_seller_code' => !empty($data['erp_seller_code']) ? trim((string) $data['erp_seller_code']) : null,
            'admin_user_id' => $adminUserId,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if (!empty($data['attendant_id'])) {
            $connection->update($table, $attendantData, ['attendant_id = ?' => $data['attendant_id']]);
            return (int) $data['attendant_id'];
        } else {
            $attendantData['created_at'] = date('Y-m-d H:i:s');
            $attendantData['customer_count'] = 0;
            $connection->insert($table, $attendantData);
            return (int) $connection->lastInsertId($table);
        }
    }

    /**
     * Remove atendente (soft delete - desativa)
     */
    public function deactivateAttendant(int $attendantId, ?int $transferToId = null): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE_ATTENDANTS);

        try {
            // Transfere clientes se especificado
            if ($transferToId) {
                $this->transferAllCustomers($attendantId, $transferToId);
            }

            // Desativa atendente
            $connection->update(
                $table,
                ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['attendant_id = ?' => $attendantId]
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error deactivating attendant: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Transfere todos os clientes de um atendente para outro
     */
    public function transferAllCustomers(int $fromAttendantId, int $toAttendantId): int
    {
        $connection = $this->resource->getConnection();
        $tableMap = $this->resource->getTableName(self::TABLE_CUSTOMER_ATTENDANT);
        $tableLog = $this->resource->getTableName(self::TABLE_ATTENDANT_LOG);
        $now = date('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($tableMap, ['customer_id'])
            ->where('attendant_id = ?', $fromAttendantId);
        $customerIds = $connection->fetchCol($select);

        if (empty($customerIds)) {
            return 0;
        }

        // Batch UPDATE
        $connection->update(
            $tableMap,
            ['attendant_id' => $toAttendantId, 'assigned_at' => $now],
            ['attendant_id = ?' => $fromAttendantId]
        );

        // Batch INSERT de logs
        $logData = [];
        foreach ($customerIds as $customerId) {
            $logData[] = [
                'customer_id' => (int) $customerId,
                'attendant_id' => $toAttendantId,
                'previous_attendant_id' => $fromAttendantId,
                'action' => 'transferred',
                'reason' => 'Transferência em massa',
                'created_at' => $now
            ];
        }
        $connection->insertMultiple($tableLog, $logData);

        // Atualiza contadores
        $this->updateAttendantCustomerCount($fromAttendantId);
        $this->updateAttendantCustomerCount($toAttendantId);

        return count($customerIds);
    }

    /**
     * Obtém estatísticas de um atendente
     */
    public function getAttendantStats(int $attendantId, int $days = 30): array
    {
        $connection = $this->resource->getConnection();
        $tableLog = $this->resource->getTableName(self::TABLE_ATTENDANT_LOG);

        $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $selectNew = $connection->select()
            ->from($tableLog, ['count' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
            ->where('attendant_id = ?', $attendantId)
            ->where('action = ?', 'assigned')
            ->where('created_at >= ?', $startDate);

        $newCustomers = (int) $connection->fetchOne($selectNew);
        $totalCustomers = $this->countAttendantCustomers($attendantId);

        // Last assignment date
        $selectLast = $connection->select()
            ->from($tableLog, ['created_at'])
            ->where('attendant_id = ?', $attendantId)
            ->where('action = ?', 'assigned')
            ->order('created_at DESC')
            ->limit(1);
        $lastAssignment = $connection->fetchOne($selectLast);

        return [
            'total_customers' => $totalCustomers,
            'new_customers_period' => $newCustomers,
            'last_assignment' => $lastAssignment ?: null,
            'period_days' => $days
        ];
    }

    /**
     * Obtém resumo de todos os atendentes
     */
    public function getAttendantsSummary(?string $department = null): array
    {
        $attendants = $this->getActiveAttendants($department);
        $summary = [];

        foreach ($attendants as $attendant) {
            $stats = $this->getAttendantStats((int) $attendant['attendant_id']);
            $summary[] = array_merge($attendant, $stats);
        }

        return $summary;
    }

    /**
     * Redistribui clientes equilibradamente entre atendentes ativos.
     * Respeita max_customers. Processa em batches para performance.
     */
    public function redistributeCustomers(?string $department = null): array
    {
        $attendants = $this->getActiveAttendants($department);

        if (count($attendants) < 2) {
            return ['redistributed' => 0, 'message' => 'Mínimo de 2 atendentes necessários'];
        }

        $connection = $this->resource->getConnection();
        $tableMap = $this->resource->getTableName(self::TABLE_CUSTOMER_ATTENDANT);
        $tableLog = $this->resource->getTableName(self::TABLE_ATTENDANT_LOG);

        // Get all assigned customer IDs
        $allCustomerIds = $connection->fetchCol(
            $connection->select()->from($tableMap, ['customer_id'])->order('customer_id ASC')
        );

        $totalCustomers = count($allCustomerIds);
        if ($totalCustomers === 0) {
            return ['redistributed' => 0, 'total_customers' => 0, 'attendants' => count($attendants)];
        }

        $attendantCount = count($attendants);
        $perAttendant = (int) ceil($totalCustomers / $attendantCount);
        $now = date('Y-m-d H:i:s');
        $redistributed = 0;
        $offset = 0;

        foreach ($attendants as $index => $attendant) {
            $attendantId = (int) $attendant['attendant_id'];
            $max = (int) $attendant['max_customers'];

            // Target count: min between fair share and max_customers
            $targetCount = min($perAttendant, $max);
            if ($index === $attendantCount - 1) {
                // Last attendant gets the remainder
                $targetCount = min($totalCustomers - $offset, $max);
            }

            $batch = array_slice($allCustomerIds, $offset, $targetCount);
            if (empty($batch)) {
                break;
            }

            // Batch update
            $connection->update(
                $tableMap,
                ['attendant_id' => $attendantId, 'assigned_at' => $now],
                ['customer_id IN (?)' => $batch]
            );

            $redistributed += count($batch);
            $offset += count($batch);
        }

        // Recalculate all counts
        $this->recalculateAllCounts();

        $this->logger->info('[AttendantManager] Redistribution completed', [
            'redistributed' => $redistributed,
            'total' => $totalCustomers,
            'attendants' => $attendantCount,
        ]);

        return [
            'redistributed' => $redistributed,
            'total_customers' => $totalCustomers,
            'attendants' => $attendantCount,
            'target_per_attendant' => $perAttendant
        ];
    }


    /**
     * Atribui automaticamente clientes sem atendente (em massa).
     * Distribui equilibradamente respeitando max_customers.
     *
     * @return array{assigned: int, skipped: int, remaining: int, total_unassigned: int}
     */
    public function assignUnassignedCustomers(?string $department = null, int $batchLimit = 500): array
    {
        $connection = $this->resource->getConnection();
        $tableMap = $this->resource->getTableName(self::TABLE_CUSTOMER_ATTENDANT);
        $tableLog = $this->resource->getTableName(self::TABLE_ATTENDANT_LOG);

        // Get unassigned customer IDs
        $select = $connection->select()
            ->from(
                ['ce' => $this->resource->getTableName('customer_entity')],
                ['entity_id']
            )
            ->joinLeft(
                ['ca' => $tableMap],
                'ce.entity_id = ca.customer_id',
                []
            )
            ->where('ca.customer_id IS NULL')
            ->order('ce.entity_id ASC')
            ->limit($batchLimit);

        $unassignedIds = $connection->fetchCol($select);

        if (empty($unassignedIds)) {
            return ['assigned' => 0, 'skipped' => 0, 'remaining' => 0, 'total_unassigned' => 0];
        }

        // Get attendants with capacity
        $attendants = $this->getActiveAttendants($department);
        $capacities = [];
        foreach ($attendants as $att) {
            $available = (int) $att['max_customers'] - (int) $att['customer_count'];
            if ($available > 0) {
                $capacities[(int) $att['attendant_id']] = $available;
            }
        }

        if (empty($capacities)) {
            return [
                'assigned' => 0,
                'skipped' => count($unassignedIds),
                'remaining' => count($unassignedIds),
                'total_unassigned' => count($unassignedIds),
                'message' => 'Todos os atendentes estão no limite máximo',
            ];
        }

        $assigned = 0;
        $skipped = 0;
        $now = date('Y-m-d H:i:s');
        $inserts = [];
        $logInserts = [];
        $attendantIds = array_keys($capacities);
        $attIndex = 0;

        foreach ($unassignedIds as $customerId) {
            // Find next attendant with capacity (round-robin)
            $found = false;
            $checked = 0;
            while ($checked < count($attendantIds)) {
                $attId = $attendantIds[$attIndex % count($attendantIds)];
                $attIndex++;
                $checked++;

                if ($capacities[$attId] > 0) {
                    $inserts[] = [
                        'customer_id' => (int) $customerId,
                        'attendant_id' => $attId,
                        'assigned_at' => $now,
                    ];
                    $logInserts[] = [
                        'customer_id' => (int) $customerId,
                        'attendant_id' => $attId,
                        'previous_attendant_id' => null,
                        'action' => 'assigned',
                        'reason' => 'Auto-assign em massa (equilibrado)',
                        'created_at' => $now,
                    ];
                    $capacities[$attId]--;
                    $assigned++;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $skipped++;
            }
        }

        // Batch insert
        if (!empty($inserts)) {
            $connection->insertMultiple($tableMap, $inserts);
            $connection->insertMultiple($tableLog, $logInserts);
        }

        // Count remaining unassigned
        $remainingCount = (int) $connection->fetchOne(
            $connection->select()->from(
                ['ce' => $this->resource->getTableName('customer_entity')],
                ['cnt' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')]
            )->joinLeft(
                ['ca' => $tableMap],
                'ce.entity_id = ca.customer_id',
                []
            )->where('ca.customer_id IS NULL')
        );

        // Recalculate counts
        $this->recalculateAllCounts();

        $this->logger->info('[AttendantManager] Batch auto-assign completed', [
            'assigned' => $assigned,
            'skipped' => $skipped,
            'remaining' => $remainingCount,
        ]);

        return [
            'assigned' => $assigned,
            'skipped' => $skipped,
            'remaining' => $remainingCount,
            'total_unassigned' => count($unassignedIds),
        ];
    }
}
