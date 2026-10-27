<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use GrupoAwamotos\B2B\Model\ErpCodeResolver;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listagem do Catálogo de Compra Rápida — busca/filtros/paginação no SERVIDOR.
 *
 * - Nunca devolve o catálogo inteiro: página de 24 produtos (teto 48).
 * - Busca por nome e SKU via SQL no servidor (catálogo de 609 ativos; sem
 *   dependência do índice de busca externo nesta fase — decisão documentada).
 * - Perspectivas: view=catalog (padrão) e view=favorites (entidade IN favoritos
 *   do cliente; busca/categoria/sort/paginação continuam valendo).
 * - A montagem da linha compacta (preços batch, disponibilidade, imagem,
 *   exclusive, favorite) é delegada ao CatalogItemPresenter — mesmo JSON da
 *   Fase 1 acrescido de "favorite".
 * - Regras de catálogo restrito/exclusivo continuam sendo aplicadas pelos
 *   plugins globais de coleção do módulo (não reimplementadas aqui).
 */
class ProductListService
{
    public const VIEW_CATALOG = 'catalog';
    public const VIEW_FAVORITES = 'favorites';

    private const DEFAULT_PAGE_SIZE = 24;
    private const MAX_PAGE_SIZE = 48;
    private const MAX_QUERY_LENGTH = 100;

    /**
     * Inicializa o serviço com as dependências de coleção e catálogo.
     *
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CategoryRepositoryInterface $categoryRepository
     * @param StoreManagerInterface $storeManager
     * @param ErpCodeResolver $erpCodeResolver
     * @param CatalogItemPresenter $presenter
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ErpCodeResolver $erpCodeResolver,
        private readonly CatalogItemPresenter $presenter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Busca, filtra e pagina os produtos do catálogo no servidor.
     *
     * @param array{q?:string,cat?:int|null,page?:int,limit?:int,sort?:string,view?:string} $params
     * @param array{customer_id:int,can_add:bool,allowed:bool} $context
     * @return array<string,mixed>
     */
    public function search(array $params, array $context): array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        $query = trim(mb_substr((string) ($params['q'] ?? ''), 0, self::MAX_QUERY_LENGTH));
        $categoryId = isset($params['cat']) && (int) $params['cat'] > 0 ? (int) $params['cat'] : null;
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = (int) ($params['limit'] ?? self::DEFAULT_PAGE_SIZE);
        $limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $sort = (string) ($params['sort'] ?? 'relevance');
        $view = (string) ($params['view'] ?? self::VIEW_CATALOG);
        $view = $view === self::VIEW_FAVORITES ? self::VIEW_FAVORITES : self::VIEW_CATALOG;

        $customerId = (int) $context['customer_id'];

        $category = null;
        if ($categoryId !== null) {
            try {
                $category = $this->categoryRepository->get($categoryId, $storeId);
            } catch (\Throwable) {
                return $this->emptyError('invalid_category', (string) __('Categoria inválida.'));
            }

            if (!(int) $category->getIsActive()) {
                return $this->emptyError('invalid_category', (string) __('Categoria inválida.'));
            }
        }

        // Perspectiva Favoritos: filtra a coleção normal por entity_id IN favoritos.
        // Zero favoritos → lista vazia com total=0 SEM erro (estado amigável).
        $favoriteIds = [];
        if ($view === self::VIEW_FAVORITES) {
            $favoriteIds = $this->presenter->resolveFavoriteProductIds($customerId);

            if ($favoriteIds === []) {
                return [
                    'success' => true,
                    'items' => [],
                    'pagination' => [
                        'page' => $page,
                        'page_size' => $limit,
                        'total' => 0,
                    ],
                    'facets' => [
                        'categories' => [],
                    ],
                    'applied' => [
                        'q' => $query,
                        'cat' => $categoryId,
                        'sort' => $sort,
                        'view' => $view,
                    ],
                ];
            }
        }

        $collection = $this->buildCollection($query, $category, $storeId, $page, $limit, $sort, $favoriteIds);

        try {
            $total = (int) $collection->getSize();
            $products = $collection->getItems();
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Product listing failed.', [
                'customer_id' => $customerId,
                'exception' => $exception->getMessage(),
            ]);
            return [
                'success' => false,
                'reason' => 'error',
                'message' => (string) __('Não foi possível carregar os produtos agora.'),
            ];
        }

        $erpCode = $this->resolveErpCode($customerId);
        $items = $this->presenter->present($products, $context, $erpCode, $storeId);

        return [
            'success' => true,
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $limit,
                'total' => $total,
            ],
            'facets' => [
                // Fase 1: contagens por categoria não são calculadas (custo x ganho);
                // ver docs/PENDENCIAS.md.
                'categories' => [],
            ],
            'applied' => [
                'q' => $query,
                'cat' => $categoryId,
                'sort' => $sort,
                'view' => $view,
            ],
        ];
    }

    /**
     * Monta a coleção de produtos da página com os filtros aplicados.
     *
     * @param string $query
     * @param mixed $category
     * @param int $storeId
     * @param int $page
     * @param int $limit
     * @param string $sort
     * @param int[] $favoriteIds
     * @return Collection
     */
    private function buildCollection(
        string $query,
        mixed $category,
        int $storeId,
        int $page,
        int $limit,
        string $sort,
        array $favoriteIds = []
    ): Collection {
        $collection = $this->productCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('visibility', ['in' => [
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_IN_SEARCH,
            Visibility::VISIBILITY_BOTH,
        ]]);
        $collection->addAttributeToSelect($this->presenter->selectAttributes());

        if ($favoriteIds !== []) {
            $collection->addAttributeToFilter('entity_id', ['in' => $favoriteIds]);
        }

        if ($category !== null) {
            $collection->addCategoryFilter($category);
        }

        if ($query !== '') {
            $like = '%' . $this->escapeLike($query) . '%';
            $collection->addAttributeToFilter([
                ['attribute' => 'name', 'like' => $like],
                ['attribute' => 'sku', 'like' => $like],
            ]);
        }

        // Ordenação no servidor. Sem índice de relevância dedicado nesta fase,
        // "relevance" mapeia para nome ascendente (determinístico, estável).
        if ($sort === 'name_desc') {
            $collection->setOrder('name', 'DESC');
        } else {
            $collection->setOrder('name', 'ASC');
        }

        $collection->setPageSize($limit)->setCurPage($page);

        return $collection;
    }

    /**
     * Escapa os curingas do LIKE para uso seguro na busca.
     *
     * @param string $value
     * @return string
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Monta a resposta de erro amigável da listagem.
     *
     * @param string $reason
     * @param string $message
     * @return array{success:false,reason:string,message:string}
     */
    private function emptyError(string $reason, string $message): array
    {
        return [
            'success' => false,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    /**
     * Resolução do código ERP do cliente (fallback de preço via batch do presenter).
     *
     * @param int $customerId
     * @return int|null
     */
    private function resolveErpCode(int $customerId): ?int
    {
        if ($customerId <= 0) {
            return null;
        }

        try {
            return $this->erpCodeResolver->resolveForCustomerId($customerId);
        } catch (\Throwable $exception) {
            $this->logger->warning('[B2B QuickCatalog] ERP code resolution failed.', [
                'customer_id' => $customerId,
                'exception' => $exception->getMessage(),
            ]);
            return null;
        }
    }
}
