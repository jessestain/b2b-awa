<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\CommercialPanel\Model\Intelligence;

use GrupoAwamotos\B2B\CommercialPanel\Api\PortfolioScopeInterface;
use GrupoAwamotos\B2B\Model\ResourceModel\CustomerAttendant\CollectionFactory as CustomerAttendantCollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * Clientes sem compra há X dias dentro da carteira visível.
 */
class InactiveCustomerService
{
    public const STATUS_IN_PROGRESS = 'em_atendimento';

    /** @var int[] */
    public const PRESET_DAYS = [30, 60, 90, 120];

    public function __construct(
        private readonly PortfolioScopeInterface $portfolioScope,
        private readonly CustomerAttendantCollectionFactory $customerAttendantCollectionFactory,
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly CustomerOrderInsightService $orderInsightService,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInactiveCustomers(int $minDays, ?int $maxDays = null, int $limit = 500): array
    {
        $minDays = max(1, $minDays);
        $customerIds = $this->portfolioScope->getVisibleCustomerIds();
        if ($customerIds === []) {
            return [];
        }

        $orderStats = $this->orderInsightService->getOrderSummaryByCustomer($customerIds);
        $mappings = $this->loadCustomerMappings($customerIds);
        $customerNames = $this->loadCustomerNames($customerIds);
        $attendantNames = $this->loadAttendantNames();
        $now = new \DateTimeImmutable('today');

        $items = [];
        foreach ($customerIds as $customerId) {
            $stats = $orderStats[$customerId] ?? null;
            $lastOrderAt = $stats['last_order_at'] ?? null;
            $daysSince = $this->orderInsightService->getDaysSince($lastOrderAt);

            if ($daysSince === null) {
                $daysSince = 9999;
            }

            if ($daysSince < $minDays) {
                continue;
            }

            if ($maxDays !== null && $daysSince > $maxDays) {
                continue;
            }

            $mapping = $mappings[$customerId] ?? [];
            $attendantId = (int) ($mapping['attendant_id'] ?? 0);

            $items[] = [
                'entity_id' => $customerId,
                'customer_display' => $customerNames[$customerId] ?? '',
                'last_order_at' => $lastOrderAt,
                'days_inactive' => $daysSince === 9999 ? null : $daysSince,
                'days_inactive_label' => $daysSince === 9999
                    ? (string) __('Nunca comprou')
                    : (string) $daysSince,
                'avg_order_value' => $stats['avg_total'] ?? 0.0,
                'attendant_id' => $attendantId,
                'attendant_name' => $attendantNames[$attendantId] ?? '',
                'commercial_status' => (string) ($mapping['commercial_status'] ?? ''),
            ];
        }

        usort($items, static fn (array $a, array $b): int => ($b['days_inactive'] ?? 9999) <=> ($a['days_inactive'] ?? 9999));

        return array_slice($items, 0, $limit);
    }

    /**
     * Contagem real de clientes inativos no escopo visível, sem o teto de exibição
     * (o KPI do relatório não pode saturar em 500 quando a carteira tem milhares).
     */
    public function countInactiveCustomers(int $minDays, ?int $maxDays = null): int
    {
        return count($this->getInactiveCustomers($minDays, $maxDays, 100000));
    }

    public function markInProgress(int $customerId): bool
    {
        if (!$this->portfolioScope->canAccessCustomer($customerId)) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer_attendant');

        $updated = $connection->update(
            $table,
            ['commercial_status' => self::STATUS_IN_PROGRESS],
            ['customer_id = ?' => $customerId]
        );

        return $updated > 0;
    }

    /**
     * @param int[] $customerIds
     * @return array<int, array<string, mixed>>
     */
    private function loadCustomerMappings(array $customerIds): array
    {
        $collection = $this->customerAttendantCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', ['in' => $customerIds]);

        $result = [];
        foreach ($collection as $item) {
            $result[(int) $item->getData('customer_id')] = $item->getData();
        }

        return $result;
    }

    /**
     * @param int[] $customerIds
     * @return array<int, string>
     */
    private function loadCustomerNames(array $customerIds): array
    {
        $collection = $this->customerCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => $customerIds]);
        $collection->addAttributeToSelect(['firstname', 'lastname', 'b2b_razao_social']);

        $result = [];
        foreach ($collection as $customer) {
            $id = (int) $customer->getId();
            $name = trim($customer->getFirstname() . ' ' . $customer->getLastname());
            if ($customer->getData('b2b_razao_social')) {
                $name = (string) $customer->getData('b2b_razao_social');
            }
            $result[$id] = $name;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function loadAttendantNames(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('grupoawamotos_b2b_attendants');
        $rows = $connection->fetchPairs(
            $connection->select()->from($table, ['attendant_id', 'name'])
        );

        $result = [];
        foreach ($rows as $id => $name) {
            $result[(int) $id] = (string) $name;
        }

        return $result;
    }
}
