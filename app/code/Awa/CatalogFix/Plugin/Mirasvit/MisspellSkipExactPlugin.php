<?php

declare(strict_types=1);

namespace Awa\CatalogFix\Plugin\Mirasvit;

use Awa\CatalogFix\Model\Search\SkuQueryClassifier;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Mirasvit\Misspell\Observer\OnCatalogSearchObserver;

/**
 * Skip Mirasvit Misspell/fallback 302 when exact=1 is present.
 *
 * After SearchLanding is bypassed, Misspell may still rewrite
 * e.g. capacete → cavalete. exact=1 must stay on the original query.
 *
 * Existing catalog SKUs (3022, LS3022) also skip misspell. Text tokens that
 * merely match the SKU character class (bagageiro) keep spelling assistance.
 */
class MisspellSkipExactPlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ?SkuQueryClassifier $skuQueryClassifier = null
    ) {
    }

    private function classifier(): SkuQueryClassifier
    {
        return $this->skuQueryClassifier
            ?? \Magento\Framework\App\ObjectManager::getInstance()->get(SkuQueryClassifier::class);
    }

    /**
     * @param OnCatalogSearchObserver $subject
     * @param callable $proceed
     * @param EventObserver $observer
     */
    public function aroundExecute(
        OnCatalogSearchObserver $subject,
        callable $proceed,
        EventObserver $observer
    ): void {
        $exact = $this->request->getParam('exact');

        if ($exact === 1 || $exact === '1') {
            return;
        }

        $query = trim((string) $this->request->getParam('q'));
        if ($query !== '' && $this->classifier()->isExistingExactSkuQuery($query)) {
            return;
        }

        $proceed($observer);
    }
}
