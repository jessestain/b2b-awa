<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Test\Unit\Plugin\Response;

use GrupoAwamotos\Theme\Model\CascadeAssetManifest;
use GrupoAwamotos\Theme\Model\Generated\CascadeAssetVersionConsts;
use GrupoAwamotos\Theme\Model\HeadStylesheetNormalizer;
use GrupoAwamotos\Theme\Plugin\Response\OptimizeHeadStylesPlugin;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State as AppState;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Contratos HTML da cascata CSS (Fase 4).
 *
 * @covers \GrupoAwamotos\Theme\Plugin\Response\OptimizeHeadStylesPlugin
 */
class OptimizeHeadStylesPluginTest extends TestCase
{
    private OptimizeHeadStylesPlugin $plugin;
    private CascadeAssetManifest $manifest;
    private HttpRequest&MockObject $request;
    private AppState&MockObject $appState;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->appState = $this->createMock(AppState::class);
        $this->manifest = new CascadeAssetManifest();
        $this->plugin = new OptimizeHeadStylesPlugin(
            $this->request,
            $this->appState,
            $this->manifest,
            new HeadStylesheetNormalizer($this->manifest)
        );
    }

    public function testStripStylesheetFragmentsRemovesLinkAndNoscript(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-commerce-impeccable-refine.min.css"/>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/styles-m.css"/>
<noscript><link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-commerce-impeccable-refine.min.css"/></noscript>
</head><body></body></html>
HTML;

        $out = $this->invoke('stripStylesheetFragments', $html, ['awa-commerce-impeccable-refine']);

        $this->assertStringNotContainsString('awa-commerce-impeccable-refine', $out);
        $this->assertStringContainsString('styles-m.css', $out);
        $this->assertSame(0, preg_match_all('/awa-commerce-impeccable-refine/', $out));
    }

    public function testStripStylesheetFragmentsLeavesUnrelatedAssets(): void
    {
        $html = '<link rel="stylesheet" href="/x/awa-footer-terminal-lock-v1.min.css"/>';
        $out = $this->invoke('stripStylesheetFragments', $html, ['awa-commerce-impeccable-refine']);
        $this->assertStringContainsString('awa-footer-terminal-lock-v1', $out);
    }

    public function testConsolidateM2VisualSsotKeepsExactlyOneAfterAlignGrid(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="/static/version1786042591/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-m2-visual-ssot.min.css?v=stale"/>
<link rel="stylesheet" href="/static/version1786042591/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-align-grid-terminal-2026-06-11.min.css?v=696a4f424479" data-awa-align-grid-body-terminal="1"/>
<link rel="stylesheet" href="/static/version1786042591/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-m2-visual-ssot.min.css?v=dup"/>
</head><body></body></html>
HTML;

        $out = $this->invoke('consolidateM2VisualSsotTerminal', $html, 'cms_index_index');

        $this->assertSame(
            1,
            preg_match_all('/<link\s[^>]*awa-m2-visual-ssot[^>]*\/?>/i', $out),
            'deve restar exatamente um link visual SSOT'
        );
        $this->assertMatchesRegularExpression(
            '/awa-align-grid-terminal[^>]*>\s*\n?<link[^>]*awa-m2-visual-ssot/i',
            $out
        );
        $this->assertStringContainsString('data-awa-m2-visual-ssot="1"', $out);
        $this->assertStringContainsString(CascadeAssetVersionConsts::M2_VISUAL_SSOT, $out);
    }

    public function testNormalizeAlignGridKeepsSingleCanonicalLink(): void
    {
        $canonicalQuery = \GrupoAwamotos\Theme\Model\HeaderImpeccableCascadeLockCss::ALIGN_GRID_QUERY;
        // O dedupe do plugin casa href === arquivo+query (não path /static/...).
        $html = <<<'HTML'
<link rel="stylesheet" href="awa-align-grid-terminal-2026-06-11.min.css?v=old-a"/>
<link rel="stylesheet" href="awa-align-grid-terminal-2026-06-11.min.css?v=old-b" data-awa-align-grid-body-terminal="1"/>
HTML;

        $out = $this->invoke('normalizeAlignGridStylesheetVersion', $html);

        $this->assertSame(
            1,
            preg_match_all(
                '/<link\s[^>]*awa-align-grid-terminal-2026-06-11\.min\.css'
                . preg_quote($canonicalQuery, '/')
                . '[^>]*\/?>/i',
                $out
            )
        );
        $this->assertStringContainsString('data-awa-align-grid-body-terminal', $out);
        $this->assertStringNotContainsString('?v=old-a', $out);
        $this->assertStringNotContainsString('?v=old-b', $out);
    }

    public function testNormalizeAlignGridRewritesStaleQueryOnStaticPaths(): void
    {
        $canonicalQuery = \GrupoAwamotos\Theme\Model\HeaderImpeccableCascadeLockCss::ALIGN_GRID_QUERY;
        $html = <<<'HTML'
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-align-grid-terminal-2026-06-11.min.css?v=old-a"/>
HTML;

        $out = $this->invoke('normalizeAlignGridStylesheetVersion', $html);
        $this->assertStringContainsString(
            'awa-align-grid-terminal-2026-06-11.min.css' . $canonicalQuery,
            $out
        );
        $this->assertStringNotContainsString('?v=old-a', $out);
    }

    public function testAuthStripFragmentsDoNotTargetAlignGrid(): void
    {
        $auth = $this->manifest->authStripFragments();
        $this->assertNotContains('awa-align-grid-terminal-2026-06-11', $auth);
        $this->assertContains('awa-commerce-impeccable-refine', $auth);
    }

    public function testHomeNeverGateIncludesAlignGridExcludesFooterTerminal(): void
    {
        $never = $this->manifest->homeNeverGateFragments();
        $gate = $this->manifest->homeGateFragments();

        // 51.58: align-grid is never-gated (media=all delivery; home css-gate gone).
        $this->assertContains('awa-align-grid-terminal-2026-06-11', $never);
        $this->assertNotContains('awa-align-grid-terminal-2026-06-11', $gate);
        $this->assertNotContains('awa-footer-terminal-lock-v1', $never);
        $this->assertContains('awa-footer-terminal-lock-v1', $gate);
    }

    public function testHomeGateLayoutParityRemovesExistInCmsIndexIndex(): void
    {
        $layoutPath = BP . '/app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Cms/layout/cms_index_index.xml';
        $this->assertFileExists($layoutPath);
        $xml = (string) file_get_contents($layoutPath);
        $missing = \GrupoAwamotos\Theme\Model\HomeCssGateParity::missingLayoutRemoves($xml);
        $this->assertSame([], $missing, 'cms_index_index.xml deve espelhar LAYOUT_REMOVE_SRCS: ' . implode(', ', $missing));
    }

    public function testHomeGateActiveFragmentsAreCoveredByPluginConstant(): void
    {
        $gate = $this->manifest->homeGateFragments();
        foreach (\GrupoAwamotos\Theme\Model\HomeCssGateParity::ACTIVE_GATE_FRAGMENTS as $fragment) {
            $this->assertContains(
                $fragment,
                $gate,
                'ACTIVE_GATE_FRAGMENTS deve permanecer em homeGate (safety-net): ' . $fragment
            );
        }
    }

    public function testCatalogPlpLayoutParityRemovesExistInCategoryLayouts(): void
    {
        $catalogLayout = BP . '/app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Catalog/layout/catalog_category_view.xml';
        $themeLayout = BP . '/app/design/frontend/AWA_Custom/ayo_home5_child/Magento_Theme/layout/catalog_category_view.xml';
        $this->assertFileExists($catalogLayout);
        $this->assertFileExists($themeLayout);
        $missing = \GrupoAwamotos\Theme\Model\CatalogPlpCssParity::missingLayoutRemoves([
            (string) file_get_contents($catalogLayout),
            (string) file_get_contents($themeLayout),
        ]);
        $this->assertSame(
            [],
            $missing,
            'catalog_category_view.xml deve espelhar LAYOUT_REMOVE_SRCS: ' . implode(', ', $missing)
        );
    }

    public function testCatalogSearchLayoutParityRemovesExistInSearchLayout(): void
    {
        $searchLayout = BP . '/app/design/frontend/AWA_Custom/ayo_home5_child/Magento_CatalogSearch/layout/catalogsearch_result_index.xml';
        $this->assertFileExists($searchLayout);
        $missing = \GrupoAwamotos\Theme\Model\CatalogPlpCssParity::missingLayoutRemoves([
            (string) file_get_contents($searchLayout),
        ]);
        $this->assertSame(
            [],
            $missing,
            'catalogsearch_result_index.xml deve espelhar LAYOUT_REMOVE_SRCS: ' . implode(', ', $missing)
        );
    }

    public function testCatalogPlpStripFragmentsRemainInPluginManifest(): void
    {
        $pluginStrips = array_merge(
            $this->manifest->catalogStripImpeccableFragments(),
            $this->manifest->catalogStripLegacyBundleFragments()
        );
        $missing = \GrupoAwamotos\Theme\Model\CatalogPlpCssParity::missingStripFragments($pluginStrips);
        $this->assertSame(
            [],
            $missing,
            'STRIP_FRAGMENTS deve permanecer no plugin (safety-net): ' . implode(', ', $missing)
        );
    }

    public function testCascadeCommercePdpCartCheckoutAuthAccountLayoutParity(): void
    {
        $theme = BP . '/app/design/frontend/AWA_Custom/ayo_home5_child';
        $pdp = (string) file_get_contents($theme . '/Magento_Catalog/layout/catalog_product_view.xml');
        $cart = (string) file_get_contents($theme . '/Magento_Checkout/layout/checkout_cart_index.xml');
        $checkout = (string) file_get_contents($theme . '/Magento_Checkout/layout/checkout_index_index.xml');
        $opc = (string) file_get_contents($theme . '/Rokanthemes_OnePageCheckout/layout/onepagecheckout_index_index.xml');
        $auth = (string) file_get_contents($theme . '/GrupoAwamotos_B2B/layout/b2b_auth_shell.xml');
        $account = (string) file_get_contents($theme . '/Magento_Customer/layout/customer_account.xml');
        $accountB2b = (string) file_get_contents($theme . '/GrupoAwamotos_B2B/layout/customer_account.xml');

        $parity = \GrupoAwamotos\Theme\Model\CascadeCommerceCssParity::class;
        $this->assertSame([], $parity::missingRemoves($parity::PDP_REMOVE_SRCS, [$pdp]));
        $this->assertSame([], $parity::missingRemoves($parity::CART_REMOVE_SRCS, [$cart]));
        $this->assertSame([], $parity::missingRemoves($parity::CHECKOUT_REMOVE_SRCS, [$checkout, $opc]));
        $this->assertSame([], $parity::missingRemoves($parity::AUTH_REMOVE_SRCS, [$auth]));
        $this->assertSame([], $parity::missingRemoves($parity::ACCOUNT_REMOVE_SRCS, [$account]));

        $this->assertFalse($parity::missingNeedle($parity::SHELL_KEEP['cart'], $cart));
        $this->assertFalse($parity::missingNeedle($parity::SHELL_KEEP['checkout'], $opc));
        $this->assertFalse($parity::missingNeedle('awa-checkout-shell-final-loader.phtml', $checkout));
        $this->assertFalse($parity::missingNeedle($parity::SHELL_KEEP['auth'], $auth));
        $this->assertFalse($parity::missingNeedle($parity::SHELL_KEEP['account'], $accountB2b));
        $this->assertStringNotContainsString('<remove src="css/awa-cart-stack.min.css"/>', $cart);
        $this->assertStringNotContainsString('<remove src="css/awa-checkout-shell-final.css"/>', $opc);
        $this->assertStringNotContainsString('<remove src="css/awa-b2b-auth-shell-final.css"/>', $auth);
    }

    public function testStripStylesheetFragmentsNoopsWhenFragmentAbsent(): void
    {
        $html = '<link rel="stylesheet" href="/x/awa-cart-stack.min.css"/>';
        $out = $this->invoke('stripStylesheetFragments', $html, ['awa-commerce-impeccable-refine']);
        $this->assertSame($html, $out);
    }

    public function testConsolidateHomeDeferredStylesheetStackReplacesPatchSheets(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-bugfix-terminal-2026-06-12.min.css" media="print"/>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-home-impeccable-terminal-v1.min.css" media="print"/>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-cls-nav-fix.min.css" media="print"/>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/themes.min.css"/>
</head><body></body></html>
HTML;

        $out = $this->invoke('consolidateHomeDeferredStylesheetStack', $html);
        $this->assertStringContainsString('awa-home-deferred-stack.min.css', $out);
        $this->assertStringContainsString('data-awa-home-deferred-stack="1"', $out);
        $this->assertStringNotContainsString('awa-bugfix-terminal-2026-06-12.min.css', $out);
        $this->assertStringNotContainsString('awa-home-impeccable-terminal-v1.min.css', $out);
        $this->assertStringNotContainsString('awa-cls-nav-fix.min.css', $out);
        $this->assertStringContainsString('themes.min.css', $out);
    }

    public function testHomeDeferredStackDoesNotAbsorbFooterTerminal(): void
    {
        $this->assertNotContains(
            'awa-footer-terminal-lock-v1',
            $this->manifest->homeDeferredStackFragments()
        );
    }

    public function testConsolidateHomeDeferredStackKeepsFooterTerminalFile(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-home-impeccable-terminal-v1.min.css" media="print"/>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-footer-terminal-lock-v1.min.css" media="print" data-awa-footer-sync="1"/>
</head><body></body></html>
HTML;

        $out = $this->invoke('consolidateHomeDeferredStylesheetStack', $html);
        $this->assertStringContainsString('awa-home-deferred-stack.min.css', $out);
        $this->assertStringContainsString('awa-footer-terminal-lock-v1.min.css', $out);
        $this->assertStringContainsString('data-awa-footer-sync="1"', $out);
        $this->assertStringNotContainsString('awa-home-impeccable-terminal-v1.min.css', $out);
    }

    public function testConvertFooterHomeUsesSlimCriticalAndImmediateSheet(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/styles-m.css"/>
</head><body class="cms-index-index"><footer class="page-footer"><div class="page_footer footer-bottom"></div></footer></body></html>
HTML;

        $out = $this->invoke('convertFooterCssToAsync', $html, 'cms_index_index');
        $this->assertSame(
            1,
            preg_match(
                '/<style id="awa-footer-critical-stability-v1">(.*?)<\/style>/s',
                $out,
                $criticalMatch
            )
        );
        $this->assertLessThan(6000, strlen($criticalMatch[1]));
        $this->assertStringContainsString('content-visibility:visible', $criticalMatch[1]);
        $this->assertStringContainsString('awa-footer-terminal-lock-v1.min.css', $out);
        $this->assertStringContainsString('data-awa-footer-sync="1"', $out);
        $this->assertStringContainsString("onload=\"this.media='all'\"", $out);
        $this->assertStringNotContainsString('__awaCssQ', $out);
    }

    public function testInjectHomeCartDedupeCriticalInlineHidesLegacyCartLink(): void
    {
        $html = <<<'HTML'
<html><head></head><body class="cms-index-index">
<a class="awa-header-cart-link" href="/checkout/cart/"><svg class="awa-header-cart-link-icon"></svg></a>
</body></html>
HTML;

        $out = $this->invoke('injectHomeCartDedupeCriticalInline', $html);
        $this->assertStringContainsString('awa-home-cart-dedupe-critical-sync', $out);
        $this->assertStringContainsString('.awa-header-primary-row>.awa-header-cart-link', $out);
        $this->assertStringContainsString('display:none!important', $out);
        $this->assertStringContainsString(':has(.minicart-wrapper .action.showcart)', $out);
        $this->assertStringContainsString('.mini-carts', $out);
        $this->assertStringContainsString('awa-minicart-footer', $out);
        $this->assertMatchesRegularExpression('/<\/style>\s*<\/body>/i', $out);
    }

    public function testInjectTabletHeaderRestoreShowsDepartamentosAndAccountIcon(): void
    {
        $html = <<<'HTML'
<html><body class="cms-index-index"><div class="page-wrapper"><header data-awa-site-header="true" class="awa-site-header"></header></div></body></html>
HTML;

        $out = $this->invoke('injectTabletHeaderRestore', $html);
        $this->assertStringContainsString('id="awa-tablet-header-restore-r1"', $out);
        $this->assertStringContainsString('@media (min-width:768px) and (max-width:991px)', $out);
        $this->assertStringContainsString('.awa-header-categories.menu_left_home1', $out);
        $this->assertStringContainsString('#awa-category-navigation.awa-header-primary-nav', $out);
        $this->assertStringContainsString('display:contents!important', $out);
        $this->assertStringContainsString('transform:none!important', $out);
        $this->assertStringContainsString('max-height:min(70vh,560px)', $out);
        $this->assertStringContainsString('.awa-nav-quick-links', $out);
        $this->assertStringContainsString('.awa-header-account-prompt__mobile-link', $out);
        $this->assertStringContainsString('grid-template-columns:minmax(112px,148px) minmax(0,1fr) 98px', $out);
        $this->assertMatchesRegularExpression('/<\/style>\s*<\/body>/i', $out);
    }

    public function testGateHomeLargeStylesheetsMergesDiscoveredUrlsIntoSeededQueue(): void
    {
        $html = <<<'HTML'
<html><head>
<script id="awa-css-gate-queue" type="application/json">["/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-align-grid-terminal-2026-06-11.min.css?v=seed"]</script>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-commerce-impeccable-refine.min.css?v=4a18e0d41a1f"/>
<link rel="stylesheet" href="https://awamotos.com/media/rokanthemes/theme_option/custom_default.css?v=1"/>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/GrupoAwamotos_B2B/css/product/login-to-cart.min.css"/>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/GrupoAwamotos_B2B/css/header/status-panel.min.css?v=x"/>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-b2b-status-panel.min.css?v=x"/>
</head><body></body></html>
HTML;

        $out = $this->invoke('gateHomeLargeStylesheets', $html);
        $this->assertSame(
            1,
            preg_match(
                '/<script id="awa-css-gate-queue"[^>]*>\[(.*?)\]<\/script>/s',
                $out,
                $queueMatch
            )
        );
        $decoded = json_decode('[' . $queueMatch[1] . ']', true);
        $this->assertIsArray($decoded);
        $missing = \GrupoAwamotos\Theme\Model\HomeCssGateParity::missingActiveGateFragments($decoded);
        $this->assertSame([], $missing, 'fila gate deve conter ACTIVE_GATE_FRAGMENTS: ' . implode(', ', $missing));
    }

    public function testInjectCatalogDesktopClsGeometryLockReservesBreadcrumbHeightInHead(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/x.css"/>
</head><body class="catalog-category-view"><div class="nav-breadcrumbs"></div></body></html>
HTML;

        $out = $this->invoke('injectCatalogDesktopClsGeometryLock', $html);
        $headEnd = stripos($out, '</head>');
        $stylePos = strpos($out, 'id="awa-plp-desktop-cls-lock"');

        $this->assertNotFalse($headEnd);
        $this->assertNotFalse($stylePos);
        $this->assertLessThan($headEnd, $stylePos);
        $this->assertStringContainsString('min-height:27px', $out);
        $this->assertStringContainsString('min-height:104px', $out);
        $this->assertStringContainsString('--awa-plp-stack-gap:12px', $out);
        $this->assertStringContainsString('header-content', $out);
        $this->assertStringContainsString('@media (min-width:768px)', $out);
        $this->assertStringContainsString('padding-inline:var(--awa-shell-pad)', $out);
        $this->assertStringContainsString('scrollbar-gutter:stable', $out);
        $this->assertStringContainsString('margin:0!important;padding:0!important', $out);
        $this->assertStringContainsString('grid-template-columns:260px', $out);
        $this->assertStringContainsString('height:160px', $out);
        $this->assertStringContainsString('grid-template-columns:160px', $out);
        $this->assertStringContainsString('contain:layout', $out);
        $this->assertStringContainsString('category-view-move', $out);
        $this->assertStringContainsString('display:contents', $out);

        $again = $this->invoke('injectCatalogDesktopClsGeometryLock', $out);
        $this->assertSame(1, substr_count($again, 'id="awa-plp-desktop-cls-lock"'));
    }

    public function testInjectCatalogDesktopBlockingCssWritesParserInsertedLinksBeforeHeadClose(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="https://awamotos.com/static/version1786957625/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/awa-align-grid-terminal-2026-06-11.min.css"/>
</head><body>
<script>var href = "https:\/\/awamotos.com\/static\/version1786957625\/frontend\/AWA_Custom\/ayo_home5_child\/pt_BR\/css\/themes.min.css?v=20260805-main-auto-height-r15";</script>
</body></html>
HTML;

        $out = $this->invoke('injectCatalogDesktopBlockingCss', $html);
        $headEnd = stripos($out, '</head>');
        $scriptPos = strpos($out, 'id="awa-plp-desktop-css-blocking"');

        $this->assertNotFalse($headEnd);
        $this->assertNotFalse($scriptPos);
        $this->assertLessThan($headEnd, $scriptPos);
        $this->assertStringContainsString('d.write(', $out);
        $this->assertStringContainsString('min-width: 768px', $out);
        $this->assertStringContainsString('styles-l.min.css', $out);

        $blockingStart = strpos($out, 'id="awa-plp-desktop-css-blocking"');
        $this->assertNotFalse($blockingStart);
        $blockingChunk = substr($out, $blockingStart, $headEnd - $blockingStart);
        $this->assertStringNotContainsString('awa-third-party-bundle.min.css', $blockingChunk);
        $this->assertStringNotContainsString('themes.min.css', $blockingChunk);
        $this->assertStringNotContainsString('awa-super-global-20260611m.min.css', $blockingChunk);

        $again = $this->invoke('injectCatalogDesktopBlockingCss', $out);
        $this->assertSame(1, substr_count($again, 'id="awa-plp-desktop-css-blocking"'));
    }

    public function testInjectCatalogDesktopBlockingCssNoopsWithoutHeadClose(): void
    {
        $html = '<link href="/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/x.css"/>';
        $this->assertSame($html, $this->invoke('injectCatalogDesktopBlockingCss', $html));
    }

    public function testCatalogDesktopClsLockIsInjectedAfterBlockingScript(): void
    {
        $html = <<<'HTML'
<html><head>
<link rel="stylesheet" href="https://awamotos.com/static/version1786957625/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/x.css"/>
</head><body class="catalog-category-view"></body></html>
HTML;

        $out = $this->invoke('injectCatalogDesktopBlockingCss', $html);
        $out = $this->invoke('injectCatalogDesktopClsGeometryLock', $out);

        $blockingPos = strpos($out, 'id="awa-plp-desktop-css-blocking"');
        $lockPos = strpos($out, 'id="awa-plp-desktop-cls-lock"');
        $headEnd = stripos($out, '</head>');

        $this->assertNotFalse($blockingPos);
        $this->assertNotFalse($lockPos);
        $this->assertNotFalse($headEnd);
        $this->assertLessThan($lockPos, $blockingPos);
        $this->assertLessThan($headEnd, $lockPos);
    }

    public function testInjectTouch44TerminalSkipsRuntimeScriptForCatalogActions(): void
    {
        $this->request->method('getFullActionName')->willReturn('catalog_category_view');

        $html = '<html><body class="catalog-category-view"><div class="page-wrapper"></div></body></html>';
        $out = $this->invoke('injectTouch44Terminal', $html);

        $this->assertStringContainsString('id="awa-touch-44-terminal"', $out);
        $this->assertStringNotContainsString('id="awa-touch-44-terminal-js"', $out);
    }

    public function testInjectTouch44TerminalKeepsRuntimeScriptForNonCatalogActions(): void
    {
        $this->request->method('getFullActionName')->willReturn('cms_index_index');

        $html = '<html><body class="cms-index-index"><div class="page-wrapper"></div></body></html>';
        $out = $this->invoke('injectTouch44Terminal', $html);

        $this->assertStringContainsString('id="awa-touch-44-terminal"', $out);
        $this->assertStringContainsString('id="awa-touch-44-terminal-js"', $out);
    }

    public function testDelayCatalogMinicartDeferIdleBootRemovesFallbackTimeouts(): void
    {
        $html = <<<'HTML'
<script>
if (deferOnCatalogIntentOnly) {
    if (typeof w.requestIdleCallback === 'function') {
        idleCallbackId = w.requestIdleCallback(bootFromIdle, { timeout: 3500 });
    } else {
        idleTimerId = w.setTimeout(bootFromIdle, 3500);
    }
}
</script>
HTML;

        $out = $this->invoke('delayCatalogMinicartDeferIdleBoot', $html);

        $this->assertStringNotContainsString('timeout: 3500', $out);
        $this->assertStringNotContainsString('timeout: 18000', $out);
        $this->assertStringNotContainsString('bootFromIdle, 3500', $out);
        $this->assertStringContainsString('deferOnCatalogIntentOnly', $out);
    }

    public function testDisableCatalogIntentDesktopIdleTurnsOffFallback(): void
    {
        $html = "<script>\nvar allowDesktopIdle = true;\n</script>";
        $out = $this->invoke('disableCatalogIntentDesktopIdle', $html);
        $this->assertStringContainsString('var allowDesktopIdle = false;', $out);
        $this->assertStringNotContainsString('var allowDesktopIdle = true;', $out);
    }

    public function testDelayCatalogLegacyDesktopCssInjectWaitsForIntent(): void
    {
        $html = <<<'HTML'
<script>
    if (w.matchMedia('(min-width: 768px)').matches) {
        inject();
    } else {
        w.matchMedia('(min-width: 768px)').addEventListener('change', function (e) {
            if (e.matches) {
                inject();
            }
        });
    }
</script>
HTML;

        $out = $this->invoke('delayCatalogLegacyDesktopCssInject', $html);

        $this->assertStringContainsString('awa-plp-legacy-css-intent', $out);
        $this->assertStringContainsString("intentEvents = ['pointerdown', 'keydown', 'touchstart']", $out);
        $this->assertStringNotContainsString("matches) {\n        inject();", $out);
    }

    /**
     * @param mixed ...$args
     */
    private function invoke(string $method, ...$args): string
    {
        $ref = new ReflectionMethod(OptimizeHeadStylesPlugin::class, $method);
        $ref->setAccessible(true);
        $result = $ref->invoke($this->plugin, ...$args);
        $this->assertIsString($result);

        return $result;
    }
}
