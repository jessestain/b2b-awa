<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\Response;

use GrupoAwamotos\Theme\Model\HeaderImpeccableCascadeLockCss;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\HttpInterface;

/**
 * Corrige cabeçalho na home quando o HTML completo é atribuído à resposta (FPM).
 */
class PatchHomeHeaderHtmlPlugin
{
    /** Carrinho/checkout: critical inline cobre header — omitir ~170KB cascade-lock. */
    private const CHECKOUT_FOCUS_ACTIONS = [
        'checkout_cart_index',
        'checkout_index_index',
        'onepagecheckout_index_index',
    ];

    /** PLP/PDP/busca: OptimizeHeadStylesPlugin emite catalog-parity, não o cascade-lock. */
    private const CATALOG_STACK_ACTIONS = [
        'catalog_category_view',
        'catalogsearch_result_index',
        'catalog_product_view',
    ];

    private static bool $isPatching = false;

    public function __construct(
        private readonly HttpRequest $request,
    ) {
    }

    public function afterAppendBody(HttpInterface $subject, mixed $result, $value): mixed
    {
        return $this->patchHomeHtmlIfNeeded($subject, $result);
    }

    public function afterSetBody(HttpInterface $subject, mixed $result, $body): mixed
    {
        return $this->patchHomeHtmlIfNeeded($subject, $result);
    }

    public function beforeSendResponse(HttpInterface $subject): void
    {
        $this->patchHomeHtmlIfNeeded($subject, null);
    }

    private function patchHomeHtmlIfNeeded(HttpInterface $subject, mixed $result): mixed
    {
        $html = (string) $subject->getBody();
        $action = $this->request->getFullActionName();

        if (self::$isPatching || $html === '' || !str_contains($html, '</body>')) {
            return $result;
        }

        if (!HeaderImpeccableCascadeLockCss::htmlHasSiteHeader($html)) {
            return $result;
        }

        self::$isPatching = true;
        try {
            $terminalV = HeaderImpeccableCascadeLockCss::HEADER_TERMINAL_VERSION;
            $html = preg_replace(
                '/awa-header-refine-terminal\.min\.css(?:\?v=[^"\'&\s>]+)?/',
                'awa-header-refine-terminal.min.css?v=' . $terminalV,
                $html
            ) ?? $html;

            if ($action === 'cms_index_index') {
                // Normaliza qualquer versão legada do gate script para a versão canônica atual.
                // Usa regex para evitar lista crescente de versões hardcoded (BUG-01 Camada 4).
                $html = preg_replace(
                    '/awa-css-gate\.min\.js\?v=[^"\'&\s>]+/',
                    'awa-css-gate.min.js?v=' . HeaderImpeccableCascadeLockCss::GATE_SCRIPT_QUERY,
                    $html
                ) ?? $html;
            }

            if ($action === 'cms_index_index') {
                // Home vis-fix + distill: OptimizeHeadStylesPlugin (sortOrder 998) injeta após distill terminal.
            } elseif ($action === 'helpcenter_index_index') {
                // Não re-injetar cascade-lock (~213KB) — mesmo tratamento de checkout/auth.
                $html = HeaderImpeccableCascadeLockCss::stripLegacyFromHtml($html);
            } elseif (in_array($action, self::CHECKOUT_FOCUS_ACTIONS, true)
                || in_array($action, HeaderImpeccableCascadeLockCss::AUTH_FOCUS_ACTIONS, true)
                || HeaderImpeccableCascadeLockCss::isAuthShellHtml($html)
                || HeaderImpeccableCascadeLockCss::isB2bAccountOperationalHtml($html)
                || in_array($action, self::CATALOG_STACK_ACTIONS, true)
                || in_array($action, ['cms_noroute_index', 'cms_page_view'], true) // HOTFIX PERF (hml): 404/CMS herdam o stack do catalogo
            ) {
                $html = HeaderImpeccableCascadeLockCss::stripLegacyFromHtml($html);
            } elseif (!HeaderImpeccableCascadeLockCss::isPresentInHtml($html)
                || !str_contains($html, 'awa-header-cascade-lock-guard')
            ) {
                // Catálogo agora também está no ramo de strip: Optimize emite
                // injectCatalogLightBeforeBodyClose (sem cascade-lock ~221KB).
                $html = HeaderImpeccableCascadeLockCss::injectBeforeBodyClose($html);
            }

            /*
             * O shell cross-page é a única camada terminal compartilhada. Este
             * plugin pode reinjetar o cascade-lock depois do Optimize; portanto
             * reposiciona o mesmo bloco, sem duplicá-lo, no fim do body.
             */
            $shellStyleId = HeaderImpeccableCascadeLockCss::CROSSPAGE_SHELL_STYLE_ID;
            if (preg_match(
                '/<style id="' . preg_quote($shellStyleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
                $html,
                $shellMatch
            ) === 1) {
                $html = str_replace($shellMatch[0], '', $html);
                $moved = preg_replace('/<\/body>/i', $shellMatch[0] . "\n</body>", $html, 1);
                $html = is_string($moved) ? $moved : $html;
            }

            $densityStyleId = 'awa-home-b2b-ops-density-20260805';
            if ($action === 'cms_index_index' && preg_match(
                '/<style id="' . preg_quote($densityStyleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
                $html,
                $densityMatch
            ) === 1) {
                $html = str_replace($densityMatch[0], '', $html);
                $movedDensity = preg_replace('/<\/body>/i', $densityMatch[0] . "\n</body>", $html, 1);
                $html = is_string($movedDensity) ? $movedDensity : $html;
            }

            $subject->setBody($html);
            // Não sobrescrever headers especializados (v12-auth, v12-b2b-account) setados pelo OptimizeHeadStylesPlugin.
            $existingHeader = $subject->getHeader('X-Awa-Header-Optimize');
            $existingValue = ($existingHeader && $existingHeader !== false)
                ? (string) $existingHeader->getFieldValue()
                : '';
            if ($existingValue === '' || $existingValue === 'v12') {
                $subject->setHeader('X-Awa-Header-Optimize', 'v12', true);
            }
        } finally {
            self::$isPatching = false;
        }

        return $result;
    }
}
