<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Model\Intelligence;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\CommercialPanel\Model\ResourceModel\CommercialTask\CollectionFactory as TaskCollectionFactory;
use GrupoAwamotos\B2B\CommercialPanel\Model\ResourceModel\ContactLog\CollectionFactory as ContactLogCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\CustomerAttendant\CollectionFactory as CustomerAttendantCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Relatório comercial agregado com filtros e escopo de carteira.
 */
class CommercialReportService
{
    public function __construct(
        private readonly PortfolioScopeInterface $portfolioScope,
        private readonly ContactLogCollectionFactory $contactLogCollectionFactory,
        private readonly TaskCollectionFactory $taskCollectionFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly CustomerAttendantCollectionFactory $customerAttendantCollectionFactory,
        private readonly InactiveCustomerService $inactiveCustomerService,
        private readonly CommercialGoalProgressService $goalProgressService,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function buildReport(array $filters = []): array
    {
        $dateFrom = (string) ($filters['date_from'] ?? date('Y-m-01'));
        $dateTo = (string) ($filters['date_to'] ?? date('Y-m-d'));
        $attendantFilter = isset($filters['attendant_id']) ? (int) $filters['attendant_id'] : null;
        $customerStatus = (string) ($filters['customer_status'] ?? '');
        $taskType = (string) ($filters['task_type'] ?? '');

        $attendantIds = $this->resolveAttendantIds($attendantFilter);
        $customerIds = $this->resolveCustomerIds($attendantIds, $customerStatus);

        $contacts = $this->countContacts($attendantIds, $customerIds, $dateFrom, $dateTo);
        $tasks = $this->countTasks($attendantIds, $customerIds, $dateFrom, $dateTo, $taskType);
        $inactive = $this->inactiveCustomerService->countInactiveCustomers(30);
        $reactivated = $this->sumReactivated($attendantIds, $dateFrom);
        $cartsTreated = $this->countAbandonedCartsTreated($customerIds, $dateFrom, $dateTo);
        $orders = $this->countOrders($customerIds, $dateFrom, $dateTo);

        return [
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'attendant_id' => $attendantFilter,
                'customer_status' => $customerStatus,
                'task_type' => $taskType,
            ],
            'summary' => [
                'contacts' => $contacts,
                'tasks' => $tasks,
                'inactive_customers' => $inactive,
                'reactivated_customers' => $reactivated,
                'abandoned_carts_treated' => $cartsTreated,
                'orders_generated' => $orders,
            ],
            'goal_progress' => $this->goalProgressService->getProgressForPeriod(substr($dateFrom, 0, 7)),
        ];
    }

    /**
     * @return int[]
     */
    private function resolveAttendantIds(?int $attendantFilter): array
    {
        $visible = $this->portfolioScope->getVisibleAttendantIds();
        if ($attendantFilter === null || $attendantFilter <= 0) {
            return $visible;
        }

        if (!in_array($attendantFilter, $visible, true)) {
            return [];
        }

        return [$attendantFilter];
    }

    /**
     * @param int[] $attendantIds
     * @return int[]
     */
    private function resolveCustomerIds(array $attendantIds, string $customerStatus): array
    {
        if ($attendantIds === []) {
            return [];
        }

        $collection = $this->customerAttendantCollectionFactory->create();
        $collection->addFieldToFilter('attendant_id', ['in' => $attendantIds]);

        if ($customerStatus !== '') {
            $collection->addFieldToFilter('commercial_status', $customerStatus);
        }

        return array_map('intval', $collection->getColumnValues('customer_id'));
    }

    /**
     * @param int[] $attendantIds
     * @param int[] $customerIds
     */
    private function countContacts(array $attendantIds, array $customerIds, string $from, string $to): int
    {
        if ($attendantIds === [] || $customerIds === []) {
            return 0;
        }

        $collection = $this->contactLogCollectionFactory->create();
        $collection->addFieldToFilter('attendant_id', ['in' => $attendantIds]);
        $collection->addFieldToFilter('customer_id', ['in' => $customerIds]);
        $collection->addFieldToFilter('created_at', ['gteq' => $from . ' 00:00:00']);
        $collection->addFieldToFilter('created_at', ['lteq' => $to . ' 23:59:59']);

        return (int) $collection->getSize();
    }

    /**
     * @param int[] $attendantIds
     * @param int[] $customerIds
     */
    private function countTasks(array $attendantIds, array $customerIds, string $from, string $to, string $taskType): int
    {
        if ($attendantIds === [] || $customerIds === []) {
            return 0;
        }

        $collection = $this->taskCollectionFactory->create();
        $collection->addFieldToFilter('attendant_id', ['in' => $attendantIds]);
        $collection->addFieldToFilter('customer_id', ['in' => $customerIds]);
        $collection->addFieldToFilter('created_at', ['gteq' => $from . ' 00:00:00']);
        $collection->addFieldToFilter('created_at', ['lteq' => $to . ' 23:59:59']);

        if ($taskType !== '') {
            $collection->addFieldToFilter('task_type', $taskType);
        }

        return (int) $collection->getSize();
    }

    /**
     * @param int[] $attendantIds
     */
    private function sumReactivated(array $attendantIds, string $dateFrom): int
    {
        $period = substr($dateFrom, 0, 7);
        $progress = $this->goalProgressService->getProgressForPeriod($period);
        $total = 0;

        foreach ($progress as $row) {
            if ($attendantIds !== [] && !in_array((int) $row['attendant_id'], $attendantIds, true)) {
                continue;
            }
            $total += (int) ($row['reactivated_actual'] ?? 0);
        }

        return $total;
    }

    /**
     * @param int[] $customerIds
     */
    private function countAbandonedCartsTreated(array $customerIds, string $from, string $to): int
    {
        if ($customerIds === []) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('grupoawamotos_abandoned_cart');
        if (!$connection->isTableExists($table)) {
            return 0;
        }

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['cnt' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
                ->where('customer_id IN (?)', $customerIds)
                ->where('recovered = ?', 1)
                ->where('updated_at >= ?', $from . ' 00:00:00')
                ->where('updated_at <= ?', $to . ' 23:59:59')
        );
    }

    /**
     * @param int[] $customerIds
     */
    private function countOrders(array $customerIds, string $from, string $to): int
    {
        if ($customerIds === []) {
            return 0;
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', ['in' => $customerIds]);
        $collection->addFieldToFilter('created_at', ['gteq' => $from . ' 00:00:00']);
        $collection->addFieldToFilter('created_at', ['lteq' => $to . ' 23:59:59']);
        $collection->addFieldToFilter('state', ['nin' => ['canceled']]);

        return (int) $collection->getSize();
    }
}
