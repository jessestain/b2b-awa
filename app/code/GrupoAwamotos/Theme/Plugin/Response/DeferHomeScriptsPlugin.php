<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\Response;

use GrupoAwamotos\Theme\Model\Html\DebugIngestStripper;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\View\Asset\Repository as AssetRepository;

/**
 * Storefront response plugin. Home: AMD wrap (stub + empty data-amd-srcs
 * + text/plain placeholders + deferred bootstrap; no executable require.min.js).
 * Catalog: native AMD. Home carousel strip + PLP QuickView defer remain.
 * Auth pages never enter here.
 */
class DeferHomeScriptsPlugin
{
    private const HOME_ACTION = 'cms_index_index';
    private const HOME_BOOTSTRAP_VERSION = '20260902-freeze-fix-r10';

    /** Catálogo storefront: plugin still runs (carousel strip / PLP defer); AMD is native. */
    private const CATALOG_ACTIONS = [
        'catalog_category_view',
        'catalogsearch_result_index',
        'catalog_product_view',
    ];

    /** PLP/search only — defer QuickView / AjaxSuite / AI mage-init; keep LayeredAjax. */
    private const CATALOG_PLP_ACTIONS = [
        'catalog_category_view',
        'catalogsearch_result_index',
    ];

    /** Scripts que bloqueiam o parser na home — adiar com defer. RequireJS stays Magento native. */
    private const HOME_DEFER_SCRIPT_FRAGMENTS = [
        'awa-header-account-prompt.js',
        'awa-mirasvit-autocomplete-init.js',
        'awa-search-clear-init.js',
        'awa-a11y-nav-links.js',
    ];

    public function __construct(
        private readonly HttpRequest $request,
        private readonly AssetRepository $assetRepository,
    ) {
    }

    public function beforeSendResponse(ResponseHttp $subject): void
    {
        $fullAction = (string) $this->request->getFullActionName();

        if (!$this->shouldDeferAmd($fullAction)) {
            return;
        }

        $contentType = $subject->getHeader('Content-Type');
        if ($contentType && stripos($contentType->getFieldValue(), 'text/html') === false) {
            // #region agent log
            $this->debugSignal('H3-CONTENTTYPE-SKIP', 'defer plugin skipped non-html response', [
                'fullAction' => $fullAction,
                'contentType' => (string) $contentType->getFieldValue(),
            ]);
            // #endregion
            return;
        }

        $html = (string) $subject->getBody();
        if ($html === '') {
            // #region agent log
            $this->debugSignal('H3-EMPTY-BODY', 'defer plugin skipped empty body', [
                'fullAction' => $fullAction,
            ]);
            // #endregion
            return;
        }

        // #region agent log
        $this->debugSignal('H1-H5-PRE-HTML', 'defer plugin pre-strip html markers', [
            'fullAction' => $fullAction,
            'bodyBytes' => strlen($html),
            'hasIngest' => str_contains($html, '/ingest/'),
            'hasLocalhost' => str_contains($html, '127.0.0.1'),
            'hasAwaHeroBeacon' => str_contains($html, 'awa_hero='),
            'hasOldStaticVersion' => str_contains($html, 'version1787860319'),
            'hasBootstrapMergedMarker' => str_contains($html, 'id="awa-home-bootstrap-merged"'),
            'request' => $this->requestMeta(),
        ]);
        // #endregion

        $html = DebugIngestStripper::strip($html);

        $isHome = $fullAction === self::HOME_ACTION;

        if ($isHome) {
            $html = $this->stripHomeCarouselScripts($html);
            $html = $this->stripBodyLoaderMageInit($html);
            $html = $this->deferBlockingHomeScripts($html);
            if (!str_contains($html, 'id="awa-home-bootstrap-merged"')) {
                $html = $this->deferMagentoAmdBootstrap($html, 'home');
            }
            $html = $this->stripHomeExecutableAmd($html);
            $html = $this->bumpHomeBootstrapAssetVersions($html);
        } else {
            $html = $this->restoreNativeAmdBootstrap($html);
        }

        if (in_array($fullAction, self::CATALOG_PLP_ACTIONS, true)) {
            $html = $this->stripHomeCarouselScripts($html);
            $html = $this->deferCatalogPlpNonCriticalJs($html);
        }

        // #region agent log
        $this->debugSignal('H2-H4-POST-HTML', 'defer plugin post-strip html markers', [
            'fullAction' => $fullAction,
            'bodyBytes' => strlen($html),
            'hasIngest' => str_contains($html, '/ingest/'),
            'hasLocalhost' => str_contains($html, '127.0.0.1'),
            'hasAwaHeroBeacon' => str_contains($html, 'awa_hero='),
            'hasOldStaticVersion' => str_contains($html, 'version1787860319'),
            'hasBootstrapMergedMarker' => str_contains($html, 'id="awa-home-bootstrap-merged"'),
            'request' => $this->requestMeta(),
        ]);
        // #endregion

        $subject->setBody($html);
    }

    private function shouldDeferAmd(string $fullAction): bool
    {
        return $fullAction === self::HOME_ACTION
            || in_array($fullAction, self::CATALOG_ACTIONS, true);
    }

    /**
     * Magento 2 practice: require.min.js + mixins + requirejs-config as real scripts
     * (B2B login pattern). Unwrap freeze-fix stub / text/plain AMD if present.
     */
    private function restoreNativeAmdBootstrap(string $html): string
    {
        if (!str_contains($html, 'awa-require-stub')
            && !str_contains($html, 'awa-home-bootstrap-merged')
            && !str_contains($html, 'awa-home-bootstrap-defer.js')
        ) {
            return $html;
        }

        $pattern = '#<script[^>]*src="[^"]*awa-require-stub(?:\\.min)?\\.js[^"]*"[^>]*>\\s*</script>\\s*'
            . '<script[^>]*\\bid=["\']awa-home-bootstrap-inline["\'][^>]*>(.*?)</script>\\s*'
            . '<script([^>]*\\bid=["\']awa-home-bootstrap-merged["\'][^>]*)>\\s*</script>\\s*'
            . '(?:<script(?![^>]*type=["\']text/plain["\'])[^>]*src="[^"]*requirejs/require(?:\\.min)?\\.js"[^>]*>\\s*</script>\\s*)?'
            . '<script[^>]*src="[^"]*awa-home-bootstrap-defer(?:\\.min)?\\.js[^"]*"[^>]*>\\s*</script>#is';

        $replaced = preg_replace_callback(
            $pattern,
            static function (array $matches): string {
                $inlineJs = $matches[1];
                $mergedAttrs = $matches[2];
                $amdSrcs = [];
                if (preg_match('~data-amd-srcs=["\']([^"\']+)["\']~', $mergedAttrs, $am)) {
                    $json = html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $amdSrcs = $decoded;
                    }
                }
                if ($amdSrcs === [] && preg_match('~data-src=["\']([^"\']+)["\']~', $mergedAttrs, $ds)) {
                    $amdSrcs = [$ds[1]];
                }

                $out = '<script>' . $inlineJs . '</script>';
                foreach ($amdSrcs as $src) {
                    if (!is_string($src) || $src === '') {
                        continue;
                    }
                    $out .= '<script type="text/javascript" src="'
                        . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . '"></script>';
                }

                return $out;
            },
            $html,
            1
        );
        if (is_string($replaced)) {
            $html = $replaced;
        }

        $html = (string) preg_replace(
            '#<script[^>]*src="[^"]*awa-require-stub(?:\\.min)?\\.js[^"]*"[^>]*>\\s*</script>\\s*#i',
            '',
            $html
        );
        $html = (string) preg_replace(
            '#<script[^>]*src="[^"]*awa-home-bootstrap-defer(?:\\.min)?\\.js[^"]*"[^>]*>\\s*</script>\\s*#i',
            '',
            $html
        );

        return $html;
    }

    /**
     * Capture Magento unmerged AMD (LOCALE/require config + require.min.js +
     * resolver + mixins + requirejs-config) and wrap it. Home: stub + empty
     * data-amd-srcs + text/plain placeholders; do NOT emit executable
     * require.min.js / resolver / mixins / requirejs-config. Catalog restore
     * keeps the captured chain.
     */
    private function deferMagentoAmdBootstrap(string $html, string $bootMode = 'home'): string
    {
        $inlineOpen = '<script(?![^>]*type=["\']text/plain["\'])[^>]*>\s*(var LOCALE\s*=.*?require\s*=\s*\{.*?\};)\s*</script>\s*';

        $unmerged = '~' . $inlineOpen
            . '<script(?![^>]*defer)[^>]*src="([^"]*requirejs/require(?:\.min)?\.js)"[^>]*>\s*</script>\s*'
            . '<script(?![^>]*defer)[^>]*src="([^"]*requirejs-min-resolver(?:\.min)?\.js)"[^>]*>\s*</script>\s*'
            . '<script(?![^>]*defer)[^>]*src="([^"]*mage/requirejs/mixins(?:\.min)?\.js)"[^>]*>\s*</script>\s*'
            . '<script(?![^>]*defer)[^>]*src="([^"]*requirejs-config(?:\.min)?\.js)"[^>]*>\s*</script>~s';

        if (preg_match($unmerged, $html, $matches)) {
            $wrapped = $this->wrapAmdBootstrapPlaceholder($matches[1], [
                $matches[2],
                $matches[3],
                $matches[4],
                $matches[5],
            ], $bootMode);
            if ($wrapped === null) {
                return $html;
            }

            return str_replace($matches[0], $wrapped, $html);
        }

        $merged = '~' . $inlineOpen
            . '<script(?![^>]*defer)[^>]*src="([^"]*_cache/merged/[^"]+\.js)"[^>]*>\s*</script>~s';

        if (preg_match($merged, $html, $matches)) {
            $wrapped = $this->wrapAmdBootstrapPlaceholder($matches[1], [$matches[2]], $bootMode);
            if ($wrapped === null) {
                return $html;
            }

            return str_replace($matches[0], $wrapped, $html);
        }

        return $html;
    }

    /**
     * @param list<string> $amdSrcs
     */
    private function wrapAmdBootstrapPlaceholder(string $inlineJs, array $amdSrcs, string $bootMode = 'home'): ?string
    {
        try {
            $deferSrc = $this->assetRepository->getUrl('js/awa-home-bootstrap-defer.js')
                . '?v=' . self::HOME_BOOTSTRAP_VERSION;
            $stubSrc = $this->assetRepository->getUrl('js/awa-require-stub.js')
                . '?v=' . self::HOME_BOOTSTRAP_VERSION;
        } catch (\Throwable) {
            return null;
        }

        $firstSrc = $amdSrcs[0] ?? '';
        if ($firstSrc === '') {
            return null;
        }

        $requireSrc = '';
        foreach ($amdSrcs as $src) {
            if (!is_string($src) || $src === '') {
                continue;
            }
            if (preg_match('~requirejs/require(?:\.min)?\.js~i', $src)) {
                $requireSrc = $src;
                break;
            }
        }

        $bootMode = $bootMode === 'catalog' ? 'catalog' : 'home';

        /* freeze-fix-r10: home must not start the AMD graph. Empty
         * data-amd-srcs so appendMerged has nothing to inject. Catalog
         * keeps the captured chain for restoreNativeAmdBootstrap. */
        if ($bootMode !== 'home' && $requireSrc === '') {
            return null;
        }

        /* freeze-fix-r10: keep LOCALE/BASE_URL; strip `require = {...}` so
         * deferred runInline() cannot replace the r1 stub with Magento's
         * config object (that is how require.min.js starts). */
        if ($bootMode === 'home') {
            $strippedInline = preg_replace('/(?:var\s+)?require\s*=\s*\{.*?\};/s', '', $inlineJs);
            if (is_string($strippedInline) && trim($strippedInline) !== '') {
                $inlineJs = trim($strippedInline);
            }
        }

        $jsonSrcs = $bootMode === 'home' ? [] : array_values($amdSrcs);
        $amdJson = json_encode($jsonSrcs, JSON_UNESCAPED_SLASHES);
        if (!is_string($amdJson)) {
            return null;
        }

        $html = '<script src="'
            . htmlspecialchars($stubSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '"></script><script type="text/plain" id="awa-home-bootstrap-inline">'
            . $inlineJs
            . '</script><script type="text/plain" id="awa-home-bootstrap-merged" data-awa-boot-mode="'
            . $bootMode
            . '" data-src="'
            . htmlspecialchars($firstSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" data-amd-srcs="'
            . htmlspecialchars($amdJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '"></script>';

        /* freeze-fix-r10: do NOT emit executable require.min.js (nor
         * resolver/mixins/config) on cms_index_index. Stub satisfies
         * typeof require==='function'. */
        if ($bootMode !== 'home' && $requireSrc !== '') {
            $html .= '<script src="'
                . htmlspecialchars($requireSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"></script>';
        }

        $html .= '<script src="'
            . htmlspecialchars($deferSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" defer></script>';

        return $html;
    }

    /**
     * Home: body loader/loaderAjax via mage-init quebra com AMD adiado — init manual após bootstrap.
     */
    private function stripBodyLoaderMageInit(string $html): string
    {
        $html = (string) preg_replace(
            '/<body\s+data-container="body"\s+data-mage-init=\'[^\']*loaderAjax[^\']*\'/i',
            '<body data-container="body"',
            $html,
            1
        );

        if (!str_contains($html, 'awa-home-body-loader-defer')) {
            try {
                $loaderIcon = $this->assetRepository->getUrl('images/loader-2.gif');
            } catch (\Throwable) {
                return $html;
            }

            $loaderIconEsc = htmlspecialchars($loaderIcon, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $snippet = '<script>(function(w,d){"use strict";var done=false;var cfg={"loader":{"icon":"'
                . $loaderIconEsc
                . '"},"loaderAjax":{}};function boot(){if(done||typeof w.require!=="function"){return;}done=true;w.require(["jquery","mage/loader"],function($){var $body=$("body");if(!$body.data("mageLoader")){$body.mage("loader",cfg.loader);}if(!$body.data("mageLoaderAjax")){$body.mage("loaderAjax",cfg.loaderAjax);}});}d.addEventListener("awa:customer-data-ready",boot,{once:true});if("requestIdleCallback"in w){w.requestIdleCallback(boot,{timeout:5000});}else{w.setTimeout(boot,3500);}})(window,document);</script>';

            $replaced = preg_replace('/<\/body>/i', $snippet . "\n</body>", $html, 1);

            return is_string($replaced) ? $replaced : $html;
        }

        return $html;
    }

    private function deferBlockingHomeScripts(string $html): string
    {
        foreach (self::HOME_DEFER_SCRIPT_FRAGMENTS as $fragment) {
            $quoted = preg_quote($fragment, '#');
            $html = (string) preg_replace(
                '#<script(\s[^>]*src="[^"]*' . $quoted . '[^"]*")(?![^>]*\bdefer\b)([^>]*)>\s*</script>#i',
                '<script$1 defer$2></script>',
                $html
            );
        }

        return $html;
    }

    /**
     * Isolation r4: home HTML must not mention carousel/bootstrap JS.
     * Layout + loader already skip them; this is the response-level safety net
     * so DeferHomeScriptsPlugin cannot leave or rewrite those URLs on home.
     */
    private function stripHomeCarouselScripts(string $html): string
    {
        $html = (string) preg_replace(
            '#<script[^>]*\bid="awa-home-shelf-bootstrap-config"[^>]*>.*?</script>\s*#is',
            '',
            $html
        );
        $html = (string) preg_replace(
            '#<script[^>]*src="[^"]*awa-(?:scroll-carousel|home-shelf-bootstrap)(?:\.min)?\.js[^"]*"[^>]*>\s*</script>\s*#i',
            '',
            $html
        );
        $html = (string) preg_replace(
            '#\s+data-awa-shelf-js="[^"]*awa-scroll-carousel[^"]*"#i',
            '',
            $html
        );

        return $html;
    }

    /**
     * freeze-fix-r10: home HTML must not execute Magento AMD. Drop leftover
     * parser-blocking require.min.js / resolver / mixins / requirejs-config.
     * text/plain placeholders (data-src) stay.
     */
    private function stripHomeExecutableAmd(string $html): string
    {
        $patterns = [
            'requirejs/require(?:\.min)?\.js',
            'requirejs-min-resolver(?:\.min)?\.js',
            'mage/requirejs/mixins(?:\.min)?\.js',
            'requirejs-config(?:\.min)?\.js',
        ];

        foreach ($patterns as $fragment) {
            $html = (string) preg_replace(
                '#<script(?![^>]*\\btype=["\']text/plain["\'])[^>]*\\bsrc=["\'][^"\']*'
                . $fragment
                . '[^"\']*["\'][^>]*>\\s*</script>\\s*#i',
                '',
                $html
            );
        }

        return $html;
    }

    private function bumpHomeBootstrapAssetVersions(string $html): string
    {
        $version = self::HOME_BOOTSTRAP_VERSION;
        $fragments = [
            'awa-home-bootstrap-defer.js',
            'awa-require-stub.js',
        ];

        foreach ($fragments as $fragment) {
            $quoted = preg_quote($fragment, '~');
            $html = (string) preg_replace(
                '~(' . $quoted . '\?v=)[^"\']+~',
                '${1}' . $version,
                $html
            );
        }

        return $html;
    }


    /**
     * Magento PLP: QuickView / AjaxSuite / AwaAiChat are not first-paint.
     * LayeredAjax mage-init is left intact so filters stay usable.
     */
    private function deferCatalogPlpNonCriticalJs(string $html): string
    {
        $html = (string) preg_replace_callback(
            '~<script([^>]*type=["\']text/x-magento-init["\'][^>]*)>(.*?)</script>~is',
            static function (array $matches): string {
                $body = $matches[2];
                if (preg_match('/LayeredAjax|layeredajax/i', $body)) {
                    return $matches[0];
                }
                if (!preg_match('/productQuickview|quickview-deps-init|rokanthemes\/ajaxsuite|AwaAiChat/i', $body)) {
                    return $matches[0];
                }

                return '<script type="text/plain" data-awa-catalog-defer-init="1">' . $body . '</script>';
            },
            $html
        );

        if (preg_match('~<script([^>]*\\bid=["\']awa-menu-bootstrap-runtime["\'][^>]*)>\\s*</script>~i', $html, $menuMatch)) {
            $attrs = $menuMatch[1];
            if (!str_contains(strtolower($attrs), 'text/plain')
                && preg_match('/\\ssrc=["\']([^"\']+)["\']/', $attrs, $srcMatch)
            ) {
                $src = htmlspecialchars($srcMatch[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html = str_replace(
                    $menuMatch[0],
                    '<script type="text/plain" id="awa-menu-bootstrap-runtime" data-awa-catalog-menu-pending="1" data-src="'
                    . $src
                    . '"></script>',
                    $html
                );
            }
        }

        if (!str_contains($html, 'id="awa-catalog-plp-defer-boot"')) {
            $replaced = preg_replace('/<\\/body>/i', $this->catalogPlpDeferBootSnippet() . "\n</body>", $html, 1);
            if (is_string($replaced)) {
                $html = $replaced;
            }
        }

        return $html;
    }

    private function catalogPlpDeferBootSnippet(): string
    {
        return <<<'HTML'
<script id="awa-catalog-plp-defer-boot">
(function(w,d){"use strict";if(w.__awaCatalogPlpDeferBoot){return;}w.__awaCatalogPlpDeferBoot=true;var done=false;function promote(){d.querySelectorAll('script[data-awa-catalog-defer-init="1"]').forEach(function(n){var s=d.createElement("script");s.type="text/x-magento-init";s.text=n.textContent||"";n.parentNode.replaceChild(s,n);});}function loadMenu(){var n=d.getElementById("awa-menu-bootstrap-runtime");if(!n||n.getAttribute("data-awa-catalog-menu-pending")!=="1"){return;}var src=n.getAttribute("data-src");if(!src){return;}var s=d.createElement("script");s.src=src;s.defer=true;s.id="awa-menu-bootstrap-runtime";n.parentNode.replaceChild(s,n);}function apply(){if(done){return;}done=true;function run(){if(typeof w.require!=="function"||w.require._awaStub){done=false;w.setTimeout(apply,80);return;}promote();loadMenu();w.require(["mage/apply/main"],function(m){if(m&&typeof m.apply==="function"){m.apply();}},function(){});}if(typeof w.awaRunWhenRequire==="function"){w.awaRunWhenRequire(run,{key:"awa-catalog-plp-defer"});}else{run();}}["pointerdown","keydown","touchstart"].forEach(function(e){w.addEventListener(e,apply,{once:true,passive:true,capture:true});});function idle(){if(w.requestIdleCallback){w.requestIdleCallback(apply,{timeout:1000});}else{w.setTimeout(apply,1000);}}if(d.readyState==="complete"){idle();}else{w.addEventListener("load",idle,{once:true});}})(window,document);
</script>
HTML;
    }

    private function debugSignal(string $hypothesisId, string $message, array $data = []): void
    {
        return;
    }

    /**
     * @return array<string, string>
     */
    private function requestMeta(): array
    {
        $ua = (string) $this->request->getServer('HTTP_USER_AGENT', '');
        if ($ua !== '' && strlen($ua) > 140) {
            $ua = substr($ua, 0, 140);
        }

        return [
            'method' => (string) $this->request->getMethod(),
            'uri' => (string) $this->request->getRequestUri(),
            'ua' => $ua,
            'host' => (string) $this->request->getHttpHost(),
        ];
    }
}
