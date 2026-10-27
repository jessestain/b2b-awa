<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\AddResultAggregator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Model/QuickCatalog/AddResultAggregator.php';

/**
 * Testes unitários do agregador de resultado por item (lote de add).
 */
class AddResultAggregatorTest extends TestCase
{
    /**
     * Sucesso parcial: 1 item válido + 1 erro.
     *
     * @return void
     */
    public function testPartialSuccess(): void
    {
        $aggregator = new AddResultAggregator();
        $aggregator->startItem('A');
        $aggregator->addSuccess('A', 2, 'Produto A', 7);
        $aggregator->startItem('B');
        $aggregator->addError('B', 'Sem estoque');

        $result = $aggregator->toArray();

        $this->assertCount(1, $result['added']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(2, $result['processed']);
        $this->assertSame(['added' => 1, 'failed' => 1], $result['summary']);
        $this->assertTrue($aggregator->hasAdded());
        $this->assertSame(7, $result['added'][0]['cart_qty_sku']);
    }

    /**
     * Agregador vazio não reporta sucesso.
     *
     * @return void
     */
    public function testEmptyAggregator(): void
    {
        $aggregator = new AddResultAggregator();

        $this->assertFalse($aggregator->hasAdded());
        $this->assertSame(
            [
                'added' => [],
                'errors' => [],
                'processed' => 0,
                'summary' => ['added' => 0, 'failed' => 0],
            ],
            $aggregator->toArray()
        );
    }
}
