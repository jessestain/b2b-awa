<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Model\Intelligence;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\CommercialPanel\Model\Config\TaskConfig;
use GrupoAwamotos\B2B\CommercialPanel\Model\ResourceModel\CommercialTask\CollectionFactory as TaskCollectionFactory;
use GrupoAwamotos\B2B\CommercialPanel\Model\ResourceModel\ContactLog\CollectionFactory as ContactLogCollectionFactory;
use GrupoAwamotos\B2B\Model\ResourceModel\CustomerAttendant\CollectionFactory as CustomerAttendantCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Ranking comercial expandido para supervisora (Fase 3).
 */
class CommercialRankingService
{
    /** @var string[] */
    private const OPEN_STATUSES = ['open', 'in_progress'];

    /** @var string[] */
    private const COMPLETED_STATUSES = ['done'];

    public function __construct(
        private readonly PortfolioScopeInterface $portfolioScope,
        private readonly TaskCollectionFactory $taskCollectionFactory,
        private readonly ContactLogCollectionFactory $contactLogCollectionFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly CustomerAttendantCollectionFactory $customerAttendantCollectionFactory,
        private readonly CommercialGoalProgressService $goalProgressService,
        private readonly ResourceConnection $resourceConnection,
        private readonly TaskConfig $taskConfig
    ) {
    }

    public function isRankingAllowed(): bool
    {
        return $this->portfolioScope->canViewAllPortfolios();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRanking(?string $periodMonth = null): array
    {
        if (!$this->isRankingAllowed()) {
            return [];
        }

        $periodMonth = $periodMonth ?? date('Y-m');
        [$periodStart, $periodEnd] = $this->periodBounds($periodMonth);
        $attendantIds = $this->portfolioScope->getVisibleAttendantIds();
        if ($attendantIds === []) {
            return [];
        }

        $names = $this->loadAttendantNames($attendantIds);
        $progress = $this->goalProgressService->getProgressForPeriod($periodMonth);
        $progressByAttendant = [];
        foreach ($progress as $row) {
            $progressByAttendant[(int) $row['attendant_id']] = $row;
        }

        $ranking = [];
        foreach ($attendantIds as $attendantId) {
            $customerIds = $this->getCustomerIdsForAttendant($attendantId);
            $contacts = $this->countContacts($attendantId, $periodStart, $periodEnd);
            $tasksDone = $this->countCompletedTasks($attendantId, $periodStart, $periodEnd);
            $reactivated = (int) ($progressByAttendant[$attendantId]['reactivated_actual'] ?? 0);
            $orders = $this->countOrders($customerIds, $periodStart, $periodEnd);
            $revenue = (float) ($progressByAttendant[$attendantId]['revenue_actual'] ?? 0.0);

            $ranking[] = [
                'attendant_id' => $attendantId,
                'attendant_name' => (string) ($names[$attendantId] ?? ''),
                'contacts_count' => $contacts,
                'tasks_completed' => $tasksDone,
                'reactivated_count' => $reactivated,
                'orders_count' => $orders,
                'revenue_total' => $revenue,
                'score' => $contacts * 2 + $tasksDone * 3 + $reactivated * 5 + $orders,
            ];
        }

        usort($ranking, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

        $position = 1;
        foreach ($ranking as &$row) {
            $row['position'] = $position++;
        }

        return $ranking;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodBounds(string $periodMonth): array
    {
        $start = $periodMonth . '-01 00:00:00';
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d 23:59:59');

        return [$start, $end];
    }

    private function countContacts(int $attendantId, string $from, string $to): int
    {
        $collection = $this->contactLogCollectionFactory->create();
        $collection->addFieldToFilter('attendant_id', $attendantId);
        $collection->addFieldToFilter('created_at', ['gteq' => $from]);
        $collection->addFieldToFilter('created_at', ['lteq' => $to]);

        return (int) $collection->getSize();
    }

    private function countCompletedTasks(int $attendantId, string $from, string $to): int
    {
        $collection = $this->taskCollectionFactory->create();
        $collection->addFieldToFilter('attendant_id', $attendantId);
        $collection->addFieldToFilter('status', ['in' => self::COMPLETED_STATUSES]);
        $collection->addFieldToFilter('updated_at', ['gteq' => $from]);
        $collection->addFieldToFilter('updated_at', ['lteq' => $to]);

        return (int) $collection->getSize();
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
        $collection->addFieldToFilter('created_at', ['gteq' => $from]);
        $collection->addFieldToFilter('created_at', ['lteq' => $to]);
        $collection->addFieldToFilter('state', ['nin' => ['canceled']]);

        return (int) $collection->getSize();
    }

    /**
     * @return int[]
     */
    private function getCustomerIdsForAttendant(int $attendantId): array
    {
        $collection = $this->customerAttendantCollectionFactory->create();
        $collection->addFieldToFilter('attendant_id', $attendantId);

        return array_map('intval', $collection->getColumnValues('customer_id'));
    }

    /**
     * @param int[] $attendantIds
     * @return array<int, string>
     */
    private function loadAttendantNames(array $attendantIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('grupoawamotos_b2b_attendants');
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['attendant_id', 'name'])
                ->where('attendant_id IN (?)', $attendantIds)
        );

        $result = [];
        foreach ($rows as $id => $name) {
            $result[(int) $id] = (string) $name;
        }

        return $result;
    }
}
