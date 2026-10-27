<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\AiAssistant\Model\Pricing\CustomerPriceResolver;
use GrupoAwamotos\WhatsAppCommerce\Api\CatalogInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Busca de catálogo para o assistente.
 *
 * 1) Frase exata via WhatsAppCommerce CatalogInterface
 * 2) OpenSearch multi_match (OR) — mesmo índice do storefront
 * 3) Fallback EAV tokenizado com ranking por termos no nome
 */
class CatalogSearchTool implements ToolInterface
{
    private const STOP_WORDS = [
        'de', 'da', 'do', 'das', 'dos', 'para', 'com', 'uma', 'um', 'o', 'a', 'e', 'ou',
        'the', 'and', 'or', 'tem', 'quero', 'preciso', 'busca', 'buscar', 'produto',
    ];

    public function __construct(
        private readonly CatalogInterface $catalog,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurlFactory $curlFactory,
        private readonly CustomerPriceResolver $priceResolver,
    ) {
    }

    public function getName(): string
    {
        return 'catalog_search';
    }

    public function getDescription(): string
    {
        return 'Busca produtos no catálogo da AWA Motos por nome, modelo, SKU ou palavras-chave. '
            . 'Use termos curtos e relevantes (ex.: "manete freio CG 160", "óleo 20W50"). '
            . 'Se a peça exata não existir, retorna itens relacionados para você sugerir alternativas.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Termo de busca (nome do produto, modelo de moto, SKU ou palavra-chave).',
                ],
                'page' => [
                    'type'        => 'integer',
                    'description' => 'Número da página de resultados (padrão: 1).',
                    'default'     => 1,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $page  = max(1, (int) ($arguments['page'] ?? 1));

        if ($query === '') {
            return ['error' => 'Informe um termo de busca.'];
        }

        $strategy = 'phrase';
        $result   = $this->filterRelevantProducts($query, $this->catalog->search($query, $page));

        if ($this->productCount($result) === 0) {
            $strategy = 'opensearch';
            $result   = $this->filterRelevantProducts($query, $this->searchOpenSearch($query, $page));
        }

        if ($this->productCount($result) === 0) {
            $strategy = 'tokenized';
            $result   = $this->filterRelevantProducts($query, $this->searchTokenized($query, $page));
        }

        if (is_array($result)) {
            $result['strategy'] = $strategy;
        }

        // A5: cliente B2B logado com tabela própria recebe o preço da lista dele.
        if (is_array($result) && !empty($result['products']) && is_array($result['products'])) {
            $this->applyCustomerPrices($result['products'], $context);
        }

        return $result;
    }

    /**
     * Aplica preços da tabela ERP (Sectra) aos produtos, quando existir preço
     * próprio para o cliente logado. Sem preço próprio, mantém o catálogo.
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed> $context
     */
    private function applyCustomerPrices(array &$products, array $context): void
    {
        $skus = [];
        foreach ($products as $product) {
            $sku = trim((string) ($product['sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
        $resolved = $this->priceResolver->resolvePrices($context, $skus);
        if ($resolved === []) {
            return;
        }
        foreach ($products as &$product) {
            $sku = trim((string) ($product['sku'] ?? ''));
            if ($sku === '' || !isset($resolved[$sku])) {
                continue;
            }
            $product['price'] = $resolved[$sku]['price'];
            $product['price_formatted'] = $resolved[$sku]['price_formatted'];
            $product['price_source'] = $resolved[$sku]['price_source'];
            $product['price_note'] = $resolved[$sku]['price_note'];
        }
        unset($product);
    }

    /**
     * Reject industrial products that happen to share words with motorcycle consumables.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function filterRelevantProducts(string $query, array $result): array
    {
        $lowerQuery = mb_strtolower($query);
        if (preg_match('/\b(óleo|oleo)\b/u', $lowerQuery) !== 1) {
            return $result;
        }

        preg_match_all('/\b\d{1,2}w\d{2}\b/u', $lowerQuery, $matches);
        $viscosities = array_values(array_unique($matches[0] ?? []));
        preg_match_all('/\b([24])t\b/u', $lowerQuery, $cycleMatches);
        $cycles = array_values(array_unique($cycleMatches[1] ?? []));
        $products = array_filter(
            $result['products'] ?? [],
            static function (array $product) use ($viscosities, $cycles): bool {
                $haystack = mb_strtolower(
                    (string) ($product['name'] ?? '') . ' ' . (string) ($product['short_description'] ?? '')
                );
                if (preg_match('/\b(transformador|kva|trifásico|trifasico|monofásico|monofasico|tensão|tensao)\b/u', $haystack) === 1) {
                    return false;
                }
                foreach ($viscosities as $viscosity) {
                    if (!str_contains($haystack, $viscosity)) {
                        return false;
                    }
                }
                foreach ($cycles as $cycle) {
                    if (preg_match('/\b' . $cycle . '(?:t|\s*tempos?)\b/u', $haystack) !== 1) {
                        return false;
                    }
                }
                if ($viscosities !== [] || $cycles !== []) {
                    return true;
                }

                return preg_match(
                    '/\b(moto|motocicleta|motor|lubrificante|motul|mobil|castrol|yamalube|\d{1,2}w\d{2}|[24]t)\b/u',
                    $haystack
                ) === 1;
            }
        );

        $result['products'] = array_values($products);
        $result['total'] = count($result['products']);
        $result['pages'] = 1;

        return $result;
    }

    public function getAllowedChannels(): array
    {
        return [
            AssistantOrchestratorInterface::CHANNEL_STOREFRONT,
            AssistantOrchestratorInterface::CHANNEL_B2B,
            AssistantOrchestratorInterface::CHANNEL_ADMIN,
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function productCount(array $result): int
    {
        return count($result['products'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function searchOpenSearch(string $query, int $page): array
    {
        $limit  = 8;
        $storeId = (int) $this->storeManager->getStore()->getId();
        $prefix = (string) ($this->scopeConfig->getValue(
            'catalog/search/opensearch_index_prefix',
            ScopeInterface::SCOPE_STORE
        ) ?: 'magento2');
        $host = (string) ($this->scopeConfig->getValue(
            'catalog/search/opensearch_server_hostname',
            ScopeInterface::SCOPE_STORE
        ) ?: 'localhost');
        $port = (int) ($this->scopeConfig->getValue(
            'catalog/search/opensearch_server_port',
            ScopeInterface::SCOPE_STORE
        ) ?: 9201);
        $scheme = (string) ($this->scopeConfig->getValue(
            'catalog/search/opensearch_server_scheme',
            ScopeInterface::SCOPE_STORE
        ) ?: 'http');

        $index = sprintf('%s_product_%d', $prefix, $storeId);
        $url   = sprintf('%s://%s:%d/%s/_search', $scheme, $host, $port, $index);

        $payload = [
            'from'  => ($page - 1) * $limit,
            'size'  => $limit,
            'query' => [
                'multi_match' => [
                    'query'    => $query,
                    'fields'   => ['name^5', 'sku^3', 'description', 'short_description'],
                    'type'     => 'best_fields',
                    'operator' => 'or',
                    'fuzziness'=> 'AUTO',
                ],
            ],
        ];

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(5);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post($url, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            if ($curl->getStatus() !== 200) {
                return ['products' => [], 'total' => 0, 'page' => $page, 'pages' => 1];
            }
            $body = json_decode($curl->getBody(), true);
            if (!is_array($body)) {
                return ['products' => [], 'total' => 0, 'page' => $page, 'pages' => 1];
            }
            $hits = $body['hits']['hits'] ?? [];
            $total = (int) ($body['hits']['total']['value'] ?? $body['hits']['total'] ?? 0);
            $skus = [];
            foreach ($hits as $hit) {
                $sku = (string) ($hit['_source']['sku'] ?? '');
                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }
            $products = $this->loadProductsBySkus($skus);
            return [
                'products' => $products,
                'total'    => $total > 0 ? $total : count($products),
                'page'     => $page,
                'pages'    => max(1, (int) ceil(max($total, 1) / $limit)),
            ];
        } catch (\Throwable $e) {
            return ['products' => [], 'total' => 0, 'page' => $page, 'pages' => 1];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function searchTokenized(string $query, int $page): array
    {
        $terms = $this->tokenize($query);
        if ($terms === []) {
            return ['products' => [], 'total' => 0, 'page' => $page, 'pages' => 1];
        }

        $limit = 8;
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect([
            'name',
            'price',
            'special_price',
            'thumbnail',
            'url_key',
            'short_description',
            'marca_moto',
            'modelo_moto',
            'ano_moto',
        ]);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('visibility', ['in' => [
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_IN_SEARCH,
            Visibility::VISIBILITY_BOTH,
        ]]);
        $collection->addStoreFilter($this->storeManager->getStore()->getId());

        $filters = [];
        foreach ($terms as $term) {
            $filters[] = ['attribute' => 'name', 'like' => '%' . $term . '%'];
            $filters[] = ['attribute' => 'sku', 'like' => '%' . $term . '%'];
        }
        $collection->addAttributeToFilter($filters);
        $collection->setPageSize(40);
        $collection->setCurPage(1);
        $collection->addFinalPrice();

        $scored = [];
        foreach ($collection as $product) {
            $hay = mb_strtolower((string) $product->getName() . ' ' . (string) $product->getSku());
            $score = 0;
            foreach ($terms as $term) {
                if (str_contains($hay, mb_strtolower($term))) {
                    $score++;
                }
            }
            if ($score === 0) {
                continue;
            }
            $scored[] = ['score' => $score, 'product' => $this->formatProduct($product)];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $products = array_map(static fn(array $row) => $row['product'], array_slice($scored, ($page - 1) * $limit, $limit));
        $total = count($scored);

        return [
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'pages'    => max(1, (int) ceil(max($total, 1) / $limit)),
        ];
    }

    /**
     * @return string[]
     */
    private function tokenize(string $query): array
    {
        $parts = preg_split('/[\s,;\/|+]+/u', mb_strtolower(trim($query))) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) < 2) {
                continue;
            }
            if (in_array($part, self::STOP_WORDS, true)) {
                continue;
            }
            $terms[] = $part;
        }
        return array_values(array_unique($terms));
    }

    /**
     * @param string[] $skus
     * @return array<int, array<string, mixed>>
     */
    private function loadProductsBySkus(array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect([
            'name',
            'price',
            'special_price',
            'thumbnail',
            'url_key',
            'short_description',
            'marca_moto',
            'modelo_moto',
            'ano_moto',
        ]);
        $collection->addAttributeToFilter('sku', ['in' => $skus]);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addStoreFilter($this->storeManager->getStore()->getId());
        $collection->addFinalPrice();

        $bySku = [];
        foreach ($collection as $product) {
            $bySku[(string) $product->getSku()] = $this->formatProduct($product);
        }

        $ordered = [];
        foreach ($skus as $sku) {
            if (isset($bySku[$sku])) {
                $ordered[] = $bySku[$sku];
            }
        }
        return $ordered;
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     * @return array<string, mixed>
     */
    private function formatProduct($product): array
    {
        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
        $baseUrl  = $this->storeManager->getStore()->getBaseUrl();
        $thumbnail = $product->getThumbnail();
        $imageUrl = ($thumbnail && $thumbnail !== 'no_selection')
            ? $mediaUrl . 'catalog/product' . $thumbnail
            : '';

        $price = (float) $product->getFinalPrice();
        $originalPrice = (float) $product->getPrice();
        $hasDiscount = $originalPrice > $price && $price > 0;

        return [
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'price' => $price,
            'price_formatted' => 'R$ ' . number_format($price, 2, ',', '.'),
            'original_price' => $hasDiscount ? $originalPrice : null,
            'discount_percent' => $hasDiscount ? (int) round((1 - $price / $originalPrice) * 100) : null,
            'image_url' => $imageUrl,
            'url' => $baseUrl . $product->getUrlKey() . '.html',
            'short_description' => strip_tags((string) $product->getShortDescription()),
            'fitment' => $this->formatSafeFitment($product),
            'in_stock' => (bool) $product->isSalable(),
        ];
    }

    private function formatSafeFitment(Product $product): string
    {
        $model = trim((string) $product->getData('modelo_moto'));
        if ($model !== '') {
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($model)) ?: [];
            $tokens = array_values(array_filter(
                $tokens,
                static fn(string $token): bool => mb_strlen($token) >= 2
                    && !in_array($token, ['ano', 'anos', 'mod', 'modelo', 'modelos', 'moto', 'todos', 'todas'], true)
            ));
            $alphaTokens = array_values(array_filter(
                $tokens,
                static fn(string $token): bool => preg_match('/\p{L}/u', $token) === 1
            ));
            $numericTokens = array_values(array_filter(
                $tokens,
                static fn(string $token): bool => preg_match('/^\d+$/', $token) === 1
            ));
            $name = mb_strtolower((string) $product->getName());
            foreach ([$alphaTokens, $numericTokens] as $requiredGroup) {
                if ($requiredGroup === []) {
                    continue;
                }
                $groupMatchesName = false;
                foreach ($requiredGroup as $token) {
                    if (preg_match(
                        '/(?<![\p{L}\p{N}])' . preg_quote($token, '/') . '(?![\p{L}\p{N}])/u',
                        $name
                    ) === 1) {
                        $groupMatchesName = true;
                        break;
                    }
                }
                if (!$groupMatchesName) {
                    return '';
                }
            }
        }

        $parts = array_filter([
            trim((string) $product->getData('marca_moto')),
            $model,
            trim((string) $product->getData('ano_moto')),
        ], static fn(string $value): bool => $value !== '');

        return $parts !== [] ? implode(' · ', $parts) : '';
    }
}
