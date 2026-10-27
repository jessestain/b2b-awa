<?php

/**
 * B2B Helper - Central configuration access
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_PATH_ENABLED = 'grupoawamotos_b2b/general/enabled';
    public const XML_PATH_B2B_MODE = 'grupoawamotos_b2b/general/b2b_mode';

    // Price Visibility
    public const XML_PATH_HIDE_PRICE_GUESTS = 'grupoawamotos_b2b/price_visibility/hide_price_guests';
    public const XML_PATH_HIDE_ADD_TO_CART_GUESTS = 'grupoawamotos_b2b/price_visibility/hide_add_to_cart_guests';
    public const XML_PATH_LOGIN_MESSAGE = 'grupoawamotos_b2b/price_visibility/login_message';
    public const XML_PATH_SHOW_PRICE_PENDING = 'grupoawamotos_b2b/price_visibility/show_price_pending';
    public const XML_PATH_HIDE_PRICE_NO_ERP = 'grupoawamotos_b2b/price_visibility/hide_price_no_erp';
    public const XML_PATH_PENDING_ERP_MESSAGE = 'grupoawamotos_b2b/price_visibility/pending_erp_message';

    // Customer Approval
    public const XML_PATH_REQUIRE_APPROVAL = 'grupoawamotos_b2b/customer_approval/require_approval';
    public const XML_PATH_SCORING_ENABLED = 'grupoawamotos_b2b/customer_approval/scoring_enabled';
    public const XML_PATH_AUTO_APPROVE_GROUPS = 'grupoawamotos_b2b/customer_approval/auto_approve_groups';
    public const XML_PATH_PENDING_MESSAGE = 'grupoawamotos_b2b/customer_approval/pending_message';
    // FIX F-02 (2026-10-14): mensagens específicas por status — rejeitado/suspenso não
    // podem receber a mensagem de "conta em análise".
    public const XML_PATH_REJECTED_MESSAGE = 'grupoawamotos_b2b/customer_approval/rejected_message';
    public const XML_PATH_SUSPENDED_MESSAGE = 'grupoawamotos_b2b/customer_approval/suspended_message';
    public const XML_PATH_SEND_APPROVAL_EMAIL = 'grupoawamotos_b2b/customer_approval/send_approval_email';
    public const XML_PATH_NOTIFY_ADMIN = 'grupoawamotos_b2b/customer_approval/notify_admin_new_customer';
    public const XML_PATH_ADMIN_EMAIL = 'grupoawamotos_b2b/customer_approval/admin_email';

    // Minimum Qty
    public const XML_PATH_MIN_QTY_ENABLED = 'grupoawamotos_b2b/minimum_qty/enabled';
    public const XML_PATH_GLOBAL_MIN_QTY = 'grupoawamotos_b2b/minimum_qty/global_min_qty';
    public const XML_PATH_MIN_ORDER_AMOUNT = 'grupoawamotos_b2b/minimum_qty/min_order_amount';
    public const XML_PATH_MIN_ORDER_MESSAGE = 'grupoawamotos_b2b/minimum_qty/min_order_message';

    // Quote Request
    public const XML_PATH_QUOTE_ENABLED = 'grupoawamotos_b2b/quote_request/enabled';
    public const XML_PATH_QUOTE_BUTTON = 'grupoawamotos_b2b/quote_request/show_button';
    public const XML_PATH_QUOTE_ALLOW_GUESTS = 'grupoawamotos_b2b/quote_request/allow_guests';
    public const XML_PATH_QUOTE_EXPIRY_DAYS = 'grupoawamotos_b2b/quote_request/expiry_days';
    public const XML_PATH_QUOTE_NOTIFY_CUSTOMER = 'grupoawamotos_b2b/quote_request/notify_customer';

    // Customer Groups
    public const XML_PATH_WHOLESALE_GROUP = 'grupoawamotos_b2b/customer_groups/wholesale_group';
    public const XML_PATH_WHOLESALE_DISCOUNT = 'grupoawamotos_b2b/customer_groups/wholesale_discount';
    public const XML_PATH_VIP_GROUP = 'grupoawamotos_b2b/customer_groups/vip_group';
    public const XML_PATH_VIP_DISCOUNT = 'grupoawamotos_b2b/customer_groups/vip_discount';
    public const XML_PATH_DEFAULT_B2B_GROUP = 'grupoawamotos_b2b/customer_groups/default_b2b_group';
    public const XML_PATH_REVENDEDOR_GROUP = 'grupoawamotos_b2b/customer_groups/revendedor_group';
    public const XML_PATH_PENDING_GROUP = 'grupoawamotos_b2b/customer_groups/pending_group';

    // CNAE Profiling
    public const XML_PATH_CNAE_ENABLED = 'grupoawamotos_b2b/cnae_profiling/enabled';
    public const XML_PATH_CNAE_AUTO_APPROVE_DIRECT = 'grupoawamotos_b2b/cnae_profiling/auto_approve_direct';
    public const XML_PATH_CNAE_DIRECT_GROUP = 'grupoawamotos_b2b/cnae_profiling/direct_group';
    public const XML_PATH_CNAE_ADJACENT_GROUP = 'grupoawamotos_b2b/cnae_profiling/adjacent_group';

    // Sectra integration
    public const XML_PATH_SECTRA_CANCEL_STUCK_DRY_RUN = 'grupoawamotos_b2b/sectra/cancel_stuck_dry_run';

    // Customer ERP status flag (marcacao de cliente aprovado e inativo/ausente no Sectra)
    public const XML_PATH_CUSTOMER_ERP_STATUS_FLAG_ENABLED = 'grupoawamotos_b2b/customer_erp_status/flag_enabled';
    public const XML_PATH_CUSTOMER_ERP_STATUS_FLAG_DRY_RUN = 'grupoawamotos_b2b/customer_erp_status/flag_dry_run';
    public const XML_PATH_CUSTOMER_ERP_STATUS_FLAG_MAX_PERCENT = 'grupoawamotos_b2b/customer_erp_status/flag_max_percent';

    // Checkout Fields (Delivery Date, Order Notes, PO Number)
    public const XML_PATH_DELIVERY_DATE_ENABLED = 'grupoawamotos_b2b/checkout/delivery_date_enabled';
    public const XML_PATH_DELIVERY_DATE_REQUIRED = 'grupoawamotos_b2b/checkout/delivery_date_required';
    public const XML_PATH_ORDER_NOTES_ENABLED = 'grupoawamotos_b2b/checkout/order_notes_enabled';
    public const XML_PATH_ORDER_NOTES_REQUIRED = 'grupoawamotos_b2b/checkout/order_notes_required';
    public const XML_PATH_PO_NUMBER_ENABLED = 'grupoawamotos_b2b/checkout/po_number_enabled';
    public const XML_PATH_PO_NUMBER_REQUIRED = 'grupoawamotos_b2b/checkout/po_number_required';

    /**
     * Check if B2B module is enabled
     */
    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get B2B mode (strict or mixed)
     */
    public function getB2BMode($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_B2B_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if strict B2B mode
     */
    public function isStrictB2B($storeId = null): bool
    {
        return $this->getB2BMode($storeId) === 'strict';
    }

    /**
     * Check if should hide prices for guests
     */
    public function hidePriceForGuests($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_HIDE_PRICE_GUESTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if should hide add to cart for guests
     */
    public function hideAddToCartForGuests($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_HIDE_ADD_TO_CART_GUESTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get login message for guests
     */
    public function getLoginMessage($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_LOGIN_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if pending customers can see prices
     */
    public function showPriceForPending($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_PRICE_PENDING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if should hide prices for approved customers without ERP code
     */
    public function hidePriceForNoErp($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_HIDE_PRICE_NO_ERP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get message for customers awaiting ERP price list assignment
     */
    public function getPendingErpMessage($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PENDING_ERP_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if customer approval is required
     */
    public function requireApproval($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_REQUIRE_APPROVAL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if approval scoring (green/yellow/red screening at registration) is enabled.
     *
     * Bug fix: this method was referenced by ApprovalScoreService::evaluate() but never
     * implemented, causing a fatal "Call to undefined method" whenever a customer with a
     * direct CNAE profile went through registration screening.
     */
    public function isApprovalScoringEnabled($storeId = null): bool
    {
        return $this->requireApproval($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_SCORING_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get auto approve groups
     */
    public function getAutoApproveGroups($storeId = null): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_AUTO_APPROVE_GROUPS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (empty($value)) {
            return [];
        }

        return array_map('intval', explode(',', $value));
    }

    /**
     * Get pending message
     */
    public function getPendingMessage($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PENDING_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get rejected message (FIX F-02)
     */
    public function getRejectedMessage($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_REJECTED_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get suspended message (FIX F-02)
     */
    public function getSuspendedMessage($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SUSPENDED_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if should send approval email
     */
    public function sendApprovalEmail($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SEND_APPROVAL_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if should notify admin
     */
    public function notifyAdmin($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_NOTIFY_ADMIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get admin email for notifications
     */
    public function getAdminEmail($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_ADMIN_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Sender identity used by TransportBuilder::setFromByScope() for B2B transactional e-mails.
     * Matches the "general" store contact identity used consistently across the module
     * (CustomerApproval, Quote\Save, Register\Save, Cron\NotifyPendingApprovals).
     */
    public function getEmailSender($storeId = null): string
    {
        return 'general';
    }

    /**
     * Check if minimum qty is enabled
     */
    public function isMinQtyEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_MIN_QTY_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get global minimum qty
     */
    public function getGlobalMinQty($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_GLOBAL_MIN_QTY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get minimum order amount
     */
    public function getMinOrderAmount($storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_MIN_ORDER_AMOUNT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get minimum order message
     */
    public function getMinOrderMessage($storeId = null): string
    {
        $message = (string) $this->scopeConfig->getValue(
            self::XML_PATH_MIN_ORDER_MESSAGE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return str_replace(
            '{{min_amount}}',
            number_format($this->getMinOrderAmount($storeId), 2, ',', '.'),
            $message
        );
    }

    /**
     * Check if quote request is enabled
     */
    public function isQuoteEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_QUOTE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get quote button position
     */
    public function getQuoteButtonPosition($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_QUOTE_BUTTON,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if guests can request quotes
     */
    public function allowGuestsQuote($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_QUOTE_ALLOW_GUESTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get quote expiry days
     */
    public function getQuoteExpiryDays($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_QUOTE_EXPIRY_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get wholesale group ID
     */
    public function getWholesaleGroupId($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_WHOLESALE_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get wholesale discount percentage
     */
    public function getWholesaleDiscount($storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_WHOLESALE_DISCOUNT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get VIP group ID
     */
    public function getVipGroupId($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_VIP_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get VIP discount percentage
     */
    public function getVipDiscount($storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_VIP_DISCOUNT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get default B2B group ID for new approved customers
     */
    public function getDefaultB2BGroupId($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_B2B_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get Revendedor group ID
     */
    public function getRevendedorGroupId($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_REVENDEDOR_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get Pending group ID
     */
    public function getPendingGroupId($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_PENDING_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if CNAE profiling is enabled
     */
    public function isCnaeProfilingEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_CNAE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if direct-profile customers should be auto-approved
     */
    public function isCnaeAutoApproveDirect($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CNAE_AUTO_APPROVE_DIRECT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get target group ID for Direct profile (Motos)
     */
    public function getDirectProfileGroupId($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_CNAE_DIRECT_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get target group ID for Adjacent profile (Automotive)
     */
    public function getAdjacentProfileGroupId($storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_CNAE_ADJACENT_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if delivery date field is enabled in checkout
     */
    public function isDeliveryDateEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_DELIVERY_DATE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if delivery date is required in checkout
     */
    public function isDeliveryDateRequired($storeId = null): bool
    {
        return $this->isDeliveryDateEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_DELIVERY_DATE_REQUIRED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if order notes field is enabled in checkout
     */
    public function isOrderNotesEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_ORDER_NOTES_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if order notes is required in checkout
     */
    public function isOrderNotesRequired($storeId = null): bool
    {
        return $this->isOrderNotesEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_ORDER_NOTES_REQUIRED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if PO number field is enabled in checkout
     */
    public function isPoNumberEnabled($storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_PO_NUMBER_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if PO number is required in checkout
     */
    public function isPoNumberRequired($storeId = null): bool
    {
        return $this->isPoNumberEnabled($storeId) && $this->scopeConfig->isSetFlag(
            self::XML_PATH_PO_NUMBER_REQUIRED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
    /**
     * Get WhatsApp support number from config
     */
    public function getWhatsAppSupportNumber($storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            'grupoawamotos_b2b/whatsapp/default_number',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Whether Sectra "cancel stuck orders" runs in dry-run mode (log only, no cancellation).
     * Default: false = actually cancel orders for unvalidated customers.
     */
    public function isSectraCancelStuckDryRun($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SECTRA_CANCEL_STUCK_DRY_RUN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Marcacao (nao bloqueio) de cliente aprovado e inativo/ausente no Sectra.
     * Desligado por padrao.
     */
    public function isCustomerErpStatusFlagEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CUSTOMER_ERP_STATUS_FLAG_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Enquanto verdadeiro, so registra no log quem seria marcado. Ausencia de
     * configuracao tambem significa dry-run: o lado seguro e o padrao.
     */
    public function isCustomerErpStatusFlagDryRun($storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CUSTOMER_ERP_STATUS_FLAG_DRY_RUN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value === null || $value === '' || (bool) (int) $value;
    }

    /**
     * Fracao maxima (0-1) dos clientes aprovados checados que podem ser marcados
     * numa passada. Acima disto, nada e gravado (indica falha de consulta ao ERP).
     */
    public function getCustomerErpStatusFlagMaxRatio($storeId = null): float
    {
        $percent = (float) $this->scopeConfig->getValue(
            self::XML_PATH_CUSTOMER_ERP_STATUS_FLAG_MAX_PERCENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $percent > 0 && $percent <= 100 ? $percent / 100 : 0.15;
    }
}
