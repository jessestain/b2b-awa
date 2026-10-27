<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\ViewModel;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\StoreManagerInterface;

class ProductStructuredData implements ArgumentInterface
{
    /**
     * Marcas de motocicletas usadas no atributo 'manufacturer' para fitment/compatibilidade.
     * Esses valores NÃO representam o fabricante do produto, mas sim a compatibilidade com
     * a moto. Produtos com esses valores devem usar 'AWA Motos' como brand no schema.org.
     *
     * @var array<string>
     */
    private const MOTO_BRANDS = ['Honda', 'Kawasaki', 'Suzuki', 'Yamaha', 'KTM', 'BMW', 'Ducati', 'Triumph', 'Harley-Davidson'];
    private bool $currentProductResolved = false;

    private ?Product $currentProduct = null;

    private bool $productImageUrlResolved = false;

    private string $productImageUrl = '';

    private bool $productStructuredDataResolved = false;

    /**
     * @var array<string, mixed>
     */
    private array $productStructuredData = [];

    private bool $breadcrumbStructuredDataResolved = false;

    /**
     * @var array<string, mixed>
     */
    private array $breadcrumbStructuredData = [];

    private bool $aggregateRatingResolved = false;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $aggregateRating = null;

    private bool $resolvedCurrentCategoryLoaded = false;

    private ?Category $resolvedCurrentCategory = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly ImageHelper $imageHelper,
        private readonly ReviewFactory $reviewFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CatalogHelper $catalogHelper,
    ) {
    }

    /**
     * Trilha visual da PDP (Home → categoria → produto) via CatalogHelper + plugin BREAD-001.
     *
     * @return array<string, array{label?: string, title?: string, link?: string, last?: bool}>
     */
    public function getBreadcrumbPath(): array
    {
        try {
            $path = $this->catalogHelper->getBreadcrumbPath();

            return is_array($path) ? $path : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getCurrentProduct(): ?Product
    {
        if ($this->currentProductResolved) {
            return $this->currentProduct;
        }

        $product = $this->registry->registry('product') ?: $this->registry->registry('current_product');
        $this->currentProduct = $product instanceof Product ? $product : null;
        $this->currentProductResolved = true;

        return $this->currentProduct;
    }

    /**
     * URL da imagem principal do produto — usada no preload LCP do <head>.
     */
    public function getProductLcpImageUrl(): string
    {
        $product = $this->getCurrentProduct();
        if (!$product || !$product->getId()) {
            return '';
        }

        return $this->getProductImageUrl($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function getProductStructuredData(): array
    {
        if ($this->productStructuredDataResolved) {
            return $this->productStructuredData;
        }

        $product = $this->getCurrentProduct();
        if (!$product || !$product->getId()) {
            $this->productStructuredDataResolved = true;

            return [];
        }

        $productUrl = (string) $product->getProductUrl();
        $schema = [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $this->normalizeText((string) $product->getName()),
            'sku' => (string) $product->getSku(),
            'image' => $this->getProductImageUrl($product),
            'url' => $productUrl,
            'itemCondition' => 'https://schema.org/NewCondition',
            'brand' => [
                '@type' => 'Brand',
                'name' => $this->resolveBrandName($product),
            ],
        ];

        $description = $this->normalizeText(
            (string) ($product->getShortDescription() ?: $product->getDescription() ?: '')
        );
        if ($description !== '') {
            $schema['description'] = mb_substr($description, 0, 1000);
        }

        $finalPrice = (float) $product->getFinalPrice();
        $regularPrice = (float) $product->getPrice();
        if ($finalPrice > 0) {
            // VTEX-grade 2026-06-27: usa AggregateOffer quando há range de preço
            // (preço normal vs. promocional, ou variantes). Sinaliza ao Google
            // que há economia, melhorando CTR em rich results de produto.
            $lowPrice = $finalPrice;
            $highPrice = max($finalPrice, $regularPrice);
            $priceRange = abs($highPrice - $lowPrice) > 0.005;

            if ($priceRange) {
                $schema['offers'] = [
                    '@type' => 'AggregateOffer',
                    'url' => $productUrl,
                    'priceCurrency' => 'BRL',
                    'lowPrice' => number_format($lowPrice, 2, '.', ''),
                    'highPrice' => number_format($highPrice, 2, '.', ''),
                    'offerCount' => 1,
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'priceValidUntil' => date('Y-12-31', strtotime('+1 year')),
                    'availability' => $product->isAvailable()
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'seller' => [
                        '@type' => 'Organization',
                        'name' => 'Grupo Awamotos',
                    ],
                ];
            } else {
                $schema['offers'] = [
                    '@type' => 'Offer',
                    'url' => $productUrl,
                    'priceCurrency' => 'BRL',
                    'price' => number_format($finalPrice, 2, '.', ''),
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'priceValidUntil' => date('Y-12-31', strtotime('+1 year')),
                    'availability' => $product->isAvailable()
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'seller' => [
                        '@type' => 'Organization',
                        'name' => 'Grupo Awamotos',
                    ],
                ];
            }
        }

        $aggregateRating = $this->getAggregateRating($product);
        if ($aggregateRating !== null) {
            $schema['aggregateRating'] = $aggregateRating;
        }

        $mpn = $product->getData('mpn') ?: $product->getData('codigo_original');
        if ($mpn) {
            $schema['mpn'] = $this->normalizeText((string) $mpn);
        }

        $gtin = $product->getData('ean') ?: $product->getData('gtin') ?: $product->getData('barcode');
        if ($gtin) {
            $gtinStr = $this->normalizeText((string) $gtin);
            if (strlen($gtinStr) === 13) {
                $schema['gtin13'] = $gtinStr;
            } elseif (strlen($gtinStr) === 14) {
                $schema['gtin14'] = $gtinStr;
            } elseif (strlen($gtinStr) === 12) {
                $schema['gtin12'] = $gtinStr;
            } else {
                $schema['gtin'] = $gtinStr;
            }
        }

        // Sprint 4 SEO (2026-06-28): hasMerchantReturnPolicy
        // Obrigatorio pelo Google para rich results desde 2024.
        // Politica padrao AWA Motos: 7 dias para troca, defeito = garantia legal.
        $schema['hasMerchantReturnPolicy'] = [
            '@type' => 'MerchantReturnPolicy',
            'merchantReturnDays' => 7,
            'returnMethod' => 'https://schema.org/ReturnByMail',
            'returnFees' => 'https://schema.org/FreeReturn',
            'merchantReturnLink' => 'https://awamotos.com/trocas-devolucoes',
            'category' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'description' => 'Garantia legal de 90 dias para defeitos de fabricacao. ' .
                             'Trocas por arrependimento em ate 7 dias conforme CDC.',
        ];

        // Sprint 4 SEO (2026-06-28): shippingDetails
        // Necessario para LSO (Local Shipping Optimization) do Google.
        // Valores conservativos - melhorar com tabela de frete real na Sprint 2+.
        $schema['shippingDetails'] = [
            '@type' => 'OfferShippingDetails',
            'shippingDestination' => [
                '@type' => 'DefinedRegion',
                'addressCountry' => 'BR',
                'addressRegion' => ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA',
                                    'MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN',
                                    'RS','RO','RR','SC','SP','SE','TO'],
            ],
            'deliveryTime' => [
                '@type' => 'ShippingDeliveryTime',
                'handlingTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 1,
                    'maxValue' => 2,
                    'unitCode' => 'DAY',
                ],
                'transitTime' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => 2,
                    'maxValue' => 12,
                    'unitCode' => 'DAY',
                ],
            ],
            'shippingRate' => [
                '@type' => 'MonetaryAmount',
                'value' => '0.00',
                'currency' => 'BRL',
            ],
        ];

        $this->productStructuredData = $schema;
        $this->productStructuredDataResolved = true;

        return $this->productStructuredData;
    }

    /**
     * BreadcrumbList JSON-LD: Home → Category (optional) → Product.
     *
     * @return array<string, mixed>
     */
    public function getBreadcrumbStructuredData(): array
    {
        if ($this->breadcrumbStructuredDataResolved) {
            return $this->breadcrumbStructuredData;
        }

        $product = $this->getCurrentProduct();
        if (!$product || !$product->getId()) {
            $this->breadcrumbStructuredDataResolved = true;

            return [];
        }

        try {
            $baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $this->breadcrumbStructuredDataResolved = true;

            return [];
        }

        $items = [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => 'Home',
                'item' => $baseUrl . '/',
            ],
        ];

        $position = 2;
        $category = $this->resolveCurrentCategory($product, $storeId);

        if ($category !== null) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $this->normalizeText((string) $category->getName()),
                'item' => (string) $category->getUrl(),
            ];
        }

        $items[] = [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $this->normalizeText((string) $product->getName()),
        ];

        $this->breadcrumbStructuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
        $this->breadcrumbStructuredDataResolved = true;

        return $this->breadcrumbStructuredData;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getAggregateRating(Product $product): ?array
    {
        if ($this->aggregateRatingResolved) {
            return $this->aggregateRating;
        }
        // SEO-003: o widget de avaliacao da PDP e controlado por catalog/review/active.
        // Com a config desligada o widget NAO renderiza; declarar aggregateRating sem nota visivel
        // e marcacao nao sustentada pela pagina. Mesma config do widget => marca e tela nunca divergem.
        if (!$this->storeManager->getStore()->getConfig("catalog/review/active")) {
            $this->aggregateRatingResolved = true;

            return null;
        }


        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            $this->reviewFactory->create()->getEntitySummary($product, $storeId);

            $ratingValue = $this->extractRatingValue($product->getData('rating_summary'));
            $reviewCount = $this->extractIntValue($product->getData('reviews_count'));

            if ($ratingValue <= 0 || $reviewCount <= 0) {
                $this->aggregateRatingResolved = true;

                return null;
            }

            $this->aggregateRating = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format($ratingValue / 20, 1),
                'reviewCount' => $reviewCount,
                'bestRating' => '5',
                'worstRating' => '1',
            ];
            $this->aggregateRatingResolved = true;

            return $this->aggregateRating;
        } catch (\Throwable) {
            $this->aggregateRatingResolved = true;

            return null;
        }
    }

    private function resolveCurrentCategory(Product $product, int $storeId): ?Category
    {
        if ($this->resolvedCurrentCategoryLoaded) {
            return $this->resolvedCurrentCategory;
        }

        $category = $this->registry->registry('current_category');
        if ($category instanceof Category && $category->getId()) {
            $this->resolvedCurrentCategory = $category;
            $this->resolvedCurrentCategoryLoaded = true;

            return $this->resolvedCurrentCategory;
        }

        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            $this->resolvedCurrentCategoryLoaded = true;

            return null;
        }

        foreach (array_reverse($categoryIds) as $categoryId) {
            try {
                /** @var Category $cat */
                $cat = $this->categoryRepository->get((int) $categoryId, $storeId);
                if ($cat->getIsActive() && (int) $cat->getLevel() >= 2) {
                    $this->resolvedCurrentCategory = $cat;
                    $this->resolvedCurrentCategoryLoaded = true;

                    return $this->resolvedCurrentCategory;
                }
            } catch (NoSuchEntityException) {
                continue;
            }
        }

        $this->resolvedCurrentCategoryLoaded = true;

        return null;
    }

    private function getProductImageUrl(Product $product): string
    {
        if ($this->productImageUrlResolved) {
            return $this->productImageUrl;
        }

        $this->productImageUrl = (string) $this->imageHelper->init($product, 'product_page_image_medium')->getUrl();
        $this->productImageUrlResolved = true;

        return $this->productImageUrl;
    }

    private function extractRatingValue(mixed $ratingSummary): float
    {
        if (is_numeric($ratingSummary)) {
            return (float) $ratingSummary;
        }

        if ($ratingSummary instanceof \Magento\Framework\DataObject) {
            $summary = $ratingSummary->getData('rating_summary');

            return is_numeric($summary) ? (float) $summary : 0.0;
        }

        return 0.0;
    }

    private function extractIntValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function resolveBrandName(Product $product): string
    {
        try {
            $brand = $product->getAttributeText('manufacturer') ?: $product->getAttributeText('marca');
            $brand = is_scalar($brand) ? (string) $brand : '';
            $normalized = $this->normalizeText($brand);

            // O atributo 'manufacturer' contém marcas de moto (fitment), não a marca do produto.
            if ($normalized === '' || in_array($normalized, self::MOTO_BRANDS, true)) {
                return 'AWA Motos';
            }

            return $normalized;
        } catch (\Throwable) {
            return 'AWA Motos';
        }
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
