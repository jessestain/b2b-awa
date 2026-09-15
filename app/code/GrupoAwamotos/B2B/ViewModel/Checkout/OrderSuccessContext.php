<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\ViewModel\Checkout;

use GrupoAwamotos\B2B\Model\Checkout\OrderSuccessPresenter;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;

class OrderSuccessContext implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderSuccessPresenter $orderSuccessPresenter,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPresentation(): ?array
    {
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order instanceof Order || !$order->getId()) {
            return null;
        }

        return $this->orderSuccessPresenter->present($order);
    }

    public function getCatalogUrl(): string
    {
        return $this->urlBuilder->getUrl('catalogo');
    }

    public function getWhatsappUrl(): string
    {
        $presentation = $this->getPresentation();
        $orderRef = $presentation !== null ? $presentation['incrementId'] : '';
        $text = rawurlencode(
            (string) __(
                'Olá, acabei de fazer o pedido B2B %1 e preciso de ajuda.',
                $orderRef !== '' ? '#' . $orderRef : ''
            )
        );

        return 'https://wa.me/5516997367588?text=' . $text;
    }
}
