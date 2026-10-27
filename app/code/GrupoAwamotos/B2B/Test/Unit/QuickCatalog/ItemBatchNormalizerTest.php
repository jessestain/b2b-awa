<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\ItemBatchNormalizer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Model/QuickCatalog/ItemBatchNormalizer.php';

/**
 * Testes unitários do normalizador puro de lotes (add individual/em massa).
 */
class ItemBatchNormalizerTest extends TestCase
{
    /**
     * Normalizador sob teste.
     *
     * @var ItemBatchNormalizer
     */
    private ItemBatchNormalizer $normalizer;

    /**
     * Prepara o objeto por teste.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->normalizer = new ItemBatchNormalizer();
    }

    /**
     * SKUs repetidos no mesmo lote somam quantidade (case-insensitive).
     *
     * @return void
     */
    public function testDedupeBySkuCaseInsensitive(): void
    {
        $items = $this->normalizer->normalize([
            ['sku' => 'abc', 'qty' => 2],
            ['sku' => 'ABC', 'qty' => 3],
        ]);

        $this->assertCount(1, $items);
        $this->assertSame(5, $items[0]['qty']);
    }

    /**
     * Quantidade inválida vira 1 e teto é respeitado.
     *
     * @return void
     */
    public function testQtyBounds(): void
    {
        $items = $this->normalizer->normalize([
            ['sku' => 'X', 'qty' => 0],
            ['sku' => 'Y', 'qty' => 999999],
        ]);

        $this->assertSame(1, $items[0]['qty']);
        $this->assertSame(ItemBatchNormalizer::MAX_QTY_PER_SKU, $items[1]['qty']);
    }

    /**
     * SKU vazio é ignorado e itens não-array são descartados.
     *
     * @return void
     */
    public function testInvalidEntriesIgnored(): void
    {
        $items = $this->normalizer->normalize([
            ['sku' => '   ', 'qty' => 1],
            'invalido',
            ['sku' => 'OK', 'qty' => 1],
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('OK', $items[0]['sku']);
    }

    /**
     * request_id válido (uuid) é aceito e formatos inválidos rejeitados.
     *
     * @return void
     */
    public function testRequestIdValidation(): void
    {
        $this->assertTrue($this->normalizer->isValidRequestId('123e4567-e89b-42d3-a456-426614174000'));
        $this->assertFalse($this->normalizer->isValidRequestId('abc'));
        $this->assertFalse($this->normalizer->isValidRequestId('abc def ghij'));
        $this->assertFalse($this->normalizer->isValidRequestId(str_repeat('a', 65)));
        $this->assertFalse($this->normalizer->isValidRequestId('abc<>defgh'));
    }
}
