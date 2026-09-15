<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Checkout;

use GrupoAwamotos\B2B\ViewModel\Cart\EmptyCartContext;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Result\Page;

/**
 * Título/H1 coerente com o estado: "Pedido confirmado" pós-checkout, "Carrinho vazio" caso contrário.
 *
 * Dependências pesadas injetadas via Proxy (di.xml) — plugin roda em todas as páginas,
 * mas só instancia sessão/ViewModel nas rotas de carrinho e sucesso.
 */
class CartEmptyPageTitlePlugin
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly HttpRequest $request,
        private readonly EmptyCartContext $emptyCartContext
    ) {
    }

    public function beforeRenderResult(Page $result, ResponseInterface $response): void
    {
        $action = $this->request->getFullActionName();

        if ($action === 'checkout_onepage_success') {
            $orderId = 0;
            try {
                $order = $this->checkoutSession->getLastRealOrder();
                if ($order && $order->getId()) {
                    $orderId = (int) $order->getId();
                }
            } catch (\Exception) {
                $orderId = 0;
            }
            // Sessão direta: o Proxy gerado de EmptyCartContext (3 set) não
            // encaminha markOrderSuccessEmptyCart(); chamar o método no Proxy
            // acessa $checkoutSession não inicializado e derruba a success page.
            $this->checkoutSession->setData(EmptyCartContext::SESSION_KEY_ORDER_SUCCESS_EMPTY, true);
            if ($orderId > 0) {
                $this->checkoutSession->setData(EmptyCartContext::SESSION_KEY_ORDER_SUCCESS_ID, $orderId);
            }
            $result->getConfig()->getTitle()->set(__('Pedido confirmado'));
            return;
        }

        if ($action !== 'checkout_cart_index') {
            return;
        }

        try {
            if ($this->checkoutSession->getQuote()->hasItems()) {
                return;
            }
        } catch (\Exception) {
            return;
        }

        if ($this->emptyCartContext->isOrderSuccessState()) {
            $result->getConfig()->getTitle()->set(__('Pedido confirmado'));
            return;
        }

        $result->getConfig()->getTitle()->set(__('Carrinho vazio'));
    }
}
