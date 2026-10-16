<?php

declare(strict_types=1);

namespace GrupoAwamotos\ERPIntegration\Test\Unit\Model\Product;

use GrupoAwamotos\ERPIntegration\Model\Product\NonCatalogProductList;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\ERPIntegration\Model\Product\NonCatalogProductList
 */
class NonCatalogProductListTest extends TestCase
{
    /**
     * Os tres itens medidos em 06/10/2026: o Sectra os marca como ativo E
     * comercializado, mas nao sao peca de moto. O Jev os classificou como
     * nao_vender_nao_e_moto com confianca 1.00, 0.99 e 0.45.
     */
    public function testOsTresItensForaDoRamoEstaoListados(): void
    {
        $this->assertTrue(NonCatalogProductList::isExcluded('50150102'));
        $this->assertTrue(NonCatalogProductList::isExcluded('50150162'));
        $this->assertTrue(NonCatalogProductList::isExcluded('55102952'));
    }

    public function testCadaEntradaTemUmMotivo(): void
    {
        foreach (array_keys(NonCatalogProductList::all()) as $sku) {
            $this->assertNotEmpty(
                NonCatalogProductList::reason($sku),
                "SKU $sku esta na lista sem motivo escrito"
            );
        }
    }

    /**
     * Peca legitima de moto NAO pode entrar aqui. Este e o teste que impede a
     * lista de virar um segundo escopo escondido.
     */
    public function testPecasLegitimasNaoEstaoListadas(): void
    {
        foreach (['6013', '6014', '410 BLACK', '3122', '2385', '340 DR', '1005 VM', '20050058'] as $sku) {
            $this->assertFalse(
                NonCatalogProductList::isExcluded($sku),
                "SKU $sku e peca de moto e nao pode estar na lista de exclusao"
            );
        }
    }

    public function testSkuDesconhecidoNaoEstaListado(): void
    {
        $this->assertFalse(NonCatalogProductList::isExcluded('NAO-EXISTE-999'));
        $this->assertNull(NonCatalogProductList::reason('NAO-EXISTE-999'));
    }

    /**
     * Espaco em volta nao pode furar a lista — o Magento faz trim nos SKUs do
     * catalogo, e a comparacao precisa ser igual.
     */
    public function testEspacoEmVoltaNaoFuraALista(): void
    {
        $this->assertTrue(NonCatalogProductList::isExcluded(' 50150102 '));
        $this->assertTrue(NonCatalogProductList::isExcluded("50150162\n"));
    }

    /**
     * array_keys() de um array cujas chaves sao SKUs numericos devolve INT, nao
     * string — o PHP converte chave numerica automaticamente. Este teste existe
     * porque essa conversao ja causou TypeError em 06/10/2026, e e a TERCEIRA vez
     * que a mesma armadilha aparece no projeto.
     */
    public function testChaveInteiraNaoQuebra(): void
    {
        foreach (array_keys(NonCatalogProductList::all()) as $chave) {
            $this->assertTrue(
                NonCatalogProductList::isExcluded($chave),
                'SKU ' . var_export($chave, true) . ' nao foi reconhecido'
            );
            $this->assertNotNull(NonCatalogProductList::reason($chave));
        }
    }

    public function testInteiroPuroTambemEaceito(): void
    {
        $this->assertTrue(NonCatalogProductList::isExcluded(50150102));
        $this->assertNotNull(NonCatalogProductList::reason(50150102));
    }
}
