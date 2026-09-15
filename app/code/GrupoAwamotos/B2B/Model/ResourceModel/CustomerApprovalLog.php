<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CustomerApprovalLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('grupoawamotos_b2b_customer_approval_log', 'log_id');
    }

    /**
     * Returns daily approval timeline for the last $days days.
     *
     * Uses the framework Select builder (which binds values automatically) and
     * keeps the original aggregate columns so the public contract is unchanged.
     *
     * @param int $days Window size in days. Must be > 0.
     * @return array<int, array<string, int|string>>
     */
    public function getDailyTimeline(int $days): array
    {
        $connection = $this->getConnection();
        $table = $this->getMainTable();

        // Build aggregations as Zend_Db_Expr so the SQL stays identical to the previous
        // hand-written string, while leveraging Varien_Db_Select parameter binding.
        $registeredExpr = new \Zend_Db_Expr("SUM(CASE WHEN action = 'registered' THEN 1 ELSE 0 END)");
        $approvedExpr   = new \Zend_Db_Expr("SUM(CASE WHEN action = 'approved' THEN 1 ELSE 0 END)");
        $rejectedExpr   = new \Zend_Db_Expr("SUM(CASE WHEN action = 'rejected' THEN 1 ELSE 0 END)");

        $select = $connection->select()
            ->from(
                $table,
                [
                    'day'        => new \Zend_Db_Expr('DATE(created_at)'),
                    'registered' => $registeredExpr,
                    'approved'   => $approvedExpr,
                    'rejected'   => $rejectedExpr,
                ]
            )
            ->where('created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)', (int) $days)
            ->group(new \Zend_Db_Expr('DATE(created_at)'))
            ->order('day ASC');

        return $connection->fetchAll($select);
    }

    public function getRecentLogsWithJoin(?string $fromDate = null, int $limit = 25): array
    {
        return $this->getLogsWithJoin($fromDate, $limit);
    }

    public function getLogsForExport(?string $fromDate = null): array
    {
        return $this->getLogsWithJoin($fromDate, null);
    }

    private function getLogsWithJoin(?string $fromDate = null, ?int $limit = null): array
    {
        $connection = $this->getConnection();
        $logTable = $this->getMainTable();
        $customerTable = $connection->getTableName('customer_entity');
        $companyTable = $connection->getTableName('grupoawamotos_b2b_company');

        $select = $connection->select()
            ->from(['l' => $logTable], [
                'l.log_id',
                'l.action',
                'l.comment',
                'l.created_at',
            ])
            ->join(
                ['ce' => $customerTable],
                'ce.entity_id = l.customer_id',
                ['email' => 'ce.email', 'customer_name' => new \Zend_Db_Expr("CONCAT(ce.firstname, ' ', ce.lastname)")]
            )
            ->joinLeft(
                ['co' => $companyTable],
                'co.admin_customer_id = l.customer_id',
                ['company_name' => 'co.razao_social', 'cnpj' => 'co.cnpj']
            )
            ->order('l.log_id DESC');

        if ($fromDate) {
            $select->where('l.created_at >= ?', $fromDate);
        }

        if ($limit) {
            $select->limit($limit);
        }

        return $connection->fetchAll($select);
    }
}
