<?php

declare(strict_types=1);

namespace GrupoAwamotos\SchemaOrg\Block;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Gera schema.org ItemList com os primeiros produtos da categoria atual
 */
class CategoryItemList extends Template
{
    private const MAX_ITEMS = 10;

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly CollectionFactory $collectionFactory,
        private readonly ImageHelper $imageHelper,
        private readonly PricingHelper $pricingHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Retorna JSON-LD ItemList ou string vazia se não houver itens
     */
    public function getItemListSchema(): string
    {
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return '';
        }

        $items = $this->buildItemListElements($category);
        if (empty($items)) {
            return '';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'ItemList',
            'name'     => $category->getName(),
            'url'      => $category->getUrl(),
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ];

        return json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function getCurrentCategory(): ?Category
    {
        $category = $this->registry->registry('current_category');
        return ($category instanceof Category && $category->getId()) ? $category : null;
    }

    /**
     * @param Category $category
     * @return array<int, array<string, mixed>>
     */
    private function buildItemListElements(Category $category): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addCategoryFilter($category);
        $collection->addAttributeToSelect(['name', 'url_key', 'price', 'special_price', 'image', 'thumbnail', 'small_image']);
        $collection->addAttributeToFilter('status', 1);
        $collection->addAttributeToFilter('visibility', ['in' => [2, 3, 4]]);
        $collection->setPageSize(self::MAX_ITEMS);
        $collection->setCurPage(1);
        $collection->setOrder('position', 'ASC');

        $items = [];
        $position = 1;

        foreach ($collection as $product) {
            $productUrl = $product->getUrlModel()->getUrl($product);
            $imageUrl   = $this->getProductImageUrl($product);
            $price      = $this->getFinalPrice($product);

            $item = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'url'      => $productUrl,
                'name'     => $product->getName(),
            ];

            if ($imageUrl !== '') {
                $item['image'] = $imageUrl;
            }

            if ($this->canEmitPrices() && $price > 0.0) {
                $item['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => round($price, 2),
                    'priceCurrency' => 'BRL',
                    'availability'  => 'https://schema.org/InStock',
                ];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * ACHADO SEO-001: o preço do JSON-LD da listagem só é emitido para quem pode ver preço.
     *
     * O gate chega por <argument name="b2b_gate"> no layout do tema (passo 04 deste pacote),
     * sem alterar o construtor do bloco — o que manteria o custo de setup:di:compile e criaria
     * risco de dependência circular SchemaOrg -> B2B.
     *
     * Fail-closed: sem gate válido, ou em erro, NÃO emite preço.
     */
    private function canEmitPrices(): bool
    {
        $gate = $this->getData('b2b_gate');

        if (!$gate instanceof \GrupoAwamotos\B2B\ViewModel\PdpGate) {
            return false;
        }

        try {
            return $gate->canViewPrices();
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function getProductImageUrl(mixed $product): string
    {
        try {
            $url = $this->imageHelper
                ->init($product, 'product_thumbnail_image')
                ->setImageFile($product->getThumbnail() ?: $product->getSmallImage() ?: $product->getImage())
                ->getUrl();
            return (string) $url;
        } catch (\Exception) {
            return '';
        }
    }

    private function getFinalPrice(mixed $product): float
    {
        $special = (float) ($product->getSpecialPrice() ?? 0);
        $regular = (float) ($product->getPrice() ?? 0);
        if ($special > 0.0 && $special < $regular) {
            return $special;
        }
        return $regular;
    }
}
