<?php
declare(strict_types=1);

namespace GrupoAwamotos\B2B\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * B2B Densidade — ViewModel de estoque por item (cards/PDP).
 * Lê o índice de estoque legado (ERP-sync) e devolve dados prontos
 * para o template renderizar "Em estoque: N unidades".
 */
class StockQty implements ArgumentInterface
{
    public function __construct(
        private readonly StockItemRepositoryInterface $stockItemRepository
    ) {
    }

    /**
     * @return array{in_stock: bool, qty: float, managed: bool}
     */
    public function getStockInfo(ProductInterface $product): array
    {
        $empty = ['in_stock' => false, 'qty' => 0.0, 'managed' => false];
        try {
            $item = $this->stockItemRepository->get((int) $product->getId());
        } catch (\Throwable $e) {
            return $empty;
        }
        return [
            'in_stock' => (bool) $item->getIsInStock(),
            'qty'      => (float) $item->getQty(),
            'managed'  => (bool) $item->getManageStock(),
        ];
    }
}
