<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Test\Unit\Plugin\Response;

use GrupoAwamotos\Theme\Plugin\Response\DeferHomeScriptsPlugin;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \GrupoAwamotos\Theme\Plugin\Response\DeferHomeScriptsPlugin
 */
class DeferHomeScriptsPluginTest extends TestCase
{
    private DeferHomeScriptsPlugin $plugin;

    /** @var AssetRepository&MockObject */
    private AssetRepository $assets;

    protected function setUp(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $this->assets = $this->createMock(AssetRepository::class);
        $this->assets->method('getUrl')->willReturnCallback(
            static function (string $path): string {
                return 'https://awamotos.com/static/' . $path;
            }
        );
        $this->plugin = new DeferHomeScriptsPlugin($request, $this->assets);
    }

    public function testLeavesNativeMagentoAmdStackUntouched(): void
    {
        $html = <<<'HTML'
<footer></footer>
<script> var LOCALE = 'pt\u002DBR'; var BASE_URL = 'https\u003A\u002F\u002Fawamotos.com\u002F'; var require = { 'baseUrl': 'https\u003A\u002F\u002Fawamotos.com\u002Fstatic\u002Fversion1\u002Ffrontend\u002FAWA_Custom\u002Fayo_home5_child\u002Fpt_BR' };</script>
<script type="text/javascript" src="https://awamotos.com/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/requirejs/require.min.js"></script>
<script type="text/javascript" src="https://awamotos.com/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/requirejs-min-resolver.min.js"></script>
<script type="text/javascript" src="https://awamotos.com/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/mage/requirejs/mixins.min.js"></script>
<script type="text/javascript" src="https://awamotos.com/static/version1/frontend/AWA_Custom/ayo_home5_child/pt_BR/requirejs-config.min.js"></script>
HTML;

        $out = $this->invoke('restoreNativeAmdBootstrap', $html);

        $this->assertSame($html, $out);
        $this->assertStringNotContainsString('awa-require-stub', $out);
        $this->assertStringNotContainsString('type="text/plain"', $out);
    }

    public function testUnwrapsHomeFreezeFixStubToNativeAmd(): void
    {
        $html = <<<'HTML'
<footer></footer>
<script src="https://awamotos.com/static/js/awa-require-stub.js?v=20260902-freeze-fix-r7"></script><script type="text/plain" id="awa-home-bootstrap-inline">var LOCALE = 'pt\u002DBR'; var require = { 'baseUrl': '/static' };</script><script type="text/plain" id="awa-home-bootstrap-merged" data-awa-boot-mode="home" data-src="https://awamotos.com/static/requirejs/require.min.js" data-amd-srcs="[&quot;https://awamotos.com/static/requirejs/require.min.js&quot;,&quot;https://awamotos.com/static/requirejs-min-resolver.min.js&quot;,&quot;https://awamotos.com/static/mage/requirejs/mixins.min.js&quot;,&quot;https://awamotos.com/static/requirejs-config.min.js&quot;]"></script><script src="https://awamotos.com/static/requirejs/require.min.js"></script><script src="https://awamotos.com/static/js/awa-home-bootstrap-defer.js?v=20260902-freeze-fix-r7" defer></script>
HTML;

        $out = $this->invoke('restoreNativeAmdBootstrap', $html);

        $this->assertStringNotContainsString('awa-require-stub', $out);
        $this->assertStringNotContainsString('awa-home-bootstrap-defer', $out);
        $this->assertStringNotContainsString('type="text/plain"', $out);
        $this->assertStringNotContainsString('data-awa-boot-mode', $out);
        $this->assertStringContainsString('var LOCALE', $out);
        $this->assertStringContainsString('type="text/javascript" src="https://awamotos.com/static/requirejs/require.min.js"', $out);
        $this->assertStringContainsString('mage/requirejs/mixins.min.js', $out);
        $this->assertStringContainsString('requirejs-config.min.js', $out);
        $this->assertStringContainsString('requirejs-min-resolver.min.js', $out);
    }

    public function testUnwrapsCatalogStubWithoutExecutableRequire(): void
    {
        $html = <<<'HTML'
<script src="https://awamotos.com/static/js/awa-require-stub.js?v=20260902-freeze-fix-r7"></script><script type="text/plain" id="awa-home-bootstrap-inline">var LOCALE = 'pt\u002DBR'; var require = { 'baseUrl': '/static' };</script><script type="text/plain" id="awa-home-bootstrap-merged" data-awa-boot-mode="catalog" data-src="https://awamotos.com/static/requirejs/require.min.js" data-amd-srcs="[&quot;https://awamotos.com/static/requirejs/require.min.js&quot;,&quot;https://awamotos.com/static/requirejs-min-resolver.min.js&quot;,&quot;https://awamotos.com/static/mage/requirejs/mixins.min.js&quot;,&quot;https://awamotos.com/static/requirejs-config.min.js&quot;]"></script><script src="https://awamotos.com/static/js/awa-home-bootstrap-defer.js?v=20260902-freeze-fix-r7" defer></script>
HTML;

        $out = $this->invoke('restoreNativeAmdBootstrap', $html);

        $this->assertStringNotContainsString('awa-require-stub', $out);
        $this->assertStringNotContainsString('awa-home-bootstrap-defer', $out);
        $this->assertStringNotContainsString('type="text/plain"', $out);
        $this->assertStringContainsString('type="text/javascript" src="https://awamotos.com/static/requirejs/require.min.js"', $out);
        $this->assertStringContainsString('mage/requirejs/mixins.min.js', $out);
    }

    public function testDeferMagentoAmdBootstrapDoesNotWrapNativeChain(): void
    {
        $html = <<<'HTML'
<script> var LOCALE = 'pt\u002DBR'; var require = { 'baseUrl': '/static' };</script>
<script type="text/javascript" src="https://awamotos.com/static/requirejs/require.min.js"></script>
<script type="text/javascript" src="https://awamotos.com/static/requirejs-min-resolver.min.js"></script>
<script type="text/javascript" src="https://awamotos.com/static/mage/requirejs/mixins.min.js"></script>
<script type="text/javascript" src="https://awamotos.com/static/requirejs-config.min.js"></script>
HTML;

        $out = $this->invoke('deferMagentoAmdBootstrap', $html);

        $this->assertStringNotContainsString('awa-require-stub', $out);
        $this->assertStringNotContainsString('awa-home-bootstrap-merged', $out);
        $this->assertStringContainsString('requirejs/require.min.js', $out);
        $this->assertStringContainsString('mixins.min.js', $out);
    }

    public function testShouldDeferAmdForStorefrontCatalogNotCheckout(): void
    {
        $ref = new ReflectionMethod(DeferHomeScriptsPlugin::class, 'shouldDeferAmd');
        $ref->setAccessible(true);

        $this->assertTrue((bool) $ref->invoke($this->plugin, 'cms_index_index'));
        $this->assertTrue((bool) $ref->invoke($this->plugin, 'catalog_category_view'));
        $this->assertTrue((bool) $ref->invoke($this->plugin, 'catalogsearch_result_index'));
        $this->assertTrue((bool) $ref->invoke($this->plugin, 'catalog_product_view'));
        $this->assertFalse((bool) $ref->invoke($this->plugin, 'checkout_index_index'));
        $this->assertFalse((bool) $ref->invoke($this->plugin, 'checkout_cart_index'));
        $this->assertFalse((bool) $ref->invoke($this->plugin, 'customer_account_index'));
        $this->assertFalse((bool) $ref->invoke($this->plugin, 'b2b_account_login'));
    }

    public function testWrapAmdBootstrapPlaceholderIsDisabled(): void
    {
        $out = $this->invoke('wrapAmdBootstrapPlaceholder', 'var require = {};', ['https://example/require.min.js'], 'home');
        $this->assertNull($out);
    }

    private function invoke(string $method, ...$args)
    {
        $ref = new ReflectionMethod(DeferHomeScriptsPlugin::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->plugin, ...$args);
    }
}
