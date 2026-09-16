<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\ViewModel;

use GrupoAwamotos\B2B\Helper\Data as B2bHelper;
use Magento\Catalog\Helper\Product\Compare as CompareHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Wishlist\Helper\Data as WishlistHelper;

/**
 * ViewModel substituto de $this->helper() no template bestseller.phtml.
 * Injeta os helpers de B2B, Wishlist e Compare para eliminar as chamadas
 * deprecated a AbstractBlock::helper().
 */
class BestsellerCardViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly B2bHelper    $b2bHelper,
        private readonly WishlistHelper $wishlistHelper,
        private readonly CompareHelper  $compareHelper,
    ) {
    }

    public function canViewPrices(): bool
    {
        return $this->b2bHelper->canViewPrices();
    }

    public function getPriceGateHeadline(): string
    {
        return $this->b2bHelper->getPriceGateHeadline();
    }

    public function getPriceGateDescription(): string
    {
        return $this->b2bHelper->getPriceGateDescription();
    }

    public function getPriceGateCardMessage(): string
    {
        return $this->b2bHelper->getPriceGateCardMessage();
    }

    public function getPriceGatePrimaryUrl(): string
    {
        return $this->b2bHelper->getPriceGatePrimaryUrl();
    }

    public function isWishlistAllowed(): bool
    {
        return $this->wishlistHelper->isAllow();
    }

    public function getWishlistAddParams(Product $product): string
    {
        return (string) $this->wishlistHelper->getAddParams($product);
    }

    public function comparePostData(Product $product): string
    {
        return (string) $this->compareHelper->getPostDataParams($product);
    }

    /**
     * Remove atributos id="" duplicados inseridos pelo renderer de preço do Magento.
     * Reproduz a lógica de Rokanthemes\Themeoption\Helper\Data::getPriceDisplayCustom().
     */
    public function priceDisplayHtml(string $html): string
    {
        return (string) preg_replace('/(<[^>]+) id="[^"]*"/i', '$1', $html);
    }
}
