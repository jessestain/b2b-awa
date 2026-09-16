<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\StockSyncInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use GrupoAwamotos\ERPIntegration\Model\Validator\StockValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use GrupoAwamotos\ERPIntegration\Model\Alert\EmailSender;
use Psr\Log\LoggerInterface;

class StockSync implements StockSyncInterface
{
    private const CACHE_PREFIX = 'erp_stock_';
    private const CACHE_NEGATIVE_PREFIX = 'erp_stock_neg_';
    private const BATCH_SIZE = 1000;
    private const ANOMALY_SAMPLE_LIMIT = 15;
    private const ANOMALY_WARNING_COOLDOWN_SECONDS = 21600;

    private ConnectionInterface $connection;
    private EmailSender $emailSender;
    private Helper $helper;
    private ProductRepositoryInterface $productRepository;
    private StockRegistryInterface $stockRegistry;
    private SyncLogResource $syncLogResource;
    private StockValidator $stockValidator;
    private CacheInterface $cache;
    private LoggerInterface $logger;
    private ResourceConnection $resourceConnection;
    private ?array $existingSkuCache = null;
    private ?array $skuByInternalCodeCache = null;
    private ?int $erpInternalCodeAttributeId = null;
    private bool $erpInternalCodeAttributeIdLoaded = false;
    /** @var array<int, array{sku: string, change_percent: float|int|null, previous_qty: float|int|null, new_qty: float|int|null}> */
    private array $anomalySamples = [];
    /** @var array<int, array{erp_identifier: string, target_sku: string}> */
    private array $internalCodeResolutionSamples = [];
    private int $resolvedByInternalCodeCount = 0;

    public function __construct(
        ConnectionInterface $connection,
        Helper $helper,
        ProductRepositoryInterface $productRepository,
        StockRegistryInterface $stockRegistry,
        SyncLogResource $syncLogResource,
        StockValidator $stockValidator,
        CacheInterface $cache,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection,
        EmailSender $emailSender
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->productRepository = $productRepository;
        $this->stockRegistry = $stockRegistry;
        $this->syncLogResource = $syncLogResource;
        $this->stockValidator = $stockValidator;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
        $this->emailSender = $emailSender;
    }

    /**
     * Generate cache keys for stock data
     */
    private function generateCacheKeys(string $sku, array $filiais): array
    {
        $suffix = hash('xxh128', $sku . '_' . implode(',', $filiais));
        return [
            'positive' => self::CACHE_PREFIX . $suffix,
            'negative' => self::CACHE_NEGATIVE_PREFIX . $suffix,
        ];
    }

    /**
     * Try to get stock from cache
     *
     * @return array|null|false Returns cached data, null if negative cache hit, false if no cache
     */
    private function getFromCache(string $cacheKey, string $negativeCacheKey, string $sku): array|null|false
    {
        if ($this->cache->load($negativeCacheKey) !== false) {
            $this->logger->debug('[ERP] Stock negative cache hit for SKU: ' . $sku);
            return null;
        }

        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                $this->logger->debug('[ERP] Stock cache hit for SKU: ' . $sku);
                return $decoded;
            }
            $this->cache->remove($cacheKey);
        }

        return false;
    }

    /**
     * Save stock result to cache
     */
    private function saveToCache(string $cacheKey, string $negativeCacheKey, ?array $result, string $sku): void
    {
        if ($result) {
            $result['cached_at'] = date('Y-m-d H:i:s');
            $result['cache_ttl'] = $this->helper->getStockCacheTtl();
            $tags = $this->buildCacheTags($result);
            $this->cache->save(json_encode($result), $cacheKey, $tags, $this->helper->getStockCacheTtl());
            $this->logger->debug('[ERP] Stock cached for SKU: ' . $sku);
        } else {
            $this->cache->save('not_found', $negativeCacheKey, ['erp_stock', 'erp_stock_negative'], $this->helper->getNegativeCacheTtl());
            $this->logger->debug('[ERP] Stock negative cache set for SKU: ' . $sku);
        }
    }

    /**
     * Build cache tags from result
     */
    private function buildCacheTags(?array $result): array
    {
        $tags = ['erp_stock'];
        if (isset($result['branches']) && is_array($result['branches'])) {
            foreach (array_keys($result['branches']) as $filialId) {
                $tags[] = 'erp_stock_filial_' . $filialId;
            }
        }
        return $tags;
    }

    /**
     * Build SQL bindings for filial filters.
     *
     * @return array{params: array<string, int|string>, list: string}
     */
    private function buildFilialBindings(array $filiais, array $params = []): array
    {
        $placeholders = [];
        foreach ($filiais as $i => $filial) {
            $key = ':filial' . $i;
            $placeholders[] = $key;
            $params[$key] = (int) $filial;
        }

        return [
            'params' => $params,
            'list' => implode(',', $placeholders),
        ];
    }

    /**
     * Build SQL bindings for SKU filters.
     *
     * @return array{params: array<string, int|string>, list: string}
     */
    private function buildSkuBindings(array $skus, array $params = [], string $prefix = 'sku'): array
    {
        $placeholders = [];
        foreach (array_values($skus) as $i => $sku) {
            $key = ':' . $prefix . $i;
            $placeholders[] = $key;
            $params[$key] = trim($sku);
        }

        return [
            'params' => $params,
            'list' => implode(',', $placeholders),
        ];
    }

    private function isMultiBranch(array $filiais): bool
    {
        return $this->helper->isMultiBranchEnabled() && count($filiais) > 1;
    }

    /**
     * @param array<int, string> $skus
     * @return array<int, string>
     */
    private function normalizeSkuList(array $skus): array
    {
        $normalized = [];
        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }
            $normalized[$sku] = $sku;
        }

        return array_values($normalized);
    }

    public function getStockBySku(string $sku): ?array
    {
        $filiais = $this->helper->getStockFiliais();
        $cacheKeys = $this->generateCacheKeys($sku, $filiais);

        $cachedResult = $this->getFromCache($cacheKeys['positive'], $cacheKeys['negative'], $sku);
        if ($cachedResult !== false) {
            return $cachedResult;
        }

        try {
            $result = $this->isMultiBranch($filiais)
                ? $this->getMultiBranchStock($sku, $filiais)
                : $this->getSingleBranchStock($sku, (int) $filiais[0]);

            $this->saveToCache($cacheKeys['positive'], $cacheKeys['negative'], $result, $sku);
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Stock query error for SKU ' . $sku . ': ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Load stock for multiple SKUs while reusing the same cache and ERP query.
     *
     * @param array<int, string> $skus
     * @return array<string, array<string, mixed>>
     */
    public function getStocksBySkus(array $skus): array
    {
        $normalizedSkus = $this->normalizeSkuList($skus);
        if (empty($normalizedSkus)) {
            return [];
        }

        $filiais = $this->helper->getStockFiliais();
        $results = [];
        $missingSkus = [];

        foreach ($normalizedSkus as $sku) {
            $cacheKeys = $this->generateCacheKeys($sku, $filiais);
            $cachedResult = $this->getFromCache($cacheKeys['positive'], $cacheKeys['negative'], $sku);

            if ($cachedResult === false) {
                $missingSkus[] = $sku;
                continue;
            }

            if ($cachedResult !== null) {
                $results[$sku] = $cachedResult;
            }
        }

        if (empty($missingSkus)) {
            return $results;
        }

        try {
            $fetchedResults = $this->isMultiBranch($filiais)
                ? $this->getMultiBranchStockBatch($missingSkus, $filiais)
                : $this->getSingleBranchStockBatch($missingSkus, (int) $filiais[0]);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Batch stock query error: ' . $e->getMessage(), [
                'sku_count' => count($missingSkus),
            ]);
            return $results;
        }

        foreach ($missingSkus as $sku) {
            $cacheKeys = $this->generateCacheKeys($sku, $filiais);
            $result = $fetchedResults[$sku] ?? null;
            $this->saveToCache($cacheKeys['positive'], $cacheKeys['negative'], $result, $sku);

            if ($result !== null) {
                $results[$sku] = $result;
            }
        }

        return $results;
    }

    /**
     * Get stock from a single branch
     */
    private function getSingleBranchStock(string $sku, int $filial): ?array
    {
        $sql = "SELECT TOP 1 QTDE, VLRMEDIA, DATA
                FROM MT_ESTOQUEMEDIA
                WHERE MATERIAL = :sku AND FILIAL = :filial
                ORDER BY DATA DESC, CODIGO DESC";

        $row = $this->connection->fetchOne($sql, [
            ':sku' => $sku,
            ':filial' => $filial,
        ]);

        if ($row) {
            return [
                'qty' => (float) $row['QTDE'],
                'cost' => (float) $row['VLRMEDIA'],
                'date' => $row['DATA'],
                'filial' => $filial,
                'branches' => [$filial => (float) $row['QTDE']],
            ];
        }

        return null;
    }

    /**
     * Load latest stock for multiple SKUs from a single branch in one ERP query.
     *
     * @param array<int, string> $skus
     * @return array<string, array<string, mixed>>
     */
    private function getSingleBranchStockBatch(array $skus, int $filial): array
    {
        $bindings = $this->buildSkuBindings($skus, [
            ':filial' => $filial,
            ':filial2' => $filial,
        ]);
        $params = $bindings['params'];
        $skuList = $bindings['list'];

        $rows = $this->connection->query(
            "SELECT m.MATERIAL, m.QTDE, m.VLRMEDIA, m.DATA
             FROM MT_ESTOQUEMEDIA m
             INNER JOIN (
                 SELECT MATERIAL, MAX(CODIGO) AS MaxCodigo
                 FROM MT_ESTOQUEMEDIA
                 WHERE FILIAL = :filial AND MATERIAL IN ({$skuList})
                 GROUP BY MATERIAL
             ) latest ON m.MATERIAL = latest.MATERIAL AND m.CODIGO = latest.MaxCodigo
             WHERE m.FILIAL = :filial2",
            $params
        ) ?? [];

        $results = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['MATERIAL'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $results[$sku] = [
                'qty' => (float) $row['QTDE'],
                'cost' => (float) $row['VLRMEDIA'],
                'date' => $row['DATA'],
                'filial' => $filial,
                'branches' => [$filial => (float) $row['QTDE']],
            ];
        }

        return $results;
    }

    /**
     * Get aggregated stock from multiple branches
     */
    private function getMultiBranchStock(string $sku, array $filiais): ?array
    {
        $bindings = $this->buildFilialBindings($filiais, [':sku' => $sku, ':sku2' => $sku]);
        $params = $bindings['params'];
        $filialList = $bindings['list'];

        // Query to get latest stock for each branch
        $sql = "SELECT m.FILIAL, m.QTDE, m.VLRMEDIA, m.DATA
                FROM MT_ESTOQUEMEDIA m
                INNER JOIN (
                    SELECT FILIAL, MAX(CODIGO) AS MaxCodigo
                    FROM MT_ESTOQUEMEDIA
                    WHERE MATERIAL = :sku AND FILIAL IN ({$filialList})
                    GROUP BY FILIAL
                ) latest ON m.FILIAL = latest.FILIAL AND m.CODIGO = latest.MaxCodigo
                WHERE m.MATERIAL = :sku2";

        $rows = $this->connection->query($sql, $params);

        if (empty($rows)) {
            return null;
        }

        $branches = [];
        $quantities = [];
        $totalCost = 0;
        $costCount = 0;
        $latestDate = null;

        foreach ($rows as $row) {
            $filial = (int) $row['FILIAL'];
            $qty = (float) $row['QTDE'];
            $branches[$filial] = $qty;
            $quantities[] = $qty;

            if ((float) $row['VLRMEDIA'] > 0) {
                $totalCost += (float) $row['VLRMEDIA'];
                $costCount++;
            }

            if ($latestDate === null || $row['DATA'] > $latestDate) {
                $latestDate = $row['DATA'];
            }
        }

        // Aggregate quantity based on mode
        $aggregatedQty = $this->aggregateQuantities($quantities);

        return [
            'qty' => $aggregatedQty,
            'cost' => $costCount > 0 ? $totalCost / $costCount : 0,
            'date' => $latestDate,
            'filial' => 'multi',
            'branches' => $branches,
            'aggregation_mode' => $this->helper->getStockAggregationMode(),
        ];
    }

    /**
     * Load aggregated stock for multiple SKUs from multiple branches in one ERP query.
     *
     * @param array<int, string> $skus
     * @param array<int, int|string> $filiais
     * @return array<string, array<string, mixed>>
     */
    private function getMultiBranchStockBatch(array $skus, array $filiais): array
    {
        $bindings = $this->buildFilialBindings($filiais);
        $bindings = $this->buildSkuBindings($skus, $bindings['params'], 'sku');
        $params = $bindings['params'];
        $filialList = $this->buildFilialBindings($filiais)['list'];
        $skuList = $bindings['list'];

        $rows = $this->connection->query(
            "SELECT m.MATERIAL, m.FILIAL, m.QTDE, m.VLRMEDIA, m.DATA
             FROM MT_ESTOQUEMEDIA m
             INNER JOIN (
                 SELECT MATERIAL, FILIAL, MAX(CODIGO) AS MaxCodigo
                 FROM MT_ESTOQUEMEDIA
                 WHERE FILIAL IN ({$filialList}) AND MATERIAL IN ({$skuList})
                 GROUP BY MATERIAL, FILIAL
             ) latest ON m.MATERIAL = latest.MATERIAL
                     AND m.FILIAL = latest.FILIAL
                     AND m.CODIGO = latest.MaxCodigo",
            $params
        ) ?? [];

        $groupedRows = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['MATERIAL'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $groupedRows[$sku][] = $row;
        }

        $results = [];
        foreach ($groupedRows as $sku => $skuRows) {
            $branches = [];
            $quantities = [];
            $totalCost = 0.0;
            $costCount = 0;
            $latestDate = null;

            foreach ($skuRows as $row) {
                $filial = (int) $row['FILIAL'];
                $qty = (float) $row['QTDE'];
                $branches[$filial] = $qty;
                $quantities[] = $qty;

                if ((float) $row['VLRMEDIA'] > 0) {
                    $totalCost += (float) $row['VLRMEDIA'];
                    $costCount++;
                }

                if ($latestDate === null || $row['DATA'] > $latestDate) {
                    $latestDate = $row['DATA'];
                }
            }

            $results[$sku] = [
                'qty' => $this->aggregateQuantities($quantities),
                'cost' => $costCount > 0 ? $totalCost / $costCount : 0,
                'date' => $latestDate,
                'filial' => 'multi',
                'branches' => $branches,
                'aggregation_mode' => $this->helper->getStockAggregationMode(),
            ];
        }

        return $results;
    }

    /**
     * Aggregate quantities based on configured mode
     */
    private function aggregateQuantities(array $quantities): float
    {
        if (empty($quantities)) {
            return 0;
        }

        $mode = $this->helper->getStockAggregationMode();

        switch ($mode) {
            case 'sum':
                return array_sum($quantities);
            case 'min':
                return min($quantities);
            case 'max':
                return max($quantities);
            case 'avg':
                return array_sum($quantities) / count($quantities);
            default:
                return array_sum($quantities);
        }
    }

    public function invalidateCache(string $sku): void
    {
        $filiais = $this->helper->getStockFiliais();
        $cacheKey = self::CACHE_PREFIX . hash('xxh128', $sku . '_' . implode(',', $filiais));
        $negativeCacheKey = self::CACHE_NEGATIVE_PREFIX . hash('xxh128', $sku . '_' . implode(',', $filiais));

        // Remove both positive and negative cache
        $this->cache->remove($cacheKey);
        $this->cache->remove($negativeCacheKey);

        // Also invalidate single-branch cache key for backwards compatibility
        if (count($filiais) === 1) {
            $singleKey = self::CACHE_PREFIX . hash('xxh128', $sku . '_' . $filiais[0]);
            $singleNegativeKey = self::CACHE_NEGATIVE_PREFIX . hash('xxh128', $sku . '_' . $filiais[0]);
            $this->cache->remove($singleKey);
            $this->cache->remove($singleNegativeKey);
        }

        // Intentionally no per-SKU log here to avoid debug log flooding during bulk sync.
    }

    public function invalidateAllCache(): void
    {
        $this->cache->clean(['erp_stock']);
        $this->logger->info('[ERP] All stock cache invalidated');
    }

    /**
     * Invalidate cache by branch/filial
     */
    public function invalidateCacheByFilial(int $filialId): void
    {
        $this->cache->clean(['erp_stock_filial_' . $filialId]);
        $this->logger->info('[ERP] Stock cache invalidated for filial: ' . $filialId);
    }

    /**
     * Invalidate negative cache only (when new SKUs are added to ERP)
     */
    public function invalidateNegativeCache(): void
    {
        $this->cache->clean(['erp_stock_negative']);
        $this->logger->info('[ERP] Stock negative cache invalidated');
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        // Note: This is a simplified implementation
        // Full implementation would require counting cache entries
        return [
            'ttl' => $this->helper->getStockCacheTtl(),
            'negative_ttl' => $this->helper->getNegativeCacheTtl(),
            'tags' => ['erp_stock', 'erp_stock_negative', 'erp_stock_filial_*'],
        ];
    }

    public function syncAll(): array
    {
        $startTime = microtime(true);
        $result = $this->initializeSyncResult();
        $this->existingSkuCache = null; // Reset cache for fresh data
        $this->skuByInternalCodeCache = null;
        $this->anomalySamples = [];
        $this->internalCodeResolutionSamples = [];
        $this->resolvedByInternalCodeCount = 0;

        try {
            $filiais = $this->helper->getStockFiliais();
            $result = $this->runBranchSync($filiais, $result);
            $result = $this->finalizeSyncResult($result, $startTime);
            $this->logAnomalySummary($result);
            $this->logInternalCodeResolutionSummary();
            $this->logSyncSummary($filiais, $result);

            $this->logger->info('[ERP] Stock sync completed', $result);
        } catch (\Exception $e) {
            $result = $this->finalizeSyncResult($result, $startTime);
            $this->logAnomalySummary($result);
            $this->logInternalCodeResolutionSummary();
            $this->logger->error('[ERP] Stock sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'partial_result' => $result,
            ]);

            $this->syncLogResource->addLog('stock', 'import', 'error', $e->getMessage());
        }

        return $result;
    }

    /**
     * @return array{
     *     updated: int,
     *     skipped: int,
     *     errors: int,
     *     not_found: int,
     *     unchanged: int,
     *     validation_failed: int,
     *     anomalies_detected: int,
     *     total_erp_records: int,
     *     execution_time: float
     * }
     */
    private function initializeSyncResult(): array
    {
        return [
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'not_found' => 0,
            'unchanged' => 0,
            'validation_failed' => 0,
            'anomalies_detected' => 0,
            'total_erp_records' => 0,
            'execution_time' => 0.0,
        ];
    }

    private function finalizeSyncResult(array $result, float $startTime): array
    {
        $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);
        return $result;
    }

    private function runBranchSync(array $filiais, array $result): array
    {
        if ($this->isMultiBranch($filiais)) {
            return $this->syncMultiBranch($filiais, $result);
        }

        return $this->syncSingleBranch((int) $filiais[0], $result);
    }

    private function determineSyncStatus(array $result): string
    {
        if ($result['errors'] <= 0) {
            return 'success';
        }

        return $result['errors'] > ($result['total_erp_records'] * 0.1) ? 'error' : 'partial';
    }

    private function buildSyncSummaryMessage(array $filiais, array $result): string
    {
        $branchInfo = $this->isMultiBranch($filiais)
            ? 'Filiais: ' . implode(',', $filiais)
            : 'Filial: ' . (int) $filiais[0];

        return sprintf(
            '%s | Atualizados: %d, Não encontrados: %d, Sem alteração: %d, Ignorados: %d, Erros: %d | Tempo: %dms',
            $branchInfo,
            $result['updated'],
            $result['not_found'],
            $result['unchanged'],
            $result['skipped'],
            $result['errors'],
            $result['execution_time']
        );
    }

    private function logSyncSummary(array $filiais, array $result): void
    {
        $this->syncLogResource->addLog(
            'stock',
            'import',
            $this->determineSyncStatus($result),
            $this->buildSyncSummaryMessage($filiais, $result),
            null,
            null,
            $result['updated']
        );
    }

    /**
     * Build WHERE clause fragment to filter by active/sellable materials.
     * Applies the same business rules as ProductSync to avoid fetching stock
     * for materials that will never exist in Magento.
     */
    private function buildMaterialFilter(): string
    {
        $filter = " AND mat.CCKATIVO = 'S'";
        if ($this->helper->filterComercializa()) {
            $filter .= " AND mat.CKCOMERCIALIZA = 'S'";
        }
        return $filter;
    }

    /**
     * Sync stock from a single branch
     */
    private function syncSingleBranch(int $filial, array $result): array
    {
        $materialFilter = $this->buildMaterialFilter();

        $sql = "SELECT m.MATERIAL, m.QTDE, m.VLRMEDIA
                FROM MT_ESTOQUEMEDIA m
                INNER JOIN (
                    SELECT MATERIAL, MAX(CODIGO) AS MaxCodigo
                    FROM MT_ESTOQUEMEDIA
                    WHERE FILIAL = :filial
                    GROUP BY MATERIAL
                ) latest ON m.MATERIAL = latest.MATERIAL AND m.CODIGO = latest.MaxCodigo
                INNER JOIN MT_MATERIAL mat ON mat.CODIGO = m.MATERIAL
                WHERE m.FILIAL = :filial2" . $materialFilter;

        $rows = $this->connection->query($sql, [
            ':filial' => $filial,
            ':filial2' => $filial,
        ]);

        $result['total_erp_records'] = count($rows);

        $this->logger->info('[ERP] Starting stock sync', [
            'total_records' => $result['total_erp_records'],
            'filial' => $filial,
        ]);

        foreach ($rows as $row) {
            $syncResult = $this->syncSingleStock($row);
            $result = $this->updateResultCounter($result, $syncResult);
        }

        return $result;
    }

    /**
     * Sync aggregated stock from multiple branches
     */
    private function syncMultiBranch(array $filiais, array $result): array
    {
        $bindings = $this->buildFilialBindings($filiais);
        $params = $bindings['params'];
        $filialList = $bindings['list'];
        $materialFilter = $this->buildMaterialFilter();

        // Get aggregated stock per material across all branches (filtered by active materials)
        $sql = "SELECT m.MATERIAL,
                       SUM(m.QTDE) AS QTDE_TOTAL,
                       AVG(m.VLRMEDIA) AS VLRMEDIA,
                       MAX(m.DATA) AS DATA
                FROM MT_ESTOQUEMEDIA m
                INNER JOIN (
                    SELECT MATERIAL, FILIAL, MAX(CODIGO) AS MaxCodigo
                    FROM MT_ESTOQUEMEDIA
                    WHERE FILIAL IN ({$filialList})
                    GROUP BY MATERIAL, FILIAL
                ) latest ON m.MATERIAL = latest.MATERIAL
                        AND m.FILIAL = latest.FILIAL
                        AND m.CODIGO = latest.MaxCodigo
                INNER JOIN MT_MATERIAL mat ON mat.CODIGO = m.MATERIAL
                WHERE 1=1" . $materialFilter . "
                GROUP BY m.MATERIAL";

        $rows = $this->connection->query($sql, $params);

        $result['total_erp_records'] = count($rows);

        $this->logger->info('[ERP] Starting multi-branch stock sync', [
            'total_records' => $result['total_erp_records'],
            'filiais' => $filiais,
            'aggregation_mode' => $this->helper->getStockAggregationMode(),
        ]);

        foreach ($rows as $row) {
            // For multi-branch, we use QTDE_TOTAL which is already aggregated
            $row['QTDE'] = $row['QTDE_TOTAL'];
            $syncResult = $this->syncSingleStock($row);
            $result = $this->updateResultCounter($result, $syncResult);
        }

        return $result;
    }

    /**
     * Sync stock for a single product
     *
     * @return array ['status' => string, 'anomaly' => bool]
     */
    private function syncSingleStock(array $row): array
    {
        $result = ['status' => 'skipped', 'anomaly' => false];
        $erpIdentifier = trim($row['MATERIAL'] ?? '');

        if (empty($erpIdentifier)) {
            return $result;
        }

        try {
            $targetSku = $this->resolveMagentoSku($erpIdentifier);
            $validationResult = $this->validateStockData($row, $targetSku ?? $erpIdentifier);
            if ($validationResult === null) {
                $result['status'] = 'validation_failed';
                return $result;
            }

            if ($targetSku === null) {
                $result['status'] = 'not_found';
                return $result;
            }

            $resolutionType = $this->determineSkuResolutionType($erpIdentifier, $targetSku);
            $result['anomaly'] = $this->checkAndLogAnomaly(
                $validationResult,
                $erpIdentifier,
                $targetSku,
                $resolutionType
            );
            $result['status'] = $this->syncValidatedStock($targetSku, $validationResult);
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Stock sync error', ['sku' => $erpIdentifier, 'error' => $e->getMessage()]);
            $result['status'] = 'error';
            return $result;
        }
    }

    private function syncValidatedStock(string $sku, object $validationResult): string
    {
        return $this->updateStockIfChanged($sku, (float) $validationResult->getField('quantity', 0));
    }

    /**
     * Validate stock data and return result or null if invalid
     */
    private function validateStockData(array $row, string $sku): ?object
    {
        $validationResult = $this->stockValidator->validate($row, $sku);

        if (!$validationResult->isValid()) {
            $this->logger->warning('[ERP] Stock validation failed', [
                'sku' => $sku,
                'errors' => $validationResult->getErrors(),
            ]);
            return null;
        }

        return $validationResult;
    }

    /**
     * Check for anomalies and log if detected
     */
    private function checkAndLogAnomaly(
        object $validationResult,
        string $erpIdentifier,
        string $targetSku,
        string $resolutionType
    ): bool {
        if ($validationResult->getField('quantity_original') !== null || $resolutionType === 'base_sku') {
            return false;
        }

        $anomalyResult = $this->stockValidator->detectAnomaly(
            $targetSku,
            (float) $validationResult->getField('quantity', 0)
        );
        $validationResult->merge($anomalyResult);

        if (!$validationResult->getField('anomaly_detected', false)) {
            return false;
        }

        if (count($this->anomalySamples) < self::ANOMALY_SAMPLE_LIMIT) {
            $this->anomalySamples[] = [
                'requested_identifier' => $erpIdentifier,
                'target_sku' => $targetSku,
                'change_percent' => $validationResult->getField('anomaly_percent_change'),
                'previous_qty' => $validationResult->getField('previous_quantity'),
                'new_qty' => $validationResult->getField('quantity'),
                'resolution_type' => $resolutionType,
            ];
        }

        return true;
    }

    /**
     * Logs one consolidated anomaly warning per sync execution.
     */
    private function logAnomalySummary(array $result): void
    {
        $total = (int) ($result['anomalies_detected'] ?? 0);
        if ($total <= 0) {
            return;
        }

        $sampleCount = count($this->anomalySamples);
        $context = [
            'anomalies_detected' => $total,
            'sample_count' => $sampleCount,
            'sample_limit' => self::ANOMALY_SAMPLE_LIMIT,
            'samples' => $this->anomalySamples,
        ];

        if ($total > $sampleCount) {
            $context['suppressed_count'] = $total - $sampleCount;
        }

        if (!$this->shouldLogAnomalyWarning()) {
            return;
        }

        $this->logger->warning('[ERP] Stock anomalies detected during sync', $context);

        // Send admin notification
        $this->emailSender->sendStockAnomalyAlert($total, $this->anomalySamples);
    }

    private function logInternalCodeResolutionSummary(): void
    {
        if ($this->resolvedByInternalCodeCount <= 0) {
            return;
        }

        $context = [
            'resolved_count' => $this->resolvedByInternalCodeCount,
            'sample_count' => count($this->internalCodeResolutionSamples),
            'samples' => $this->internalCodeResolutionSamples,
        ];

        if ($this->resolvedByInternalCodeCount > count($this->internalCodeResolutionSamples)) {
            $context['suppressed_count'] = $this->resolvedByInternalCodeCount - count($this->internalCodeResolutionSamples);
        }

        $this->logger->info('[ERP] Stock sync resolved ERP internal codes to Magento SKUs', $context);
    }

    /**
     * Limit repetitive anomaly warnings across cron runs.
     */
    private function shouldLogAnomalyWarning(): bool
    {
        $basePath = defined('BP') ? BP : sys_get_temp_dir();
        $lockDir = rtrim($basePath, '/') . '/var/locks';

        if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            return true;
        }

        $file = $lockDir . '/erp_warn_stock_anomalies.lock';
        $handle = @fopen($file, 'c+');

        if ($handle === false) {
            return true;
        }

        @chmod($file, 0666);

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return true;
        }

        try {
            rewind($handle);
            $last = (int) trim((string) stream_get_contents($handle));
            $now = time();

            if ($last > 0 && ($now - $last) < self::ANOMALY_WARNING_COOLDOWN_SECONDS) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) $now);
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Check if product exists in catalog using pre-loaded SKU cache
     */
    private function productExists(string $sku): bool
    {
        if ($this->existingSkuCache === null) {
            $this->loadExistingSkus();
        }

        return isset($this->existingSkuCache[$sku]);
    }

    /**
     * Pre-load all existing SKUs in a single query for performance
     */
    private function loadExistingSkus(): void
    {
        $conn = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $skus = $conn->fetchCol("SELECT sku FROM {$productTable}");
        $this->existingSkuCache = array_flip($skus);
        $this->skuByInternalCodeCache = [];

        $attributeId = $this->getErpInternalCodeAttributeId();
        if ($attributeId !== null) {
            $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
            $rows = $conn->fetchAll(
                "SELECT cpe.sku, value_table.value AS erp_internal_code
                 FROM {$productTable} cpe
                 INNER JOIN {$varcharTable} value_table
                    ON value_table.entity_id = cpe.entity_id
                    AND value_table.attribute_id = :attribute_id
                    AND value_table.store_id = 0
                 WHERE value_table.value IS NOT NULL
                   AND TRIM(value_table.value) != ''",
                [':attribute_id' => $attributeId]
            );

            foreach ($rows as $row) {
                $sku = trim((string) ($row['sku'] ?? ''));
                $internalCode = trim((string) ($row['erp_internal_code'] ?? ''));

                if ($sku === '' || $internalCode === '' || isset($this->skuByInternalCodeCache[$internalCode])) {
                    continue;
                }

                $this->skuByInternalCodeCache[$internalCode] = $sku;
            }
        }

        $this->logger->info('[ERP] Pre-loaded SKU cache', [
            'total_skus' => count($this->existingSkuCache),
            'internal_code_mappings' => count($this->skuByInternalCodeCache),
        ]);
    }

    private function resolveMagentoSku(string $erpIdentifier): ?string
    {
        $erpIdentifier = trim($erpIdentifier);
        if ($erpIdentifier === '') {
            return null;
        }

        if ($this->productExists($erpIdentifier)) {
            return $erpIdentifier;
        }

        $baseSku = $this->getBaseSku($erpIdentifier);
        if ($baseSku !== $erpIdentifier && $this->productExists($baseSku)) {
            return $baseSku;
        }

        $resolvedSku = $this->getSkuByInternalCode($erpIdentifier);
        if ($resolvedSku !== null && $this->productExists($resolvedSku)) {
            $this->recordInternalCodeResolution($erpIdentifier, $resolvedSku);
            return $resolvedSku;
        }

        return null;
    }

    private function getSkuByInternalCode(string $internalCode): ?string
    {
        if ($this->skuByInternalCodeCache === null) {
            $this->loadExistingSkus();
        }

        return $this->skuByInternalCodeCache[$internalCode] ?? null;
    }

    private function recordInternalCodeResolution(string $erpIdentifier, string $targetSku): void
    {
        $this->resolvedByInternalCodeCount++;

        if (count($this->internalCodeResolutionSamples) >= self::ANOMALY_SAMPLE_LIMIT) {
            return;
        }

        $this->internalCodeResolutionSamples[] = [
            'erp_identifier' => $erpIdentifier,
            'target_sku' => $targetSku,
        ];
    }

    private function determineSkuResolutionType(string $erpIdentifier, string $targetSku): string
    {
        if ($erpIdentifier === $targetSku) {
            return 'direct';
        }

        if ($this->getBaseSku($erpIdentifier) === $targetSku) {
            return 'base_sku';
        }

        if ($this->getSkuByInternalCode($erpIdentifier) === $targetSku) {
            return 'internal_code';
        }

        return 'fallback';
    }

    private function getErpInternalCodeAttributeId(): ?int
    {
        if ($this->erpInternalCodeAttributeIdLoaded) {
            return $this->erpInternalCodeAttributeId;
        }

        $conn = $this->resourceConnection->getConnection();
        $eavAttributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $entityTypeTable = $this->resourceConnection->getTableName('eav_entity_type');

        $attributeId = $conn->fetchOne(
            "SELECT ea.attribute_id
             FROM {$eavAttributeTable} ea
             INNER JOIN {$entityTypeTable} eet ON eet.entity_type_id = ea.entity_type_id
             WHERE ea.attribute_code = :attribute_code
               AND eet.entity_type_code = :entity_type_code
             LIMIT 1",
            [
                ':attribute_code' => 'erp_internal_code',
                ':entity_type_code' => 'catalog_product',
            ]
        );

        $this->erpInternalCodeAttributeId = $attributeId !== false && $attributeId !== null
            ? (int) $attributeId
            : null;
        $this->erpInternalCodeAttributeIdLoaded = true;

        return $this->erpInternalCodeAttributeId;
    }

    /**
     * Update stock if quantity changed
     */
    private function updateStockIfChanged(string $sku, float $qty): string
    {
        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        $currentQty = (float) $stockItem->getQty();

        // Pula somente se qty igual E já configurado como infinite stock
        if (abs($currentQty - $qty) < 0.001
            && !$stockItem->getManageStock()
            && !$stockItem->getUseConfigManageStock()
            && (bool) $stockItem->getIsInStock()
        ) {
            return 'unchanged';
        }

        $stockItem->setQty($qty);
        // Estoque infinito: produto sempre disponível — controle real de estoque é do ERP
        $stockItem->setIsInStock(true);
        $stockItem->setUseConfigManageStock(false);
        $stockItem->setManageStock(false);
        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);
        $this->invalidateCache($sku);

        return 'updated';
    }

    /**
     * Update result counters based on sync result
     */
    private function updateResultCounter(array $result, array $syncResult): array
    {
        $statusCounterMap = [
            'updated' => 'updated',
            'unchanged' => 'unchanged',
            'not_found' => 'not_found',
            'skipped' => 'skipped',
            'validation_failed' => 'validation_failed',
            'error' => 'errors',
        ];

        $status = $syncResult['status'] ?? 'skipped';
        $counterKey = $statusCounterMap[$status] ?? 'skipped';
        $result[$counterKey]++;

        if ($syncResult['anomaly'] ?? false) {
            $result['anomalies_detected']++;
        }

        return $result;
    }

    public function syncBySku(string $sku): bool
    {
        try {
            $stockData = $this->getStockBySku($sku);

            if ($stockData === null) {
                $this->logger->info('[ERP] No stock data found in ERP for SKU: ' . $sku);
                return false;
            }

            $targetSku = $this->resolveMagentoSku($sku);
            if ($targetSku === null) {
                $this->logger->warning('[ERP] Product not found in Magento for stock sync: ' . $sku);
                return false;
            }

            try {
                $this->productRepository->get($targetSku);
            } catch (NoSuchEntityException $e) {
                $this->logger->warning('[ERP] Product not found in Magento for stock sync: ' . $targetSku);
                return false;
            }

            $qty = $stockData['qty'];
            if ($qty < 0) {
                $qty = 0;
            }

            $stockItem = $this->stockRegistry->getStockItemBySku($targetSku);
            $stockItem->setQty($qty);
            // Estoque infinito: produto sempre disponível — controle real de estoque é do ERP
            $stockItem->setIsInStock(true);
            $stockItem->setUseConfigManageStock(false);
            $stockItem->setManageStock(false);
            $this->stockRegistry->updateStockItemBySku($targetSku, $stockItem);

            $this->logger->info('[ERP] Stock synced for SKU', [
                'sku' => $targetSku,
                'requested_identifier' => $sku,
                'qty' => $qty,
                'branches' => $stockData['branches'] ?? [],
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Single stock sync error', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get stock breakdown by branch for a SKU
     */
    public function getStockBreakdownBySku(string $sku): array
    {
        $filiais = $this->helper->getStockFiliais();

        if (count($filiais) === 1) {
            $data = $this->getSingleBranchStock($sku, $filiais[0]);
            return $data ? $data['branches'] : [];
        }

        $data = $this->getMultiBranchStock($sku, $filiais);
        return $data ? $data['branches'] : [];
    }

    /**
     * Extract base SKU from variant SKU.
     *
     * Same logic as PriceSync to keep SKU matching consistent.
     */
    private function getBaseSku(string $sku): string
    {
        $sku = trim($sku);

        // Space-separated variant: "1119 RS" → "1119"
        if (str_contains($sku, ' ')) {
            return trim(explode(' ', $sku)[0]);
        }

        // Dot-separated variant: "0045.01" → "0045", "2213.00" → "2213"
        if (preg_match('/^(\d{3,})\.\d+$/', $sku, $m)) {
            return $m[1];
        }

        // Alpha suffix variant: "1125NAO" → "1125"
        if (preg_match('/^(\d{3,})[A-Z]{2,3}$/i', $sku, $m)) {
            return $m[1];
        }

        return $sku;
    }

    /**
     * Get available branches from ERP
     */
    public function getAvailableBranches(): array
    {
        try {
            $sql = "SELECT DISTINCT f.CODIGO, f.FANTASIA, f.CIDADE
                    FROM CD_FILIAL f
                    WHERE f.ATIVO = 'S'
                    ORDER BY f.CODIGO";

            return $this->connection->query($sql);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Get branches error: ' . $e->getMessage());
            return [];
        }
    }
}
