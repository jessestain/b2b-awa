<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Test\Unit\Model\Product;

use GrupoAwamotos\ERPIntegration\Model\Product\InactiveProductDeactivationGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\ERPIntegration\Model\Product\InactiveProductDeactivationGuard
 */
class InactiveProductDeactivationGuardTest extends TestCase
{
    public function testSkipsWhenErpReturnsNoMaterials(): void
    {
        $this->assertFalse(InactiveProductDeactivationGuard::shouldDeactivate(0, 0));
    }

    public function testSkipsWhenErpReturnsMaterialsButZeroActive(): void
    {
        $this->assertFalse(InactiveProductDeactivationGuard::shouldDeactivate(15064, 0));
    }

    public function testAllowsWhenActiveScopeIsNonEmpty(): void
    {
        $this->assertTrue(InactiveProductDeactivationGuard::shouldDeactivate(15064, 637));
    }
}
