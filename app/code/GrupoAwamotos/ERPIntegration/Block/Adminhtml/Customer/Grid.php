<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Block\Adminhtml\Customer;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Helper\Data as Helper;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;

/**
 * Admin Block - ERP Customer Grid
 */
class Grid extends Template
{
    protected $_template = 'GrupoAwamotos_ERPIntegration::customer/grid.phtml';

    private ConnectionInterface $connection;
    private Helper $helper;
    private CustomerCollectionFactory $customerCollectionFactory;
    private ?array $customers = null;
    private ?array $magentoCustomersByCnpj = null;
    private int $page = 1;
    private int $pageSize = 100;
    private ?string $search = null;
    private ?string $typeFilter = null;
    private string $sortBy = 'total_pedidos';
    private string $sortOrder = 'DESC';

    public function __construct(
        Context $context,
        ConnectionInterface $connection,
        Helper $helper,
        CustomerCollectionFactory $customerCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->connection = $connection;
        $this->helper = $helper;
        $this->customerCollectionFactory = $customerCollectionFactory;

        // Get request params
        $this->page = max(1, (int) $this->getRequest()->getParam('page', 1));
        $this->search = $this->getRequest()->getParam('search');
        $this->typeFilter = $this->getRequest()->getParam('type');
        $this->sortBy = $this->getRequest()->getParam('sort', 'total_pedidos');
        $this->sortOrder = strtoupper($this->getRequest()->getParam('dir', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    }

    /**
     * Check if ERP connection is enabled
     */
    public function isEnabled(): bool
    {
        return $this->helper->isEnabled();
    }

    /**
     * Get customers with purchase history
     */
    public function getCustomers(): array
    {
        if ($this->customers !== null) {
            return $this->customers;
        }

        if (!$this->isEnabled()) {
            return [];
        }

        try {
            $offset = ($this->page - 1) * $this->pageSize;

            // Build WHERE clause - only active records
            $whereClause = "f.ATCLIENTE = 'S'";
            $params = [];

            // Type filter
            if ($this->typeFilter === 'cliente') {
                $whereClause .= " AND f.CKCLIENTE = 'S'";
            } elseif ($this->typeFilter === 'fornecedor') {
                $whereClause .= " AND (f.CKCLIENTE <> 'S' OR f.CKCLIENTE IS NULL)";
            } elseif ($this->typeFilter === 'com_pedidos') {
                $whereClause .= " AND f.CKCLIENTE = 'S'";
            }

            if (!empty($this->search)) {
                $whereClause .= " AND (f.RAZAO LIKE ? OR f.FANTASIA LIKE ? OR f.CGC LIKE ? OR CAST(f.CODIGO AS VARCHAR) LIKE ?)";
                $searchParam = '%' . $this->search . '%';
                $params = [$searchParam, $searchParam, $searchParam, $searchParam];
            }

            // Valid sort columns
            $validSorts = ['codigo', 'razao', 'cidade', 'uf', 'total_pedidos', 'valor_total', 'ultima_compra'];
            $sortColumn = in_array($this->sortBy, $validSorts) ? $this->sortBy : 'total_pedidos';

            $this->customers = $this->connection->query("
                SELECT
                    f.CODIGO as codigo,
                    f.RAZAO as razao,
                    f.FANTASIA as fantasia,
                    f.CGC as cnpj,
                    f.CIDADE as cidade,
                    f.UF as uf,
                    f.CKCLIENTE as is_cliente,
                    COALESCE(stats.total_pedidos, 0) as total_pedidos,
                    COALESCE(stats.valor_total, 0) as valor_total,
                    stats.ultima_compra
                FROM FN_FORNECEDORES f
                LEFT JOIN (
                    SELECT
                        p.CLIENTE,
                        COUNT(DISTINCT p.CODIGO) as total_pedidos,
                        SUM(i.VLRTOTAL) as valor_total,
                        MAX(p.DTPEDIDO) as ultima_compra
                    FROM VE_PEDIDO p
                    INNER JOIN VE_PEDIDOITENS i ON p.CODIGO = i.PEDIDO
                    WHERE p.STATUS NOT IN ('C', 'X')
                    GROUP BY p.CLIENTE
                ) stats ON f.CODIGO = stats.CLIENTE
                WHERE {$whereClause}
                ORDER BY {$sortColumn} {$this->sortOrder}
                OFFSET {$offset} ROWS
                FETCH NEXT {$this->pageSize} ROWS ONLY
            ", $params);

            return $this->customers;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get total customer count
     */
    public function getTotalCount(): int
    {
        if (!$this->isEnabled()) {
            return 0;
        }

        try {
            $whereClause = "ATCLIENTE = 'S'";
            $params = [];

            // Apply type filter to count
            if ($this->typeFilter === 'cliente') {
                $whereClause .= " AND CKCLIENTE = 'S'";
            } elseif ($this->typeFilter === 'fornecedor') {
                $whereClause .= " AND (CKCLIENTE <> 'S' OR CKCLIENTE IS NULL)";
            }

            if (!empty($this->search)) {
                $whereClause .= " AND (RAZAO LIKE ? OR FANTASIA LIKE ? OR CGC LIKE ? OR CAST(CODIGO AS VARCHAR) LIKE ?)";
                $searchParam = '%' . $this->search . '%';
                $params = [$searchParam, $searchParam, $searchParam, $searchParam];
            }

            return (int) $this->connection->fetchColumn("
                SELECT COUNT(*) FROM FN_FORNECEDORES WHERE {$whereClause}
            ", $params);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get current page
     */
    public function getCurrentPage(): int
    {
        return $this->page;
    }

    /**
     * Get page size
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Get total pages
     */
    public function getTotalPages(): int
    {
        return (int) ceil($this->getTotalCount() / $this->pageSize);
    }

    /**
     * Get search term
     */
    public function getSearchTerm(): ?string
    {
        return $this->search;
    }

    /**
     * Get current sort column
     */
    public function getSortBy(): string
    {
        return $this->sortBy;
    }

    /**
     * Get current sort order
     */
    public function getSortOrder(): string
    {
        return $this->sortOrder;
    }

    /**
     * Format price
     */
    public function formatPrice(float $price): string
    {
        return 'R$ ' . number_format($price, 2, ',', '.');
    }

    /**
     * Format ERP date
     */
    public function formatErpDate(?string $date): string
    {
        if (empty($date)) {
            return '-';
        }
        try {
            return (new \DateTime($date))->format('d/m/Y');
        } catch (\Exception $e) {
            return substr($date, 0, 10);
        }
    }

    /**
     * Get view URL for a customer
     */
    public function getViewUrl(int $customerCode): string
    {
        return $this->getUrl('*/*/view', ['id' => $customerCode]);
    }

    /**
     * Get sort URL
     */
    public function getSortUrl(string $column): string
    {
        $newDir = ($this->sortBy === $column && $this->sortOrder === 'DESC') ? 'ASC' : 'DESC';
        return $this->getUrl('*/*/*', [
            'sort' => $column,
            'dir' => $newDir,
            'search' => $this->search,
            'type' => $this->typeFilter,
            'page' => 1
        ]);
    }

    /**
     * Get page URL
     */
    public function getPageUrl(int $page): string
    {
        return $this->getUrl('*/*/*', [
            'page' => $page,
            'sort' => $this->sortBy,
            'dir' => $this->sortOrder,
            'search' => $this->search,
            'type' => $this->typeFilter
        ]);
    }

    /**
     * Get current type filter
     */
    public function getTypeFilter(): ?string
    {
        return $this->typeFilter;
    }

    /**
     * Get filter URL
     */
    public function getFilterUrl(?string $type): string
    {
        return $this->getUrl('*/*/*', [
            'type' => $type,
            'search' => $this->search,
            'page' => 1
        ]);
    }

    /**
     * Load Magento customers indexed by CNPJ (taxvat)
     */
    private function getMagentoCustomersByCnpj(): array
    {
        if ($this->magentoCustomersByCnpj !== null) {
            return $this->magentoCustomersByCnpj;
        }

        $this->magentoCustomersByCnpj = [];

        try {
            $collection = $this->customerCollectionFactory->create();
            $collection->addAttributeToSelect(['firstname', 'lastname', 'email', 'taxvat']);
            $collection->addAttributeToFilter('taxvat', ['notnull' => true]);
            $collection->addAttributeToFilter('taxvat', ['neq' => '']);

            foreach ($collection as $customer) {
                $cnpj = preg_replace('/\D/', '', $customer->getTaxvat());
                if (!empty($cnpj)) {
                    $this->magentoCustomersByCnpj[$cnpj] = [
                        'id' => $customer->getId(),
                        'name' => $customer->getFirstname() . ' ' . $customer->getLastname(),
                        'email' => $customer->getEmail()
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->_logger->warning('[Grid::getMagentoCustomersByCnpj] Exceção ignorada: ' . $e->getMessage());
        }

        return $this->magentoCustomersByCnpj;
    }

    /**
     * Get Magento customer linked to an ERP customer
     */
    public function getMagentoCustomer(?string $cnpj): ?array
    {
        if (empty($cnpj)) {
            return null;
        }

        $cleanCnpj = preg_replace('/\D/', '', $cnpj);
        $customers = $this->getMagentoCustomersByCnpj();

        return $customers[$cleanCnpj] ?? null;
    }

    /**
     * Get Magento customer edit URL
     */
    public function getMagentoCustomerUrl(int $customerId): string
    {
        return $this->getUrl('customer/index/edit', ['id' => $customerId]);
    }

    /**
     * Get statistics for summary
     */
    public function getStats(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        try {
            // Count by type
            $stats = $this->connection->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN CKCLIENTE = 'S' THEN 1 ELSE 0 END) as clientes,
                    SUM(CASE WHEN CKCLIENTE <> 'S' OR CKCLIENTE IS NULL THEN 1 ELSE 0 END) as fornecedores
                FROM FN_FORNECEDORES
                WHERE ATCLIENTE = 'S'
            ");

            return $stats[0] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
