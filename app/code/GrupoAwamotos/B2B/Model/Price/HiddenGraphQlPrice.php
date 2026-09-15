<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Price;

/**
 * Payload GraphQL sem preço comercial quando o visitante/pendente não pode ver tabela.
 */
final class HiddenGraphQlPrice
{
    /**
     * @return array{regular_price: array{value: null, currency: null}, final_price: array{value: null, currency: null}, discount: null}
     */
    public static function emptySlot(): array
    {
        return [
            'regular_price' => [
                'value' => null,
                'currency' => null,
            ],
            'final_price' => [
                'value' => null,
                'currency' => null,
            ],
            'discount' => null,
        ];
    }

    /**
     * @return array{minimum_price: array, maximum_price: array}
     */
    public static function emptyRange(): array
    {
        $slot = self::emptySlot();

        return [
            'minimum_price' => $slot,
            'maximum_price' => $slot,
        ];
    }

    /**
     * Zera valores monetários em estruturas legadas do resolver Product\Price.
     *
     * @param array<string, mixed> $prices
     * @return array<string, mixed>
     */
    public static function redactLegacyPrice(array $prices): array
    {
        foreach ($prices as $key => $value) {
            if (is_array($value)) {
                $prices[$key] = self::redactLegacyPrice($value);
                continue;
            }
            if (($key === 'value' || $key === 'amount') && is_numeric($value)) {
                $prices[$key] = 0;
            }
        }

        return $prices;
    }
}
