<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Block;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Carrossel de produtos filtrado por categoria(s) — mais vendidos da família (90d).
 *
 * Uso no phtml:
 *   $b = $layout->createBlock(CategoryProductCarousel::class);
 *   $b->setCategoryIds('46,75,76');   // IDs separados por vírgula
 *   $b->setQty(12);                   // opcional, default 12
 *   $b->setTemplate('Rokanthemes_BestsellerProduct::bestseller.phtml');
 *   echo $b->toHtml();
 */
class CategoryProductCarousel extends \Rokanthemes\BestsellerProduct\Block\Bestseller
{
    private const DEFAULT_QTY = 12;

    private const SALES_WINDOW_DAYS = 90;

    private const SALES_FALLBACK_DAYS = 365;

    private const CANCELED_ORDER_STATES = [
        'canceled',
        'closed',
        'paypal_canceled_reversal',
        'fraud',
    ];

    private ?Collection $resolvedCollection = null;

    /**
     * Produtos das categorias em category_ids, ordenados por vendas recentes.
     * Sem category_ids, delega ao pai (mais vendidos globais do Rokanthemes).
     */
    public function getProducts(): Collection
    {
        if ($this->resolvedCollection !== null) {
            return $this->resolvedCollection;
        }

        $salesCategoryIds = $this->resolveCategoryIds('category_ids');
        if ($salesCategoryIds === []) {
            $this->resolvedCollection = parent::getProducts();

            return $this->resolvedCollection;
        }

        // membership_category_ids: subcats "puras" da família (ex.: litros de bauleto),
        // evitando acessórios/lentes ligados só à categoria pai.
        $membershipIds = $this->resolveCategoryIds('membership_category_ids');
        if ($membershipIds === []) {
            $membershipIds = $salesCategoryIds;
        }

        $qty = max(1, (int) ($this->getData('qty') ?: $this->getConfig('qty') ?: self::DEFAULT_QTY));
        $pool = max(32, $qty * 6);

        $rankedIds = $this->fetchCategoryTopSellerIds($salesCategoryIds, self::SALES_WINDOW_DAYS, $pool);
        if (count($rankedIds) < $qty) {
            $rankedIds = $this->mergeUniqueIds(
                $rankedIds,
                $this->fetchCategoryTopSellerIds($salesCategoryIds, self::SALES_FALLBACK_DAYS, $pool)
            );
        }
        if (count($rankedIds) < $qty) {
            $rankedIds = $this->mergeUniqueIds(
                $rankedIds,
                $this->fetchCategoryTopSellerIds($salesCategoryIds, 0, $pool)
            );
        }

        $rankedIds = $this->filterIdsByMembership($rankedIds, $membershipIds);

        if ($rankedIds === []) {
            $this->resolvedCollection = $this->buildNewestInCategoriesCollection($membershipIds, $qty);
        } else {
            $picked = array_slice($rankedIds, 0, $qty);
            $collection = $this->buildCollectionFromIds($picked, $membershipIds);
            if ((int) $collection->getSize() < $qty) {
                $fallback = $this->buildNewestInCategoriesCollection($membershipIds, $qty * 3);
                $merged = [];
                foreach ($collection as $product) {
                    $merged[] = (int) $product->getId();
                }
                foreach ($fallback as $product) {
                    $id = (int) $product->getId();
                    if (!in_array($id, $merged, true)) {
                        $merged[] = $id;
                    }
                    if (count($merged) >= $qty) {
                        break;
                    }
                }
                $collection = $this->buildCollectionFromIds(array_slice($merged, 0, $qty), $membershipIds);
            }
            $this->resolvedCollection = $collection;
        }

        return $this->resolvedCollection;
    }

    public function hasProducts(): bool
    {
        return (int) $this->getProducts()->getSize() > 0;
    }

    /**
     * Garante que getConfig('enabled') retorna 1 para este bloco,
     * mesmo que o módulo bestsellerproduct esteja desabilitado no admin.
     *
     * @param mixed $att
     */
    public function getConfig($att): mixed
    {
        if ($att === 'enabled') {
            return 1;
        }

        if ($att === 'show_price') {
            $override = $this->getData('show_price');

            return $override !== null ? $override : 1;
        }

        $override = $this->getData($att);
        if ($override !== null) {
            return $override;
        }

        return parent::getConfig($att);
    }

    /**
     * @return list<int>
     */
    private function resolveCategoryIds(string $key = 'category_ids'): array
    {
        $rawIds = $this->getData($key);
        if ($rawIds === null || $rawIds === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', explode(',', (string) $rawIds)),
            static fn (int $id): bool => $id > 0
        ));
    }

    /**
     * Mantém só produtos que pertençam a pelo menos uma categoria de membership.
     *
     * @param list<int> $productIds
     * @param list<int> $membershipIds
     * @return list<int>
     */
    private function filterIdsByMembership(array $productIds, array $membershipIds): array
    {
        if ($productIds === [] || $membershipIds === []) {
            return $productIds;
        }

        $table = $this->resource->getTableName('catalog_category_product');
        $select = $this->connection->select()
            ->from($table, ['product_id'])
            ->where('product_id IN (?)', $productIds)
            ->where('category_id IN (?)', $membershipIds)
            ->group('product_id');

        $allowed = [];
        foreach ($this->connection->fetchCol($select) as $id) {
            $allowed[(int) $id] = true;
        }

        $filtered = [];
        foreach ($productIds as $id) {
            if (isset($allowed[$id])) {
                $filtered[] = $id;
            }
        }

        return $filtered;
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int>
     */
    private function fetchCategoryTopSellerIds(array $categoryIds, int $days, int $limit): array
    {
        $orderItemTable = $this->resource->getTableName('sales_order_item');
        $orderTable = $this->resource->getTableName('sales_order');
        $categoryProductTable = $this->resource->getTableName('catalog_category_product');

        $select = $this->connection->select()
            ->from(['oi' => $orderItemTable], [
                'product_id' => 'oi.product_id',
                'qty' => new Expression('SUM(oi.qty_ordered)'),
            ])
            ->joinInner(['o' => $orderTable], 'o.entity_id = oi.order_id', [])
            ->joinInner(
                ['ccp' => $categoryProductTable],
                'ccp.product_id = oi.product_id',
                []
            )
            ->where('ccp.category_id IN (?)', $categoryIds)
            ->where('oi.parent_item_id IS NULL')
            ->where('oi.product_id IS NOT NULL')
            ->where('oi.product_id > 0')
            ->where('o.state NOT IN (?)', self::CANCELED_ORDER_STATES)
            ->group('oi.product_id')
            ->order(['qty DESC', 'oi.product_id ASC'])
            ->limit($limit);

        if ($days > 0) {
            $select->where(
                'o.created_at >= ?',
                (new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s')
            );
        }

        $ids = [];
        foreach ($this->connection->fetchAll($select) as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId > 0) {
                $ids[] = $productId;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $base
     * @param list<int> $extra
     * @return list<int>
     */
    private function mergeUniqueIds(array $base, array $extra): array
    {
        $seen = array_fill_keys($base, true);
        foreach ($extra as $id) {
            if (!isset($seen[$id])) {
                $base[] = $id;
                $seen[$id] = true;
            }
        }

        return $base;
    }

    /**
     * @param list<int> $picked
     * @param list<int> $categoryIds
     */
    private function buildCollectionFromIds(array $picked, array $categoryIds): Collection
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $collection = $this->productCollectionFactory->create()->setStoreId($storeId);
        $collection
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            // awa_home_shelf usa type="small_image": sem estes atributos o
            // getImage() cai no placeholder (mesmo o produto tendo foto).
            ->addAttributeToSelect(['image', 'small_image', 'thumbnail'])
            ->addCategoriesFilter(['in' => $categoryIds])
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite()
            ->setVisibility($this->productVisibility->getVisibleInCatalogIds())
            ->addAttributeToFilter('entity_id', ['in' => $picked])
            ->addAttributeToFilter('image', ['neq' => 'no_selection']);

        $safeIds = array_map('intval', $picked);
        $collection->getSelect()->order(
            new Expression('FIELD(e.entity_id,' . implode(',', $safeIds) . ')')
        );
        $collection->setPageSize(count($picked))->setCurPage(1);

        $this->_eventManager->dispatch(
            'catalog_block_product_list_collection',
            ['collection' => $collection]
        );

        return $collection;
    }

    /**
     * @param list<int> $categoryIds
     */
    private function buildNewestInCategoriesCollection(array $categoryIds, int $qty): Collection
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $collection = $this->productCollectionFactory->create()->setStoreId($storeId);
        $collection
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            // awa_home_shelf usa type="small_image": sem estes atributos o
            // getImage() cai no placeholder (mesmo o produto tendo foto).
            ->addAttributeToSelect(['image', 'small_image', 'thumbnail'])
            ->addCategoriesFilter(['in' => $categoryIds])
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite()
            ->setVisibility($this->productVisibility->getVisibleInCatalogIds())
            ->addAttributeToFilter('image', ['neq' => 'no_selection'])
            ->addAttributeToSort('entity_id', 'desc');

        $collection->setPageSize($qty)->setCurPage(1);

        $this->_eventManager->dispatch(
            'catalog_block_product_list_collection',
            ['collection' => $collection]
        );

        return $collection;
    }
}
