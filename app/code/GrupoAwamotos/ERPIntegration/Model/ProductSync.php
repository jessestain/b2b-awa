<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ProductSyncInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use GrupoAwamotos\ERPIntegration\Model\Product\CatalogPublicationGuard;
use GrupoAwamotos\ERPIntegration\Model\Product\InactiveProductDeactivationGuard;
use GrupoAwamotos\ERPIntegration\Model\Product\InactiveProductReactivationGuard;
use GrupoAwamotos\ERPIntegration\Model\Product\NonCatalogProductList;
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
        $scope = $this->helper->getProductScope();

        if ($scope === 'uniao' || $scope === 'com_preco') {
            // precisa do JOIN para saber se o produto tem preço na lista padrão
            $sql = "SELECT COUNT(*) as total
                    FROM MT_MATERIAL m
                    LEFT JOIN MT_MATERIALLISTA p
                        ON p.MATERIAL = m.CODIGO
                        AND p.FILIAL = :filial
                        AND p.FATORPRECO = :priceList
                    WHERE m.CCKATIVO = 'S'
                      AND " . ($scope === 'uniao'
                            ? "(m.CKCOMERCIALIZA = 'S' OR p.VLRVDSUG > 0)"
                            : "p.VLRVDSUG > 0");
            $result = $this->connection->fetchOne($sql, [
                ':filial' => $this->helper->getStockFilial(),
                ':priceList' => $this->helper->getDefaultPriceList(),
            ]);
            return $result ? (int) ($result['total'] ?? 0) : 0;
        }

        $sql = "SELECT COUNT(*) as total
                FROM MT_MATERIAL m
                WHERE m.CCKATIVO = 'S'";

        if ($scope === 'comercializa') {
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

        $scope = $this->helper->getProductScope();
        if ($scope === 'comercializa') {
            $sql .= " AND m.CKCOMERCIALIZA = 'S'";
        } elseif ($scope === 'uniao') {
            // soma: comercializável OU com preço — NUNCA remove o que já estava
            $sql .= " AND (m.CKCOMERCIALIZA = 'S' OR p.VLRVDSUG > 0)";
        } elseif ($scope === 'com_preco') {
            // SUBSTITUI o critério: só com preço (pode remover comercializáveis sem preço)
            $sql .= " AND p.VLRVDSUG > 0";
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

            $result['deactivated'] = (int) $this->reconcileInactiveProducts()['deactivated'];
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

        /* Raiz do F2: com preco ausente o sync gravava R$ 0,01 E deixava o produto
         * HABILITADO. Com o portao ligado (e fora do dry-run) produto sem preco no
         * Sectra nasce/permanece desabilitado. Fica antes do hash/skip de proposito:
         * assim o estado esperado ja considera o portao e o produto nao e regravado
         * a cada sync completo. */
        if ($isActive && $this->isPublicationGateBlockingPrice($erpProduct, $validationResult)) {
            $isActive = false;
        }

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
            // B2B Densidade: tenta a base numérica ("1119 RS" -> "1119"),
            // que é o SKU real da loja para a maioria dos produtos.
            $baseSku = $this->getBaseSku($sku);
            if ($baseSku !== $sku) {
                try {
                    $product = $this->productRepository->get($baseSku);
                    $isNew = false;
                    $sku = $baseSku;
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e2) {
                    $product = $this->productFactory->create();
                    $product->setSku($sku);
                    $isNew = true;
                }
            } else {
                $product = $this->productFactory->create();
                $product->setSku($sku);
                $isNew = true;
            }
        }
        if ($isNew) {
            $product->setTypeId(Type::TYPE_SIMPLE);
            $product->setAttributeSetId($this->getDefaultAttributeSetId());
            $product->setWebsiteIds($websiteIds);
            $product->setVisibility(Visibility::VISIBILITY_BOTH);
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

        // Classe fiscal do produto. O sync nao definia isto e 498 produtos ficaram
        // com tax_class_id=0 (None) enquanto 678 tinham 2 (Taxable Goods) — medido
        // em 05/10/2026. Sem regra de imposto configurada isso nao muda o preco hoje,
        // mas se um dia houver regra, os produtos sem classe ficariam de fora.
        // Idempotente: so grava quando o valor atual e 0/ausente.
        $taxClassId = $this->helper->getDefaultTaxClassId();
        $currentTaxClass = $product->getTaxClassId();
        if ($currentTaxClass === null || (int) $currentTaxClass <= 0) {
            $product->setTaxClassId($taxClassId);
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

    /**
     * Ano minimo plausivel de fitment (moto mais antiga com faixa real no catalogo
     * era 1979-1984). Sem essa guarda, padroes "NN/NN" alheios a ano (ex.: "34/41 L"
     * de capacidade de bauleto) viravam "1934-1941" via normalizeFitmentYear().
     */
    private const FITMENT_YEAR_MIN = 1975;

    private function detectFitmentYear(string $source): string
    {
        $maxYear = (int) date('Y') + 2;

        if (preg_match('/\b((?:19|20)?\d{2})\s*(?:\/|-)\s*((?:19|20)?\d{2})\b/', $source, $yearRange) === 1) {
            $startYear = $this->normalizeFitmentYear((string) ($yearRange[1] ?? ''));
            $endYear = $this->normalizeFitmentYear((string) ($yearRange[2] ?? ''));
            if (
                $startYear !== ''
                && $endYear !== ''
                && $this->isPlausibleFitmentYear((int) $startYear, $maxYear)
                && $this->isPlausibleFitmentYear((int) $endYear, $maxYear)
                && (int) $startYear <= (int) $endYear
            ) {
                return $startYear === $endYear ? $startYear : $startYear . '-' . $endYear;
            }
        }

        if (preg_match('/\b(19|20)\d{2}\b/', $source, $singleYear) === 1) {
            $year = (string) ($singleYear[0] ?? '');
            if ($this->isPlausibleFitmentYear((int) $year, $maxYear)) {
                return $year;
            }
        }

        return '';
    }

    private function isPlausibleFitmentYear(int $year, int $maxYear): bool
    {
        return $year >= self::FITMENT_YEAR_MIN && $year <= $maxYear;
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
     * Disable Magento products that exist in Sectra but are outside the active scope.
     *
     * Active scope is CCKATIVO = S, plus CKCOMERCIALIZA = S when filter_comercializa is on.
     * SKUs that do not exist in MT_MATERIAL are left untouched.
     * An empty Sectra result never disables the catalog.
     *
     * @return array{
     *     deactivated:int,
     *     candidates:int,
     *     active_erp:int,
     *     all_erp:int,
     *     skipped:bool,
     *     skip_reason:?string,
     *     candidate_skus:string[]
     * }
     */
    public function reconcileInactiveProducts(bool $dryRun = false): array
    {
        $result = [
            'deactivated' => 0,
            'candidates' => 0,
            'active_erp' => 0,
            'all_erp' => 0,
            'skipped' => true,
            'skip_reason' => null,
            'candidate_skus' => [],
        ];

        try {
            $scope = $this->getErpMaterialScope();
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Unable to load Sectra material scope for deactivation', [
                'error' => $e->getMessage(),
            ]);
            $result['skip_reason'] = 'erp_scope_query_failed';
            return $result;
        }

        $result['all_erp'] = count($scope['all']);
        $result['active_erp'] = count($scope['active']);

        if (!InactiveProductDeactivationGuard::shouldDeactivate($result['all_erp'], $result['active_erp'])) {
            $reason = $result['all_erp'] === 0
                ? 'erp_returned_zero_materials'
                : 'erp_returned_zero_active_materials';
            $this->logger->warning('[ERP] Skipping product deactivation', [
                'reason' => $reason,
                'all_erp' => $result['all_erp'],
                'active_erp' => $result['active_erp'],
            ]);
            $result['skip_reason'] = $reason;
            return $result;
        }

        $candidates = $this->findOutOfScopeEnabledProducts($scope);
        $result['skipped'] = false;
        $result['candidates'] = count($candidates);
        $result['candidate_skus'] = array_slice(array_column($candidates, 'sku'), 0, 50);

        if ($candidates === []) {
            return $result;
        }

        if ($dryRun) {
            $this->logger->info('[ERP] Dry-run: products outside Sectra active scope', [
                'candidates' => $result['candidates'],
                'sample' => $result['candidate_skus'],
            ]);
            return $result;
        }

        $deactivated = 0;
        foreach ($candidates as $candidate) {
            $entityId = (int) $candidate['entity_id'];
            try {
                $product = $this->productRepository->getById($entityId, true, 0, true);
                $product->setStoreId(0);
                $product->setStatus(Status::STATUS_DISABLED);
                $this->productRepository->save($product);
                $this->persistGlobalDisabledStatus($entityId);
                $deactivated++;

                $this->logger->info('[ERP] Product deactivated (outside current Sectra active scope)', [
                    'sku' => $candidate['sku'],
                    'erp_code' => $candidate['erp_code'],
                    'magento_id' => $entityId,
                ]);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->warning(
                    '[ProductSync::reconcileInactiveProducts] Exceção ignorada: ' . $e->getMessage()
                );
            } catch (\Exception $e) {
                $this->logger->warning('[ERP] Failed to deactivate out-of-scope product', [
                    'sku' => $candidate['sku'],
                    'magento_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }


        $result['deactivated'] = $deactivated;
        if ($deactivated > 0) {
            $this->logger->info('[ERP] Out-of-scope Magento products were disabled', [
                'deactivated' => $deactivated,
                'candidates' => $result['candidates'],
            ]);
        }

        return $result;
    }

    /**
     * Lado espelhado de reconcileInactiveProducts: o Sectra diz que o produto deve
     * ser vendido, mas ele esta inativo na loja. Sem isto, produto que sai do escopo
     * e depois volta NUNCA volta a venda — foi o que criou os 27 casos de 06/10/2026.
     *
     * E mais estrita que a desativacao, porque reativar coloca coisa a venda:
     *   - mesma guarda de conectividade (Sectra vazio => nao faz nada)
     *   - exige preco utilizavel (ver InactiveProductReactivationGuard)
     *   - NAO decide se o item e do ramo da loja; isso e julgamento a parte
     *
     * @return array{reactivated:int, candidates:int, blocked_by_price:int,
     *               blocked_skus:list<string>, blocked_not_catalog:int,
     *               blocked_not_catalog_skus:list<string>, all_erp:int, active_erp:int,
     *               skipped:bool, skip_reason:?string, candidate_skus:list<string>}
     */
    public function reconcileReactivation(bool $dryRun = false): array
    {
        $result = [
            'reactivated' => 0,
            'candidates' => 0,
            'blocked_by_price' => 0,
            'blocked_skus' => [],
            'blocked_not_catalog' => 0,
            'blocked_not_catalog_skus' => [],
            'all_erp' => 0,
            'active_erp' => 0,
            'skipped' => true,
            'skip_reason' => null,
            'candidate_skus' => [],
        ];

        try {
            $scope = $this->getErpMaterialScope();
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Unable to load Sectra material scope for reactivation', [
                'error' => $e->getMessage(),
            ]);
            $result['skip_reason'] = 'erp_scope_query_failed';
            return $result;
        }

        $result['all_erp'] = count($scope['all']);
        $result['active_erp'] = count($scope['active']);

        if (!InactiveProductReactivationGuard::shouldReactivate($result['all_erp'], $result['active_erp'])) {
            $reason = $result['all_erp'] === 0
                ? 'erp_returned_zero_materials'
                : 'erp_returned_zero_active_materials';
            $this->logger->warning('[ERP] Skipping product reactivation', [
                'reason' => $reason,
                'all_erp' => $result['all_erp'],
                'active_erp' => $result['active_erp'],
            ]);
            $result['skip_reason'] = $reason;
            return $result;
        }

        $candidates = $this->findInScopeDisabledProducts($scope);
        $result['skipped'] = false;
        $result['candidates'] = count($candidates);
        $result['candidate_skus'] = array_slice(array_column($candidates, 'sku'), 0, 50);

        if ($candidates === []) {
            return $result;
        }

        // Preco e checado SEMPRE, inclusive no dry-run: e o que separa os 3 reativaveis
        // dos 21 com R$ 0,01 medidos em 06/10/2026.
        // A lista de itens fora do ramo e checada antes: o Sectra marca "MESA DE CORTE
        // PARA VIDRO" e "MANETE DE TITAN" do mesmo jeito, e preco certo num item errado
        // nao o torna vendavel.
        $liberados = [];
        foreach ($candidates as $candidate) {
            if (NonCatalogProductList::isExcluded((string) $candidate['sku'])) {
                $result['blocked_not_catalog']++;
                if (count($result['blocked_not_catalog_skus']) < 50) {
                    $result['blocked_not_catalog_skus'][] = $candidate['sku'];
                }
                continue;
            }
            if (!InactiveProductReactivationGuard::hasUsablePrice($candidate['price'])) {
                $result['blocked_by_price']++;
                if (count($result['blocked_skus']) < 50) {
                    $result['blocked_skus'][] = $candidate['sku'];
                }
                continue;
            }
            $liberados[] = $candidate;
        }

        if ($result['blocked_not_catalog'] > 0) {
            $this->logger->warning('[ERP] Products in Sectra scope but outside the store catalogue', [
                'blocked' => $result['blocked_not_catalog'],
                'skus' => $result['blocked_not_catalog_skus'],
                'why' => 'cadastro do Sectra marca como ativo+comercializa; ver NonCatalogProductList',
            ]);
        }

        if ($result['blocked_by_price'] > 0) {
            $this->logger->warning('[ERP] Products in Sectra scope held back by unusable price', [
                'blocked' => $result['blocked_by_price'],
                'floor' => InactiveProductReactivationGuard::PRICE_FLOOR,
                'sample' => array_slice($result['blocked_skus'], 0, 20),
            ]);
        }

        if ($dryRun) {
            $this->logger->info('[ERP] Dry-run: products inside Sectra active scope but disabled', [
                'candidates' => $result['candidates'],
                'reactivatable' => count($liberados),
                'blocked_by_price' => $result['blocked_by_price'],
                'sample' => array_slice(array_column($liberados, 'sku'), 0, 50),
            ]);
            $result['reactivatable'] = count($liberados);
            return $result;
        }

        $reactivated = 0;
        foreach ($liberados as $candidate) {
            $entityId = (int) $candidate['entity_id'];
            try {
                $product = $this->productRepository->getById($entityId, true, 0, true);
                $product->setStoreId(0);
                $product->setStatus(Status::STATUS_ENABLED);
                $this->productRepository->save($product);
                $this->persistGlobalEnabledStatus($entityId);
                $reactivated++;

                $this->logger->info('[ERP] Product reactivated (back inside Sectra active scope)', [
                    'sku' => $candidate['sku'],
                    'erp_code' => $candidate['erp_code'],
                    'magento_id' => $entityId,
                    'price' => $candidate['price'],
                ]);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->warning(
                    '[ProductSync::reconcileReactivation] Excecao ignorada: ' . $e->getMessage()
                );
            } catch (\Exception $e) {
                $this->logger->warning('[ERP] Failed to reactivate in-scope product', [
                    'sku' => $candidate['sku'],
                    'magento_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $result['reactivated'] = $reactivated;
        $result['reactivatable'] = count($liberados);
        if ($reactivated > 0) {
            $this->logger->info('[ERP] In-scope Magento products were enabled', [
                'reactivated' => $reactivated,
                'candidates' => $result['candidates'],
                'blocked_by_price' => $result['blocked_by_price'],
                'blocked_not_catalog' => $result['blocked_not_catalog'],
            ]);
        }

        return $result;
    }

    /**
     * Espelho de findOutOfScopeEnabledProducts: produtos INATIVOS cujo SKU (ou
     * erp_code mapeado) esta no escopo ATIVO do Sectra.
     *
     * @param array{all: array<string, true>, active: array<string, true>} $scope
     * @return list<array{entity_id:int, sku:string, erp_code:?string, price:?float}>
     */
    private function findInScopeDisabledProducts(array $scope): array
    {
        $connection = $this->syncLogResource->getConnection();
        $statusAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'status')
                ->where('entity_type_id = ?', 4)
        );

        if ($statusAttrId <= 0) {
            $this->logger->error('[ERP] Product status attribute not found; skipping reactivation');
            return [];
        }

        $priceAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'price')
                ->where('entity_type_id = ?', 4)
        );

        $select = $connection->select()
            ->from(['cpe' => $connection->getTableName('catalog_product_entity')], ['entity_id', 'sku'])
            ->joinInner(
                ['st' => $connection->getTableName('catalog_product_entity_int')],
                'st.entity_id = cpe.entity_id AND st.attribute_id = ' . $statusAttrId
                . ' AND st.store_id = 0',
                []
            )
            ->joinLeft(
                ['map' => $connection->getTableName('grupoawamotos_erp_entity_map')],
                "map.magento_entity_id = cpe.entity_id AND map.entity_type = 'product'",
                ['erp_code']
            )
            ->where('st.value = ?', Status::STATUS_DISABLED);

        if ($priceAttrId > 0) {
            /* A coluna de valor em EAV e `value`, nao o nome do atributo. Nomear o
             * alias de 'price' gerou "Unknown column 'pr.price'" no primeiro dry-run. */
            $select->joinLeft(
                ['pr' => $connection->getTableName('catalog_product_entity_decimal')],
                'pr.entity_id = cpe.entity_id AND pr.attribute_id = ' . $priceAttrId
                . ' AND pr.store_id = 0',
                ['price' => 'value']
            );
        }

        $rows = $connection->fetchAll($select);
        $candidates = [];

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $erpCode = trim((string) ($row['erp_code'] ?? ''));
            $keys = [];
            if ($sku !== '') {
                $keys[] = $sku;
            }
            if ($erpCode !== '' && $erpCode !== $sku) {
                $keys[] = $erpCode;
            }

            $isActive = false;
            foreach ($keys as $key) {
                if (isset($scope['active'][$key])) {
                    $isActive = true;
                    break;
                }
            }
            if (!$isActive) {
                continue;
            }

            $candidates[] = [
                'entity_id' => (int) $row['entity_id'],
                'sku' => $sku,
                'erp_code' => $erpCode !== '' ? $erpCode : null,
                'price' => isset($row['price']) && $row['price'] !== null ? (float) $row['price'] : null,
            ];
        }

        return $candidates;
    }

    /**
     * Espelho de persistGlobalDisabledStatus. Usa insertOnDuplicate pelo mesmo
     * motivo corrigido em 06/10/2026: `update()` devolve 0 tambem quando o valor
     * JA E o desejado, e tratar isso como "linha nao existe" estoura 1062.
     */
    private function persistGlobalEnabledStatus(int $entityId): void
    {
        $connection = $this->syncLogResource->getConnection();
        $statusAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'status')
                ->where('entity_type_id = ?', 4)
        );
        if ($statusAttrId <= 0) {
            return;
        }

        $intTable = $connection->getTableName('catalog_product_entity_int');
        $storeIds = [0];
        foreach ($connection->fetchCol($connection->select()->from($connection->getTableName('store'), ['store_id'])) as $sid) {
            $storeIds[] = (int) $sid;
        }
        foreach (array_unique($storeIds) as $storeId) {
            $connection->insertOnDuplicate(
                $intTable,
                [
                    'attribute_id' => $statusAttrId,
                    'store_id' => $storeId,
                    'entity_id' => $entityId,
                    'value' => Status::STATUS_ENABLED,
                ],
                ['value']
            );
        }
    }

    /**
     * @return array{all: array<string, true>, active: array<string, true>}
     */
    private function getErpMaterialScope(): array
    {
        $scope = $this->helper->getProductScope();

        if ($scope === 'uniao' || $scope === 'com_preco') {
            // nestes modos o preco faz parte do escopo, entao a query precisa dele
            $rows = $this->connection->query(
                'SELECT m.CODIGO, m.CCKATIVO, m.CKCOMERCIALIZA, p.VLRVDSUG
                 FROM MT_MATERIAL m
                 LEFT JOIN MT_MATERIALLISTA p
                     ON p.MATERIAL = m.CODIGO
                     AND p.FILIAL = :filial
                     AND p.FATORPRECO = :priceList',
                [
                    ':filial' => $this->helper->getStockFilial(),
                    ':priceList' => $this->helper->getDefaultPriceList(),
                ]
            );
        } else {
            $rows = $this->connection->query(
                'SELECT m.CODIGO, m.CCKATIVO, m.CKCOMERCIALIZA FROM MT_MATERIAL m'
            );
        }

        $all = [];
        $active = [];

        foreach ($rows as $row) {
            $sku = trim((string) ($row['CODIGO'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $all[$sku] = true;
            $isActive = (($row['CCKATIVO'] ?? 'N') === 'S');
            if ($scope === 'comercializa') {
                $isActive = $isActive && (($row['CKCOMERCIALIZA'] ?? 'N') === 'S');
            } elseif ($scope === 'uniao') {
                $isActive = $isActive
                    && ((($row['CKCOMERCIALIZA'] ?? 'N') === 'S') || ((float) ($row['VLRVDSUG'] ?? 0) > 0));
            } elseif ($scope === 'com_preco') {
                $isActive = $isActive && ((float) ($row['VLRVDSUG'] ?? 0) > 0);
            }
            if ($isActive) {
                $active[$sku] = true;
            }
        }

        return ['all' => $all, 'active' => $active];
    }

    /**
     * @param array{all: array<string, true>, active: array<string, true>} $scope
     * @return list<array{entity_id:int, sku:string, erp_code:?string}>
     */
    private function findOutOfScopeEnabledProducts(array $scope): array
    {
        $connection = $this->syncLogResource->getConnection();
        $statusAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'status')
                ->where('entity_type_id = ?', 4)
        );

        if ($statusAttrId <= 0) {
            $this->logger->error('[ERP] Product status attribute not found; skipping deactivation');
            return [];
        }

        $productTable = $connection->getTableName('catalog_product_entity');
        $intTable = $connection->getTableName('catalog_product_entity_int');
        $mapTable = $connection->getTableName('grupoawamotos_erp_entity_map');

        $select = $connection->select()
            ->from(['cpe' => $productTable], ['entity_id', 'sku'])
            ->joinInner(
                ['st' => $intTable],
                'st.entity_id = cpe.entity_id AND st.attribute_id = ' . $statusAttrId
                . ' AND st.store_id = 0',
                []
            )
            ->joinLeft(
                ['map' => $mapTable],
                "map.magento_entity_id = cpe.entity_id AND map.entity_type = 'product'",
                ['erp_code']
            )
            ->where('st.value = ?', Status::STATUS_ENABLED);

        $rows = $connection->fetchAll($select);
        $candidates = [];

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $erpCode = trim((string) ($row['erp_code'] ?? ''));
            $keys = [];
            if ($sku !== '') {
                $keys[] = $sku;
            }
            if ($erpCode !== '' && $erpCode !== $sku) {
                $keys[] = $erpCode;
            }

            $existsInErp = false;
            $isActive = false;
            foreach ($keys as $key) {
                if (isset($scope['all'][$key])) {
                    $existsInErp = true;
                }
                if (isset($scope['active'][$key])) {
                    $isActive = true;
                }
            }

            if ($existsInErp && !$isActive) {
                $candidates[] = [
                    'entity_id' => (int) $row['entity_id'],
                    'sku' => $sku,
                    'erp_code' => $erpCode !== '' ? $erpCode : null,
                ];
            }
        }

        return $candidates;
    }

    /**
     * O portao esta ativo (ligado, fora do dry-run) e o Sectra nao tem preco utilizavel?
     */
    private function isPublicationGateBlockingPrice(
        array $erpProduct,
        ?\GrupoAwamotos\ERPIntegration\Model\Validator\ValidationResult $validationResult
    ): bool {
        if (!$this->helper->isPublicationGateEnabled() || $this->helper->isPublicationGateDryRun()) {
            return false;
        }

        $price = $validationResult
            ? $validationResult->getField('price', (float) ($erpProduct['VLRVENDA'] ?? 0))
            : (float) ($erpProduct['VLRVENDA'] ?? 0);

        return CatalogPublicationGuard::isPlaceholderPrice((float) $price);
    }

    /**
     * Portao de publicacao: o lado da LOJA. Os outros reconcilers partem do que o Sectra
     * devolve; este parte do que esta HABILITADO e pergunta se pode ficar.
     *
     * Desabilita (nunca apaga) produto simples/virtual habilitado que:
     *   - nao existe no Sectra por nenhuma chave (SKU, erp_code, SKU base); ou
     *   - esta sem preco ou com preco de placeholder (<= R$ 0,01).
     * Preco entre R$ 0,01 e R$ 1,00 NAO e desabilitado: so entra em low_price_skus para
     * revisao (ha itens legitimamente baratos).
     *
     * Protecoes, na mesma linha das guardas existentes:
     *   - Sectra vazio / sem ativos => nao faz nada (falha de conectividade, nao catalogo vazio);
     *   - limite de seguranca: candidatos > maxRatio do catalogo habilitado => nada e alterado;
     *   - tipos que nao vem do Sectra (configuravel, bundle...) sao ignorados e contados.
     *
     * @return array<string, mixed>
     */
    public function reconcilePublicationGate(bool $dryRun = false, ?float $maxRatio = null): array
    {
        $maxRatio ??= $this->helper->getPublicationGateMaxRatio();

        $result = [
            'skipped' => true,
            'skip_reason' => null,
            'all_erp' => 0,
            'active_erp' => 0,
            'enabled_total' => 0,
            'other_types_ignored' => 0,
            'missing_from_erp' => 0,
            'placeholder_price' => 0,
            'low_price_review' => 0,
            'candidates' => 0,
            'over_limit' => false,
            'max_ratio' => $maxRatio,
            'disabled' => 0,
            'missing_skus' => [],
            'placeholder_skus' => [],
            'low_price_skus' => [],
        ];

        try {
            $scope = $this->getErpMaterialScope();
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Unable to load Sectra material scope for publication gate', [
                'error' => $e->getMessage(),
            ]);
            $result['skip_reason'] = 'erp_scope_query_failed';
            return $result;
        }

        $result['all_erp'] = count($scope['all']);
        $result['active_erp'] = count($scope['active']);

        if (!InactiveProductDeactivationGuard::shouldDeactivate($result['all_erp'], $result['active_erp'])) {
            $result['skip_reason'] = $result['all_erp'] === 0
                ? 'erp_returned_zero_materials'
                : 'erp_returned_zero_active_materials';
            $this->logger->warning('[ERP] Skipping publication gate', [
                'reason' => $result['skip_reason'],
                'all_erp' => $result['all_erp'],
                'active_erp' => $result['active_erp'],
            ]);
            return $result;
        }

        $erpBase = [];
        foreach (array_keys($scope['all']) as $code) {
            $erpBase[$this->getBaseSku((string) $code)] = true;
        }

        $enabled = $this->findEnabledProductsWithPrice();
        $result['skipped'] = false;
        $result['enabled_total'] = count($enabled['products']);
        $result['other_types_ignored'] = $enabled['other_types'];

        /** @var array<int, array{sku:string, reason:string, erp_code:?string}> $targets */
        $targets = [];
        foreach ($enabled['products'] as $product) {
            $sku = $product['sku'];
            $keys = [$sku, $this->getBaseSku($sku)];
            if ($product['erp_code'] !== null) {
                $keys[] = $product['erp_code'];
                $keys[] = $this->getBaseSku($product['erp_code']);
            }

            if (CatalogPublicationGuard::isMissingFromErp($keys, $scope['all'], $erpBase)) {
                $result['missing_from_erp']++;
                if (count($result['missing_skus']) < 50) {
                    $result['missing_skus'][] = $sku;
                }
                $targets[$product['entity_id']] = [
                    'sku' => $sku,
                    'reason' => CatalogPublicationGuard::REASON_MISSING_FROM_ERP,
                    'erp_code' => $product['erp_code'],
                ];
            } elseif (CatalogPublicationGuard::isPlaceholderPrice($product['price'])) {
                $result['placeholder_price']++;
                if (count($result['placeholder_skus']) < 50) {
                    $result['placeholder_skus'][] = $sku;
                }
                $targets[$product['entity_id']] = [
                    'sku' => $sku,
                    'reason' => CatalogPublicationGuard::REASON_PLACEHOLDER_PRICE,
                    'erp_code' => $product['erp_code'],
                ];
            } elseif (CatalogPublicationGuard::isLowPriceForReview($product['price'])) {
                $result['low_price_review']++;
                if (count($result['low_price_skus']) < 50) {
                    $result['low_price_skus'][] = $sku;
                }
            }
        }

        $result['candidates'] = count($targets);
        $result['over_limit'] = !CatalogPublicationGuard::withinSafetyLimit(
            $result['candidates'],
            $result['enabled_total'],
            $maxRatio
        );

        $this->logger->info('[ERP] Publication gate evaluated', [
            'dry_run' => $dryRun,
            'enabled_total' => $result['enabled_total'],
            'candidates' => $result['candidates'],
            'missing_from_erp' => $result['missing_from_erp'],
            'placeholder_price' => $result['placeholder_price'],
            'low_price_review' => $result['low_price_review'],
            'over_limit' => $result['over_limit'],
            'sample_missing' => array_slice($result['missing_skus'], 0, 20),
            'sample_placeholder' => array_slice($result['placeholder_skus'], 0, 20),
        ]);

        if ($targets === [] || $dryRun) {
            return $result;
        }

        if ($result['over_limit']) {
            $result['skip_reason'] = 'safety_limit_exceeded';
            $this->logger->error('[ERP] Publication gate aborted: candidate volume above safety limit', [
                'candidates' => $result['candidates'],
                'enabled_total' => $result['enabled_total'],
                'max_ratio' => $maxRatio,
                'action' => 'revisar candidatos com --dry-run antes de ampliar o limite',
            ]);
            return $result;
        }

        $disabled = 0;
        foreach ($targets as $entityId => $target) {
            try {
                $product = $this->productRepository->getById($entityId, true, 0, true);
                $product->setStoreId(0);
                $product->setStatus(Status::STATUS_DISABLED);
                $this->productRepository->save($product);
                $this->persistGlobalDisabledStatus($entityId);
                $disabled++;

                $this->logger->info('[ERP] Product disabled by publication gate', [
                    'sku' => $target['sku'],
                    'erp_code' => $target['erp_code'],
                    'magento_id' => $entityId,
                    'reason' => $target['reason'],
                ]);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->warning(
                    '[ProductSync::reconcilePublicationGate] Excecao ignorada: ' . $e->getMessage()
                );
            } catch (\Exception $e) {
                $this->logger->warning('[ERP] Failed to disable product in publication gate', [
                    'sku' => $target['sku'],
                    'magento_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $result['disabled'] = $disabled;

        return $result;
    }

    /**
     * Produtos simples/virtuais HABILITADOS (status global) com preco e codigo ERP mapeado.
     * Outros tipos nao nascem do Sectra e ficam fora do portao (so contados).
     *
     * @return array{products: list<array{entity_id:int, sku:string, erp_code:?string, price:?float}>, other_types:int}
     */
    private function findEnabledProductsWithPrice(): array
    {
        $empty = ['products' => [], 'other_types' => 0];
        $connection = $this->syncLogResource->getConnection();

        $statusAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'status')
                ->where('entity_type_id = ?', 4)
        );
        $priceAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'price')
                ->where('entity_type_id = ?', 4)
        );

        if ($statusAttrId <= 0 || $priceAttrId <= 0) {
            $this->logger->error('[ERP] Status/price attribute not found; skipping publication gate');
            return $empty;
        }

        $select = $connection->select()
            ->from(['cpe' => $connection->getTableName('catalog_product_entity')], ['entity_id', 'sku', 'type_id'])
            ->joinInner(
                ['st' => $connection->getTableName('catalog_product_entity_int')],
                'st.entity_id = cpe.entity_id AND st.attribute_id = ' . $statusAttrId . ' AND st.store_id = 0',
                []
            )
            ->joinLeft(
                ['map' => $connection->getTableName('grupoawamotos_erp_entity_map')],
                "map.magento_entity_id = cpe.entity_id AND map.entity_type = 'product'",
                ['erp_code']
            )
            ->joinLeft(
                ['pr' => $connection->getTableName('catalog_product_entity_decimal')],
                'pr.entity_id = cpe.entity_id AND pr.attribute_id = ' . $priceAttrId . ' AND pr.store_id = 0',
                ['price' => 'value']
            )
            ->where('st.value = ?', Status::STATUS_ENABLED);

        $products = [];
        $otherTypes = 0;
        foreach ($connection->fetchAll($select) as $row) {
            $entityId = (int) $row['entity_id'];
            if (isset($products[$entityId])) {
                // entity_map duplicado: mantem a primeira linha, completa o erp_code se faltava
                if ($products[$entityId]['erp_code'] === null && trim((string) ($row['erp_code'] ?? '')) !== '') {
                    $products[$entityId]['erp_code'] = trim((string) $row['erp_code']);
                }
                continue;
            }
            if (!in_array((string) $row['type_id'], ['simple', 'virtual'], true)) {
                $otherTypes++;
                continue;
            }

            $erpCode = trim((string) ($row['erp_code'] ?? ''));
            $products[$entityId] = [
                'entity_id' => $entityId,
                'sku' => trim((string) ($row['sku'] ?? '')),
                'erp_code' => $erpCode !== '' ? $erpCode : null,
                'price' => isset($row['price']) && $row['price'] !== null ? (float) $row['price'] : null,
            ];
        }

        return ['products' => array_values($products), 'other_types' => $otherTypes];
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
            } else {
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
            }

            $reconcile = $this->reconcileInactiveProducts();
            $result['deactivated'] = (int) $reconcile['deactivated'];
            if (!empty($reconcile['skip_reason'])) {
                $result['deactivation_skipped'] = $reconcile['skip_reason'];
            }

            $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);

            $this->syncLogResource->addLog(
                'product',
                'import',
                $result['errors'] > 0 ? 'partial' : 'success',
                sprintf(
                    '[Delta] Criados: %d, Atualizados: %d, Desativados: %d, Erros: %d, Ignorados: %d | Tempo: %dms | Desde: %s',
                    $result['created'],
                    $result['updated'],
                    $result['deactivated'],
                    $result['errors'],
                    $result['skipped'],
                    $result['execution_time'],
                    $lastSync
                ),
                null,
                null,
                $result['created'] + $result['updated'] + $result['deactivated']
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

    private function persistGlobalDisabledStatus(int $entityId): void
    {
        $connection = $this->syncLogResource->getConnection();
        $statusAttrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'status')
                ->where('entity_type_id = ?', 4)
        );
        if ($statusAttrId <= 0) {
            return;
        }

        $intTable = $connection->getTableName('catalog_product_entity_int');
        $updated = $connection->update(
            $intTable,
            ['value' => Status::STATUS_DISABLED],
            [
                'attribute_id = ?' => $statusAttrId,
                'store_id = ?' => 0,
                'entity_id = ?' => $entityId,
            ]
        );

        /* BUG-STATUS-DUP (corrigido 06/10/2026): `update()` devolve 0 tambem quando
         * a linha JA EXISTE e o valor JA E o desejado — e era o caso aqui, porque o
         * `productRepository->save()` imediatamente antes ja gravou status=2. O codigo
         * tratava isso como "linha nao existe", tentava INSERT e estourava
         * "1062 Duplicate entry". Consequencia: o produto ERA desativado, mas o catch
         * registrava "Failed to deactivate" e `$deactivated` nunca incrementava —
         * o comando imprimia "Desativados: 0" depois de desativar 432 produtos.
         * INSERT ... ON DUPLICATE KEY UPDATE e idempotente e resolve os dois casos. */
        $storeIds = [0];
        foreach ($connection->fetchCol($connection->select()->from($connection->getTableName('store'), ['store_id'])) as $sid) {
            $storeIds[] = (int) $sid;
        }
        foreach (array_unique($storeIds) as $storeId) {
            $connection->insertOnDuplicate(
                $intTable,
                [
                    'attribute_id' => $statusAttrId,
                    'store_id' => $storeId,
                    'entity_id' => $entityId,
                    'value' => Status::STATUS_DISABLED,
                ],
                ['value']
            );
        }
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

        $scope = $this->helper->getProductScope();
        if ($scope === 'comercializa') {
            $sql .= " AND m.CKCOMERCIALIZA = 'S'";
        } elseif ($scope === 'uniao') {
            $sql .= " AND (m.CKCOMERCIALIZA = 'S' OR p.VLRVDSUG > 0)";
        } elseif ($scope === 'com_preco') {
            $sql .= " AND p.VLRVDSUG > 0";
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
