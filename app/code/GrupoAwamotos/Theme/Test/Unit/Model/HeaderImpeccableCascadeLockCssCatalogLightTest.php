<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Test\Unit\Model;

use GrupoAwamotos\Theme\Model\HeaderImpeccableCascadeLockCss;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GrupoAwamotos\Theme\Model\HeaderImpeccableCascadeLockCss::injectCatalogLightBeforeBodyClose
 */
class HeaderImpeccableCascadeLockCssCatalogLightTest extends TestCase
{
    public function testCatalogLightReplacesFullCascadeLock(): void
    {
        $full = HeaderImpeccableCascadeLockCss::footerInjection();
        $html = '<html><body class="catalog-category-view">'
            . $full
            . '</body></html>';

        $out = HeaderImpeccableCascadeLockCss::injectCatalogLightBeforeBodyClose($html);

        $this->assertStringNotContainsString(
            'id="' . HeaderImpeccableCascadeLockCss::STYLE_ID . '"',
            $out
        );
        $this->assertStringNotContainsString('id="awa-header-cascade-lock-guard"', $out);
        $this->assertStringNotContainsString(
            'id="' . HeaderImpeccableCascadeLockCss::CATALOG_PARITY_STYLE_ID . '"',
            $out
        );
        $this->assertStringContainsString('id="awa-header-mobile-grid-guard"', $out);
        $this->assertStringContainsString('id="awa-catalog-root-height-lock"', $out);
        $this->assertStringContainsString(
            'id="' . HeaderImpeccableCascadeLockCss::AUDIT30_SURFACE_STYLE_ID . '"',
            $out
        );
        $this->assertLessThan(
            80000,
            strlen($out),
            'catalog light must stay far below the ~221KB cascade-lock HTML'
        );
    }

    public function testCatalogLightIsIdempotent(): void
    {
        $html = '<html><body class="catalog-category-view"></body></html>';
        $once = HeaderImpeccableCascadeLockCss::injectCatalogLightBeforeBodyClose($html);
        $twice = HeaderImpeccableCascadeLockCss::injectCatalogLightBeforeBodyClose($once);

        $this->assertSame(
            0,
            substr_count($twice, 'id="' . HeaderImpeccableCascadeLockCss::CATALOG_PARITY_STYLE_ID . '"')
        );
        $this->assertSame(1, substr_count($twice, 'id="awa-header-mobile-grid-guard"'));
        $this->assertSame(1, substr_count($twice, 'id="awa-catalog-root-height-lock"'));
    }
}
