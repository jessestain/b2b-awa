<?php

/**
 * Block: Meta B2B Funnel Dashboard
 *
 * Reads existing B2B tables and maps actions to Meta CAPI event names.
 * No new tables — uses grupoawamotos_b2b_customer_approval_log,
 * grupoawamotos_b2b_quote_request, grupoawamotos_b2b_company,
 * grupoawamotos_b2b_credit_limit.
 */

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Block\Adminhtml\Dashboard;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ResourceConnection;

class MetaFunnel extends Template
{
    private const META_EVENT_MAP = [
        'registered' => 'CompleteRegistration',
        'approved'   => 'SubmitApplication ✓',
        'rejected'   => 'SubmitApplication ✗',
        'suspended'  => '—',
    ];

    private const ALLOWED_PERIODS = [7, 30, 90, 0];

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resourceConnection,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Returns requested period in days. 0 = all time.
     */
    public function getPeriod(): int
    {
        $raw = (int) $this->getRequest()->getParam('period', 30);

        return in_array($raw, self::ALLOWED_PERIODS, true) ? $raw : 30;
    }

    /**
     * Returns a human-readable label for the current period.
     */
    public function getPeriodLabel(): string
    {
        return match ($this->getPeriod()) {
            7       => 'Últimos 7 dias',
            30      => 'Últimos 30 dias',
            90      => 'Últimos 90 dias',
            0       => 'Todo o período',
            default => 'Últimos 30 dias',
        };
    }

    /**
     * Builds a URL to switch the active period filter.
     */
    public function getPeriodUrl(int $period): string
    {
        return $this->getUrl('*/*/index', ['period' => $period]);
    }

    /**
     * Returns CSV export URL preserving selected period.
     */
    public function getExportCsvUrl(): string
    {
        return $this->getUrl('*/*/exportCsv', ['period' => $this->getPeriod()]);
    }

    /**
     * Returns the Meta CAPI event name for a given approval action.
     */
    public function getMetaEventLabel(string $action): string
    {
        return self::META_EVENT_MAP[$action] ?? '—';
    }

    /**
     * Returns the CSS badge class for a given approval action.
     */
    public function getActionBadgeClass(string $action): string
    {
        return match ($action) {
            'approved'   => 'b2b-meta-badge-success',
            'rejected'   => 'b2b-meta-badge-danger',
            'registered' => 'b2b-meta-badge-info',
            'suspended'  => 'b2b-meta-badge-warning',
            default      => 'b2b-meta-badge-neutral',
        };
    }

    /**
     * Formats a datetime string to Brazilian d/m/Y H:i format.
     */
    public function formatBrDate(string $datetime): string
    {
        try {
            return (new \DateTime($datetime))->format('d/m/Y H:i');
        } catch (\Exception) {
            return $datetime;
        }
    }

    /**
     * Returns the six KPI cards for the funnel header.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getKpis(): array
    {
        $conn   = $this->resourceConnection->getConnection();
        $period = $this->getPeriod();
        $pc     = $this->buildDateCondition($period);

        $logTable    = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer_approval_log');
        $companyTable = $this->resourceConnection->getTableName('grupoawamotos_b2b_company');

        $registered = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$logTable} WHERE action = 'registered'{$pc}"
        );
        $approved = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$logTable} WHERE action = 'approved'{$pc}"
        );
        $rejected = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$logTable} WHERE action = 'rejected'{$pc}"
        );
        $pending    = max(0, $registered - $approved - $rejected);
        $total      = $approved + $rejected;
        $taxaAprov  = $total > 0 ? round(($approved / $total) * 100, 1) : 0.0;
        $empresas   = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$companyTable} WHERE is_active = 1"
        );

        return [
            [
                'label'       => 'Cadastros B2B',
                'value'       => $registered,
                'meta_event'  => 'CompleteRegistration',
                'color'       => '#6366f1',
                'icon'        => '📋',
                'description' => 'Formulários B2B enviados',
            ],
            [
                'label'       => 'Aprovados',
                'value'       => $approved,
                'meta_event'  => 'SubmitApplication ✓',
                'color'       => '#059669',
                'icon'        => '✅',
                'description' => 'Clientes liberados para compra',
            ],
            [
                'label'       => 'Rejeitados',
                'value'       => $rejected,
                'meta_event'  => 'SubmitApplication ✗',
                'color'       => '#dc2626',
                'icon'        => '❌',
                'description' => 'Cadastros não aprovados',
            ],
            [
                'label'       => 'Em Análise',
                'value'       => $pending,
                'meta_event'  => 'Lead',
                'color'       => '#d97706',
                'icon'        => '⏳',
                'description' => 'Aguardando revisão admin',
            ],
            [
                'label'       => 'Taxa de Aprovação',
                'value'       => $taxaAprov . '%',
                'meta_event'  => '—',
                'color'       => $taxaAprov >= 70 ? '#059669' : ($taxaAprov >= 40 ? '#d97706' : '#dc2626'),
                'icon'        => '📊',
                'description' => 'Aprovados ÷ (Aprovados + Rejeitados)',
            ],
            [
                'label'       => 'Empresas Ativas',
                'value'       => $empresas,
                'meta_event'  => '—',
                'color'       => '#0ea5e9',
                'icon'        => '🏢',
                'description' => 'Total geral (sem filtro de período)',
            ],
        ];
    }

    /**
     * Returns aggregate quote stats + total credit granted.
     *
     * @return array<string, mixed>
     */
    public function getQuoteStats(): array
    {
        $conn        = $this->resourceConnection->getConnection();
        $period      = $this->getPeriod();
        $pc          = $this->buildDateCondition($period);
        $quoteTable  = $this->resourceConnection->getTableName('grupoawamotos_b2b_quote_request');
        $creditTable = $this->resourceConnection->getTableName('grupoawamotos_b2b_credit_limit');

        $abertas     = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$quoteTable} WHERE status IN ('pending','processing'){$pc}"
        );
        $negociacao  = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$quoteTable} WHERE status = 'quoted'{$pc}"
        );
        $convertidas = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$quoteTable} WHERE status IN ('accepted','converted'){$pc}"
        );
        $creditoTotal = (float) $conn->fetchOne(
            "SELECT COALESCE(SUM(credit_limit), 0) FROM {$creditTable}"
        );

        return [
            'abertas'       => $abertas,
            'negociacao'    => $negociacao,
            'convertidas'   => $convertidas,
            'credito_total' => 'R$ ' . number_format($creditoTotal, 0, ',', '.'),
        ];
    }

    /**
     * Returns advanced KPI highlights based on funnel and quote totals.
     *
     * @return array<string, string>
     */
    public function getAdvancedKpis(): array
    {
        $conn   = $this->resourceConnection->getConnection();
        $period = $this->getPeriod();
        $pc     = $this->buildDateCondition($period);

        $logTable   = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer_approval_log');
        $quoteTable = $this->resourceConnection->getTableName('grupoawamotos_b2b_quote_request');

        $registered = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$logTable} WHERE action = 'registered'{$pc}"
        );
        $approved = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$logTable} WHERE action = 'approved'{$pc}"
        );
        $rejected = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$logTable} WHERE action = 'rejected'{$pc}"
        );

        $quotesTotal = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$quoteTable} WHERE 1=1{$pc}"
        );
        $quotesConverted = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$quoteTable} WHERE status IN ('accepted','converted'){$pc}"
        );

        $approvalRate = $registered > 0 ? round(($approved / $registered) * 100, 1) : 0.0;
        $rejectionRate = $registered > 0 ? round(($rejected / $registered) * 100, 1) : 0.0;
        $quoteConversionRate = $quotesTotal > 0 ? round(($quotesConverted / $quotesTotal) * 100, 1) : 0.0;

        return [
            'approval_rate' => number_format($approvalRate, 1, ',', '.') . '%',
            'rejection_rate' => number_format($rejectionRate, 1, ',', '.') . '%',
            'quote_conversion_rate' => number_format($quoteConversionRate, 1, ',', '.') . '%',
            'total_interactions' => (string) ($registered + $approved + $rejected),
        ];
    }

    /**
     * Returns daily trend data for chart rendering.
     *
     * @return array<string, array<int, int|string>>
     */
    public function getDailyTimeline(): array
    {
        $conn = $this->resourceConnection->getConnection();
        $logTable = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer_approval_log');
        $days = $this->getChartWindowDays();

        // Same query as before, but built with the framework Select so the
        // bound values flow through Varien_Db_Select's parameter binding.
        $select = $conn->select()
            ->from(
                $logTable,
                [
                    'day'        => new \Zend_Db_Expr('DATE(created_at)'),
                    'registered' => new \Zend_Db_Expr("SUM(CASE WHEN action = 'registered' THEN 1 ELSE 0 END)"),
                    'approved'   => new \Zend_Db_Expr("SUM(CASE WHEN action = 'approved' THEN 1 ELSE 0 END)"),
                    'rejected'   => new \Zend_Db_Expr("SUM(CASE WHEN action = 'rejected' THEN 1 ELSE 0 END)"),
                ]
            )
            ->where('created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)', (int) $days)
            ->group(new \Zend_Db_Expr('DATE(created_at)'))
            ->order('day ASC');

        $rows = $conn->fetchAll($select);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['day']] = [
                'registered' => (int) ($row['registered'] ?? 0),
                'approved' => (int) ($row['approved'] ?? 0),
                'rejected' => (int) ($row['rejected'] ?? 0),
            ];
        }

        $labels = [];
        $registeredSeries = [];
        $approvedSeries = [];
        $rejectedSeries = [];

        $start = new \DateTimeImmutable(sprintf('-%d days', $days - 1));
        $today = new \DateTimeImmutable('today');

        for ($date = $start; $date <= $today; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $labels[] = $date->format('d/m');
            $registeredSeries[] = $indexed[$key]['registered'] ?? 0;
            $approvedSeries[] = $indexed[$key]['approved'] ?? 0;
            $rejectedSeries[] = $indexed[$key]['rejected'] ?? 0;
        }

        return [
            'labels' => $labels,
            'registered' => $registeredSeries,
            'approved' => $approvedSeries,
            'rejected' => $rejectedSeries,
        ];
    }

    /**
     * Returns the 25 most recent approval_log entries with customer and company data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentLog(): array
    {
        $conn          = $this->resourceConnection->getConnection();
        $period        = $this->getPeriod();
        $pc            = $this->buildDateCondition($period, 'l.created_at');
        $logTable      = $this->resourceConnection->getTableName('grupoawamotos_b2b_customer_approval_log');
        $customerTable = $this->resourceConnection->getTableName('customer_entity');
        $companyTable  = $this->resourceConnection->getTableName('grupoawamotos_b2b_company');

        $sql = "SELECT
                    l.log_id,
                    l.action,
                    l.comment,
                    l.created_at,
                    ce.email,
                    CONCAT(ce.firstname, ' ', ce.lastname) AS customer_name,
                    co.razao_social AS company_name,
                    co.cnpj
                FROM {$logTable} l
                INNER JOIN {$customerTable} ce ON ce.entity_id = l.customer_id
                LEFT  JOIN {$companyTable}  co ON co.admin_customer_id = l.customer_id
                WHERE 1=1{$pc}
                ORDER BY l.log_id DESC
                LIMIT 25";

        return $conn->fetchAll($sql);
    }

    /**
     * Builds a period WHERE clause fragment. Empty string when period = 0 (all time).
     */
    private function buildDateCondition(int $period, string $column = 'created_at'): string
    {
        if ($period === 0) {
            return '';
        }

        return " AND {$column} >= DATE_SUB(NOW(), INTERVAL {$period} DAY)";
    }

    /**
     * Uses last 30 days as chart window when "all time" is selected.
     */
    private function getChartWindowDays(): int
    {
        $period = $this->getPeriod();

        return $period === 0 ? 30 : $period;
    }
}
