<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\QuickCatalog;

use GrupoAwamotos\B2B\Model\ErpCodeResolver;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zend_Db_Expr;

/**
 * "Comprar novamente" — dados REAIS de pedidos, agregados por SKU por recência.
 *
 * - Fonte: sales_order_item ⨝ sales_order (customer_id da sessão,
 *   parent_item_id IS NULL, product_type='simple', estados de compra
 *   efetivada — whitelist pública) ⨝ catalog_product_entity (SKU ainda existe).
 * - Preço NUNCA vem do pedido: a página passa pelo CatalogItemPresenter
 *   (batch de preços da conta + fallback de catálogo); disponibilidade é a ATUAL.
 * - Skus de pedidos cujo produto sumiu do catálogo são omitidos (o total já os
 *   exclui — páginas nunca ficam curtas por isso).
 * - Query builders pequenos e privados; clamps/whitelist públicos e cobertos
 *   por teste puro (qa/run-pure-tests.php).
 */
class BuyAgainService
{
    /** @var string[] estados de pedido considerados "compra efetivada" */
    public const STATE_WHITELIST = ['processing', 'complete', 'closed'];

    /**
     * Inicializa o serviço com as dependências de catálogo e pedidos.
     *
     * @param ProductCollectionFactory $productCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resourceConnection
     * @param CatalogItemPresenter $presenter
     * @param ErpCodeResolver $erpCodeResolver
     * @param RequestInputNormalizer $inputNormalizer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly CatalogItemPresenter $presenter,
        private readonly ErpCodeResolver $erpCodeResolver,
        private readonly RequestInputNormalizer $inputNormalizer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Devolve os itens comprados anteriormente, ordenados por recência.
     *
     * @param array{customer_id:int,can_add:bool,allowed:bool} $context
     * @param int $page
     * @param int $limit
     * @return array<string,mixed>
     */
    public function getBuyAgain(array $context, int $page, int $limit): array
    {
        $customerId = (int) $context['customer_id'];
        $storeId = (int) $this->storeManager->getStore()->getId();
        $page = $this->inputNormalizer->clampPage($page);
        $limit = $this->inputNormalizer->clampLimit($limit);
        $offset = ($page - 1) * $limit;

        try {
            $connection = $this->resourceConnection->getConnection();
            $total = (int) $connection->fetchOne($this->buildCountSelect($connection, $customerId));
            $rows = $connection->fetchAll($this->buildPageSelect($connection, $customerId, $limit, $offset));
        } catch (\Throwable $exception) {
            $this->logger->error('[B2B QuickCatalog] Buy again listing failed.', [
                'customer_id' => $customerId,
                'exception' => $exception->getMessage(),
            ]);
            return [
                'success' => false,
                'reason' => 'error',
                'message' => (string) __('Não foi possível carregar os produtos agora.'),
            ];
        }

        if ($rows === []) {
            return $this->envelope([], $page, $limit, $total);
        }

        // rank por recência: product_id => posição na página (0-based)
        $rankById = [];
        $lastPurchasedById = [];
        foreach ($rows as $index => $row) {
            $productId = (int) $row['product_id'];
            $rankById[$productId] = (int) $index;
            $lastPurchasedById[$productId] = $this->formatLastPurchased($row['last_bought'] ?? null);
        }

        // Sem filtro de status/visibilidade: item comprado que saiu de linha
        // aparece "Indisponível". Os plugins globais de coleção (restrito/
        // exclusivo) continuam valendo.
        $products = $this->loadProducts(array_keys($rankById), $storeId);

        $erpCode = $this->resolveErpCode($customerId);
        $items = $this->presenter->present($products, $context, $erpCode, $storeId);

        $byId = [];
        foreach ($items as $item) {
            $byId[(int) $item['id']] = $item;
        }

        // Ordem final = rank de recência (a coleção carrega em ordem de entity_id).
        $ordered = [];
        foreach ($rankById as $productId => $rank) {
            if (!isset($byId[$productId])) {
                // Produto sumiu do catálogo entre as duas consultas — omitido.
                continue;
            }

            $byId[$productId]['last_purchased'] = $lastPurchasedById[$productId];
            $ordered[] = $byId[$productId];
        }

        return $this->envelope($ordered, $page, $limit, $total);
    }

    /**
     * Base comum dos selects (MESMOS filtros para página e total).
     *
     * @param AdapterInterface $connection
     * @param int $customerId
     * @return Select
     */
    private function buildBaseSelect(AdapterInterface $connection, int $customerId): Select
    {
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');

        return $connection->select()
            ->from(['soi' => $orderItemTable], [])
            ->join(['so' => $orderTable], 'so.entity_id = soi.order_id', [])
            ->join(['cpe' => $productTable], 'cpe.entity_id = soi.product_id', [])
            ->where('so.customer_id = ?', $customerId)
            ->where('soi.parent_item_id IS NULL')
            ->where('soi.product_type = ?', 'simple')
            ->where('so.state IN (?)', self::STATE_WHITELIST)
            ->where("soi.sku <> ''");
    }

    /**
     * Monta o select que conta os SKUs distintos comprados pelo cliente.
     *
     * @param AdapterInterface $connection
     * @param int $customerId
     * @return Select
     */
    private function buildCountSelect(AdapterInterface $connection, int $customerId): Select
    {
        return $this->buildBaseSelect($connection, $customerId)
            ->columns(['cnt' => new Zend_Db_Expr('COUNT(DISTINCT soi.sku)')]);
    }

    /**
     * Monta o select paginado dos SKUs comprados pelo cliente.
     *
     * @param AdapterInterface $connection
     * @param int $customerId
     * @param int $limit
     * @param int $offset
     * @return Select
     */
    private function buildPageSelect(AdapterInterface $connection, int $customerId, int $limit, int $offset): Select
    {
        return $this->buildBaseSelect($connection, $customerId)
            ->columns([
                'sku' => 'soi.sku',
                'last_bought' => new Zend_Db_Expr('MAX(so.created_at)'),
                'product_id' => new Zend_Db_Expr('MAX(soi.product_id)'),
                'max_item_id' => new Zend_Db_Expr('MAX(soi.item_id)'),
            ])
            ->group('soi.sku')
            // Tiebreaker determinístico: itens do MESMO pedido empatam em
            // recência; sem isto a paginação pode repetir/pular SKUs na borda.
            ->order([
                new Zend_Db_Expr('last_bought DESC'),
                new Zend_Db_Expr('max_item_id DESC'),
            ])
            ->limit($limit, $offset);
    }

    /**
     * Carrega os produtos da página atual com os atributos necessários.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int,\Magento\Catalog\Api\Data\ProductInterface>
     */
    private function loadProducts(array $productIds, int $storeId): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToFilter('entity_id', ['in' => $productIds]);
        $collection->addAttributeToSelect($this->presenter->selectAttributes());

        return $collection->getItems();
    }

    /**
     * Monta o envelope de resposta com itens e paginação.
     *
     * @param array<int,array<string,mixed>> $items
     * @param int $page
     * @param int $limit
     * @param int $total
     * @return array<string,mixed>
     */
    private function envelope(array $items, int $page, int $limit, int $total): array
    {
        return [
            'success' => true,
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $limit,
                'total' => $total,
            ],
            'applied' => [
                'view' => 'buyagain',
            ],
        ];
    }

    /**
     * Formata o created_at do pedido (UTC) para 'Y-m-d H:i:s' ou null.
     *
     * @param mixed $lastBought
     * @return string|null
     */
    private function formatLastPurchased(mixed $lastBought): ?string
    {
        if (!is_string($lastBought) || trim($lastBought) === '' || $lastBought === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime($lastBought);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Resolve o código ERP do cliente para o batch de preços.
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
