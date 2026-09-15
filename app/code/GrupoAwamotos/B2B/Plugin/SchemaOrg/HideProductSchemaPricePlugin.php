<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\SchemaOrg;

use GrupoAwamotos\B2B\Api\PriceVisibilityInterface;
use GrupoAwamotos\SchemaOrg\Block\ProductSchema;

/**
 * Remove price/priceSpecification do JSON-LD quando o visitante não pode ver tabela.
 */
class HideProductSchemaPricePlugin
{
    public function __construct(
        private readonly PriceVisibilityInterface $priceVisibility
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetProductSchemaData(ProductSchema $subject, array $result): array
    {
        if ($result === [] || $this->priceVisibility->canViewPrices()) {
            return $result;
        }

        if (isset($result['offers']) && is_array($result['offers'])) {
            unset($result['offers']['price'], $result['offers']['priceSpecification']);
        }

        return $result;
    }
}
