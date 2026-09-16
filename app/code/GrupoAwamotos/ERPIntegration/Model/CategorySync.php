<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\CategorySyncInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\State as AppState;
use Psr\Log\LoggerInterface;

class CategorySync implements CategorySyncInterface
{
    private ConnectionInterface $connection;
    private Helper $helper;
    private SyncLogResource $syncLogResource;
    private CategoryRepositoryInterface $categoryRepository;
    private CategoryInterfaceFactory $categoryFactory;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;
    private AppState $appState;

    public function __construct(
        ConnectionInterface $connection,
        Helper $helper,
        SyncLogResource $syncLogResource,
        CategoryRepositoryInterface $categoryRepository,
        CategoryInterfaceFactory $categoryFactory,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        AppState $appState
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->syncLogResource = $syncLogResource;
        $this->categoryRepository = $categoryRepository;
        $this->categoryFactory = $categoryFactory;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->appState = $appState;
    }

    public function syncAll(): array
    {
        $startTime = microtime(true);
        $result = [
            'created' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total_categories' => 0,
        ];

        // Set area code for CLI execution
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        // Area code already set by CLI bootstrap or a previous call in this
        // same request; safe to ignore.
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code already set
        }

        try {
            $erpCategories = $this->getErpCategories();
            $result['total_categories'] = count($erpCategories);

            if (empty($erpCategories)) {
                $this->logger->info('[ERP] No categories found in ERP');
                return $result;
            }

            $this->logger->info('[ERP] Starting category sync', [
                'total_categories' => $result['total_categories'],
            ]);

            // Get or create the ERP root category
            $rootCategoryId = $this->getOrCreateErpRootCategory();

            // Build nivel-to-Magento-ID map for hierarchy resolution
            $nivelToMagentoId = [];
            // Track which ERP codes we've seen for deactivation pass
            $processedErpCodes = [];

            // Single pass: ORDER BY NIVEL ensures parents come before children
            foreach ($erpCategories as $erpCategory) {
                $erpCode = (string) ($erpCategory['CODIGO'] ?? '');
                if (empty($erpCode)) {
                    continue;
                }

                $processedErpCodes[] = $erpCode;

                try {
                    $nivel = trim($erpCategory['NIVEL'] ?? '');
                    $nivelPai = trim($erpCategory['NIVELPAI'] ?? '');

                    // Resolve parent Magento category ID
                    $parentMagentoId = $rootCategoryId;
                    if (!empty($nivelPai) && isset($nivelToMagentoId[$nivelPai])) {
                        $parentMagentoId = $nivelToMagentoId[$nivelPai];
                    }

                    $syncResult = $this->syncSingleCategory($erpCategory, $parentMagentoId);

                    // Store the Magento ID in our map for child resolution
                    $magentoCategoryId = $this->syncLogResource->getEntityMap('category', $erpCode);
                    if ($magentoCategoryId && !empty($nivel)) {
                        $nivelToMagentoId[$nivel] = $magentoCategoryId;
                    }

                    switch ($syncResult) {
                        case 'created':
                            $result['created']++;
                            break;
                        case 'updated':
                            $result['updated']++;
                            break;
                        case 'skipped':
                            $result['skipped']++;
                            break;
                    }
                } catch (\Exception $e) {
                    $result['errors']++;
                    $this->logger->error('[ERP] Category sync error', [
                        'erp_code' => $erpCode,
                        'name' => $erpCategory['DESCRICAO'] ?? '?',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Deactivation pass: categories in entity_map but not in ERP response
            $result['deactivated'] = $this->deactivateMissingCategories($processedErpCodes);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->syncLogResource->addLog(
                'category',
                'import',
                $result['errors'] > 0 ? 'partial' : 'success',
                sprintf(
                    'Criadas: %d, Atualizadas: %d, Desativadas: %d, Ignoradas: %d, Erros: %d | Tempo: %dms',
                    $result['created'],
                    $result['updated'],
                    $result['deactivated'],
                    $result['skipped'],
                    $result['errors'],
                    $executionTime
                ),
                null,
                null,
                $result['created'] + $result['updated']
            );

            $this->logger->info('[ERP] Category sync completed', $result);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Category sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->syncLogResource->addLog('category', 'import', 'error', $e->getMessage());
            $result['errors']++;
        }

        return $result;
    }

    public function getErpCategories(): array
    {
        $sql = "SELECT gc.CODIGO, gc.DESCRICAO, gc.NIVEL, gc.NIVELPAI
                FROM MT_GRUPOCOMERCIAL gc
                ORDER BY gc.NIVEL";

        return $this->connection->query($sql);
    }

    public function getErpCategoryCount(): int
    {
        $sql = "SELECT COUNT(*) AS total FROM MT_GRUPOCOMERCIAL";
        $row = $this->connection->fetchOne($sql);
        return $row ? (int) ($row['total'] ?? $row['TOTAL'] ?? 0) : 0;
    }

    public function getOrCreateErpRootCategory(): int
    {
        // Check if we already have the root mapped
        $existingId = $this->syncLogResource->getEntityMap('category_root', 'ROOT');
        if ($existingId) {
            // Verify it still exists in Magento
            try {
                $this->categoryRepository->get($existingId);
                return $existingId;
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->warning('[CategorySync::getOrCreateErpRootCategory] Exceção ignorada: ' . $e->getMessage());
                // Root was deleted, recreate below
            }
        }

        $rootName = $this->helper->getCategoryRootName();
        $includeInMenu = $this->helper->getCategoryIncludeInMenu();

        $category = $this->categoryFactory->create();
        $category->setName($rootName);
        $category->setParentId(1); // Under Magento's root-of-roots (NOT "Default Category" which is 2)
        $category->setIsActive(true);
        $category->setIncludeInMenu($includeInMenu);
        $category->setPosition(99);
        $category->setCustomAttribute('available_sort_by', 'position,name,price');
        $category->setCustomAttribute('default_sort_by', 'position');

        $saved = $this->categoryRepository->save($category);
        $newId = (int) $saved->getId();

        $this->syncLogResource->setEntityMap('category_root', 'ROOT', $newId);

        $this->logger->info('[ERP] Created ERP root category', [
            'id' => $newId,
            'name' => $rootName,
        ]);

        return $newId;
    }

    /**
     * Sync a single category from ERP data
     *
     * @return string 'created', 'updated', or 'skipped'
     */
    private function syncSingleCategory(array $erpCategory, int $parentMagentoId): string
    {
        $erpCode = (string) $erpCategory['CODIGO'];
        $name = trim($erpCategory['DESCRICAO'] ?? '');

        if (empty($name)) {
            $name = 'Categoria ' . $erpCode;
        }

        // Truncate to Magento's 255 char limit
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }

        // Hash-based change detection
        $dataHash = hash('xxh128', json_encode($erpCategory));
        $existingHash = $this->syncLogResource->getEntityMapHash('category', $erpCode);

        if ($existingHash === $dataHash) {
            return 'skipped';
        }

        $existingMagentoId = $this->syncLogResource->getEntityMap('category', $erpCode);

        if ($existingMagentoId) {
            // UPDATE existing category
            try {
                $category = $this->categoryRepository->get($existingMagentoId);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                // Category was deleted externally, treat as new
                return $this->createCategory($erpCode, $name, $parentMagentoId, $dataHash);
            }

            $changed = false;

            if ($category->getName() !== $name) {
                $category->setName($name);
                $changed = true;
            }

            if (!$category->getIsActive()) {
                $category->setIsActive(true);
                $changed = true;
            }

            // Handle reparenting
            if ((int) $category->getParentId() !== $parentMagentoId) {
                try {
                    $category->move($parentMagentoId, null);
                    $changed = true;
                    $this->logger->info('[ERP] Category reparented', [
                        'erp_code' => $erpCode,
                        'old_parent' => $category->getParentId(),
                        'new_parent' => $parentMagentoId,
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('[ERP] Category reparenting failed', [
                        'erp_code' => $erpCode,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($changed) {
                $this->categoryRepository->save($category);
            }

            $this->syncLogResource->setEntityMap('category', $erpCode, $existingMagentoId, $dataHash);
            return $changed ? 'updated' : 'skipped';
        }

        // CREATE new category
        return $this->createCategory($erpCode, $name, $parentMagentoId, $dataHash);
    }

    private function createCategory(string $erpCode, string $name, int $parentMagentoId, string $dataHash): string
    {
        $includeInMenu = $this->helper->getCategoryIncludeInMenu();
        $urlKey = $this->generateCategoryUrlKey($name, $erpCode);

        $category = $this->categoryFactory->create();
        $category->setName($name);
        $category->setParentId($parentMagentoId);
        $category->setIsActive(true);
        $category->setIncludeInMenu($includeInMenu);
        $category->setUrlKey($urlKey);
        $category->setCustomAttribute('available_sort_by', 'position,name,price');
        $category->setCustomAttribute('default_sort_by', 'position');

        $saved = $this->categoryRepository->save($category);
        $newId = (int) $saved->getId();

        $this->syncLogResource->setEntityMap('category', $erpCode, $newId, $dataHash);

        $this->logger->info('[ERP] Category created', [
            'erp_code' => $erpCode,
            'magento_id' => $newId,
            'name' => $name,
            'parent_id' => $parentMagentoId,
        ]);

        return 'created';
    }

    /**
     * Deactivate Magento categories that no longer exist in ERP
     */
    private function deactivateMissingCategories(array $activeErpCodes): int
    {
        $deactivated = 0;

        // Get all category mappings from entity_map
        $connection = $this->syncLogResource->getConnection();
        $select = $connection->select()
            ->from('grupoawamotos_erp_entity_map', ['erp_code', 'magento_entity_id'])
            ->where('entity_type = ?', 'category');

        $allMapped = $connection->fetchPairs($select);

        foreach ($allMapped as $erpCode => $magentoId) {
            if (in_array((string) $erpCode, $activeErpCodes, true)) {
                continue;
            }

            try {
                $category = $this->categoryRepository->get((int) $magentoId);
                if ($category->getIsActive()) {
                    $category->setIsActive(false);
                    $this->categoryRepository->save($category);
                    $deactivated++;

                    $this->logger->info('[ERP] Category deactivated (removed from ERP)', [
                        'erp_code' => $erpCode,
                        'magento_id' => $magentoId,
                        'name' => $category->getName(),
                    ]);
                }
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->warning('[CategorySync::deactivateMissingCategories] Exceção ignorada: ' . $e->getMessage());
                // Already gone
            } catch (\Exception $e) {
                $this->logger->warning('[ERP] Failed to deactivate category', [
                    'erp_code' => $erpCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $deactivated;
    }

    /**
     * Generate unique URL key for category
     */
    private function generateCategoryUrlKey(string $name, string $erpCode): string
    {
        $urlKey = $this->transliterate($name);
        $urlKey = strtolower($urlKey);
        $urlKey = preg_replace('/[^a-z0-9]+/', '-', $urlKey);
        $urlKey = trim($urlKey, '-');

        // Append ERP code to ensure uniqueness
        $codeSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $erpCode));
        $codeSlug = trim($codeSlug, '-');
        $urlKey = $urlKey . '-erp-' . $codeSlug;

        if (strlen($urlKey) > 200) {
            $urlKey = substr($urlKey, 0, 200);
            $urlKey = rtrim($urlKey, '-');
        }

        return $urlKey;
    }

    /**
     * Transliterate accented characters to ASCII
     * Same pattern as ProductSync
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
}
