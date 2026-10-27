<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Model\Product;

/**
 * Decisoes puras do "portao de publicacao": o que NAO pode ficar visivel na loja.
 *
 * POR QUE ESTA CLASSE EXISTE
 * O sync so age sobre o que o Sectra DEVOLVE. Produto que nunca existiu no Sectra
 * (legado de migracao, cadastro manual) e produto publicado a R$ 0,01 (o ProductSync
 * grava esse placeholder quando o Sectra nao tem preco) ficavam visiveis para sempre,
 * violando a regra "so aparece o que e ativo no Sectra, com preco do Sectra".
 *
 * ASSIMETRIA DE RISCO (mesma logica de InactiveProductReactivationGuard)
 * Esconder por engano perde venda; deixar passar vende errado. Aqui o lado destrutivo
 * (desabilitar) e deliberadamente MAIS estreito que o piso de reativacao:
 *   - desabilita so placeholder (<= R$ 0,01) ou ausente;
 *   - preco entre R$ 0,01 e R$ 1,00 NAO e desabilitado, so vai para revisao. O catalogo
 *     tem itens legitimamente baratos (lente a R$ 0,97) e desabilita-los em massa
 *     seria perda de venda sem evidencia de erro.
 *
 * Alem disso, existe um limite de seguranca: se o portao quisesse desabilitar mais que
 * uma fracao do catalogo habilitado de uma vez, o proprio volume indica falha (consulta
 * errada, base do Sectra trocada) e nada e alterado ate um humano revisar.
 */
final class CatalogPublicationGuard
{
    /** Preco igual ou abaixo disto e placeholder, nao preco. */
    public const PLACEHOLDER_PRICE_CEILING = 0.01;

    /** Fracao maxima do catalogo habilitado que o portao desabilita numa passada. */
    public const DEFAULT_MAX_DISABLE_RATIO = 0.10;

    public const REASON_MISSING_FROM_ERP = 'missing_from_sectra';
    public const REASON_PLACEHOLDER_PRICE = 'placeholder_price';

    /**
     * Preco ausente ou de placeholder: o produto nao pode ser vendido.
     */
    public static function isPlaceholderPrice(?float $price): bool
    {
        return $price === null || round($price, 2) <= self::PLACEHOLDER_PRICE_CEILING;
    }

    /**
     * Preco acima do placeholder mas abaixo do piso de reativacao: nao desabilita,
     * mas merece olho humano.
     */
    public static function isLowPriceForReview(?float $price): bool
    {
        return $price !== null
            && round($price, 2) > self::PLACEHOLDER_PRICE_CEILING
            && $price < InactiveProductReactivationGuard::PRICE_FLOOR;
    }

    /**
     * O produto existe no Sectra? Procura por qualquer chave conhecida (SKU, codigo ERP
     * mapeado, SKU base) tanto nos codigos exatos quanto nos codigos-base do Sectra.
     *
     * Sem NENHUMA chave utilizavel a resposta e false: sem informacao nao se desabilita.
     *
     * @param list<string>         $keys    chaves candidatas do produto Magento
     * @param array<string, true>  $erpAll  todos os CODIGO do Sectra
     * @param array<string, true>  $erpBase CODIGO do Sectra reduzido ao SKU base
     */
    public static function isMissingFromErp(array $keys, array $erpAll, array $erpBase): bool
    {
        $usable = false;
        foreach ($keys as $key) {
            if ($key === '') {
                continue;
            }
            $usable = true;
            if (isset($erpAll[$key]) || isset($erpBase[$key])) {
                return false;
            }
        }

        return $usable;
    }

    /**
     * O volume de candidatos esta dentro do que um ajuste normal produziria?
     */
    public static function withinSafetyLimit(int $candidates, int $totalEnabled, float $maxRatio): bool
    {
        if ($candidates <= 0) {
            return true;
        }
        if ($totalEnabled <= 0) {
            return false;
        }

        return ($candidates / $totalEnabled) <= $maxRatio;
    }
}
