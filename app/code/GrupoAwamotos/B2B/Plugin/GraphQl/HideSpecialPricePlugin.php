<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\GraphQl;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use Magento\CatalogGraphQl\Model\Resolver\Product\SpecialPrice;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Oculta special_price no GraphQL quando a tabela B2B não pode ser exibida.
 */
class HideSpecialPricePlugin
{
    public function __construct(
        private readonly PriceVisibilityInterface $priceVisibility
    ) {
    }

    /**
     * @param mixed $result
     * @param mixed $context
     * @param array<string, mixed>|null $value
     * @param array<string, mixed>|null $args
     * @return mixed
     */
    public function afterResolve(
        SpecialPrice $subject,
        $result,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if ($this->priceVisibility->canViewPrices()) {
            return $result;
        }

        return null;
    }
}
