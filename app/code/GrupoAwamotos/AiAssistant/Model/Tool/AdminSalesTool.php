<?php
declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Model\Tool;

use GrupoAwamotos\AiAssistant\Api\AssistantOrchestratorInterface;
use GrupoAwamotos\AiAssistant\Api\ToolInterface;
use GrupoAwamotos\B2B\CommercialPanel\Model\PortfolioScope;
use GrupoAwamotos\WhatsAppCommerce\Api\AdminDashboardInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Resumo de vendas para o copiloto Admin.
 *
 * Sem restrição de carteira (Administrators, Supervisora) delega ao dashboard
 * global. Com restrição (Vendedora) calcula só sobre os clientes visíveis em
 * PortfolioScope — as mesmas regras do cockpit comercial.
 */
class AdminSalesTool implements ToolInterface
{
    public function __construct(
        private readonly AdminDashboardInterface $adminDashboard,
        private readonly PortfolioScope $portfolioScope,
        private readonly ResourceConnection $resource
    ) {
    }

    public function getName(): string
    {
        return 'admin_sales_summary';
    }

    public function getDescription(): string
    {
        return 'Retorna o resumo de vendas de hoje (total, número de pedidos, ticket médio) e contagem de novos clientes. '
            . 'Para usuários com carteira restrita, considera apenas os clientes da própria carteira. Somente leitura.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, array $context = []): array
    {
        if ($this->portfolioScope->canBypassPortfolioScope()) {
            $sales     = $this->adminDashboard->salesToday();
            $customers = $this->adminDashboard->newCustomers();
            return ['scope' => 'loja', 'sales_today' => $sales, 'new_customers' => $customers];
        }

        $customerIds = $this->portfolioScope->getVisibleCustomerIds();
        if ($customerIds === []) {
            return [
                'scope'   => 'carteira',
                'message' => 'Sua carteira não tem clientes vinculados; não há vendas para resumir.',
            ];
        }

        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName('sales_order');

        $todayStart     = date('Y-m-d 00:00:00');
        $tomorrowStart  = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $yesterdayStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $monthStart     = date('Y-m-d 00:00:00', strtotime('-30 days'));

        $select = $connection->select()
            ->from($table, [
                'today_orders'      => new \Zend_Db_Expr(sprintf(
                    "SUM(CASE WHEN created_at >= %s AND created_at < %s THEN 1 ELSE 0 END)",
                    $connection->quote($todayStart),
                    $connection->quote($tomorrowStart)
                )),
                'today_revenue'     => new \Zend_Db_Expr(sprintf(
                    "COALESCE(SUM(CASE WHEN created_at >= %s AND created_at < %s THEN grand_total ELSE 0 END), 0)",
                    $connection->quote($todayStart),
                    $connection->quote($tomorrowStart)
                )),
                'yesterday_orders'  => new \Zend_Db_Expr(sprintf(
                    "SUM(CASE WHEN created_at >= %s AND created_at < %s THEN 1 ELSE 0 END)",
                    $connection->quote($yesterdayStart),
                    $connection->quote($todayStart)
                )),
                'yesterday_revenue' => new \Zend_Db_Expr(sprintf(
                    "COALESCE(SUM(CASE WHEN created_at >= %s AND created_at < %s THEN grand_total ELSE 0 END), 0)",
                    $connection->quote($yesterdayStart),
                    $connection->quote($todayStart)
                )),
                'month_orders'      => new \Zend_Db_Expr('COUNT(*)'),
                'month_revenue'     => new \Zend_Db_Expr('COALESCE(SUM(grand_total), 0)'),
                'month_customers'   => new \Zend_Db_Expr('COUNT(DISTINCT customer_id)'),
            ])
            ->where('customer_id IN (?)', $customerIds)
            ->where('created_at >= ?', $monthStart)
            ->where('created_at < ?', $tomorrowStart)
            ->where('state NOT IN (?)', ['canceled', 'closed']);

        $row = $connection->fetchRow($select) ?: [];

        $todayOrders  = (int) ($row['today_orders'] ?? 0);
        $todayRevenue = round((float) ($row['today_revenue'] ?? 0), 2);

        return [
            'scope'            => 'carteira',
            'portfolio_size'   => count($customerIds),
            'today_orders'     => $todayOrders,
            'today_revenue'    => $todayRevenue,
            'today_avg_ticket' => $todayOrders > 0 ? round($todayRevenue / $todayOrders, 2) : 0.0,
            'yesterday_orders' => (int) ($row['yesterday_orders'] ?? 0),
            'yesterday_revenue' => round((float) ($row['yesterday_revenue'] ?? 0), 2),
            'last_30d_orders'  => (int) ($row['month_orders'] ?? 0),
            'last_30d_revenue' => round((float) ($row['month_revenue'] ?? 0), 2),
            'last_30d_buying_customers' => (int) ($row['month_customers'] ?? 0),
            'note'             => 'Números restritos à sua carteira de clientes.',
        ];
    }

    public function getAllowedChannels(): array
    {
        return [AssistantOrchestratorInterface::CHANNEL_ADMIN];
    }
}
