<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\Quote;

use GrupoAwamotos\B2B\Helper\Config;
use GrupoAwamotos\B2B\Model\ErpCodeResolver;
use GrupoAwamotos\ERPIntegration\Helper\Data as ErpHelper;
use GrupoAwamotos\ERPIntegration\Model\CustomerPriceProvider;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Psr\Log\LoggerInterface;

/**
 * Persists the Sectra-authorized B2B unit price on quote items.
 *
 * GroupPricePlugin only runs in the frontend area. Checkout totals AJAX uses
 * webapi_rest, where Magento Subtotal recalculates from catalog (lista 24).
 * Stamping custom_price + original_custom_price keeps the customer list price
 * stable across collectTotals without calling the ERP on every total refresh.
 */
class ErpAuthorizedPriceApplier
{
    public const OPTION_PRICE_LIST = 'erp_authorized_price_list';
    public const OPTION_UNIT_PRICE = 'erp_authorized_unit_price';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ErpCodeResolver $erpCodeResolver,
        private readonly CustomerPriceProvider $customerPriceProvider,
        private readonly ErpHelper $erpHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Apply (or refresh) ERP price on a newly added / updated quote item.
     *
     * @throws LocalizedException When the customer has a non-default price list
     *                            and the authorized price cannot be resolved.
     */
    public function applyToItem(Quote $quote, Item $item, bool $forceRefresh = false): void
    {
        if (!$this->config->isEnabled() || $item->getParentItem()) {
            return;
        }

        $context = $this->resolveCustomerPriceContext($quote);
        if ($context === null) {
            return;
        }

        if ($context['list_code'] === $context['default_list']) {
            // Lista Nacional: catalog Magento (já sincronizado) is the authority.
            return;
        }

        $sku = trim((string) $item->getSku());
        if ($sku === '') {
            return;
        }

        if (!$forceRefresh && $this->itemHasValidAuthorizedPrice($item, $context['list_code'])) {
            $this->stampSuperMode($item);
            return;
        }

        $erpPrice = $this->customerPriceProvider->getCustomerPrice($context['erp_code'], $sku);
        if ($erpPrice === null || $erpPrice <= 0) {
            if ($this->itemHasValidAuthorizedPrice($item, $context['list_code'])) {
                $this->stampSuperMode($item);
                $this->logger->warning('[B2B ErpQuotePrice] Keeping last validated quote price after ERP miss', [
                    'customer_id' => $context['customer_id'],
                    'erp_code' => $context['erp_code'],
                    'price_list' => $context['list_code'],
                    'sku' => $sku,
                ]);
                return;
            }

            $this->logger->error('[B2B ErpQuotePrice] Fail-closed: authorized price unavailable', [
                'customer_id' => $context['customer_id'],
                'erp_code' => $context['erp_code'],
                'price_list' => $context['list_code'],
                'sku' => $sku,
            ]);

            throw new LocalizedException(__(
                'Não foi possível validar o preço comercial deste item. Tente novamente ou fale com o atendimento.'
            ));
        }

        $this->stampItemPrice($item, (float) $erpPrice, $context['list_code']);
    }

    /**
     * Ensure all quote items keep their authorized price before totals run.
     * Prefer persisted custom_price; only hits CustomerPriceProvider (cached) when missing.
     *
     * @throws LocalizedException
     */
    public function ensureQuoteItems(Quote $quote): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $context = $this->resolveCustomerPriceContext($quote);
        if ($context === null || $context['list_code'] === $context['default_list']) {
            return;
        }

        foreach ($quote->getAllVisibleItems() as $item) {
            if (!$item instanceof Item || $item->getParentItem()) {
                continue;
            }
            $this->applyToItem($quote, $item, false);
        }
    }

    /**
     * @return array{customer_id:int,erp_code:int,list_code:int,default_list:int}|null
     */
    private function resolveCustomerPriceContext(Quote $quote): ?array
    {
        $customerId = (int) $quote->getCustomerId();
        if ($customerId <= 0) {
            return null;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable $e) {
            $this->logger->error('[B2B ErpQuotePrice] Customer load failed', [
                'customer_id' => $customerId,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }

        $approvalAttr = $customer->getCustomAttribute('b2b_approval_status');
        $approval = $approvalAttr ? (string) $approvalAttr->getValue() : '';
        if ($approval !== '' && $approval !== 'approved') {
            return null;
        }

        $erpCode = $this->erpCodeResolver->resolveForCustomerId($customerId, $customer);
        if ($erpCode === null || $erpCode <= 0) {
            return null;
        }

        $listCode = $this->customerPriceProvider->getCustomerPriceListCode($erpCode);
        if ($listCode === null) {
            return null;
        }

        return [
            'customer_id' => $customerId,
            'erp_code' => $erpCode,
            'list_code' => $listCode,
            'default_list' => $this->erpHelper->getDefaultPriceList(),
        ];
    }

    private function itemHasValidAuthorizedPrice(Item $item, int $expectedListCode): bool
    {
        if (!$item->hasOriginalCustomPrice()) {
            return false;
        }

        $price = (float) $item->getOriginalCustomPrice();
        if ($price <= 0) {
            return false;
        }

        $listOption = $item->getOptionByCode(self::OPTION_PRICE_LIST);
        if ($listOption && (int) $listOption->getValue() !== $expectedListCode) {
            return false;
        }

        return true;
    }

    private function stampItemPrice(Item $item, float $erpPrice, int $listCode): void
    {
        $item->setCustomPrice($erpPrice);
        $item->setOriginalCustomPrice($erpPrice);
        $this->stampSuperMode($item);
        $this->upsertItemOption($item, self::OPTION_PRICE_LIST, (string) $listCode);
        $this->upsertItemOption($item, self::OPTION_UNIT_PRICE, (string) $erpPrice);
    }

    private function stampSuperMode(Item $item): void
    {
        $product = $item->getProduct();
        if ($product) {
            // Request-scoped; matches B2B Quote Accept / Magento admin custom price pattern.
            $product->setIsSuperMode(true);
        }
    }

    private function upsertItemOption(Item $item, string $code, string $value): void
    {
        $existing = $item->getOptionByCode($code);
        if ($existing) {
            $existing->setValue($value);
            return;
        }

        $item->addOption([
            'product_id' => (int) $item->getProductId(),
            'code' => $code,
            'value' => $value,
        ]);
    }
}
