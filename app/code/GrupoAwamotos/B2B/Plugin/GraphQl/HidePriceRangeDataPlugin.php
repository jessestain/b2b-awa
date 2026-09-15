<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\GraphQl;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\B2B\Model\Price\HiddenGraphQlPrice;
use Magento\CatalogGraphQl\Model\PriceRangeDataProvider;

/**
 * Impede vazamento de preço B2B no campo GraphQL price_range.
 */
class HidePriceRangeDataPlugin
{
    public function __construct(
        private readonly PriceVisibilityInterface $priceVisibility
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterPrepare(PriceRangeDataProvider $subject, array $result): array
    {
        if ($this->priceVisibility->canViewPrices()) {
            return $result;
        }

        return HiddenGraphQlPrice::emptyRange();
    }
}
