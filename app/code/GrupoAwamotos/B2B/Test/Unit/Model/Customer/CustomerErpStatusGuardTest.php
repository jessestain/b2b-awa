<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Model\Customer;

use GrupoAwamotos\B2B\Model\Customer\CustomerErpStatusGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\Model\Customer\CustomerErpStatusGuard
 */
class CustomerErpStatusGuardTest extends TestCase
{
    public function testClienteAtivoNaoEMarcado(): void
    {
        $this->assertNull(CustomerErpStatusGuard::reasonToFlag(true, 'S', 'S'));
    }

    public function testClienteNaoEncontradoEMarcado(): void
    {
        $this->assertSame(
            CustomerErpStatusGuard::REASON_NOT_FOUND,
            CustomerErpStatusGuard::reasonToFlag(false, null, null)
        );
    }

    public function testClienteInativoEMarcado(): void
    {
        $this->assertSame(
            CustomerErpStatusGuard::REASON_INACTIVE,
            CustomerErpStatusGuard::reasonToFlag(true, 'S', 'N')
        );
    }

    public function testClienteSemCkClienteEMarcado(): void
    {
        $this->assertSame(
            CustomerErpStatusGuard::REASON_INACTIVE,
            CustomerErpStatusGuard::reasonToFlag(true, 'N', 'S')
        );
    }

    /**
     * "Nao encontrado" tem prioridade: sem linha, CKCLIENTE/ATCLIENTE nem existem.
     */
    public function testNaoEncontradoTemPrioridadeSobreInativo(): void
    {
        $this->assertSame(
            CustomerErpStatusGuard::REASON_NOT_FOUND,
            CustomerErpStatusGuard::reasonToFlag(false, 'S', 'S')
        );
    }

    public function testLimiteDeSegurancaPermiteVolumeMedido(): void
    {
        // 723 de 9139 ~= 7.9%, dentro do limite padrao de 15%
        $this->assertTrue(CustomerErpStatusGuard::withinSafetyLimit(723, 9139, 0.15));
    }

    public function testLimiteDeSegurancaBarraVolumeAnormal(): void
    {
        $this->assertFalse(CustomerErpStatusGuard::withinSafetyLimit(5000, 9139, 0.15));
    }

    public function testSemCandidatosSempreDentroDoLimite(): void
    {
        $this->assertTrue(CustomerErpStatusGuard::withinSafetyLimit(0, 0, 0.15));
    }

    public function testSemClientesChecadosQualquerCandidatoEAnomalo(): void
    {
        $this->assertFalse(CustomerErpStatusGuard::withinSafetyLimit(1, 0, 0.15));
    }
}
