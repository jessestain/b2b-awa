<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Test\Unit\Model\Product;

use GrupoAwamotos\ERPIntegration\Model\Product\CatalogPublicationGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\ERPIntegration\Model\Product\CatalogPublicationGuard
 */
class CatalogPublicationGuardTest extends TestCase
{
    public function testPrecoAusenteEPlaceholder(): void
    {
        $this->assertTrue(CatalogPublicationGuard::isPlaceholderPrice(null));
    }

    public function testPrecoZeroEUmCentavoSaoPlaceholder(): void
    {
        $this->assertTrue(CatalogPublicationGuard::isPlaceholderPrice(0.0));
        $this->assertTrue(CatalogPublicationGuard::isPlaceholderPrice(0.01));
    }

    /**
     * Item legitimamente barato (lente a R$ 0,97) nao pode ser desabilitado em massa.
     */
    public function testPrecoLegitimamenteBaratoNaoEPlaceholder(): void
    {
        $this->assertFalse(CatalogPublicationGuard::isPlaceholderPrice(0.97));
        $this->assertFalse(CatalogPublicationGuard::isPlaceholderPrice(0.02));
        $this->assertFalse(CatalogPublicationGuard::isPlaceholderPrice(168.47));
    }

    public function testPrecoEntrePlaceholderEPisoVaiParaRevisao(): void
    {
        $this->assertTrue(CatalogPublicationGuard::isLowPriceForReview(0.97));
        $this->assertFalse(CatalogPublicationGuard::isLowPriceForReview(0.01));
        $this->assertFalse(CatalogPublicationGuard::isLowPriceForReview(1.00));
        $this->assertFalse(CatalogPublicationGuard::isLowPriceForReview(null));
    }

    public function testProdutoPresenteNoSectraPorSkuExatoNaoFalta(): void
    {
        $this->assertFalse(CatalogPublicationGuard::isMissingFromErp(['1165'], ['1165' => true], []));
    }

    /**
     * Magento guarda o SKU base ("1119"), o Sectra tem "1119 RS".
     */
    public function testProdutoPresenteNoSectraPeloSkuBaseNaoFalta(): void
    {
        $this->assertFalse(CatalogPublicationGuard::isMissingFromErp(
            ['1119'],
            ['1119 RS' => true],
            ['1119' => true]
        ));
    }

    public function testProdutoPresentePeloCodigoErpMapeadoNaoFalta(): void
    {
        $this->assertFalse(CatalogPublicationGuard::isMissingFromErp(
            ['SKU-ANTIGO', '022.012-6001'],
            ['022.012-6001' => true],
            []
        ));
    }

    public function testProdutoAusenteDoSectraFalta(): void
    {
        $this->assertTrue(CatalogPublicationGuard::isMissingFromErp(
            ['LEGADO-1'],
            ['1165' => true],
            ['1165' => true]
        ));
    }

    /**
     * Sem informacao nao se desabilita.
     */
    public function testSemChaveUtilizavelNaoDesabilita(): void
    {
        $this->assertFalse(CatalogPublicationGuard::isMissingFromErp([], ['1165' => true], []));
        $this->assertFalse(CatalogPublicationGuard::isMissingFromErp(['', ''], ['1165' => true], []));
    }

    public function testLimiteDeSegurancaPermiteAjusteNormal(): void
    {
        $this->assertTrue(CatalogPublicationGuard::withinSafetyLimit(0, 700, 0.10));
        $this->assertTrue(CatalogPublicationGuard::withinSafetyLimit(70, 700, 0.10));
    }

    public function testLimiteDeSegurancaBarraVolumeAnormal(): void
    {
        $this->assertFalse(CatalogPublicationGuard::withinSafetyLimit(71, 700, 0.10));
        $this->assertFalse(CatalogPublicationGuard::withinSafetyLimit(700, 700, 0.10));
    }

    public function testSemProdutoHabilitadoQualquerCandidatoEAnomalo(): void
    {
        $this->assertFalse(CatalogPublicationGuard::withinSafetyLimit(1, 0, 0.10));
    }
}
