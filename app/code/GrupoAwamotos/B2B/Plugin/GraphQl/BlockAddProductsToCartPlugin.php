<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\GraphQl;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Helper\Config;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Config\Element\Field;

/**
 * HOTFIX B2B-AUTH (13/10): gate dos resolvers GraphQL de adicionar ao carrinho.
 * Identidade: $context->getUserType()/getUserId() (o contexto GraphQL autenticado).
 */
class BlockAddProductsToCartPlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly PriceVisibilityInterface $priceVisibility
    ) {
    }

    public function beforeResolve(
        object $subject,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        if (!$this->config->isEnabled()) {
            return [$field, $context, $info, $value, $args];
        }

        $userType = method_exists($context, 'getUserType') ? (int) $context->getUserType() : 0;
        $userId = method_exists($context, 'getUserId') ? (int) $context->getUserId() : 0;

        $allowed = $userType === UserContextInterface::USER_TYPE_CUSTOMER
            ? $this->priceVisibility->canAddToCartForCustomer($userId)
            : $this->priceVisibility->canAddToCart();

        if (!$allowed) {
            throw new GraphQlInputException(
                __('Sua conta não está autorizada a adicionar produtos ao carrinho. Verifique a aprovação da sua empresa.')
            );
        }

        return [$field, $context, $info, $value, $args];
    }
}