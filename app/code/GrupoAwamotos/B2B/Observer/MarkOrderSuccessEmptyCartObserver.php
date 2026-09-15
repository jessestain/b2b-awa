<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\ViewModel\Cart\EmptyCartContext;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Marca a sessão para a tela de confirmação no carrinho vazio.
 *
 * Sem esta flag, Magento SuccessValidator e lastRealOrder permanecem na sessão
 * após um pedido real e o fallback de 6h reabria "Pedido confirmado" ao esvaziar
 * um carrinho de teste.
 */
class MarkOrderSuccessEmptyCartObserver implements ObserverInterface
{
    public function __construct(
        private readonly EmptyCartContext $emptyCartContext
    ) {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $order = $event->getOrder();
        $orderId = 0;

        if (is_object($order) && method_exists($order, 'getId') && $order->getId()) {
            $orderId = (int) $order->getId();
        } elseif (is_array($event->getOrderIds()) && $event->getOrderIds() !== []) {
            $orderId = (int) reset($event->getOrderIds());
        }

        $this->emptyCartContext->markOrderSuccessEmptyCart($orderId);
    }
}
