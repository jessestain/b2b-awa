<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\WhatsAppCommerce\Api\AdminDashboardInterface;

/**
 * Tool: lista os produtos mais vendidos num período (Admin only, somente leitura).
 */
class AdminTopSellingTool implements ToolInterface
{
    public function __construct(
        private readonly AdminDashboardInterface $adminDashboard,
        private readonly \GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope $portfolioScope,
        private readonly \Magento\Framework\App\ResourceConnection $resource
    ) {
    }

    public function getName(): string
    {
        return 'admin_top_selling';
    }

    public function getDescription(): string
    {
        return 'Lista os produtos mais vendidos nos últimos N dias. Somente leitura.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'days' => [
                    'type'        => 'integer',
                    'description' => 'Número de dias a considerar (padrão: 30).',
                    'default'     => 30,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        $days = max(1, min(365, (int) ($arguments['days'] ?? 30)));

        if ($this->portfolioScope->canBypassPortfolioScope()) {
            return $this->adminDashboard->topSelling($days);
        }

        /* Escopo por carteira: mesma consulta do dashboard, restrita aos
         * clientes visíveis em PortfolioScope (regras do cockpit comercial). */
        $customerIds = $this->portfolioScope->getVisibleCustomerIds();
        if ($customerIds === []) {
            return ['scope' => 'carteira', 'period_days' => $days, 'products' => [],
                'message' => 'Sua carteira não tem clientes vinculados.'];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['oi' => $this->resource->getTableName('sales_order_item')],
                [
                    'sku'           => 'oi.sku',
                    'name'          => 'oi.name',
                    'total_qty'     => new \Zend_Db_Expr('SUM(oi.qty_ordered)'),
                    'total_revenue' => new \Zend_Db_Expr('SUM(oi.row_total)'),
                ]
            )
            ->join(['o' => $this->resource->getTableName('sales_order')], 'oi.order_id = o.entity_id', [])
            ->where('o.customer_id IN (?)', $customerIds)
            ->where('o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)', $days)
            ->where('o.state NOT IN (?)', ['canceled', 'closed'])
            ->where('oi.parent_item_id IS NULL')
            ->group('oi.sku')
            ->order('total_qty DESC')
            ->limit(10);

        $products = [];
        foreach ($connection->fetchAll($select) as $row) {
            $products[] = [
                'sku'     => (string) $row['sku'],
                'name'    => (string) $row['name'],
                'qty'     => (int) $row['total_qty'],
                'revenue' => round((float) $row['total_revenue'], 2),
            ];
        }

        return [
            'scope'          => 'carteira',
            'portfolio_size' => count($customerIds),
            'period_days'    => $days,
            'products'       => $products,
            'note'           => 'Ranking restrito à sua carteira de clientes.',
        ];
    }

    public function getAllowedChannels(): array
    {
        return [AssistantOrchestratorInterface::CHANNEL_ADMIN];
    }
}
