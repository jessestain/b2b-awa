<?php

/**
 * Price Visibility Interface
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Api;

interface PriceVisibilityInterface
{
    /**
     * Check if current user can view prices
     *
     * @return bool
     */
    public function canViewPrices(): bool;

    /**
     * Check if current user can add to cart
     *
     * @return bool
     */
    public function canAddToCart(): bool;

    /**
     * Get message to display instead of price
     *
     * @return string
     */
    public function getPriceReplacementMessage(): string;

    /**
     * Check if current customer is approved
     *
     * @return bool
     */
    public function isCustomerApproved(): bool;

    /**
     * Check if current customer is approved but pending ERP code assignment
     *
     * @return bool
     */
    public function isApprovedPendingErp(): bool;

    /**
     * HOTFIX B2B-AUTH (13/10): mesma regra comercial, para um cliente EXPLICITO
     * (canais REST/GraphQL onde nao ha sessao de frontend).
     *
     * @param int $customerId
     * @return bool
     */
    public function canAddToCartForCustomer(int $customerId): bool;
}
