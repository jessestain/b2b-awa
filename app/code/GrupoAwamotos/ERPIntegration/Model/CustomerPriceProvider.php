<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Customer Price Provider
 *
 * Fetches customer-specific prices from ERP based on their assigned price list (FATORPRECO).
 * Each customer in FN_FORNECEDORES has a FATORPRECO that maps to a price list in VE_FATORPRECO.
 * Prices come from MT_MATERIALLISTA for that FATORPRECO.
 *
 * Falls back to the default list (NACIONAL #24) if customer has no specific list.
 */
class CustomerPriceProvider
{
    private const CACHE_PREFIX = 'erp_customer_price_';
    private const CACHE_TTL = 7200; // 2 hours — prices change infrequently; ERP sync cron updates Magento
    private const CONTEXT_VERSION_PREFIX = self::CACHE_PREFIX . 'ctx_v_';
    private const CONTEXT_VERSION_TTL = 31536000; // 1 year

    /**
     * Max seconds a price query to ERP may block a PHP-FPM worker.
     * Fail-fast: if ERP is slow, return null → use Magento base price.
     * Bulk operations (PriceSync cron) use Connection's default 45s timeout.
     */
    private const PRICE_QUERY_TIMEOUT = 6;

    private ConnectionInterface $connection;
    private Helper $helper;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    /** In-memory cache: customerCode → priceListCode */
    private array $customerListCache = [];

    /** In-memory cache: "listCode:sku" → price */
    private array $priceCache = [];

    public function __construct(
        ConnectionInterface $connection,
        Helper $helper,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Get customer-specific price for a SKU
     *
     * @return float|null Customer price, or null if not available
     */
    public function getCustomerPrice(int $erpCustomerCode, string $sku): ?float
    {
        $priceListCode = $this->getCustomerPriceListCode($erpCustomerCode);
        $defaultList = $this->helper->getDefaultPriceList();

        // If customer has the default list or no list, return null (use base Magento price)
        if ($priceListCode === null || $priceListCode === $defaultList) {
            return null;
        }

        return $this->getPriceFromList($priceListCode, $sku);
    }

    /**
     * Resolve a unit price from an ERP list code without a customer ERP code.
     *
     * Used by storefront price plugins when Magento has already depersonalized
     * the customer session (FPC) but HttpContext still carries the list token.
     * Returns null for the default NACIONAL list so Magento catalog remains
     * the authority there.
     */
    public function getAuthorizedListPrice(int $priceListCode, string $sku): ?float
    {
        $defaultList = $this->helper->getDefaultPriceList();
        if ($priceListCode <= 0 || $priceListCode === $defaultList) {
            return null;
        }

        return $this->getPriceFromList($priceListCode, $sku);
    }

    /**
     * Get prices for multiple SKUs for a specific customer
     *
     * @return array SKU → price map (only includes SKUs with customer-specific prices)
     */
    public function getCustomerPrices(int $erpCustomerCode, array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $priceListCode = $this->getCustomerPriceListCode($erpCustomerCode);
        $defaultList = $this->helper->getDefaultPriceList();

        if ($priceListCode === null || $priceListCode === $defaultList) {
            return [];
        }

        return $this->getPricesFromList($priceListCode, $skus);
    }

    /**
     * Get the customer's assigned price list code (FATORPRECO)
     */
    public function getCustomerPriceListCode(int $erpCustomerCode): ?int
    {
        if (isset($this->customerListCache[$erpCustomerCode])) {
            return $this->customerListCache[$erpCustomerCode];
        }

        // Check persistent cache
        $cacheKey = $this->buildListCacheKey($erpCustomerCode);
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            $value = $cached === '' ? null : (int) $cached;
            $this->customerListCache[$erpCustomerCode] = $value;
            return $value;
        }

        try {
            $row = $this->connection->fetchOne(
                "SELECT FATORPRECO FROM FN_FORNECEDORES WHERE CODIGO = ?",
                [$erpCustomerCode]
            );

            $listCode = ($row && !empty($row['FATORPRECO'])) ? (int) $row['FATORPRECO'] : null;

            $this->customerListCache[$erpCustomerCode] = $listCode;
            $this->cache->save(
                (string) ($listCode ?? ''),
                $cacheKey,
                [],
                self::CACHE_TTL
            );

            return $listCode;
        } catch (\Throwable $e) {
            $this->logger->error('[ERP] Error getting customer price list: ' . $e->getMessage());
            $this->customerListCache[$erpCustomerCode] = null;
            $this->cache->save('', $cacheKey, [], 60);
            return null;
        }
    }

    /**
     * Get customer's price list name
     *
     * Result is cached by list code (shared across all customers on the same list).
     * On ERP outage, a short 60-second negative cache prevents hammering.
     */
    public function getCustomerPriceListName(int $erpCustomerCode): ?string
    {
        $listCode = $this->getCustomerPriceListCode($erpCustomerCode);
        if ($listCode === null) {
            return null;
        }

        // Cache by list code — same name for all customers on the same list
        $cacheKey = self::CACHE_PREFIX . 'list_name_' . $listCode;
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false) {
            return $cached === '' ? null : $cached;
        }

        try {
            $row = $this->connection->fetchOne(
                "SELECT DESCRICAO FROM VE_FATORPRECO WHERE CODIGO = ?",
                [$listCode]
            );
            $name = $row ? trim($row['DESCRICAO']) : null;

            $this->cache->save(
                (string) ($name ?? ''),
                $cacheKey,
                [],
                self::CACHE_TTL
            );

            return $name;
        } catch (\Throwable $e) {
            $this->logger->error('[ERP] Error getting price list name: ' . $e->getMessage());
            // Cache empty briefly to avoid hammering ERP during outage
            $this->cache->save('', $cacheKey, [], 60);
            return null;
        }
    }

    /**
     * Get price for a single SKU from a specific price list
     */
    private function getPriceFromList(int $priceListCode, string $sku): ?float
    {
        $cacheKey = $priceListCode . ':' . trim($sku);
        if (isset($this->priceCache[$cacheKey])) {
            return $this->priceCache[$cacheKey] ?: null;
        }

        // Check persistent cache
        $persistKey = $this->buildPricePersistKey($priceListCode, $sku);
        $cached = $this->cache->load($persistKey);
        if ($cached !== false) {
            $price = $cached === '' ? null : (float) $cached;
            $this->priceCache[$cacheKey] = $price ?? 0.0;
            return $price;
        }

        try {
            $filial = $this->helper->getStockFilial();

            $row = $this->connection->fetchOne(
                "SELECT VLRVDSUG FROM MT_MATERIALLISTA
                 WHERE MATERIAL = ? AND FATORPRECO = ? AND FILIAL = ? AND VLRVDSUG > 0",
                [$sku, $priceListCode, $filial],
                self::PRICE_QUERY_TIMEOUT
            );

            // Try base SKU if not found
            if (!$row) {
                $baseSku = $this->getBaseSku($sku);
                if ($baseSku !== $sku) {
                    $row = $this->connection->fetchOne(
                        "SELECT VLRVDSUG FROM MT_MATERIALLISTA
                         WHERE MATERIAL = ? AND FATORPRECO = ? AND FILIAL = ? AND VLRVDSUG > 0",
                        [$baseSku, $priceListCode, $filial],
                        self::PRICE_QUERY_TIMEOUT
                    );
                }
            }

            $price = $row ? (float) $row['VLRVDSUG'] : null;

            $this->priceCache[$cacheKey] = $price ?? 0.0;
            $this->cache->save(
                (string) ($price ?? ''),
                $persistKey,
                [],
                self::CACHE_TTL
            );

            return $price;
        } catch (\Throwable $e) {
            $this->logger->error('[ERP] Error getting price from list: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get prices for multiple SKUs from a specific price list (batch query)
     *
     * @return array SKU → price map
     */
    private function getPricesFromList(int $priceListCode, array $skus): array
    {
        $result = [];
        $uncachedSkus = [];

        // Check in-memory cache first, then persistent Redis cache.
        // Only SKUs missing from both go to the ERP SQL Server query.
        foreach ($skus as $sku) {
            $cacheKey = $priceListCode . ':' . $sku;
            if (isset($this->priceCache[$cacheKey])) {
                // Hot: in-memory hit
                $val = $this->priceCache[$cacheKey];
                if ($val > 0) {
                    $result[$sku] = $val;
                }
            } else {
                // Warm: check persistent Redis cache
                $persistKey = $this->buildPricePersistKey($priceListCode, $sku);
                $cached = $this->cache->load($persistKey);
                if ($cached !== false) {
                    $price = $cached === '' ? null : (float) $cached;
                    $this->priceCache[$cacheKey] = $price ?? 0.0;
                    if ($price !== null && $price > 0) {
                        $result[$sku] = $price;
                    }
                } else {
                    // Cold: must query ERP
                    $uncachedSkus[] = $sku;
                }
            }
        }

        if (empty($uncachedSkus)) {
            return $result;
        }

        // Also include base-SKU variants for broader matching
        $allSkus = [];
        $baseSkuMap = []; // baseSku → [originalSkus]
        foreach ($uncachedSkus as $sku) {
            $allSkus[] = $sku;
            $baseSku = $this->getBaseSku($sku);
            if ($baseSku !== $sku) {
                $allSkus[] = $baseSku;
                $baseSkuMap[$baseSku][] = $sku;
            }
        }
        $allSkus = array_unique($allSkus);

        try {
            $filial = $this->helper->getStockFilial();

            // Build parameterized IN clause
            $placeholders = [];
            $params = [$priceListCode, $filial];
            foreach ($allSkus as $s) {
                $placeholders[] = '?';
                $params[] = trim($s);
            }
            $inClause = implode(',', $placeholders);

            $rows = $this->connection->query(
                "SELECT MATERIAL, VLRVDSUG FROM MT_MATERIALLISTA
                 WHERE FATORPRECO = ? AND FILIAL = ? AND MATERIAL IN ({$inClause}) AND VLRVDSUG > 0",
                $params,
                self::PRICE_QUERY_TIMEOUT
            ) ?? [];

            // Build result map
            $erpPrices = [];
            foreach ($rows as $row) {
                $erpPrices[trim($row['MATERIAL'])] = (float) $row['VLRVDSUG'];
            }

            // Map prices back to original SKUs
            foreach ($uncachedSkus as $sku) {
                $price = $erpPrices[$sku] ?? null;
                if ($price === null) {
                    $baseSku = $this->getBaseSku($sku);
                    $price = $erpPrices[$baseSku] ?? null;
                }

                $cacheKey = $priceListCode . ':' . $sku;
                $this->priceCache[$cacheKey] = $price ?? 0.0;

                // Persist to Redis/cache so next request also skips the ERP query
                $persistKey = $this->buildPricePersistKey($priceListCode, $sku);
                $this->cache->save((string) ($price ?? ''), $persistKey, [], self::CACHE_TTL);

                if ($price !== null && $price > 0) {
                    $result[$sku] = $price;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[ERP] Error batch-fetching prices: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Extract base SKU from variant SKU
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
     * Clear cached prices for a customer
     */
    public function clearCustomerCache(int $erpCustomerCode): void
    {
        $this->invalidateCustomerPriceList($erpCustomerCode);
    }

    /**
     * Pre-warm Redis cache for all SKUs in a given ERP price list.
     *
     * Called by WarmB2BPrices cron after SyncPrices. Fetches the entire list
     * from MT_MATERIALLISTA in a single query and writes each price to Redis
     * using the same key format as getPriceFromList() / getPricesFromList().
     * Overwrites stale entries in-place (no TTL reset disruption).
     *
     * @return int Number of price entries written to Redis
     */
    public function warmPriceList(int $listCode): int
    {
        $filial = $this->helper->getStockFilial();
        $warmed = 0;

        try {
            $rows = $this->connection->query(
                "SELECT MATERIAL, VLRVDSUG FROM MT_MATERIALLISTA
                 WHERE FATORPRECO = ? AND FILIAL = ? AND VLRVDSUG > 0",
                [$listCode, $filial]
            ) ?? [];

            foreach ($rows as $row) {
                $sku = trim($row['MATERIAL']);
                $price = (float) $row['VLRVDSUG'];

                // Match the exact key format used by getPriceFromList / getPricesFromList
                $cacheKey = $listCode . ':' . $sku;
                $persistKey = $this->buildPricePersistKey($listCode, $sku);

                $this->priceCache[$cacheKey] = $price;
                $this->cache->save((string) $price, $persistKey, [], self::CACHE_TTL);
                $warmed++;
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[ERP] warmPriceList error for list #%d: %s',
                $listCode,
                $e->getMessage()
            ));
        }

        return $warmed;
    }

    /**
     * Returns cache context token (priceList:version) for FPC segmentation.
     */
    public function getContextTokenForCustomer(int $erpCustomerCode): ?string
    {
        $listCode = $this->getCustomerPriceListCode($erpCustomerCode);
        if ($listCode === null) {
            return null;
        }

        return $listCode . ':' . $this->getPriceListContextVersion($listCode);
    }

    /**
     * Invalidate customer list cache and bump context versions for affected lists.
     */
    public function invalidateCustomerPriceList(
        int $erpCustomerCode,
        ?int $previousListCode = null,
        ?int $nextListCode = null
    ): void {
        $this->cache->remove($this->buildListCacheKey($erpCustomerCode));
        unset($this->customerListCache[$erpCustomerCode]);

        $listCodes = array_unique(array_filter([$previousListCode, $nextListCode], static fn($v) => $v !== null));
        foreach ($listCodes as $listCode) {
            $this->bumpPriceListContextVersion((int) $listCode);
        }
    }

    /**
     * Invalidate specific list/SKU cached prices.
     *
     * @param int[] $listCodes
     * @param string[] $skus
     * @return int Removed cache entries
     */
    public function invalidatePricesForListsAndSkus(array $listCodes, array $skus): int
    {
        $removed = 0;
        $normalizedSkus = array_values(array_unique(array_map(static fn($sku) => trim((string) $sku), $skus)));

        foreach (array_unique($listCodes) as $listCode) {
            $code = (int) $listCode;
            if ($code <= 0) {
                continue;
            }

            foreach ($normalizedSkus as $sku) {
                if ($sku == '') {
                    continue;
                }

                $cacheKey = $code . ':' . $sku;
                $persistKey = $this->buildPricePersistKey($code, $sku);
                $this->cache->remove($persistKey);
                unset($this->priceCache[$cacheKey]);
                $removed++;
            }

            $this->bumpPriceListContextVersion($code);
        }

        return $removed;
    }

    /**
     * Replace cached list/SKU value and report if the value changed.
     */
    public function replaceCachedPrice(int $priceListCode, string $sku, ?float $price): bool
    {
        $cacheKey = $priceListCode . ':' . trim($sku);
        $persistKey = $this->buildPricePersistKey($priceListCode, $sku);
        $newValue = $this->normalizeCachePrice($price);

        $existing = $this->cache->load($persistKey);
        $changed = $existing !== false && $existing !== $newValue;

        if ($changed) {
            $this->cache->remove($persistKey);
            unset($this->priceCache[$cacheKey]);
        }

        if ($existing === false || $changed) {
            $this->cache->save($newValue, $persistKey, [], self::CACHE_TTL);
        }

        $this->priceCache[$cacheKey] = $price ?? 0.0;

        return $changed;
    }

    public function getPriceListContextVersion(int $priceListCode): int
    {
        $cacheKey = self::CONTEXT_VERSION_PREFIX . $priceListCode;
        $cached = $this->cache->load($cacheKey);
        if ($cached !== false && ctype_digit($cached)) {
            return (int) $cached;
        }

        $this->cache->save('1', $cacheKey, [], self::CONTEXT_VERSION_TTL);
        return 1;
    }

    public function bumpPriceListContextVersion(int $priceListCode): int
    {
        $next = $this->getPriceListContextVersion($priceListCode) + 1;
        $this->cache->save((string) $next, self::CONTEXT_VERSION_PREFIX . $priceListCode, [], self::CONTEXT_VERSION_TTL);

        return $next;
    }

    private function buildListCacheKey(int $erpCustomerCode): string
    {
        return self::CACHE_PREFIX . 'list_' . $erpCustomerCode;
    }

    private function buildPricePersistKey(int $priceListCode, string $sku): string
    {
        return self::CACHE_PREFIX . hash('xxh128', $priceListCode . ':' . trim($sku));
    }

    private function normalizeCachePrice(?float $price): string
    {
        return $price === null ? '' : (string) $price;
    }
}
