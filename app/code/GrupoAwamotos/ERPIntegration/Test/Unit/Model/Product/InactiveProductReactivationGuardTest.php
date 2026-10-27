<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Test\Unit\Model\Product;

use GrupoAwamotos\ERPIntegration\Model\Product\InactiveProductReactivationGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\ERPIntegration\Model\Product\InactiveProductReactivationGuard
 */
class InactiveProductReactivationGuardTest extends TestCase
{
    /**
     * A consulta ao Sectra nao pode resultar em "reative tudo".
     */
    public function testNaoReativaQuandoOConsultaVoltaVazia(): void
    {
        $this->assertFalse(InactiveProductReactivationGuard::shouldReactivate(0, 0));
    }

    public function testNaoReativaQuandoNaoHaNenhumAtivoNoErp(): void
    {
        $this->assertFalse(InactiveProductReactivationGuard::shouldReactivate(15088, 0));
    }

    public function testReativaQuandoHaAtivosNoErp(): void
    {
        $this->assertTrue(InactiveProductReactivationGuard::shouldReactivate(15088, 631));
    }

    /**
     * Os casos reais medidos em 06/10/2026.
     */
    public function testPrecoPlaceholderDeUmCentavoNaoPassa(): void
    {
        $this->assertFalse(InactiveProductReactivationGuard::hasUsablePrice(0.01));
    }

    public function testPrecoZeroNaoPassa(): void
    {
        $this->assertFalse(InactiveProductReactivationGuard::hasUsablePrice(0.0));
    }

    public function testPrecoAusenteNaoPassa(): void
    {
        $this->assertFalse(InactiveProductReactivationGuard::hasUsablePrice(null));
    }

    /**
     * Limite exato: o piso e inclusivo. R$ 1,00 e um preco; R$ 0,99 nao e tratado
     * como placeholder legado, mas tambem nao passa — o corte e no piso.
     */
    public function testPrecoNoPisoExatoPassa(): void
    {
        $this->assertTrue(InactiveProductReactivationGuard::hasUsablePrice(1.00));
    }

    public function testPrecoAbaixoDoPisoNaoPassa(): void
    {
        $this->assertFalse(InactiveProductReactivationGuard::hasUsablePrice(0.99));
    }

    /**
     * Itens legitimamente baratos do catalogo nao podem ser bloqueados.
     * Medido: "LENTE / MINI PISCA" a R$ 0,97 e "RET. BIZ" a R$ 3,16.
     * Este teste documenta que o piso de R$ 1,00 e uma escolha consciente e que
     * um item de R$ 0,97 fica retido — de proposito, para revisao humana.
     */
    public function testItemDeNoventaESeteCentavosFicaRetidoDeProposito(): void
    {
        $this->assertFalse(InactiveProductReactivationGuard::hasUsablePrice(0.97));
    }

    public function testPrecosReaisDoCatalogoPassam(): void
    {
        $this->assertTrue(InactiveProductReactivationGuard::hasUsablePrice(3.16));
        $this->assertTrue(InactiveProductReactivationGuard::hasUsablePrice(6.55));
        $this->assertTrue(InactiveProductReactivationGuard::hasUsablePrice(168.47));
    }
}
