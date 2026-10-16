<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\ViewModel;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

final class OpenGraph implements ArgumentInterface
{
    private const DEFAULT_DESCRIPTION = 'AWA Motos — Especialistas em peças e acessórios para motos. Baús, bagageiros, retrovisores, luvas e muito mais. Entrega para todo o Brasil.';
    private const DEFAULT_IMAGE_PATH = '/media/logo/stores/1/awamotos-og-default.jpg';
    private const DEFAULT_IMAGE_WIDTH = '1200';
    private const DEFAULT_IMAGE_HEIGHT = '630';

    private ?Store $store = null;

    private ?string $baseUrl = null;

    private ?string $storeName = null;

    private ?string $currentUrl = null;

    private bool $currentProductResolved = false;

    private ?Product $currentProduct = null;

    private bool $currentCategoryResolved = false;

    private ?Category $currentCategory = null;

    private ?string $pageTitle = null;

    private ?string $pageDescription = null;

    private ?string $defaultImage = null;

    /**
     * @var array<int, string>
     */
    private array $productImageUrlCache = [];

    /**
     * @var array<string, string>|null
     */
    private ?array $metaData = null;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Registry $registry,
        private readonly PageConfig $pageConfig,
        private readonly ImageHelper $imageHelper,
        private readonly HttpRequest $request,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    public function getBaseUrl(): string
    {
        if ($this->baseUrl !== null) {
            return $this->baseUrl;
        }

        $this->baseUrl = rtrim($this->getStore()->getBaseUrl(), '/');

        return $this->baseUrl;
    }

    public function getStoreName(): string
    {
        if ($this->storeName !== null) {
            return $this->storeName;
        }

        $this->storeName = (string) $this->getStore()->getName();

        return $this->storeName;
    }

    public function getCurrentUrl(): string
    {
        if ($this->currentUrl !== null) {
            return $this->currentUrl;
        }

        $currentUrl = $this->urlBuilder->getCurrentUrl();

        $pos = strpos($currentUrl, '?');

        $this->currentUrl = $pos !== false ? substr($currentUrl, 0, $pos) : $currentUrl;

        return $this->currentUrl;
    }

    public function getCurrentProduct(): ?Product
    {
        if ($this->currentProductResolved) {
            return $this->currentProduct;
        }

        $product = $this->registry->registry('current_product') ?: $this->registry->registry('product');
        $this->currentProduct = $product instanceof Product ? $product : null;
        $this->currentProductResolved = true;

        return $this->currentProduct;
    }

    public function getCurrentCategory(): ?Category
    {
        if ($this->currentCategoryResolved) {
            return $this->currentCategory;
        }

        $category = $this->registry->registry('current_category');
        $this->currentCategory = $category instanceof Category ? $category : null;
        $this->currentCategoryResolved = true;

        return $this->currentCategory;
    }

    public function getPageTitle(): string
    {
        if ($this->pageTitle !== null) {
            return $this->pageTitle;
        }

        $this->pageTitle = $this->normalizeText((string) $this->pageConfig->getTitle()->get());

        return $this->pageTitle;
    }

    public function getPageDescription(): string
    {
        if ($this->pageDescription !== null) {
            return $this->pageDescription;
        }

        $description = $this->normalizeText((string) $this->pageConfig->getDescription());
        $this->pageDescription = $description !== '' ? $description : self::DEFAULT_DESCRIPTION;

        return $this->pageDescription;
    }

    public function getDefaultImage(): string
    {
        if ($this->defaultImage !== null) {
            return $this->defaultImage;
        }

        $this->defaultImage = $this->getBaseUrl() . self::DEFAULT_IMAGE_PATH;

        return $this->defaultImage;
    }

    public function getProductImageUrl(Product $product): string
    {
        $productId = (int) $product->getId();
        if ($productId > 0 && isset($this->productImageUrlCache[$productId])) {
            return $this->productImageUrlCache[$productId];
        }

        $imageUrl = (string) $this->imageHelper->init($product, 'product_base_image')->getUrl();
        if ($productId > 0) {
            $this->productImageUrlCache[$productId] = $imageUrl;
        }

        return $imageUrl;
    }

    public function isHomepage(): bool
    {
        return $this->request->getFullActionName() === 'cms_index_index';
    }

    /**
     * @return array<string, string>
     */
    public function getMetaData(): array
    {
        if ($this->metaData !== null) {
            return $this->metaData;
        }

        $title = $this->getPageTitle();
        $description = $this->getPageDescription();
        $image = $this->getDefaultImage();
        $storeName = $this->getStoreName() !== '' ? $this->getStoreName() : 'AWA Motos';

        // Home: título social de marca (não o <title> SEO longo)
        if ($this->isHomepage()) {
            $title = $storeName . ' — Peças e Acessórios para Motos';
        }

        $meta = [
            'type' => 'website',
            'title' => $title !== '' ? $title : 'AWA Motos | Peças e Acessórios para Motos',
            'description' => $description,
            'image' => $image,
            'image_width' => self::DEFAULT_IMAGE_WIDTH,
            'image_height' => self::DEFAULT_IMAGE_HEIGHT,
            'url' => $this->getCurrentUrl(),
            'site_name' => $storeName,
            'locale' => 'pt_BR',
            'image_alt' => $title !== '' ? $title : 'AWA Motos',
        ];

        $product = $this->getCurrentProduct();
        if ($product && $product->getId()) {
            $meta['type'] = 'product';
            $meta['title'] = $this->normalizeText((string) $product->getName()) ?: $meta['title'];
            $meta['description'] = $this->resolveProductDescription($product);
            $productImage = $this->getProductImageUrl($product);
            if ($productImage !== '') {
                $meta['image'] = $productImage;
                unset($meta['image_width'], $meta['image_height']);
            }
            $meta['image_alt'] = $meta['title'];

            $finalPrice = (float) $product->getFinalPrice();
            if ($finalPrice > 0) {
                $meta['price_amount'] = number_format($finalPrice, 2, '.', '');
                $meta['price_currency'] = 'BRL';
            }

            if ($product->isAvailable()) {
                $meta['availability'] = 'in stock';
            }

            $this->metaData = $meta;

            return $this->metaData;
        }

        $category = $this->getCurrentCategory();
        if ($category && $category->getId()) {
            $meta['title'] = $this->normalizeText((string) $category->getName()) ?: $meta['title'];
            $meta['description'] = $this->resolveCategoryDescription($category);
            $meta['image_alt'] = $meta['title'];

            $categoryImage = (string) $category->getImageUrl();
            if ($categoryImage !== '') {
                // ACHADO SEO-015: og:image precisa ser URL ABSOLUTA; getImageUrl() devolve
                // caminho relativo (/media/catalog/category/...), o que quebra o preview social.
                $meta['image'] = $this->toAbsoluteUrl($categoryImage);
                unset($meta['image_width'], $meta['image_height']);
            }
        }

        $this->metaData = $meta;

        return $this->metaData;
    }

    private function getStore(): Store
    {
        if ($this->store === null) {
            /** @var Store $store */
            $store = $this->storeManager->getStore();
            $this->store = $store;
        }

        return $this->store;
    }

    private function resolveProductDescription(Product $product): string
    {
        $description = $this->normalizeText(
            (string) ($product->getShortDescription() ?: $product->getDescription() ?: '')
        );

        if ($description !== '') {
            return mb_substr($description, 0, 200);
        }

        // Build product-specific fallback instead of store default
        $name = $this->normalizeText((string) $product->getName());
        $parts = [$name];

        $sku = (string) $product->getSku();
        if ($sku !== '') {
            $parts[] = "Cód. $sku";
        }

        $brand = $product->getAttributeText('manufacturer');
        if ($brand && is_string($brand)) {
            $parts[] = $brand;
        }

        $fallback = implode(' | ', $parts) . '. Compre na AWA Motos com entrega para todo Brasil.';

        return mb_substr($fallback, 0, 200);
    }

    private function resolveCategoryDescription(Category $category): string
    {
        $description = $this->normalizeText((string) ($category->getDescription() ?: ''));

        if ($description !== '') {
            return mb_substr($description, 0, 200);
        }

        return $this->getPageDescription();
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Garante URL absoluta para tags que exigem host (Open Graph / Twitter Card).
     */
    private function toAbsoluteUrl(string $url): string
    {
        if ($url === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return $this->getBaseUrl() . '/' . ltrim($url, '/');
    }
}
