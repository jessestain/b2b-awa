<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ImageSyncInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Gallery\Processor as GalleryProcessor;
use Magento\Catalog\Model\Product\Gallery\EntryFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Io\File as IoFile;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

class ImageSync implements ImageSyncInterface
{
    private const BATCH_SIZE = 100;
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const IMAGE_ROLES = ['image', 'small_image', 'thumbnail'];

    private ConnectionInterface $connection;
    private Helper $helper;
    private ProductRepositoryInterface $productRepository;
    private GalleryProcessor $galleryProcessor;
    private ProductAttributeMediaGalleryManagementInterface $mediaGalleryManagement;
    private ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory;
    private ImageContentInterfaceFactory $imageContentFactory;
    private Filesystem $filesystem;
    private IoFile $ioFile;
    private SyncLogResource $syncLogResource;
    private LoggerInterface $logger;

    private ?string $mediaPath = null;
    private ?string $tmpPath = null;
    private bool $isBatchSyncRunning = false;
    private array $missingMagentoCandidates = [];
    private array $resolvedMagentoCandidates = [];

    public function __construct(
        ConnectionInterface $connection,
        Helper $helper,
        ProductRepositoryInterface $productRepository,
        GalleryProcessor $galleryProcessor,
        ProductAttributeMediaGalleryManagementInterface $mediaGalleryManagement,
        Filesystem $filesystem,
        IoFile $ioFile,
        SyncLogResource $syncLogResource,
        LoggerInterface $logger,
        ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory,
        ImageContentInterfaceFactory $imageContentFactory
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        $this->productRepository = $productRepository;
        $this->galleryProcessor = $galleryProcessor;
        $this->mediaGalleryManagement = $mediaGalleryManagement;
        $this->filesystem = $filesystem;
        $this->ioFile = $ioFile;
        $this->syncLogResource = $syncLogResource;
        $this->logger = $logger;
        $this->galleryEntryFactory = $galleryEntryFactory;
        $this->imageContentFactory = $imageContentFactory;
    }

    public function syncAll(bool $force = false): array
    {
        $result = [
            'synced' => 0,
            'errors' => 0,
            'skipped' => 0,
            'total' => 0,
            'execution_time' => 0,
        ];

        if (!$force && !$this->helper->isImageSyncEnabled()) {
            $this->logger->info('[ERP] Image sync is disabled');
            return $result;
        }

        $startTime = microtime(true);
        $this->startBatchDiagnostics();

        try {
            $products = $this->getProductsWithImages();
            $result['total'] = count($products);

            $this->logger->info(sprintf('[ERP] Starting image sync for %d products', $result['total']));

            // Process in batches for memory management and progress tracking
            $batches = array_chunk($products, self::BATCH_SIZE);
            $batchCount = \count($batches);

            foreach ($batches as $batchIndex => $batch) {
                $this->logger->info(sprintf(
                    '[ERP] Image sync batch %d/%d (%d products)',
                    $batchIndex + 1,
                    $batchCount,
                    \count($batch)
                ));

                foreach ($batch as $productData) {
                    try {
                        $sku = $productData['CODIGO'];
                        $synced = $this->syncBySku($sku);

                        if ($synced) {
                            $result['synced']++;
                        } else {
                            $result['skipped']++;
                        }
                    } catch (\Exception $e) {
                        $result['errors']++;
                        $this->logger->error(sprintf(
                            '[ERP] Image sync error for SKU %s: %s',
                            $productData['CODIGO'] ?? 'unknown',
                            $e->getMessage()
                        ));
                    }
                }

                // Free memory between batches
                gc_collect_cycles();
            }

            $result['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);

            $this->syncLogResource->addLog(
                'image',
                'import',
                $result['errors'] > 0 ? 'partial' : 'success',
                sprintf(
                    'Sincronizadas: %d, Erros: %d, Ignoradas: %d | Tempo: %dms',
                    $result['synced'],
                    $result['errors'],
                    $result['skipped'],
                    $result['execution_time']
                )
            );

            $this->logger->info('[ERP] Image sync completed', $result);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Image sync failed: ' . $e->getMessage());
            $this->syncLogResource->addLog('image', 'import', 'error', $e->getMessage());
        } finally {
            $this->flushBatchDiagnostics();
            $this->resetBatchDiagnostics();
        }

        return $result;
    }

    public function syncBySku(string $sku): bool
    {
        $requestedSku = trim($sku);

        try {
            ['product' => $product, 'sku' => $productSku] = $this->loadProductForImageSync($requestedSku);
        } catch (NoSuchEntityException $e) {
            $this->recordMissingMagentoCandidate($requestedSku);

            if (!$this->isBatchSyncRunning) {
                $this->logger->debug('[ERP] Product not found in Magento for image sync: ' . $requestedSku);
            }

            return false;
        }

        $erpImages = $this->getErpImagesForSync($requestedSku, $productSku);

        if (empty($erpImages)) {
            return false; // No images in source
        }

        // Phase 1: Download all ERP images to tmp and compute hashes
        // This determines which images need updating BEFORE touching the product
        $pendingImages = [];
        foreach ($erpImages as $index => $imageData) {
            try {
                $imagePath = $this->resolveImagePath($imageData);
                if (!$imagePath || !$this->isValidImage($imagePath)) {
                    continue;
                }

                $tmpFile = $this->downloadImageToTmp($imagePath, $productSku, $index);
                if (!$tmpFile) {
                    continue;
                }

                $imageHash = md5_file($tmpFile);
                $storedHash = $this->getStoredImageHash($productSku, $index);

                if ($storedHash === $imageHash) {
                    // Image hasn't changed — skip
                    $this->ioFile->rm($tmpFile);
                    continue;
                }

                // In non-replace mode, also check if image already exists on product
                if (!$this->helper->shouldReplaceImages()) {
                    if ($this->imageExistsOnProduct($product, $imageHash)) {
                        $this->ioFile->rm($tmpFile);
                        continue;
                    }
                }

                $isMain = $imageData['is_main'] ?? ($index === 0);
                $pendingImages[] = [
                    'index' => $index,
                    'tmpFile' => $tmpFile,
                    'hash' => $imageHash,
                    'label' => $imageData['label'] ?? '',
                    'roles' => $isMain ? self::IMAGE_ROLES : [],
                ];
            } catch (\Exception $e) {
                $this->logger->warning(sprintf(
                    '[ERP] Failed to prepare image %d for SKU %s: %s',
                    $index,
                    $productSku,
                    $e->getMessage()
                ));
            }
        }

        // No changes detected — exit early without touching the product
        if (empty($pendingImages)) {
            return false;
        }

        // Phase 2: Remove existing images if replace mode is active
        // Only done AFTER confirming we have new images to add
        if ($this->helper->shouldReplaceImages()) {
            $this->removeAllProductImages($product, $productSku);
            $this->productRepository->save($product);
        }

        // Phase 3: Add pending images via MediaGalleryManagement API
        $imagesAdded = 0;
        foreach ($pendingImages as $pending) {
            try {
                $this->addImageToProduct(
                    $product,
                    $pending['tmpFile'],
                    $pending['label'],
                    $pending['roles'],
                    $pending['index']
                );

                $this->saveImageHash($productSku, $pending['index'], $pending['hash'], null);
                $imagesAdded++;
            } catch (\Exception $e) {
                $this->logger->warning(sprintf(
                    '[ERP] Failed to sync image %d for SKU %s: %s',
                    $pending['index'],
                    $productSku,
                    $e->getMessage()
                ));
                // Clean up tmp file on failure
                @unlink($pending['tmpFile']);
            }
        }

        if ($imagesAdded > 0) {
            $this->logger->info(sprintf('[ERP] Added %d images to product %s', $imagesAdded, $productSku));
            return true;
        }

        return false;
    }

    public function getErpImages(string $sku): array
    {
        $images = [];
        $imageSource = $this->helper->getImageSource();

        switch ($imageSource) {
            case 'table':
                $images = $this->getImagesFromTable($sku);
                break;
            case 'folder':
                $images = $this->getImagesFromFolder($sku);
                break;
            case 'url':
                $images = $this->getImagesFromUrl($sku);
                break;
            default:
                // Try table first, then folder
                $images = $this->getImagesFromTable($sku);
                if (empty($images)) {
                    $images = $this->getImagesFromFolder($sku);
                }
        }

        return $images;
    }

    public function cleanOrphanImages(): array
    {
        $result = [
            'removed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'dry_run' => false,
            'products_checked' => 0,
            'orphans_found' => [],
        ];

        if (!$this->helper->isOrphanCleanupEnabled()) {
            $this->logger->info('[ERP] Orphan image cleanup is disabled');
            return $result;
        }

        $isDryRun = $this->helper->isOrphanCleanupDryRun();
        $keepManual = $this->helper->shouldKeepManualImages();
        $result['dry_run'] = $isDryRun;

        $this->logger->info(sprintf(
            '[ERP] Starting orphan image cleanup (dry_run=%s, keep_manual=%s)',
            $isDryRun ? 'yes' : 'no',
            $keepManual ? 'yes' : 'no'
        ));

        try {
            $productsWithImages = $this->getProductsWithMagentoImages();
            $result['products_checked'] = count($productsWithImages);

            foreach ($productsWithImages as $productData) {
                try {
                    $orphansRemoved = $this->cleanOrphansForProduct(
                        $productData['sku'],
                        (int) $productData['product_id'],
                        $isDryRun,
                        $keepManual,
                        $result['orphans_found']
                    );

                    $result['removed'] += $orphansRemoved;
                } catch (\Exception $e) {
                    $result['errors']++;
                    $this->logger->error(sprintf(
                        '[ERP] Error cleaning orphans for SKU %s: %s',
                        $productData['sku'],
                        $e->getMessage()
                    ));
                }
            }

            $status = $isDryRun ? 'dry_run' : ($result['errors'] > 0 ? 'partial' : 'success');
            $this->syncLogResource->addLog(
                'image_cleanup',
                'cleanup',
                $status,
                sprintf(
                    '%s | Produtos: %d, Orfas removidas: %d, Erros: %d',
                    $isDryRun ? '[SIMULACAO]' : '[EXECUTADO]',
                    $result['products_checked'],
                    $result['removed'],
                    $result['errors']
                )
            );

            $this->logger->info('[ERP] Orphan cleanup completed', $result);
        } catch (\Exception $e) {
            $this->logger->error('[ERP] Orphan cleanup failed: ' . $e->getMessage());
            $this->syncLogResource->addLog('image_cleanup', 'cleanup', 'error', $e->getMessage());
        }

        return $result;
    }

    /**
     * Get all products that have images in Magento
     */
    private function getProductsWithMagentoImages(): array
    {
        $connection = $this->syncLogResource->getConnection();
        $mediaGalleryTable = $connection->getTableName('catalog_product_entity_media_gallery_value_to_entity');
        $productTable = $connection->getTableName('catalog_product_entity');

        $select = $connection->select()
            ->from(['mg' => $mediaGalleryTable], [])
            ->join(
                ['p' => $productTable],
                'mg.entity_id = p.entity_id',
                ['product_id' => 'entity_id', 'sku']
            )
            ->group('p.entity_id');

        return $connection->fetchAll($select);
    }

    /**
     * Clean orphan images for a specific product
     */
    private function cleanOrphansForProduct(
        string $sku,
        int $productId,
        bool $isDryRun,
        bool $keepManual,
        array &$orphansLog
    ): int {
        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException $e) {
            return 0;
        }

        $mediaGallery = $product->getMediaGalleryEntries();
        if (!$mediaGallery || count($mediaGallery) === 0) {
            return 0;
        }

        // Get images that exist in ERP
        $erpImages = $this->getErpImages($sku);
        $erpImageHashes = $this->getErpImageHashes($erpImages);

        $removed = 0;
        $mediaPath = $this->getMediaPath();
        $entriesToRemove = [];

        foreach ($mediaGallery as $entry) {
            $imagePath = $mediaPath . $entry->getFile();

            // Skip if file doesn't exist
            if (!file_exists($imagePath)) {
                continue;
            }

            $imageHash = md5_file($imagePath);

            // Check if this image exists in ERP
            $existsInErp = in_array($imageHash, $erpImageHashes);

            if (!$existsInErp) {
                // Check if it's a manually uploaded image (heuristic: check label or metadata)
                if ($keepManual && $this->isManuallyUploadedImage($entry)) {
                    $this->logger->debug(sprintf(
                        '[ERP] Skipping manual image for SKU %s: %s',
                        $sku,
                        $entry->getFile()
                    ));
                    continue;
                }

                $orphansLog[] = [
                    'sku' => $sku,
                    'file' => $entry->getFile(),
                    'label' => $entry->getLabel(),
                ];

                if (!$isDryRun) {
                    $entriesToRemove[] = $entry->getId();
                }

                $removed++;
            }
        }

        // Remove entries if not dry run
        if (!$isDryRun && !empty($entriesToRemove)) {
            foreach ($entriesToRemove as $entryId) {
                try {
                    $this->mediaGalleryManagement->remove($sku, $entryId);
                } catch (\Exception $e) {
                    $this->logger->warning(sprintf(
                        '[ERP] Failed to remove image entry %d for SKU %s: %s',
                        $entryId,
                        $sku,
                        $e->getMessage()
                    ));
                }
            }

            $this->logger->info(sprintf(
                '[ERP] Removed %d orphan images from product %s',
                count($entriesToRemove),
                $sku
            ));
        }

        return $removed;
    }

    /**
     * Get MD5 hashes for ERP images for comparison
     */
    private function getErpImageHashes(array $erpImages): array
    {
        $hashes = [];

        foreach ($erpImages as $imageData) {
            $path = $this->resolveImagePath($imageData);
            if ($path && file_exists($path) && !filter_var($path, FILTER_VALIDATE_URL)) {
                $hashes[] = md5_file($path);
            } elseif ($path && filter_var($path, FILTER_VALIDATE_URL)) {
                // For URLs, download temporarily to get hash
                $tmpFile = $this->downloadImageToTmp($path, 'hash_check', 0);
                if ($tmpFile && file_exists($tmpFile)) {
                    $hashes[] = md5_file($tmpFile);
                    $this->ioFile->rm($tmpFile);
                }
            }
        }

        return $hashes;
    }

    /**
     * Check if image was manually uploaded (not from ERP sync)
     * Heuristic: images with custom labels or without ERP sync markers
     */
    private function isManuallyUploadedImage($entry): bool
    {
        $label = $entry->getLabel() ?? '';

        // If label contains manual upload indicators
        $manualIndicators = ['manual', 'admin', 'custom', 'uploaded'];
        foreach ($manualIndicators as $indicator) {
            if (stripos($label, $indicator) !== false) {
                return true;
            }
        }

        // If label is custom (not empty and not just the SKU)
        // This is a heuristic - manually uploaded images often have custom labels
        if (!empty($label) && strlen($label) > 20) {
            return true;
        }

        return false;
    }

    public function getPendingCount(): int
    {
        try {
            return \count($this->getProductsWithImages());
        } catch (\Exception $e) {
            return 0;
        }
    }

    // ==================== Private Methods ====================

    /**
     * Get products that have images in ERP.
     * Respects the configured source and, in auto mode, merges table and folder candidates.
     */
    private function getProductsWithImages(): array
    {
        $imageSource = $this->helper->getImageSource();

        if ($imageSource === 'table') {
            return $this->getProductsFromTables();
        }

        if ($imageSource === 'folder') {
            return $this->getProductsFromFolder();
        }

        if ($imageSource === 'url') {
            // URL mode still needs a candidate SKU list; keep using ERP-backed product lists.
            return $this->getProductsFromTables();
        }

        $tableProducts = $this->getProductsFromTables();
        $folderProducts = $this->getProductsFromFolder();
        $mergedProducts = $this->mergeProductRows($tableProducts, $folderProducts);

        if (!empty($folderProducts) && \count($mergedProducts) > \count($tableProducts)) {
            $this->logger->info(sprintf(
                '[ERP] Auto image sync merged %d table products with %d folder products (%d unique)',
                \count($tableProducts),
                \count($folderProducts),
                \count($mergedProducts)
            ));
        }

        if (empty($mergedProducts)) {
            $this->logger->debug('[ERP] No image candidates found in configured sources');
        }

        return $mergedProducts;
    }

    /**
     * Get products that have images in ERP tables.
     * Merges PR_MEDIDAIMAGEM and GR_DOCUMENTOS candidates instead of stopping at the first hit.
     */
    private function getProductsFromTables(): array
    {
        $products = [];

        try {
            $sql = "SELECT DISTINCT i.CODIGO
                    FROM PR_MEDIDAIMAGEM i
                    INNER JOIN MT_MATERIAL m ON m.CODIGO = i.CODIGO
                    WHERE i.IMAGEM IS NOT NULL
                      AND m.CCKATIVO = 'S'
                    ORDER BY i.CODIGO";
            $rows = $this->connection->query($sql);
            if (!empty($rows)) {
                $products = $this->mergeProductRows($products, $rows);
            }
        } catch (\Exception $e) {
            $this->logger->debug('[ERP] PR_MEDIDAIMAGEM not available: ' . $e->getMessage());
        }

        try {
            $sql = "SELECT DISTINCT d.CHAVE AS CODIGO
                    FROM GR_DOCUMENTOS d
                    INNER JOIN MT_MATERIAL m ON m.CODIGO = d.CHAVE
                    WHERE d.TPCHAVE = 'MA'
                      AND d.EXTENSAO IN ('jpg','jpeg','png','gif','webp','JPG','JPEG','PNG')
                      AND d.DOCUMENTO IS NOT NULL
                      AND m.CCKATIVO = 'S'
                    ORDER BY d.CHAVE";
            $rows = $this->connection->query($sql);
            if (!empty($rows)) {
                $this->logger->info(sprintf('[ERP] Found %d active products with images in GR_DOCUMENTOS', \count($rows)));
                $products = $this->mergeProductRows($products, $rows);
            }
        } catch (\Exception $e) {
            $this->logger->debug('[ERP] GR_DOCUMENTOS fallback failed: ' . $e->getMessage());
        }

        return $products;
    }

    /**
     * Merge product candidate rows by CODIGO, preserving the first occurrence order.
     */
    private function mergeProductRows(array ...$sources): array
    {
        $products = [];

        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                $sku = trim((string) ($row['CODIGO'] ?? ''));
                if ($sku === '' || isset($products[$sku])) {
                    continue;
                }

                $products[$sku] = ['CODIGO' => $sku];
            }
        }

        return array_values($products);
    }

    /**
     * Get images from ERP table.
     * Tries PR_MEDIDAIMAGEM first, then GR_DOCUMENTOS as fallback.
     * Supports both path/filename strings and binary BLOB data in IMAGEM column.
     */
    private function getImagesFromTable(string $sku): array
    {
        // 1. Try PR_MEDIDAIMAGEM (dedicated product image table)
        $images = $this->getImagesFromPrMedidaImagem($sku);
        if (!empty($images)) {
            return $images;
        }

        // 2. Fallback: GR_DOCUMENTOS (general document table, filtered to images)
        $images = $this->getImagesFromGrDocumentos($sku);
        if (!empty($images)) {
            return $images;
        }

        return [];
    }

    /**
     * Get images from PR_MEDIDAIMAGEM table (CODIGO = SKU, IMAGEM = image blob/path)
     */
    private function getImagesFromPrMedidaImagem(string $sku): array
    {
        try {
            $sql = "SELECT IMAGEM
                    FROM PR_MEDIDAIMAGEM
                    WHERE CODIGO = :sku
                      AND IMAGEM IS NOT NULL";

            $rows = $this->connection->query($sql, [':sku' => $sku]);
            if (empty($rows)) {
                return [];
            }

            return $this->parseImageRows($rows, $sku, 'IMAGEM', null, null, null);
        } catch (\Exception $e) {
            $this->logger->debug('[ERP] PR_MEDIDAIMAGEM query failed for SKU ' . $sku . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get images from GR_DOCUMENTOS table (TPCHAVE='MA', CHAVE=SKU, image extensions only)
     */
    private function getImagesFromGrDocumentos(string $sku): array
    {
        try {
            $sql = "SELECT d.DOCUMENTO AS IMAGEM, d.DESCRICAO, d.EXTENSAO
                    FROM GR_DOCUMENTOS d
                    WHERE d.TPCHAVE = 'MA'
                      AND d.CHAVE = :sku
                      AND d.EXTENSAO IN ('jpg','jpeg','png','gif','webp','JPG','JPEG','PNG')
                      AND d.DOCUMENTO IS NOT NULL
                    ORDER BY d.CODIGO ASC";

            $rows = $this->connection->query($sql, [':sku' => $sku]);
            if (empty($rows)) {
                return [];
            }

            return $this->parseImageRows($rows, $sku, 'IMAGEM', 'DESCRICAO', null, null);
        } catch (\Exception $e) {
            $this->logger->debug('[ERP] GR_DOCUMENTOS query failed for SKU ' . $sku . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Parse image rows from ERP query results.
     * Handles both binary BLOB data and path/filename strings in the image column.
     */
    private function parseImageRows(
        array $rows,
        string $sku,
        string $imageCol,
        ?string $labelCol,
        ?string $orderCol,
        ?string $mainCol
    ): array {
        $images = [];

        foreach ($rows as $row) {
            if (empty($row[$imageCol])) {
                continue;
            }

            $imagemValue = $row[$imageCol];
            $path = null;
            $isTmpBlob = false;

            // Detect if value is binary data (VARBINARY/IMAGE blob) or a path string
            if ($this->isBinaryData($imagemValue)) {
                $tmpFile = $this->saveBlobToTmp($imagemValue, $sku, \count($images));
                if ($tmpFile) {
                    $path = $tmpFile;
                    $isTmpBlob = true;
                }
            } else {
                $path = (string) $imagemValue;
            }

            if ($path) {
                $images[] = [
                    'path' => $path,
                    'label' => ($labelCol && isset($row[$labelCol])) ? $row[$labelCol] : '',
                    'position' => ($orderCol && isset($row[$orderCol])) ? (int) $row[$orderCol] : \count($images),
                    'is_main' => ($mainCol && isset($row[$mainCol])) ? ($row[$mainCol] === 'S') : (\count($images) === 0),
                    'is_tmp_blob' => $isTmpBlob,
                ];
            }
        }

        return $images;
    }

    /**
     * Get images from folder path.
     * Supports naming by SKU or by CODINTERNO (internal ERP code).
     */
    private function getImagesFromFolder(string $sku): array
    {
        $basePath = $this->helper->getImageBasePath();
        if (empty($basePath) || !is_dir($basePath)) {
            return [];
        }

        $identifiers = [$sku];

        $codInterno = $this->getCodInternoForSku($sku);
        if ($codInterno) {
            $identifiers[] = $codInterno;
        }

        foreach ($identifiers as $identifier) {
            $files = $this->findFolderFilesByIdentifier($basePath, $identifier);
            if (empty($files)) {
                continue;
            }

            $images = [];
            foreach ($files as $index => $file) {
                $images[] = [
                    'path' => $file,
                    'label' => '',
                    'position' => $index,
                    'is_main' => $index === 0,
                ];
            }

            return $images;
        }

        return [];
    }

    /**
     * Get CODINTERNO (internal ERP code) for a product SKU.
     * Cached per-request to avoid repeated ERP queries.
     */
    private array $codInternoCache = [];
    private array $skuByCodInternoCache = [];

    private function startBatchDiagnostics(): void
    {
        $this->isBatchSyncRunning = true;
        $this->missingMagentoCandidates = [];
        $this->resolvedMagentoCandidates = [];
    }

    private function resetBatchDiagnostics(): void
    {
        $this->isBatchSyncRunning = false;
        $this->missingMagentoCandidates = [];
        $this->resolvedMagentoCandidates = [];
    }

    private function flushBatchDiagnostics(): void
    {
        if (!empty($this->resolvedMagentoCandidates)) {
            $this->logger->info(sprintf(
                '[ERP] Resolved %d ERP image identifiers to Magento SKUs: %s',
                count($this->resolvedMagentoCandidates),
                $this->summarizeIdentifierPairs($this->resolvedMagentoCandidates)
            ));
        }

        if (!empty($this->missingMagentoCandidates)) {
            $this->logger->debug(sprintf(
                '[ERP] Skipping %d image candidates not yet in Magento catalog: %s',
                count($this->missingMagentoCandidates),
                $this->summarizeIdentifiers(array_keys($this->missingMagentoCandidates))
            ));
        }
    }

    private function recordMissingMagentoCandidate(string $identifier): void
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return;
        }

        $this->missingMagentoCandidates[$identifier] = true;
    }

    private function recordResolvedMagentoCandidate(string $requestedIdentifier, string $resolvedSku): void
    {
        $requestedIdentifier = trim($requestedIdentifier);
        $resolvedSku = trim($resolvedSku);

        if ($requestedIdentifier === '' || $resolvedSku === '' || $requestedIdentifier === $resolvedSku) {
            return;
        }

        $this->resolvedMagentoCandidates[$requestedIdentifier] = $resolvedSku;
    }

    private function summarizeIdentifiers(array $identifiers, int $limit = 10): string
    {
        $identifiers = array_values(array_unique(array_filter(array_map('trim', $identifiers))));
        if (empty($identifiers)) {
            return '[none]';
        }

        $visible = array_slice($identifiers, 0, $limit);
        $summary = implode(', ', $visible);
        $remaining = count($identifiers) - count($visible);

        if ($remaining > 0) {
            $summary .= sprintf(' (+%d more)', $remaining);
        }

        return $summary;
    }

    private function summarizeIdentifierPairs(array $pairs, int $limit = 10): string
    {
        $summary = [];
        foreach (array_slice($pairs, 0, $limit, true) as $requestedIdentifier => $resolvedSku) {
            $summary[] = $requestedIdentifier . '->' . $resolvedSku;
        }

        $remaining = count($pairs) - count($summary);
        if ($remaining > 0) {
            $summary[] = sprintf('+%d more', $remaining);
        }

        return implode(', ', $summary);
    }

    /**
     * Load the target Magento product for an ERP image identifier.
     *
     * @return array{product: ProductInterface, sku: string}
     * @throws NoSuchEntityException
     */
    private function loadProductForImageSync(string $identifier): array
    {
        try {
            return [
                'product' => $this->productRepository->get($identifier, false, 0),
                'sku' => $identifier,
            ];
        } catch (NoSuchEntityException $exception) {
            $resolvedSku = $this->getSkuByCodInterno($identifier);
            if ($resolvedSku !== null && $resolvedSku !== $identifier) {
                $product = $this->productRepository->get($resolvedSku, false, 0);
                $this->recordResolvedMagentoCandidate($identifier, $resolvedSku);

                return [
                    'product' => $product,
                    'sku' => $resolvedSku,
                ];
            }

            throw $exception;
        }
    }

    private function getErpImagesForSync(string $requestedIdentifier, string $productSku): array
    {
        foreach ($this->buildImageLookupIdentifiers($requestedIdentifier, $productSku) as $imageIdentifier) {
            $images = $this->getErpImages($imageIdentifier);
            if (!empty($images)) {
                return $images;
            }
        }

        return [];
    }

    private function buildImageLookupIdentifiers(string $requestedIdentifier, string $productSku): array
    {
        $identifiers = [];

        foreach ([$requestedIdentifier, $productSku, $this->getCodInternoForSku($productSku)] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || in_array($candidate, $identifiers, true)) {
                continue;
            }

            $identifiers[] = $candidate;
        }

        return $identifiers;
    }

    private function getCodInternoForSku(string $sku): ?string
    {
        if (array_key_exists($sku, $this->codInternoCache)) {
            return $this->codInternoCache[$sku];
        }

        try {
            $rows = $this->connection->query(
                "SELECT CODINTERNO FROM MT_MATERIAL WHERE CODIGO = :sku AND CODINTERNO IS NOT NULL AND CODINTERNO != 0",
                [':sku' => $sku]
            );
            $this->codInternoCache[$sku] = $this->getFirstRowStringValue($rows, 'CODINTERNO');
        } catch (\Exception $e) {
            $this->codInternoCache[$sku] = null;
        }

        return $this->codInternoCache[$sku];
    }

    private function getSkuByCodInterno(string $codInterno): ?string
    {
        if (array_key_exists($codInterno, $this->skuByCodInternoCache)) {
            return $this->skuByCodInternoCache[$codInterno];
        }

        try {
            $rows = $this->connection->query(
                "SELECT TOP 1 CODIGO
                 FROM MT_MATERIAL
                 WHERE CAST(CODINTERNO AS VARCHAR(50)) = :cod_interno",
                [':cod_interno' => $codInterno]
            );
            $this->skuByCodInternoCache[$codInterno] = $this->getFirstRowStringValue($rows, 'CODIGO');
        } catch (\Exception $e) {
            $this->skuByCodInternoCache[$codInterno] = null;
        }

        return $this->skuByCodInternoCache[$codInterno];
    }

    private function getFirstRowStringValue(array $rows, string $column): ?string
    {
        if (empty($rows) || !isset($rows[0]) || !array_key_exists($column, $rows[0])) {
            return null;
        }

        $value = trim((string) $rows[0][$column]);

        return $value !== '' ? $value : null;
    }

    /**
     * Find folder images that match an identifier exactly or with Magento gallery suffixes.
     *
     * Accepted formats:
     * - IDENTIFIER.jpg
     * - IDENTIFIER_1.jpg
     * - IDENTIFIER_2.png
     */
    private function findFolderFilesByIdentifier(string $basePath, string $identifier): array
    {
        $files = scandir($basePath);
        if ($files === false) {
            return [];
        }

        $quotedIdentifier = preg_quote($identifier, '/');
        $matches = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, self::SUPPORTED_EXTENSIONS, true)) {
                continue;
            }

            $filename = pathinfo($file, PATHINFO_FILENAME);
            if (strcasecmp($filename, $identifier) !== 0
                && !preg_match('/^' . $quotedIdentifier . '_\d+$/i', $filename)
            ) {
                continue;
            }

            $matches[] = $basePath . DIRECTORY_SEPARATOR . $file;
        }

        natsort($matches);

        return array_values($matches);
    }

    /**
     * Get images from URL pattern
     */
    private function getImagesFromUrl(string $sku): array
    {
        $baseUrl = $this->helper->getImageBaseUrl();
        if (empty($baseUrl)) {
            return [];
        }

        // Build URL with SKU
        $imageUrl = str_replace('{sku}', $sku, $baseUrl);

        // Check if URL is accessible
        $headers = @get_headers($imageUrl);
        if ($headers && strpos($headers[0], '200') !== false) {
            return [
                [
                    'path' => $imageUrl,
                    'label' => '',
                    'position' => 0,
                    'is_main' => true,
                ],
            ];
        }

        return [];
    }

    /**
     * Get products that have images in folder.
     * Handles filenames by SKU or by CODINTERNO — maps CODINTERNO back to SKU.
     */
    private function getProductsFromFolder(): array
    {
        $basePath = $this->helper->getImageBasePath();
        if (empty($basePath) || !is_dir($basePath)) {
            return [];
        }

        $products = [];
        $files = scandir($basePath);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, self::SUPPORTED_EXTENSIONS)) {
                // Preserve dots inside the SKU and only strip the gallery suffix convention (_1, _2, ...)
                $identifier = pathinfo($file, PATHINFO_FILENAME);
                $identifier = preg_replace('/_\d+$/', '', $identifier);
                if (!$identifier) {
                    continue;
                }

                // Resolve to SKU: could be a direct SKU or a CODINTERNO
                $sku = $this->getSkuByCodInterno($identifier) ?? $identifier;

                if (!isset($products[$sku])) {
                    $products[$sku] = ['CODIGO' => $sku];
                }
            }
        }

        return array_values($products);
    }

    /**
     * Resolve image path (could be local path, network path, or URL)
     */
    private function resolveImagePath(array $imageData): ?string
    {
        $path = $imageData['path'] ?? '';

        if (empty($path)) {
            return null;
        }

        // If it's a URL
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        // If it's an absolute path
        if (file_exists($path)) {
            return $path;
        }

        // Try with base path
        $basePath = $this->helper->getImageBasePath();
        if ($basePath) {
            $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

    /**
     * Validate image file
     */
    private function isValidImage(string $path): bool
    {
        // Check extension
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::SUPPORTED_EXTENSIONS)) {
            return false;
        }

        // If it's a URL, we'll validate after download
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return true;
        }

        // Check if file is readable and is an image
        if (!is_readable($path)) {
            return false;
        }

        $imageInfo = @getimagesize($path);
        return $imageInfo !== false;
    }

    /**
     * Download or copy image to tmp directory
     */
    private function downloadImageToTmp(string $sourcePath, string $sku, int $index): ?string
    {
        $tmpDir = $this->getTmpPath();
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'jpg';
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $sku . '_' . $index . '.' . $ext;

        try {
            if (filter_var($sourcePath, FILTER_VALIDATE_URL)) {
                // Download from URL with timeout and size limit
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => 30,
                        'max_redirects' => 3,
                    ],
                ]);
                $content = @file_get_contents($sourcePath, false, $ctx);
                if ($content === false || \strlen($content) > 10 * 1024 * 1024) {
                    return null;
                }
                file_put_contents($tmpFile, $content);
            } else {
                // Copy from local/network path
                if (!copy($sourcePath, $tmpFile)) {
                    return null;
                }
            }

            // Validate downloaded/copied image
            $imageInfo = @getimagesize($tmpFile);
            if ($imageInfo === false) {
                $this->ioFile->rm($tmpFile);
                return null;
            }

            return $tmpFile;
        } catch (\Exception $e) {
            $this->logger->warning('[ERP] Failed to download image: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if image already exists on product
     */
    private function imageExistsOnProduct($product, string $imageHash): bool
    {
        $mediaGallery = $product->getMediaGalleryEntries();

        if (!$mediaGallery) {
            return false;
        }

        $mediaPath = $this->getMediaPath();

        foreach ($mediaGallery as $entry) {
            $existingFile = $mediaPath . $entry->getFile();
            if (file_exists($existingFile)) {
                $existingHash = md5_file($existingFile);
                if ($existingHash === $imageHash) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Add image to product via MediaGalleryManagement API.
     * Uses base64 content + entry object — the Magento-native way that avoids
     * double-processing during productRepository->save().
     */
    private function addImageToProduct($product, string $imagePath, string $label, array $roles, int $position): void
    {
        $sku = $product->getSku();

        $imageContent = $this->imageContentFactory->create();
        $imageContent->setBase64EncodedData(base64_encode(file_get_contents($imagePath)));
        $imageContent->setType(mime_content_type($imagePath));
        // Sanitize name: replace dots with underscores to prevent Magento's
        // ImageContentValidator from misinterpreting them as file extensions
        $imageName = str_replace('.', '_', pathinfo($imagePath, PATHINFO_FILENAME));
        $imageContent->setName($imageName);

        $entry = $this->galleryEntryFactory->create();
        $entry->setMediaType('image');
        $entry->setLabel($label ?: $sku);
        $entry->setPosition($position);
        $entry->setDisabled(false);
        $entry->setTypes($roles);
        $entry->setContent($imageContent);

        $this->mediaGalleryManagement->create($sku, $entry);

        // Clean up tmp file
        @unlink($imagePath);
    }

    /**
     * Get media catalog path
     */
    private function getMediaPath(): string
    {
        if ($this->mediaPath === null) {
            $this->mediaPath = $this->filesystem
                ->getDirectoryRead(DirectoryList::MEDIA)
                ->getAbsolutePath('catalog/product');
        }

        return $this->mediaPath;
    }

    /**
     * Get tmp path for image downloads.
     * Must be inside pub/media/ — Magento's GalleryProcessor rejects files outside that tree.
     */
    private function getTmpPath(): string
    {
        if ($this->tmpPath === null) {
            $this->tmpPath = $this->filesystem
                ->getDirectoryWrite(DirectoryList::MEDIA)
                ->getAbsolutePath('tmp/erp_images');

            if (!is_dir($this->tmpPath)) {
                mkdir($this->tmpPath, 0755, true);
            }
        }

        return $this->tmpPath;
    }

    // ==================== Replace / Blob / Hash Methods ====================

    /**
     * Remove all images from a product (used when replace mode is active).
     * Marks images as removed in the data array — processed during product save.
     * Also clears store-level image attribute overrides to avoid stale values.
     */
    private function removeAllProductImages($product, string $sku): void
    {
        $mediaGallery = $product->getData('media_gallery');
        if (!$mediaGallery || empty($mediaGallery['images'])) {
            // Even with no gallery, clear stale attribute values
            $this->clearImageAttributeOverrides($product);
            return;
        }

        $removed = 0;
        foreach ($mediaGallery['images'] as &$image) {
            $image['removed'] = 1;
            $removed++;
        }
        unset($image);

        $product->setData('media_gallery', $mediaGallery);

        // Reset image role attributes so new images get assigned cleanly
        $this->clearImageAttributeOverrides($product);

        if ($removed > 0) {
            $this->logger->debug(sprintf('[ERP] Marked %d images for removal from SKU %s', $removed, $sku));
        }
    }

    /**
     * Clear store-level image attribute overrides and reset the product data.
     * Prevents stale store-specific values from persisting after replace.
     */
    private function clearImageAttributeOverrides($product): void
    {
        // Reset product data attributes
        foreach (self::IMAGE_ROLES as $role) {
            $product->setData($role, 'no_selection');
        }

        // Delete store-level overrides directly from DB to avoid stale values
        try {
            $connection = $this->syncLogResource->getConnection();
            $varcharTable = $connection->getTableName('catalog_product_entity_varchar');
            $entityId = (int) $product->getId();

            foreach (self::IMAGE_ROLES as $role) {
                $attrId = (int) $product->getResource()->getAttribute($role)->getAttributeId();
                if ($attrId) {
                    $connection->delete($varcharTable, [
                        'entity_id = ?' => $entityId,
                        'attribute_id = ?' => $attrId,
                        'store_id != ?' => 0,
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('[ERP] Failed to clear store image overrides: ' . $e->getMessage());
        }
    }

    /**
     * Detect if data is binary (VARBINARY/IMAGE blob) vs a path string
     */
    private function isBinaryData($data): bool
    {
        if (!is_string($data)) {
            return true;
        }

        // If it contains non-printable bytes in the first 100 chars, it's binary
        return preg_match('/[^\x20-\x7E\x0A\x0D\x09]/', substr($data, 0, 100)) === 1;
    }

    /**
     * Save binary blob data to a temporary file
     */
    private function saveBlobToTmp(string $blobData, string $sku, int $index): ?string
    {
        $tmpDir = $this->getTmpPath();
        $ext = $this->detectImageExtension($blobData);
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $sku . '_blob_' . $index . '.' . $ext;

        try {
            file_put_contents($tmpFile, $blobData);

            // Validate it's a real image
            $imageInfo = @getimagesize($tmpFile);
            if ($imageInfo === false) {
                $this->ioFile->rm($tmpFile);
                $this->logger->debug(sprintf('[ERP] Blob for SKU %s index %d is not a valid image', $sku, $index));
                return null;
            }

            return $tmpFile;
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('[ERP] Failed to save blob for SKU %s: %s', $sku, $e->getMessage()));
            return null;
        }
    }

    /**
     * Detect image format from binary header (magic bytes)
     */
    private function detectImageExtension(string $data): string
    {
        $header = substr($data, 0, 4);
        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($header, "\x89PNG")) {
            return 'png';
        }
        if (str_starts_with($header, "GIF8")) {
            return 'gif';
        }
        if (str_starts_with($header, "RIFF")) {
            return 'webp';
        }
        return 'jpg'; // fallback
    }

    /**
     * Get stored hash for a previously synced image (incremental sync)
     */
    private function getStoredImageHash(string $sku, int $imageIndex): ?string
    {
        try {
            $connection = $this->syncLogResource->getConnection();
            $tableName = $connection->getTableName('grupoawamotos_erp_image_hash');

            // Check if table exists (first sync after upgrade)
            if (!$connection->isTableExists($tableName)) {
                return null;
            }

            $select = $connection->select()
                ->from($tableName, ['erp_hash'])
                ->where('sku = ?', $sku)
                ->where('image_index = ?', $imageIndex);

            $hash = $connection->fetchOne($select);
            return $hash ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Save/update hash for a synced image (incremental tracking)
     */
    private function saveImageHash(string $sku, int $imageIndex, string $hash, ?string $magentoFile = null): void
    {
        try {
            $connection = $this->syncLogResource->getConnection();
            $tableName = $connection->getTableName('grupoawamotos_erp_image_hash');

            if (!$connection->isTableExists($tableName)) {
                return;
            }

            $data = [
                'sku' => $sku,
                'image_index' => $imageIndex,
                'erp_hash' => $hash,
                'synced_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ];

            if ($magentoFile !== null) {
                $data['magento_file'] = $magentoFile;
            }

            $updateFields = ['erp_hash', 'synced_at'];
            if ($magentoFile !== null) {
                $updateFields[] = 'magento_file';
            }

            $connection->insertOnDuplicate($tableName, $data, $updateFields);
        } catch (\Exception $e) {
            $this->logger->debug(sprintf('[ERP] Failed to save image hash for SKU %s: %s', $sku, $e->getMessage()));
        }
    }

    /**
     * Get the file path of the last added image in the media gallery
     */
    private function getLastAddedImageFile($product): ?string
    {
        $mediaGallery = $product->getData('media_gallery');
        if ($mediaGallery && !empty($mediaGallery['images'])) {
            $images = $mediaGallery['images'];
            $lastImage = end($images);
            return $lastImage['file'] ?? null;
        }
        return null;
    }
}
