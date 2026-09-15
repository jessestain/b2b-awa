<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer;

use GrupoAwamotos\B2B\ViewModel\Cart\EmptyCartContext;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Encerra a tela de confirmação quando o comprador volta a adicionar itens.
 */
class ClearOrderSuccessEmptyCartObserver implements ObserverInterface
{
    public function __construct(
        private readonly EmptyCartContext $emptyCartContext
    ) {
    }

    public function execute(Observer $observer): void
    {
        $this->emptyCartContext->clearOrderSuccessEmptyCart();
    }
}
