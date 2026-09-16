<?php

declare(strict_types=1);

namespace GrupoAwamotos\CatalogFix\Plugin\Catalog;

use Magento\Catalog\Block\Category\Plugin\PriceBoxTags;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\Render\PriceBox;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Guest FPC: cache key de preço na PLP sem ler endereços fiscais da sessão (PriceBoxTags).
 */
class PriceBoxTagsFpcPlugin
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TimezoneInterface $dateTime,
        private readonly ScopeResolverInterface $scopeResolver,
        private readonly PhpCookieManager $cookieManager
    ) {
    }

    /**
     * @param callable(PriceBox, string): string $proceed
     */
    public function aroundAfterGetCacheKey(
        PriceBoxTags $subject,
        callable $proceed,
        PriceBox $priceBox,
        string $result
    ): string {
        if ($this->cookieManager->getCookie(session_name()) !== null) {
            return $proceed($priceBox, $result);
        }

        return implode(
            '-',
            [
                $result,
                $this->priceCurrency->getCurrency()->getCode(),
                $this->dateTime->scopeDate($this->scopeResolver->getScope()->getId())->format('Ymd'),
                (string) $this->scopeResolver->getScope()->getId(),
                '0',
                '',
            ]
        );
    }
}
