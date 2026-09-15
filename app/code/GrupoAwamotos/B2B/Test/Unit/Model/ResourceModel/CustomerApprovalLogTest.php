<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Model\ResourceModel;

use GrupoAwamotos\B2B\Model\ResourceModel\CustomerApprovalLog;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the daily timeline aggregate is built through the framework
 * Select builder instead of a hand-written SQL string, while still returning
 * the original shape (day/registered/approved/rejected).
 *
 * @covers \GrupoAwamotos\B2B\Model\ResourceModel\CustomerApprovalLog
 */
class CustomerApprovalLogTest extends TestCase
{
    /** @var Context&MockObject */
    private Context $context;

    /** @var ResourceConnection&MockObject */
    private ResourceConnection $resources;

    /** @var AdapterInterface&MockObject */
    private AdapterInterface $connection;

    /** @var Select&MockObject */
    private Select $select;

    protected function setUp(): void
    {
        $this->context = $this->createMock(Context::class);
        $this->resources = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);

        // Make the fluent Select chain return itself for every method we touch,
        // so we can assert the binding without caring about real SQL generation.
        $self = $this->select;
        foreach (['from', 'where', 'group', 'order'] as $method) {
            $self->method($method)->willReturnSelf();
        }
        $this->connection->method('select')->willReturn($self);
        $this->connection->method('getTableName')->willReturnArgument(0);

        $this->resources->method('getConnection')->willReturn($this->connection);
        $this->context->method('getResources')->willReturn($this->resources);
    }

    public function testGetDailyTimelineBuildsSelectAndReturnsRows(): void
    {
        $rows = [
            ['day' => '2026-09-01', 'registered' => '3', 'approved' => '1', 'rejected' => '0'],
            ['day' => '2026-09-02', 'registered' => '5', 'approved' => '4', 'rejected' => '1'],
        ];

        $this->connection
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->select)
            ->willReturn($rows);

        $log = new CustomerApprovalLog(
            $this->context,
            'grupoawamotos_b2b_customer_approval_log',
            'log_id'
        );

        $result = $log->getDailyTimeline(7);

        $this->assertSame($rows, $result);
    }

    public function testGetDailyTimelineBindsDaysParameterAsInt(): void
    {
        $this->connection->expects($this->once())->method('fetchAll')->willReturn([]);

        // The where() call must receive the days window already cast to int,
        // so that no string flows into the prepared statement.
        $this->select
            ->expects($this->once())
            ->method('where')
            ->with(
                $this->stringContains('DATE_SUB'),
                $this->isType('integer')
            )
            ->willReturnSelf();

        $log = new CustomerApprovalLog(
            $this->context,
            'grupoawamotos_b2b_customer_approval_log',
            'log_id'
        );

        $log->getDailyTimeline(30);
    }
}
