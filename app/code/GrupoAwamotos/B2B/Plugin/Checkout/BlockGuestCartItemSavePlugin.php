<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Checkout;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Helper\Config;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Api\Data\CartItemInterface;

/**
 * HOTFIX B2B-AUTH (13/10): gate central do CartItem (REST /V1/carts/mine/items).
 * A identidade vem do UserContextInterface (o contexto seguro do canal REST),
 * nunca de IDs do navegador. Adminhtml e pulado (backoffice confiavel).
 */
class BlockGuestCartItemSavePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly PriceVisibilityInterface $priceVisibility,
        private readonly UserContextInterface $userContext,
        private readonly State $state
    ) {
    }

    /**
     * @throws CouldNotSaveException
     */
    public function beforeSave(object $subject, CartItemInterface $cartItem): array
    {
        if ($this->state->getAreaCode() === Area::AREA_ADMINHTML) {
            return [$cartItem];
        }

        if (!$this->config->isEnabled()) {
            return [$cartItem];
        }

        $allowed = $this->userContext->getUserType() === UserContextInterface::USER_TYPE_CUSTOMER
            ? $this->priceVisibility->canAddToCartForCustomer((int) $this->userContext->getUserId())
            : $this->priceVisibility->canAddToCart();

        if ($allowed) {
            return [$cartItem];
        }

        throw new CouldNotSaveException(
            __('Sua conta não está autorizada a adicionar produtos ao carrinho. Verifique a aprovação da sua empresa.')
        );
    }
}