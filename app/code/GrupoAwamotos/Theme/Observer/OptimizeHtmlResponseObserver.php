<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Observer;

use GrupoAwamotos\B2B\ViewModel\Cart\EmptyCartContext;
use GrupoAwamotos\Theme\Model\HeaderImpeccableCascadeLockCss;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Otimiza HTML na saída (Promax off no carrinho, refine global) — independente de OPcache em PHTML.
 */
class OptimizeHtmlResponseObserver implements ObserverInterface
{
    private const CART_PATH_PREFIX = 'checkout/cart';
    /**
     * @var array<string, string>
     */
    private const LEGACY_REDIRECT_MAP = [
        'ofertas.html' => '/super-ofertas.html',
        'contato' => '/contact',
        'contact-us' => '/contact',
    ];

    private const CART_STRIP_CSS_FRAGMENTS = [
        'awa-ui-promax-bundle.min.css',
        'awa-ui-promax-bundle.css',
        'awa-commerce-impeccable-refine',
        'awa-focus-visible',
    ];

    /** CSS inline redundante no carrinho (nav oculto; critical cobre header). */
    private const CART_STRIP_INLINE_STYLE_IDS = [
        'awa-cls-nav-fix',
        'awa-header-impeccable-critical-global',
        'awa-menu-v2-dept-open-fix',
    ];

    /** Fragment sem extensão — detecta tanto .css quanto .min.css no HTML. */
    private const REFINE_CSS_FRAGMENT = 'awa-commerce-impeccable-refine';

    /** PLP/busca/PDP: stack dedicado §89–§94 — não reinjetar refine terminal. */
    private const CATALOG_STACK_ACTIONS = [
        'catalog_category_view',
        'catalogsearch_result_index',
        'catalog_product_view',
    ];

    private const CATALOG_STRIP_IMPECCABLE_FRAGMENTS = [
        'awa-impeccable-audit-2026-05-28',
        'awa-commerce-impeccable-refine',
    ];

    public function __construct(
        private readonly HttpRequest $request,
        private readonly CheckoutSession $checkoutSession,
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var ResponseHttp|null $response */
        $response = $observer->getEvent()->getData('response');
        if (!$response instanceof ResponseHttp) {
            return;
        }

        $pathInfo = trim((string) $this->request->getPathInfo(), '/');
        $fullAction = (string) $this->request->getFullActionName();
        if ($this->applyLegacyRedirect($response)) {
            return;
        }

        $contentType = $response->getHeader('Content-Type');
        if ($contentType && stripos($contentType->getFieldValue(), 'text/html') === false) {
            return;
        }

        $html = (string) $response->getBody();
        if ($html === '') {
            return;
        }

        $isCart = $this->request->getFullActionName() === 'checkout_cart_index'
            || $pathInfo === self::CART_PATH_PREFIX
            || str_starts_with($pathInfo, self::CART_PATH_PREFIX . '/');

        if ($isCart) {
            $html = $this->stripStylesheetFragments($html, self::CART_STRIP_CSS_FRAGMENTS);
            $html = $this->stripInlineStyleBlocksById($html, self::CART_STRIP_INLINE_STYLE_IDS);
            $html = $this->stripCookieStatusNotice($html);
        }

        if ($isCart) {
            $html = $this->ensureExpressEmptyCartNotice($html);
            $html = $this->injectCartFooterStickyGuard($html);
        }

        $html = $this->normalizeRefineStylesheetQuery($html);

        $isAuthFocusPage = in_array($fullAction, HeaderImpeccableCascadeLockCss::AUTH_FOCUS_ACTIONS, true)
            || HeaderImpeccableCascadeLockCss::isAuthShellHtml($html);
        $isB2bAccountFocusPage = in_array($fullAction, HeaderImpeccableCascadeLockCss::B2B_ACCOUNT_FOCUS_ACTIONS, true);
        if (in_array($fullAction, self::CATALOG_STACK_ACTIONS, true)) {
            $html = $this->stripStylesheetFragments($html, self::CATALOG_STRIP_IMPECCABLE_FRAGMENTS);
        } elseif ($fullAction !== 'cms_index_index'
            && !$isCart
            && !$isAuthFocusPage
            && !$isB2bAccountFocusPage
        ) {
            $html = $this->injectGlobalRefineStylesheetIfMissing($html);
        }

        $html = $this->patchStaleHeaderStickyCriticalCss($html);
        $html = $this->stripAsyncDeferredStylePreloads($html);

        $html = $this->dedupeStylesheetHrefs($html);
        $html = $this->applyRouteSpecificHtmlFixes($html, $fullAction);

        $response->setBody($html);
    }

    private function applyLegacyRedirect(ResponseHttp $response): bool
    {
        $requestPath = $this->extractRequestPath((string) $this->request->getRequestUri());
        $target = $this->resolveLegacyRedirectTarget($requestPath, (string) $this->request->getMethod());
        if ($target === null) {
            return false;
        }

        $response->setRedirect($target, 301);
        $response->setBody('');

        return true;
    }

    private function resolveLegacyRedirectTarget(string $pathInfo, string $method): ?string
    {
        $httpMethod = strtoupper(trim($method));
        if ($httpMethod !== 'GET' && $httpMethod !== 'HEAD') {
            return null;
        }

        $normalizedPath = trim($pathInfo, '/');
        return self::LEGACY_REDIRECT_MAP[$normalizedPath] ?? null;
    }

    private function extractRequestPath(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }

        return trim($path, '/');
    }

    private function hydratePopularSearchLabels(string $html): string
    {
        return preg_replace_callback(
            '/<a\b([^>]*\bhref=(["\'])([^"\']+)\2[^>]*)>\s*<\/a>/i',
            static function (array $matches): string {
                $queryString = parse_url($matches[3], PHP_URL_QUERY);
                if (!is_string($queryString)) {
                    return $matches[0];
                }

                parse_str($queryString, $queryParams);
                $label = trim((string) ($queryParams['q'] ?? ''));
                if ($label === '') {
                    return $matches[0];
                }

                return '<a' . $matches[1] . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
            },
            $html
        ) ?? $html;
    }

    private function applyRouteSpecificHtmlFixes(string $html, string $fullAction): string
    {
        if ($fullAction === 'cms_index_index') {
            $html = $this->patchStaleHomeHeaderAssets($html);
            $html = $this->stripOrphanMenuDeptStylePreload($html);
        }

        if ($fullAction === 'search_term_popular') {
            $html = $this->hydratePopularSearchLabels($html);
        }

        return $html;
    }






    private function stripCookieStatusNotice(string $html): string
    {
        $html = preg_replace(
            '/<div\\s+class="cookie-status-message"\\s+id="cookie-status"[^>]*>.*?<\\/div>\\s*/is',
            '',
            $html
        ) ?? $html;

        return preg_replace(
            '/<script[^>]*>\\s*document\\.querySelector\\("#cookie-status"\\)\\.style\\.display\\s*=\\s*"none";\\s*<\\/script>\\s*/i',
            '',
            $html
        ) ?? $html;
    }

    /**
     * @param string[] $styleIds
     */
    private function stripInlineStyleBlocksById(string $html, array $styleIds): string
    {
        foreach ($styleIds as $styleId) {
            $pattern = '/<style\\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\\/style>\\s*/is';
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        return $html;
    }

    /**
     * @param string[] $fragments
     */
    private function stripStylesheetFragments(string $html, array $fragments): string
    {
        foreach ($fragments as $fragment) {
            $html = preg_replace(
                '/<link\s[^>]*' . preg_quote($fragment, '/') . '[^>]*\/?>\s*/i',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<noscript>\s*<link\s[^>]*' . preg_quote($fragment, '/') . '[^>]*\/?>\s*<\/noscript>\s*/i',
                '',
                $html
            ) ?? $html;
        }

        return $html;
    }

    private function normalizeRefineStylesheetQuery(string $html): string
    {
        // Normaliza TODAS as versões antigas (.css?v=X e .min.css?v=X) para .min.css?v=18.
        return preg_replace(
            '/awa-commerce-impeccable-refine\.(?:min\.)?css(?:\?v=[^"\'&\s>]+)?/',
            HeaderImpeccableCascadeLockCss::REFINE_CSS_FILE . HeaderImpeccableCascadeLockCss::REFINE_QUERY,
            $html
        ) ?? $html;
    }

    private function patchStaleHomeHeaderAssets(string $html): string
    {
        $gateJs = 'awa-css-gate.min.js?v=' . HeaderImpeccableCascadeLockCss::GATE_SCRIPT_QUERY;

        return str_replace(
            [
                'awa-css-gate.min.js?v=20260528-loader',
                'awa-css-gate.min.js?v=20260531-impeccable-v10',
                'awa-css-gate.min.js?v=20260531-impeccable-v11',
                'awa-css-gate.min.js?v=20260531-impeccable-v12',
                'awa-css-gate.min.js?v=20260531-optimize',
                'awa-css-gate.min.js?v=20260601-home-opt3',
                'awa-css-gate.min.js?v=20260601-home-opt4',
                'awa-css-gate.min.js?v=20260601-home-opt5',
                'awa-css-gate.min.js?v=20260601-home-opt6',
                'awa-css-gate.min.js?v=20260601-home-opt7',
                'awa-css-gate.min.js?v=20260601-home-opt8',
                'awa-css-gate.min.js?v=20260601-carousel13',
                'awa-css-gate.min.js?v=20260601-home-opt17',
                'awa-css-gate.min.js?v=20260805-hygiene-r71',
                'awa-css-gate.min.js?v=20260820-nav-gutter-r10',
                'awa-css-gate.min.js?v=20260827-footer-axis-bp',
                'awa-css-gate.min.js?v=20260827-footer-axis-js',
                'awa-css-gate.min.js?v=20260827-footer-axis-js2',
                'awa-css-gate.min.js?v=20260827-footer-axis-js3',
                'awa-css-gate.min.js?v=20260827-footer-axis-js4',
                'awa-css-gate.min.js?v=20260827-footer-axis-js5',
                'awa-css-gate.min.js?v=20260827-footer-axis-js6',
                'awa-css-gate.min.js?v=20260827-footer-axis-js7dbg',
                'awa-css-gate.min.js?v=20260827-footer-axis-js8',
                'awa-css-gate.min.js?v=20260827-footer-axis-js9',
                'awa-css-gate.min.js?v=20260902-gate-idle-1s',
            ],
            $gateJs,
            $html
        );
    }

    private function patchStaleHeaderStickyCriticalCss(string $html): string
    {
        if (!HeaderImpeccableCascadeLockCss::htmlHasSiteHeader($html)) {
            return $html;
        }

        $html = str_replace(
            [
                'height:116px!important;min-height:116px!important;max-height:116px!important;overflow:hidden!important;box-sizing:border-box!important}',
                'max-height:96px!important;height:96px!important;overflow:hidden!important}',
                'height:116px!important;min-height:116px!important;max-height:116px!important;overflow:visible!important}',
            ],
            [
                'height:auto!important;min-height:96px!important;max-height:none!important;overflow:visible!important;contain:none!important;padding-block:0!important;box-sizing:border-box!important}',
                'max-height:96px!important;height:96px!important;overflow:visible!important}',
                'height:auto!important;min-height:116px!important;max-height:none!important;overflow:visible!important;contain:none!important;padding-block:0!important}',
            ],
            $html
        );

        return $html;
    }

    private function injectGlobalRefineStylesheetIfMissing(string $html): string
    {
        // REFINE_CSS_FRAGMENT não tem extensão: detecta tanto .css quanto .min.css.
        if (str_contains($html, self::REFINE_CSS_FRAGMENT)) {
            return $html;
        }

        if (!preg_match('#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#', $html, $versionMatch)) {
            return $html;
        }

        $href = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . HeaderImpeccableCascadeLockCss::REFINE_CSS_FILE
            . HeaderImpeccableCascadeLockCss::REFINE_QUERY;

        $tag = '<link rel="stylesheet" href="' . $href . '" media="print" onload="this.media=\'all\'"'
            . ' data-awa-impeccable-terminal="refine"/>';

        $injected = preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    private function dedupeStylesheetHrefs(string $html): string
    {
        $pattern = '/<link\s[^>]*rel=["\']stylesheet["\'][^>]*\/?>/i';
        $hrefCounts = [];
        $hasActiveTag = [];
        $seen = [];

        $isActiveStylesheet = static function (string $tag): bool {
            return !preg_match('/media=["\']print["\']/i', $tag)
                && !preg_match('/onload\s*=/i', $tag);
        };

        // Exclude <noscript> content from analysis — links inside noscript are fallbacks,
        // not active stylesheets. Counting them as active causes async print/onload links
        // to be incorrectly removed (the noscript link triggers hasActiveTag, removing the async one).
        $htmlForAnalysis = preg_replace('/<noscript>.*?<\/noscript>/is', '', $html) ?? $html;

        if (!preg_match_all($pattern, $htmlForAnalysis, $stylesheetMatches)) {
            return $html;
        }

        foreach ($stylesheetMatches[0] as $tag) {
            if (!preg_match('/href=(["\'])([^"\']+)\1/i', $tag, $hrefMatch)) {
                continue;
            }

            $href = $hrefMatch[2];
            $hrefCounts[$href] = ($hrefCounts[$href] ?? 0) + 1;
            if ($isActiveStylesheet($tag)) {
                $hasActiveTag[$href] = true;
            }
        }

        return preg_replace_callback(
            $pattern,
            static function (array $matches) use (&$seen, $hrefCounts, $hasActiveTag, $isActiveStylesheet): string {
                $tag = $matches[0];
                if (!preg_match('/href=(["\'])([^"\']+)\1/i', $tag, $hrefMatch)) {
                    return $tag;
                }

                $href = $hrefMatch[2];
                if (($hrefCounts[$href] ?? 0) < 2) {
                    return $tag;
                }

                $active = $isActiveStylesheet($tag);
                if (($hasActiveTag[$href] ?? false) && !$active) {
                    return '';
                }

                if (isset($seen[$href])) {
                    return '';
                }

                $seen[$href] = true;

                return $tag;
            },
            $html
        ) ?? $html;
    }

    /**
     * Sticky do #cart-summary para ao encontrar o footer (não depende de OPcache em PHTML).
     */
    private function injectCartFooterStickyGuard(string $html): string
    {
        if (str_contains($html, 'id="awa-cart-footer-sticky-guard"')) {
            return $html;
        }

        $snippet = <<<'HTML'
<style id="awa-cart-footer-sticky-guard">
@media (min-width:992px){
html body#html-body#html-body#html-body#html-body#html-body.checkout-cart-index .page-wrapper
.cart-container :is(.cart-summary,#cart-summary,.cart-summary._sticky,#cart-summary._sticky){
max-height:none!important;height:auto!important;overflow:visible!important;overflow-y:visible!important}
html body#html-body#html-body#html-body#html-body#html-body.checkout-cart-index.awa-cart-footer-inview .page-wrapper
.cart-container :is(.cart-summary,#cart-summary,.cart-summary._sticky,#cart-summary._sticky){
position:static!important;top:auto!important;left:auto!important;bottom:auto!important;
max-height:none!important;height:auto!important;overflow:visible!important;overflow-y:visible!important;
z-index:auto!important;transform:none!important}
}
html body#html-body.checkout-cart-index .page-wrapper footer.page-footer{
max-width:none!important;width:100%!important;margin-inline:0!important;
padding-inline:0!important;padding-left:0!important;padding-right:0!important;box-sizing:border-box!important}
html body#html-body.checkout-cart-index .page-wrapper footer.page-footer
:is(section.awa-footer-trust-bar,.awa-footer-trust-bar){
width:100%!important;max-width:none!important;margin-inline:0!important}
@media (min-width:768px){
html body#html-body.checkout-cart-index .page-wrapper footer.page-footer
:is(#newsletter-validate-detail,.form.subscribe){
max-width:none!important;width:100%!important;margin:0!important;
margin-inline:0!important;margin-left:0!important;margin-right:0!important}
html body#html-body.checkout-cart-index .page-wrapper footer.page-footer
.awa-newsletter-form-container{max-width:none!important}
}
</style>
<script>
require.config({
    paths: {
        'js/awa-cart-runtime': 'js/awa-cart-runtime.js?v=20260828-cart-unclamp-r2'
    }
});
require(['js/awa-cart-runtime'], function (boot) {
    if (typeof boot === 'function') { boot(); }
});
/* AQ r2: AMD em cache immutable ainda aplica overflow:hidden auto no scroll.
   MutationObserver reverte o inline (prova CDP: 80ms → mh none, innerScroll 0). */
(function (w, d) {
    'use strict';
    if (w.__awaCartStickyFix32aq) { return; }
    w.__awaCartStickyFix32aq = 1;
    function isDesktop() {
        return w.matchMedia('(min-width:992px)').matches;
    }
    function enforceDesktopUnclamp(el) {
        if (!el || !isDesktop()) { return; }
        if (el.style.getPropertyValue('max-height') === 'none'
            && (el.style.getPropertyValue('overflow') === 'visible'
                || el.style.getPropertyValue('overflow-y') === 'visible')) {
            return;
        }
        el.style.setProperty('max-height', 'none', 'important');
        el.style.setProperty('height', 'auto', 'important');
        el.style.setProperty('overflow', 'visible', 'important');
        el.style.setProperty('overflow-y', 'visible', 'important');
    }
    function apply(active) {
        d.querySelectorAll('#cart-summary, .cart-container .cart-summary').forEach(function (el) {
            if (active) {
                el.style.setProperty('position', 'static', 'important');
                el.style.setProperty('max-height', 'none', 'important');
                el.style.setProperty('height', 'auto', 'important');
                el.style.setProperty('overflow', 'visible', 'important');
                el.style.setProperty('z-index', 'auto', 'important');
            } else if (isDesktop()) {
                el.style.removeProperty('position');
                el.style.removeProperty('z-index');
                enforceDesktopUnclamp(el);
            } else {
                el.style.removeProperty('position');
                el.style.removeProperty('max-height');
                el.style.removeProperty('height');
                el.style.removeProperty('z-index');
                el.style.setProperty('overflow-x', 'hidden', 'important');
                el.style.setProperty('overflow-y', 'auto', 'important');
            }
        });
    }
    function check() {
        var footer = d.querySelector('footer.page-footer, .page_footer');
        var summary = d.querySelector('#cart-summary');
        if (!footer || !summary || !d.body) { return; }
        var fr = footer.getBoundingClientRect();
        var sr = summary.getBoundingClientRect();
        var near = fr.top < (w.innerHeight - 24) || fr.top < (sr.bottom + 12);
        d.body.classList.toggle('awa-cart-footer-inview', near);
        apply(near);
        enforceNewsletterAxis();
    }
    function enforceNewsletterAxis() {
        if (!isDesktop()) { return; }
        var form = d.querySelector('footer.page-footer #newsletter-validate-detail');
        if (!form) { return; }
        form.style.setProperty('max-width', 'none', 'important');
        form.style.setProperty('width', '100%', 'important');
        form.style.setProperty('margin', '0', 'important');
        form.style.setProperty('margin-inline', '0', 'important');
    }
    function watchClamp() {
        d.querySelectorAll('#cart-summary, .cart-container .cart-summary').forEach(function (el) {
            if (el.getAttribute('data-awa-unclamp-mo') === '1') { return; }
            el.setAttribute('data-awa-unclamp-mo', '1');
            new MutationObserver(function () {
                enforceDesktopUnclamp(el);
            }).observe(el, { attributes: true, attributeFilter: ['style'] });
            enforceDesktopUnclamp(el);
        });
        enforceNewsletterAxis();
    }
    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () { check(); watchClamp(); }, { once: true });
    } else {
        check();
        watchClamp();
    }
    w.addEventListener('scroll', function () { w.requestAnimationFrame(check); }, { passive: true });
    w.addEventListener('resize', check, { passive: true });
}(window, document));
</script>
HTML;

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $snippet . '</body>', $html, 1) ?? ($html . $snippet);
        }

        return $html . $snippet;
    }

    private function ensureExpressEmptyCartNotice(string $html): string
    {
        if ($this->hasExpressNoticeMarkup($html)) {
            return $html;
        }

        $hasFlag = (bool) $this->checkoutSession->getData(EmptyCartContext::SESSION_KEY_EXPRESS_REDIRECT)
            || $this->request->getCookie(EmptyCartContext::COOKIE_KEY_EXPRESS_REDIRECT) === '1';
        if (!$hasFlag) {
            return $html;
        }

        $message = 'Você veio do checkout expresso. Adicione peças ao carrinho para continuar.';
        $notice = '<div class="awa-cart-empty__notice" role="status">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</div>';
        $replaced = preg_replace(
            '/(<h1[^>]*awa-cart-empty__title[^>]*>)/i',
            $notice . "\n$1",
            $html,
            1
        );

        $this->checkoutSession->unsetData(EmptyCartContext::SESSION_KEY_EXPRESS_REDIRECT);

        return is_string($replaced) ? $replaced : $html;
    }

    private function hasExpressNoticeMarkup(string $html): bool
    {
        return (bool) preg_match(
            '/<div[^>]+class="[^"]*\\bawa-cart-empty__notice\\b[^"]*"[^>]*role="status"/i',
            $html
        );
    }

    /**
     * Remove preload órfão do menu v2 (FPC/block_html stale) — stylesheet sync no bootstrap.
     */
    private function stripOrphanMenuDeptStylePreload(string $html): string
    {
        if (!str_contains($html, 'awa-menu-v2-dept-open-fix.css')) {
            return $html;
        }

        if (preg_match(
            '/<link\s[^>]*rel=["\']stylesheet["\'][^>]*awa-menu-v2-dept-open-fix\.css[^>]*>/i',
            $html
        ) || preg_match('/awa-menu-v2-dept-open-fix\.css[^>]*data-awa-defer/i', $html)
        ) {
            return $html;
        }

        return preg_replace(
            '/<link\s+rel=["\']preload["\'][^>]*awa-menu-v2-dept-open-fix\.css[^>]*\/?>\s*/i',
            '',
            $html
        ) ?? $html;
    }

    /**
     * Preload de CSS com media=print+onload não é consumido a tempo — gera warning no Chrome.
     * Remove preloads stale; o stylesheet async continua no HTML.
     *
     * @see awa-audit-bundle-css.phtml
     */
    private function stripAsyncDeferredStylePreloads(string $html): string
    {
        foreach (['awa-visual-polish-r2.css', 'awa-audit-bundle.min.css', 'awa-audit-bundle.css'] as $fragment) {
            if (!str_contains($html, $fragment)) {
                continue;
            }

            $html = preg_replace(
                '/<link\s+rel=["\']preload["\'][^>]*' . preg_quote($fragment, '/') . '[^>]*\/?>\s*/i',
                '',
                $html
            ) ?? $html;
        }

        return $html;
    }
}
