<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\GraphQl;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Model\Price\HiddenGraphQlPrice;
use Magento\CatalogGraphQl\Model\Resolver\Product\Price;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Redige o resolver GraphQL legado Product.price.
 */
class HideLegacyProductPricePlugin
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
        Price $subject,
        $result,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if ($this->priceVisibility->canViewPrices() || !is_array($result)) {
            return $result;
        }

        return HiddenGraphQlPrice::redactLegacyPrice($result);
    }
}
