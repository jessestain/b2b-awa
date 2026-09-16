<?php

declare(strict_types=1);

namespace Awa\CatalogFix\Model\Search;

use Magento\Framework\App\ResourceConnection;

/**
 * Distinguishes a catalog SKU lookup from a textual search token.
 *
 * Character-class matching alone is not enough: words such as "bagageiro"
 * match ^[A-Za-z0-9._-]{2,12}$ but are product names, not SKUs.
 */
class SkuQueryClassifier
{
    private const SKU_LIKE_PATTERN = '/^[A-Za-z0-9._-]{2,12}$/';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function isSkuLikeToken(string $query): bool
    {
        $query = trim($query);

        return $query !== '' && preg_match(self::SKU_LIKE_PATTERN, $query) === 1;
    }

    public function skuExists(string $query): bool
    {
        $query = trim($query);
        if ($query === '') {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity');
        $entityId = $connection->fetchOne(
            $connection->select()
                ->from($table, ['entity_id'])
                ->where('sku = ?', $query)
                ->limit(1)
        );
        if ($entityId !== false && $entityId !== null && $entityId !== '') {
            return true;
        }

        $entityId = $connection->fetchOne(
            $connection->select()
                ->from($table, ['entity_id'])
                ->where('LOWER(sku) = LOWER(?)', $query)
                ->limit(1)
        );

        return $entityId !== false && $entityId !== null && $entityId !== '';
    }

    /**
     * True only when the query looks like a SKU and that SKU exists in catalog.
     */
    public function isExistingExactSkuQuery(string $query): bool
    {
        return $this->isSkuLikeToken($query) && $this->skuExists($query);
    }
}
