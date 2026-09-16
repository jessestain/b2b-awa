<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin;

use Magento\Catalog\Pricing\Price;
use Magento\Catalog\Pricing\Render\FinalPriceBox;

/**
 * Evita Warning/Exception "Undefined array key" no método FinalPriceBox::hasSpecialPrice()
 * quando o bloco está em modo listagem e special_price_map não possui a chave do produto.
 *
 * Observação: isto é um hotfix defensivo (sem editar vendor). A causa raiz costuma ser
 * um preenchimento parcial de special_price_map em algum fluxo de renderização/cache.
 */
class FinalPriceBoxPlugin
{
    /**
     * Intercepta hasSpecialPrice para prevenir "Undefined array key" no modo listagem
     *
     * @param FinalPriceBox $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundHasSpecialPrice(FinalPriceBox $subject, callable $proceed): bool
    {
        // Verificação preventiva ANTES de chamar o método original
        // para evitar Warning sendo convertido em Exception pelo ErrorHandler
        $map = $subject->getData('special_price_map');

        if (is_array($map)) {
            // Estamos em modo listagem - verificar se a chave existe
            $saleableItem = $subject->getSaleableItem();
            if ($saleableItem) {
                $productId = $saleableItem->getId();
                if ($productId !== null && !array_key_exists($productId, $map)) {
                    // Chave não existe - calcular manualmente para evitar o erro
                    return $this->calculateHasSpecialPrice($subject);
                }
            }
        }

        // Se chegou aqui, é seguro chamar o método original
        try {
            return (bool)$proceed();
        } catch (\Throwable $e) {
            // Fallback caso ainda ocorra algum erro
            if (str_contains($e->getMessage(), 'Undefined array key') ||
                str_contains($e->getMessage(), 'Undefined index')
            ) {
                return $this->calculateHasSpecialPrice($subject);
            }
            throw $e;
        }
    }

    /**
     * Calcula hasSpecialPrice manualmente sem usar special_price_map
     *
     * @param FinalPriceBox $subject
     * @return bool
     */
    private function calculateHasSpecialPrice(FinalPriceBox $subject): bool
    {
        try {
            $regularPrice = $subject->getPriceType(Price\RegularPrice::PRICE_CODE);
            $finalPrice = $subject->getPriceType(Price\FinalPrice::PRICE_CODE);

            if (!$regularPrice || !$finalPrice) {
                return false;
            }

            $displayRegularPrice = $regularPrice->getAmount()->getValue();
            $displayFinalPrice = $finalPrice->getAmount()->getValue();

            return $displayFinalPrice < $displayRegularPrice;
        } catch (\Throwable $t) {
            // Em caso de qualquer erro, retorna false (sem preço especial)
            return false;
        }
    }
}
