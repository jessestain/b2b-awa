<?php

declare(strict_types=1);

namespace GrupoAwamotos\AiAssistant\Test\Unit\Model\Copilot;

use GrupoAwamotos\AiAssistant\Model\Copilot\CustomerHealth;
use PHPUnit\Framework\TestCase;

class CustomerHealthTest extends TestCase
{
    private CustomerHealth $health;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        if (!class_exists(CustomerHealth::class, false)) {
            require_once dirname(__DIR__, 4) . '/Model/Copilot/CustomerHealth.php';
        }
        $this->health = new CustomerHealth();
        $this->today = new \DateTimeImmutable('2026-10-16');
    }

    public function testNoOrders(): void
    {
        $h = $this->health->classify(0, null, null, $this->today);
        $this->assertSame(CustomerHealth::SEM_COMPRAS, $h['status']);
        $this->assertNull($h['cycle_days']);
    }

    /**
     * @dataProvider singleOrderCases
     */
    public function testSingleOrderUsesFixedBands(string $last, string $expected): void
    {
        $this->assertSame($expected, $this->health->classify(1, $last, $last, $this->today)['status']);
    }

    public static function singleOrderCases(): array
    {
        return [
            ['2026-10-01', CustomerHealth::NOVO],
            ['2026-07-01', CustomerHealth::ATRASADO],
            ['2026-01-10', CustomerHealth::ESFRIANDO],
            ['2025-06-01', CustomerHealth::INATIVO],
        ];
    }

    public function testCycleAndStatusFromHistory(): void
    {
        // 5 pedidos em 120 dias => ciclo 30; última compra há 20 dias => em dia
        $h = $this->health->classify(5, '2026-05-29', '2026-09-26', $this->today);
        $this->assertSame(30, $h['cycle_days']);
        $this->assertSame(20, $h['days_since_last']);
        $this->assertSame(CustomerHealth::EM_DIA, $h['status']);
        $this->assertSame('2026-10-26', $h['next_expected']);
    }

    /**
     * @dataProvider ratioCases
     */
    public function testRatioBands(string $last, string $expected): void
    {
        // ciclo fixo de 30 dias: 5 pedidos, 120 dias de span terminando em $last
        $first = (new \DateTimeImmutable($last))->modify('-120 days')->format('Y-m-d');
        $this->assertSame($expected, $this->health->classify(5, $first, $last, $this->today)['status']);
    }

    public static function ratioCases(): array
    {
        return [
            'ratio 1.2 => em dia'     => ['2026-09-10', CustomerHealth::EM_DIA],
            'ratio 1.5 => atrasado'   => ['2026-09-01', CustomerHealth::ATRASADO],
            'ratio 3 => esfriando'    => ['2026-07-18', CustomerHealth::ESFRIANDO],
            'ratio 5 => inativo'      => ['2026-05-19', CustomerHealth::INATIVO],
        ];
    }

    public function testCycleHasFloorOfSevenDays(): void
    {
        $h = $this->health->classify(10, '2026-10-01', '2026-10-03', $this->today);
        $this->assertSame(7, $h['cycle_days']);
    }

    public function testPriorityFavoursValuableLateCustomers(): void
    {
        $late = $this->health->classify(5, '2026-04-19', '2026-08-17', $this->today);   // ~2x
        $onTime = $this->health->classify(5, '2026-05-29', '2026-09-26', $this->today); // 20/30
        $this->assertGreaterThan(
            $this->health->priority($late, 1000.0),
            $this->health->priority($late, 5000.0)
        );
        $this->assertSame(0.0, $this->health->priority($onTime, 50000.0));
        $this->assertSame(0.0, $this->health->priority(['status' => CustomerHealth::SEM_COMPRAS], 1000.0));
    }

    public function testReorderCandidatesOnlyRegularAndOverdueItems(): void
    {
        $items = [
            ['sku' => 'A', 'name' => 'Relação', 'order_count' => 6, 'days_since_last' => 50, 'avg_price' => 80],
            ['sku' => 'B', 'name' => 'Pastilha', 'order_count' => 1, 'days_since_last' => 200, 'avg_price' => 20],
            ['sku' => 'C', 'name' => 'Cabo', 'order_count' => 4, 'days_since_last' => 10, 'avg_price' => 15],
            ['sku' => 'D', 'name' => 'Manete', 'order_count' => 6, 'days_since_last' => 90, 'avg_price' => 30],
        ];
        $out = $this->health->reorderCandidates($items, 30);
        $this->assertSame(['D', 'A'], array_column($out, 'sku'));
        $this->assertSame(['habito', 'habito'], array_column($out, 'basis'));
    }

    public function testReorderFallsBackToBiggestSingleBuysWhenNoHabit(): void
    {
        $items = [
            ['sku' => 'X', 'name' => 'Retrovisor', 'order_count' => 1, 'days_since_last' => 52, 'total_qty' => 10],
            ['sku' => 'Y', 'name' => 'Suporte', 'order_count' => 1, 'days_since_last' => 52, 'total_qty' => 40],
            ['sku' => 'Z', 'name' => 'Pisca', 'order_count' => 1, 'days_since_last' => 5, 'total_qty' => 99],
        ];
        $out = $this->health->reorderCandidates($items, 24);
        $this->assertSame(['Y', 'X'], array_column($out, 'sku'));
        $this->assertSame('compra_unica', $out[0]['basis']);
    }
}
