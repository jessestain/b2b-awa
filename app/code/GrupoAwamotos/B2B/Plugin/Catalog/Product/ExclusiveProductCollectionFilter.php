<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Plugin\Catalog\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\App\Area;
use Magento\Framework\Stdlib\Cookie\PhpCookieManager;

/**
 * Oculta produtos OEM/exclusivos de todos os clientes exceto o dono cadastrado
 * em grupoawamotos_b2b_exclusive_product.
 *
 * Lógica do LEFT JOIN:
 *   - Produto SEM linha na tabela → b2b_ep.product_id IS NULL → visível para todos.
 *   - Produto COM linha → só aparece se b2b_ep.customer_id = ID do cliente logado.
 *   - Visitante não logado → customer_id = 0 → jamais corresponde → produto oculto.
 */
class ExclusiveProductCollectionFilter
{
    private const FLAG = 'b2b_exclusive_filter_applied';
    private const TABLE = 'grupoawamotos_b2b_exclusive_product';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly ResourceConnection $resourceConnection,
        private readonly AppState $appState,
        private readonly PhpCookieManager $cookieManager
    ) {
    }

    /**
     * Injeta o filtro de exclusividade antes de cada load de coleção de produtos,
     * somente na área frontend.
     */
    public function beforeLoad(
        ProductCollection $collection,
        $printQuery = false,
        $logQuery = false
    ): array {
        if ($this->shouldSkip($collection)) {
            return [$printQuery, $logQuery];
        }

        $collection->setFlag(self::FLAG, true);

        $customerId = $this->resolveCustomerIdForFilter();
        $table      = $this->resourceConnection->getTableName(self::TABLE);

        $collection->getSelect()->joinLeft(
            ['b2b_ep' => $table],
            'b2b_ep.product_id = e.entity_id',
            []
        );

        $collection->getSelect()->where(
            'b2b_ep.product_id IS NULL OR b2b_ep.customer_id = ?',
            $customerId
        );

        return [$printQuery, $logQuery];
    }

    private function shouldSkip(ProductCollection $collection): bool
    {
        if ($collection->getFlag(self::FLAG)) {
            return true;
        }

        try {
            return $this->appState->getAreaCode() !== Area::AREA_FRONTEND;
        } catch (\Exception) {
            return true;
        }
    }

    /**
     * Guest = 0 sem session_start (FPC-safe). Logado só com cookie de sessão presente.
     */
    private function resolveCustomerIdForFilter(): int
    {
        if ($this->cookieManager->getCookie(session_name()) === null) {
            return 0;
        }

        return (int) $this->customerSession->getCustomerId();
    }
}
