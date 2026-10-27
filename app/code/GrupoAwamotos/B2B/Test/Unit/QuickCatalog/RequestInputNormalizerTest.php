<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\QuickCatalog;

use GrupoAwamotos\B2B\Model\QuickCatalog\RequestInputNormalizer;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/Model/QuickCatalog/RequestInputNormalizer.php';

/**
 * Testes unitários do normalizador puro de parâmetros das rotas do catálogo.
 */
class RequestInputNormalizerTest extends TestCase
{
    /**
     * Normalizador sob teste.
     *
     * @var RequestInputNormalizer
     */
    private RequestInputNormalizer $normalizer;

    /**
     * Prepara o objeto por teste.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->normalizer = new RequestInputNormalizer();
    }

    /**
     * Página mínima deve ser 1.
     *
     * @return void
     */
    public function testClampPageMinimum(): void
    {
        $this->assertSame(1, $this->normalizer->clampPage(0));
        $this->assertSame(1, $this->normalizer->clampPage(-5));
        $this->assertSame(7, $this->normalizer->clampPage(7));
    }

    /**
     * Limite deve ficar entre 1 e o teto.
     *
     * @return void
     */
    public function testClampLimitRange(): void
    {
        $this->assertSame(1, $this->normalizer->clampLimit(0));
        $this->assertSame(RequestInputNormalizer::MAX_PAGE_SIZE, $this->normalizer->clampLimit(999999));
        $this->assertSame(24, $this->normalizer->clampLimit(24));
        $this->assertSame(RequestInputNormalizer::DEFAULT_PAGE_SIZE, 24);
    }

    /**
     * product_id válido apenas para inteiros positivos.
     *
     * @return void
     */
    public function testIsValidProductId(): void
    {
        $this->assertTrue($this->normalizer->isValidProductId(123));
        $this->assertFalse($this->normalizer->isValidProductId(0));
        $this->assertFalse($this->normalizer->isValidProductId(-7));
    }
}
