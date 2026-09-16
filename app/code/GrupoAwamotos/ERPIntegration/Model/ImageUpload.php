<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model;

use GrupoAwamotos\ERPIntegration\Api\ImageUploadInterface;
use GrupoAwamotos\ERPIntegration\Api\ImageSyncInterface;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

class ImageUpload implements ImageUploadInterface
{
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024; // 10MB

    private Helper $helper;
    private ProductRepositoryInterface $productRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private ImageSyncInterface $imageSync;
    private ConnectionInterface $connection;
    private Filesystem $filesystem;
    private LoggerInterface $logger;

    public function __construct(
        Helper $helper,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ImageSyncInterface $imageSync,
        ConnectionInterface $connection,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->imageSync = $imageSync;
        $this->connection = $connection;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    public function upload(
        string $sku,
        string $imageData,
        string $filename,
        bool $isMain = true,
        ?string $label = null
    ): array {
        try {
            // Validate SKU exists
            try {
                $this->productRepository->get($sku);
            } catch (\Exception $e) {
                return ['status' => 'error', 'message' => "Produto SKU '$sku' nao encontrado no Magento"];
            }

            // Validate filename extension
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, self::SUPPORTED_EXTENSIONS)) {
                return ['status' => 'error', 'message' => "Extensao '$ext' nao suportada. Use: " . implode(', ', self::SUPPORTED_EXTENSIONS)];
            }

            // Decode base64
            $decoded = base64_decode($imageData, true);
            if ($decoded === false) {
                return ['status' => 'error', 'message' => 'Dados de imagem base64 invalidos'];
            }

            if (strlen($decoded) > self::MAX_IMAGE_SIZE) {
                return ['status' => 'error', 'message' => 'Imagem excede o limite de 10MB'];
            }

            // Save to erp_images folder
            $basePath = $this->getImageBasePath();
            $targetFile = $basePath . DIRECTORY_SEPARATOR . $sku . '.' . $ext;

            file_put_contents($targetFile, $decoded);

            // Validate it's a real image
            $imageInfo = @getimagesize($targetFile);
            if ($imageInfo === false) {
                @unlink($targetFile);
                return ['status' => 'error', 'message' => 'Arquivo nao e uma imagem valida'];
            }

            // Trigger sync for this SKU immediately
            $synced = $this->imageSync->syncBySku($sku);

            $sizeKb = round(strlen($decoded) / 1024, 1);
            $dims = $imageInfo[0] . 'x' . $imageInfo[1];

            $this->logger->info(sprintf(
                '[ERP API] Image uploaded for SKU %s: %s (%sKB, %s) synced=%s',
                $sku,
                $filename,
                $sizeKb,
                $dims,
                $synced ? 'yes' : 'no'
            ));

            return [
                'status' => 'success',
                'message' => sprintf('Imagem %s importada para produto %s (%sKB, %s)', $filename, $sku, $sizeKb, $dims),
                'synced' => $synced ? 'true' : 'pending',
            ];
        } catch (\Exception $e) {
            $this->logger->error('[ERP API] Image upload failed: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function uploadBatch(array $images): array
    {
        $results = [];

        foreach ($images as $item) {
            $sku = $item['sku'] ?? '';
            $imageData = $item['imageData'] ?? $item['image_data'] ?? '';
            $filename = $item['filename'] ?? ($sku . '.jpg');
            $isMain = $item['isMain'] ?? $item['is_main'] ?? true;
            $label = $item['label'] ?? null;

            if (empty($sku) || empty($imageData)) {
                $results[] = [
                    'sku' => $sku,
                    'status' => 'error',
                    'message' => 'SKU e imageData sao obrigatorios',
                ];
                continue;
            }

            $result = $this->upload($sku, $imageData, $filename, (bool) $isMain, $label);
            $result['sku'] = $sku;
            $results[] = $result;
        }

        return $results;
    }

    public function getPendingProducts(int $limit = 100): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->setPageSize($limit)
            ->create();
        $products = $this->productRepository->getList($searchCriteria);

        // Get CODINTERNO map from ERP
        $codInternoMap = [];
        try {
            $rows = $this->connection->query(
                "SELECT CODIGO, CODINTERNO FROM MT_MATERIAL WHERE CODINTERNO IS NOT NULL AND CODINTERNO != 0"
            );
            foreach ($rows as $row) {
                $codInternoMap[$row['CODIGO']] = (string) $row['CODINTERNO'];
            }
        } catch (\Exception $e) {
            $this->logger->warning('[ImageUpload::getPendingProducts] Exceção ignorada: ' . $e->getMessage());
            // ERP unavailable
        }

        $missing = [];
        foreach ($products->getItems() as $product) {
            $image = $product->getData('image');
            if (empty($image) || $image === 'no_selection') {
                $sku = $product->getSku();
                $missing[] = [
                    'sku' => $sku,
                    'name' => $product->getName(),
                    'codinterno' => $codInternoMap[$sku] ?? null,
                ];
            }
        }

        return $missing;
    }

    private function getImageBasePath(): string
    {
        $basePath = $this->helper->getImageBasePath();
        if (empty($basePath)) {
            $basePath = $this->filesystem
                ->getDirectoryWrite(DirectoryList::MEDIA)
                ->getAbsolutePath('erp_images');
        }

        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        return $basePath;
    }
}
