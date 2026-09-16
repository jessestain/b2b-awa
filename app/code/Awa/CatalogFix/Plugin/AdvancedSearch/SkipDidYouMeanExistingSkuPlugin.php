<?php

declare(strict_types=1);

namespace Awa\CatalogFix\Plugin\AdvancedSearch;

use Awa\CatalogFix\Model\Search\SkuQueryClassifier;
use Magento\AdvancedSearch\Block\SearchData;
use Magento\Framework\App\RequestInterface;

/**
 * Hide OpenSearch "Did you mean" when the query is an existing catalog SKU.
 */
class SkipDidYouMeanExistingSkuPlugin
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly SkuQueryClassifier $skuQueryClassifier
    ) {
    }

    public function beforeToHtml(SearchData $subject): void
    {
        $subject->setData('sku_query_classifier', $this->skuQueryClassifier);
    }

    /**
     * @param list<\Magento\Search\Model\QueryResult> $result
     * @return list<\Magento\Search\Model\QueryResult>
     */
    public function afterGetItems(SearchData $subject, array $result): array
    {
        $query = trim((string) $this->request->getParam('q', ''));
        if ($query !== '' && $this->skuQueryClassifier->isExistingExactSkuQuery($query)) {
            return [];
        }

        return $result;
    }
}
