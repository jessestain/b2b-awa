<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\AccountKeyProvider;
use GrupoAwamotos\B2B\Model\QuickCatalog\AuthorizationService;
use GrupoAwamotos\B2B\Model\QuickCatalog\CartService;
use GrupoAwamotos\B2B\Model\QuickCatalog\CategoryTreeProvider;
use GrupoAwamotos\B2B\Model\QuickCatalog\ItemBatchNormalizer;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Bloco da página "Comprar produtos".
 *
 * Renderização progressiva: categorias e resumo do carrinho saem prontos do
 * servidor; a lista de produtos (24/página) é carregada por fetch no servidor.
 * O JS recebe apenas configuração e mensagens — dados de catálogo/preço nunca
 * são embutidos para todas as contas.
 */
class Index extends Template
{
    /**
     * Inicializa o bloco com as dependências do catálogo de compra rápida.
     *
     * @param Context $context
     * @param FormKey $formKey
     * @param AuthorizationService $authorizationService
     * @param CategoryTreeProvider $categoryTreeProvider
     * @param CartService $cartService
     * @param AccountKeyProvider $accountKeyProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly FormKey $formKey,
        private readonly AuthorizationService $authorizationService,
        private readonly CategoryTreeProvider $categoryTreeProvider,
        private readonly CartService $cartService,
        private readonly AccountKeyProvider $accountKeyProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Retorna o contexto de autorização da conta atual.
     *
     * @return array{allowed:bool,reason:string|null,message:string,customer_id:int,can_view_prices:bool,can_add:bool}
     */
    public function getGate(): array
    {
        return $this->authorizationService->resolve();
    }

    /**
     * Indica se a conta atual pode acessar o catálogo de compra rápida.
     *
     * @return bool
     */
    public function isAllowed(): bool
    {
        return (bool) ($this->getGate()['allowed'] ?? false);
    }

    /**
     * Retorna a árvore de categorias exibida na navegação lateral.
     *
     * @return array<int,array{id:int,name:string,children:array<int,array{id:int,name:string}>}>
     */
    public function getCategories(): array
    {
        if (!$this->isAllowed()) {
            return [];
        }

        return $this->categoryTreeProvider->getTree();
    }

    /**
     * Retorna o snapshot inicial do carrinho exibido no painel.
     *
     * @return array<string,mixed>
     */
    public function getInitialCart(): array
    {
        if (!$this->isAllowed()) {
            return [
                'items_count' => 0,
                'total_units' => 0.0,
                'distinct_skus' => 0,
                'items' => [],
                'subtotal' => '',
                'subtotal_value' => 0.0,
                'shipping_unknown' => true,
            ];
        }

        return $this->cartService->snapshot();
    }

    /**
     * Chave pública da conta (escopo do estado local do frontend).
     *
     * @return string
     */
    public function getAccountKey(): string
    {
        $customerId = (int) ($this->getGate()['customer_id'] ?? 0);

        return $this->accountKeyProvider->getAccountKey($customerId);
    }

    /**
     * JSON inicial seguro para embutir em <script type="application/json">.
     *
     * @return string
     */
    public function getClientConfigJson(): string
    {
        $gate = $this->getGate();
        $initialCart = $this->getInitialCart();

        $config = [
            'endpoints' => [
                'search' => $this->getUrl('b2b/quickcatalog/search'),
                'add' => $this->getUrl('b2b/quickcatalog/add'),
                'cart' => $this->getUrl('b2b/quickcatalog/cart'),
                'buyagain' => $this->getUrl('b2b/quickcatalog/buyagain'),
                'favorite' => $this->getUrl('b2b/quickcatalog/favorite'),
            ],
            'formKey' => $this->formKey->getFormKey(),
            'accountKey' => $this->getAccountKey(),
            'pageSize' => 24,
            'maxQty' => ItemBatchNormalizer::MAX_QTY_PER_SKU,
            'initialCart' => [
                'items_count' => (int) $initialCart['items_count'],
                'total_units' => (float) $initialCart['total_units'],
                'distinct_skus' => (int) $initialCart['distinct_skus'],
                'items' => $initialCart['items'],
                'subtotal' => (string) $initialCart['subtotal'],
                'subtotal_value' => (float) $initialCart['subtotal_value'],
                'shipping_unknown' => true,
            ],
            'urls' => [
                'cart' => $this->getUrl('checkout/cart'),
                'dashboard' => $this->getUrl('b2b/account/dashboard'),
                'orders' => $this->getUrl('b2b/account/orders'),
                'account' => $this->getUrl('b2b/account'),
                'home' => $this->getUrl(''),
            ],
            'gate' => [
                'allowed' => (bool) $gate['allowed'],
                'reason' => (string) ($gate['reason'] ?? ''),
            ],
            'messages' => $this->getMessages(),
        ];

        $json = json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return is_string($json) ? $json : '{}';
    }

    /**
     * Retorna as mensagens de interface embutidas na configuração do cliente.
     *
     * @return array<string,string>
     */
    private function getMessages(): array
    {
        return [
            'loading' => (string) __('Carregando produtos…'),
            'empty' => (string) __('Nenhum produto encontrado para esta busca.'),
            'searchLabel' => (string) __('Buscar produtos'),
            'searchPlaceholder' => (string) __('Nome, SKU ou aplicação'),
            'addButton' => (string) __('Adicionar'),
            'addSelected' => (string) __('Adicionar selecionados'),
            'configureButton' => (string) __('Configurar'),
            'selectedBadge' => (string) __('Selecionado'),
            'addedBadge' => (string) __('Adicionado'),
            'clearSelection' => (string) __('Limpar seleção'),
            'reviewOrder' => (string) __('Revisar pedido'),
            'cartTitle' => (string) __('Resumo do carrinho'),
            'cartEmpty' => (string) __('Seu carrinho está vazio.'),
            'cartDistinct' => (string) __('%1 produto(s) diferente(s)'),
            'cartUnits' => (string) __('%1 unidade(s) no total'),
            'subtotal' => (string) __('Subtotal'),
            'shippingPending' => (string) __('Frete e demais valores são calculados no checkout.'),
            'resultsFound' => (string) __('%1 produto(s) encontrado(s)'),
            'pageOf' => (string) __('Página %1 de %2'),
            'prevPage' => (string) __('Página anterior'),
            'nextPage' => (string) __('Próxima página'),
            'qtyLabel' => (string) __('Quantidade de %1'),
            'qtyShort' => (string) __('Qtd.'),
            'selectHint' => (string) __('Digite a quantidade. Nada é adicionado automaticamente.'),
            'networkError' => (string) __('Falha de conexão. Vamos conferir o carrinho antes de tentar novamente.'),
            'addUnconfirmed' => (string) __(
                'Não foi possível confirmar a adição. Abra Revisar carrinho e confira as '
                . 'quantidades antes de adicionar novamente.'
            ),
            'addError' => (string) __('Não foi possível adicionar: %1'),
            'addPartial' => (string) __('Alguns itens não puderam ser adicionados.'),
            'addSuccess' => (string) __('%1 item(ns) adicionado(s) ao carrinho.'),
            'reconciled' => (string) __('Confirmamos o estado do seu carrinho antes de continuar.'),
            'priceUnavailable' => (string) __('Preço indisponível'),
            'availabilityPending' => (string) __('Disponibilidade a confirmar'),
            'notAddable' => (string) __('Indisponível para compra'),
            'requiredConfig' => (string) __('Requer configuração na página do produto'),
            'exclusive' => (string) __('Exclusivo da sua conta'),
            'sortLabel' => (string) __('Ordenar por'),
            'sortNameAsc' => (string) __('Nome (A–Z)'),
            'sortNameDesc' => (string) __('Nome (Z–A)'),
            'allProducts' => (string) __('Todos os produtos'),
            'tabAllProducts' => (string) __('Todos os produtos'),
            'tabFavorites' => (string) __('Favoritos'),
            'tabBuyAgain' => (string) __('Comprar novamente'),
            'favoriteAdd' => (string) __('Marcar como favorito'),
            'favoriteRemove' => (string) __('Remover dos favoritos'),
            'favoriteAdded' => (string) __('Produto adicionado aos favoritos.'),
            'favoriteRemoved' => (string) __('Produto removido dos favoritos.'),
            'favoriteError' => (string) __('Não foi possível atualizar o favorito.'),
            'favoritesEmpty' => (string) __('Você ainda não marcou produtos como favoritos.'),
            'buyAgainEmpty' => (string) __('Você ainda não comprou produtos para repetir.'),
            'emptyCta' => (string) __('Ver todos os produtos'),
            'lastPurchased' => (string) __('Comprado em %1'),
            'buyAgainHint' => (string) __('Itens dos seus pedidos, com preço e disponibilidade atuais.'),
            'tabsLabel' => (string) __('Perspectivas do catálogo'),
        ];
    }
}
