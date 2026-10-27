<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Montagem da linha compacta do Catálogo de Compra Rápida (JSON da Fase 1 + "favorite").
 *
 * Concentra a lógica que antes vivia em ProductListService e é reusada por
 * ProductListService (todas as perspectivas de busca) e BuyAgainService
 * (comprar novamente), SEM duplicação:
 * - batch de preços do ERP (1 chamada por página) + fallback de catálogo;
 * - disponibilidade ATUAL (isSaleable/estoque) — nunca a da época da compra;
 * - atributos candidatos (application/unit), imagem, priceBox, URL do produto;
 * - flags "exclusive" e "favorite" (favoritos: 1 query por página).
 *
 * Nunca usa preço de pedido: o preço é sempre o preço vigente da conta.
 */
class CatalogItemPresenter
{
    public const FAVORITE_TABLE = 'grupoawamotos_b2b_favorite';

    /** @var string[] candidatos de atributo de aplicação (não existe em produção hoje) */
    private const APPLICATION_ATTRIBUTES = ['aplicacao', 'application', 'b2b_application', 'aplicacao_moto'];

    /** @var string[] candidatos de atributo de unidade de venda */
    private const UNIT_ATTRIBUTES = ['unidade_venda', 'unidade', 'unit', 'b2b_unit', 'sale_unit'];

    /** @var array<string, bool>|null cache de atributos existentes */
    private ?array $attributePresence = null;

    /** @var bool|null cache de existência da tabela de favoritos (evita log em loop) */
    private ?bool $favoriteTableExists = null;

    /**
     * Inicializa o presenter com as dependências de catálogo e preço.
     *
     * @param StoreManagerInterface $storeManager
     * @param StockRegistryInterface $stockRegistry
     * @param ImageHelper $imageHelper
     * @param PriceHelper $priceHelper
     * @param CustomerPriceProvider $customerPriceProvider
     * @param ResourceConnection $resourceConnection
     * @param EavConfig $eavConfig
     * @param \Magento\Framework\Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ImageHelper $imageHelper,
        private readonly PriceHelper $priceHelper,
        private readonly CustomerPriceProvider $customerPriceProvider,
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly \Magento\Framework\Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Monta o JSON de cada produto de uma página (mesmo contrato da Fase 1 + "favorite").
     *
     * @param array<int,ProductInterface> $products
     * @param array{customer_id:int,can_add:bool} $context
     * @param int|null $erpCode
     * @param int $storeId
     * @return array<int,array<string,mixed>>
     */
    public function present(array $products, array $context, ?int $erpCode, int $storeId): array
    {
        $customerId = (int) $context['customer_id'];
        $canAdd = (bool) $context['can_add'];

        $customerPrices = $this->resolveCustomerPrices($erpCode, $products);
        $exclusiveIds = $this->resolveExclusiveProductIds($customerId);
        $favoriteIds = $this->resolveFavoriteIds($customerId, $products);

        $items = [];
        foreach ($products as $product) {
            $items[] = $this->buildItem(
                $product,
                $customerPrices,
                $exclusiveIds,
                $favoriteIds,
                $canAdd,
                $storeId
            );
        }

        return $items;
    }

    /**
     * Atributos necessários para montar a linha compacta (mesma lista da Fase 1).
     *
     * @return string[]
     */
    public function selectAttributes(): array
    {
        $base = ['name', 'sku', 'small_image', 'thumbnail', 'price', 'special_price', 'status', 'visibility'];

        foreach (array_merge(self::APPLICATION_ATTRIBUTES, self::UNIT_ATTRIBUTES) as $candidate) {
            if ($this->attributeExists($candidate)) {
                $base[] = $candidate;
            }
        }

        return $base;
    }

    /**
     * Todos os product_ids favoritados pelo cliente (perspectiva view=favorites).
     *
     * Tabela ausente (ex.: antes do setup:upgrade) → warning + lista vazia:
     * NUNCA quebra a listagem.
     *
     * @param int $customerId
     * @return int[]
     */
    public function resolveFavoriteProductIds(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        return $this->queryFavoriteIds($customerId, []);
    }

    /**
     * Favoritos do cliente restritos aos ids da página (1 query por página).
     *
     * @param int $customerId
     * @param array<int,ProductInterface> $products
     * @return int[]
     */
    private function resolveFavoriteIds(int $customerId, array $products): array
    {
        if ($customerId <= 0 || $products === []) {
            return [];
        }

        $productIds = [];
        foreach ($products as $product) {
            $productIds[] = (int) $product->getId();
        }

        return $this->queryFavoriteIds($customerId, $productIds);
    }

    /**
     * Consulta os ids favoritados do cliente, opcionalmente restritos à página.
     *
     * @param int $customerId
     * @param int[] $productIds vazio = todos os favoritos do cliente
     * @return int[]
     */
    private function queryFavoriteIds(int $customerId, array $productIds): array
    {
        if ($this->favoriteTableExists === false) {
            return [];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::FAVORITE_TABLE);

            if (!$connection->isTableExists($table)) {
                $this->favoriteTableExists = false;
                $this->logger->warning('[B2B QuickCatalog] Favorites table missing; favorite=false for all items.', [
                    'customer_id' => $customerId,
                    'table' => $table,
                ]);

                return [];
            }

            $this->favoriteTableExists = true;

            $select = $connection->select()
                ->from($table, ['product_id'])
                ->where('customer_id = ?', $customerId);

            if ($productIds !== []) {
                $select->where('product_id IN (?)', $productIds);
            }

            $ids = $connection->fetchCol($select);

            return array_map('intval', $ids);
        } catch (\Throwable $exception) {
            $this->favoriteTableExists = false;
            $this->logger->warning('[B2B QuickCatalog] Favorites lookup failed; favorite=false for all items.', [
                'customer_id' => $customerId,
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Monta a linha compacta de um produto para o JSON do catálogo.
     *
     * @param ProductInterface $product
     * @param array<string,float> $customerPrices
     * @param array<int,int> $exclusiveIds
     * @param array<int,int> $favoriteIds
     * @param bool $canAdd
     * @param int $storeId
     * @return array<string,mixed>
     */
    private function buildItem(
        ProductInterface $product,
        array $customerPrices,
        array $exclusiveIds,
        array $favoriteIds,
        bool $canAdd,
        int $storeId
    ): array {
        $sku = (string) $product->getSku();
        $customerPrice = $customerPrices[mb_strtolower($sku)] ?? null;
        $effectivePrice = $customerPrice !== null ? $customerPrice : (float) $product->getFinalPrice();

        [$saleable, $inStock, $availabilityLabel] = $this->resolveAvailability($product);

        $requiresConfig = (string) $product->getTypeId() !== 'simple'
            || $this->hasRequiredOptions($product);

        $addable = $canAdd && $saleable && !$requiresConfig;

        return [
            'id' => (int) $product->getId(),
            'sku' => $sku,
            'name' => (string) $product->getName(),
            'url' => $this->productUrl($product, $storeId),
            'image' => $this->imageUrl($product),
            'application' => $this->attributeText($product, self::APPLICATION_ATTRIBUTES),
            'unit' => $this->attributeText($product, self::UNIT_ATTRIBUTES),
            'price' => $this->priceBox($product, $effectivePrice),
            'availability' => [
                'saleable' => $saleable,
                'in_stock' => $inStock,
                'label' => $availabilityLabel,
            ],
            'qty' => [
                'min' => 1,
                'step' => 1,
                'max' => ItemBatchNormalizer::MAX_QTY_PER_SKU,
            ],
            'requires_config' => $requiresConfig,
            'addable' => $addable,
            'exclusive' => in_array((int) $product->getId(), $exclusiveIds, true),
            'favorite' => in_array((int) $product->getId(), $favoriteIds, true),
        ];
    }

    /**
     * Resolve disponibilidade, estoque e rótulo de exibição do produto.
     *
     * @param ProductInterface $product
     * @return array{0:bool,1:bool|null,2:string}
     */
    private function resolveAvailability(ProductInterface $product): array
    {
        $inStock = null;

        try {
            $stockItem = $this->stockRegistry->getStockItem((int) $product->getId());
            $inStock = (bool) $stockItem->getIsInStock();
        } catch (\Throwable $exception) {
            $this->logger->warning('[B2B QuickCatalog] Stock status unavailable.', [
                'sku' => (string) $product->getSku(),
                'exception' => $exception->getMessage(),
            ]);
        }

        $saleable = $product->isSaleable();

        if ($inStock === null) {
            return [false, null, (string) __('Disponibilidade a confirmar')];
        }

        if (!$inStock) {
            return [false, false, (string) __('Sem estoque')];
        }

        if (!$saleable) {
            return [false, true, (string) __('Indisponível')];
        }

        return [true, true, (string) __('Disponível')];
    }

    /**
     * Indica se o produto exige configuração antes de ir ao carrinho.
     *
     * @param ProductInterface $product
     * @return bool
     */
    private function hasRequiredOptions(ProductInterface $product): bool
    {
        try {
            if (!$product->getHasOptions()) {
                return false;
            }

            return $product->getTypeInstance()->hasRequiredOptions($product);
        } catch (\Throwable) {
            // Em dúvida, exige configuração na PDP (não inventa).
            return true;
        }
    }

    /**
     * Retorna o primeiro atributo candidato preenchido do produto.
     *
     * @param ProductInterface $product
     * @param string[] $candidates
     * @return string|null
     */
    private function attributeText(ProductInterface $product, array $candidates): ?string
    {
        foreach ($candidates as $code) {
            if (!$this->attributeExists($code)) {
                continue;
            }

            $value = $product->getData($code);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Monta o bloco de preço exibido no catálogo.
     *
     * @param ProductInterface $product
     * @param float $effectivePrice
     * @return array{html:string,value:float,source:string}
     */
    private function priceBox(ProductInterface $product, float $effectivePrice): array
    {
        $source = 'catalog';

        if ($effectivePrice <= 0) {
            return ['html' => '', 'value' => 0.0, 'source' => 'unavailable'];
        }

        if (abs($effectivePrice - (float) $product->getFinalPrice()) > 0.0001) {
            $source = 'customer';
        }

        $regularPrice = 0.0;
        try {
            $regularPrice = (float) $product->getPriceInfo()->getPrice('regular_price')->getValue();
        } catch (\Throwable) {
            $regularPrice = 0.0;
        }

        $formattedEffective = $this->escaper->escapeHtml(
            (string) $this->priceHelper->currency($effectivePrice, true, false)
        );

        if ($regularPrice > ($effectivePrice + 0.0001)) {
            $formattedCompare = $this->escaper->escapeHtml(
                (string) $this->priceHelper->currency($regularPrice, true, false)
            );

            return [
                'html' => '<div class="price-box price-final_price">'
                    . '<span class="old-price"><span class="price-container"><span class="price-wrapper">'
                    . '<span class="price">' . $formattedCompare . '</span></span></span></span>'
                    . '<span class="special-price"><span class="price-container"><span class="price-wrapper">'
                    . '<span class="price">' . $formattedEffective . '</span></span></span></span>'
                    . '</div>',
                'value' => $effectivePrice,
                'source' => $source,
            ];
        }

        return [
            'html' => '<div class="price-box price-final_price">'
                . '<span class="normal-price"><span class="price-container"><span class="price-wrapper">'
                . '<span class="price">' . $formattedEffective . '</span></span></span></span>'
                . '</div>',
            'value' => $effectivePrice,
            'source' => $source,
        ];
    }

    /**
     * Retorna a URL do produto na store informada.
     *
     * @param ProductInterface $product
     * @param int $storeId
     * @return string
     */
    private function productUrl(ProductInterface $product, int $storeId): string
    {
        try {
            if ($product instanceof Product) {
                $product->setStoreId($storeId);
            }

            return (string) $product->getProductUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Retorna a URL da imagem do produto ou null quando indisponível.
     *
     * @param ProductInterface $product
     * @return string|null
     */
    private function imageUrl(ProductInterface $product): ?string
    {
        try {
            $thumbnail = (string) $product->getData('thumbnail');

            if ($thumbnail !== '' && $thumbnail !== 'no_selection') {
                $store = $this->storeManager->getStore();

                return $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
                    . 'catalog/product' . $thumbnail;
            }

            if ($product instanceof Product) {
                return (string) $this->imageHelper->init($product, 'product_thumbnail_image')->getUrl();
            }
        } catch (\Throwable) {
            // sem imagem: o frontend exibe placeholder neutro (sem inventar foto)
            return null;
        }

        return null;
    }

    /**
     * Indica se o atributo informado existe no catálogo (com cache em memória).
     *
     * @param string $code
     * @return bool
     */
    private function attributeExists(string $code): bool
    {
        if ($this->attributePresence === null) {
            $this->attributePresence = [];
        }

        if (!array_key_exists($code, $this->attributePresence)) {
            try {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
                $this->attributePresence[$code] = $attribute !== null && $attribute->getId() !== null;
            } catch (\Throwable) {
                $this->attributePresence[$code] = false;
            }
        }

        return $this->attributePresence[$code];
    }

    /**
     * Uma única chamada batch ao provider de preços do ERP (nunca por produto).
     *
     * @param int|null $erpCode
     * @param array<int,ProductInterface> $products
     * @return array<string,float>
     */
    private function resolveCustomerPrices(?int $erpCode, array $products): array
    {
        if ($erpCode === null || $products === []) {
            return [];
        }

        $skus = [];
        foreach ($products as $product) {
            $skus[] = (string) $product->getSku();
        }

        try {
            $prices = $this->customerPriceProvider->getCustomerPrices($erpCode, $skus);
        } catch (\Throwable $exception) {
            // Mesmo fail-safe da vitrine (PDP/carrinho): ERP fora → preço de
            // catálogo; o checkout continua validando o preço comercial.
            $this->logger->warning('[B2B QuickCatalog] Batch customer prices failed; falling back to catalog prices.', [
                'erp_code' => $erpCode,
                'skus' => count($skus),
                'exception' => $exception->getMessage(),
            ]);
            return [];
        }

        $normalized = [];
        foreach ($prices as $sku => $price) {
            if ($price !== null && (float) $price > 0) {
                $normalized[mb_strtolower((string) $sku)] = (float) $price;
            }
        }

        return $normalized;
    }

    /**
     * Retorna os product_ids exclusivos do cliente.
     *
     * @param int $customerId
     * @return int[]
     */
    private function resolveExclusiveProductIds(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('grupoawamotos_b2b_exclusive_product');
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($table, ['product_id'])
                    ->where('customer_id = ?', $customerId)
            );

            return array_map('intval', $ids);
        } catch (\Throwable $exception) {
            $this->logger->warning('[B2B QuickCatalog] Exclusive product ids lookup failed.', [
                'customer_id' => $customerId,
                'exception' => $exception->getMessage(),
            ]);
            return [];
        }
    }
}
