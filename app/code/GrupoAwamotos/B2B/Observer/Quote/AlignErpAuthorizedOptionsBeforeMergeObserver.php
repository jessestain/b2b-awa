<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Observer\Quote;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;

/**
 * Copies ERP-authorized quote-item options onto the product being added
 * so Magento Quote\Item::representProduct() can merge qty instead of
 * creating a second line.
 *
 * ErpAuthorizedPriceApplier stamps erp_authorized_price_list and
 * erp_authorized_unit_price AFTER the first add. Magento only ignores
 * info_buyRequest when comparing items, so a second add of the same
 * simple SKU otherwise looks like a different product.
 *
 * Events: catalog_product_type_prepare_{full,lite}_options
 * (runs inside Quote::addProduct, before getItemByProduct).
 */
class AlignErpAuthorizedOptionsBeforeMergeObserver implements ObserverInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly State $appState
    ) {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product instanceof Product || (int) $product->getId() <= 0) {
            return;
        }

        try {
            if ($this->appState->getAreaCode() === Area::AREA_ADMINHTML) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $quote = $this->resolveQuote();
        if ($quote === null) {
            return;
        }

        $productId = (int) $product->getId();
        foreach ($quote->getAllVisibleItems() as $item) {
            if (!$item instanceof Item || $item->isDeleted()) {
                continue;
            }
            if ((int) $item->getProductId() !== $productId) {
                continue;
            }

            $this->copyAuthorizedOptions($item, $product);
            return;
        }
    }

    private function resolveQuote(): ?Quote
    {
        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Throwable) {
            return null;
        }

        return $quote instanceof Quote && (int) $quote->getId() > 0 ? $quote : null;
    }

    private function copyAuthorizedOptions(Item $item, Product $product): void
    {
        foreach ([
            'erp_authorized_price_list',
            'erp_authorized_unit_price',
        ] as $code) {
            $option = $item->getOptionByCode($code);
            if ($option === null) {
                continue;
            }

            $value = (string) $option->getValue();
            if ($value === '') {
                continue;
            }

            $product->addCustomOption($code, $value);
        }
    }
}
