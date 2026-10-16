<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Checkout;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Helper\Config;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

/**
 * HOTFIX B2B-AUTH (13/10): ponto central para caminhos que escrevem no quote via
 * Cart::addProduct (shopping list, quickorder, custom) — mesma politica central.
 */
class BlockCartAddProductPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly PriceVisibilityInterface $priceVisibility,
        private readonly State $state
    ) {
    }

    public function beforeAddProduct(
        \Magento\Checkout\Model\Cart $subject,
        $productInfo,
        $requestInfo = null
    ): array {
        if ($this->state->getAreaCode() === Area::AREA_ADMINHTML) {
            return [$productInfo, $requestInfo];
        }

        if ($this->config->isEnabled() && !$this->priceVisibility->canAddToCart()) {
            throw new LocalizedException(
                $this->priceVisibility->isApprovedPendingErp()
                    ? __('Sua tabela de preços está sendo definida. Consulte o departamento de vendas.')
                    : __('Sua conta está pendente de aprovação. Você receberá um e-mail assim que for aprovada.')
            );
        }

        return [$productInfo, $requestInfo];
    }
}