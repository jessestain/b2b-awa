<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Fixes instant.json store_id=0 fallback pointing to non-existent OpenSearch indices.
 *
 * Mirasvit generates instant.json with store_id=0 entries (e.g. magento2_product_0)
 * that are never created. When a request lacks `store_id`, ConfigProvider defaults
 * to 0 → OpenSearch 404 → 0 autocomplete results.
 *
 * This service copies store_id=1 real index names into the store_id=0 section.
 */
class InstantJsonFixer
{
    private const INDEX_KEYS = [
        'magento_catalog_product',
        'magento_catalog_category',
        'magento_cms_page',
        'mst_misspell_index',
    ];

    private Filesystem $filesystem;

    private Json $serializer;

    private LoggerInterface $logger;

    public function __construct(
        Filesystem $filesystem,
        Json $serializer,
        LoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->serializer = $serializer;
        $this->logger     = $logger;
    }

    /**
     * Read instant.json, copy elasticsearch7 index names from first real store into store 0.
     *
     * @return void
     */
    public function fix(): void
    {
        try {
            $dir  = $this->filesystem->getDirectoryWrite(DirectoryList::CONFIG);
            $path = $dir->getRelativePath('instant.json');

            if (!$dir->isExist($path)) {
                return;
            }

            $raw    = $dir->readFile($path);
            $config = $this->serializer->unserialize($raw);

            if (!is_array($config)) {
                return;
            }

            $sourceEngine = $this->findFirstRealStoreEngine($config);
            if ($sourceEngine === null) {
                return;
            }

            $targetKey = '0/elasticsearch7';
            if (!isset($config[$targetKey]) || !is_array($config[$targetKey])) {
                return;
            }

            $changed = false;
            foreach (self::INDEX_KEYS as $indexKey) {
                if (isset($sourceEngine[$indexKey], $config[$targetKey][$indexKey])
                    && $config[$targetKey][$indexKey] !== $sourceEngine[$indexKey]
                ) {
                    $config[$targetKey][$indexKey] = $sourceEngine[$indexKey];
                    $changed = true;
                }
            }

            if (!$changed) {
                return;
            }

            $tmp = $path . '.tmp.' . uniqid('', true);
            $dir->writeFile($tmp, $this->serializer->serialize($config));
            $dir->renameFile($tmp, $path);

            $this->logger->info('CatalogFix: instant.json store_id=0 elasticsearch7 indices fixed.');
        } catch (\Exception $e) {
            $this->logger->warning('CatalogFix: InstantJsonFixer::fix() failed — ' . $e->getMessage());
        }
    }

    /**
     * Find the first real store's elasticsearch7 config (store_id 1-10).
     *
     * @param array<string, mixed> $config
     * @return array<string, string>|null
     */
    private function findFirstRealStoreEngine(array $config): ?array
    {
        for ($storeId = 1; $storeId <= 10; $storeId++) {
            $key = "{$storeId}/elasticsearch7";
            if (isset($config[$key]) && is_array($config[$key])) {
                return $config[$key];
            }
        }
        return null;
    }
}
