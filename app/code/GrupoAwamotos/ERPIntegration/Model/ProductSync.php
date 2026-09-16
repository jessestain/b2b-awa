<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ProductSyncInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use GrupoAwamotos\ERPIntegration\Model\Validator\ProductValidator;
use GrupoAwamotos\ERPIntegration\Api\ColorNormalizationInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Psr\Log\LoggerInterface;

class ProductSync implements ProductSyncInterface
{
    /**
     * Batch size for pagination
     */
    private const BATCH_SIZE = 500;

    /**
     * Memory limit warning threshold (80% of limit)
     */
    private const MEMORY_WARNING_THRESHOLD = 0.8;
    private const FITMENT_ATTR_BRAND = 'marca_moto';
    private const FITMENT_ATTR_MODEL = 'modelo_moto';
    private const FITMENT_ATTR_YEAR = 'ano_moto';

    /**
     * @var array<string, string>
     */
    private const FITMENT_MODEL_TO_BRAND = [
        'biz' => 'Honda',
        'cg' => 'Honda',
        'titan' => 'Honda',
        'bros' => 'Honda',
        'fan' => 'Honda',
        'xre' => 'Honda',
        'cb' => 'Honda',
        'fazer' => 'Yamaha',
        'factor' => 'Yamaha',
        'ybr' => 'Yamaha',
        'ninja' => 'Kawasaki',
    ];

    /**
     * @var array<string, string>
     */
    private const FITMENT_BRAND_NEEDLES = [
        'royal enfield' => 'Royal Enfield',
        'harley' => 'Harley-Davidson',
        'kawasaki' => 'Kawasaki',
        'yamaha' => 'Yamaha',
        'suzuki' => 'Suzuki',
        'honda' => 'Honda',
        'triumph' => 'Triumph',
        'ducati' => 'Ducati',
        'bmw' => 'BMW',
        'dafra' => 'Dafra',
    ];

    private ConnectionInterface $connection;
    private Helper $helper;
    private ProductRepositoryInterface $productRepository;
    private ProductInterfaceFactory $productFactory;
    private StoreManagerInterface $storeManager;
    private SyncLogResource $syncLogResource;
    private ProductValidator $productValidator;
    private LoggerInterface $logger;
    private AppState $appState;
    private CategoryLinkManagementInterface $categoryLinkManagement;
    private StockRegistryInterface $stockRegistry;
    private ColorNormalizationInterface $colorNormalization;
    private ?int $defaultAttributeSetId = null;

    public function __construct(
        ConnectionInterface $connection,
        Helper $helper,
        ProductRepositoryInterface $productRepository,
        ProductInterfaceFactory $productFactory,
        StoreManagerInterface $storeManager,
        SyncLogResource $syncLogResource,
        ProductValidator $productValidator,
        LoggerInterface $logger,
        AppState $appState,
        CategoryLinkManagementInterface $categoryLinkManagement,
        StockRegistryInterface $stockRegistry,
        ColorNormalizationInterface $colorNormalization
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->productRepository = $productRepository;
        $this->productFactory = $productFactory;
        $this->storeManager = $storeManager;
        $this->syncLogResource = $syncLogResource;
        $this->productValidator = $productValidator;
        $this->logger = $logger;
        $this->appState = $appState;
        $this->categoryLinkManagement = $categoryLinkManagement;
        $this->stockRegistry = $stockRegistry;
        $this->colorNormalization = $colorNormalization;
    }

    /**
     * Get total count of ERP products
     */
    public function getErpProductCount(): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM MT_MATERIAL m
                WHERE m.CCKATIVO = 'S'";

        if ($this->helper->filterComercializa()) {
            $sql .= " AND m.CKCOMERCIALIZA = 'S'";
        }

        $result = $this->connection->fetchOne($sql, []);

        return $result ? (int) ($result['total'] ?? 0) : 0;
    }

    public function getErpProducts(int $limit = 0, int $offset = 0): array
    {
        $priceList = $this->helper->getDefaultPriceList();
        $sql = "SELECT m.CODIGO, m.DESCRICAO, m.COMPLEMENTO, m.CODINTERNO,
                       m.NCM, m.CPESO, m.VPESO, m.DIMENSOES, m.UNDVENDA,
                       m.CKCOMERCIALIZA, m.CCKATIVO, m.VCKATIVO, m.TPMATERIAL,
                       m.GRUPOCOMERCIAL, m.EDITDATE, m.COR,
                       c.VLRCUSTO, c.MARGEMSUG,
                       p.VLRVDSUG as VLRVENDA
                FROM MT_MATERIAL m
                LEFT JOIN MT_MATERIALCUSTO c ON c.MATERIAL = m.CODIGO AND c.FILIAL = :filial1
                LEFT JOIN MT_MATERIALLISTA p
                    ON p.MATERIAL = m.CODIGO
                    AND p.FILIAL = :filial2
                    AND p.FATORPRECO = :priceList
                WHERE m.CCKATIVO = 'S'";

        if ($this->helper->filterComercializa()) {
            $sql .= " AND m.CKCOMERCIALIZA = 'S'";
        }

        $sql .= " ORDER BY m.CODIGO";

        // SQL Server requires integer literals for OFFSET/FETCH, not parameters
        if ($limit > 0) {
            $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }

        $params = [
            ':filial1' => $this->helper->getStockFilial(),
            ':filial2' => $this->helper->getStockFilial(),
            ':priceList' => $priceList,
        ];

        return $this->applyBaseSkuPriceFallback(
            $this->connection->query($sql, $params)
        );
    }

    public function syncAll(): array
    {
        $startTime = microtime(true);
        $result = [
            'created' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'errors' => 0,
            'skipped' => 0,
            'validation_failed' => 0,
            'batches_processed' => 0,
            'total_products' => 0,
            'execution_time' => 0,
        ];
        $activeErpCodes = [];

        // Set area code for CLI execution
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set, ignore
        }

        try {
            // Get total count for progress tracking
            $totalCount = $this->getErpProductCount();
            $result['total_products'] = $totalCount;

            if ($totalCount === 0) {
                $this->logger->info('[ERP] No products to sync from ERP');
            } else {
                $this->logger->info('[ERP] Starting product sync', [
                    'total_products' => $totalCount,
                    'batch_size' => self::BATCH_SIZE,
                    'estimated_batches' => ceil($totalCount / self::BATCH_SIZE),
                ]);

                $websiteIds = [$this->storeManager->getDefaultStoreView()->getWebsiteId()];
                $offset = 0;

                // Process in batches
                while ($offset < $totalCount) {
                    // Check memory usage before processing batch
                    $this->checkMemoryUsage();

                    $batchStartTime = microtime(true);
                    $batchResult = $this->processBatch($offset, self::BATCH_SIZE, $websiteIds);

                    $result['created'] += $batchResult['created'];
                    $result['updated'] += $batchResult['updated'];
                    $result['errors'] += $batchResult['errors'];
                    $result['skipped'] += $batchResult['skipped'];
                    $result['validation_failed'] += $batchResult['validation_failed'] ?? 0;
                    $result['batches_processed']++;
                    $activeErpCodes = array_merge($activeErpCodes, $batchResult['active_erp_codes'] ?? []);

                    $batchTime = round((microtime(true) - $batchStartTime) * 1000, 2);

                    $this->logger->info('[ERP] Batch processed', [
                        'batch_number' => $result['batches_processed'],
                        'offset' => $offset,
                        'batch_created' => $batchResult['created'],
                        'batch_updated' => $batchResult['updated'],
                        'batch_errors' => $batchResult['errors'],
                        'batch_time_ms' => $batchTime,
                        'progress' => round(min(100, (($offset + self::BATCH_SIZE) / $totalCount) * 100), 1) . '%',
                    ]);

                    $offset += self::BATCH_SIZE;

                    // Clear entity manager to free memory
                    $this->clearEntityCache();
                }
            }

            $result['deactivated'] = $this->deactivateMissingProducts(
                array_values(array_unique($activeErpCodes))
            );
            $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);

            $this->syncLogResource->addLog(
                'product',
                'import',
                $result['errors'] > 0 ? 'partial' : 'success',
                sprintf(
                    'Criados: %d, Atualizados: %d, Desativados: %d, Erros: %d, Ignorados: %d | Batches: %d | Tempo: %dms',
                    $result['created'],
                    $result['updated'],
                    $result['deactivated'],
                    $result['errors'],
                    $result['skipped'],
                    $result['batches_processed'],
                    $result['execution_time']
                ),
                null,
                null,
                $result['created'] + $result['updated'] + $result['deactivated']
            );

            $this->logger->info('[ERP] Product sync completed', $result);
        } catch (\Exception $e) {
            $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->error('[ERP] Product sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'partial_result' => $result,
            ]);

            $this->syncLogResource->addLog('product', 'import', 'error', $e->getMessage());
            $result['errors']++;
        }

        return $result;
    }

    /**
     * Process a single batch of products
     */
    private function processBatch(int $offset, int $limit, array $websiteIds): array
    {
        $batchResult = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'skipped' => 0,
            'validation_failed' => 0,
            'active_erp_codes' => [],
        ];

        $erpProducts = $this->getErpProducts($limit, $offset);

        foreach ($erpProducts as $erpProduct) {
            $sku = trim((string) ($erpProduct['CODIGO'] ?? ''));
            if ($sku !== '') {
                $batchResult['active_erp_codes'][] = $sku;
            }

            try {
                // Validate product data before sync
                $validationResult = $this->productValidator->validate($erpProduct);

                if (!$validationResult->isValid()) {
                    $batchResult['validation_failed']++;
                    $this->logger->warning('[ERP] Product validation failed', [
                        'sku' => $erpProduct['CODIGO'] ?? '?',
                        'errors' => $validationResult->getErrors(),
                    ]);
                    continue;
                }

                // Log warnings if any
                if ($validationResult->hasWarnings()) {
                    $this->logger->info('[ERP] Product validation warnings', [
                        'sku' => $erpProduct['CODIGO'] ?? '?',
                        'warnings' => $validationResult->getWarnings(),
                    ]);
                }

                $syncResult = $this->syncSingleProduct($erpProduct, $websiteIds, $validationResult);

                switch ($syncResult) {
                    case 'created':
                        $batchResult['created']++;
                        break;
                    case 'updated':
                        $batchResult['updated']++;
                        break;
                    case 'skipped':
                        $batchResult['skipped']++;
                        // For skipped products, still ensure category assignment
                        $this->ensureCategoryAssignment($erpProduct);
                        break;
                }
            } catch (\Exception $e) {
                $batchResult['errors']++;
                $this->logger->error('[ERP] Product sync error', [
                    'sku' => $erpProduct['CODIGO'] ?? '?',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $batchResult;
    }

    /**
     * Sync a single product
     *
     * @return string 'created', 'updated', or 'skipped'
     */
    private function syncSingleProduct(
        array $erpProduct,
        array $websiteIds,
        ?\GrupoAwamotos\ERPIntegration\Model\Validator\ValidationResult $validationResult = null
    ): string {
        // Use validated data if available, otherwise use raw data
        $sku = $validationResult
            ? $validationResult->getField('sku', trim($erpProduct['CODIGO'] ?? ''))
            : trim($erpProduct['CODIGO'] ?? '');

        if (empty($sku)) {
            return 'skipped';
        }

        $name = $validationResult
            ? $validationResult->getField('name', trim($erpProduct['DESCRICAO'] ?? ''))
            : trim($erpProduct['DESCRICAO'] ?? '');

        if (empty($name)) {
            return 'skipped';
        }

        $isActive = $validationResult
            ? $validationResult->getField('is_active', ($erpProduct['CCKATIVO'] ?? 'N') === 'S')
            : ($erpProduct['CCKATIVO'] ?? 'N') === 'S';

        $fitmentSource = trim((string) ($erpProduct['DESCRICAO'] ?? '') . ' ' . (string) ($erpProduct['COMPLEMENTO'] ?? ''));
        $fitmentData = $this->extractFitmentFromText($fitmentSource);

        // Check if data has changed using hash.
        // Include fitment derivado para forçar atualização quando a regra de parsing evoluir.
        $dataHashPayload = $erpProduct;
        $dataHashPayload['__fitment_auto'] = $fitmentData;
        $dataHash = hash('xxh128', json_encode($dataHashPayload));
        $existingHash = $this->syncLogResource->getEntityMapHash('product', $sku);

        if ($existingHash === $dataHash && $this->canSkipUnchangedProduct($sku, $isActive)) {
            // Data hasn't changed, skip update
            return 'skipped';
        }

        try {
            $product = $this->productRepository->get($sku);
            $isNew = false;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $product = $this->productFactory->create();
            $product->setSku($sku);
            $product->setTypeId(Type::TYPE_SIMPLE);
            $product->setAttributeSetId($this->getDefaultAttributeSetId());
            $product->setWebsiteIds($websiteIds);
            $product->setVisibility(Visibility::VISIBILITY_BOTH);
            $isNew = true;
        }

        $product->setName($name);

        // Generate unique URL key using SKU to avoid duplicates
        $urlKey = $this->generateUrlKey($name, $sku);
        $product->setUrlKey($urlKey);

        if (!empty($erpProduct['COMPLEMENTO'])) {
            $product->setShortDescription(trim($erpProduct['COMPLEMENTO']));
        }

        $this->applyFitmentAttributes($product, $fitmentData);

        // Use validated price if available
        $price = $validationResult
            ? $validationResult->getField('price', (float) ($erpProduct['VLRVENDA'] ?? 0))
            : (float) ($erpProduct['VLRVENDA'] ?? 0);

        // Magento requires a price. Set minimum price if none defined.
        if ($price <= 0) {
            $price = 0.01; // Minimum placeholder price
        }
        $product->setPrice($price);

        // Use validated weight if available
        $weight = $validationResult
            ? $validationResult->getField('weight', (float) ($erpProduct['VPESO'] ?? $erpProduct['CPESO'] ?? 0))
            : (float) ($erpProduct['VPESO'] ?? $erpProduct['CPESO'] ?? 0);

        if ($weight > 0) {
            $product->setWeight($weight);
        }

        $product->setStatus($isActive ? Status::STATUS_ENABLED : Status::STATUS_DISABLED);

        if (!empty($erpProduct['CODINTERNO'])) {
            $product->setCustomAttribute('erp_internal_code', $erpProduct['CODINTERNO']);
        }
        if (!empty($erpProduct['NCM'])) {
            $product->setCustomAttribute('erp_ncm', $erpProduct['NCM']);
        }


        // Sincronizar atributo cor do ERP
        if (!empty($erpProduct['COR'])) {
            $colorOptionId = $this->colorNormalization->resolveOptionId($erpProduct['COR']);
            if ($colorOptionId !== null) {
                $product->setCustomAttribute('color', $colorOptionId);
            }
        }

        $this->productRepository->save($product);

        // Configurar estoque infinito: produto sempre disponível, controle de estoque é do ERP
        $this->configureInfiniteStock($product->getSku());

        // Assign product to ERP category via GRUPOCOMERCIAL
        $grupoComercial = $erpProduct['GRUPOCOMERCIAL'] ?? null;
        if ($grupoComercial !== null && $grupoComercial !== '' && (int) $grupoComercial !== 0) {
            $this->assignProductToErpCategory($product, (string) $grupoComercial);
        }

        $this->syncLogResource->setEntityMap(
            'product',
            $sku,
            (int) $product->getId(),
            $dataHash
        );

        return $isNew ? 'created' : 'updated';
    }

    /**
     * Ensure category assignment for products that were skipped (hash unchanged)
     */
    private function ensureCategoryAssignment(array $erpProduct): void
    {
        $grupoComercial = $erpProduct['GRUPOCOMERCIAL'] ?? null;
        if ($grupoComercial === null || $grupoComercial === '' || (int) $grupoComercial === 0) {
            return;
        }

        $sku = trim($erpProduct['CODIGO'] ?? '');
        if (empty($sku)) {
            return;
        }

        try {
            $product = $this->productRepository->get($sku);
            $this->assignProductToErpCategory($product, (string) $grupoComercial);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->logger->warning('[ProductSync::ensureCategoryAssignment] Exceção ignorada: ' . $e->getMessage());
            // Product doesn't exist in Magento
        } catch (\Exception $e) {
            // Non-critical, just log
            $this->logger->debug('[ERP] Category assignment skipped', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Assign product to ERP category WITHOUT removing existing manual categories
     */
    private function assignProductToErpCategory($product, string $erpCategoryCode): void
    {
        try {
            $magentoCategoryId = $this->syncLogResource->getEntityMap('category', $erpCategoryCode);

            if (!$magentoCategoryId) {
                // Category not yet synced — will be assigned on next run
                return;
            }

            $currentCategoryIds = $product->getCategoryIds();

            if (in_array($magentoCategoryId, $currentCategoryIds)) {
                return; // Already assigned
            }

            // Non-destructive merge: add ERP category to existing ones
            $newCategoryIds = array_unique(array_merge($currentCategoryIds, [$magentoCategoryId]));

            $this->categoryLinkManagement->assignProductToCategories(
                $product->getSku(),
                $newCategoryIds
            );

            $this->logger->info('[ERP] Product assigned to ERP category', [
                'sku' => $product->getSku(),
                'erp_category' => $erpCategoryCode,
                'magento_category_id' => $magentoCategoryId,
            ]);
        } catch (\Exception $e) {
            // Never fail product sync due to category assignment error
            $this->logger->warning('[ERP] Category assignment failed', [
                'sku' => $product->getSku(),
                'erp_category' => $erpCategoryCode,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate unique URL key for product
     * Combines product name with SKU to ensure uniqueness
     */
    private function generateUrlKey(string $name, string $sku): string
    {
        // Transliterate accented characters
        $urlKey = $this->transliterate($name);

        // Convert to lowercase
        $urlKey = strtolower($urlKey);

        // Replace non-alphanumeric characters with hyphens
        $urlKey = preg_replace('/[^a-z0-9]+/', '-', $urlKey);

        // Remove leading/trailing hyphens
        $urlKey = trim($urlKey, '-');

        // Append SKU to ensure uniqueness
        $skuSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $sku));
        $skuSlug = trim($skuSlug, '-');

        // Combine name and SKU
        $urlKey = $urlKey . '-' . $skuSlug;

        // Limit length (Magento limit is 255, but we keep it shorter for readability)
        if (strlen($urlKey) > 200) {
            $urlKey = substr($urlKey, 0, 200);
            $urlKey = rtrim($urlKey, '-');
        }

        return $urlKey;
    }

    /**
     * Transliterate accented characters to ASCII
     */
    private function transliterate(string $string): string
    {
        $transliterations = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ];

        return strtr($string, $transliterations);
    }

    /**
     * @return array{brand:string,model:string,year:string}
     */
    private function extractFitmentFromText(string $source): array
    {
        $source = trim($source);
        if ($source === '') {
            return ['brand' => '', 'model' => '', 'year' => ''];
        }

        $brand = $this->detectFitmentBrand($source);
        $modelData = $this->detectFitmentModel($source);
        $modelToken = $modelData['token'];
        $model = $modelData['label'];

        if ($brand === '' && $modelToken !== '' && isset(self::FITMENT_MODEL_TO_BRAND[$modelToken])) {
            $brand = self::FITMENT_MODEL_TO_BRAND[$modelToken];
        }

        return [
            'brand' => $brand,
            'model' => $model,
            'year' => $this->detectFitmentYear($source),
        ];
    }

    private function detectFitmentBrand(string $source): string
    {
        $normalized = mb_strtolower($this->transliterate($source));
        foreach (self::FITMENT_BRAND_NEEDLES as $needle => $label) {
            if (str_contains($normalized, $needle)) {
                return $label;
            }
        }

        return '';
    }

    /**
     * @return array{token:string,label:string}
     */
    private function detectFitmentModel(string $source): array
    {
        if (preg_match('/\b(fazer|factor|ybr|ninja|biz|bros|fan|xre|cg|titan|cb|pcx|xj6|mt|xtz|lander|crosser|burgman|intruder|yes|neo|crypton)(?:[\s\-\/]*(\d{2,4}))?\b/i', $source, $match) !== 1) {
            return ['token' => '', 'label' => ''];
        }

        $token = mb_strtolower((string) ($match[1] ?? ''));
        return [
            'token' => $token,
            'label' => $this->formatFitmentModelLabel($token, (string) ($match[2] ?? '')),
        ];
    }

    private function detectFitmentYear(string $source): string
    {
        if (preg_match('/\b((?:19|20)?\d{2})\s*(?:\/|-)\s*((?:19|20)?\d{2})\b/', $source, $yearRange) === 1) {
            $startYear = $this->normalizeFitmentYear((string) ($yearRange[1] ?? ''));
            $endYear = $this->normalizeFitmentYear((string) ($yearRange[2] ?? ''));
            if ($startYear !== '' && $endYear !== '') {
                return $startYear === $endYear ? $startYear : $startYear . '-' . $endYear;
            }
        }

        if (preg_match('/\b(19|20)\d{2}\b/', $source, $singleYear) === 1) {
            return (string) ($singleYear[0] ?? '');
        }

        return '';
    }

    private function formatFitmentModelLabel(string $token, string $suffix): string
    {
        $token = mb_strtolower(trim($token));
        if ($token === '') {
            return '';
        }

        $labelMap = [
            'cg' => 'CG',
            'cb' => 'CB',
            'xre' => 'XRE',
            'xj6' => 'XJ6',
            'mt' => 'MT',
            'xtz' => 'XTZ',
            'pcx' => 'PCX',
            'ybr' => 'YBR',
        ];

        $base = $labelMap[$token] ?? ucfirst($token);
        $suffix = trim($suffix);

        return $suffix !== '' ? $base . ' ' . $suffix : $base;
    }

    private function normalizeFitmentYear(string $year): string
    {
        $digits = preg_replace('/\D+/', '', $year) ?? '';
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 4) {
            return $digits;
        }

        if (strlen($digits) === 2) {
            $value = (int) $digits;
            return ($value <= 30 ? '20' : '19') . $digits;
        }

        return '';
    }

    /**
     * @param array{brand:string,model:string,year:string} $fitmentData
     */
    private function applyFitmentAttributes($product, array $fitmentData): void
    {
        $attributeMap = [
            self::FITMENT_ATTR_BRAND => 'brand',
            self::FITMENT_ATTR_MODEL => 'model',
            self::FITMENT_ATTR_YEAR => 'year',
        ];

        foreach ($attributeMap as $attributeCode => $fitmentKey) {
            $newValue = trim((string) ($fitmentData[$fitmentKey] ?? ''));
            if ($newValue === '') {
                continue;
            }

            $currentValue = trim((string) $product->getData($attributeCode));
            if ($currentValue === $newValue) {
                continue;
            }

            $product->setCustomAttribute($attributeCode, $newValue);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $erpProducts
     * @return array<int, array<string, mixed>>
     */
    private function applyBaseSkuPriceFallback(array $erpProducts): array
    {
        if (empty($erpProducts)) {
            return $erpProducts;
        }

        $baseSkuByIndex = [];

        foreach ($erpProducts as $index => $erpProduct) {
            $price = (float) ($erpProduct['VLRVENDA'] ?? 0);
            if ($price > 0) {
                continue;
            }

            $sku = trim((string) ($erpProduct['CODIGO'] ?? ''));
            $baseSku = $this->getBaseSku($sku);
            if ($sku === '' || $baseSku === $sku) {
                continue;
            }

            $baseSkuByIndex[$index] = $baseSku;
        }

        if (empty($baseSkuByIndex)) {
            return $erpProducts;
        }

        $baseSkuPriceMap = $this->getBaseSkuPriceMap(array_values(array_unique($baseSkuByIndex)));

        foreach ($baseSkuByIndex as $index => $baseSku) {
            if (!isset($baseSkuPriceMap[$baseSku])) {
                continue;
            }

            $erpProducts[$index]['VLRVENDA'] = $baseSkuPriceMap[$baseSku];
        }

        return $erpProducts;
    }

    /**
     * @param string[] $baseSkus
     * @return array<string, float>
     */
    private function getBaseSkuPriceMap(array $baseSkus): array
    {
        if (empty($baseSkus)) {
            return [];
        }

        $placeholders = [];
        $params = [
            $this->helper->getDefaultPriceList(),
            $this->helper->getStockFilial(),
        ];

        foreach ($baseSkus as $baseSku) {
            $placeholders[] = '?';
            $params[] = $baseSku;
        }

        $sql = "SELECT MATERIAL as CODIGO, VLRVDSUG as VLRVENDA
                FROM MT_MATERIALLISTA
                WHERE FATORPRECO = ?
                  AND FILIAL = ?
                  AND MATERIAL IN (" . implode(',', $placeholders) . ")
                  AND VLRVDSUG > 0";

        try {
            $rows = $this->connection->query($sql, $params) ?? [];
        } catch (\Exception $e) {
            $this->logger->warning('[ERP] Base SKU price fallback query failed', [
                'error' => $e->getMessage(),
                'base_skus' => $baseSkus,
            ]);
            return [];
        }

        $priceMap = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['CODIGO'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $priceMap[$sku] = (float) ($row['VLRVENDA'] ?? 0);
        }

        return $priceMap;
    }

    private function getBaseSku(string $sku): string
    {
        $sku = trim($sku);

        if (str_contains($sku, ' ')) {
            return trim(explode(' ', $sku)[0]);
        }

        if (preg_match('/^(\d{3,})\.\d+$/', $sku, $match)) {
            return $match[1];
        }

        if (preg_match('/^(\d{3,})[A-Z]{2,3}$/i', $sku, $match)) {
            return $match[1];
        }

        return $sku;
    }

    /**
     * Configure product as infinite stock: manage_stock=false, is_in_stock=true.
     * Stock control is handled by the ERP, not by Magento.
     */
    private function configureInfiniteStock(string $sku): void
    {
        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
            if ($stockItem->getManageStock() || $stockItem->getUseConfigManageStock() || !$stockItem->getIsInStock()) {
                $stockItem->setUseConfigManageStock(false);
                $stockItem->setManageStock(false);
                $stockItem->setIsInStock(true);
                $this->stockRegistry->updateStockItemBySku($sku, $stockItem);
            }
        } catch (\Exception $e) {
            $this->logger->warning('[ERP] Failed to configure infinite stock for SKU: ' . $sku, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get default attribute set ID
     */
    private function getDefaultAttributeSetId(): int
    {
        if ($this->defaultAttributeSetId === null) {
            // Get from default product or use 4 as fallback
            $this->defaultAttributeSetId = 4;
        }

        return $this->defaultAttributeSetId;
    }

    /**
     * Check memory usage and log warning if high
     */
    private function checkMemoryUsage(): void
    {
        $memoryLimit = $this->getMemoryLimitBytes();
        $currentUsage = memory_get_usage(true);

        if ($memoryLimit > 0 && ($currentUsage / $memoryLimit) > self::MEMORY_WARNING_THRESHOLD) {
            $this->logger->warning('[ERP] High memory usage during product sync', [
                'current_usage_mb' => round($currentUsage / 1024 / 1024, 2),
                'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
                'percentage' => round(($currentUsage / $memoryLimit) * 100, 1),
            ]);
        }
    }

    /**
     * Get PHP memory limit in bytes
     */
    private function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return 0; // No limit
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        switch ($unit) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Clear entity cache to free memory
     */
    private function clearEntityCache(): void
    {
        // Force garbage collection
        gc_collect_cycles();
    }

    private function canSkipUnchangedProduct(string $sku, bool $isActive): bool
    {
        try {
            $product = $this->productRepository->get($sku);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return false;
        } catch (\Exception $e) {
            $this->logger->warning('[ERP] Unable to verify unchanged product state', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $expectedStatus = $isActive ? Status::STATUS_ENABLED : Status::STATUS_DISABLED;
        return (int) $product->getStatus() === $expectedStatus;
    }

    /**
     * Disable mapped Magento products that are no longer part of the current ERP scope.
     *
     * @param string[] $activeErpCodes
     */
    private function deactivateMissingProducts(array $activeErpCodes): int
    {
        $deactivated = 0;
        $activeCodeMap = array_fill_keys($activeErpCodes, true);

        $connection = $this->syncLogResource->getConnection();
        $select = $connection->select()
            ->from('grupoawamotos_erp_entity_map', ['erp_code', 'magento_entity_id'])
            ->where('entity_type = ?', 'product');

        $allMapped = $connection->fetchPairs($select);

        foreach ($allMapped as $erpCode => $magentoId) {
            $erpCode = trim((string) $erpCode);
            if (isset($activeCodeMap[$erpCode])) {
                continue;
            }

            try {
                $product = $this->productRepository->getById((int) $magentoId);
                if ((int) $product->getStatus() === Status::STATUS_DISABLED) {
                    continue;
                }

                $product->setStatus(Status::STATUS_DISABLED);
                $this->productRepository->save($product);
                $deactivated++;

                $this->logger->info('[ERP] Product deactivated (missing from current ERP scope)', [
                    'erp_code' => $erpCode,
                    'magento_id' => (int) $magentoId,
                    'sku' => $product->getSku(),
                ]);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->warning('[ProductSync::deactivateMissingProducts] Exceção ignorada: ' . $e->getMessage());
                // Product was removed manually from Magento.
            } catch (\Exception $e) {
                $this->logger->warning('[ERP] Failed to deactivate missing product', [
                    'erp_code' => $erpCode,
                    'magento_id' => (int) $magentoId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($deactivated > 0) {
            $this->logger->info('[ERP] Missing mapped products were disabled', [
                'deactivated' => $deactivated,
            ]);
        }

        return $deactivated;
    }

    /**
     * Sync only products modified since the last successful product sync.
     * Falls back to syncAll() if no previous sync exists.
     *
     * @return array Same result shape as syncAll()
     */
    public function syncDelta(): array
    {
        $lastSync = $this->getLastSuccessfulProductSync();

        if ($lastSync === null) {
            $this->logger->info('[ERP] No previous product sync found, running full sync');
            return $this->syncAll();
        }

        $startTime = microtime(true);
        $result = [
            'created' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'errors' => 0,
            'skipped' => 0,
            'validation_failed' => 0,
            'batches_processed' => 0,
            'total_products' => 0,
            'execution_time' => 0,
            'mode' => 'delta',
        ];

        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->logger->warning('[ProductSync::syncDelta] Exceção ignorada: ' . $e->getMessage());
            // Already set
        }

        try {
            $deltaProducts = $this->getErpProductsModifiedSince($lastSync);
            $result['total_products'] = count($deltaProducts);

            if ($result['total_products'] === 0) {
                $this->logger->info('[ERP] Delta product sync: no changes since ' . $lastSync);
                $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);
                return $result;
            }

            $this->logger->info('[ERP] Starting delta product sync', [
                'modified_since' => $lastSync,
                'total_products' => $result['total_products'],
            ]);

            $websiteIds = [$this->storeManager->getDefaultStoreView()->getWebsiteId()];

            foreach ($deltaProducts as $erpProduct) {
                $sku = trim((string) ($erpProduct['CODIGO'] ?? ''));

                try {
                    $validationResult = $this->productValidator->validate($erpProduct);
                    if (!$validationResult->isValid()) {
                        $result['validation_failed']++;
                        continue;
                    }

                    $syncResult = $this->syncSingleProduct($erpProduct, $websiteIds, $validationResult);
                    match ($syncResult) {
                        'created' => $result['created']++,
                        'updated' => $result['updated']++,
                        default => $result['skipped']++,
                    };
                } catch (\Exception $e) {
                    $result['errors']++;
                    $this->logger->error('[ERP] Delta product sync error', [
                        'sku' => $sku,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);

            $this->syncLogResource->addLog(
                'product',
                'import',
                $result['errors'] > 0 ? 'partial' : 'success',
                sprintf(
                    '[Delta] Criados: %d, Atualizados: %d, Erros: %d, Ignorados: %d | Tempo: %dms | Desde: %s',
                    $result['created'],
                    $result['updated'],
                    $result['errors'],
                    $result['skipped'],
                    $result['execution_time'],
                    $lastSync
                ),
                null,
                null,
                $result['created'] + $result['updated']
            );

            $this->logger->info('[ERP] Delta product sync completed', $result);
        } catch (\Exception $e) {
            $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);
            $this->logger->error('[ERP] Delta product sync failed', [
                'error' => $e->getMessage(),
            ]);
            $this->syncLogResource->addLog('product', 'import', 'error', $e->getMessage());
            $result['errors']++;
        }

        return $result;
    }

    /**
     * Get ERP products modified since a given datetime
     */
    private function getErpProductsModifiedSince(string $since): array
    {
        $priceList = $this->helper->getDefaultPriceList();
        $sql = "SELECT m.CODIGO, m.DESCRICAO, m.COMPLEMENTO, m.CODINTERNO,
                       m.NCM, m.CPESO, m.VPESO, m.DIMENSOES, m.UNDVENDA,
                       m.CKCOMERCIALIZA, m.CCKATIVO, m.VCKATIVO, m.TPMATERIAL,
                       m.GRUPOCOMERCIAL, m.EDITDATE, m.COR,
                       c.VLRCUSTO, c.MARGEMSUG,
                       p.VLRVDSUG as VLRVENDA
                FROM MT_MATERIAL m
                LEFT JOIN MT_MATERIALCUSTO c ON c.MATERIAL = m.CODIGO AND c.FILIAL = :filial1
                LEFT JOIN MT_MATERIALLISTA p
                    ON p.MATERIAL = m.CODIGO
                    AND p.FILIAL = :filial2
                    AND p.FATORPRECO = :priceList
                WHERE m.CCKATIVO = 'S'
                AND m.EDITDATE > :since";

        if ($this->helper->filterComercializa()) {
            $sql .= " AND m.CKCOMERCIALIZA = 'S'";
        }

        $sql .= " ORDER BY m.EDITDATE ASC";

        $params = [
            ':filial1' => $this->helper->getStockFilial(),
            ':filial2' => $this->helper->getStockFilial(),
            ':priceList' => $priceList,
            ':since' => $since,
        ];

        return $this->applyBaseSkuPriceFallback(
            $this->connection->query($sql, $params)
        );
    }

    /**
     * Get timestamp of last successful product sync from sync log
     */
    private function getLastSuccessfulProductSync(): ?string
    {
        $connection = $this->syncLogResource->getConnection();
        $select = $connection->select()
            ->from('grupoawamotos_erp_sync_log', 'created_at')
            ->where('entity_type = ?', 'product')
            ->where('status IN (?)', ['success', 'partial'])
            ->order('created_at DESC')
            ->limit(1);

        $result = $connection->fetchOne($select);
        return $result ?: null;
    }

    public function syncBySku(string $sku): bool
    {
        try {
            try {
                $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
            // Area code already set by CLI bootstrap or a previous call in this
            // same request; safe to ignore.
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                // Area code already set, ignore
            }

            $sql = "SELECT m.CODIGO, m.DESCRICAO, m.COMPLEMENTO, m.CODINTERNO,
                           m.NCM, m.CPESO, m.VPESO, m.DIMENSOES, m.UNDVENDA,
                           m.CKCOMERCIALIZA, m.CCKATIVO, m.VCKATIVO, m.TPMATERIAL,
                           m.GRUPOCOMERCIAL, m.EDITDATE, m.COR,
                           c.VLRCUSTO, c.MARGEMSUG,
                           p.VLRVDSUG as VLRVENDA
                    FROM MT_MATERIAL m
                    LEFT JOIN MT_MATERIALCUSTO c ON c.MATERIAL = m.CODIGO AND c.FILIAL = :filial1
                    LEFT JOIN MT_MATERIALLISTA p
                        ON p.MATERIAL = m.CODIGO
                        AND p.FILIAL = :filial2
                        AND p.FATORPRECO = :priceList
                    WHERE m.CODIGO = :sku AND m.CCKATIVO = 'S'";

            $row = $this->connection->fetchOne($sql, [
                ':filial1' => $this->helper->getStockFilial(),
                ':filial2' => $this->helper->getStockFilial(),
                ':priceList' => $this->helper->getDefaultPriceList(),
                ':sku' => $sku,
            ]);

            if ($row === null) {
                return false;
            }

            $row = $this->applyBaseSkuPriceFallback([$row])[0] ?? $row;

            $validationResult = $this->productValidator->validate($row);

            if (!$validationResult->isValid()) {
                $this->logger->warning('[ERP] Product validation failed', [
                    'sku' => $row['CODIGO'] ?? $sku,
                    'errors' => $validationResult->getErrors(),
                ]);
                return false;
            }

            if ($validationResult->hasWarnings()) {
                $this->logger->info('[ERP] Product validation warnings', [
                    'sku' => $row['CODIGO'] ?? $sku,
                    'warnings' => $validationResult->getWarnings(),
                ]);
            }

            $websiteIds = [$this->storeManager->getDefaultStoreView()->getWebsiteId()];
            $result = $this->syncSingleProduct($row, $websiteIds, $validationResult);

            return in_array($result, ['created', 'updated', 'skipped'], true);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Single product sync error: ' . $e->getMessage());
            return false;
        }
    }
}
