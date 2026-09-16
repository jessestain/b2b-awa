<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\ViewModel;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\CustomerData\LastOrderedItems;

/**
 * Homepage "recent orders" shell for FPC.
 *
 * Item lists are private: hydrate via Magento customer-data section
 * last-ordered-items. This ViewModel must not load LastOrderedItems during
 * HTML render, or FPC would need to vary by customer_id.
 */
class HomeRecentOrders implements ArgumentInterface
{
    public const STATE_GUEST = 'guest';

    public const STATE_LOGGED_EMPTY = 'logged_empty';

    public const STATE_HAS_ITEMS = 'has_items';

    private const WHATSAPP_NUMBER = '5516997367588';

    public function __construct(
        private readonly HttpContext $httpContext,
        private readonly LastOrderedItems $lastOrderedItems,
        private readonly CategoryCarouselData $categoryCarouselData,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    public function isHomeSectionEnabled(): bool
    {
        return true;
    }

    public function isLoggedIn(): bool
    {
        return (bool) $this->httpContext->getValue(CustomerContext::CONTEXT_AUTH);
    }

    public function getState(): string
    {
        return $this->isLoggedIn() ? self::STATE_LOGGED_EMPTY : self::STATE_GUEST;
    }

    /**
     * Server HTML never includes order lines (FPC-safe).
     *
     * @return list<array{id: int|string, name: string, url: string|null, is_saleable: bool, product_id: int|string}>
     */
    public function getRecentItems(): array
    {
        return [];
    }

    public function getBestsellersUrl(): string
    {
        return $this->urlBuilder->getUrl('', ['_direct' => 'super-ofertas.html']);
    }

    public function getLoginUrl(): string
    {
        return $this->urlBuilder->getUrl('b2b/account/login');
    }

    public function getRegisterUrl(): string
    {
        return $this->urlBuilder->getUrl('b2b/register');
    }

    public function getWhatsAppUrl(): string
    {
        $text = rawurlencode((string) __('Olá, quero falar com um vendedor sobre pedidos B2B na AWA Motos.'));

        return 'https://wa.me/' . self::WHATSAPP_NUMBER . '?text=' . $text;
    }

    public function getOrdersUrl(): string
    {
        return $this->urlBuilder->getUrl('sales/order/history');
    }
}
