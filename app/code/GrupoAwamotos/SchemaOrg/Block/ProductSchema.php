<?php

/**
 * Product Schema.org Block
 * Gera markup JSON-LD para páginas de produto
 */

declare(strict_types=1);

namespace GrupoAwamotos\SchemaOrg\Block;

use Magento\Catalog\Block\Product\View;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\Registry;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\StoreManagerInterface;

class ProductSchema extends Template
{
    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var ReviewFactory
     */
    protected $reviewFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ReviewFactory $reviewFactory
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ReviewFactory $reviewFactory,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->reviewFactory = $reviewFactory;
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * Get current product
     *
     * @return \Magento\Catalog\Model\Product|null
     */
    public function getProduct()
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Get product schema data
     *
     * @return array
     */
    public function getProductSchemaData()
    {
        $product = $this->getProduct();
        if (!$product) {
            return [];
        }

        $store = $this->storeManager->getStore();
        $baseUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);

        // Imagem principal
        $productImage = $product->getImage();
        $imageUrl = ($productImage && $productImage !== '/no_selection')
            ? $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product' . $productImage
            : '';

        // Dados básicos
        $description = $product->getShortDescription() ?: $product->getDescription();
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            '@id' => $product->getProductUrl() . '#product',
            'name' => $product->getName(),
            'description' => $description ? strip_tags($description) : '',
            'sku' => $product->getSku(),
            'url' => $product->getProductUrl(),
        ];

        if ($imageUrl) {
            $schema['image'] = $imageUrl;
        }

        // Marca
        if ($manufacturer = $product->getAttributeText('manufacturer')) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name' => $manufacturer
            ];
        }

        // Preço e disponibilidade
        $extensionAttributes = $product->getExtensionAttributes();
        $stockItem = $extensionAttributes ? $extensionAttributes->getStockItem() : null;
        $inStock = $stockItem && $stockItem->getIsInStock();

        // B2B SEO: preço pode ser null/string quando oculto para guests
        $finalPrice = (float) $product->getFinalPrice();
        $regularPrice = (float) $product->getPrice();
        $specialPrice = $product->getSpecialPrice() !== null ? (float) $product->getSpecialPrice() : null;

        $schema['offers'] = [
            '@type' => 'Offer',
            'priceCurrency' => 'BRL',
            'availability' => $inStock ?
                'https://schema.org/InStock' :
                'https://schema.org/OutOfStock',
            'url' => $product->getProductUrl(),
            'priceValidUntil' => date('Y-12-31', strtotime('+1 year')),
            'seller' => [
                '@type' => 'Organization',
                'name' => 'Grupo Awamotos'
            ]
        ];

        // Apenas incluir preço se disponível (guests B2B não veem preço em algumas configs)
        if ($finalPrice > 0) {
            $schema['offers']['price'] = number_format($finalPrice, 2, '.', '');
        }

        // Special price
        if ($specialPrice !== null && $specialPrice > 0 && $regularPrice > 0 && $specialPrice < $regularPrice) {
            $schema['offers']['priceSpecification'] = [
                '@type' => 'UnitPriceSpecification',
                'priceType' => 'https://schema.org/SalePrice',
                'price' => number_format($specialPrice, 2, '.', ''),
                'priceCurrency' => 'BRL'
            ];
        }

        // Ratings e reviews — dados reais via getRatingSummary() (carregado com o produto)
        try {
            $reviewSummary = $product->getRatingSummary();
            if ($reviewSummary && (int) $reviewSummary->getReviewsCount() > 0) {
                $ratingPercent = (float) $reviewSummary->getRatingSummary(); // escala 0–100
                $ratingValue = round($ratingPercent / 20, 1); // converte para escala 0–5
                $schema['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => number_format($ratingValue, 1),
                    'reviewCount' => (int) $reviewSummary->getReviewsCount(),
                    'bestRating' => '5',
                    'worstRating' => '1',
                ];
            }
        } catch (\Exception $e) {
            $this->_logger->debug('[ProductSchema::getProductSchemaData] Sem reviews disponíveis: ' . $e->getMessage());
        }

        // GTIN/EAN se disponível
        if ($gtin = $product->getData('gtin')) {
            $schema['gtin'] = $gtin;
        } elseif ($ean = $product->getData('ean')) {
            $schema['gtin13'] = $ean;
        }

        // Condição do produto dentro de Offers (Padrão Schema.org correto)
        if (isset($schema['offers'])) {
            $schema['offers']['itemCondition'] = 'https://schema.org/NewCondition';
        }

        return $schema;
    }

    /**
     * Get schema JSON
     *
     * @return string
     */
    public function getSchemaJson()
    {
        $data = $this->getProductSchemaData();
        if (empty($data)) {
            return '';
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
