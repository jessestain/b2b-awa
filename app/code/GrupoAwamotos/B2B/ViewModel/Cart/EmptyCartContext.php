<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\ViewModel\Cart;

use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\Checkout\OrderSuccessPresenter;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Session\SuccessValidator;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

class EmptyCartContext implements ArgumentInterface
{
    public const SESSION_KEY_EXPRESS_REDIRECT = 'awa_express_checkout_redirect';

    public const COOKIE_KEY_EXPRESS_REDIRECT = 'awa_express_checkout_redirect';

    public const SESSION_KEY_ORDER_SUCCESS_EMPTY = 'awa_order_success_empty_cart';

    public const SESSION_KEY_ORDER_SUCCESS_ID = 'awa_order_success_empty_cart_order_id';

    private ?PriceCurrencyInterface $priceCurrency;

    /**
     * @var list<array{label: \Magento\Framework\Phrase, url: string}>
     */
    private const FEATURED_CATEGORIES = [
        ['label' => 'Bauletos', 'url' => 'bauletos'],
        ['label' => 'Retrovisores', 'url' => 'retrovisores'],
        ['label' => 'Bagageiros', 'url' => 'bagageiros'],
        ['label' => 'Manetes', 'url' => 'manetes'],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        private readonly CheckoutSession $checkoutSession,
        private readonly HttpRequest $request,
        private readonly PhpCookieManager $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly TimezoneInterface $timezone,
        private readonly SuccessValidator $successValidator,
        private readonly OrderSuccessPresenter $orderSuccessPresenter,
        private readonly LoggerInterface $logger,
        ?PriceCurrencyInterface $priceCurrency = null
    ) {
        $this->priceCurrency = $priceCurrency;
        // TimezoneInterface permanece no construtor para não quebrar factories compiladas
        // (não é lido em outro ponto da classe); a tipagem do parâmetro já garante o contrato.
    }

    public function getCatalogUrl(): string
    {
        return $this->urlBuilder->getUrl('catalogo');
    }

    public function getAllCategoriesUrl(): string
    {
        return $this->getCatalogUrl();
    }

    /**
     * @return list<array{label: \Magento\Framework\Phrase, url: string}>
     */
    public function getFeaturedCategories(): array
    {
        $categories = [];

        foreach (self::FEATURED_CATEGORIES as $category) {
            $categories[] = [
                'label' => __($category['label']),
                'url' => $this->urlBuilder->getUrl('', ['_direct' => $category['url']]),
            ];
        }

        return $categories;
    }

    public function shouldShowMinOrderHint(): bool
    {
        if ($this->isOrderSuccessState()) {
            return false;
        }

        return $this->config->isEnabled()
            && $this->config->isMinQtyEnabled()
            && $this->getMinOrderAmount() > 0;
    }

    public function getMinOrderAmount(): float
    {
        return $this->config->getMinOrderAmount();
    }

    public function getMinOrderAmountFormatted(): string
    {
        if ($this->priceCurrency === null) {
            return 'R$ ' . number_format($this->getMinOrderAmount(), 2, ',', '.');
        }

        return $this->priceCurrency->format($this->getMinOrderAmount(), false);
    }

    public function getMinOrderHint(): string
    {
        return (string) __(
            'Pedido mínimo B2B: %1. Adicione peças ao carrinho para seguir à finalização do pedido.',
            $this->getMinOrderAmountFormatted()
        );
    }

    public function shouldShowExpressCheckoutNotice(): bool
    {
        if ($this->isOrderSuccessState()) {
            return false;
        }

        if ($this->checkoutSession->getData(self::SESSION_KEY_EXPRESS_REDIRECT)) {
            return true;
        }

        return $this->request->getCookie(self::COOKIE_KEY_EXPRESS_REDIRECT) === '1';
    }

    public function consumeExpressCheckoutNotice(): bool
    {
        if (!$this->shouldShowExpressCheckoutNotice()) {
            return false;
        }

        $this->checkoutSession->unsetData(self::SESSION_KEY_EXPRESS_REDIRECT);
        $this->deleteExpressRedirectCookie();

        return true;
    }

    private function deleteExpressRedirectCookie(): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createCookieMetadata();
            $metadata->setPath('/');
            $this->cookieManager->deleteCookie(self::COOKIE_KEY_EXPRESS_REDIRECT, $metadata);
        } catch (\Exception $e) {
            $this->logger->debug(
                '[EmptyCartContext::deleteExpressRedirectCookie] Cookie ausente ou headers já enviados: '
                . $e->getMessage()
            );
        }
    }

    public function getExpressCheckoutNotice(): string
    {
        return (string) __(
            'Você veio da finalização expressa. Adicione peças ao carrinho para continuar.'
        );
    }

    public function getEmptyCartSubtitle(): string
    {
        if ($this->shouldShowMinOrderHint()) {
            return (string) __(
                'Explore o catálogo ou fale com nossa equipe comercial para montar seu pedido.'
            );
        }

        return (string) __(
            'Adicione peças ao carrinho para seguir à finalização do pedido B2B.'
        );
    }

    public function isOrderSuccessState(): bool
    {
        return $this->getOrderSuccessPresentation() !== null;
    }

    /**
     * Liga a tela de confirmação no carrinho vazio após um pedido recém-colocado.
     */
    public function markOrderSuccessEmptyCart(int $orderId = 0): void
    {
        $this->checkoutSession->setData(self::SESSION_KEY_ORDER_SUCCESS_EMPTY, true);

        if ($orderId > 0) {
            $this->checkoutSession->setData(self::SESSION_KEY_ORDER_SUCCESS_ID, $orderId);
        }
    }

    public function clearOrderSuccessEmptyCart(): void
    {
        $this->checkoutSession->unsetData(self::SESSION_KEY_ORDER_SUCCESS_EMPTY);
        $this->checkoutSession->unsetData(self::SESSION_KEY_ORDER_SUCCESS_ID);
    }

    /**
     * Dados para a tela de confirmação pós-pedido no carrinho vazio.
     *
     * @return array{
     *     incrementId: string,
     *     orderUrl: string,
     *     formattedDate: string,
     *     grandTotalFormatted: string,
     *     itemsCount: int,
     *     paymentLabel: string,
     *     headline: string,
     *     subline: string,
     *     nextStep: string
     * }|null
     */
    public function getOrderSuccessPresentation(): ?array
    {
        if ($this->quoteHasItems()) {
            $this->clearOrderSuccessEmptyCart();

            return null;
        }

        $order = $this->resolveSuccessOrder();
        if ($order === null) {
            return null;
        }

        return $this->orderSuccessPresenter->present($order);
    }

    /**
     * @deprecated Use getOrderSuccessPresentation() — mantido para compatibilidade interna.
     *
     * @return array{message: string, orderUrl: string, incrementId: string}|null
     */
    public function getRecentOrderContext(): ?array
    {
        $presentation = $this->getOrderSuccessPresentation();
        if ($presentation === null) {
            return null;
        }

        return [
            'message' => (string) __(
                'Seu pedido %1 foi registrado em %2.',
                $presentation['incrementId'],
                $presentation['formattedDate']
            ),
            'orderUrl' => $presentation['orderUrl'],
            'incrementId' => $presentation['incrementId'],
        ];
    }

    private function quoteHasItems(): bool
    {
        try {
            return $this->checkoutSession->getQuote()->hasItems();
        } catch (\Exception) {
            return false;
        }
    }

    private function resolveSuccessOrder(): ?Order
    {
        if (!$this->checkoutSession->getData(self::SESSION_KEY_ORDER_SUCCESS_EMPTY)) {
            return null;
        }

        if ($this->successValidator->isValid()) {
            $sessionOrder = $this->checkoutSession->getLastRealOrder();
            if ($sessionOrder instanceof Order && $sessionOrder->getId()) {
                return $sessionOrder;
            }
        }

        $orderId = (int) $this->checkoutSession->getData(self::SESSION_KEY_ORDER_SUCCESS_ID);
        if ($orderId <= 0) {
            return null;
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', $orderId)
            ->setPageSize(1);

        $order = $collection->getFirstItem();

        return $order->getId() ? $order : null;
    }
}
