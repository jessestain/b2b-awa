<?php

/**
 * Plugin para aplicar preco B2B dinamico por cliente
 *
 * Prioridade:
 * 1. Cliente com ERP → preco vem da lista ERP do cliente (VLRVDSUG)
 * 2. Cliente sem lista especifica (NACIONAL) ou sem ERP → preco base Magento (VLRVDSUG NACIONAL)
 *
 * O ERP e a autoridade de preco. Nao ha desconto de grupo — todo pricing vem do ERP.
 * Quando o ERP define preco, special_price e catalog rules sao neutralizados
 * para evitar precos conflitantes na PDP.
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin;

use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\ErpCodeResolver;
use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use GrupoAwamotos\ERPIntegration\Model\ResourceModel\SyncLog as SyncLogResource;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class GroupPricePlugin
{
    private const CONTEXT_APPROVAL_STATUS = 'b2b_approval_status';
    private const CONTEXT_PRICE_LIST = 'erp_price_list';

    private Config $config;
    private CustomerSession $customerSession;
    private CustomerRepositoryInterface $customerRepository;
    private CustomerPriceProvider $customerPriceProvider;
    private SyncLogResource $syncLogResource;
    private LoggerInterface $logger;
    private ?ErpCodeResolver $erpCodeResolver;
    private HttpContext $httpContext;
    private PhpCookieManager $cookieManager;

    /** Cache: productId → computed price */
    private array $processedProducts = [];

    /** Cache: productId → computed final price */
    private array $processedFinalPrices = [];

    /** Customer approval status cache */
    private string|null|false $approvalStatusCache = false;

    /** Customer ERP code cache */
    private int|null|false $erpCodeCache = false;

    public function __construct(
        Config $config,
        CustomerSession $customerSession,
        CustomerRepositoryInterface $customerRepository,
        CustomerPriceProvider $customerPriceProvider,
        SyncLogResource $syncLogResource,
        HttpContext $httpContext,
        PhpCookieManager $cookieManager,
        ?LoggerInterface $logger = null,
        ?ErpCodeResolver $erpCodeResolver = null
    ) {
        $this->config = $config;
        $this->customerSession = $customerSession;
        $this->customerRepository = $customerRepository;
        $this->cookieManager = $cookieManager;
        $this->customerPriceProvider = $customerPriceProvider;
        $this->syncLogResource = $syncLogResource;
        $this->httpContext = $httpContext;
        $this->logger = $logger ?? new NullLogger();
        $this->erpCodeResolver = $erpCodeResolver;
    }

    /**
     * Apply B2B pricing to product price
     */
    public function afterGetPrice(Product $subject, $result)
    {
        if (!$this->config->isEnabled() || !$this->isLoggedInContext()) {
            return $result;
        }

        $productId = $subject->getId();
        if (isset($this->processedProducts[$productId])) {
            return $this->processedProducts[$productId];
        }

        $price = $this->computeB2BPrice($subject, (float) $result);
        $this->processedProducts[$productId] = $price;

        return $price;
    }

    /**
     * Apply B2B pricing to final price
     */
    public function afterGetFinalPrice(Product $subject, $result)
    {
        if (!$this->config->isEnabled() || !$this->isLoggedInContext()) {
            return $result;
        }

        $productId = $subject->getId();
        if (isset($this->processedFinalPrices[$productId])) {
            return $this->processedFinalPrices[$productId];
        }

        $price = $this->computeB2BPrice($subject, (float) $result);
        $this->processedFinalPrices[$productId] = $price;

        return $price;
    }

    /**
     * Compute B2B price — ERP is the sole pricing authority
     *
     * - Customer with ERP code + specific list → ERP price (VLRVDSUG)
     * - Customer with ERP code + NACIONAL list → base price (VLRVDSUG NACIONAL, already synced)
     * - Customer without ERP code (new, pending ERP registration) → base price (NACIONAL)
     *
     * When ERP price is applied, special_price and catalog_rule_price are cleared
     * from the product data to prevent the Pricing Pool from showing competing prices.
     */
    private function computeB2BPrice(Product $product, float $basePrice): float
    {
        if ($basePrice <= 0) {
            return $basePrice;
        }

        // Must be approved B2B customer
        $approvalStatus = $this->getCustomerApprovalStatus();
        if ($approvalStatus !== 'approved') {
            return $basePrice;
        }

        $sku = (string) $product->getSku();
        if ($sku === '') {
            return $basePrice;
        }

        // Customer has ERP code → price comes from ERP
        $erpCode = $this->getCustomerErpCode();
        if ($erpCode !== null) {
            $erpPrice = $this->customerPriceProvider->getCustomerPrice($erpCode, $sku);
            if ($this->applyAuthorizedErpPrice($product, $erpPrice)) {
                return $erpPrice;
            }
        }

        // FPC/HTML render: customer session is depersonalized before toHtml(),
        // but HttpContext still carries the price-list vary token (list:version).
        $listCode = $this->resolveHttpContextPriceListCode();
        if ($listCode !== null) {
            $erpPrice = $this->customerPriceProvider->getAuthorizedListPrice($listCode, $sku);
            if ($this->applyAuthorizedErpPrice($product, $erpPrice)) {
                return $erpPrice;
            }
        }

        // No ERP code, or NACIONAL list, or SKU not in customer's list
        // → use base Magento price (VLRVDSUG NACIONAL, already synced from ERP)
        // ERP is the pricing authority — no group discounts applied
        return $basePrice;
    }

    /**
     * Neutralize Magento competing prices when an ERP unit price is the authority.
     */
    private function applyAuthorizedErpPrice(Product $product, ?float $erpPrice): bool
    {
        if ($erpPrice === null || $erpPrice <= 0) {
            return false;
        }

        $product->setData('special_price', null);
        $product->setData('special_from_date', null);
        $product->setData('special_to_date', null);
        $product->setData('catalog_rule_price', null);

        return true;
    }

    /**
     * Parse erp_price_list HttpContext token ("45:3", "24:1").
     */
    private function resolveHttpContextPriceListCode(): ?int
    {
        $token = $this->httpContext->getValue(self::CONTEXT_PRICE_LIST);
        if (!is_string($token) || $token === '' || $token === '0' || $token === 'logged_in') {
            return null;
        }

        if (!preg_match('/^(\d+)(?::|$)/', $token, $matches)) {
            return null;
        }

        $listCode = (int) $matches[1];

        return $listCode > 0 ? $listCode : null;
    }

    /**
     * Check login status via Http\Context (FPC-safe: no session start for guests).
     *
     * IMPORTANT: Context::setValue(CONTEXT_AUTH, false, false) REMOVES the key from _data
     * when value===default, so getValue(CONTEXT_AUTH) returns null for guests even after
     * HttpContextPlugin ran. Null alone cannot signal "context was populated".
     */
    private function isLoggedInContext(): bool
    {
        $contextLoggedIn = $this->httpContext->getValue(CustomerContext::CONTEXT_AUTH);
        if ($contextLoggedIn !== null) {
            return (bool) $contextLoggedIn;
        }
        // Null = guest (removed as value==default) OR context not yet set.
        // Cookie check avoids session_start() for every anonymous request.
        if ($this->cookieManager->getCookie(session_name()) === null) {
            return false;
        }
        return (bool) $this->customerSession->isLoggedIn();
    }

    /**
     * Get customer's ERP code.
     * Primary: erp_code customer attribute (single definitive value).
     * Fallback: entity_map table (may have duplicates).
     */
    private function getCustomerErpCode(): ?int
    {
        if ($this->erpCodeCache !== false) {
            return $this->erpCodeCache;
        }

        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                $this->erpCodeCache = null;
                return null;
            }
            $customer = $this->customerRepository->getById($customerId);

            if ($this->erpCodeResolver !== null) {
                $this->erpCodeCache = $this->erpCodeResolver->resolveForCustomerId($customerId, $customer);
                return $this->erpCodeCache;
            }

            // Primary: erp_code attribute (definitive, single value)
            $attr = $customer->getCustomAttribute('erp_code');
            $erpCode = ($attr && $attr->getValue()) ? $attr->getValue() : null;

            // Fallback: entity_map table
            if ($erpCode === null) {
                $erpCode = $this->syncLogResource->getErpCodeByMagentoId('customer', $customerId);
            }

            $this->erpCodeCache = ($erpCode !== null && is_numeric($erpCode)) ? (int) $erpCode : null;
        } catch (\Exception $e) {
            $this->logger->error('[B2B GroupPricePlugin] getCustomerErpCode error: ' . $e->getMessage());
            $this->erpCodeCache = null;
        }

        return $this->erpCodeCache;
    }

    /**
     * Get customer approval status via HttpContext (FPC-safe) then repository.
     */
    private function getCustomerApprovalStatus(): ?string
    {
        if ($this->approvalStatusCache !== false) {
            return $this->approvalStatusCache;
        }

        $contextStatus = $this->httpContext->getValue(self::CONTEXT_APPROVAL_STATUS);
        if (is_string($contextStatus) && $contextStatus !== '' && $contextStatus !== 'guest') {
            $this->approvalStatusCache = $contextStatus;
            return $this->approvalStatusCache;
        }

        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            if ($customerId <= 0) {
                $this->approvalStatusCache = null;
                return null;
            }
            $customer = $this->customerRepository->getById($customerId);
            $attr = $customer->getCustomAttribute('b2b_approval_status');
            $this->approvalStatusCache = $attr ? (string) $attr->getValue() : null;
        } catch (\Exception $e) {
            $this->approvalStatusCache = null;
        }

        return $this->approvalStatusCache;
    }
}
