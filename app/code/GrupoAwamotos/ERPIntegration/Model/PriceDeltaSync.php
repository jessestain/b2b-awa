<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Incremental sync for customer price list assignments and ERP list prices.
 *
 * This job keeps B2B pricing "near real-time" by:
 * - reconciling FN_FORNECEDORES.FATORPRECO -> customer list state;
 * - updating cached list/SKU prices from MT_MATERIALLISTA in small batches;
 * - invalidating list context versions used by FPC variation.
 */
class PriceDeltaSync
{
    private const TABLE_CUSTOMER_PRICE_STATE = 'grupoawamotos_erp_customer_price_state';
    private const TABLE_PRICE_SYNC_STATE = 'grupoawamotos_erp_price_sync_state';
    private const TABLE_ENTITY_MAP = 'grupoawamotos_erp_entity_map';
    private const STATE_KEY_CUSTOMER_CURSOR = 'price_delta_customer_cursor';
    private const STATE_KEY_PRICE_CURSOR_PREFIX = 'price_delta_price_cursor_';
    private const DECIMAL_EPSILON = 0.01;

    private array $productAttributeIds = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Helper $helper,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerPriceProvider $customerPriceProvider,
        private readonly ProductAction $productAction,
        private readonly SyncLogResource $syncLogResource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function execute(): array
    {
        $result = [
            'customers_scanned' => 0,
            'customers_changed' => 0,
            'price_rows_scanned' => 0,
            'price_cache_changed' => 0,
            'catalog_updated' => 0,
            'catalog_missing' => 0,
            'lists_warmed' => 0,
            'warm_entries' => 0,
            'errors' => 0,
        ];

        if (!$this->helper->isPriceDeltaSyncEnabled()) {
            return $result;
        }

        $listsToWarm = [];

        try {
            $customerStats = $this->syncCustomerPriceListAssignments();
            $priceStats = $this->syncPriceRows();

            $listsToWarm = $customerStats['changed_lists'];
            $warmStats = $this->warmChangedLists($listsToWarm);

            $result['customers_scanned'] = $customerStats['scanned'];
            $result['customers_changed'] = $customerStats['changed'];
            $result['price_rows_scanned'] = $priceStats['rows_scanned'];
            $result['price_cache_changed'] = $priceStats['cache_changed'];
            $result['catalog_updated'] = $priceStats['catalog_updated'];
            $result['catalog_missing'] = $priceStats['catalog_missing'];
            $result['lists_warmed'] = $warmStats['lists_warmed'];
            $result['warm_entries'] = $warmStats['entries_warmed'];
        } catch (\Throwable $e) {
            $result['errors']++;
            $this->logger->error('[ERP PriceDeltaSync] Fatal error: ' . $e->getMessage(), ['exception' => $e]);
        }

        $status = $result['errors'] > 0 ? 'partial' : 'success';
        $this->syncLogResource->addLog(
            'price_delta',
            'sync',
            $status,
            sprintf(
                'Customers scanned: %d, changed: %d, price rows: %d, cache changed: %d, catalog updated: %d, missing: %d, lists warmed: %d',
                $result['customers_scanned'],
                $result['customers_changed'],
                $result['price_rows_scanned'],
                $result['price_cache_changed'],
                $result['catalog_updated'],
                $result['catalog_missing'],
                $result['lists_warmed']
            ),
            null,
            null,
            $result['price_rows_scanned']
        );

        return $result;
    }

    /**
     * @return array{cursors:array<string,string>,customer_price_states:int,tracked_lists:int}
     */
    public function getStateSnapshot(): array
    {
        $connection = $this->getDbConnection();
        $rows = $connection->fetchAll(
            sprintf(
                'SELECT sync_key, sync_value FROM %s ORDER BY sync_key',
                self::TABLE_PRICE_SYNC_STATE
            )
        );

        $cursors = [];
        foreach ($rows as $row) {
            $key = (string) ($row['sync_key'] ?? '');
            if ($key !== '') {
                $cursors[$key] = (string) ($row['sync_value'] ?? '');
            }
        }

        return [
            'cursors' => $cursors,
            'customer_price_states' => (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s', self::TABLE_CUSTOMER_PRICE_STATE)
            ),
            'tracked_lists' => count($this->getTrackedPriceLists()),
        ];
    }

    /**
     * @return array<int, array{sku:string,base_sku:string,price_list_code:int,price:float}>
     */
    public function getMissingCatalogSkus(int $limit = 100, ?int $priceList = null): array
    {
        $limit = max(1, min($limit, 1000));
        $listCode = $priceList ?? $this->helper->getDefaultPriceList();
        $filial = $this->helper->getStockFilial();
        $cursor = '';
        $missing = [];
        $batchSize = max(100, min($limit * 2, 500));

        while (count($missing) < $limit) {
            $rows = $this->connection->query(
                "SELECT MATERIAL, VLRVDSUG
                 FROM MT_MATERIALLISTA
                 WHERE FATORPRECO = ?
                   AND FILIAL = ?
                   AND VLRVDSUG > 0
                   AND MATERIAL > ?
                 ORDER BY MATERIAL
                 OFFSET 0 ROWS FETCH NEXT {$batchSize} ROWS ONLY",
                [$listCode, $filial, $cursor]
            );

            if (empty($rows)) {
                break;
            }

            $lastRow = end($rows);
            $cursor = trim((string) ($lastRow['MATERIAL'] ?? ''));

            $lookupSkus = [];
            foreach ($rows as $row) {
                $sku = trim((string) ($row['MATERIAL'] ?? ''));
                if ($sku === '') {
                    continue;
                }

                $lookupSkus[$sku] = $sku;
                $baseSku = $this->getBaseSku($sku);
                $lookupSkus[$baseSku] = $baseSku;
            }

            $catalogRows = $this->getCatalogProductsBySkus(array_values($lookupSkus));
            $catalogSkuSet = [];
            foreach ($catalogRows as $catalogRow) {
                $catalogSkuSet[(string) $catalogRow['sku']] = true;
            }

            foreach ($rows as $row) {
                if (count($missing) >= $limit) {
                    break 2;
                }

                $sku = trim((string) ($row['MATERIAL'] ?? ''));
                if ($sku === '') {
                    continue;
                }

                $baseSku = $this->getBaseSku($sku);
                if (isset($catalogSkuSet[$sku]) || isset($catalogSkuSet[$baseSku])) {
                    continue;
                }

                $missing[] = [
                    'sku' => $sku,
                    'base_sku' => $baseSku !== $sku ? $baseSku : '',
                    'price_list_code' => $listCode,
                    'price' => (float) ($row['VLRVDSUG'] ?? 0),
                ];
            }
        }

        return $missing;
    }

    /**
     * @return array{scanned:int,changed:int,changed_lists:array<int,int>}
     */
    private function syncCustomerPriceListAssignments(): array
    {
        $stats = ['scanned' => 0, 'changed' => 0, 'changed_lists' => []];
        $batchSize = $this->helper->getCustomerPriceDeltaBatchSize();
        $defaultList = $this->helper->getDefaultPriceList();
        $lastCustomerCode = (int) ($this->getStateValue(self::STATE_KEY_CUSTOMER_CURSOR) ?? 0);

        $rows = $this->connection->query(
            "SELECT CODIGO, FATORPRECO
             FROM FN_FORNECEDORES
             WHERE CKCLIENTE = 'S'
               AND ATCLIENTE = 'S'
               AND CODIGO > ?
             ORDER BY CODIGO
             OFFSET 0 ROWS FETCH NEXT {$batchSize} ROWS ONLY",
            [$lastCustomerCode]
        );

        if (empty($rows)) {
            if ($lastCustomerCode > 0) {
                $this->setStateValue(self::STATE_KEY_CUSTOMER_CURSOR, '0');
            }
            return $stats;
        }

        $erpCodes = array_values(array_unique(array_map(
            static fn(array $row): string => (string) ((int) ($row['CODIGO'] ?? 0)),
            $rows
        )));
        $erpCodes = array_values(array_filter($erpCodes, static fn(string $code): bool => $code !== '0'));

        $customerMap = $this->getMagentoCustomerMapByErpCodes($erpCodes);
        $existingStates = $this->getCustomerPriceStateByErpCodes($erpCodes);

        foreach ($rows as $row) {
            $erpCode = (int) ($row['CODIGO'] ?? 0);
            if ($erpCode <= 0) {
                continue;
            }

            $stats['scanned']++;
            $lastCustomerCode = $erpCode;
            $erpCodeKey = (string) $erpCode;

            $customerId = (int) ($customerMap[$erpCodeKey] ?? 0);
            if ($customerId <= 0) {
                continue;
            }

            $nextList = (int) ($row['FATORPRECO'] ?? 0);
            if ($nextList <= 0) {
                $nextList = $defaultList;
            }

            $state = $existingStates[$erpCodeKey] ?? null;
            if ($state === null) {
                // First time this customer is tracked here: a Redis entry may already
                // exist from a lazy storefront lookup (CustomerPriceProvider) and could
                // be stale relative to this list. Invalidate defensively so the next
                // request resolves the price list live instead of trusting a possibly
                // outdated cached value.
                $this->upsertCustomerPriceState($customerId, $erpCodeKey, $nextList);
                $this->customerPriceProvider->invalidateCustomerPriceList($erpCode, null, $nextList);
                $stats['changed_lists'][$nextList] = $nextList;
                continue;
            }

            $prevList = (int) ($state['price_list_code'] ?? 0);
            $prevCustomerId = (int) ($state['customer_id'] ?? 0);
            if ($prevList === $nextList && $prevCustomerId === $customerId) {
                continue;
            }

            $this->upsertCustomerPriceState($customerId, $erpCodeKey, $nextList);
            $this->customerPriceProvider->invalidateCustomerPriceList($erpCode, $prevList, $nextList);

            $stats['changed']++;
            $stats['changed_lists'][$prevList] = $prevList;
            $stats['changed_lists'][$nextList] = $nextList;
        }

        $this->setStateValue(self::STATE_KEY_CUSTOMER_CURSOR, (string) $lastCustomerCode);

        unset($stats['changed_lists'][0]);
        return $stats;
    }

    /**
     * @return array{rows_scanned:int,cache_changed:int,catalog_updated:int,catalog_missing:int}
     */
    private function syncPriceRows(): array
    {
        $stats = [
            'rows_scanned' => 0,
            'cache_changed' => 0,
            'catalog_updated' => 0,
            'catalog_missing' => 0,
        ];

        $batchSize = $this->helper->getPriceDeltaBatchSize();
        $defaultList = $this->helper->getDefaultPriceList();
        $filial = $this->helper->getStockFilial();
        $trackedLists = $this->getTrackedPriceLists();

        foreach ($trackedLists as $listCode) {
            $cursorKey = self::STATE_KEY_PRICE_CURSOR_PREFIX . $listCode;
            $lastSku = (string) ($this->getStateValue($cursorKey) ?? '');

            $rows = $this->connection->query(
                "SELECT MATERIAL, VLRVDSUG, VLRCUSTO, VLRVDMAX
                 FROM MT_MATERIALLISTA
                 WHERE FATORPRECO = ?
                   AND FILIAL = ?
                   AND VLRVDSUG > 0
                   AND MATERIAL > ?
                 ORDER BY MATERIAL
                 OFFSET 0 ROWS FETCH NEXT {$batchSize} ROWS ONLY",
                [$listCode, $filial, $lastSku]
            );

            if (empty($rows)) {
                if ($lastSku !== '') {
                    $this->setStateValue($cursorKey, '');
                }
                continue;
            }

            $stats['rows_scanned'] += count($rows);
            $lastRow = end($rows);
            $this->setStateValue($cursorKey, trim((string) ($lastRow['MATERIAL'] ?? '')));
            $listChanged = false;

            if ($listCode === $defaultList) {
                $catalog = $this->syncDefaultListRowsToCatalog($rows);
                $stats['catalog_updated'] += $catalog['updated'];
                $stats['catalog_missing'] += $catalog['missing'];
            }

            foreach ($rows as $row) {
                $sku = trim((string) ($row['MATERIAL'] ?? ''));
                if ($sku === '') {
                    continue;
                }

                $price = (float) ($row['VLRVDSUG'] ?? 0);
                $changed = $this->customerPriceProvider->replaceCachedPrice($listCode, $sku, $price);
                if ($changed) {
                    $stats['cache_changed']++;
                    $listChanged = true;
                }
            }

            if ($listChanged) {
                $this->customerPriceProvider->bumpPriceListContextVersion($listCode);
            }
        }

        return $stats;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{updated:int,missing:int}
     */
    private function syncDefaultListRowsToCatalog(array $rows): array
    {
        $result = ['updated' => 0, 'missing' => 0];
        $rowsBySku = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['MATERIAL'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $rowsBySku[$sku] = $row;

            $baseSku = $this->getBaseSku($sku);
            if ($baseSku !== $sku && !isset($rowsBySku[$baseSku])) {
                $rowsBySku[$baseSku] = $row;
            }
        }

        if (empty($rowsBySku)) {
            return $result;
        }

        $catalogRows = $this->getCatalogProductsBySkus(array_keys($rowsBySku));
        $catalogSkuSet = [];
        foreach ($catalogRows as $catalogRow) {
            $catalogSkuSet[(string) $catalogRow['sku']] = true;
        }

        foreach ($rows as $row) {
            $sku = trim((string) ($row['MATERIAL'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $baseSku = $this->getBaseSku($sku);
            if (!isset($catalogSkuSet[$sku]) && !isset($catalogSkuSet[$baseSku])) {
                $result['missing']++;
            }
        }

        foreach ($catalogRows as $catalogRow) {
            $sku = (string) $catalogRow['sku'];
            $erpRow = $rowsBySku[$sku] ?? null;
            if ($erpRow === null) {
                continue;
            }

            $newPrice = (float) ($erpRow['VLRVDSUG'] ?? 0);
            if ($newPrice <= 0) {
                continue;
            }

            $currentPrice = $this->toNullableFloat($catalogRow['price_value'] ?? null);
            $currentCost = $this->toNullableFloat($catalogRow['cost_value'] ?? null);
            $currentMsrp = $this->toNullableFloat($catalogRow['msrp_value'] ?? null);

            $attrData = [];
            if ($this->isDecimalDifferent($currentPrice, $newPrice)) {
                $attrData['price'] = $newPrice;
            }

            $newCost = (float) ($erpRow['VLRCUSTO'] ?? 0);
            if ($newCost > 0 && $this->isDecimalDifferent($currentCost, $newCost)) {
                $attrData['cost'] = $newCost;
            }

            $newMsrp = (float) ($erpRow['VLRVDMAX'] ?? 0);
            if ($newMsrp > $newPrice * 1.05 && $this->isDecimalDifferent($currentMsrp, $newMsrp)) {
                $attrData['msrp'] = $newMsrp;
            }

            if (empty($attrData)) {
                continue;
            }

            try {
                $this->productAction->updateAttributes(
                    [(int) $catalogRow['entity_id']],
                    $attrData,
                    0
                );
                $result['updated']++;
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    '[ERP PriceDeltaSync] Could not update catalog price for SKU %s: %s',
                    $sku,
                    $e->getMessage()
                ));
            }
        }

        return $result;
    }

    /**
     * @param string[] $skus
     * @return array<int, array<string, mixed>>
     */
    private function getCatalogProductsBySkus(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $connection = $this->getDbConnection();
        $ids = $this->getProductAttributeIds();
        if ($ids['price'] === null) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $params = $skus;

        $sql = "SELECT cpe.entity_id, cpe.sku,
                       price.value AS price_value,
                       cost.value AS cost_value,
                       msrp.value AS msrp_value
                FROM catalog_product_entity cpe
                LEFT JOIN catalog_product_entity_decimal price
                    ON price.entity_id = cpe.entity_id
                   AND price.attribute_id = {$ids['price']}
                   AND price.store_id = 0
                LEFT JOIN catalog_product_entity_decimal cost
                    ON cost.entity_id = cpe.entity_id
                   AND cost.attribute_id = " . ($ids['cost'] ?? 0) . "
                   AND cost.store_id = 0
                LEFT JOIN catalog_product_entity_decimal msrp
                    ON msrp.entity_id = cpe.entity_id
                   AND msrp.attribute_id = " . ($ids['msrp'] ?? 0) . "
                   AND msrp.store_id = 0
                WHERE cpe.sku IN ({$placeholders})";

        return $connection->fetchAll($sql, $params);
    }

    /**
     * @return array{price:?int,cost:?int,msrp:?int}
     */
    private function getProductAttributeIds(): array
    {
        if (!empty($this->productAttributeIds)) {
            return $this->productAttributeIds;
        }

        $connection = $this->getDbConnection();
        $rows = $connection->fetchAll(
            "SELECT ea.attribute_code, ea.attribute_id
             FROM eav_attribute ea
             INNER JOIN eav_entity_type eet ON eet.entity_type_id = ea.entity_type_id
             WHERE eet.entity_type_code = 'catalog_product'
               AND ea.attribute_code IN ('price', 'cost', 'msrp')"
        );

        $map = ['price' => null, 'cost' => null, 'msrp' => null];
        foreach ($rows as $row) {
            $code = (string) ($row['attribute_code'] ?? '');
            if (array_key_exists($code, $map)) {
                $map[$code] = (int) $row['attribute_id'];
            }
        }

        $this->productAttributeIds = $map;
        return $this->productAttributeIds;
    }

    /**
     * @return int[]
     */
    private function getTrackedPriceLists(): array
    {
        $connection = $this->getDbConnection();
        $defaultList = $this->helper->getDefaultPriceList();

        $listCodes = $connection->fetchCol(
            sprintf(
                'SELECT DISTINCT price_list_code FROM %s WHERE price_list_code > 0',
                self::TABLE_CUSTOMER_PRICE_STATE
            )
        );

        $listCodes = array_map('intval', $listCodes);
        $listCodes[] = $defaultList;
        $listCodes = array_values(array_unique(array_filter($listCodes, static fn(int $v): bool => $v > 0)));
        sort($listCodes);

        return $listCodes;
    }

    /**
     * @param array<int,int> $listCodes
     * @return array{lists_warmed:int,entries_warmed:int}
     */
    private function warmChangedLists(array $listCodes): array
    {
        $stats = ['lists_warmed' => 0, 'entries_warmed' => 0];
        if (empty($listCodes)) {
            return $stats;
        }

        foreach (array_values(array_unique($listCodes)) as $listCode) {
            if ($listCode <= 0) {
                continue;
            }

            $count = $this->customerPriceProvider->warmPriceList((int) $listCode);
            $stats['lists_warmed']++;
            $stats['entries_warmed'] += $count;
        }

        return $stats;
    }

    /**
     * @param string[] $erpCodes
     * @return array<string,int>
     */
    private function getMagentoCustomerMapByErpCodes(array $erpCodes): array
    {
        if (empty($erpCodes)) {
            return [];
        }

        $connection = $this->getDbConnection();
        $placeholders = implode(',', array_fill(0, count($erpCodes), '?'));
        $params = array_merge(['customer'], $erpCodes);

        $rows = $connection->fetchAll(
            sprintf(
                'SELECT erp_code, magento_entity_id
                 FROM %s
                 WHERE entity_type = ?
                   AND erp_code IN (%s)',
                self::TABLE_ENTITY_MAP,
                $placeholders
            ),
            $params
        );

        $map = [];
        foreach ($rows as $row) {
            $erpCode = (string) ($row['erp_code'] ?? '');
            $customerId = (int) ($row['magento_entity_id'] ?? 0);
            if ($erpCode !== '' && $customerId > 0) {
                $map[$erpCode] = $customerId;
            }
        }

        $attributeRows = $connection->fetchAll(
            "SELECT cev.value AS erp_code, ce.entity_id AS customer_id
             FROM customer_entity ce
             INNER JOIN customer_entity_varchar cev ON cev.entity_id = ce.entity_id
             INNER JOIN eav_attribute ea ON ea.attribute_id = cev.attribute_id
             INNER JOIN eav_entity_type eet ON eet.entity_type_id = ea.entity_type_id
             WHERE eet.entity_type_code = 'customer'
               AND ea.attribute_code = 'erp_code'
               AND cev.value IN ({$placeholders})",
            $erpCodes
        );

        foreach ($attributeRows as $row) {
            $erpCode = (string) ($row['erp_code'] ?? '');
            $customerId = (int) ($row['customer_id'] ?? 0);
            if ($erpCode !== '' && $customerId > 0) {
                $map[$erpCode] = $customerId;
            }
        }

        return $map;
    }

    /**
     * @param string[] $erpCodes
     * @return array<string,array{customer_id:int,price_list_code:int}>
     */
    private function getCustomerPriceStateByErpCodes(array $erpCodes): array
    {
        if (empty($erpCodes)) {
            return [];
        }

        $connection = $this->getDbConnection();
        $placeholders = implode(',', array_fill(0, count($erpCodes), '?'));

        $rows = $connection->fetchAll(
            sprintf(
                'SELECT erp_code, customer_id, price_list_code
                 FROM %s
                 WHERE erp_code IN (%s)',
                self::TABLE_CUSTOMER_PRICE_STATE,
                $placeholders
            ),
            $erpCodes
        );

        $result = [];
        foreach ($rows as $row) {
            $erpCode = (string) ($row['erp_code'] ?? '');
            if ($erpCode === '') {
                continue;
            }
            $result[$erpCode] = [
                'customer_id' => (int) ($row['customer_id'] ?? 0),
                'price_list_code' => (int) ($row['price_list_code'] ?? 0),
            ];
        }

        return $result;
    }

    private function upsertCustomerPriceState(int $customerId, string $erpCode, int $priceListCode): void
    {
        if ($customerId <= 0 || $erpCode === '' || $priceListCode <= 0) {
            return;
        }

        $connection = $this->getDbConnection();
        $now = gmdate('Y-m-d H:i:s');
        $connection->insertOnDuplicate(
            self::TABLE_CUSTOMER_PRICE_STATE,
            [
                'customer_id' => $customerId,
                'erp_code' => $erpCode,
                'price_list_code' => $priceListCode,
                'synced_at' => $now,
                'updated_at' => $now,
            ],
            ['customer_id', 'erp_code', 'price_list_code', 'synced_at', 'updated_at']
        );
    }

    private function getStateValue(string $syncKey): ?string
    {
        $connection = $this->getDbConnection();
        $select = $connection->select()
            ->from(self::TABLE_PRICE_SYNC_STATE, 'sync_value')
            ->where('sync_key = ?', $syncKey)
            ->limit(1);

        $value = $connection->fetchOne($select);
        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    private function setStateValue(string $syncKey, string $syncValue): void
    {
        $connection = $this->getDbConnection();
        $now = gmdate('Y-m-d H:i:s');
        $connection->insertOnDuplicate(
            self::TABLE_PRICE_SYNC_STATE,
            [
                'sync_key' => $syncKey,
                'sync_value' => $syncValue,
                'updated_at' => $now,
                'created_at' => $now,
            ],
            ['sync_value', 'updated_at']
        );
    }

    private function isDecimalDifferent(?float $current, ?float $next): bool
    {
        if ($current === null && $next === null) {
            return false;
        }
        if ($current === null || $next === null) {
            return true;
        }

        return abs($current - $next) >= self::DECIMAL_EPSILON;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function getBaseSku(string $sku): string
    {
        $sku = trim($sku);

        if (str_contains($sku, ' ')) {
            return trim(explode(' ', $sku)[0]);
        }

        if (preg_match('/^(\d{3,})\.\d+$/', $sku, $matches)) {
            return $matches[1];
        }

        if (str_contains($sku, '-')) {
            $parts = explode('-', $sku);
            if (count($parts) > 1 && preg_match('/^\d+$/', $parts[0])) {
                return $parts[0];
            }
        }

        return $sku;
    }

    private function getDbConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}
