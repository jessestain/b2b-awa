<?php
declare(strict_types=1);

namespace GrupoAwamotos\B2B\Controller\Ajax;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * B2B Densidade — suggest com PRIORIDADE DE SKU para o autocomplete da busca.
 * GET /b2b/ajax/skusuggest?q=6001
 * Resposta: lista com SKU, nome e URL — matches exatos de SKU primeiro.
 */
class SkuSuggest implements HttpGetActionInterface
{
    private const LIMIT = 8;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $q = trim((string) $this->request->getParam('q', ''));
        if (strlen($q) < 2) {
            return $result->setData(['items' => []]);
        }

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'url_key', 'sku'])
            ->addAttributeToFilter('status', 1)
            ->setPageSize(self::LIMIT + 4);

        $items = [];
        $qLike = '%' . addcslashes($q, '%_\\') . '%';

        // 1) matches de SKU (prefixo/exato primeiro)
        $sku = clone $collection;
        $sku->addAttributeToFilter('sku', ['like' => $qLike]);
        $sku->getSelect()->order(new \Zend_Db_Expr('CASE WHEN sku = ' . $collection->getConnection()->quote($q) . ' THEN 0 WHEN sku LIKE ' . $collection->getConnection()->quote($q . '%') . ' THEN 1 ELSE 2 END'));
        foreach ($sku as $product) {
            $items[] = $this->format($product, true);
            if (count($items) >= self::LIMIT) {
                return $result->setData(['items' => $items]);
            }
        }

        // 2) completa com matches de nome
        $name = clone $collection;
        $name->addAttributeToFilter('name', ['like' => $qLike]);
        foreach ($name as $product) {
            $items[] = $this->format($product, false);
            if (count($items) >= self::LIMIT) {
                break;
            }
        }

        return $result->setData(['items' => $items]);
    }

    private function format($product, bool $isSku): array
    {
        return [
            'sku'  => (string) $product->getSku(),
            'name' => (string) $product->getName(),
            'url'  => (string) $product->getProductUrl(),
            'match' => $isSku ? 'sku' : 'name',
        ];
    }
}
