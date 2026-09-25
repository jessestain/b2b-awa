<?php
declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Test\Unit\Model;

use GrupoAwamotos\ERPIntegration\Api\ConnectionInterface;
use GrupoAwamotos\ERPIntegration\Api\OrderPullInterface;
use GrupoAwamotos\ERPIntegration\Model\ErpOrderReconciler;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ErpOrderReconcilerTest extends TestCase
{
    public function testDoesNotAcknowledgeWhenSameLineCountHasDifferentQuantity(): void
    {
        $erp = $this->createMock(ConnectionInterface::class);
        $pull = $this->createMock(OrderPullInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $connection = $this->createMock(AdapterInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $pending = [
            ['orders' => [
                [
                    'increment_id' => '000000120',
                    'magento_id' => 108,
                    'grand_total' => 100.0,
                    'items' => [['sku' => 'SKU-1', 'qty' => 2]],
                ],
            ]],
        ];
        $pull->method('getPendingOrders')->willReturn($pending);
        $erp->method('query')->willReturnOnConsecutiveCalls(
            [[
                'CODIGO' => 196799, 'PEDIDOWEB' => '200108', 'VLRTOTAL' => 112.99,
            ]],
            [[
                'MATERIAL' => 'SKU-1', 'QTDE' => 1,
            ]]
        );

        // Both orders have one line. The quantity, however, differs: Magento=2, ERP=1.
        // The production code must inspect the item structure and leave this for a human.
        $resource->method('getConnection')->willReturn($connection);
        $connection->method('fetchOne')->willReturn(1);
        $erp->method('fetchOne')->willReturn(['LINHAS' => 1]);
        $pull->expects($this->never())->method('acknowledgeOrder');

        $reconciler = new ErpOrderReconciler($erp, $pull, $resource, $logger);
        $result = $reconciler->reconciliar(true);

        $this->assertCount(1, $result['requer_conferencia']);
        $this->assertSame(2.0, $result['requer_conferencia'][0]['quantidade_magento']);
        $this->assertSame(1.0, $result['requer_conferencia'][0]['quantidade_erp']);
    }
}
