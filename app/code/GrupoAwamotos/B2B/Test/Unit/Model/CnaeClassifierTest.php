<?php

declare(strict_types=1);

namespace GrupoAwamotos\B2B\Test\Unit\Model;

use GrupoAwamotos\B2B\Model\CnaeClassifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\B2B\Model\CnaeClassifier
 */
class CnaeClassifierTest extends TestCase
{
    public function testDefaultDirectCnaesIncludeRetailMotorcyclePartsNew(): void
    {
        $this->assertContains('4541-2/06', CnaeClassifier::DEFAULT_DIRECT_CNAES);
        $this->assertContains('4541-2/05', CnaeClassifier::DEFAULT_DIRECT_CNAES);
    }
}
