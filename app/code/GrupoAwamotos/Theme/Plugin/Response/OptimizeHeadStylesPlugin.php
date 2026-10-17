<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Plugin\Response;

use GrupoAwamotos\Theme\Model\CascadeAssetManifest;
use GrupoAwamotos\Theme\Model\HeaderImpeccableCascadeLockCss;
use GrupoAwamotos\Theme\Model\HeadStylesheetNormalizer;
use GrupoAwamotos\Theme\Model\MinicartAssetVersion;
use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;

/**
 * Performance — dedupe CSS async + gate bundles grandes na home (Sprint 3 / PSI).
 */
class OptimizeHeadStylesPlugin
{
    /**
     * @var array<string, string>
     */
    private const LEGACY_ROUTE_REDIRECT_MAP = [
        '/contato' => '/contact',
        '/contact-us' => '/contact',
        '/ofertas.html' => '/super-ofertas.html',
    ];

    private const HOME_ACTION = 'cms_index_index';
    /** 404/URL inexistente — sem cobertura de defer; cai no fallback total-blocking (BUG-404-CSS). */
    private const NOROUTE_ACTION = 'cms_noroute_index';
    /**
     * Todas as páginas CMS por identificador (Sobre, Contato, FAQ, Termos, Trocas,
     * Formas de Pagamento, Atacado, etc. — 25+ páginas) compartilham este fullAction
     * (Magento\Cms\Controller\Page\View). Mesmo bug do BUG-404-HANG (ver NOROUTE_ACTION
     * abaixo): confirmado via Playwright em about-us/contact-us/faq (renderer do
     * Chromium trava/derruba com awa-master-fix.js ativo, 0/3 runs completam; 3/3 OK
     * bloqueado). Agrupado com NOROUTE_ACTION no mesmo branch de mitigação.
     */
    private const CMS_PAGE_VIEW_ACTION = 'cms_page_view';
    /**
     * Landing page de marketing B2B ("seja-revendedor" via url_rewrite →
     * b2b/marketing/landing). Não é cms_page_view, mas herda o mesmo layout
     * default.xml completo e reproduz o mesmo travamento (confirmado via Playwright:
     * falha intermitente ~1 a cada 3-5 runs com awa-master-fix.js ativo).
     */
    private const B2B_MARKETING_LANDING_ACTION = 'b2b_marketing_landing';

    /** Central de Ajuda — mesmo chrome de CMS, sem o cascade-lock inline (~213KB). */
    private const HELP_CENTER_ACTION = 'helpcenter_index_index';

    // Nomes/fragmentos/queries de CSS vivem em CascadeAssetManifest (Fase 3).
    // Listas de match por rota (homeGate/authStrip/cartStrip/...) também —
    // ver os grupos homônimos no manifest.

    /**
     * /catalogo — rota de MODULO custom (`catalogo/index/index`), NAO e CMS page nem
     * categoria. Medido em 07/10/2026: o body class e `catalogo-index-index
     * awa-catalog-page page-layout-2columns-left`, e o header `X-Awa-Header-Optimize`
     * vinha *** v12 *** (default) — ou seja, NENHUM branch de otimizacao rodava.
     *
     * Consequencia medida: `awa-master-fix.js` ficava ATIVO nesta rota. E o mesmo script
     * que o comentario de BUG-404-HANG (abaixo) documenta como "trava/derruba o renderer
     * do Chromium" — 0/3 runs completam com ele, 3/3 OK sem. Ele ja e removido em
     * HOME_ACTION e CATALOG_STACK_ACTIONS; a rota do catalogo nunca foi incluida.
     *
     * Medicao no /catalogo antes da correcao: 31 long tasks, *** 4.088ms *** de main
     * thread bloqueada, DCL 8.321ms, load 17.507ms, e 62 long tasks DEPOIS do load
     * (t=40s+), 923 mutacoes de DOM, 304 requestAnimationFrame.
     */
    private const CATALOGO_ACTION = 'catalogo_index_index';

    /** PLP/busca/PDP: stack dedicado §89–§94 — omitir terminal Impeccable (~183KB). */
    private const CATALOG_STACK_ACTIONS = [
        'catalog_category_view',
        'catalogsearch_result_index',
        'catalog_product_view',
    ];

    /** @deprecated use CATALOG_STACK_ACTIONS — mantido para defer não-crítico PLP/busca */
    private const CATALOG_HEADER_ACTIONS = [
        'catalog_category_view',
        'catalogsearch_result_index',
    ];

    /** align-grid async — só home/carrinho: inline lock cobre o 1º paint.
     * PLP/PDP/busca NÃO entram: defer (print→all no </body>) gerava CLS ~0.94 nos cards. */
    private const DEFER_ALIGN_GRID_ACTIONS = [
        self::HOME_ACTION,
        self::CART_ACTION,
    ];

    private const CART_ACTION = 'checkout_cart_index';

    /** Carrinho/checkout: header em modo foco — omitir cascade-lock inline (~170KB + guard script). */
    private const CHECKOUT_FOCUS_ACTIONS = [
        'checkout_cart_index',
        'checkout_index_index',
        'onepagecheckout_index_index',
        'checkout_onepage_success',
    ];

    public function __construct(
        private readonly HttpRequest $request,
        private readonly AppState $appState,
        private readonly CascadeAssetManifest $manifest,
        private readonly HeadStylesheetNormalizer $normalizer,
    ) {
    }

    public function beforeSendResponse(HttpInterface $subject): void
    {
        if (!$subject instanceof \Magento\Framework\HTTP\PhpEnvironment\Response) {
            return;
        }

        // Defesa: nunca injetar CSS/JS de storefront (ex.: js/awa-minicart-position) no admin.
        try {
            if ($this->appState->getAreaCode() !== Area::AREA_FRONTEND) {
                return;
            }
        } catch (LocalizedException) {
            return;
        }

        $fullAction = $this->request->getFullActionName();
        $legacyRequestPath = $this->resolveLegacyRequestPath();
        if ($this->applyLegacyRouteRedirect($subject, $legacyRequestPath)) {
            return;
        }

        $contentType = $subject->getHeader('Content-Type');
        if ($contentType && stripos($contentType->getFieldValue(), 'text/html') === false) {
            return;
        }

        $html = (string) $subject->getBody();
        if ($html === '') {
            return;
        }


        $html = $this->stripRedundantAsyncNoscript($html);
        $html = $this->normalizeCssStaggerOnloadHandlers($html, $fullAction === self::HOME_ACTION);
        $html = $this->appendVisualFixesCacheBuster($html);
        $html = $this->appendDesignSystemCacheBuster($html);
        $html = $this->appendPlpShellCacheBuster($html);
        $html = $this->appendAiAssistantCacheBuster($html);
        $html = $this->appendMasterFixCacheBuster($html);

        if ($fullAction === self::CART_ACTION) {
            $html = $this->stripStylesheetFragments($html, $this->manifest->cartStripFragments());
            $html = $this->injectAlignGridStylesheetIfMissing($html);
            $subject->setHeader('X-Awa-Header-Optimize', 'v12-cart', true);
        }

        if (in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)) {
            $html = $this->injectAlignGridStylesheetIfMissing($html);
        }

        if ($fullAction === 'catalog_product_view') {
            $html = $this->injectAlignGridStylesheetIfMissing($html);
        }

        if ($fullAction === self::HOME_ACTION) {
            $html = $this->stripScriptFragments($html, $this->manifest->heavyLegacyScriptFragments());
            $html = $this->stripStandaloneStylesheetNoscript($html);
            $html = $this->stripHomeHeavyParentStylesheets($html);
            $html = $this->stripStaleHomeCriticalStylesheets($html);
            $html = $this->patchStaleHomeHeaderAssets($html);
            /* Fila gate antes de gateHomeLargeStylesheets — inject vazio após gate quebrava merge (H-opt). */
            $html = $this->injectHomeHeaderTerminalAssets($html);
            $html = $this->injectHomeAlignGridStylesheetsIfMissing($html);
            $html = $this->gateHomeLargeStylesheets($html);
            $html = $this->pruneHomeNeverGateQueueUrls($html);
            $html = $this->stripHomeThemeOptionLateFouc($html);
            $html = $this->removePrintLinksListedInGateQueue($html);
            $html = $this->stripHomeGatedNoscriptFallbacks($html);
            $html = $this->dedupeStylesheetHrefs($html);
            $subject->setHeader('X-Awa-Header-Optimize', 'v12-r8-nostack', true);
        } elseif (in_array($fullAction, self::CATALOG_HEADER_ACTIONS, true)) {
            $html = $this->injectHeaderTerminalStylesheetIfMissing($html);
            $html = $this->injectAlignGridStylesheetIfMissing($html);
            $html = $this->deferCatalogNonCriticalStylesheets($html);
            $html = $this->stripStylesheetFragments($html, $this->manifest->catalogStripLegacyBundleFragments());
            // SSOT 2026-08-10: plp-distill aposentado (migrado ao LESS refine terminal).
            // PLP usa styles-l + refine + align-grid + m2-visual-ssot + design-system.
            $subject->setHeader('X-Awa-Header-Optimize', 'v12-catalog', true);
        } elseif ($fullAction === self::NOROUTE_ACTION
            || $fullAction === self::CMS_PAGE_VIEW_ACTION
            || $fullAction === self::B2B_MARKETING_LANDING_ACTION
            || $fullAction === self::HELP_CENTER_ACTION
            || $fullAction === self::CATALOGO_ACTION
        ) {
            // BUG-404-HANG / BUG-CMS-HANG: awa-master-fix.js trava/derruba o renderer
            // do Chromium nestas rotas (confirmado via Playwright em cms_noroute_index,
            // cms_page_view — about-us/contact-us/faq — e b2b_marketing_landing —
            // seja-revendedor: falhas intermitentes/consistentes com o script ativo
            // vs 100% OK bloqueado). Já é removido em HOME_ACTION/CATALOG_STACK_ACTIONS
            // pelo mesmo motivo — nunca foi estendido às demais rotas que herdam o
            // layout default.xml completo (25+ páginas institucionais + landing B2B).
            $html = $this->stripScriptFragments($html, $this->manifest->heavyLegacyScriptFragments());
            $html = $this->deferStylesheetsByFragments($html, $this->manifest->norouteDeferFragments());
            $optimizeTag = match ($fullAction) {
                self::NOROUTE_ACTION => 'v12-noroute',
                self::CMS_PAGE_VIEW_ACTION => 'v12-cms-page',
                self::HELP_CENTER_ACTION => 'v12-helpcenter',
                self::CATALOGO_ACTION => 'v12-catalogo',
                default => 'v12-b2b-landing',
            };
            $subject->setHeader('X-Awa-Header-Optimize', $optimizeTag, true);
        }

        if (in_array($fullAction, self::CATALOG_STACK_ACTIONS, true)) {
            $html = $this->stripScriptFragments($html, $this->manifest->heavyLegacyScriptFragments());
            $html = $this->stripStylesheetFragments($html, $this->manifest->catalogStripImpeccableFragments());
            $subject->setHeader('X-Awa-Header-Optimize', 'v12-catalog-stack', true);
        }

        $html = $this->normalizeRefineStylesheetQuery($html);

        if ($fullAction === 'catalog_product_view') {
            $html = $this->normalizePdpTerminalStylesheets($html);
            $html = $this->deferCatalogNonCriticalStylesheets($html);
        }

        $isAuthFocusPageEarly = in_array($fullAction, HeaderImpeccableCascadeLockCss::AUTH_FOCUS_ACTIONS, true)
            || HeaderImpeccableCascadeLockCss::isAuthShellHtml($html);
        $isB2bAccountFocusEarly = $this->isB2bAccountFocusPage($fullAction, $html);
        if (!in_array($fullAction, self::CATALOG_STACK_ACTIONS, true)
            && $fullAction !== self::CART_ACTION
            && $fullAction !== self::HOME_ACTION
            && !$isAuthFocusPageEarly
            && !$isB2bAccountFocusEarly
        ) {
            $html = $this->injectGlobalRefineStylesheetIfMissing($html, $fullAction);
        }

        if (HeaderImpeccableCascadeLockCss::htmlHasSiteHeader($html)) {
            $html = $this->stripStylesheetFragments($html, $this->manifest->migratedHeaderFragments());
            $html = $this->normalizeHeaderTerminalStylesheetVersion($html);
            if ($fullAction === self::HOME_ACTION) {
                // Home: v10 + critical-home no head; omitir cascade-lock (~112KB) — lock leve no body.
                $html = HeaderImpeccableCascadeLockCss::injectHomeLightBeforeBodyClose($html);
            } elseif (in_array($fullAction, HeaderImpeccableCascadeLockCss::AUTH_FOCUS_ACTIONS, true)
                || HeaderImpeccableCascadeLockCss::isAuthShellHtml($html)
            ) {
                // Auth B2B: critical inline cobre layout — só remover cascade (~111KB).
                $html = HeaderImpeccableCascadeLockCss::stripLegacyFromHtml($html);
            } elseif ($isB2bAccountFocusEarly) {
                // Painel B2B: remove só cascade-lock global; não injeta home-light para não degradar a UI da conta.
                $html = HeaderImpeccableCascadeLockCss::stripLegacyFromHtml($html);
            } elseif (in_array($fullAction, self::CATALOG_STACK_ACTIONS, true)) {
                // PLP/PDP/busca: omite cascade-lock (~221KB). A geometria do header
                // permanece na SSOT unify (normalizeHeaderGeometryAuthorityInlineStyles);
                // catalog-parity-v1 é removido lá como segunda fonte de verdade.
                $html = HeaderImpeccableCascadeLockCss::injectCatalogLightBeforeBodyClose($html);
            } elseif (in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)) {
                // Checkout/carrinho: mantém sem guard para preservar o shell foco.
                $html = HeaderImpeccableCascadeLockCss::stripLegacyFromHtml($html);
                $html = HeaderImpeccableCascadeLockCss::injectFooterOnlyBeforeBodyClose($html);
            } elseif ($fullAction === self::HELP_CENTER_ACTION) {
                // Central de Ajuda: omitir cascade-lock (~213KB) como home/checkout.
                // Já tem footer-critical/terminal; não chamar injectFooterOnly (re-injeta ~107KB).
                $html = HeaderImpeccableCascadeLockCss::stripLegacyFromHtml($html);
            } else {
                $html = $this->injectHeaderTerminalFixStyle($html);
            }
        }

        $html = HeaderImpeccableCascadeLockCss::injectFooterCategoriesDesktopLayoutScript($html);

        $html = $this->dedupeStylesheetHrefs($html);
        $isAuthFocusPage = in_array($fullAction, HeaderImpeccableCascadeLockCss::AUTH_FOCUS_ACTIONS, true)
            || HeaderImpeccableCascadeLockCss::isAuthShellHtml($html);
        $isB2bAccountFocusPage = $this->isB2bAccountFocusPage($fullAction, $html);
        if ($fullAction !== self::HOME_ACTION
            && !in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)
            && !$isAuthFocusPage
            && !$isB2bAccountFocusPage
        ) {
            $html = $this->deferStylesheetsByFragments($html, $this->manifest->menuDeferFragments());
        }
        if (in_array($fullAction, self::CATALOG_HEADER_ACTIONS, true)) {
            $html = $this->deferStylesheetsByFragments($html, $this->manifest->plpDeferFragments());
        }
        if (!$isAuthFocusPage && !$isB2bAccountFocusPage) {
            $html = $this->consolidateAlignGridToBodyTerminal($html, $fullAction);
            $html = $this->consolidateM2VisualSsotTerminal($html, $fullAction);
            if (!in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)) {
                $html = $this->injectSiteShellInlineLock($html, $fullAction);
            }
            $html = $this->injectAlignGridHeaderContainerTerminal($html);
            $html = $this->injectHeaderSimplifyUiTerminalLock($html);
            $html = $this->injectMainContentSkipTargetTabindex($html);
            $html = $this->injectSearchAutocompleteCompactStyles($html);
        } else {
            // Auth / painel B2B: critical inline define layout — omitir locks globais + folhas pesadas.
            $html = preg_replace('/<style id="awa-align-grid-inline-lock[^"]*"[^>]*>.*?<\/style>/s', '', $html) ?? $html;
            $html = preg_replace('/<style id="awa-align-grid-header-container-terminal"[^>]*>.*?<\/style>/s', '', $html) ?? $html;
            $html = preg_replace(
                '/<link\s[^>]*' . preg_quote($this->manifest->fragment('align-grid-base'), '/') . '[^>]*\/?>\s*/i',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<link\s[^>]*' . preg_quote($this->manifest->fragment('header-contract-grid'), '/') . '[^>]*\/?>\s*/i',
                '',
                $html
            ) ?? $html;
            $stripFragments = $isB2bAccountFocusPage
                ? $this->manifest->b2bAccountStripFragments()
                : $this->manifest->authStripFragments();
            $html = $this->stripStylesheetFragments($html, $stripFragments);
            $html = preg_replace('/<script[^>]*awa-mirasvit-autocomplete-init[^>]*><\/script>\s*/i', '', $html) ?? $html;
            $html = preg_replace('/<link[^>]+href=["\'][^"\']*\/fonts\/(?:rubik|source-sans-3)\/[^"\']+["\'][^>]*>\s*/i', '', $html) ?? $html;
            $html = preg_replace(
                '/<link[^>]+rel=["\']preload["\'][^>]+as=["\']style["\'][^>]+'
                . preg_quote($this->manifest->fragment('cookie-consent-fix'), '/') . '[^>]*>\s*/i',
                '',
                $html
            ) ?? $html;
            if ($isAuthFocusPage) {
                $html = $this->stripAuthPageNoise($html);
                $subject->setHeader('X-Awa-Header-Optimize', 'v12-auth-r5e', true);
            } else {
                $html = $this->stripB2bAccountPageNoise($html);
                $subject->setHeader('X-Awa-Header-Optimize', 'v12-b2b-account', true);
            }
        }

        $html = $this->normalizeHeaderGeometryAuthorityInlineStyles(
            $html,
            $fullAction,
            $isAuthFocusPage,
            $isB2bAccountFocusPage
        );

        $html = $this->stripHeavyHeadPreloadInlineStyleOutsideHome(
            $html,
            $fullAction,
            $isAuthFocusPage,
            $isB2bAccountFocusPage
        );

        $html = $this->stripRedundantAsyncNoscript($html);
        $html = $this->stripStylePreloadDuplicates($html);
        $html = $this->injectGlobalFocusVisibleFallback($html);
        $html = $this->injectGlobalWebVitalsRum($html);
        $html = $this->injectViewTransitionGuardScript($html);
          $html = $this->normalizeExcessiveHtmlBodySpecificity($html);

        /* task-51: o lock de geometria do breadcrumbs e injetado DEPOIS do
         * normalizador de especificidade - senao ele colapsa o booster de 24x
         * #html-body para 3x e a regra perde para o min-height do
         * align-grid-terminal (6 IDs, mesma @layer awa-visual-priority).
         * Mesma posicao relativa do injectCatalogDesktopClsGeometryLock (PLP). */
        if ($fullAction === 'catalog_product_view') {
            $html = $this->injectPdpBreadcrumbGeometryLock($html);
        }

        /* task-73 P1: hierarquia de CTA na PDP anonima — o cadastro (cliente NOVO)
         * passa a ser o controle PRIMARIO e "Entrar para Comprar" (quem JA e cliente)
         * vira SECUNDARIO, mas continua visivel e focavel. Medido antes: o unico
         * preenchido era o "Entrar para Comprar" (bg rgb(183,51,55), 574x44), e o
         * cadastro era link de texto de 219x44/13px. */
        if ($fullAction === 'catalog_product_view') {
            $html = $this->injectPdpCtaHierarchy($html);
        }

        /* task-51 passo 2: alinha o bloco do hero da categoria (breadcrumb + H1 +
         * contagem) a borda do container. Medido: +24px no desktop e +12px no
         * mobile, porque o card do hero re-aplica padding que o container ja da. */
        /* task-71: as LANDINGS de busca (/capacetes etc.) tem a classe
         * `search_landing-page-view` no body, mas o $fullAction delas e a acao do
         * modulo de landing — NAO entra em CATALOG_HEADER_ACTIONS, entao nenhum lock
         * era injetado nelas. Disparamos tambem por CONTEUDO da resposta. */
        if (in_array($fullAction, self::CATALOG_HEADER_ACTIONS, true)
            || str_contains($html, 'search_landing-page-view')
        ) {
            $html = $this->injectCategoryHeroAlignLock($html);
        }

        /* Home: gate após body-terminal — folhas pesadas entram na fila idle (PSI/TBT). */
        if ($fullAction === self::HOME_ACTION) {
            $html = $this->deferStylesheetsByFragments(
                $html,
                $this->manifest->homeDeferFragments()
            );
            $html = $this->gateHomeLargeStylesheets($html);
            $html = $this->pruneHomeNeverGateQueueUrls($html);
            $html = $this->stripHomeThemeOptionLateFouc($html);
            $html = $this->removePrintLinksListedInGateQueue($html);
            $html = $this->stripHomeGatedNoscriptFallbacks($html);
            $html = $this->synchronizeHomeRefineStylesheet($html);
            $html = $this->consolidateHomeDeferredStylesheetStack($html);
            $html = $this->dedupeStylesheetHrefs($html);
            /* BUG-SHELL-GEOM-TOKEN-001: stack absorve/strip o contract; reinjeta SSOT geom depois. */
            $html = $this->injectHeaderContractGeomSsotForHome($html);
        }

        // Executa após o gate da home para a folha estrutural síncrona não ser reclassificada como pesada.
        // r5e: B2B auth empty-shell keeps awa-b2b-auth-critical-inline only (~40KB).
        // Skip home cascade: footer-critical, promo/FAB, pixel-qa, visual-bugfix terminals.
        if (!$isAuthFocusPage) {
            $html = $this->convertFooterCssToAsync($html, $fullAction);

            $html = $this->injectHeaderVisualAuditFixes($html, $fullAction, $isAuthFocusPage, $isB2bAccountFocusPage);
            $html = $this->injectHomeGapMarginHotfix($html, $fullAction);
            $html = $this->injectVisualBugfixTerminalStyles($html, $fullAction);
            $html = $this->injectMinicartOpenTerminalStyles($html, $fullAction);
            $html = $this->injectHomeCompactSpacingTerminalStyles($html, $fullAction);

            if ($fullAction === self::HOME_ACTION) {
                $html = $this->moveHomeCriticalInlineStylesToHead($html);
                $html = $this->stripEmptyHomePriceShells($html);
                $html = $this->injectHomeHiddenUiGuardStyles($html);
                // CSS/JS terminais grandes → arquivos externos cacheáveis (HTML −~140KB).
                $html = $this->convertHomeHeavyTerminalsToExternal($html);
                // convert recria <link> que o stack já absorveu — strip de novo sem segundo inject.
                $html = $this->consolidateHomeDeferredStylesheetStack($html);
                $html = $this->stripHomeHeavyParentStylesheets($html);
                $html = $this->dedupeStylesheetHrefs($html);
                $html = $this->injectHeaderContractGeomSsotForHome($html);
                $html = $this->injectShelfViewAllMobileHideR18($html, $fullAction);
                $html = $this->injectCategoryCarouselDotsR24b($html, $fullAction);
            }

            $html = $this->injectFooterStoreFlatR21b($html);
            $html = $this->injectFooterCnpjFlatR26($html);
            $html = $this->injectB2bPromoContrastR27($html);
            $html = $this->injectFooterSealsPayFlatR28($html);
            $html = $this->injectFooterNewsletterFormR29($html);
            $html = $this->injectMobileFabStackR30($html);
            $html = $this->injectDesktopFabStackR33($html);
            $html = $this->injectMobileToggleFlatR34($html);
            $html = $this->injectMobileBottomNavEqualR35($html);
            $html = $this->injectOwlProgressRadiusR35($html, $fullAction);
            $html = $this->injectCarouselNavRadiusR36($html, $fullAction);
            $html = $this->injectCarouselToggleGutterR38($html, $fullAction);
            $html = $this->injectB2bPromoShellR40($html);
            $html = $this->injectMobileBottomNavInactiveR37($html);
            $html = $this->injectFooterTrustIconFlatR31($html);
        }

        if ($fullAction === self::HOME_ACTION
            || $fullAction === self::HELP_CENTER_ACTION
            || in_array($fullAction, self::CATALOG_STACK_ACTIONS, true)
            || in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)
            || $isAuthFocusPage
            || $isB2bAccountFocusPage
        ) {
            $html = $this->stripScriptFragments($html, $this->manifest->heavyLegacyScriptFragments());
        }
        // Carrinho precisa do hydrate (trigger "Olá, …"); checkout/auth/conta não.
        if ((
                in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)
                && $fullAction !== self::CART_ACTION
            )
            || $isAuthFocusPage
            || $isB2bAccountFocusPage
        ) {
            $html = $this->stripScriptFragments($html, $this->manifest->checkoutAuthStripScriptFragments());
        }

        // Última camada: unifica overflow/bg do .page-wrapper e sticky entre rotas.
        // r5e: auth pages skip storefront pixel-qa / hero / PLP / header-prompt locks.
        if (!$isAuthFocusPage) {
            $html = $this->injectCrossPageShellLock($html);
        /* PIXEL-QA r5: AFTER audit/normalize — senão IDs colapsam p/ 3 e audit (7×) vence. */
            $html = $this->injectPixelQaGutterLock($html);
        /* 2026-08-12: AFTER normalize — 13 IDs vencem design-system min-width:220px + pílula. */
            $html = $this->injectHeaderAccountPromptLock($html);
        /* WCAG 2.5.8 — touch ≥44 (sorter/breadcrumb/footer); body-end vence locks inline. */
            $html = $this->injectTouch44Terminal($html);
        /* Hero home: border-box + full-bleed (evita clip 16px / imgOverflow). */
            $html = $this->injectHeroBoxTerminal($html);
        /* Home section headers: título+subtítulo empilhados (sem wrap lateral). */
            $html = $this->injectSectionHeaderStackTerminal($html);
        /* PLP: .col-main flex-shrink:0 (page-products) estourava ~18px além do gutter. */
            $html = $this->injectPlpColContainTerminal($html);
        }

        /* ÚLTIMA camada chrome: depois de audit/minicart-open/pixel-qa (senão primary vence). */
        if (!$isAuthFocusPage && !$isB2bAccountFocusPage
            && !in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)
        ) {
            $html = $this->injectGlobalHeaderChromeFoucStyles($html);
        }

        /* Cart dedupe na home; density vai para o fim absoluto (depois do tablet restore). */
        if ($fullAction === self::HOME_ACTION) {
            $html = $this->injectHomeCartDedupeCriticalInline($html);
        }

        /* PLP/busca desktop: CSS legado (styles-l/themes/…) no <head> real via document.write.
         * head.additional cai no <body> nesta resposta — createElement após 1º paint gerava CLS 0.68–0.87.
         * Lock geométrico DEPOIS do blocking: document.write insere <link> no ponto do script;
         * o <style> precisa vir depois desses links para vencer styles-l no 1º paint. */
        if (in_array($fullAction, self::CATALOG_HEADER_ACTIONS, true)) {
            // TBT PLP: defer-init do minicart adiciona observers/timers longos antes da
            // 1ª interação. Em catálogo, a navegação continua funcional sem esse runtime.
            $html = $this->stripScriptFragments($html, [
                'awa-minicart-defer-init.js',
                'awa-minicart-defer-init.min.js',
            ]);
            $html = $this->delayCatalogMinicartDeferIdleBoot($html);
            $html = $this->disableCatalogIntentDesktopIdle($html);
            $html = $this->delayCatalogLegacyDesktopCssInject($html);
            $html = $this->injectCatalogDesktopBlockingCss($html);
            $html = $this->injectCatalogDesktopClsGeometryLock($html);
        }

        if (!$isAuthFocusPage
            && !$isB2bAccountFocusPage
            && (
                !in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)
                || $fullAction === self::CART_ACTION
            )
        ) {
            $html = $this->injectTabletHeaderRestore($html);
        }

        /* Home density: depois do tablet restore; o Patch ainda pode mover o shell depois —
           PatchHomeHeaderHtmlPlugin reposiciona este bloco para depois do shell. */
        if ($fullAction === self::HOME_ACTION) {
            $html = $this->injectHomeB2bOpsDensityTerminalStyles($html, $fullAction);
        }

        if ($fullAction === self::HOME_ACTION) {
            $html = $this->stripHomeAwaInlineStyleTags($html);
            $html = $this->stripHomeCssGate($html);
        }


        /* A11y bridge (WCAG 2.5.3/1.3.5): as edicoes de template do header nao
           propagam (anomalia de cache de bloco documentada no claude-progress).
           Normalizacao no HTML final; remover quando os templates renderizarem. */
        $html = str_replace(
            'aria-label="Ir&#x20;para&#x20;a&#x20;p&#xE1;gina&#x20;inicial&#x20;da&#x20;AWA&#x20;Motos"',
            'aria-label="Ir&#x20;para&#x20;a&#x20;p&#xE1;gina&#x20;inicial&#x20;da&#x20;AWA&#x20;Motos&#x20;&#x2014;&#x20;MOTO&#x20;COMPONENTES"',
            $html
        );
        $html = str_replace(
            'aria-label="AWA&#x20;Motos"',
            'aria-label="AWA&#x20;Motos&#x20;&#x2014;&#x20;MOTO&#x20;COMPONENTES"',
            $html
        );
        $html = str_replace(' autocomplete="search"', '', $html);
        /* Nomenclatura unica do SKU (auditoria #39): Cod: -> REF: */
        $html = str_replace(
            '<span class="awa-b2b-sku__label">Cod:</span>',
            '<span class="awa-b2b-sku__label">REF:</span>',
            $html
        );

        $subject->setBody($html);
    }

    
    /**
     * Home freeze 2026-09-02: do not load css-gate JS or QUEUE_HOME_LOAD_LAST
     * on cms_index_index. Safety-net for FPC/preload leftovers.
     */
    private function stripHomeCssGate(string $html): string
    {
        $html = preg_replace(
            '/<script\b[^>]*\bsrc=["\'][^"\']*awa-css-gate(?:\.min)?\.js[^"\']*["\'][^>]*>\s*<\/script>\s*/i',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script\b[^>]*\bid=["\']awa-css-gate-queue["\'][^>]*>.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script\b[^>]*>[^<]*awa-css-gate(?:\.min)?\.js.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Home freeze 2026-09-02: ~540KB of <style id="awa-*"> (12×#html-body / :has())
     * stalled Chrome's main thread. Rules now live in awa-design-system.css SEÇÃO 49
     * (@layer awa-visual-priority). Strip leftover PHTML/plugin injectors on home only.
     */
    private function stripHomeAwaInlineStyleTags(string $html): string
    {
        $stripped = preg_replace(
            '/<style\b[^>]*\bid="awa-[^"]*"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        );

        return is_string($stripped) ? $stripped : $html;
    }


    private function applyLegacyRouteRedirect(
        \Magento\Framework\HTTP\PhpEnvironment\Response $response,
        string $requestPath
    ): bool {
        $method = strtoupper((string) $this->request->getMethod());
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        $target = self::LEGACY_ROUTE_REDIRECT_MAP[$requestPath] ?? null;
        if ($target === null || $target === $requestPath) {
            return false;
        }

        $response->setRedirect($target, 301);
        $response->setBody('');

        return true;
    }

    private function resolveLegacyRequestPath(): string
    {
        foreach ($this->resolveLegacyRequestPathCandidates() as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return string[]
     */
    private function resolveLegacyRequestPathCandidates(): array
    {
        $candidates = [];
        $requestUri = $this->request->getServer('REQUEST_URI');
        if (is_string($requestUri)) {
            $candidates[] = $requestUri;
        }
        $redirectUrl = $this->request->getServer('REDIRECT_URL');
        if (is_string($redirectUrl)) {
            $candidates[] = $redirectUrl;
        }
        $origPathInfo = $this->request->getServer('ORIG_PATH_INFO');
        if (is_string($origPathInfo)) {
            $candidates[] = $origPathInfo;
        }
        $pathInfo = $this->request->getServer('PATH_INFO');
        if (is_string($pathInfo)) {
            $candidates[] = $pathInfo;
        }
        if (method_exists($this->request, 'getOriginalPathInfo')) {
            $candidates[] = (string) $this->request->getOriginalPathInfo();
        }
        $candidates[] = (string) $this->request->getRequestUri();
        $candidates[] = (string) $this->request->getPathInfo();

        $normalizedPaths = [];
        foreach ($candidates as $candidate) {
            $path = parse_url($candidate, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                continue;
            }

            $normalized = '/' . ltrim($path, '/');
            if ($normalized !== '/' && str_ends_with($normalized, '/')) {
                $normalized = rtrim($normalized, '/');
            }

            if (!in_array($normalized, $normalizedPaths, true)) {
                $normalizedPaths[] = $normalized;
            }
        }

        return $normalizedPaths;
    }



    /**
     * Resolve parâmetros de probe com fallback no query string bruto.
     *
     * @return array{
     *   probeParam:string,
     *   probeParamAlias:string,
     *   probePingParam:string,
     *   probePingParamAlias:string,
     *   probeNoscriptParam:string,
     *   probeNoscriptParamAlias:string,
     *   probeDomParam:string,
     *   runTraceToken:string,
     *   probeTraceParam:string,
     *   usedRawFallback:bool
     * }
     */

    /**
     * @param mixed $value
     */

    /**
     * Runtime probe (cliente) para capturar axis/header/nav no navegador real do usuário.
     */

    /**
     * Recebe probe de axis enviado pelo navegador (query string) e grava no NDJSON de debug.
     */

    /**
     * Heartbeat de execução do probe para diferenciar JS bloqueado x beacon bloqueado.
     */

    /**
     * Home: dedupe do link legado de carrinho no 1º paint (sync inline).
     * A regra vive em awa-head-preload-critical-home (print→all); sem inline o SVG
     * estoura antes da folha e o JS legacyCart remove display no tablet.
     */
    private function injectHomeCartDedupeCriticalInline(string $html): string
    {
        $styleId = 'awa-home-cart-dedupe-critical-sync';
        $html = preg_replace(
            '/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::headerCartDedupeRules()
            . 'html body#html-body .page-wrapper .minicart-wrapper:not(.active):not(.show):not(.is-open) '
            . ':is(.block-minicart,.awa-minicart-footer),'
            . 'html body#html-body .page-wrapper .awa-header-minicart:not(.awa-header-minicart--expanded) '
            . ':is(.block-minicart:not(._active):not(.is-open),.awa-minicart-footer){'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important}'
            . '</style>';

        /* Body-end: 6×#html-body depois do align-grid (link) — vence display:inline-flex no fallback. */
        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Garante contrato visual do shell (.page-wrapper + sticky) em qualquer fullAction.
     */
    private function injectCrossPageShellLock(string $html): string
    {
        if (!str_contains($html, 'page-wrapper')
            || !HeaderImpeccableCascadeLockCss::htmlHasSiteHeader($html)
        ) {
            return $html;
        }

        $styleId = HeaderImpeccableCascadeLockCss::CROSSPAGE_SHELL_STYLE_ID;
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::crossPageShellLockRules()
            . HeaderImpeccableCascadeLockCss::headerChromeCanonicalRules()
            . HeaderImpeccableCascadeLockCss::headerCartDedupeRules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : ($html . "\n" . $css);
    }

    /**
     * Última camada tablet: restaura nav + conta 44px depois do prompt-lock (13×).
     */
    private function injectTabletHeaderRestore(string $html): string
    {
        if (!str_contains($html, 'page-wrapper')
            || !HeaderImpeccableCascadeLockCss::htmlHasSiteHeader($html)
        ) {
            return $html;
        }

        $styleId = 'awa-tablet-header-restore-r1';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::headerTabletShellRestoreRules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : ($html . "\n" . $css);
    }

    private function moveHomeCriticalInlineStylesToHead(string $html): string
    {
        $styleIds = [
            'awa-home-impeccable-terminal-v1',
            'awa-align-grid-inline-lock-20260626-phase3d22b',
            'awa-align-grid-header-container-terminal',
            'awa-header-simplify-ui-terminal-lock',
            'awa-header-visual-audit-fixes-20260630',
            'awa-home-gap-hotfix-20260705',
            'awa-visual-bugfix-terminal-20260707',
            'awa-home-compact-spacing-terminal-20260707',
        ];
        $criticalStyles = [];

        foreach ($styleIds as $styleId) {
            $pattern = '/<style\s[^>]*id=(["\'])' . preg_quote($styleId, '/') . '\1[^>]*>.*?<\/style>\s*/is';
            if (!preg_match($pattern, $html, $match)) {
                continue;
            }

            $criticalStyles[] = trim($match[0]);
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        if ($criticalStyles === []) {
            return $html;
        }

        $injected = preg_replace(
            '/<\/head>/i',
            implode("\n", $criticalStyles) . "\n</head>",
            $html,
            1
        );

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Home B2B guest — remove wrappers de preco vazios deixados por blocos Rokanthemes.
     * Motivo do ponto de corte: os templates podem vir de cache/preprocessamento, entao a limpeza
     * precisa acontecer na resposta final sem afetar cards que contenham preco/gate real.
     */
    /**
     * Acrescenta ?v=... ao href já renderizado de awa-visual-fixes-2026-06-29-final.min.css.
     *
     * Não pode ser feito via <css src="...?v=..."> no layout XML: isso quebra
     * `pathinfo($path, PATHINFO_EXTENSION)` no core
     * (Magento\Framework\View\Asset\Source::getContentType), fazendo o
     * Magento omitir `rel="stylesheet"` do <link> renderizado — o navegador
     * então ignora o arquivo inteiro (bug real, confirmado ao vivo via curl
     * no HTML bruto antes de qualquer plugin deste tema rodar; não é
     * suposição). Aplicando a query DEPOIS que o core já resolveu o asset e
     * montou o <link> completo (com rel="stylesheet" correto), evitamos o
     * problema e mantemos o cache-busting necessário (pub/static é servido
     * com cache-control immutable de 1 ano).
     *
     * @param string $html
     * @return string
     */
    private function appendVisualFixesCacheBuster(string $html): string
    {
        $assetBase = $this->manifest->fragment('visual-fixes');
        if (strpos($html, $assetBase) === false) {
            return $html;
        }

        $query = $this->manifest->query('visual-fixes');
        $assetPattern = preg_quote($assetBase, '/') . '(?:\.min)?\.css';

        return preg_replace_callback(
            '/<link\s[^>]*href=["\']([^"\']*' . $assetPattern . ')(\?[^"\']*)?["\'][^>]*\/?>/i',
            static function (array $m) use ($query): string {
                if (stripos($m[0], 'rel="stylesheet"') === false && stripos($m[0], "rel='stylesheet'") === false) {
                    return $m[0];
                }
                $newHref = $m[1] . $query;
                return str_replace($m[1] . ($m[2] ?? ''), $newHref, $m[0]);
            },
            $html
        ) ?? $html;
    }

    /**
     * Acrescenta ?v=20260820-slideshow ao awa-design-system.min.css já renderizado.
     *
     * Mesmo motivo de appendVisualFixesCacheBuster(): o asset é immutable (1 ano).
     * Sem query nova, o navegador mantém o CSS anterior e o hero da home continua
     * com letterbox + fade invisível + menu por cima do slide.
     *
     * @param string $html
     * @return string
     */
    private function appendDesignSystemCacheBuster(string $html): string
    {
        $assetBase = $this->manifest->fragment('design-system');
        if (strpos($html, $assetBase) === false) {
            return $html;
        }

        $query = $this->manifest->query('design-system');
        if ($query === '') {
            return $html;
        }

        $assetPattern = preg_quote($assetBase, '/') . '(?:\.min)?\.css';

        return preg_replace_callback(
            '/<link\s[^>]*href=["\']([^"\']*' . $assetPattern . ')(\?[^"\']*)?["\'][^>]*\/?>/i',
            static function (array $m) use ($query): string {
                if (stripos($m[0], 'rel="stylesheet"') === false && stripos($m[0], "rel='stylesheet'") === false) {
                    return $m[0];
                }
                $newHref = $m[1] . $query;
                return str_replace($m[1] . ($m[2] ?? ''), $newHref, $m[0]);
            },
            $html
        ) ?? $html;
    }

    /**
     * Cache-bust de awa-plp-shell-final.min.css (immutable 1 ano).
     *
     * AWA visual-audit 2026-08-26: padding/ícone da busca da categoria.
     *
     * @param string $html
     * @return string
     */
    private function appendPlpShellCacheBuster(string $html): string
    {
        $assetBase = $this->manifest->fragment('plp-shell');
        if (strpos($html, $assetBase) === false) {
            return $html;
        }

        $query = $this->manifest->query('plp-shell');
        if ($query === '') {
            return $html;
        }

        $assetPattern = preg_quote($assetBase, '/') . '(?:\.min)?\.css';

        return preg_replace_callback(
            '/<link\s[^>]*href=["\']([^"\']*' . $assetPattern . ')(\?[^"\']*)?["\'][^>]*\/?>/i',
            static function (array $m) use ($query): string {
                if (stripos($m[0], 'rel="stylesheet"') === false && stripos($m[0], "rel='stylesheet'") === false) {
                    return $m[0];
                }
                $newHref = $m[1] . $query;
                return str_replace($m[1] . ($m[2] ?? ''), $newHref, $m[0]);
            },
            $html
        ) ?? $html;
    }

    /**
     * Cache-bust do CSS do widget AiAssistant (async print/onload; cache imutável).
     *
     * @param string $html
     * @return string
     */
    private function appendAiAssistantCacheBuster(string $html): string
    {
        $assetBase = $this->manifest->fragment('ai-assistant');
        if (strpos($html, $assetBase) === false) {
            return $html;
        }

        $query = $this->manifest->query('ai-assistant');
        if ($query === '') {
            return $html;
        }

        $assetPattern = preg_quote($assetBase, '/') . '(?:\.min)?\.css';

        return preg_replace_callback(
            '/<link\s[^>]*href=["\']([^"\']*' . $assetPattern . ')(\?[^"\']*)?["\'][^>]*\/?>/i',
            static function (array $m) use ($query): string {
                if (stripos($m[0], 'rel="stylesheet"') === false && stripos($m[0], "rel='stylesheet'") === false) {
                    return $m[0];
                }
                $newHref = $m[1] . $query;
                return str_replace($m[1] . ($m[2] ?? ''), $newHref, $m[0]);
            },
            $html
        ) ?? $html;
    }

    /**
     * Acrescenta cache-buster ao awa-master-fix.js depois que o HTML foi gerado.
     *
     * O script e servido por static/version... com cache imutavel. Sem query,
     * navegadores que ja carregaram a versao anterior continuam executando o
     * init bloqueante que segura DOMContentLoaded em PLP/PDP.
     *
     * @param string $html
     * @return string
     */
    private function appendMasterFixCacheBuster(string $html): string
    {
        $needle = 'awa-master-fix.js';
        if (strpos($html, $needle) === false) {
            return $html;
        }

        $query = '20260815-hygiene-r1';

        return preg_replace_callback(
            '/<script\s[^>]*src=["\']([^"\']*' . preg_quote($needle, '/') . ')(\?[^"\']*)?["\'][^>]*><\/script>/i',
            static function (array $m) use ($query): string {
                $newSrc = $m[1] . '?v=' . $query;
                return str_replace($m[1] . ($m[2] ?? ''), $newSrc, $m[0]);
            },
            $html
        ) ?? $html;
    }

    private function stripEmptyHomePriceShells(string $html): string
    {
        return preg_replace('/\s*<div\s+class=["\']info-price["\']>\s*<\/div>/i', '', $html) ?? $html;
    }

    /**
     * Drawer do minicart — CSS/JS vêm do layout Magento (head.additional).
     *
     * Este método não concatena regras CSS no HTML. Ele apenas:
     * - captura <style>/<script> completos já renderizados pelo PHTML;
     * - remove CSS cru vazado se o </style> tiver se perdido (texto visível);
     * - omite o bloco em checkout/auth/404;
     * - recoloca as tags completas antes do último </body> para preservar a
     *   cascata terminal (vence locks injetados depois do head).
     */
    private function injectMinicartOpenTerminalStyles(string $html, string $fullAction): string
    {
        $skip = $fullAction === self::NOROUTE_ACTION
            || in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)
            || HeaderImpeccableCascadeLockCss::isAuthShellHtml($html);

        $stylePattern = '/<style\b[^>]*\bid="awa-minicart-open-terminal-20260717[a-z]?"[^>]*>.*?<\/style>\s*/is';
        $scriptPattern = '/<script\b[^>]*\bid="awa-minicart-anchor-terminal-'
            . '(?:20260717[a-z0-9]*|20260801v22)"[^>]*>.*?<\/script>\s*/is';

        $styleTag = null;
        if (preg_match($stylePattern, $html, $styleMatch) === 1) {
            $styleTag = trim($styleMatch[0]);
        }
        $html = preg_replace($stylePattern, '', $html) ?? $html;

        $scriptTag = null;
        if (preg_match($scriptPattern, $html, $scriptMatch) === 1) {
            $scriptTag = trim($scriptMatch[0]);
        }
        $html = preg_replace($scriptPattern, '', $html) ?? $html;

        $html = $this->stripUnwrappedMinicartOpenTerminalCss($html);

        if ($skip) {
            return $html;
        }

        $payload = '';
        if (is_string($styleTag) && $styleTag !== '') {
            $payload .= $styleTag . "\n";
        }
        if (is_string($scriptTag) && $scriptTag !== '') {
            $payload .= $scriptTag . "\n";
        }
        if ($payload === '') {
            return $html;
        }

        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $html . "\n" . $payload;
        }

        return substr($html, 0, $pos) . $payload . substr($html, $pos);
    }

    /**
     * Se o </style> do terminal do minicart se perde, as regras viram texto no body.
     * Remove o bloco por fingerprint único, só depois do </head>.
     */
    private function stripUnwrappedMinicartOpenTerminalCss(string $html): string
    {
        $headClose = stripos($html, '</head>');
        if ($headClose === false) {
            return $html;
        }

        $split = $headClose + 7;
        $head = substr($html, 0, $split);
        $rest = substr($html, $split);
        $stripped = preg_replace(
            '/html body#html-body\.awa-minicart-overlay-active\{overflow:hidden!important\}'
            . '.*?html body#html-body \.modal-popup\{z-index:901\}/s',
            '',
            $rest
        );

        return $head . (is_string($stripped) ? $stripped : $rest);
    }

    private function injectVisualBugfixTerminalStyles(string $html, string $fullAction): string
    {
        // Footer surface precisa ser idêntica em qualquer rota storefront (home/PLP/PDP/CMS).
        $hasFooterMarkup = str_contains($html, 'footer-bottom')
            || str_contains($html, 'page_footer')
            || str_contains($html, 'page-footer');
        if (!$hasFooterMarkup) {
            return $html;
        }

        $styleId = 'awa-visual-bugfix-terminal-20260707';
        $scriptId = 'awa-visual-bugfix-terminal-runtime-20260707';
        $html = preg_replace('/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is', '', $html) ?? $html;
        $html = preg_replace('/<script id="' . preg_quote($scriptId, '/') . '"[^>]*>.*?<\/script>\s*/is', '', $html) ?? $html;

        $targetScope = 'html body#html-body#html-body#html-body .page-wrapper';
        $catalogScope = 'html body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper';

        /* AWA PDP layout r1 (2026-10-03) — bloco versionado do child theme.
         * Lido do arquivo para não engordar esta cadeia e ficar versionável.
         * Esta área só é renderizada quando há rodapé (PDP/PLP); a home remove
         * blocos <style id="awa-…">, então lá a correção não se aplica. */
        $awaPdpLayoutR1Css = '';
        $awaPdpLayoutGlob = BP . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css/awa-pdp-layout-r*.css';
        foreach (glob($awaPdpLayoutGlob) ?: [] as $awaPdpLayoutFile) {
            if (is_readable($awaPdpLayoutFile)) {
                $awaPdpLayoutR1Css .= (string) file_get_contents($awaPdpLayoutFile);
            }
        }

        $css = '<style id="' . $styleId . '">'
            . $targetScope . ' :is(footer.page-footer,.page-footer){'
            . 'box-sizing:border-box!important;background:var(--awa-bg,Canvas)!important;'
            . 'background-color:var(--awa-bg,Canvas)!important;color:var(--awa-text,CanvasText)!important;'
            /* PIXEL-QA 2026-07-25: shell 1280 centrado — max-width:100% quebrava eixo H/C/F na PDP. */
            . 'height:auto!important;min-height:0!important;max-width:none!important;width:100%!important;'
            . 'margin-inline:0!important;overflow-x:clip!important;padding-block:0!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . $targetScope . ' footer.page-footer .page_footer{'
            . 'box-sizing:border-box!important;max-width:none!important;width:100%!important;'
            . 'margin-inline:0!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . $targetScope . ' :is(footer.page-footer,.page_footer,.page-footer) :is(section.awa-footer-trust-bar,.awa-footer-trust-bar){'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) section.awa-footer-trust-bar,'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-footer-trust-bar{'
            . 'display:block!important;background:var(--awa-primary,#b73337)!important;'
            . 'background-color:var(--awa-primary,#b73337)!important;color:#fff!important}'
            . $targetScope . ' :is(footer.page-footer,.page_footer,.page-footer) '
            . ':is(#footer,.footer-container,.footer.content,.footer-top,.footer-middle,.footer-content,.row,.rowFlexMargin,.velaBlock,.velaContent,.vela-content,.vela-content.velaFooterMenu,.awa-footer-atendimento,.awa-footer-newsletter){'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;color:var(--awa-text,CanvasText)!important;min-height:0!important}'
            /* H12: vence styles-l/themes (background:var(--awa-primary) nas .vela-content) */
            . $targetScope . ' :is(.page_footer,.page-footer) :is(.vela-content,.vela-content.velaFooterMenu,.vela-content.awa-footer-atendimento){'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'color:var(--awa-text,CanvasText)!important;box-shadow:none!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) :is(.velaFooterTitle,h4.velaFooterTitle,[id^=awa-footer-title-],.velaFooterLinks a,.awa-footer-atendimento p,.awa-footer-atendimento__label,.awa-footer-atendimento__phone,.awa-footer-atendimento__email,a){'
            . 'color:var(--awa-text,CanvasText)!important;text-shadow:none!important;-webkit-text-fill-color:currentColor!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-footer-atendimento__store{'
            . 'background:var(--awa-bg,Canvas)!important;background-color:var(--awa-bg,Canvas)!important;color:var(--awa-text,CanvasText)!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-footer-atendimento__store :is(p,a,span,strong){'
            . 'color:var(--awa-text,CanvasText)!important;-webkit-text-fill-color:currentColor!important}'
            . $targetScope . ' :is(footer.page-footer,.page_footer,.page-footer) '
            . ':is(a,p,li,span,strong,h2,h3,h4,.footer-title,.awa-footer-title,.footer.links a,.footer-content a){'
            . 'color:var(--awa-text,CanvasText)!important;text-shadow:none!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .footer-bottom{'
            . 'box-sizing:border-box!important;background:#fff!important;'
            . 'background-color:#fff!important;'
            . 'border:0!important;border-radius:0!important;'
            . 'color:var(--awa-text,CanvasText)!important;margin-inline:0!important;'
            /* PIXEL-QA: filho do shell — sem pad/margin extra (Ft=F). */
            . 'max-width:100%!important;width:100%!important;'
            . 'overflow:visible!important;padding-block:clamp(16px,2vw,24px)!important;padding-inline:0!important;'
            . 'padding-left:0!important;padding-right:0!important;box-shadow:none!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .footer-bottom>.container{'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:1280px!important;'
            . 'width:min(100%,1280px)!important;'
            . 'padding-inline:var(--awa-page-main-pad,clamp(16px,3vw,24px))!important;'
            . 'padding-left:var(--awa-page-main-pad,clamp(16px,3vw,24px))!important;'
            . 'padding-right:var(--awa-page-main-pad,clamp(16px,3vw,24px))!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .footer-bottom .footer-bottom-inner{'
            . 'box-sizing:border-box!important;max-width:100%!important;padding-inline:0!important;width:100%!important}'
            . $targetScope . ' footer.page-footer :is(#footer,.footer-container){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'max-width:100%!important;margin-inline:0!important;box-sizing:border-box!important}'
            . $catalogScope . ' .products-grid .product-thumb{position:relative!important;overflow:hidden!important}'
            . $catalogScope . ' .products-grid .quickview-link{'
            . 'box-sizing:border-box!important;inline-size:44px!important;block-size:44px!important;max-width:44px!important;'
            . 'min-width:44px!important;right:0!important;inset-inline-end:0!important}'
            . $catalogScope . ' .toolbar.toolbar-products .grid-mode-show-type-products,'
            . $catalogScope . ' .products-grid :is(.product-rating,.product-reviews-summary,.rating-summary,.reviews-actions),'
            /* 51.49: hide whole Categoria group when sole option is Arrow cat=89 */
            . $catalogScope . ' .filter-options-item:has(a[href*="cat=89"]):not(:has(.filter-options-content li.item:nth-child(2))){display:none!important}'
            /* 51.49 Bug B — PLP first-thumb must not stay opacity:0 on grey bg */
            . $catalogScope . ' .products-grid .item-product .first-thumb .product-image-photo{opacity:1!important;animation:none!important}'
            . '@media(max-width:767px){'
            . $targetScope . ' :is(.page_footer,.page-footer) .footer-bottom{max-width:100%!important;padding-inline:0!important;padding-block:16px!important}'
            . $catalogScope . ' .toolbar.toolbar-products{display:grid!important;grid-template-columns:minmax(0,1fr)!important;gap:10px!important}'
            . $catalogScope . ' .toolbar.toolbar-products :is(.modes,.toolbar-sorter,.field.limiter,.pages){'
            . 'box-sizing:border-box!important;display:flex!important;flex-wrap:wrap!important;width:100%!important;max-width:100%!important;min-width:0!important}'
            . $catalogScope . ' .toolbar.toolbar-products :is(.sorter-options,select.limiter-options){'
            . 'box-sizing:border-box!important;flex:1 1 160px!important;min-width:0!important;max-width:100%!important}}'
            /* H10B: newsletter compacto — mata LGPD no ::before do .actions (visual-fixes) e reposiciona abaixo. */
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-newsletter-form-container .field.newsletter{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;'
            . 'grid-template-rows:44px auto!important;align-items:start!important;'
            . 'column-gap:8px!important;row-gap:6px!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important;padding:0!important;padding-block:0!important;'
            . 'min-height:44px!important;height:auto!important;'
            . 'border:0!important;background:transparent!important;box-shadow:none!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-newsletter-form-container .field.newsletter :is(.control,.actions){'
            . 'width:auto!important;max-width:100%!important;min-width:0!important;height:44px!important;margin:0!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-newsletter-form-container .field.newsletter .control{grid-column:1!important;grid-row:1!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-newsletter-form-container .field.newsletter .actions{grid-column:2!important;grid-row:1!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-newsletter-form-container .field.newsletter .action.subscribe{'
            . 'width:auto!important;max-width:none!important;white-space:nowrap!important;min-height:44px!important;height:44px!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-newsletter-form-container .newsletter-footer .actions::before,'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-newsletter-form-container .field.newsletter .actions::before,'
            . $targetScope . ' :is(.page_footer,.page-footer) .newsletter-footer .actions::before,'
            . $targetScope . ' :is(.page_footer,.page-footer) .newsletter form .actions::before{'
            . 'content:none!important;display:none!important;margin:0!important;padding:0!important;font-size:0!important;'
            . 'height:0!important;width:0!important;overflow:hidden!important;position:absolute!important}'
            . $targetScope . ' :is(.page_footer,.page-footer) .awa-newsletter-form-container .field.newsletter::after{'
            . 'content:"* Ao cadastrar, você concorda com nossa Política de Privacidade"!important;'
            . 'grid-column:1/-1!important;grid-row:2!important;display:block!important;font-size:12px!important;line-height:1.4!important;'
            . 'color:var(--awa-text-secondary,var(--awa-text))!important;font-style:italic!important;margin:0!important;padding:2px 0 0!important}'
            . $awaPdpLayoutR1Css
            . '</style>';

        /* H10A: setProperty('background',…) expande longhands vazios no style attr
         * e o rAF forçava height fixa (~1481px). Usar color+image:none; height:auto. */
        $script = '<script id="' . $scriptId . '">(function(){'
            . '"use strict";'
            . 'function s(e,p,v){if(e){e.style.setProperty(p,v,"important");}}'
            /* Nunca removeProperty("background") após setar longhands — no Chrome isso zera color/image. */
            . 'function clearBg(e){if(!e||!e.style){return;}'
            . '["background-image","background-position","background-position-x","background-position-y",'
            . '"background-size","background-repeat","background-attachment","background-origin","background-clip"]'
            . '.forEach(function(p){var v=(e.style.getPropertyValue(p)||"").trim();if(v===""){e.style.removeProperty(p);}});}'
            . 'function surface(e,color){if(!e){return;}'
            . 'e.style.removeProperty("background");clearBg(e);'
            . 's(e,"background-color",color);s(e,"background-image","none");}'
            . 'function f(){'
            . 'document.querySelectorAll(".page_footer,footer.page-footer,.page-footer").forEach(function(e){'
            . '["background","background-color","background-image","color","min-height","height","max-height","overflow","overflow-x","overflow-y","padding-block","margin-top","margin-bottom","max-width"]'
            . '.forEach(function(p){e.style.removeProperty(p);});'
            . 's(e,"max-width","none");s(e,"width","100%");s(e,"margin-left","0");s(e,"margin-right","0");s(e,"margin-inline","0");'
            . '});'
            . 'document.querySelectorAll(".page_footer .awa-footer-trust-bar,.page-footer .awa-footer-trust-bar").forEach(function(e){'
            . '["background","background-color","background-image","color","display","padding-block","margin-bottom"]'
            . '.forEach(function(p){e.style.removeProperty(p);});'
            . 'if(!(e.getAttribute("style")||"").trim()){e.removeAttribute("style");}});'
            . 'document.querySelectorAll(".page_footer .awa-footer-section__toggle,.page-footer .awa-footer-section__toggle,.page_footer .velaContent a,.page-footer .velaContent a").forEach(function(e){'
            . 's(e,"color","#333333");s(e,"-webkit-text-fill-color","#333333");});'
            . 'document.querySelectorAll(".page_footer #footer,.page-footer #footer,.page_footer .footer-container,.page-footer .footer-container").forEach(function(e){'
            . 'surface(e,"transparent");s(e,"color","var(--awa-text,CanvasText)");s(e,"min-height","0");});'
            /* H12: colunas .vela-content (hífen) — H10 só limpava .velaContent (camelCase errado) */
            . 'document.querySelectorAll(".page_footer .vela-content,.page-footer .vela-content,.page_footer .velaFooterMenu,.page-footer .awa-footer-atendimento").forEach(function(e){'
            . 'surface(e,"transparent");s(e,"color","var(--awa-text,CanvasText)");s(e,"box-shadow","none");});'
            . 'document.querySelectorAll(".page_footer .velaFooterTitle,.page-footer .velaFooterLinks a,.page-footer .awa-footer-atendimento p,.page-footer .awa-footer-atendimento__label").forEach(function(e){'
            . 's(e,"color","var(--awa-text,CanvasText)");s(e,"-webkit-text-fill-color","var(--awa-text,CanvasText)");});'
            . 'document.querySelectorAll(".page_footer .awa-footer-atendimento__store").forEach(function(e){'
            . 'surface(e,"var(--awa-bg,Canvas)");s(e,"color","var(--awa-text,CanvasText)");});'
            . 'document.querySelectorAll(".page_footer .awa-footer-atendimento__store p,.page_footer .awa-footer-atendimento__store-name,.page_footer .awa-footer-atendimento__store-address").forEach(function(e){'
            . 's(e,"color","var(--awa-text,CanvasText)");s(e,"-webkit-text-fill-color","var(--awa-text,CanvasText)");});'
            . 'document.querySelectorAll(".page_footer .footer-bottom,.page-footer .footer-bottom").forEach(function(e){'
            . 's(e,"box-sizing","border-box");surface(e,"#fff");s(e,"border","0");s(e,"border-radius","0");'
            . 's(e,"color","var(--awa-text,CanvasText)");s(e,"margin-inline","0");s(e,"max-width","100%");'
            /* PIXEL-QA: pad horizontal no shell; nested 0 (evita H/C/F +16). */
            . 's(e,"width","100%");s(e,"padding-inline","0");s(e,"overflow","visible");s(e,"box-shadow","none");});'
            . 'document.querySelectorAll(".page_footer .footer-bottom .footer-bottom-inner,.page-footer .footer-bottom .footer-bottom-inner").forEach(function(e){'
            . 's(e,"box-sizing","border-box");s(e,"max-width","100%");s(e,"padding-inline","0");s(e,"width","100%");});'
            . 'document.querySelectorAll(".products-grid .product-thumb").forEach(function(e){s(e,"position","relative");s(e,"overflow","hidden");});'
            . 'document.querySelectorAll(".products-grid .quickview-link").forEach(function(e){s(e,"box-sizing","border-box");s(e,"inline-size","44px");'
            . 's(e,"block-size","44px");s(e,"max-width","44px");s(e,"min-width","44px");s(e,"right","0");s(e,"inset-inline-end","0");});'
            . '}'
            . 'var ran=false;function runOnce(){if(ran){return;}ran=true;f();}'
            . 'if(document.readyState!=="loading"){runOnce();}else{document.addEventListener("DOMContentLoaded",runOnce,{once:true});}'
            . 'window.addEventListener("load",runOnce,{once:true,passive:true});window.setTimeout(runOnce,1200);'
            . '}());</script>';

        $payload = $css;
        if ($fullAction !== self::HOME_ACTION) {
            $payload .= "\n" . $script;
        }
        $injected = preg_replace('/<\/body>/i', $payload . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Home: FABs com [hidden] devem sumir de verdade.
     *
     * Round 19 (visual-fixes) forçava display:flex !important no back-to-top
     * mobile e vencía o atributo HTML hidden — oval vermelho fantasma na home.
     */

    private function injectShelfViewAllMobileHideR18(string $html, string $fullAction): string
    {
        if ($fullAction !== self::HOME_ACTION) {
            return $html;
        }

        $styleId = 'awa-shelf-viewall-mobile-hide-r18';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        // Strip legacy debug probe if a cached HTML still carries it.
        $html = preg_replace(
            '/<script\s+id="' . preg_quote($styleId . '-dbg', '/') . '"[^>]*>.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::shelfViewAllMobileHideR18Rules()
            . HeaderImpeccableCascadeLockCss::shelfCarouselMobileChromeHideR19Rules()
            . HeaderImpeccableCascadeLockCss::footerAtendimentoStoreFlatR21Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }


    private function injectFooterStoreFlatR21b(string $html): string
    {
        $styleId = 'awa-footer-store-flat-r21b';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::footerAtendimentoStoreFlatR21Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Flatten CNPJ badge soft-card (r26) — dedicated style id so terminal link cannot skip it.
     */
    private function injectFooterCnpjFlatR26(string $html): string
    {
        $styleId = 'awa-footer-cnpj-flat-r26';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::footerCnpjBadgeFlatR26Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Mobile B2B promo contrast (r27) — light bar + dark/brand text for short labels.
     */
    private function injectB2bPromoContrastR27(string $html): string
    {
        $styleId = 'awa-b2b-promo-contrast-r27';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::b2bPromoContrastR27Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Flatten footer seals/pay soft-cards (r28).
     */
    private function injectFooterSealsPayFlatR28(string $html): string
    {
        $styleId = 'awa-footer-seals-pay-flat-r28';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::footerSealsPayFlatR28Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Newsletter form polish (r29) — uniform border, mobile stack, flat icon.
     */
    private function injectFooterNewsletterFormR29(string $html): string
    {
        $styleId = 'awa-footer-newsletter-r29';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::footerNewsletterFormR29Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Mobile FAB stack (r30) — back-to-top above WhatsApp, no overlap.
     */
    private function injectMobileFabStackR30(string $html): string
    {
        $styleId = 'awa-mobile-fab-stack-r30';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::mobileFabStackR30Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Desktop FAB stack (r33) — back-to-top above WhatsApp when both visible.
     */
    private function injectDesktopFabStackR33(string $html): string
    {
        $styleId = 'awa-desktop-fab-stack-r33';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::desktopFabStackR33Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Mobile hamburger flat icon (r34) — remove soft chip chrome.
     */
    private function injectMobileToggleFlatR34(string $html): string
    {
        $styleId = 'awa-ham-flat-r34';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::mobileToggleFlatR34Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Mobile bottom nav equal 4-column grid (r35a).
     */
    private function injectMobileBottomNavEqualR35(string $html): string
    {
        $styleId = 'awa-bottom-nav-equal-r35';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::mobileBottomNavEqualR35Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Home shelf progress radius 8px instead of pill (r35b).
     */
    private function injectOwlProgressRadiusR35(string $html, string $fullAction): string
    {
        if ($fullAction !== self::HOME_ACTION) {
            return $html;
        }

        $styleId = 'awa-owl-progress-radius-r35';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::owlProgressRadiusR35Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Home carousel nav: 8px radius instead of pill/circle (r36).
     */
    private function injectCarouselNavRadiusR36(string $html, string $fullAction): string
    {
        if ($fullAction !== self::HOME_ACTION) {
            return $html;
        }

        $styleId = 'awa-carousel-nav-radius-r36';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::carouselNavRadiusR36Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Home carousel: park autoplay toggle in bottom-right gutter (r38).
     */
    private function injectCarouselToggleGutterR38(string $html, string $fullAction): string
    {
        if ($fullAction !== self::HOME_ACTION) {
            return $html;
        }

        $styleId = 'awa-carousel-toggle-gutter-r71';
        $html = preg_replace(
            '/<style\s+id="awa-carousel-toggle-gutter-r38c?"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        // Strip leftover debug probe from prior r71 deploys
        $html = preg_replace(
            '/<script\s+id="awa-debug-cf2f85-r71"[^>]*>.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::carouselToggleGutterR38Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Desktop B2B promo shell (r40c) — 44px primary full-bleed; mobile keeps r27.
     * Head copy: first paint (barra no início do body; r40c no </body> chegava tarde).
     * Body copy: terminal contra H16B/align-grid carregado no meio do HTML.
     */
    private function injectB2bPromoShellR40(string $html): string
    {
        foreach ([
            'awa-b2b-promo-shell-r40',
            'awa-b2b-promo-shell-r40b',
            'awa-b2b-promo-shell-r40c',
            'awa-b2b-promo-shell-r40c-head',
        ] as $styleId) {
            $html = preg_replace(
                '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
                '',
                $html
            ) ?? $html;
        }

        $rules = HeaderImpeccableCascadeLockCss::b2bPromoShellR40Rules();
        $headCss = '<style id="awa-b2b-promo-shell-r40c-head">' . $rules . '</style>';
        $bodyCss = '<style id="awa-b2b-promo-shell-r40c">' . $rules . '</style>';

        $injected = preg_replace('/<\/head>/i', $headCss . "\n</head>", $html, 1);
        $html = is_string($injected) ? $injected : $html;
        $injected = preg_replace('/<\/body>/i', $bodyCss . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Mobile bottom nav: mute inactive items (r37).
     */
    private function injectMobileBottomNavInactiveR37(string $html): string
    {
        $styleId = 'awa-bottom-nav-inactive-r37';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::mobileBottomNavInactiveR37Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }






    /**
     * Flatten trust-bar icon soft chips (r31).
     */
    private function injectFooterTrustIconFlatR31(string $html): string
    {
        $styleId = 'awa-footer-trust-icon-flat-r31';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::footerTrustIconFlatR31Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }


    /**
     * Home mobile: category carousel dots compact (r24b).
     * Dedicated style id — awa-home-impeccable-terminal-v1 LINK blocks PHP re-inject of r22/r24 rules.
     */
    private function injectCategoryCarouselDotsR24b(string $html, string $fullAction): string
    {
        if ($fullAction !== self::HOME_ACTION) {
            return $html;
        }

        $styleId = 'awa-cat-dots-r24b';
        $html = preg_replace(
            '/<style\s+id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . HeaderImpeccableCascadeLockCss::categoryCarouselDotsCompactR24Rules()
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    private function injectHomeHiddenUiGuardStyles(string $html): string
    {
        $styleId = 'awa-home-hidden-ui-guard-20260717';
        $html = preg_replace('/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is', '', $html) ?? $html;

        $css = '<style id="' . $styleId . '">'
            . 'html body#html-body .page-wrapper :is(#awa-back-to-top,.awa-back-to-top)[hidden],'
            . 'html body#html-body .page-wrapper :is(#awa-back-to-top,.awa-back-to-top)[aria-hidden="true"]:not(.is-visible),'
            . 'html body#html-body .page-wrapper .awa-dark-mode-toggle[hidden],'
            . 'html body#html-body .page-wrapper .awa-dark-mode-toggle[aria-hidden="true"]:not(.is-visible){'
            . 'display:none!important;visibility:hidden!important;opacity:0!important;'
            . 'pointer-events:none!important;width:0!important;height:0!important;'
            . 'min-width:0!important;min-height:0!important;overflow:hidden!important;'
            . 'border:0!important;padding:0!important;margin:0!important;box-shadow:none!important}'
            /* H26: promo B2B oculta com painel/login — zerar shell .header-content fantasma */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.customer-logged-in '
            . '.page-wrapper .awa-site-header #header.header-container,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.customer-logged-in '
            . '.page-wrapper .awa-site-header #header.header-container>.header-content,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:has(.b2b-status-panel) '
            . '.page-wrapper .awa-site-header #header.header-container,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:has(.b2b-status-panel) '
            . '.page-wrapper .awa-site-header #header.header-container>.header-content{'
            . 'display:none!important;visibility:hidden!important;height:0!important;min-height:0!important;'
            . 'max-height:0!important;overflow:hidden!important;margin:0!important;padding:0!important;border:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:not(.onepagecheckout-index-index):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header[data-awa-header-mode=default] .header-wrapper-sticky.is-sticky,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:not(.onepagecheckout-index-index):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header[data-awa-header-mode=default] .header-wrapper-sticky.awa-is-sticky{'
            . 'position:fixed!important;top:0!important;left:0!important;right:0!important;width:100%!important;'
            . 'z-index:1000!important;overflow:visible!important;contain:none!important;will-change:auto!important;transform:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper .awa-site-header .header-wrapper-sticky{contain:none!important;will-change:auto!important}'
            . '</style>';

        $injected = preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Home: compacta o ritmo vertical final sem depender de static deploy.
     *
     * As folhas assíncronas da home ainda carregam locks antigos de min-height e
     * gaps largos para evitar CLS. O estado final fica estável, mas com áreas
     * vazias grandes no mobile. Esta regra terminal mantém a estrutura B2B
     * content-driven e reduz apenas espaçamento artificial.
     */
    private function injectHomeCompactSpacingTerminalStyles(string $html, string $fullAction): string
    {
        if ($fullAction !== self::HOME_ACTION) {
            return $html;
        }

        $styleId = 'awa-home-compact-spacing-terminal-20260707';
        $html = preg_replace('/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is', '', $html) ?? $html;

        $scope = 'html body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper';

        $css = '<style id="' . $styleId . '">'
            . '/* AWA compact home spacing: !important wins legacy async min-height/margin locks that create empty vertical gaps. */'
            . $scope . '{--awa-home-compact-section-pad:clamp(10px,1.4vw,18px);'
            . '--awa-home-compact-tight-gap:clamp(8px,1vw,12px);'
            . '--awa-home-compact-group-gap:clamp(12px,1.6vw,20px)}'
            . $scope . ' .content-top-home :is(.top-home-content,.awa-home-section,'
            . '.awa-grid-section,.awa-grid-section--featured,.awa-home-niche-shelves,'
            . '.awa-home-pricing-notice,.awa-product-promo-banners,.top-home-content--category-carousel)'
            . ':not(.top-home-content--above-fold,.awa-hero-b2b-cta,.awa-carousel-section,.awa-carousel-section--featured){'
            . 'margin-block:0!important;padding-block:var(--awa-home-compact-section-pad)!important}'
            /* r59 CLS: shelf padding-block clamp→0 @~2.5s (container 77→89 / 1248→1232). Final=0. */
            . $scope . ' .content-top-home :is(.awa-carousel-section,.awa-carousel-section--featured,'
            . '.top-home-content.awa-carousel-section){'
            . 'margin-block:0!important;padding:0!important;padding-block:0!important;padding-inline:0!important}'
            . $scope . ' .content-top-home :is(.awa-section-header,.awa-shelf__header,.rokan-product-heading){'
            . 'gap:8px 12px!important;margin-block:0 8px!important}'
            . $scope . ' .top-home-content--above-fold{margin-block-end:0!important;padding-block-end:clamp(4px,.8vw,8px)!important}'
            . $scope . ' .top-home-content--above-fold :is(.banner-slider,.banner-slider2,.owl-stage-outer,'
            . '.owl-stage,.owl-item,.banner_item,.banner_item_bg,.wrapper_slider){'
            . 'box-sizing:border-box!important;max-width:100%!important;overflow:hidden!important}'
            . $scope . ' .top-home-content--above-fold :is(picture,img){'
            . 'display:block!important;inline-size:100%!important;max-inline-size:100%!important;object-fit:cover!important}'
            . $scope . ' .awa-home-pricing-notice{margin-block:var(--awa-home-compact-tight-gap)!important}'
            . $scope . ' .awa-home-pricing-notice__inner{gap:12px!important;padding:clamp(12px,1.6vw,16px)!important}'
            . $scope . ' .awa-product-promo-banners__grid{gap:var(--awa-home-compact-group-gap)!important}'
            . $scope . ' .awa-product-promo-banners__copy{gap:4px!important;padding:10px 12px!important}'
            . $scope . ' .top-home-content--category-carousel :is(.awa-category-carousel__track,.awa-reel,.owl-stage,.owl-wrapper){'
            . 'gap:var(--awa-home-compact-tight-gap)!important}'
            . $scope . ' .top-home-content--category-carousel :is(.awa-category-carousel__item,.awa-category-carousel__link){'
            . 'min-height:104px!important;padding-block:8px!important}'
            . $scope . ' :is(.awa-carousel-section,.awa-shelf--carousel) '
            . ':is(.product-thumb,.product-thumb-link,.product-item-photo,.product-image-container,.product-image-wrapper){'
            . 'background:var(--awa-bg-soft,var(--awa-bg,Canvas))!important;box-sizing:border-box!important;'
            . 'min-width:0!important;overflow:hidden!important}'
            . $scope . ' .awa-carousel-section :is(.product-thumb,.product-thumb-link,.product-image-wrapper){'
            . 'aspect-ratio:1/1!important;display:grid!important;place-items:center!important}'
            . $scope . ' .awa-carousel-section :is(.product-image-photo,.product-thumb img,.product-item-photo img){'
            . 'block-size:100%!important;display:block!important;inline-size:100%!important;'
            . 'max-block-size:100%!important;max-inline-size:100%!important;object-fit:contain!important}'
            . $scope . ' :is(.awa-carousel-card-slot,.item-product,.content-item-product.awa-product-card,.product-item-info){'
            . 'box-sizing:border-box!important;height:100%!important;min-width:0!important;overflow:hidden!important}'
            . $scope . ' :is(.product-name,a.product-item-link,.awa-shelf--carousel .product-name a){'
            . 'overflow-wrap:anywhere!important;text-overflow:clip!important;text-transform:none!important;word-break:normal!important}'
            . $scope . ' :is(.page_footer,.page-footer) .footer-container>.container{'
            . 'padding-block:clamp(16px,2vw,24px)!important}'
            . $scope . ' :is(.page_footer,.page-footer) .awa-footer-newsletter{'
            . 'margin-block:0!important;padding-block:clamp(14px,2vw,22px)!important}'
            . $scope . ' :is(.page_footer,.page-footer) .footer-bottom{'
            . 'height:auto!important;min-height:0!important;padding-block:clamp(14px,2vw,22px)!important}'
            . $scope . ' :is(.page_footer,.page-footer) .footer-bottom-inner{gap:16px!important;height:auto!important;min-height:0!important}'
            . '@media(max-width:767px){'
            . $scope . '{--awa-home-compact-section-pad:10px;--awa-home-compact-tight-gap:8px;'
            . '--awa-home-compact-group-gap:10px}'
            . $scope . ' .content-top-home :is(.top-home-content,.awa-home-section,'
            . '.awa-grid-section,.awa-grid-section--featured,.awa-home-niche-shelves,'
            . '.awa-home-pricing-notice,.awa-product-promo-banners,.top-home-content--category-carousel)'
            . ':not(.top-home-content--above-fold,.awa-hero-b2b-cta,.awa-carousel-section,.awa-carousel-section--featured){padding-block:10px!important}'
            . $scope . ' .content-top-home :is(.awa-carousel-section,.awa-carousel-section--featured,'
            . '.top-home-content.awa-carousel-section){padding:0!important;padding-block:0!important;padding-inline:0!important}'
            . $scope . ' :is(.top-home-content--above-fold,'
            . '.top-home-content--above-fold>.banner-slider.banner-slider2){'
            . 'height:auto!important;min-height:clamp(252px,74vw,288px)!important;max-height:none!important}'
            . $scope . ' .top-home-content--above-fold .wrapper_slider.visible-xs,'
            . $scope . ' .top-home-content--above-fold .wrapper_slider.visible-xs :is(.awa-hero-swiper,'
            . '.swiper-wrapper,.swiper-slide,.banner_item,.banner_item_bg){'
            . 'height:clamp(252px,74vw,288px)!important;min-height:clamp(252px,74vw,288px)!important;'
            . 'max-height:clamp(252px,74vw,288px)!important}'
            . $scope . ' .top-home-content--above-fold .wrapper_slider.visible-xs :is(picture,img,.banner_item_bg){'
            . 'block-size:100%!important;height:100%!important;min-height:0!important;object-fit:cover!important}'
            . $scope . ' .awa-hero-b2b-cta{height:auto!important;margin-block:0!important;'
            . 'min-height:0!important;padding-block:10px!important}'
            . $scope . ' .awa-hero-b2b-cta__inner{height:auto!important;min-height:0!important;'
            . 'padding-block:0!important;row-gap:10px!important}'
            . $scope . ' .awa-hero-benefits{gap:8px!important;height:auto!important;min-height:0!important}'
            . $scope . ' .awa-hero-benefits__item{gap:10px!important;min-height:64px!important;padding:10px 12px!important}'
            . $scope . ' .awa-hero-b2b-cta :is(.awa-hero-benefits__copy,.awa-hero-benefits__title,'
            . '.awa-hero-benefits__text,.awa-hero-benefits__link){'
            . '-webkit-line-clamp:unset!important;display:block!important;min-width:0!important;'
            . 'overflow:visible!important;overflow-wrap:anywhere!important;text-overflow:clip!important;white-space:normal!important}'
            . $scope . ' .awa-hero-b2b-cta__actions{margin-top:8px!important}'
            . $scope . ' .top-home-content--category-carousel :is(.awa-category-carousel__track,.awa-reel){'
            . 'align-items:stretch!important;display:flex!important;overflow-x:auto!important;overflow-y:hidden!important;'
            . 'scroll-snap-type:x proximity!important}'
            . $scope . ' .top-home-content--category-carousel :is(.awa-category-carousel__item,.awa-category-carousel__link){'
            . 'flex:0 0 clamp(128px,12vw,152px)!important;max-width:152px!important;'
            . 'min-height:0!important;min-width:128px!important;padding-block:10px!important;scroll-snap-align:start!important}'
            . $scope . ' .top-home-content--category-carousel .awa-category-carousel__icon{'
            . 'height:88px!important;margin-inline:auto!important;width:88px!important;'
            . 'min-height:88px!important;min-width:88px!important;max-height:88px!important;max-width:88px!important}'
            . $scope . ' .top-home-content--category-carousel .awa-category-carousel__icon :is(picture,img,svg){'
            . 'display:block!important;height:88px!important;width:88px!important;'
            . 'max-height:88px!important;max-width:88px!important;object-fit:contain!important}'
            . $scope . ' .top-home-content--category-carousel '
            . ':is(.awa-category-carousel__label,.awa-category-carousel__title,.awa-category-carousel__name){'
            . '-webkit-line-clamp:2!important;display:-webkit-box!important;overflow:hidden!important;'
            . 'overflow-wrap:anywhere!important;text-overflow:clip!important;white-space:normal!important}'
            . $scope . ' .awa-carousel-section :is(.owl-stage,.owl-wrapper){align-items:stretch!important}'
            . $scope . ' :is(.awa-carousel-card-slot,.item-product,.content-item-product.awa-product-card,.product-item-info){'
            . 'min-height:0!important}'
            . $scope . ' :is(.page_footer,.page-footer) .footer-container>.container{padding-block:14px!important}'
            . $scope . ' :is(.page_footer,.page-footer) .awa-footer-newsletter{padding-block:14px!important}'
            . $scope . ' :is(.page_footer,.page-footer) .footer-bottom{height:auto!important;min-height:0!important;'
            . 'padding-block:14px!important}'
            . $scope . ' :is(.page_footer,.page-footer) .footer-bottom :is(.footer-bottom-inner,.awa-footer-bottom__row,'
            . '.awa-footer-bottom__copyright){height:auto!important;min-height:0!important}'
            . $scope . ' :is(.page_footer,.page-footer) .footer-bottom :is(.footer-bottom-inner,.awa-footer-bottom__row){'
            . 'gap:12px!important;margin:0!important}'
            . $scope . ' :is(.page_footer,.page-footer) .awa-footer-bottom__copyright{'
            . 'margin:0!important;padding:12px!important}'
            . '}'
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Home mobile: neutraliza margin-block legado de 16px entre seções.
     *
     * Evidência runtime (Playwright + CSSOM): awa-visual-fixes-2026-06-29-final.min.css
     * aplica margin-block-start/end:16px em .top-home-content/.awa-home-section no mobile,
     * somando com gap do wrapper e gerando espaçamento visual excessivo.
     */
    private function injectHomeB2bOpsDensityTerminalStyles(string $html, string $fullAction): string
    {
        if (!in_array($fullAction, ['cms_index_index', 'cms_home_index', 'cms_page_view'], true)
            && !str_contains($html, 'cms-index-index')
            && !str_contains($html, 'cms-homepage_ayo_home5')
        ) {
            return $html;
        }

        $styleId = 'awa-home-b2b-ops-density-20260805';
        $html = preg_replace(
            '/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<link[^>]*\bid="' . preg_quote($styleId, '/') . '"[^>]*>\s*/is',
            '',
            $html
        ) ?? $html;

        $path = BP . '/app/design/frontend/AWA_Custom/ayo_home5_child/web/css/' . $styleId . '.css';
        if (!is_readable($path)) {
            return $html;
        }

        $css = (string) file_get_contents($path);
        if ($css === '') {
            return $html;
        }

        // Terminal final: após align-grid / themes deferred, vence whitespace B2B.
        $tag = '<style id="' . $styleId . '" data-awa-terminal="b2b-ops-density-r5">' . $css . '</style>';
        $replaced = preg_replace('/<\/body>/i', $tag . '</body>', $html, 1);
        return is_string($replaced) ? $replaced : $html;
    }

    private function injectHomeGapMarginHotfix(string $html, string $fullAction): string
    {
        if ($fullAction !== self::HOME_ACTION) {
            return $html;
        }

        $styleId = 'awa-home-gap-hotfix-20260705';
        $html = preg_replace('/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is', '', $html) ?? $html;

        $css = '<style id="' . $styleId . '">@layer awa-visual-priority{@media(max-width:767px){'
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.content-top-home :is(.awa-home-section,.top-home-content,.awa-carousel-section--featured,.awa-grid-section--featured,'
            . '.awa-home-niche-shelves,.awa-home-pricing-notice,.awa-product-promo-banners,.top-home-content--category-carousel)'
            . ':not(.top-home-content--above-fold,.awa-hero-b2b-cta){margin-block-start:0!important;margin-block-end:0!important}'
            . '}}</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    private function isB2bAccountFocusPage(string $fullAction, string $html): bool
    {
        if (in_array($fullAction, HeaderImpeccableCascadeLockCss::B2B_ACCOUNT_FOCUS_ACTIONS, true)) {
            return true;
        }

        if (str_starts_with($fullAction, 'b2b_account_')
            && !in_array($fullAction, HeaderImpeccableCascadeLockCss::AUTH_FOCUS_ACTIONS, true)
        ) {
            return true;
        }

        return HeaderImpeccableCascadeLockCss::isB2bAccountOperationalHtml($html);
    }

    /**
     * Painel B2B logado — remove JS/CSS global desnecessário (quickview, maps, css-gate home).
     */
    private function stripB2bAccountPageNoise(string $html): string
    {
        $html = $this->stripAuthPageNoise($html);
        $html = preg_replace('/<script[^>]*awa-css-gate[^>]*><\/script>\s*/i', '', $html) ?? $html;
        $html = preg_replace('/<script id="awa-css-gate-queue"[^>]*>.*?<\/script>\s*/is', '', $html) ?? $html;

        return $html;
    }


    private function normalizeExcessiveHtmlBodySpecificity(string $html): string
    {
        return preg_replace_callback(
            '/<style\b[^>]*>.*?<\/style>/is',
            static function (array $matches): string {
                if (str_contains($matches[0], HeaderImpeccableCascadeLockCss::FOOTER_CRITICAL_STYLE_ID)) {
                    return $matches[0];
                }
                /* PIXEL-QA: manter especificidade alta do gutter lock (vence audit 7×ID). */
                if (str_contains($matches[0], 'awa-pixel-qa-catalog-hdr-gutter-16')) {
                    return $matches[0];
                }
                if (str_contains($matches[0], 'awa-home-mobile-header-grid')) {
                    return $matches[0];
                }
                /* 2026-08-12: lock guest shrink-wrap precisa de 13 IDs para vencer design-system. */
                if (str_contains($matches[0], 'awa-header-account-prompt-lock')) {
                    return $matches[0];
                }
                /* r43b: geometry guard precisa vencer padding-block:8px do terminal inline. */
                if (str_contains($matches[0], 'awa-home-cls-geometry-guard')) {
                    return $matches[0];
                }
                /* 2026-08-19: nav/container full-bleed precisa de 12 IDs unlayered
                   para vencer styles-l/themes 4×#html-body min(1280px, calc(100% - 32px)). */
                if (str_contains($matches[0], 'awa-nav-fullbleed-lock')) {
                    return $matches[0];
                }
                if (str_contains($matches[0], 'awa-nav-fullbleed-lock-body')) {
                    return $matches[0];
                }
                /* r77: 6×#html-body vence column/wrap unlayered do niche-shelves. */
                if (str_contains($matches[0], 'awa-section-header-stack-terminal')) {
                    return $matches[0];
                }
                /* Minicart open-terminal: 4×#html-body precisa vencer locks do header. */
                if (str_contains($matches[0], 'awa-minicart-open-terminal')) {
                    return $matches[0];
                }
                /* ba97c7: foto full-bleed. Sem 14 IDs o deferred-stack (pad 8/token)
                   vence o terminal (evidência CSSOM: 3 IDs vs --awa-home-card-pad). */
                if (str_contains($matches[0], 'awa-home-card-photo-fill')) {
                    return $matches[0];
                }

                return preg_replace('/(?:#html-body){4,}/i', '#html-body#html-body#html-body', $matches[0]) ?? $matches[0];
            },
            $html
        ) ?? $html;
    }

    /**
     * Auth B2B — remove JS/CSS residual do shell global (quickview, ajaxsuite, Page Builder maps, print).
     */
    private function stripAuthPageNoise(string $html): string
    {
        $html = preg_replace('/<link\s[^>]*href=["\'][^"\']*\/css\/print\.css[^"\']*["\'][^>]*\/?>\s*/i', '', $html) ?? $html;
        $html = preg_replace('/<noscript>\s*<link[^>]+awa-cookie-consent-fix[^>]+>\s*<\/noscript>\s*/i', '', $html) ?? $html;
        $html = preg_replace(
            '/<script type="text\/x-magento-init">\s*\{[^}]*"rokanthemes\/ajaxsuite"[^<]*<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script type="text\/x-magento-init">\s*\{[^}]*quickview-product[^<]*<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script type="text\/x-magento-init">\s*\{[^}]*"pageCache"[^<]*<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script[^>]*>\s*require\.config\(\{[^}]*googleMaps[^<]*<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script[^>]*>\s*require\.config\(\{[^}]*wysiwygAdapter[^<]*<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script[^>]*>\s*require\.config\(\{[^}]*Magento_PageBuilder\/js\/utils\/map[^<]*<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<body([^>]*)\sdata-mage-init=\'[^\']*loaderAjax[^\']*\'/i',
            '<body$1',
            $html
        ) ?? $html;

        // r5e: drop storefront cascade that still leaks into empty auth HTML
        // (footer-critical ~82KB, pixel-qa, promo/FAB, themes.min gate, home boot).
        $authStripStyleIds = [
            'awa-footer-critical-stability-v1',
            'awa-b2b-promo-shell-r40c-head',
            'awa-b2b-promo-shell-r40c',
            'awa-pixel-qa-catalog-hdr-gutter-16',
            'awa-pixel-qa-gutter-terminal',
            'awa-visual-bugfix-terminal-20260707',
            'awa-footer-store-flat-r21b',
            'awa-footer-cnpj-flat-r26',
            'awa-b2b-promo-contrast-r27',
            'awa-footer-seals-pay-flat-r28',
            'awa-footer-newsletter-r29',
            'awa-mobile-fab-stack-r30',
            'awa-desktop-fab-stack-r33',
            'awa-ham-flat-r34',
            'awa-bottom-nav-equal-r35',
            'awa-bottom-nav-inactive-r37',
            'awa-footer-trust-icon-flat-r31',
            'awa-header-account-prompt-lock',
            'awa-touch-44-terminal',
            'awa-header-impeccable-cascade-lock-v21',
            'awa-crosspage-shell-lock-r68',
            'awa-header-visual-audit-fixes-20260630',
            'awa-home-cls-critical-opt21',
            'awa-home-critical-cls-shell',
            'awa-home-impeccable-terminal-lock',
            'awa-bugfix-terminal-inline',
        ];
        foreach ($authStripStyleIds as $sid) {
            $html = preg_replace(
                '/<style[^>]*id="' . preg_quote($sid, '/') . '"[^>]*>.*?<\/style>\s*/is',
                '',
                $html
            ) ?? $html;
        }
        $authStripScriptIds = [
            'awa-css-stagger-boot',
            'awa-visual-bugfix-terminal-runtime-20260707',
            'awa-pixel-qa-home-gutter-24',
            'awa-touch-44-terminal-js',
            'awa-footer-desktop-heading-js',
            'awa-footer-categories-desktop-layout-v2',
            'awa-visual-bugfixes',
            'awa-home-bootstrap-defer',
            'awa-require-stub',
        ];
        foreach ($authStripScriptIds as $sid) {
            $html = preg_replace(
                '/<script[^>]*id="' . preg_quote($sid, '/') . '"[^>]*>.*?<\/script>\s*/is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<script[^>]*id="' . preg_quote($sid, '/') . '"[^>]*><\/script>\s*/is',
                '',
                $html
            ) ?? $html;
        }
        $html = preg_replace(
            '/<link\s[^>]*(?:themes\.min|third-party-bundle|visual-bugfix|deferred-stack|awa-footer-terminal-lock|awa-home-bootstrap-defer|awa-require-stub)[^>]*\/?>\s*/i',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script[^>]+src=["\'][^"\']*(?:awa-home-bootstrap-defer|awa-require-stub|awa-visual-bugfixes)[^"\']*["\'][^>]*><\/script>\s*/i',
            '',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Neutralizador terminal — container intermediário do header sem pad (vence themes.min.css tardio).
     */
    private function injectAlignGridHeaderContainerTerminal(string $html): string
    {
        $html = preg_replace('/<style id="awa-align-grid-header-container-terminal"[^>]*>.*?<\/style>/s', '', $html) ?? $html;

        $css = '<style id="awa-align-grid-header-container-terminal">'
            . 'html body#html-body#html-body .page-wrapper .header .header-main>.container,'
            . 'html body#html-body#html-body .page-wrapper .header .header_main>.container,'
            . 'html body#html-body#html-body .page-wrapper .header .header-main .container,'
            . 'html body#html-body#html-body .page-wrapper .header .header_main .container,'
            . 'html body#html-body#html-body .page-wrapper .header-main>.container,'
            . 'html body#html-body#html-body .page-wrapper .header_main>.container,'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header-main>.container,'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header_main>.container'
            . '{padding:0!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Header UI simplify — style terminal após align-grid/header-container (10× #html-body vence home critical).
     */
    private function injectHeaderSimplifyUiTerminalLock(string $html): string
    {
        $styleId = HeaderImpeccableCascadeLockCss::HEADER_SIMPLIFY_UI_STYLE_ID;
        $html = preg_replace('/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>/s', '', $html) ?? $html;

        if (!HeaderImpeccableCascadeLockCss::htmlHasSiteHeader($html)) {
            return $html;
        }

        $tag = HeaderImpeccableCascadeLockCss::headerSimplifyUiTerminalStyleTag();
        $injected = preg_replace('/<\/body>/i', $tag . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Last-mile header repair from the 390/768/1440 audit.
     *
     * Uses a final inline layer because this Magento theme emits several cached
     * header locks after static assets; the fix must win the final rendered HTML.
     */
    private function injectHeaderVisualAuditFixes(
        string $html,
        string $fullAction,
        bool $isAuthFocusPage,
        bool $isB2bAccountFocusPage
    ): string {
        $styleId = 'awa-header-visual-audit-fixes-20260630';
        $html = preg_replace('/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is', '', $html) ?? $html;

        if ($isAuthFocusPage
            || $isB2bAccountFocusPage
            || !HeaderImpeccableCascadeLockCss::htmlHasSiteHeader($html)
        ) {
            return $html;
        }

        // Catálogo: mesma folha cacheável da home (convertHomeHeavyTerminalsToExternal).
        // Evita ~70KB de CSS inline no HTML da PLP/PDP a cada HIT do Varnish.
        if (in_array($fullAction, self::CATALOG_STACK_ACTIONS, true)) {
            return $this->emitDeferredHeaderVisualAuditStylesheet($html, $styleId);
        }

        $shell = 'html body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper .awa-site-header[data-awa-header-mode="default"]';
        $auditLock = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper header.awa-site-header[data-awa-header-mode="default"]';
        $home = 'html body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header[data-awa-header-mode="default"]';
        $plpCategory = 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view) '
            . '.page-wrapper .awa-site-header[data-awa-header-mode="default"]';
        $plpCategoryAudit = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view) '
            . '.page-wrapper header.awa-site-header[data-awa-header-mode="default"]';
        $plp = 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index) '
            . '.page-wrapper ';
        $hide = 'display:none!important;visibility:hidden!important;pointer-events:none!important;'
            . 'width:0!important;height:0!important;min-width:0!important;min-height:0!important;'
            . 'max-width:0!important;max-height:0!important;overflow:hidden!important;'
            . 'position:absolute!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important';

        $css = '<style id="' . $styleId . '">'
            . $shell . ' :is(.header-content,.header-wrapper-sticky,.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container,.header-control.header-nav.awa-nav-bar,.header-control.header-nav.awa-nav-bar>.container,'
            . '.awa-nav-bar__inner){box-sizing:border-box!important}'
            . '@media(min-width:992px){'
            . $shell . ' :is(.header-content,.top-header.awa-b2b-promo-bar,.header-main>.container){'
            . 'width:min(100%,1280px)!important;max-width:1280px!important;margin-left:auto!important;margin-right:auto!important}'
            . $shell . ' :is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar){'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important}'
            . $shell . ' :is(.header-control.header-nav.awa-nav-bar>.container,.header-control.awa-nav-bar>.container){'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important;padding-inline:0!important}'
            . $shell . ' .awa-nav-bar__inner{width:100%!important;max-width:none!important;max-inline-size:none!important;margin-inline:0!important;padding-inline:24px!important}'
            . $shell . ' .header-wrapper-sticky .awa-main-header__inner.wp-header{'
            . 'width:100%!important;max-width:1280px!important;grid-template-columns:clamp(128px,12vw,168px) minmax(0,1fr) minmax(280px,max-content)!important;'
            . 'column-gap:clamp(16px,2vw,28px)!important;padding-inline:clamp(16px,2vw,24px)!important}'
            . $shell . ' .awa-header-search-col{min-width:0!important;width:100%!important;max-width:none!important}'
            . $shell . ' .awa-header-search-col :is(.block-search,.block-content){display:block!important;width:100%!important;max-width:100%!important;min-width:0!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form{'
            . 'align-items:stretch!important;background:var(--awa-bg,var(--awa-white))!important;'
            . 'border:1px solid var(--awa-border-subtle,var(--awa-border))!important;border-radius:var(--awa-radius-sm,6px)!important;'
            . 'box-shadow:none!important;box-sizing:border-box!important;display:grid!important;grid-template-columns:minmax(0,1fr) 48px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;margin:0!important;overflow:hidden!important;padding:0!important;width:100%!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form :is(.field.search,.field.search .control){'
            // FIX 2026-07-06 (Impeccable "positioned child clipped by overflow container"):
            // overflow:hidden nos dois eixos cortava o dropdown de sugestoes
            // (#search_autocomplete), que fica dentro de .control e precisa cair
            // visivelmente abaixo do input. min-width:0 ja resolve o encolhimento do
            // grid; mantemos overflow-x:hidden e liberamos overflow-y para o dropdown.
            . 'display:block!important;grid-column:1!important;height:44px!important;min-height:44px!important;max-height:44px!important;margin:0!important;min-width:0!important;overflow-x:hidden!important;overflow-y:visible!important;padding:0!important;width:100%!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form input#search{'
            . 'background:transparent!important;border:0!important;box-shadow:none!important;box-sizing:border-box!important;display:block!important;'
            . 'height:44px!important;line-height:44px!important;margin:0!important;min-width:0!important;max-width:100%!important;padding:0 14px!important;position:static!important;width:100%!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form .actions{'
            . 'align-items:stretch!important;display:flex!important;grid-column:2!important;height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'margin:0!important;min-width:48px!important;max-width:48px!important;padding:0!important;position:static!important;width:48px!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form :is(button.action.search,.action.search){'
            . 'align-items:center!important;background:transparent!important;background-color:transparent!important;border:0!important;border-radius:0!important;'
            . 'box-shadow:none!important;color:var(--awa-primary,var(--awa-red))!important;display:flex!important;'
            . 'height:44px!important;justify-content:center!important;margin:0!important;min-height:44px!important;max-height:44px!important;'
            . 'min-width:48px!important;max-width:48px!important;opacity:1!important;padding:0!important;position:static!important;transform:none!important;width:48px!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form button.action.search::before,'
            . $shell . ' .awa-header-search-col form#search_mini_form button.action.search::after{content:none!important;display:none!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form button.action.search svg{display:block!important;margin:0!important;position:static!important}'
            /* 2026-08-12: guest shrink-wrap — sem min-width 220/max 252 que inchava a coluna. */
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"]{'
            . 'align-items:center!important;background:transparent!important;border:0!important;box-shadow:none!important;display:inline-flex!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:0!important;overflow:visible!important;padding:0!important;gap:8px!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] :is(.awa-header-account-prompt__text,'
            . '.awa-header-account-prompt__line2,.awa-header-account-prompt__actions){display:inline-flex!important;align-items:center!important;flex-direction:row!important;height:auto!important;min-height:0!important;line-height:1.3!important;max-height:none!important;overflow:visible!important;white-space:nowrap!important}'
            /* .guest empilha __line1 sobre __line2 — column, não herdar row do bloco acima. */
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__guest{'
            . 'display:inline-flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'justify-content:center!important;gap:2px!important;height:44px!important;max-height:44px!important;overflow:visible!important;white-space:nowrap!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__line2{'
            . 'gap:6px!important;padding:0!important;min-height:0!important;height:auto!important;overflow:visible!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__link--login{'
            . 'min-width:0!important;min-height:0!important;padding-inline:0!important;height:auto!important;line-height:1.2!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__link{height:auto!important;line-height:1.3!important;min-height:0!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__separator{height:auto!important;line-height:1.3!important;padding-inline:0!important}'
            . $shell . ' .awa-header-right-col{'
            . 'gap:10px!important;min-width:0!important;max-width:none!important}'
            . $shell . ' .awa-b2b-promo-close{width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;max-width:44px!important;max-height:44px!important}'
            . '}'
            . '@media(min-width:768px) and (max-width:991px){'
            . $shell . ' .header-wrapper-sticky .awa-main-header__inner.wp-header{'
            . 'display:grid!important;grid-template-columns:minmax(88px,112px) minmax(0,1fr) 44px!important;grid-template-areas:"brand search actions"!important;'
            . 'column-gap:16px!important;padding-inline:16px!important;width:100%!important}'
            . $shell . ' .awa-header-right-col{width:44px!important;min-width:44px!important;max-width:44px!important;justify-content:flex-end!important}'
            . $shell . ' .awa-header-search-col :is(.block-search,.block-content){display:block!important;width:100%!important;max-width:100%!important;min-width:0!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form{display:grid!important;grid-template-columns:minmax(0,1fr) 48px!important;height:44px!important;min-height:44px!important;max-height:44px!important;overflow:hidden!important;padding:0!important;width:100%!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form :is(.field.search,.field.search .control){grid-column:1!important;height:44px!important;min-height:44px!important;max-height:44px!important;margin:0!important;min-width:0!important;overflow:hidden!important;padding:0!important;width:100%!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form input#search{height:44px!important;line-height:44px!important;padding:0 14px!important;position:static!important;width:100%!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form .actions{grid-column:2!important;display:flex!important;height:44px!important;margin:0!important;padding:0!important;position:static!important;width:48px!important;min-width:48px!important;max-width:48px!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form :is(button.action.search,.action.search){display:flex!important;height:44px!important;min-height:44px!important;max-height:44px!important;position:static!important;margin:0!important;width:48px!important;min-width:48px!important;max-width:48px!important;padding:0!important;transform:none!important}'
            . $shell . ' .header-control.header-nav.awa-nav-bar{display:block!important;height:48px!important;min-height:48px!important;max-height:48px!important;padding:0!important;overflow:visible!important}'
            . $shell . ' .header-control.header-nav.awa-nav-bar>.container,' . $shell . ' .awa-nav-bar__inner{display:flex!important;align-items:center!important;height:48px!important;min-height:48px!important;max-height:48px!important;overflow:visible!important;width:100%!important;max-width:100%!important;padding-inline:16px!important}'
            . $shell . ' .header-control.header-nav.awa-nav-bar>.container{gap:0!important}'
            . $shell . ' .awa-nav-bar__inner{gap:12px!important}'
            . $shell . ' .awa-header-categories.menu_left_home1{display:flex!important;flex:0 0 196px!important;width:196px!important;max-width:196px!important;height:44px!important;overflow:visible!important}'
            . $shell . ' .awa-header-primary-nav.menu_primary[data-awa-topnav-empty="1"],'
            . $shell . ' .awa-header-primary-nav.menu_primary:has(nav.top-menu:empty){' . $hide . '}'
            . $shell . ' .awa-nav-quick-links{display:flex!important;align-items:center!important;flex:1 1 auto!important;gap:8px!important;min-width:0!important;overflow:hidden!important}'
            . $shell . ' .awa-nav-quick-links__link{display:inline-flex!important;align-items:center!important;height:44px!important;min-height:44px!important;padding-inline:10px!important;white-space:nowrap!important}'
            . '}'
            . '@media(max-width:767px){'
            /* 2 linhas: toggle|logo|cart + busca full-bleed. O stack primary/search/actions
               deixava a busca ~141px e empurrava logo/cart para baixo do casco 96px. */
            . $shell . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-main-header__inner.wp-header{'
            . 'display:grid!important;grid-template-areas:"toggle brand cart" "search search search"!important;'
            . 'grid-template-columns:44px minmax(0,1fr) 44px!important;grid-template-rows:44px 44px!important;'
            . 'padding-block:4px 0!important;padding-inline:16px!important;row-gap:4px!important;column-gap:8px!important;'
            . 'height:96px!important;min-height:96px!important;max-height:96px!important;width:100%!important;'
            . 'max-width:100%!important;box-sizing:border-box!important}'
            . $shell . ':not(.awa-header-condensed) .awa-header-primary-row{display:contents!important}'
            . $shell . ' .awa-header-mobile-toggle{grid-area:toggle!important;justify-self:start!important}'
            . $shell . ' .awa-header-brand-cell{grid-area:brand!important;justify-self:center!important;'
            . 'width:auto!important;min-width:0!important;max-width:148px!important;height:44px!important;'
            . 'min-height:44px!important;max-height:44px!important}'
            . $shell . ' .awa-header-search-col{grid-area:search!important;width:100%!important;max-width:none!important;'
            . 'min-width:0!important;height:44px!important;min-height:44px!important;max-height:44px!important}'
            . $shell . ' .awa-header-right-col{grid-area:cart!important;width:44px!important;min-width:44px!important;'
            . 'max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'justify-content:flex-end!important;overflow:visible!important}'
            . $shell . ' .awa-header-cart-link{grid-area:cart!important;justify-self:end!important}'
            . $shell . ' .awa-b2b-promo-bar__lead-short{display:inline!important;visibility:visible!important;width:auto!important;height:auto!important;overflow:visible!important;clip:auto!important;clip-path:none!important}'
            . $shell . ' .awa-b2b-promo-bar__lead-long,' . $shell . ' .awa-b2b-promo-bar__cta-long{display:none!important}'
            . $shell . ' .awa-b2b-promo-bar__text{display:flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;min-width:0!important;max-width:100%!important}'
            . $shell . ' .awa-b2b-promo-bar__cta-short{display:inline!important}'
            . $shell . ' .awa-b2b-promo-close{width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;top:-4px!important;right:0!important}'
            . '}'
            . $shell . ' .awa-header-minicart:has(.minicart-wrapper .showcart)>.awa-header-cart-fallback,'
            . $home . ' .awa-header-minicart:has(.minicart-wrapper .showcart)>.awa-header-cart-fallback{'
            . $hide . '}'
            // HOME-P0-011/012: o header é a faixa full-width; somente seus filhos formam o rail de 1280px.
            . $auditLock . '{box-sizing:border-box!important;max-width:none!important;width:100%!important;margin-inline:0!important}'
            . $auditLock . ' .awa-header-search-col form#search_mini_form :is(button.action.search,.action.search){opacity:1!important}'
            . $auditLock . ' .awa-header-search-col form#search_mini_form :is(button.action.search,.action.search)::before,'
            . $auditLock . ' .awa-header-search-col form#search_mini_form :is(button.action.search,.action.search)::after{content:none!important;display:none!important}'
            . $auditLock . ' .awa-header-search-col form#search_mini_form :is(button.action.search,.action.search) svg{display:block!important;opacity:1!important;visibility:visible!important;color:inherit!important;stroke:currentColor!important}'
            . '@media(min-width:768px) and (max-width:991px){'
            . $auditLock . ' .header-control.header-nav.awa-nav-bar,'
            . $auditLock . ' .header-control.header-nav.awa-nav-bar>.container,'
            . $auditLock . ' .awa-nav-bar__inner{'
            . 'display:flex!important;visibility:visible!important;opacity:1!important;position:relative!important;clip:auto!important;clip-path:none!important;'
            . 'height:48px!important;min-height:48px!important;max-height:48px!important;overflow:visible!important}'
            . $auditLock . ' .awa-nav-quick-links{'
            . 'align-items:center!important;display:flex!important;flex:1 1 auto!important;flex-direction:row!important;flex-wrap:nowrap!important;'
            . 'gap:8px!important;height:44px!important;justify-content:flex-start!important;min-height:44px!important;'
            . 'max-height:44px!important;max-width:none!important;min-width:0!important;opacity:1!important;overflow:hidden!important;position:static!important;'
            . 'visibility:visible!important;width:auto!important;clip:auto!important;clip-path:none!important}'
            . $auditLock . ' .awa-nav-quick-links__link{'
            . 'align-items:center!important;display:inline-flex!important;height:44px!important;min-height:44px!important;opacity:1!important;'
            . 'padding-inline:10px!important;position:static!important;visibility:visible!important;white-space:nowrap!important;width:auto!important}'
            . $auditLock . ' .awa-nav-quick-links__list{'
            . 'align-items:center!important;display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;gap:8px!important;'
            . 'height:44px!important;list-style:none!important;margin:0!important;min-width:0!important;overflow:hidden!important;padding:0!important;width:100%!important}'
            . $auditLock . ' .awa-nav-quick-links__item{'
            . 'display:inline-flex!important;flex:0 1 auto!important;height:44px!important;margin:0!important;min-width:0!important;padding:0!important;position:static!important}'
            . $auditLock . ' .awa-nav-quick-links__link *{opacity:1!important;visibility:visible!important}'
            . $auditLock . ' .header-wrapper-sticky .awa-header-account-prompt,'
            . $auditLock . ' .header-wrapper-sticky .awa-header-account-prompt *{'
            . $hide . '}'
            . $auditLock . ' .awa-header-categories.menu_left_home1{position:relative!important;overflow:visible!important}'
            . $auditLock . ' .awa-header-categories.menu_left_home1 :is(.awa-nav-categories,.sections.category-dropdown,'
            . '.section-items.nav-sections.category-dropdown-items,#awa-category-navigation,#menu\\.vertical,'
            . '.section-item-content.nav-sections.category-dropdown-item-content,.navigation.verticalmenu.side-verticalmenu){'
            . 'display:contents!important;left:auto!important;right:auto!important;inset:auto!important;margin:0!important;'
            . 'position:static!important;transform:none!important;translate:none!important;width:auto!important;min-width:0!important;'
            . 'max-width:none!important;height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important}'
            . $auditLock . ' .awa-header-categories.menu_left_home1 button.our_categories.title-category-dropdown[data-role="awa-vertical-menu-trigger"]{'
            . 'align-items:center!important;display:inline-flex!important;gap:8px!important;height:44px!important;justify-content:flex-start!important;'
            . 'left:auto!important;margin:0!important;min-height:44px!important;max-height:44px!important;min-width:196px!important;'
            . 'max-width:196px!important;overflow:hidden!important;padding-inline:12px!important;position:static!important;transform:none!important;'
            . 'translate:none!important;visibility:visible!important;width:196px!important}'
            . $auditLock . ' .awa-header-categories.menu_left_home1 button.our_categories.title-category-dropdown[data-role="awa-vertical-menu-trigger"]::before,'
            . $auditLock . ' .awa-header-categories.menu_left_home1 button.our_categories.title-category-dropdown[data-role="awa-vertical-menu-trigger"]::after{content:none!important;display:none!important}'
            . $auditLock . ' .awa-header-categories.menu_left_home1 .awa-vmenu-trigger-icon{display:inline-flex!important;flex:0 0 22px!important;margin:0!important;width:22px!important}'
            . $auditLock . ' .awa-header-categories.menu_left_home1 .awa-vmenu-trigger-text{display:block!important;flex:1 1 auto!important;min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important}'
            . '}'
            . '@media(max-width:767px){'
            . $auditLock . ','
            . $auditLock . ' :is(.header-content,.header-wrapper-sticky,.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container){'
            . 'box-sizing:border-box!important;margin-inline:0!important;max-width:100%!important;width:100%!important}'
            /* Rest 2-row — NÃO aplicar em .awa-header-condensed (BUG-SHELL-MOBILE-CONDENSED-1ROW). */
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky div.awa-main-header__inner.wp-header,'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky div.awa-main-header__inner[data-awa-header-row="brand-search"]{'
            . 'align-items:center!important;box-sizing:border-box!important;display:grid!important;gap:8px 8px!important;'
            . 'grid-template:"toggle brand cart" auto "search search search" auto / 44px minmax(0,1fr) 44px!important;'
            . 'height:auto!important;margin:0!important;max-height:none!important;max-width:100%!important;min-height:0!important;overflow:visible!important;'
            . 'padding:8px 0 10px!important;padding-inline:0!important;width:100%!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-primary-row{display:contents!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-mobile-toggle{grid-area:toggle!important;justify-self:start!important;align-self:center!important;width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-brand-cell{grid-area:brand!important;justify-self:center!important;align-self:center!important;min-width:0!important;max-width:160px!important;width:auto!important;height:44px!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-search-col{grid-area:search!important;display:block!important;justify-self:stretch!important;min-width:0!important;max-width:100%!important;width:100%!important;height:44px!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-right-col{grid-area:cart!important;align-items:center!important;display:flex!important;justify-content:flex-end!important;justify-self:end!important;min-width:44px!important;max-width:44px!important;width:44px!important;height:44px!important;overflow:visible!important}'
            // BUG-B2B-PANEL-MOBILE-2026-07-07 + AUDIT30-2026-07-16:
            // $auditLock (8× #html-body) vence a cascata. grid-template-columns sozinho
            // NÃO sobrescreve o shorthand com 3ª track 44px — o painel estoura p/ esquerda
            // sobre o logo (CDP: logoR=219, trigL=213, overlap=true). Reaplica shorthand.
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky:has(.b2b-status-panel) div.awa-main-header__inner.wp-header,'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky:has(.b2b-status-panel) div.awa-main-header__inner[data-awa-header-row="brand-search"]{'
            . 'grid-template:"toggle brand cart" auto "search search search" auto / 44px minmax(0,1fr) minmax(96px,auto)!important;'
            . 'grid-template-columns:44px minmax(0,1fr) minmax(96px,auto)!important}'
            . $auditLock . ' .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel){'
            . 'min-width:96px!important;max-width:min(140px,38vw)!important;width:auto!important;gap:2px!important;'
            . 'overflow:hidden!important;justify-self:end!important}'
            . $auditLock . ' .header-wrapper-sticky:has(.b2b-status-panel) .awa-header-brand-cell{'
            . 'max-width:min(120px,32vw)!important;overflow:hidden!important;min-width:0!important}'
            . $auditLock . ' .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel) .b2b-status-panel{'
            . 'width:auto!important;max-width:min(88px,24vw)!important;min-width:0!important;overflow:hidden!important}'
            . $auditLock . ' .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel) .b2b-status-trigger{'
            . 'color:var(--awa-text-primary,#0f172a)!important;background:transparent!important;max-width:100%!important}'
            . $auditLock . ' .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel) '
            . ':is(.b2b-status-trigger__line1,.b2b-status-trigger__text){color:var(--awa-text-primary,#0f172a)!important;max-width:6ch!important}'
            // Promo bar: ocultar quando painel B2B está presente (cliente logado)
            . $auditLock . ':has(.b2b-status-panel) #awa-b2b-promo-bar,'
            . $auditLock . ':has(.b2b-status-panel) .awa-b2b-promo-bar{'
            . 'display:none!important;visibility:hidden!important;height:0!important;max-height:0!important;'
            . 'overflow:hidden!important;margin:0!important;padding:0!important;border:0!important}'
            . $auditLock . ' .header-wrapper-sticky .awa-header-cart-link{display:none!important;visibility:hidden!important;pointer-events:none!important}'
            . $auditLock . ' .header-wrapper-sticky .awa-header-right-col .awa-header-account-prompt{display:none!important;visibility:hidden!important}'
            // Ícone 44px só no estado FECHADO — wrapper aberto precisa expandir (drawer).
            . $auditLock . ' .header-wrapper-sticky .awa-header-minicart:not(:has(.minicart-wrapper.active)):not(:has(.minicart-wrapper.show)):not(:has(.minicart-wrapper.is-open)),'
            . $auditLock . ' .header-wrapper-sticky .minicart-wrapper:not(.active):not(.show):not(.is-open),'
            . $auditLock . ' .header-wrapper-sticky .minicart-wrapper:not(.active):not(.show):not(.is-open) .showcart{width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-search-col :is(.block-search,.block-content){display:block!important;width:100%!important;max-width:100%!important;min-width:0!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-search-col form#search_mini_form{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;height:44px!important;min-height:44px!important;'
            . 'max-height:44px!important;overflow:hidden!important;padding:0!important;width:100%!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-search-col form#search_mini_form :is(.field.search,.field.search .control){grid-column:1!important;height:44px!important;margin:0!important;min-width:0!important;overflow:hidden!important;padding:0!important;width:100%!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-search-col form#search_mini_form input#search{height:44px!important;line-height:44px!important;min-width:0!important;padding:0 12px!important;position:static!important;width:100%!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-search-col form#search_mini_form .actions{display:flex!important;grid-column:2!important;height:44px!important;margin:0!important;min-width:44px!important;max-width:44px!important;padding:0!important;position:static!important;width:44px!important}'
            . $auditLock . ':not(.awa-header-condensed) .header-wrapper-sticky .awa-header-search-col form#search_mini_form :is(button.action.search,.action.search){height:44px!important;min-height:44px!important;max-height:44px!important;min-width:44px!important;max-width:44px!important;padding:0!important;position:static!important;transform:none!important;width:44px!important}'
            . $auditLock . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__text{display:flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;height:48px!important;min-height:48px!important;max-height:48px!important;min-width:0!important;max-width:calc(100% - 52px)!important;overflow:visible!important;white-space:nowrap!important}'
            /* P1-HEADER-PROMO 2026-08-17: max-width:96px recortava o copy curto
               "Vendas B2B · CNPJ" (CDP 390: ~"Vendas B2B · C"). Lead ocupa o
               espaço restante; ellipsis só se CTA+close não couberem. */
            . $auditLock . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__lead{display:inline-flex!important;align-items:center!important;flex:1 1 auto!important;min-width:0!important;max-width:none!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;visibility:visible!important}'
            . $auditLock . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__lead-short{display:block!important;height:auto!important;max-width:100%!important;min-width:0!important;opacity:1!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;position:static!important;visibility:visible!important;width:auto!important;clip:auto!important;clip-path:none!important}'
            . $auditLock . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__lead-long,'
            . $auditLock . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__cta-long{display:none!important;visibility:hidden!important}'
            . $auditLock . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__cta{flex:0 0 auto!important;max-width:none!important;overflow:visible!important}'
            . $auditLock . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__cta-short{display:inline!important;opacity:1!important;visibility:visible!important}'
            /* BUG-SHELL-MOBILE-CONDENSED-1ROW 2026-08-05: sticky ≤767 → 1 linha ícone (vence locks 2-row). */
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky{'
            . 'height:auto!important;min-height:56px!important;max-height:56px!important;overflow:visible!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'display:grid!important;grid-template-areas:"toggle brand search cart"!important;'
            . 'grid-template-columns:44px minmax(0,1fr) 44px 44px!important;grid-template-rows:44px!important;'
            . 'gap:0 8px!important;height:56px!important;min-height:56px!important;max-height:56px!important;'
            . 'padding:6px 12px!important;box-sizing:border-box!important;align-items:center!important;overflow:visible!important}'
            /* primary-row vira contents — senão grid-area:primary cria linha fantasma sob o shell 56px. */
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky .awa-header-primary-row{'
            . 'display:contents!important;grid-area:unset!important;grid-template:none!important;'
            . 'height:auto!important;min-height:0!important;max-height:none!important;width:auto!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky :is(.awa-header-search-col,.top-search){'
            . 'grid-area:search!important;display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;'
            . 'min-height:44px!important;max-height:44px!important;overflow:visible!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky .awa-header-search-col form#search_mini_form{'
            . 'display:flex!important;width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;'
            . 'border:0!important;background:transparent!important;overflow:visible!important;grid-template-columns:none!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky .awa-header-search-col :is(.field.search,.field.search .control,.block-search .label,input#search){'
            . 'position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;'
            . 'overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky .awa-header-search-col .actions{'
            . 'display:flex!important;margin:0!important;padding:0!important;width:44px!important;height:44px!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky .awa-header-search-col :is(button.action.search,.action.search){'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;min-width:44px!important;height:44px!important;min-height:44px!important;padding:0!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky .awa-header-mobile-toggle{grid-area:toggle!important;justify-self:start!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky .awa-header-brand-cell{grid-area:brand!important;justify-self:center!important;align-self:center!important}'
            . $auditLock . '.awa-header-condensed .header-wrapper-sticky :is(.awa-header-minicart,.awa-header-right-col){grid-area:cart!important;justify-self:end!important}'
            . '}'
            . '/* FIX 2026-07-07: Impeccable home polish. !important vence locks finais inline/styles-l.css já marcados como important. */'
            . $home . '{display:block!important;width:100%!important;overflow:visible!important}'
            . $home . ' :is(.header-content,#awa-b2b-promo-bar,.top-header.awa-b2b-promo-bar,'
            . '.awa-b2b-promo-bar__inner.awa-b2b-promo-bar__layout){overflow:visible!important;box-sizing:border-box!important}'
            . $home . ' .header-content{height:44px!important;min-height:44px!important;max-height:44px!important;padding-block:0!important}'
            . 'html.awa-b2b-promo-dismissed' . substr($home, 4) . ' .header-content,'
            . 'html.awa-b2b-promo-dismissed' . substr($home, 4) . ' #header.header-container,'
            . $home . ' #header.header-container:has(#awa-b2b-promo-bar[aria-hidden="true"]),'
            . $home . ' #header.header-container:has(#awa-b2b-promo-bar[aria-hidden="true"]) .header-content{'
            . 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;'
            . 'border:0!important;overflow:hidden!important}'
            . $home . ' .header-control.header-nav{padding-inline:0!important;box-sizing:border-box!important}'
            . $home . ' .header-control.header-nav>.container{padding:0!important;box-sizing:border-box!important}'
            /* P2.1 2026-07-28: home logo↔Departamentos — gutter 24 só no inner (bar/container=0). PLP intacto. */
            . '@media(min-width:768px){'
            . $home . ' .header-control.header-nav.awa-nav-bar,'
            . $home . ' .header-control.awa-nav-bar{padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . $home . ' .header-control.header-nav.awa-nav-bar>.container,'
            . $home . ' .header-control.awa-nav-bar>.container{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'gap:0!important;column-gap:0!important;row-gap:0!important}'
            . $home . ' .awa-nav-bar__inner,'
            . $home . ' .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'padding-inline:var(--awa-grid-container-pad,16px)!important;padding-left:var(--awa-grid-container-pad,16px)!important;padding-right:var(--awa-grid-container-pad,16px)!important;'
            . 'box-sizing:border-box!important}'
            . $home . ' .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row],'
            . '.awa-main-header__inner){padding-inline:var(--awa-grid-container-pad,16px)!important;padding-left:var(--awa-grid-container-pad,16px)!important;padding-right:var(--awa-grid-container-pad,16px)!important}}'
            /* P2.1-B 2026-07-28: PLP tablet — alinhar eixo logo↔Departamentos (container=0, inner=24). */
            . '@media(min-width:768px) and (max-width:991px){'
            . $plpCategory . ' .header-control.header-nav.awa-nav-bar>.container,'
            . $plpCategory . ' .header-control.awa-nav-bar>.container{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . $plpCategory . ' .awa-nav-bar__inner,'
            . $plpCategory . ' .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            . $plpCategoryAudit . ' .awa-header-categories.menu_left_home1 button.our_categories.title-category-dropdown[data-role="awa-vertical-menu-trigger"]{'
            . 'padding-inline:16px!important;padding-left:16px!important;padding-right:16px!important}'
            . '}'
            . 'html body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper div.awa-hero-b2b-cta__inner.container{box-sizing:border-box!important}'
                        . '/* FIX 2026-08-02: PLP compact stack (all viewports) — tokens AWA 12px. */'
            . $plp . '{--awa-plp-stack-gap:var(--awa-stack-tight,var(--awa-space-3,12px))}'
            . $plp . ' .nav-breadcrumbs{margin:0!important;margin-block:0!important;padding:0!important;'
            . 'min-height:0!important;height:auto!important}'
            . $plp . ' .nav-breadcrumbs .breadcrumbs{margin:0!important;margin-block:0!important;padding-block:0!important;'
            . 'min-height:0!important;height:auto!important}'
            . $plp . ' #maincontent.page-main{padding-top:0!important}'
            . $plp . ' .category-view-move{height:auto!important;min-height:0!important;padding:0!important;'
            . 'margin:0 0 var(--awa-plp-stack-gap,12px)!important}'
            . $plp . ' .category-view-move .awa-category-hero{height:auto!important;min-height:112px!important;max-height:140px!important;'
            . 'margin:0!important;margin-block:0!important;margin-block-end:0!important;'
            . 'overflow:hidden!important;display:flex!important;align-items:flex-start!important;'
            . 'box-sizing:border-box!important;padding-block:8px var(--awa-space-3,12px)!important}'
            . $plp . ' .category-view-move .awa-category-hero.awa-category-hero--has-image{'
            . 'position:relative!important;min-height:180px!important;height:220px!important;max-height:220px!important;'
            . 'padding-block:16px 20px!important;align-items:flex-end!important}'
            . $plp . ' .category-view-move .awa-category-hero__bg-image,'
            . $plp . ' .category-view-move .awa-category-hero__overlay{height:100%!important;min-height:112px!important;max-height:none!important}'
            . $plp . ' .mst_categorySearch{display:block!important;margin:0 0 var(--awa-plp-stack-gap,12px)!important;'
            . 'min-height:0!important;height:auto!important}'
            . $plp . ' .mst_categorySearch #mst_categorySearch{min-height:44px!important;height:44px!important;'
            . 'max-height:44px!important;line-height:44px!important;width:100%!important;margin:0!important;'
            . 'box-sizing:border-box!important}'
            . $plp . ' .awa-plp-b2b-gate-banner{display:block!important;padding:0!important;border:0!important;'
            . 'background:transparent!important;margin:0 0 var(--awa-plp-stack-gap,12px)!important;'
            . 'min-height:0!important;height:auto!important;box-sizing:border-box!important}'
            . $plp . ' .shop-tab-title{margin:0 0 var(--awa-plp-stack-gap,12px)!important}'

        . '@media(max-width:767px){@layer awa-header-first-paint-lock{'
            /* r57 CLS: pagination bottom:-44 (fora do hero) → relative @~2.5s (y 488→374, CLS ~0.023).
             * Lock overlay final DENTRO do hero 288 desde o first paint. */
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .top-home-content--above-fold :is(.awa-hero__viewport .awa-hero__pagination,'
            . '.wrapper_slider.visible-xs .swiper-pagination.awa-hero__pagination,'
            . '.wrapper_slider.visible-xs .awa-hero__pagination){'
            . 'position:absolute!important;inset:auto 0 12px 0!important;top:auto!important;bottom:12px!important;'
            . 'left:0!important;right:0!important;height:44px!important;'
            . 'min-height:44px!important;max-height:44px!important;display:flex!important;align-items:center!important;'
            . 'justify-content:center!important;gap:8px!important;z-index:2!important;margin:0!important;'
            . 'width:100%!important;max-width:100%!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .top-home-content--above-fold .wrapper_slider.visible-xs :is(.awa-hero-swiper,.swiper){'
            . 'height:176px!important;min-height:176px!important;max-height:176px!important;overflow:hidden!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .top-home-content--above-fold .wrapper_slider.visible-xs .text-banner{'
            . 'top:-24px!important;bottom:auto!important;height:48px!important;max-height:48px!important;'
            . 'min-height:0!important;padding:0 12px!important;display:flex!important;align-items:center!important;'
            . 'justify-content:center!important;overflow:hidden!important;pointer-events:none!important;'
            . 'visibility:hidden!important;opacity:0!important}'
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .top-home-content--above-fold .wrapper_slider.visible-xs .text-banner '
            . ':is(.slide-title,.slide-desc,.button-slider){display:none!important}'
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .top-home-content--above-fold :is(.awa-hero__viewport .awa-hero__pagination,'
            . '.wrapper_slider.visible-xs .swiper-pagination.awa-hero__pagination) .swiper-pagination-bullet{'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'max-width:44px!important;max-height:44px!important;padding:0!important;margin:0!important;'
            . 'box-sizing:border-box!important;background:transparent!important;border:0!important;box-shadow:none!important;opacity:1!important}'
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .top-home-content--above-fold :is(.awa-hero__viewport .awa-hero__pagination,'
            . '.wrapper_slider.visible-xs .swiper-pagination.awa-hero__pagination) .swiper-pagination-bullet::before{'
            . 'width:8px!important;height:8px!important;min-width:8px!important;min-height:8px!important;'
            . 'box-shadow:0 0 0 1px rgba(183,51,55,.22)!important}'
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .top-home-content--above-fold :is(.awa-hero__viewport .awa-hero-pause-btn,'
            . '.wrapper_slider.visible-xs .awa-hero-pause-btn){'
            . 'top:auto!important;bottom:12px!important;left:auto!important;right:16px!important;width:44px!important;'
            . 'height:44px!important;min-width:44px!important;min-height:44px!important;max-width:44px!important;'
            . 'max-height:44px!important;z-index:3!important;opacity:.85!important}'
            . '/* FIX 2026-07-16: PLP mobile hero — 72px cortava H1; busca de categoria visível. */'
            . '/* FIX 2026-08-02: PLP compact stack — tokens AWA 12px. */'
            . $plp . '{--awa-plp-stack-gap:var(--awa-stack-tight,var(--awa-space-3,12px))}'
            . $plp . '.breadcrumbs{margin:0 0 var(--awa-plp-stack-gap,12px)!important;padding-block:0!important;'
            . 'min-height:0!important;height:auto!important}'
            . $plp . '.category-view-move{height:auto!important;min-height:0!important;padding:0!important;'
            . 'margin:0 0 var(--awa-plp-stack-gap,12px)!important}'
            . $plp . '.category-view-move .awa-category-hero{height:auto!important;min-height:112px!important;max-height:140px!important;'
            . 'margin:0!important;margin-block:0!important;margin-block-end:0!important;'
            . 'overflow:hidden!important;display:flex!important;align-items:flex-end!important;'
            . 'box-sizing:border-box!important;padding-block:var(--awa-space-3,12px)!important}'
            . $plp . '.category-view-move .awa-category-hero.awa-category-hero--has-image{'
            . 'position:relative!important;min-height:180px!important;height:220px!important;max-height:220px!important;'
            . 'padding-block:16px 20px!important}'
            . $plp . '.category-view-move .awa-category-hero__bg-image,'
            . $plp . '.category-view-move .awa-category-hero__overlay{height:100%!important;min-height:112px!important;max-height:none!important}'
            . $plp . '.category-view-move .awa-category-hero__content{height:auto!important;min-height:0!important;max-height:none!important;'
            . 'padding:var(--awa-space-2,8px) var(--awa-space-3,12px)!important;display:flex!important;flex-direction:column!important;justify-content:flex-end!important}'
            . $plp . '.category-view-move .awa-category-hero__title{font-size:17px!important;line-height:1.2!important;margin:0!important;'
            . 'overflow:visible!important;white-space:normal!important}'
            . $plp . '.category-view-move .awa-category-hero__count{font-size:12px!important;line-height:1.3!important;margin:4px 0 0!important}'
            . $plp . '.mst_categorySearch{display:block!important;margin:0 0 var(--awa-plp-stack-gap,12px)!important;'
            . 'min-height:0!important;height:auto!important}'
            . $plp . '.mst_categorySearch #mst_categorySearch{min-height:44px!important;height:44px!important;'
            . 'max-height:44px!important;line-height:44px!important;width:100%!important;margin:0!important;'
            . 'box-sizing:border-box!important}'
            . '/* FIX 2026-07-08: PLP mobile toolbar — grid estável (cc2a40: absolute center + sr-only pages vazava). */'
            . $plp . '.shop-tab-title{height:auto!important;min-height:0!important;max-height:none!important;margin:0 0 8px!important;overflow:visible!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products{height:auto!important;min-height:0!important;max-height:none!important;'
            . 'padding:8px 0!important;padding-inline:0!important;margin:0!important;position:relative!important;overflow:visible!important;display:flex!important;flex-direction:column!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products>.center{display:grid!important;grid-template-columns:minmax(0,1fr)!important;'
            . 'grid-template-rows:auto auto!important;gap:8px!important;position:static!important;top:auto!important;left:auto!important;right:auto!important;'
            . 'width:100%!important;height:auto!important;min-height:0!important;max-height:none!important;align-items:stretch!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products .modes{position:static!important;width:100%!important;height:auto!important;'
            . 'min-width:0!important;min-height:44px!important;max-width:100%!important;max-height:none!important;margin:0!important;padding:0!important;'
            . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;gap:8px!important;overflow:visible!important;'
            . 'clip:auto!important;clip-path:none!important;opacity:1!important;visibility:visible!important;pointer-events:auto!important}'
            /* P2-PLP-MODES-LABEL 2026-08-17: luma esconde .modes-label (Hide Sidebar).
               styles-m omitido; filtro #layered-ajax-filter-block ausente no HTML guest →
               JS não seta data-awa-filter-toggle. Clip até o toggle existir. */
            . $plp . '.shop-tab-title .toolbar.toolbar-products .modes .modes-label:not([data-awa-filter-toggle="true"]){'
            . 'position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;'
            . 'overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important;'
            . 'visibility:hidden!important;opacity:0!important;min-width:0!important;min-height:0!important;'
            . 'max-width:1px!important;display:block!important;pointer-events:none!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products .modes .modes-label[data-awa-filter-toggle="true"]{'
            . 'visibility:visible!important;opacity:1!important;position:static!important;clip:auto!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:auto!important;height:auto!important;min-height:44px!important;min-width:44px!important;'
            . 'box-sizing:border-box!important;padding-block:10px!important;max-width:100%!important;white-space:normal!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products .pages{display:none!important;visibility:hidden!important;'
            . 'width:0!important;height:0!important;overflow:hidden!important;pointer-events:none!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products .pages :is(.pages-items,.item,a,strong){display:none!important;visibility:hidden!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products .toolbar-sorter.sorter{display:flex!important;align-items:center!important;'
            . 'justify-content:space-between!important;gap:8px!important;width:100%!important;height:auto!important;min-height:44px!important;'
            . 'max-height:none!important;padding:0!important;margin:0!important;grid-row:2!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products .sorter-label{height:auto!important;min-height:0!important;font-size:11px!important;'
            . 'line-height:1.2!important;margin:0!important;white-space:normal!important;flex:0 0 auto!important}'
            . $plp . '.shop-tab-title .toolbar.toolbar-products .sorter-options{flex:1 1 auto!important;min-width:0!important;height:44px!important;'
            . 'min-height:44px!important;max-height:none!important;margin:0!important;padding:6px 10px!important;box-sizing:border-box!important}'
            . $plp . '.awa-plp-b2b-gate-banner{display:block!important;padding:0!important;border:0!important;'
            . 'background:transparent!important;margin:0 0 var(--awa-plp-stack-gap,12px)!important;'
            . 'min-height:0!important;height:auto!important;box-sizing:border-box!important}'
            . $plp . '.awa-plp-b2b-gate-banner__badge{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;'
            . 'clip-path:inset(50%)!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important}'
            . $plp . '.awa-plp-b2b-gate-banner__actions{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;'
            . 'gap:8px!important;margin:0!important;height:44px!important}'
            . $plp . '.awa-plp-b2b-gate-banner__action{height:44px!important;min-height:44px!important;padding:8px!important;'
            . 'font-size:12px!important;line-height:1.1!important;white-space:nowrap!important}'
            . '}}'
            /* BUG-IMPECCABLE-REFINE-CONDENSED 2026-08-05: awa-commerce-impeccable-refine.css seta
               border:2px!important no form de busca na home page com especificidade (0,11,3),
               vencendo o lock condensado de (0,3,1). Esta regra usa auditLock+condensed
               resultando em (0,13,4) e garante border:0 no estado ícone em todas as viewports. */
            . $auditLock . '.awa-header-condensed .awa-header-search-col :is(form#search_mini_form,form.minisearch),'
            . $auditLock . '.awa-header-condensed .awa-header-search-col :is(form#search_mini_form,form.minisearch):focus-within{'
            . 'border:0!important;background:transparent!important;box-shadow:none!important}'
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * WCAG 2.4.1 — skip-link precisa de alvo focável (#maincontent nas páginas não-home).
     */
    private function injectMainContentSkipTargetTabindex(string $html): string
    {
        if (!str_contains($html, 'id="maincontent"')) {
            return $html;
        }

        if (preg_match('/<main\s[^>]*id="maincontent"[^>]*\btabindex\s*=/i', $html)) {
            return $html;
        }

        $patched = preg_replace(
            '/<main(\s[^>]*id="maincontent"[^>]*)>/i',
            '<main$1 tabindex="-1">',
            $html,
            1
        );

        return is_string($patched) ? $patched : $html;
    }

    /**
     * FOUC do chrome do header — GLOBAL (home/PLP/PDP/B2B storefront).
     * Não amarrar a cms-index-index: Adobe trata header como componente compartilhado.
     * Auth/B2B focus / checkout já ficam de fora pelo caller.
     *
     * Minicart/lupa: sem @layer — regras #html-body unlayered !important venciam o layer
     * awa-header-chrome-fouc-global (evidência PDP 2026-07-30: bg oklch vermelho).
     *
     * A regra vem do mesmo SSOT usado pelo cascade-lock terminal. Aqui emitimos
     * apenas a cópia crítica no head; a antiga cópia idêntica no body era
     * redundante e adicionava dívida de cascata.
     */
    private function injectGlobalHeaderChromeFoucStyles(string $html): string
    {
        if (!str_contains($html, 'awa-site-header')
            && !str_contains($html, 'awa-header-account-prompt')
            && !str_contains($html, 'header-mini-cart')
        ) {
            return $html;
        }

        $html = preg_replace(
            '/<style id="(?:awa-header-chrome-fouc-global-20260730[^"]*|awa-header-chrome-critical-ssot-r1)"'
            . '[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $rules = HeaderImpeccableCascadeLockCss::headerChromeCanonicalRules();
        $headCss = '<style id="awa-header-chrome-critical-ssot-r1">' . $rules . '</style>';

        /* First paint: head (antes de parsear o sticky header no body). */
        if (stripos($html, '</head>') !== false) {
            $withHead = preg_replace('/<\/head>/i', $headCss . "\n</head>", $html, 1);
            $html = is_string($withHead) ? $withHead : $html;
        }

        return $html;
    }

    /**
     * Autocomplete Mirasvit — título+SKU compactos e esconde CMS "Informações".
     * Final-wins sobre align-grid (:is(.title){padding:4px 8px}) e refine (thumb 64px).
     * Cores/radius do design system AWA (DESIGN.md / .impeccable/design.json).
     */
    private function injectSearchAutocompleteCompactStyles(string $html): string
    {
        if (!str_contains($html, 'mst-searchautocomplete')
            && !str_contains($html, 'search_mini_form')
            && !str_contains($html, 'block-search')
        ) {
            return $html;
        }

        $styleId = 'awa-search-autocomplete-compact-20260730';
        $html = preg_replace(
            '/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $root = 'html body#html-body#html-body .page-wrapper .mst-searchautocomplete__autocomplete';
        $item = $root . ' .mst-searchautocomplete__item.magento_catalog_product';

        $css = '<style id="' . $styleId . '">'
            . $root . ' .mst-searchautocomplete__index.magento_cms_page{display:none!important}'
            . $item . '{align-items:center!important;gap:10px!important;min-height:0!important;'
            . 'padding:8px 12px!important}'
            . $item . '>a{'
            . 'flex:0 0 48px!important;display:block!important;width:48px!important;max-width:48px!important;'
            . 'height:48px!important;max-height:48px!important;text-decoration:none!important}'
            . $item . ' .mst-product-image-wrapper{'
            . 'flex:0 0 48px!important;display:grid!important;place-items:center!important;'
            . 'width:48px!important;height:48px!important;max-width:48px!important;max-height:48px!important;'
            . 'margin:0!important;overflow:hidden!important;border:1px solid #e5e5e5!important;'
            . 'border-radius:8px!important;background:#f7f7f7!important}'
            . $item . ' .mst-product-image-wrapper img{'
            . 'display:block!important;width:100%!important;height:100%!important;'
            . 'max-width:48px!important;max-height:48px!important;object-fit:contain!important}'
            . $item . ' .description,' . $item . ' .rating{display:none!important}'
            . $item . ' .title{'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'gap:2px!important;margin:0!important;padding:0!important;min-height:0!important;'
            . 'background:transparent!important;border:0!important;box-shadow:none!important}'
            . $item . ' .title>a{'
            . 'flex:0 1 auto!important;display:-webkit-box!important;-webkit-box-orient:vertical!important;'
            . '-webkit-line-clamp:2!important;max-width:100%!important;margin:0!important;padding:0!important;'
            . 'overflow:hidden!important;color:#333333!important;font-size:13px!important;font-weight:600!important;'
            . 'line-height:1.25!important;text-decoration:none!important}'
            . $item . ' .title .sku{'
            . 'display:inline-flex!important;align-items:center!important;box-sizing:border-box!important;'
            . 'height:18px!important;min-height:0!important;max-height:18px!important;margin:0!important;'
            . 'padding:0 6px!important;border-radius:8px!important;background:#f7f7f7!important;'
            . 'border:1px solid #e5e5e5!important;color:#666666!important;font-size:10px!important;'
            . 'font-weight:600!important;letter-spacing:.02em!important;line-height:18px!important;'
            . 'white-space:nowrap!important}'
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * PLP/PDP/busca: visual-audit como folha cacheável (mesmo bust da home).
     */
    private function emitDeferredHeaderVisualAuditStylesheet(string $html, string $styleId): string
    {
        if (str_contains($html, 'id="' . $styleId . '"')
            || str_contains($html, $styleId . '.min.css')
        ) {
            return $html;
        }

        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $href = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . $styleId
            . '.min.css?v=20260904-premium-ui-w9';
        $cssStagger = 'window.__awaCssQ?__awaCssQ(this):(/themes\\.min|third-party-bundle|visual-bugfix|deferred-stack/.test(this.href||\'\')?(window.__awaCssPend=window.__awaCssPend||[]).push(this):(this.media=\'all\'))';
        $link = '<link rel="stylesheet" href="' . $href . '" id="' . $styleId . '"/>';

        $replaced = preg_replace('/<\/head>/i', $link . "\n</head>", $html, 1);

        return is_string($replaced) ? $replaced : $html;
    }

    /**
     * Lock inline — última camada da cascata; eixo 1280px site-wide (vence page-containers async).
     */
    private function injectSiteShellInlineLock(string $html, string $fullAction): string
    {
        $html = preg_replace('/<style id="awa-align-grid-inline-lock[^"]*"[^>]*>.*?<\/style>/s', '', $html) ?? $html;

        /* Home: ~80–93KB inline duplicava o align-grid já no CSS gate. Mantém só shell
         * first-paint; o restante entra via awa-align-grid-terminal (idle/gate). */
        if ($fullAction === self::HOME_ACTION) {
            $slim = '<style id="awa-align-grid-inline-lock-20260626-phase3d22b">'
                . 'html body#html-body#html-body{--awa-grid-shell-max:min(100%,1280px);--awa-grid-container-pad:clamp(16px,3vw,24px);'
                . '--awa-grid-card-gap:12px;--awa-grid-col-gap:12px;--awa-grid-section-gap:16px}'
                . 'html body#html-body#html-body .page-wrapper .awa-site-header'
                . ' :is(.header-main,.header_main)>.container,html body#html-body#html-body .page-wrapper #header'
                . ' :is(.header-main,.header_main)>.container{padding-inline:0!important;max-width:100%!important;'
                . 'width:100%!important;margin-inline:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body .page-wrapper .awa-site-header'
                . ' :is(.awa-main-header__inner,.awa-header-inner,.header-content){box-sizing:border-box!important;'
                . 'margin-inline:auto!important;max-width:min(100%,1280px)!important;'
                . 'padding-inline:16px!important;width:100%!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
                . ' .page-wrapper :is(.page-main.container,#maincontent.page-main.container,#maincontent.page-main){'
                . 'box-sizing:border-box!important;margin-inline:0!important;'
                . 'max-width:none!important;padding-inline:0!important;width:100%!important}'
                . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)'
                . ' :is(#footer.footer-container,.footer-bottom,.awa-footer-newsletter,section.awa-footer-trust-bar){'
                . 'box-sizing:border-box!important;margin-inline:0!important;max-width:none!important;'
                . 'padding-inline:0!important;width:100%!important}'
                . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)'
                . ' :is(#footer.footer-container>.container,.awa-footer-newsletter>.container,'
                . '.footer-bottom>.container,.awa-footer-trust-bar>.container){'
                . 'box-sizing:border-box!important;margin-inline:auto!important;max-width:min(100%,1280px)!important;'
                . 'padding-inline:16px!important;width:100%!important}'
                . 'html body#html-body#html-body .page-wrapper .content-top-home{'
                . 'box-sizing:border-box!important;max-width:none!important;margin-inline:0!important;width:100%!important}'
                . 'html body#html-body#html-body .page-wrapper .ayo-home5-wrapper{'
                . 'box-sizing:border-box!important;max-width:min(100%,1280px)!important;margin-inline:auto!important;width:100%!important}'
                . '@media(max-width:767px){'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta .awa-hero-benefits__item{'
                . 'min-height:72px!important;padding:12px!important;gap:10px!important;'
                . 'flex-direction:row!important;align-items:flex-start!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta .awa-hero-benefits__icon{'
                . 'flex:0 0 44px!important;width:44px!important;height:44px!important;'
                . 'min-width:44px!important;min-height:44px!important}}'
                . '</style>';
            $injected = preg_replace('/<\/body>/i', $slim . "
</body>", $html, 1);

            return is_string($injected) ? $injected : $html;
        }

                if ($fullAction === 'catalog_product_view') {
            /* PDP: shell first-paint enxuto; regras detalhadas via awa-core-bundle
             * (render-blocking no head) — marcador AWA-MOVED-ALIGN-INLINE-LOCK. */
            $slimPdp = '<style id="awa-align-grid-inline-lock-20260626-phase3d22b">'
                . 'html body#html-body#html-body{--awa-grid-shell-max:min(100%,1280px);--awa-grid-container-pad:clamp(16px,3vw,24px);'
                . '--awa-grid-card-gap:12px;--awa-grid-col-gap:12px;--awa-grid-section-gap:16px}'
                . 'html body#html-body#html-body .page-wrapper .awa-site-header'
                . ' :is(.header-main,.header_main)>.container,html body#html-body#html-body .page-wrapper #header'
                . ' :is(.header-main,.header_main)>.container{padding-inline:0!important;max-width:100%!important;'
                . 'width:100%!important;margin-inline:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body .page-wrapper .awa-site-header'
                . ' :is(.awa-main-header__inner,.awa-header-inner,.header-content){box-sizing:border-box!important;'
                . 'margin-inline:auto!important;max-width:min(100%,1280px)!important;'
                . 'padding-inline:16px!important;width:100%!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index):not(.rokanthemes-onepagecheckout)'
                . ' .page-wrapper :is(.page-main.container,#maincontent.page-main.container,#maincontent#maincontent.page-main.container){'
                . 'box-sizing:border-box!important;margin-inline:auto!important;'
                . 'max-width:100%!important;padding-inline:16px!important;width:min(100%,1280px)!important}'
                . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)'
                . ' :is(#footer.footer-container,#footer .footer-container,.footer-bottom,'
                . '.awa-footer-newsletter,section.awa-footer-trust-bar){'
                . 'box-sizing:border-box!important;margin-inline:0!important;margin-left:0!important;'
                . 'margin-right:0!important;max-width:none!important;padding-inline:0!important;'
                . 'padding-left:0!important;padding-right:0!important;width:100%!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
                . 'footer.page-footer .page_footer>#footer.footer-container{'
                . 'box-sizing:border-box!important;margin-inline:0!important;margin-left:0!important;'
                . 'margin-right:0!important;max-width:none!important;padding-left:0!important;'
                . 'padding-right:0!important;width:100%!important}'
                . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)'
                . ' :is(#footer.footer-container>.container,.awa-footer-newsletter>.container,'
                . '.footer-bottom>.container,.awa-footer-trust-bar>.container){'
                . 'box-sizing:border-box!important;margin-inline:auto!important;'
                . 'max-width:min(100%,1280px)!important;padding-inline:16px!important;width:100%!important}'
                . '</style>';
            $injected = preg_replace('/<\/body>/i', $slimPdp . "\n</body>", $html, 1);
            return is_string($injected) ? $injected : $html;
        }

        if (in_array($fullAction, self::CATALOG_HEADER_ACTIONS, true)) {
            /* PLP/busca: shell first-paint enxuto; detalhes via awa-core-bundle
             * (render-blocking) — marcador AWA-MOVED-ALIGN-INLINE-LOCK-CAT. */
            $slimCat = '<style id="awa-align-grid-inline-lock-20260626-phase3d22b">'
                . 'html body#html-body#html-body{--awa-grid-shell-max:min(100%,1280px);--awa-grid-container-pad:clamp(16px,3vw,24px);'
                . '--awa-grid-card-gap:12px;--awa-grid-col-gap:12px;--awa-grid-section-gap:16px}'
                . 'html body#html-body#html-body .page-wrapper .awa-site-header'
                . ' :is(.header-main,.header_main)>.container,html body#html-body#html-body .page-wrapper #header'
                . ' :is(.header-main,.header_main)>.container{padding-inline:0!important;max-width:100%!important;'
                . 'width:100%!important;margin-inline:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body .page-wrapper .awa-site-header'
                . ' :is(.awa-main-header__inner,.awa-header-inner,.header-content){box-sizing:border-box!important;'
                . 'margin-inline:auto!important;max-width:min(100%,1280px)!important;'
                . 'padding-inline:16px!important;width:100%!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index):not(.rokanthemes-onepagecheckout)'
                . ' .page-wrapper :is(.page-main.container,#maincontent.page-main.container,#maincontent#maincontent.page-main.container){'
                . 'box-sizing:border-box!important;margin-inline:auto!important;'
                . 'max-width:100%!important;padding-inline:16px!important;width:min(100%,1280px)!important}'
                . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)'
                . ' :is(#footer.footer-container,#footer .footer-container,.footer-bottom,'
                . '.awa-footer-newsletter,section.awa-footer-trust-bar){'
                . 'box-sizing:border-box!important;margin-inline:0!important;margin-left:0!important;'
                . 'margin-right:0!important;max-width:none!important;padding-inline:0!important;'
                . 'padding-left:0!important;padding-right:0!important;width:100%!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
                . 'footer.page-footer .page_footer>#footer.footer-container{'
                . 'box-sizing:border-box!important;margin-inline:0!important;margin-left:0!important;'
                . 'margin-right:0!important;max-width:none!important;padding-left:0!important;'
                . 'padding-right:0!important;width:100%!important}'
                . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)'
                . ' :is(#footer.footer-container>.container,.awa-footer-newsletter>.container,'
                . '.footer-bottom>.container,.awa-footer-trust-bar>.container){'
                . 'box-sizing:border-box!important;margin-inline:auto!important;'
                . 'max-width:min(100%,1280px)!important;padding-inline:16px!important;width:100%!important}'
                . '</style>';
            $injected = preg_replace('/<\/body>/i', $slimCat . "\n</body>", $html, 1);
            return is_string($injected) ? $injected : $html;
        }

$css = '<style id="awa-align-grid-inline-lock-20260626-phase3d22b">'
            . 'html body#html-body#html-body{--awa-grid-shell-max:min(100%,1280px);--awa-grid-container-pad:clamp(16px,3vw,24px);'
            . '--awa-grid-card-gap:12px;--awa-grid-col-gap:12px;--awa-grid-section-gap:16px}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header'
            . ' :is(.header-main,.header_main)>.container,html body#html-body#html-body .page-wrapper #header'
            . ' :is(.header-main,.header_main)>.container{padding-inline:0!important;max-width:100%!important;'
            . 'width:100%!important;margin-inline:0!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header'
            . ' :is(.awa-main-header__inner,.awa-header-inner,.header-content){box-sizing:border-box!important;'
            . 'margin-inline:auto!important;max-width:min(100%,1280px)!important;'
            . 'padding-inline:16px!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index):not(.rokanthemes-onepagecheckout)'
            . ' .page-wrapper :is(.page-main.container,#maincontent.page-main.container,#maincontent#maincontent.page-main.container){'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:100%!important;padding-inline:16px!important;width:min(100%,1280px)!important}'
            . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)'
            . ' :is(#footer.footer-container,#footer .footer-container,.footer-bottom,'
            . '.awa-footer-newsletter,section.awa-footer-trust-bar){'
            . 'box-sizing:border-box!important;margin-inline:0!important;margin-left:0!important;'
            . 'margin-right:0!important;max-width:none!important;padding-inline:0!important;'
            . 'padding-left:0!important;padding-right:0!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            . 'footer.page-footer .page_footer>#footer.footer-container{'
            . 'box-sizing:border-box!important;margin-inline:0!important;margin-left:0!important;'
            . 'margin-right:0!important;max-width:none!important;padding-left:0!important;'
            . 'padding-right:0!important;width:100%!important}'
            . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)'
            . ' :is(#footer.footer-container>.container,.awa-footer-newsletter>.container,'
            . '.footer-bottom>.container,.awa-footer-trust-bar>.container){'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:min(100%,1280px)!important;padding-inline:16px!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-bottom{'
            . 'box-sizing:border-box!important;margin-inline:0!important;max-width:none!important;'
            . 'padding-block:clamp(16px,2vw,24px)!important;padding-inline:0!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-bottom>.container{'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:min(100%,1280px)!important;padding-inline:16px!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-bottom .footer-bottom-inner{'
            . 'box-sizing:border-box!important;display:grid!important;gap:10px!important;'
            . 'grid-template-columns:minmax(0,1fr)!important;justify-items:stretch!important;'
            . 'margin:0!important;max-width:none!important;padding-block:12px!important;padding-inline:0!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row{'
            . 'box-sizing:border-box!important;display:grid!important;'
            . 'grid-template-columns:minmax(96px,auto) minmax(0,1fr) minmax(0,1fr)!important;'
            . 'align-items:start!important;justify-items:center!important;gap:10px 24px!important;'
            . 'justify-self:stretch!important;margin:0!important;max-width:none!important;'
            . 'min-width:0!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row>[class*="col-"]:first-child{'
            . 'align-self:center!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row>[class*="col-"]{'
            . 'box-sizing:border-box!important;float:none!important;margin:0!important;max-width:none!important;'
            . 'min-width:0!important;padding-inline:0!important;width:auto!important;'
            . 'justify-self:center!important;text-align:center!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom :is(.awa-footer-pay-sec,.awa-footer-sec){'
            . 'display:flex!important;flex-direction:column!important;align-items:center!important;'
            . 'text-align:center!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom :is(.awa-footer-pay-logos,.awa-footer-sec-seals){'
            . 'display:flex!important;justify-content:center!important;align-items:center!important;'
            . 'flex-wrap:wrap!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .awa-footer-bottom__copyright{'
            . 'box-sizing:border-box!important;justify-self:stretch!important;margin:0!important;'
            . 'max-width:none!important;padding:12px!important;text-align:center!important;width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .awa-footer-cnpj-badge{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:fit-content!important;max-width:100%!important;margin-inline:auto!important;'
            . 'margin-block:0!important;vertical-align:baseline!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .awa-footer-copyright__legal{'
            . 'display:flex!important;flex-direction:column!important;align-items:center!important;'
            . 'justify-content:center!important;gap:6px!important;text-align:center!important;'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important;line-height:1.5!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .awa-footer-copyright__disclaimer{'
            . 'display:block!important;width:100%!important;max-width:none!important;'
            . 'margin-inline:0!important;text-align:center!important;line-height:1.5!important}'
            . '@media(max-width:991px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row{'
            . 'grid-template-columns:minmax(0,1fr)!important;justify-items:center!important;'
            . 'max-width:100%!important;text-align:center!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom :is(.awa-footer-pay-logos,.awa-footer-sec-seals){justify-content:center!important}'
            . '}'
            . 'html body#html-body#html-body.checkout-cart-index .page-wrapper .cart-container{'
            . 'box-sizing:border-box!important;margin-inline:auto!important;max-width:min(100%,1280px)!important;'
            . 'padding-inline:0!important;width:100%!important}'
            . 'html body#html-body#html-body.checkout-cart-index .page-wrapper :is(.page-main.container,#maincontent.page-main.container){'
            . 'box-sizing:border-box!important;margin-inline:auto!important;max-width:min(100%,1280px)!important;'
            . 'padding-inline:16px!important;width:100%!important}'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view){'
            . 'box-sizing:border-box!important;display:block!important;margin:0!important;min-width:0!important;'
            . 'position:static!important;transform:none!important;width:100%!important;max-width:100%!important}'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view) .page-wrapper{'
            . 'box-sizing:border-box!important;display:block!important;min-width:0!important;width:100%!important;max-width:100%!important}'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view)'
            . ' .page-wrapper .page-main.container .breadcrumbs{box-sizing:border-box!important;margin-inline:0!important;'
            . 'max-width:100%!important;padding-inline:0!important;width:100%!important}'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view)'
            . ' .page-wrapper .nav-breadcrumbs{min-height:0!important;max-height:none!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header'
            . ' :is(.awa-utility-bar>.container,.top-header .container){box-sizing:border-box!important;'
            . 'margin-inline:auto!important;max-width:min(100%,1280px)!important;'
            . 'padding-inline:16px!important;width:100%!important}'
            . '@media (max-width:991px){html body#html-body#html-body .page-wrapper .awa-site-header,'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header :is(.header.awa-main-header,.header-main,.header_main,.top-header){'
            . 'width:100%!important;max-width:100%!important;margin-inline:0!important;padding-inline:0!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header :is(.header-main,.header_main)>.container,'
            . 'html body#html-body#html-body .page-wrapper #header :is(.header-main,.header_main)>.container{'
            . 'padding-inline:0!important;max-width:100%!important;width:100%!important;margin-inline:0!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header'
            . ' :is(.awa-main-header__inner,.awa-header-inner,.header-content),html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ':not(.rokanthemes-onepagecheckout) .page-wrapper :is(.page-main.container,#maincontent.page-main.container),'
            . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) #footer .footer-container{'
            . 'max-width:100%!important;padding-inline:16px!important}'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view){'
            . 'box-sizing:border-box!important;display:block!important;margin:0!important;min-width:0!important;'
            . 'position:static!important;transform:none!important;width:100%!important;max-width:100%!important}'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view) .page-wrapper{'
            . 'box-sizing:border-box!important;display:block!important;min-width:0!important;width:100%!important;max-width:100%!important}'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view)'
            . ' .page-wrapper :is(.page_footer,.page-footer) :is(.awa-footer-newsletter,.awa-footer-newsletter>.container,.awa-newsletter-wrapper,.awa-newsletter-form-container){'
            . 'box-sizing:border-box!important;max-width:min(100%,calc(100vw - 32px))!important;min-width:0!important;'
            . 'width:min(100%,calc(100vw - 32px))!important;margin-inline:auto!important}'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view)'
            . ' .page-wrapper :is(.page_footer,.page-footer) #newsletter-validate-detail{'
            . 'box-sizing:border-box!important;max-width:100%!important;min-width:0!important;width:100%!important}}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header'
            . ' #awa-minicart-panel .minicart-wrapper:not(.active):not(.is-open):not(.show) .block-minicart:not(._active){'
            . 'overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header'
            . ' #awa-minicart-panel .minicart-wrapper:is(.active,.is-open,.show) .block-minicart,'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header'
            . ' #awa-minicart-panel .minicart-wrapper .block-minicart._active{overflow:visible!important}';

        if ($fullAction === self::HOME_ACTION) {
            $css .= 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home{padding-inline:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' .ayo-home5-wrapper--template-driven :is('
                . '.top-home-content:not(.top-home-content--above-fold)>.container,'
                . '.top-home-content--category-carousel.awa-home-section>.container),'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' :is(.top-home-content.awa-home-section,.top-home-content.awa-carousel-section)>.container,'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-hero-b2b-cta__inner.container,'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-home-pricing-notice>.container'
                . '{box-sizing:border-box!important;margin-inline:auto!important;max-width:min(100%,1280px)!important;'
                . 'padding-inline:16px!important;width:100%!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .ayo-home5-wrapper--template-driven'
                . ' .top-home-content--category-carousel.awa-home-section{width:100%!important;max-width:100%!important;margin-inline:0!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home :is(.top-home-content.awa-home-section,.top-home-content.awa-carousel-section):not(.top-home-content--above-fold)'
                . '{padding-inline:0!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home :is(.awa-section-header,.awa-shelf__header){padding-inline:0!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-carousel-section :is(.owl-stage,.owl-wrapper,.owl-wrapper-outer){align-items:stretch!important;display:flex!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-carousel-section .product-thumb .product-thumb-link{aspect-ratio:1/1!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-carousel-section :is(.content-item-product,.item-product){max-height:none!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.top-home-content.awa-home-section,.top-home-content.awa-carousel-section):not(.top-home-content--above-fold)'
                . '{padding-block:clamp(12px,1.5vw,20px)!important;margin-block:0!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-section-header,.awa-shelf__header){display:flex!important;flex-wrap:wrap!important;'
                . 'justify-content:space-between!important;align-items:flex-end!important;'
                . 'gap:clamp(8px,1vw,16px)!important;margin-block-end:12px!important}'
                . '@media(max-width:767px){html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
                . ' .page-wrapper :is(.page-main.container,#maincontent.page-main.container){padding-inline:16px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){overflow-x:clip!important}}';
        }

        if (in_array($fullAction, self::CATALOG_HEADER_ACTIONS, true)) {
            $css .= 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' .page-main>.columns.layout.layout-2-col.row{margin-inline:0!important;padding-inline:0!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' .page-main>.columns.layout.layout-2-col>[class*=col-]{float:none!important;width:auto!important;'
                . 'max-width:none!important;padding-inline:0!important;min-width:0!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper .columns{'
                . 'display:grid!important;column-gap:12px!important;row-gap:0!important;'
                . 'grid-template-columns:minmax(220px,260px) minmax(0,1fr)!important;align-items:start!important;'
                . 'margin-inline:0!important;padding-inline:0!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' :is(.products-grid .product-items,.products-grid ul.row.product-grid,'
                . '.products-grid ul.container-products-switch,ul.row.product-grid.container-products-switch){'
                . 'display:grid!important;gap:12px!important;'
                . 'grid-template-columns:repeat(4,minmax(0,1fr))!important;align-items:stretch!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper .products-grid .item-product{'
                . 'display:flex!important;flex-direction:column!important;align-items:stretch!important;'
                . 'height:100%!important;min-width:0!important;width:100%!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' .products-grid .item-product :is(.product-info,.product-item-details){'
                . 'display:flex!important;flex-direction:column!important;flex:1 1 auto!important;'
                . 'justify-content:flex-start!important;align-items:stretch!important;min-height:0!important;width:100%!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' .products-grid .item-product .info-price{'
                . 'margin-block-start:auto!important;margin-top:auto!important;width:100%!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' :is(.page-title-wrapper .page-title,.awa-category-hero__title){'
                . 'font-family:var(--awa-font-heading,"Rubik",system-ui,sans-serif)!important;'
                . 'font-weight:600!important;font-size:clamp(1.25rem,1rem + 1vw,1.5rem)!important;line-height:1.3!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper .toolbar.toolbar-products{'
                . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;'
                . 'justify-content:space-between!important;gap:8px 12px!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper .toolbar.toolbar-products .field.limiter,'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper .toolbar.toolbar-products .limiter .control{'
                . 'box-sizing:border-box!important;display:inline-flex!important;align-items:center!important;'
                . 'flex:0 0 auto!important;min-width:0!important;width:auto!important;max-width:160px!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper .toolbar.toolbar-products select.limiter-options{'
                . 'box-sizing:border-box!important;min-width:72px!important;width:auto!important;max-width:96px!important}'
                . '@media(max-width:1199px){html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' :is(.products-grid .product-items,.products-grid ul.row.product-grid,ul.row.product-grid.container-products-switch)'
                . '{grid-template-columns:repeat(3,minmax(0,1fr))!important}}'
                . '@media(max-width:991px){html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper .columns{'
                . 'grid-template-columns:minmax(0,1fr)!important}'
                . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' :is(.products-grid .product-items,.products-grid ul.row.product-grid,ul.row.product-grid.container-products-switch)'
                . '{grid-template-columns:repeat(2,minmax(0,1fr))!important}}'
                /* ≤479px: manter 2 colunas (anti-regressão §7 — não forçar 1 col no UL real) */
                . '@media(max-width:479px){html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
                . ' :is(.products-grid .product-items,.products-grid ul.row.product-grid,ul.row.product-grid.container-products-switch)'
                . '{grid-template-columns:repeat(2,minmax(0,1fr))!important}}';

            $catalogScope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper';
            $css .= $catalogScope . ' .toolbar.toolbar-products .grid-mode-show-type-products{display:none!important}'
                . $catalogScope . ' .toolbar.toolbar-products{min-width:0!important;overflow:visible!important}'
                . $catalogScope . ' .toolbar.toolbar-products :is(.modes,.toolbar-sorter,.field.limiter,.pages){min-width:0!important;max-width:100%!important}'
                . $catalogScope . ' .toolbar.toolbar-products :is(.sorter-label,.limiter-text,.limiter-label){white-space:normal!important;overflow-wrap:anywhere!important}'
                . $catalogScope . ' .products-grid :is(.product-rating,.product-reviews-summary,.rating-summary,.reviews-actions){'
                . 'display:none!important;margin:0!important;padding:0!important;width:0!important;height:0!important;overflow:hidden!important}'
                . $catalogScope . ' .products-grid :is(.item-product,.product-item,.product-info,.product-item-info,.product-item-details){'
                . 'box-sizing:border-box!important;min-width:0!important;max-width:100%!important;overflow:hidden!important}'
                . $catalogScope . ' .products-grid :is(.product.name,.product-item-name,.product-item-link,.product-sku,.awa-b2b-price-lock){'
                . 'max-width:100%!important;overflow-wrap:anywhere!important;word-break:normal!important}'
                . $catalogScope . ' .products-grid .product-thumb{position:relative!important;overflow:hidden!important}'
                . $catalogScope . ' .products-grid :is(.actions-secondary,.product-extra-link,.quickview-product){'
                . 'box-sizing:border-box!important;max-width:44px!important;min-width:0!important;overflow:hidden!important}'
                . $catalogScope . ' .products-grid .quickview-link{'
                . 'box-sizing:border-box!important;inline-size:40px!important;block-size:40px!important;max-width:40px!important;min-width:0!important}'
                /* 51.49 twin */
                . $catalogScope . ' .filter-options-item:has(a[href*="cat=89"]):not(:has(.filter-options-content li.item:nth-child(2))){display:none!important}'
                . $catalogScope . ' :is(.page_footer,.page-footer){'
                . 'box-sizing:border-box!important;background:var(--awa-bg-soft,var(--awa-bg,Canvas))!important;'
                . 'color:var(--awa-text,CanvasText)!important;height:auto!important;min-height:0!important;'
                . 'margin-inline:0!important;max-width:100%!important;overflow-x:clip!important;padding-block:0!important;width:100%!important}'
                . $catalogScope . ' :is(.page_footer,.page-footer) '
                . ':is(#footer,.footer-container,.footer.content,.footer-top,.footer-middle,.footer-content,.row,.rowFlexMargin,.velaBlock,.velaContent,.awa-footer-newsletter){'
                . 'background:transparent!important;background-color:transparent!important;color:var(--awa-text,CanvasText)!important;min-height:0!important}'
                . $catalogScope . ' :is(.page_footer,.page-footer) :is(h2,h3,h4,.footer-title,.awa-footer-title,a,p,li,span,.footer.links a,.footer-content a){'
                . 'color:var(--awa-text,CanvasText)!important}'
                . $catalogScope . ' :is(.page_footer,.page-footer) .footer-bottom{'
                . 'box-sizing:border-box!important;background:#fff!important;background-color:#fff!important;border:0!important;'
                . 'border-radius:0!important;color:var(--awa-text,CanvasText)!important;margin-inline:0!important;'
                . 'max-width:none!important;width:100%!important;'
                . 'overflow:hidden!important;padding-block:clamp(16px,2vw,24px)!important;padding-inline:0!important;box-shadow:none!important}'
                . $catalogScope . ' :is(.page_footer,.page-footer) .footer-bottom>.container{'
                . 'box-sizing:border-box!important;margin-inline:auto!important;'
                . 'max-width:min(100%,1280px)!important;padding-inline:16px!important;width:100%!important}'
                . $catalogScope . ' :is(.page_footer,.page-footer) .footer-bottom .footer-bottom-inner{'
                . 'box-sizing:border-box!important;max-width:100%!important;padding-inline:0!important;width:100%!important}'
                . '@media(max-width:767px){' . $catalogScope . ' .toolbar.toolbar-products{'
                . 'display:grid!important;grid-template-columns:minmax(0,1fr)!important;align-items:stretch!important;gap:10px!important}'
                . $catalogScope . ' .toolbar.toolbar-products :is(.modes,.toolbar-sorter,.field.limiter,.pages){'
                . 'box-sizing:border-box!important;display:flex!important;flex-wrap:wrap!important;width:100%!important}'
                . $catalogScope . ' .toolbar.toolbar-products :is(.sorter-options,select.limiter-options){'
                . 'box-sizing:border-box!important;flex:1 1 160px!important;min-width:0!important;max-width:100%!important}'
                . $catalogScope . ' :is(.page_footer,.page-footer) .footer-bottom{max-width:none!important;padding-inline:0!important;padding-block:16px!important}'
                . $catalogScope . ' :is(.page_footer,.page-footer) .footer-bottom>.container{max-width:min(100%,1280px)!important;padding-inline:12px!important}}';
        }

        if ($fullAction === 'catalog_product_view') {
            $css .= 'html body#html-body.catalog-product-view .page-wrapper'
                . ' :is(.nav-breadcrumbs,#maincontent.page-main.container,.page-main.container){'
                . 'box-sizing:border-box!important;margin-inline:auto!important;'
                . 'max-width:min(100%,1280px)!important;padding-inline:16px!important;width:100%!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .page-main.container{'
                . 'box-sizing:border-box!important;margin-inline:auto!important;'
                . 'max-width:min(100%,1280px)!important;padding-inline:16px!important;width:100%!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .page-title-wrapper .page-title{'
                . 'font-family:var(--awa-font-heading,"Rubik",system-ui,sans-serif)!important;'
                . 'font-weight:700!important;font-size:clamp(1.375rem,1.1rem + 1.2vw,1.75rem)!important;'
                . 'line-height:1.25!important;margin:0!important;margin-block:0!important;'
                . 'hyphens:none!important;-webkit-hyphens:none!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .page-title-wrapper{'
                . 'margin:0!important;padding-block:8px 12px!important;gap:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main{'
                . 'display:flex!important;flex-direction:column!important;gap:12px!important;min-width:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main'
                . ' :is(.product-info-stock-sku,.product-info-price){margin:0!important;padding:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-info-price{'
                . 'display:grid!important;gap:8px!important;margin-block-end:8px!important}'
                /* H-pdp-info-declutter (e86806): sem moldura em tocart/add-form; gap 0 no shell unificado */
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .box-tocart{'
                . 'border:0!important;border-radius:0!important;'
                . 'background:transparent!important;padding:0!important;margin:0!important;box-shadow:none!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .box-tocart .fieldset{'
                . 'display:grid!important;grid-template-columns:minmax(84px,108px) minmax(0,1fr)!important;'
                . 'gap:8px!important;align-items:end!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .box-tocart'
                . ' :is(.field.qty,.actions){margin:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form{'
                . 'margin:0!important;padding:0!important;border:0!important;'
                . 'border-radius:0!important;background:transparent!important;box-shadow:none!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form'
                . ' form#product_addtocart_form{display:grid!important;grid-template-columns:minmax(84px,108px) minmax(0,1fr)!important;'
                . 'gap:8px!important;align-items:end!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form'
                . ' form#product_addtocart_form>:is(.attr-product,.actions){margin:0!important;min-width:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form'
                . ' form#product_addtocart_form>.actions .action.tocart{width:100%!important;max-width:none!important;'
                . 'display:inline-flex!important;justify-content:center!important}'
                . '@media(max-width:991px){html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view .page-wrapper .main-detail>.row{'
                . 'display:flex!important;flex-direction:row!important;flex-wrap:wrap!important;'
                . 'align-items:stretch!important;gap:0!important;height:auto!important;'
                . 'grid-template-columns:none!important;margin-inline:0!important;padding-inline:0!important}'
                /* P2-PDP-STACK: align-grid H-pdp-unified-shell aplica flex:1 1 0 (2 col)
                 * sem media, depois do stack 767. 11 IDs vencem. ROW+wrap+100% = galeria
                 * acima da info (flex-basis 100% em coluna colapsava altura). */
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view .page-wrapper .main-detail>.row>:is(.col-md-6,.col-sm-6,[class*="col-"]){'
                . 'flex:1 1 100%!important;flex-basis:100%!important;width:100%!important;max-width:100%!important;'
                . 'height:auto!important;min-height:auto!important;float:none!important;overflow:visible!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view .page-wrapper .main-detail>.row>.col-md-6:first-child,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view .page-wrapper .product.media{'
                . 'height:auto!important;overflow:visible!important}}'
                . '@media(min-width:992px){html body#html-body.catalog-product-view .page-wrapper .main-detail>.row{'
                . 'display:flex!important;flex-wrap:nowrap!important;gap:0!important;align-items:stretch!important;'
                . 'grid-template-columns:none!important;margin-inline:0!important;padding-inline:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .main-detail>.row>.col-md-6.col-sm-6.col-xs-12:first-child{'
                . 'flex:1 1 55%!important;max-width:58%!important;min-width:0!important;width:auto!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .main-detail>.row>.col-md-6.col-sm-6.col-xs-12:last-child{'
                . 'flex:1 1 45%!important;max-width:48%!important;min-width:0!important;width:auto!important;'
                . 'border-inline-start:1px solid var(--awa-border,#e2e8f0)!important}}'
                . 'html body#html-body.catalog-product-view .page-wrapper .main-detail>.row>.col-md-6:last-child .product-info-main,'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main.detail-info{'
                . 'width:100%!important;max-width:100%!important;min-width:0!important;box-sizing:border-box!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form form#product_addtocart_form{'
                . 'align-items:end!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form form#product_addtocart_form>.attr-product{'
                . 'align-self:end!important;margin-bottom:0!important;padding-bottom:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form form#product_addtocart_form>.actions{'
                . 'align-self:end!important;margin-bottom:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form form#product_addtocart_form'
                . ' :is(.info-qty input.qty,.actions .action.tocart){height:44px!important;box-sizing:border-box!important}'
                . '@media(max-width:767px){html body#html-body.catalog-product-view .page-wrapper .product-info-main .box-tocart .fieldset{'
                . 'grid-template-columns:minmax(0,1fr)!important;gap:8px!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product-info-main .product-add-form'
                . ' form#product_addtocart_form{grid-template-columns:minmax(0,1fr)!important;gap:8px!important}}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.info.detailed{'
                . 'box-sizing:border-box!important;margin-top:0!important;margin-block-start:0!important;'
                . 'margin-bottom:0!important;margin-block-end:0!important;padding:0!important;'
                . 'border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items{'
                . 'box-sizing:border-box!important;margin-top:0!important;margin-bottom:0!important;'
                . 'border:0!important;background:transparent!important;box-shadow:none!important;padding:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items>.item.title{'
                . 'margin:0!important;min-height:40px!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items>.item.title>.switch{'
                . 'min-height:40px!important;padding:8px 12px!important;border-radius:6px 6px 0 0!important;'
                . 'font-size:13px!important;font-weight:700!important;line-height:1.35!important;letter-spacing:.01em!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items>.item.content{'
                . 'box-sizing:border-box!important;padding:12px!important;border:1px solid var(--awa-border)!important;'
                . 'border-radius:0 8px 8px 8px!important;background:var(--awa-bg)!important;font-size:13px!important;line-height:1.45!important}'
                /* H-pdp-shell7-inline: attr nest + FAQ + spacing + thumb/related (vence CSS apos align-grid) */
                . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
                . ' .page-wrapper .product.data.items>.item.content'
                . ' .additional-attributes-wrapper.table-wrapper{'
                . 'border:0!important;box-shadow:none!important;background:transparent!important;'
                . 'padding:0!important;margin:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
                . ' .page-wrapper .awa-pdp-faq{border:0!important;box-shadow:none!important;'
                . 'background:transparent!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
                . ' .page-wrapper .main-detail{padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
                . ' .page-wrapper .product-info-main{padding:12px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
                . ' .page-wrapper .awa-pdp-related,'
                . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
                . ' .page-wrapper .column.main>.awa-pdp-related{'
                . 'border:0!important;border-block-start:0!important;border-top:0!important;'
                . 'box-shadow:none!important;padding-top:12px!important;padding-block-start:12px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
                . ' .page-wrapper .rx-pdp-crosssell{border:0!important;box-shadow:none!important;'
                . 'padding-block-start:12px!important;padding-top:12px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
                . ' .page-wrapper .product.media :is(.fotorama__thumb,.fotorama__nav__frame--thumb .fotorama__thumb){'
                . 'border:0!important;border-width:0!important;box-shadow:none!important;outline:none!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items'
                . ' :is(table,table.additional-attributes,.additional-attributes){width:100%!important;max-width:100%!important;'
                . 'font-size:13px!important;line-height:1.4!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items'
                . ' :is(th,td){padding:8px 12px!important;border-color:var(--awa-border)!important;vertical-align:top!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items th{'
                . 'font-weight:700!important;color:var(--awa-text)!important;background:color-mix(in srgb,var(--awa-primary) 5%,var(--awa-bg))!important}'
                . '@media(min-width:768px){html body#html-body#html-body#html-body.catalog-product-view .page-wrapper'
                . ' .product.data.items table.additional-attributes :is(th,td),'
                . 'html body#html-body#html-body#html-body.catalog-product-view .page-wrapper'
                . ' .product.data.items .additional-attributes :is(th,td){display:table-cell!important;'
                . 'padding:8px 12px!important;margin:0!important;line-height:1.4!important}}'
                . '@media(max-width:767px){html body#html-body.catalog-product-view .page-wrapper .product.info.detailed{'
                . 'margin-top:0!important;margin-block-start:0!important;padding:0!important;border:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items>.item.content{padding:8px!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.data.items :is(th,td){padding:8px!important}}'
                . '@media(max-width:991px){html body#html-body.catalog-product-view .page-wrapper'
                . ' :is(.gallery-placeholder,.fotorama-item,.fotorama){'
                . 'min-height:min(374px,calc(100vw - 16px))!important;box-sizing:border-box!important}}'
                . '@media(min-width:992px){html body#html-body.catalog-product-view .page-wrapper .product.media .fotorama__stage{'
                . 'min-height:0!important;height:clamp(340px,32vw,400px)!important;max-height:clamp(340px,32vw,400px)!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.media'
                . ' :is(.fotorama__wrap,.fotorama-item,.gallery-placeholder){max-height:clamp(440px,38vw,500px)!important;min-height:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.media .fotorama__nav-wrap{'
                . 'margin-top:6px!important;padding-block:4px!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .product.media .fotorama__nav__frame{height:56px!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .main-detail:has(.b2b-login-to-see-price) .product.media{min-height:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .main-detail:has(.b2b-login-to-see-price) .product.media .fotorama__stage{'
                . 'height:clamp(280px,24vw,340px)!important;max-height:clamp(280px,24vw,340px)!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .main-detail:has(.b2b-login-to-see-price) .product.media'
                . ' :is(.fotorama__wrap,.fotorama-item,.gallery-placeholder){max-height:clamp(380px,30vw,440px)!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .main-detail:has(.b2b-login-to-see-price) .product.media'
                . ' .fotorama__nav__frame{height:52px!important}}'
                . 'html body#html-body.catalog-product-view .page-wrapper .col-main>.product-view{margin-bottom:0!important}'
                // FIX 2026-08-02: PDP stack 12px SSOT (row-gap era 24 + mt 12 = 36).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view{'
                . '--awa-pdp-stack-gap:var(--awa-stack-tight,var(--awa-space-3,12px))}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view '
                . '.page-wrapper :is(.column.main,.col-main){'
                . 'row-gap:var(--awa-pdp-stack-gap,12px)!important;gap:var(--awa-pdp-stack-gap,12px) 0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view '
                . '.page-wrapper :is(.column.main,.col-main) > :is(.product-view,.product.info.detailed,.awa-pdp-faq,.awa-pdp-related,'
                . '.block.related,.block.upsell){'
                . 'margin:0!important;margin-block:0!important;margin-top:0!important;margin-bottom:0!important;'
                . 'margin-block-start:0!important;margin-block-end:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view '
                . '.page-wrapper :is(.awa-pdp-faq,.awa-pdp-related){padding-block:0!important;padding-top:0!important;padding-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view '
                . '.page-wrapper .awa-pdp-related{margin:0!important;margin-block:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view '
                . '.page-wrapper .nav-breadcrumbs{margin:0!important;padding:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view '
                . '.page-wrapper .breadcrumbs{margin:0!important;padding-block:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view '
                . '.page-wrapper #maincontent.page-main{padding-top:var(--awa-pdp-stack-gap,12px)!important;'
                . 'padding-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view '
                . '.page-wrapper .columns{margin-bottom:0!important}'
                // Em Cascade L5, !important em @layer awa-fixes vence unlayered.
                // bugfix: footer.page-footer{margin-top:8px} está em awa-fixes.
                // Storefront unificado (PLP/busca/PDP/CMS/home/cart) — audit PLP/cart footerMt 8 + mainPb 16 = 24.
                . '@layer awa-fixes{'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view,.checkout-cart-index,.cms-page-view,.cms-noroute-index,.cms-index-index,.cms-home,.cms-homepage_ayo_home5,.contact-index-index,.curriculo-index-index,.curriculo-index-status) '
                . '.page-wrapper :is(.page-footer,.page_footer),'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view,.checkout-cart-index,.cms-page-view,.cms-noroute-index,.cms-index-index,.cms-home,.cms-homepage_ayo_home5,.contact-index-index,.curriculo-index-index,.curriculo-index-status) '
                . '.page-wrapper footer.page-footer{margin-top:var(--awa-stack-tight,var(--awa-space-3,12px))!important;'
                . 'margin-block-start:var(--awa-stack-tight,var(--awa-space-3,12px))!important}'
                . '}'
                // PLP shell: main pb 0 + toolbar/shop margins 12.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-category-view '
                . '.page-wrapper #maincontent.page-main{padding-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-category-view '
                . '.page-wrapper .toolbar.toolbar-products{margin-top:0!important;margin-bottom:var(--awa-plp-stack-gap,12px)!important;'
                . 'margin-block:0 var(--awa-plp-stack-gap,12px)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-category-view '
                . '.page-wrapper .shop-tab-title{margin:0 0 var(--awa-plp-stack-gap,12px)!important;'
                . 'margin-block:0 var(--awa-plp-stack-gap,12px)!important}'
                // FIX 2026-08-02: cart shell stack 12px (footerMt8+mainPb16=24; gap16; empty mb16).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.checkout-cart-index{'
                . '--awa-cart-stack-gap:var(--awa-stack-tight,var(--awa-space-3,12px))}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.checkout-cart-index '
                . '.page-wrapper #maincontent.page-main{padding-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.checkout-cart-index '
                . '.page-wrapper .columns{margin-bottom:0!important;margin-block-end:0!important;'
                . 'gap:var(--awa-cart-stack-gap,12px)!important;row-gap:var(--awa-cart-stack-gap,12px)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.checkout-cart-index '
                . '.page-wrapper .column.main{gap:var(--awa-cart-stack-gap,12px)!important;'
                . 'row-gap:var(--awa-cart-stack-gap,12px)!important;padding-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.checkout-cart-index '
                . '.page-wrapper .cart-empty.awa-cart-empty{margin-top:0!important;margin-bottom:0!important;'
                . 'margin-block:0!important;gap:var(--awa-cart-stack-gap,12px)!important;'
                . 'row-gap:var(--awa-cart-stack-gap,12px)!important}'
                // FIX 2026-08-02: CMS shell stack 12px (flex gap 16+h1 mb12=28; columns mb32+pb16+footer8=56).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-page-view,.cms-noroute-index,.contact-index-index,.curriculo-index-index,.curriculo-index-status){'
                . '--awa-cms-stack-gap:var(--awa-stack-tight,var(--awa-space-3,12px))}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-page-view,.cms-noroute-index,.contact-index-index,.curriculo-index-index,.curriculo-index-status) '
                . '.page-wrapper .nav-breadcrumbs{margin:0!important;margin-block:0!important;padding-block:0!important;min-height:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-page-view,.cms-noroute-index,.contact-index-index,.curriculo-index-index,.curriculo-index-status) '
                . '.page-wrapper .breadcrumbs{margin:0!important;margin-block:0!important;padding-block:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-page-view,.cms-noroute-index,.contact-index-index,.curriculo-index-index,.curriculo-index-status) '
                . '.page-wrapper #maincontent.page-main{padding-top:var(--awa-cms-stack-gap,12px)!important;padding-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-page-view,.cms-noroute-index,.contact-index-index,.curriculo-index-index,.curriculo-index-status) '
                . '.page-wrapper .columns{margin-bottom:0!important;margin-block-end:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-page-view,.cms-noroute-index,.contact-index-index,.curriculo-index-index,.curriculo-index-status) '
                . '.page-wrapper .column.main{gap:var(--awa-cms-stack-gap,12px)!important;row-gap:var(--awa-cms-stack-gap,12px)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-page-view,.cms-noroute-index,.contact-index-index,.curriculo-index-index,.curriculo-index-status) '
                . '.page-wrapper .column.main :is(.awa-institutional-h1,h1){margin-block:0!important;margin-top:0!important;margin-bottom:0!important}'
                // FIX 2026-08-02: search stack 12px (Mirasvit tabs mb20 + toolbar 4/8 + mainPb16).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index{'
                . '--awa-search-stack-gap:var(--awa-stack-tight,var(--awa-space-3,12px));'
                . '--awa-plp-stack-gap:var(--awa-search-stack-gap)}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index '
                . '.page-wrapper #maincontent.page-main{padding-top:var(--awa-search-stack-gap,12px)!important;padding-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index '
                . '.page-wrapper .page-title-wrapper{margin-bottom:0!important;margin-block-end:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index '
                . '.page-wrapper .columns.layout-2-col{row-gap:0!important;column-gap:var(--awa-search-stack-gap,12px)!important;'
                . 'gap:0 var(--awa-search-stack-gap,12px)!important;margin-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index '
                . '.page-wrapper .mst-search-in__wrapper{margin:0 0 var(--awa-search-stack-gap,12px)!important;'
                . 'margin-block:0 var(--awa-search-stack-gap,12px)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index '
                . '.page-wrapper .mst-search__result-tabs{margin:0 0 var(--awa-search-stack-gap,12px)!important;'
                . 'margin-block:0 var(--awa-search-stack-gap,12px)!important;padding-block-end:0!important;padding-bottom:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index '
                . '.page-wrapper .search.results{margin-bottom:0!important;margin-block-end:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index '
                . '.page-wrapper .toolbar.toolbar-products{margin-top:0!important;'
                . 'margin-bottom:var(--awa-search-stack-gap,12px)!important;'
                . 'margin-block:0 var(--awa-search-stack-gap,12px)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body.catalogsearch-result-index '
                . '.page-wrapper .shop-tab-title{margin:0 0 var(--awa-search-stack-gap,12px)!important;'
                . 'margin-block:0 var(--awa-search-stack-gap,12px)!important}'
                // FIX 2026-07-06: a margem negativa de -140px sobrepunha o buy-box
                // (QTD + Adicionar ao Carrinho) por 116px em produtos B2B — o gap que
                // ela fechava (~301px) encolheu para ~24px depois que caixa de
                // compatibilidade/prova social/aviso de preço passaram a ocupar a
                // coluna do buy-box. Confirmado via Playwright (overlap de 116px
                // idêntico em 3/3 produtos testados). Regra removida.
                . 'html body#html-body.catalog-product-view .page-wrapper .awa-pdp-related{'
                . 'margin-top:0!important;margin-bottom:0!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .awa-pdp-related.awa-shelf--carousel .awa-carousel__viewport{'
                . 'display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;overflow-x:auto!important;'
                . 'width:100%!important;gap:0!important;padding-block:8px!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .awa-pdp-related.awa-shelf--carousel .awa-carousel__track{'
                . 'display:flex!important;flex-direction:row!important;gap:12px!important;width:max-content!important;min-width:100%!important}'
                . 'html body#html-body.catalog-product-view .page-wrapper .awa-pdp-related>.awa-owl-nav,'
                . 'html body#html-body.catalog-product-view .page-wrapper .awa-pdp-related>.awa-owl-progress{'
                . 'display:none!important}'
                . '@media(pointer:coarse){html body#html-body.catalog-product-view .page-wrapper .product-info-main'
                . ' :is(.action.primary,#product-addtocart-button){min-height:44px!important;min-width:44px!important;'
                . 'display:inline-flex!important;align-items:center!important;justify-content:center!important}}';
        }

        if ($fullAction === self::CART_ACTION) {
            $css .= 'html body#html-body.checkout-cart-index .page-wrapper .page-title-wrapper .page-title{'
                . 'font-family:var(--awa-font-heading,"Rubik",system-ui,sans-serif)!important;'
                . 'font-weight:600!important;font-size:clamp(1.25rem,1rem + 1vw,1.5rem)!important;line-height:1.3!important}'
                . '@media(min-width:768px){html body#html-body.checkout-cart-index .page-wrapper .cart-container{'
                . 'display:grid!important;grid-template-columns:minmax(0,1fr) minmax(300px,360px)!important;'
                . 'gap:clamp(20px,2.5vw,28px)!important;align-items:start!important}}'
                . '@media(max-width:767px){html body#html-body.checkout-cart-index .page-wrapper .cart-container{'
                . 'display:flex!important;flex-direction:column!important;gap:clamp(16px,4vw,24px)!important}'
                . 'html body#html-body.checkout-cart-index .page-wrapper .cart-container .cart-summary{order:-1!important}}'
                . 'html body#html-body#html-body.checkout-cart-index .page-wrapper .awa-site-header .awa-header-search-col '
                . 'form#search_mini_form{display:flex!important;align-items:stretch!important;flex-wrap:nowrap!important}'
                . 'html body#html-body#html-body.checkout-cart-index .page-wrapper .awa-site-header .awa-header-search-col '
                . 'form#search_mini_form .field.search{flex:1 1 auto!important;min-width:0!important;width:auto!important}'
                . 'html body#html-body#html-body.checkout-cart-index .page-wrapper '
                . ':is(.awa-cart-empty__trust,.awa-cart-empty__category-chips){'
                . 'box-sizing:border-box!important;display:flex!important;flex-wrap:wrap!important;max-width:100%!important;min-width:0!important;'
                . 'overflow:visible!important;white-space:normal!important;overflow-wrap:anywhere!important}'
                . 'html body#html-body#html-body.checkout-cart-index .page-wrapper '
                . ':is(.awa-cart-empty__trust,.awa-cart-empty__category-chips)>*{min-width:0!important;max-width:100%!important}';
        }

        // §APF-T — busca header mobile compacta (polish 2026-06-12, última camada inline)
        $css .= 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            . '.awa-header-search-col :is(form#search_mini_form,form.minisearch){display:flex!important;align-items:stretch!important;'
            . 'height:36px!important;min-height:36px!important;max-height:36px!important;overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            . '.awa-header-search-col form#search_mini_form .field.search{display:flex!important;flex:1 1 auto!important;'
            . 'min-width:0!important;height:36px!important;min-height:36px!important;max-height:36px!important;margin:0!important;padding:0!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            . '.awa-header-search-col form#search_mini_form .field.search>label.label{border:0!important;'
            . 'clip:rect(0,0,0,0)!important;height:1px!important;margin:-1px!important;overflow:hidden!important;'
            . 'padding:0!important;position:absolute!important;width:1px!important;white-space:nowrap!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            . '.awa-header-search-col form#search_mini_form .field.search .control{display:flex!important;flex:1 1 auto!important;'
            . 'min-width:0!important;width:auto!important;height:36px!important;min-height:36px!important;max-height:36px!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            . '.awa-header-search-col form#search_mini_form input#search{position:static!important;display:block!important;'
            . 'width:100%!important;min-width:0!important;height:36px!important;min-height:36px!important;'
            . 'max-height:36px!important;margin:0!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            . '.awa-header-search-col form#search_mini_form .actions{display:flex!important;align-items:stretch!important;'
            . 'align-self:stretch!important;flex:0 0 48px!important;width:48px!important;min-width:48px!important;'
            . 'max-width:48px!important;height:36px!important;min-height:36px!important;max-height:36px!important;'
            . 'margin:0!important;padding:0!important;position:static!important;inset:auto!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            . '.awa-header-search-col form#search_mini_form :is(.action.search,button.action.search){display:inline-flex!important;'
            . 'align-items:center!important;justify-content:center!important;flex:0 0 48px!important;'
            . 'width:48px!important;min-width:48px!important;max-width:48px!important;'
            . 'height:36px!important;min-height:36px!important;max-height:36px!important;align-self:stretch!important;'
            . 'position:static!important;inset:auto!important;margin:0!important;padding:0!important;box-sizing:border-box!important}'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-b2b-promo-bar{height:32px!important;'
            . 'min-height:32px!important;max-height:32px!important;overflow:visible!important;background:var(--awa-primary)!important;'
            . 'color:var(--awa-on-primary,white)!important;width:100vw!important;max-width:100vw!important;'
            . 'margin-inline:calc(50% - 50vw)!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-b2b-promo-bar__inner{height:32px!important;'
            . 'min-height:32px!important;max-height:32px!important;display:flex!important;align-items:center!important;'
            . 'justify-content:center!important;position:relative!important;padding-inline:48px!important;overflow:visible!important;'
            . 'background:var(--awa-primary)!important;color:var(--awa-on-primary,white)!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-b2b-promo-bar '
            . ':is(.awa-b2b-promo-bar__lead,.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__lead-short,'
            . '.awa-b2b-promo-bar__separator,.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__cta-long){display:none!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-b2b-promo-bar__cta-short{display:block!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-b2b-promo-bar__cta{display:inline-flex!important;'
            . 'align-items:center!important;justify-content:center!important;min-height:44px!important;height:44px!important;'
            . 'padding:8px 10px!important;margin-block:-8px!important;line-height:1.2!important;white-space:nowrap!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-b2b-promo-close{position:absolute!important;'
            . 'right:6px!important;top:-6px!important;width:44px!important;height:44px!important;min-width:44px!important;'
            . 'min-height:44px!important;display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'border:0!important;background:transparent!important;color:var(--awa-on-primary,white)!important;box-shadow:none!important}'
            . '}';
            $css .= '@media(max-width:991px){html body#html-body#html-body#html-body#html-body .page-wrapper '
                . '.awa-site-header .awa-b2b-promo-close{'
                . 'box-sizing:border-box!important;width:44px!important;height:44px!important;min-width:44px!important;'
                . 'min-height:44px!important;max-width:44px!important;max-height:44px!important;display:flex!important;'
                . 'align-items:center!important;justify-content:center!important;padding:0!important;line-height:1!important}}';

            $css .= 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-b2b-promo-bar :is(strong.awa-b2b-promo-bar__cta-long,.awa-b2b-promo-bar__cta-long){'
                . 'color:var(--awa-on-primary,var(--awa-text-inverse,CanvasText))!important;text-shadow:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(form#search_mini_form,#search_mini_form,.awa-site-header form#search_mini_form) {'
                . 'overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '#search_mini_form :is(.field.search,.control,.search-autocomplete,.mst-searchautocomplete__autocomplete){'
                . 'overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(.header-control.header-nav,.header-control.header-nav.awa-nav-bar){'
                . 'box-sizing:border-box!important;padding-block:4px!important;padding-top:4px!important;padding-bottom:4px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(.header-control.header-nav,.header-control.header-nav.awa-nav-bar)>.container{'
                . 'box-sizing:border-box!important;padding-block:4px!important;padding-top:4px!important;padding-bottom:4px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(.content-top-home,.content-top-home .ayo-home5-wrapper,.content-top-home .ayo-home5-wrapper--template-driven){'
                . 'overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(.awa-product-promo-banners__item,a.awa-product-promo-banners__item){'
                . 'overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
                . '@media(max-width:767px){html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper :is(.top-home-content--above-fold,.top-home-content--above-fold>.banner-slider.banner-slider2){'
                . 'box-sizing:border-box!important;height:auto!important;min-height:176px!important;max-height:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .wrapper_slider.visible-xs{min-height:176px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .wrapper_slider.visible-xs .awa-hero-swiper{min-height:176px!important;height:176px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-product-promo-banners{overflow-x:clip!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-product-promo-banners :is(.container,.awa-product-promo-banners__grid){'
                . 'box-sizing:border-box!important;inline-size:100%!important;max-inline-size:100%!important;min-inline-size:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-product-promo-banners__grid{display:flex!important;flex-wrap:nowrap!important;'
                . 'overflow-x:auto!important;overflow-y:hidden!important;overscroll-behavior-x:contain!important;scrollbar-width:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-product-promo-banners__grid::-webkit-scrollbar{display:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper :is(.awa-product-promo-banners__item,a.awa-product-promo-banners__item){'
                . 'box-sizing:border-box!important;flex:0 0 min(82vw,320px)!important;inline-size:min(82vw,320px)!important;'
                . 'max-inline-size:min(82vw,320px)!important;min-inline-size:0!important;overflow:hidden!important;'
                . 'overflow-x:hidden!important;overflow-y:hidden!important}}'
                . '@media(max-width:767px){html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-swiper :is(.awa-hero-swiper__nav,.swiper-button-prev,.swiper-button-next){'
                . 'box-sizing:border-box!important;width:44px!important;height:44px!important;min-width:44px!important;'
                . 'min-height:44px!important;max-width:44px!important;max-height:44px!important;padding:0!important;'
                . 'display:grid!important;place-items:center!important;line-height:1!important;transform:translateY(-50%)!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-swiper :is(.awa-hero-swiper__nav,.swiper-button-prev,.swiper-button-next) '
                . ':is(svg,span){box-sizing:border-box!important;width:20px!important;height:20px!important;'
                . 'min-width:20px!important;min-height:20px!important;max-width:20px!important;max-height:20px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-swiper{position:relative!important;overflow:hidden!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-swiper .swiper-pagination{position:absolute!important;left:16px!important;'
                . 'right:16px!important;bottom:8px!important;top:auto!important;height:60px!important;margin:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-swiper .awa-hero-pause-btn{position:absolute!important;right:16px!important;'
                . 'bottom:8px!important;top:auto!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-swiper .swiper-slide{display:none!important;visibility:hidden!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-swiper:not(.swiper-initialized) .swiper-slide:first-child,'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-swiper .swiper-slide.swiper-slide-active{display:block!important;visibility:visible!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper :is(.awa-hero-inline-cta,.awa-hero-inline-cta a,.awa-hero-b2b-cta,'
                . '.awa-hero-b2b-cta__inner,.awa-hero-benefits,'
                . '.awa-hero-benefits__item,.awa-hero-benefits__copy,.awa-hero-benefits__title,'
                . '.awa-hero-benefits__text,.awa-hero-benefits__link){'
                . 'box-sizing:border-box!important;min-height:0!important;max-height:none!important;height:auto!important}'
                /* opt23 CLS (2026-07-05): a regra acima zera min-height do item p/ deixar 100%
                 * content-driven, mas isso nao evita o shift — a causa raiz real (confirmada via
                 * diff de TODAS as propriedades computadas, nao so as obvias) e flex-direction:
                 * o item nasce com flex-direction:column (icone empilhado sobre o texto, ~127px)
                 * e so muda p/ flex-direction:row (icone ao lado do texto, ~72px) quando a folha
                 * "final" entre as ~8 concorrentes (density-grid, corporate-density-grid,
                 * impeccable-layout, align-grid-terminal, themes.min.css, standardize-terminal-
                 * wins) termina de carregar — shift isolado de CLS ~0.98 em mobile (390px), a
                 * maior fonte de CLS da home. padding/gap/icon-size nao mudavam (medido antes),
                 * so a direção do flex — por isso fixar so min-height/padding nao resolvia.
                 * Fixando flex-direction:row (+ demais props de layout) nesta mesma posicao/
                 * especificidade sincrona (corpo do HTML, vence por ordem+especificidade sobre
                 * as folhas async concorrentes) elimina a corrida em vez de so mitiga-la. */
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta .awa-hero-benefits{align-items:start!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta .awa-hero-benefits__item{'
                . 'min-height:72px!important;padding:12px!important;gap:10px!important;'
                . 'flex-direction:row!important;align-items:flex-start!important;align-self:start!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta .awa-hero-benefits__icon{'
                . 'flex:0 0 44px!important;width:44px!important;height:44px!important;'
                . 'min-width:44px!important;min-height:44px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-inline-cta .action.primary,'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta .action.primary{'
                . 'min-height:44px!important;display:inline-flex!important;align-items:center!important;'
                . 'justify-content:center!important;padding-block:0!important;padding-inline:18px!important;'
                . 'box-sizing:border-box!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta{padding-block:12px!important;margin-block:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta{min-height:clamp(540px,145vw,620px)!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-b2b-cta__inner{min-height:clamp(512px,138vw,592px)!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-benefits{display:grid!important;gap:8px!important;min-height:clamp(420px,118vw,500px)!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-benefits__icon{box-sizing:border-box!important;display:inline-grid!important;'
                . 'place-items:center!important;width:44px!important;height:44px!important;min-width:44px!important;'
                . 'min-height:44px!important;max-width:44px!important;max-height:44px!important;padding:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
                . '.page-wrapper .awa-hero-benefits__icon :is(svg,img){box-sizing:border-box!important;width:22px!important;'
                . 'height:22px!important;min-width:22px!important;min-height:22px!important;max-width:22px!important;'
                . 'max-height:22px!important}}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(.page_footer,.page-footer) :is(.footer-bottom,.footer-bottom-inner,.awa-footer-bottom__row,.awa-footer-bottom__copyright){'
                . 'content-visibility:visible!important;contain-intrinsic-size:unset!important;contain:none!important}'
                . '@media(max-width:767px){html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(.page_footer,.page-footer) .footer-bottom{display:block!important;visibility:visible!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(.page_footer,.page-footer) .footer-bottom .footer-bottom-inner{display:grid!important;min-height:540px!important;visibility:visible!important}}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-product-promo-banners__item :is(picture,img){border-radius:inherit!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-product-promo-banners__media{box-sizing:border-box!important;display:grid!important;place-items:center!important;'
                . 'aspect-ratio:463/349!important;background:color-mix(in srgb,var(--awa-primary) 6%,Canvas)!important;color:var(--awa-primary)!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-product-promo-banners__media :is(svg,img,picture){max-width:72px!important;max-height:72px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-product-promo-banners__copy{display:grid!important;gap:4px!important;padding:12px!important;'
                . 'color:var(--awa-text,var(--awa-text-primary,CanvasText))!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-product-promo-banners__copy strong{font-size:15px!important;font-weight:800!important;line-height:1.25!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-product-promo-banners__copy span{color:var(--awa-text-muted,var(--awa-muted,CanvasText))!important;'
                . 'font-size:12px!important;line-height:1.35!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.content-top-home :is(.awa-section-header,.awa-shelf__header,.rokan-product-heading){'
                . 'align-items:flex-end!important;gap:8px 16px!important;margin-bottom:16px!important;text-align:left!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.content-top-home :is(.awa-section-header__title,.awa-shelf__title,.rokan-product-heading h2,.rokan-product-heading strong){'
                . 'line-height:1.2!important;text-wrap:balance!important;text-transform:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.content-top-home :is(.awa-section-header__subtitle,.awa-shelf__subtitle){'
                . 'color:var(--awa-text-muted,var(--awa-muted,CanvasText))!important;line-height:1.45!important;max-width:64ch!important;text-wrap:pretty!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.top-home-content--category-carousel :is(.awa-category-carousel__item,.awa-category-carousel__link){'
                . 'align-items:center!important;box-sizing:border-box!important;display:flex!important;flex-direction:column!important;'
                . 'gap:8px!important;justify-content:flex-start!important;min-height:118px!important;text-align:center!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.top-home-content--category-carousel .awa-category-carousel__icon{display:grid!important;margin-inline:auto!important;place-items:center!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.top-home-content--category-carousel :is(.awa-category-carousel__label,.awa-category-carousel__title,.awa-category-carousel__name){'
                . 'display:-webkit-box!important;font-size:13px!important;font-weight:700!important;line-height:1.25!important;'
                . 'max-width:18ch!important;overflow:hidden!important;text-transform:none!important;text-wrap:balance!important;'
                . '-webkit-box-orient:vertical!important;-webkit-line-clamp:2!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . ':is(.awa-carousel-card-slot,.item-product,.content-item-product.awa-product-card) '
                . ':is(h3.product-name,.product-name,a.product-item-link){line-height:1.35!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel :is(.product-name a,a.product-item-link){display:-webkit-box!important;'
                . '-webkit-box-orient:vertical!important;-webkit-line-clamp:2!important;overflow:hidden!important;'
                . 'min-height:calc(1.35em * 2)!important;text-wrap:pretty!important;text-transform:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .awa-b2b-sku{align-items:center!important;color:var(--awa-text-muted,var(--awa-muted,CanvasText))!important;'
                . 'display:inline-flex!important;font-size:11px!important;font-weight:600!important;gap:3px!important;line-height:1.3!important;'
                . 'margin-top:6px!important;max-width:100%!important;min-height:18px!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .awa-b2b-sku__label{color:var(--awa-text-muted,var(--awa-muted,CanvasText))!important;font-weight:700!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .product-rating{align-items:center!important;display:flex!important;'
                . 'min-height:18px!important;margin-top:6px!important;max-width:100%!important;overflow:hidden!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .product-rating:has(.product-reviews-summary.empty),'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .product-rating:empty{'
                . 'display:none!important;min-height:0!important;margin:0!important;overflow:hidden!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .product-reviews-summary.empty{display:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel :is(.product-reviews-summary,.rating-summary,.reviews-actions){'
                . 'align-items:center!important;display:inline-flex!important;line-height:1!important;margin:0!important;min-height:18px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .product-reviews-summary.empty,'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .product-reviews-summary.empty :is(.rating-summary,.reviews-actions){'
                . 'display:none!important;min-height:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel :is(.reviews-actions,.review-count){font-size:11px!important;line-height:1!important;'
                . 'max-width:9ch!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-hero-inline-cta{display:flex!important;justify-content:center!important;padding:12px 16px 4px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-hero-inline-cta :is(a,.action.primary){box-sizing:border-box!important;display:inline-flex!important;'
                . 'align-items:center!important;justify-content:center!important;min-height:44px!important;padding-inline:18px!important;'
                . 'border-radius:999px!important;font-weight:700!important;text-align:center!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-hero-b2b-cta__actions{display:flex!important;justify-content:center!important;margin-top:14px!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-hero-b2b-cta__button{box-sizing:border-box!important;display:inline-flex!important;align-items:center!important;'
                . 'justify-content:center!important;min-height:44px!important;padding-inline:20px!important;text-align:center!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-card-view-product{box-sizing:border-box!important;display:inline-flex!important;align-items:center!important;'
                . 'justify-content:center!important;min-height:44px!important;width:100%!important;margin-top:10px!important;'
                . 'border:1px solid var(--awa-border,var(--awa-border-color,color-mix(in srgb,CanvasText 12%,Canvas)))!important;border-radius:8px!important;'
                . 'color:var(--awa-primary)!important;font-size:13px!important;font-weight:700!important;text-decoration:none!important;'
                . 'text-transform:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-card-view-product:is(:hover,:focus-visible){border-color:var(--awa-primary)!important;'
                . 'background:color-mix(in srgb,var(--awa-primary) 8%,Canvas)!important;text-decoration:none!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel :is(.item-product,.product-item-info,.content-item-product,.awa-product-card){'
                . 'display:flex!important;flex-direction:column!important;height:100%!important;min-height:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel :is(.product-info,.product-item-details){display:flex!important;'
                . 'flex:1 1 auto!important;flex-direction:column!important;min-height:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
                . '.awa-shelf--carousel .awa-card-view-product{margin-top:auto!important}'
                . 'html body#html-body#html-body .page-wrapper .security-seals__link{display:inline-flex!important;'
                . 'align-items:center!important;justify-content:center!important;min-height:44px!important}';
        $css .= 'html body#html-body#html-body .page-wrapper :is([id^="awa-vertical-menu-"],.navigation.verticalmenu,.navigation.verticalmenu.side-verticalmenu,.side-verticalmenu>ul.togge-menu){'
            . 'box-shadow:0 2px 8px color-mix(in srgb,CanvasText 8%,transparent)!important}'
            . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) p.awa-footer-atendimento__store-address{'
            . 'line-height:1.45!important}'
            . 'html body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) p.awa-footer-copyright__disclaimer{'
            . 'line-height:1.45!important;margin-inline:0!important;max-width:none!important;width:100%!important;'
            . 'text-align:center!important;text-wrap:pretty!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            . '.footer-bottom .awa-footer-bottom__copyright p.awa-footer-copyright__disclaimer{'
            . 'line-height:1.45!important;margin-inline:0!important;max-width:none!important;width:100%!important;'
            . 'text-align:center!important;text-wrap:pretty!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column .page-wrapper '
            . '.awa-site-header #awa-b2b-promo-bar .awa-b2b-promo-bar__cta strong.awa-b2b-promo-bar__cta-long{'
            . 'color:Canvas!important;text-shadow:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column .page-wrapper '
            . '.awa-site-header #search_mini_form{overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column .page-wrapper '
            . '.awa-site-header .header-control.header-nav.awa-nav-bar{'
            . 'padding-block:4px!important;padding-top:4px!important;padding-bottom:4px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column .page-wrapper '
            . '.awa-site-header .header-control.header-nav.awa-nav-bar>.container{'
            . 'padding-block:4px!important;padding-top:4px!important;padding-bottom:4px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #header.header-container{'
            . 'box-sizing:border-box!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding-bottom:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar.awa-b2b-promo-bar{'
            . 'background:var(--awa-primary)!important;background-color:var(--awa-primary)!important;box-sizing:border-box!important;color:var(--awa-on-primary,Canvas)!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding-bottom:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar :is(.awa-b2b-promo-close,#awa-b2b-promo-close){'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;'
            . 'min-height:44px!important;max-height:44px!important;display:inline-flex!important;align-items:center!important;'
            . 'justify-content:center!important;color:inherit!important;background:transparent!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar .awa-b2b-promo-bar__cta{'
            . 'color:inherit!important;min-height:44px!important;height:44px!important;max-height:44px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,'
            . '.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__separator){'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . '@media(min-width:768px) and (max-width:991px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header :is(#header.header-container,#awa-b2b-promo-bar.awa-b2b-promo-bar){'
            . 'height:48px!important;min-height:48px!important;max-height:48px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout,'
            . '.awa-b2b-promo-bar__text){height:48px!important;min-height:48px!important;max-height:48px!important;'
            . 'line-height:48px!important;overflow:visible!important}'
            /* H23: tablet — NÃO esconder lead-long (impeccable já esconde lead-short).
               Altura 32px + ellipsis; evita “só Cadastre agora”. */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar.awa-b2b-promo-bar{'
            . 'height:32px!important;min-height:32px!important;max-height:32px!important;overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar.awa-b2b-promo-bar '
            . ':is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout,.awa-b2b-promo-bar__text){'
            . 'height:32px!important;min-height:32px!important;max-height:32px!important;line-height:32px!important;overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar.awa-b2b-promo-bar '
            . '.awa-b2b-promo-bar__text>.awa-b2b-promo-bar__lead{'
            . 'display:inline-flex!important;align-items:center!important;flex:0 1 auto!important;min-width:0!important;max-width:42%!important;'
            . 'overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar.awa-b2b-promo-bar '
            . '.awa-b2b-promo-bar__text>.awa-b2b-promo-bar__lead .awa-b2b-promo-bar__lead-long{'
            . 'display:inline-block!important;max-width:100%!important;overflow:hidden!important;text-overflow:ellipsis!important;'
            . 'white-space:nowrap!important;visibility:visible!important;width:auto!important;height:auto!important;position:static!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar.awa-b2b-promo-bar .awa-b2b-promo-bar__cta{'
            . 'height:28px!important;min-height:28px!important;max-height:28px!important;line-height:1!important}}'
            . '@media(min-width:768px) and (max-width:991px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header .header-wrapper-sticky{display:flex!important;flex-direction:column!important;'
            . 'height:auto!important;min-height:calc(56px + 48px)!important;max-height:none!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header :is(.header-control.header-nav,.header-control.awa-nav-bar){'
            . 'display:flex!important;visibility:visible!important;height:48px!important;min-height:48px!important;'
            . 'max-height:48px!important;overflow:visible!important;pointer-events:auto!important}}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt :is(.awa-header-account-prompt__link--login,'
            . '.awa-header-account-prompt__link):not(.awa-header-account-prompt__link--register){'
            . 'display:inline-flex!important;align-items:center!important;min-height:0!important;height:auto!important;'
            . 'box-sizing:border-box!important;line-height:1.2!important;padding:0 2px!important;'
            . 'background:transparent!important}'
            /* 2026-08-12: cadastre-se = link textual, igual a Entrar */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header a.awa-header-account-prompt__link--register{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'padding:0 2px!important;border-radius:0!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'color:var(--awa-text,CanvasText)!important;border:0!important;'
            . 'font-size:12px!important;font-weight:700!important;line-height:1.2!important;'
            . 'text-decoration:none!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt__line2{'
            . 'overflow:visible!important;min-height:0!important;height:auto!important;align-items:center!important;gap:6px!important;padding:0!important}'
            /* 2026-08-12: guest shrink-wrap */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]{'
            . 'flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:230px!important;overflow:hidden!important;gap:8px!important;height:50px!important;min-height:50px!important;max-height:50px!important}'
            /* 2026-08-12 terminal 14 IDs: vence design-system 13 IDs (min-width:220px + pílula). */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header .awa-header-account-prompt,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]{'
            . 'flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:230px!important;'
            . 'overflow:hidden!important;gap:8px!important;'
            . 'height:50px!important;min-height:50px!important;max-height:50px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header .awa-header-account-prompt'
            . ' :is(.awa-header-account-prompt__text,.awa-header-account-prompt__guest,.awa-header-account-prompt__copy){'
            . 'flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header .awa-header-account-prompt[data-awa-auth-state="customer"]{'
            . 'flex:1 1 auto!important;width:auto!important;max-width:232px!important;min-width:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header a.awa-header-account-prompt__link--register,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header .awa-header-account-prompt a.awa-header-account-prompt__link--register{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'background:transparent!important;background-color:transparent!important;color:var(--awa-text,CanvasText)!important;'
            . 'border:0!important;border-radius:0!important;padding:0 2px!important;min-height:0!important;height:auto!important;'
            . 'max-height:none!important;margin-inline-start:0!important;font-size:12px!important;font-weight:700!important;'
            . 'line-height:1.2!important;text-decoration:none!important;box-shadow:none!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header .awa-header-account-prompt'
            . ' :is(.awa-header-account-prompt__link--login,.awa-header-account-prompt__link--register):is(:hover,:focus-visible){'
            . 'color:var(--awa-primary)!important;text-decoration:underline!important;text-underline-offset:2px!important;'
            . 'background:transparent!important;background-color:transparent!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header .awa-header-right-col{'
            . 'gap:10px!important;max-width:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt__guest{gap:2px!important;overflow:visible!important}'

            . '@media(min-width:768px){html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-b2b-promo-bar .awa-b2b-promo-bar__cta strong.awa-b2b-promo-bar__cta-long{'
            . 'color:var(--awa-on-primary,Canvas)!important;text-shadow:none!important}}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header div.awa-main-header__inner.wp-header{'
            . 'box-sizing:border-box!important;height:64px!important;min-height:64px!important;max-height:64px!important;'
            . 'padding-top:0!important;padding-bottom:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header #awa-search-label>span{'
            . 'border:0!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;height:1px!important;margin:-1px!important;overflow:hidden!important;'
            . 'font-size:0!important;line-height:0!important;padding:0!important;position:absolute!important;width:1px!important;white-space:nowrap!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column .page-wrapper '
            . '.awa-site-header .header-control.header-nav.awa-nav-bar>.container{'
            . 'box-sizing:border-box!important;padding-left:16px!important;padding-right:16px!important}'
            . '@media(max-width:767px){html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header .header-wrapper-sticky{'
            . 'box-sizing:border-box!important;height:96px!important;min-height:96px!important;max-height:96px!important;'
            . 'padding:0 16px!important;padding-block:0!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container){'
            . 'box-sizing:border-box!important;height:96px!important;min-height:96px!important;max-height:96px!important;'
            . 'padding:0!important;margin:0!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header div.awa-main-header__inner.wp-header{'
            . 'box-sizing:border-box!important;display:grid!important;gap:4px 8px!important;grid-template-areas:"toggle brand cart" "search search search"!important;'
            . 'grid-template-columns:44px minmax(0,1fr) 44px!important;grid-template-rows:44px 44px!important;'
            . 'height:96px!important;min-height:96px!important;max-height:96px!important;overflow:visible!important;'
            . 'padding:4px 16px 0!important;padding-block:4px 0!important;padding-inline:16px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.cms-index-index.page-layout-1column '
            . '.page-wrapper .awa-site-header :is(.header-control.header-nav,.header-control.header-nav.awa-nav-bar,'
            . '.header-control.header-nav.awa-nav-bar>.container,.awa-nav-bar__inner){'
            . 'box-sizing:border-box!important;height:0!important;min-height:0!important;max-height:0!important;'
            . 'padding:0!important;margin:0!important;overflow:hidden!important;visibility:hidden!important}}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            . ':is([id^="awa-vertical-menu-"],.navigation.verticalmenu,.navigation.verticalmenu.side-verticalmenu,.side-verticalmenu>ul.togge-menu){'
            . 'border:0!important;box-shadow:0 2px 8px color-mix(in srgb,CanvasText 8%,transparent)!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header .awa-header-minicart:has(.minicart-wrapper .showcart)>.awa-header-cart-fallback{'
            . 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'min-width:0!important;min-height:0!important;overflow:hidden!important;pointer-events:none!important;'
            . 'position:absolute!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header #search_mini_form #awa-search-clear.awa-search-clear-btn[hidden]{display:none!important;visibility:hidden!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header #search_mini_form #awa-search-clear.awa-search-clear-btn:not([hidden]){display:inline-flex!important;visibility:visible!important}'
            . '@layer awa-fixes{/* Required: legacy awa-fixes !important header rules beat unlayered terminal CSS. */'
            . '@media(max-width:767px){html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper header.awa-site-header .header-wrapper-sticky>div.header.awa-main-header[data-awa-header-main="true"]{'
            . 'box-sizing:border-box!important;display:block!important;height:96px!important;min-height:96px!important;'
            . 'max-height:96px!important;block-size:96px!important;min-block-size:96px!important;max-block-size:96px!important;'
            . 'padding:0!important;margin:0!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:not(.cms-index-index) '
            . '.page-wrapper header.awa-site-header .header-wrapper-sticky{'
            . 'box-sizing:border-box!important;height:96px!important;min-height:96px!important;max-height:96px!important;'
            . 'padding:0!important;margin:0!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper header.awa-site-header .header-wrapper-sticky div.awa-main-header__inner.wp-header{'
            . 'box-sizing:border-box!important;display:grid!important;grid-template:"toggle brand cart" 44px "search search search" 44px/44px minmax(0,1fr) 44px!important;'
            . 'gap:4px 8px!important;height:96px!important;min-height:96px!important;max-height:96px!important;'
            . 'padding:4px 16px 0!important;padding-block:4px 0!important;padding-inline:16px!important;'
            . 'align-content:start!important;align-items:center!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper header.awa-site-header .header-wrapper-sticky :is(.awa-header-search-col,.block-search,.block-search .block-content){'
            . 'box-sizing:border-box!important;height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;margin:0!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper header.awa-site-header .header-wrapper-sticky>.header-control.header-nav.awa-nav-bar[data-awa-header-nav="true"]{'
            . 'display:none!important;visibility:hidden!important;height:0!important;min-height:0!important;max-height:0!important;'
            . 'padding:0!important;margin:0!important;border:0!important;overflow:hidden!important}}}';

        $css .= $this->buildUnifiedHeaderGeometryAuthorityCss();

        $css .= '</style>';
        /* Patch 2026-07-02: o MutationObserver original observava document.documentElement
         * inteiro (subtree:true) e reagia a QUALQUER mutação da página (carrosséis, grids,
         * animações) chamando sync() via requestAnimationFrame + 2x setTimeout. Como sync()
         * também escreve atributos (tabindex/aria-hidden/inert) nos próprios nós observados,
         * cada execução gerava novas mutações que realimentavam o observer indefinidamente.
         * Medido via Lighthouse/PageSpeed: este script sozinho respondia por a maior fatia de
         * "Style & Layout" no main-thread e ajudava a travar a página inteira (TBT 11s+,
         * "the page stopped responding"). Fix: escopo do observer restrito ao próprio header
         * (não o documento todo) + desconectar/reconectar durante a escrita (zero reentrância)
         * + debounce único por rAF nas mutações (em vez de 3 disparos por evento). */
        $injectHiddenFocusSync = !in_array($fullAction, self::CHECKOUT_FOCUS_ACTIONS, true)
            && !in_array($fullAction, self::CATALOG_STACK_ACTIONS, true);

        $script = '<script id="awa-header-hidden-focus-sync">(function(){'
            . 'var owned="data-awa-hidden-focus-sync",storedTab="data-awa-prev-tabindex",storedAria="data-awa-prev-aria-hidden";'
            . 'function isHidden(el){if(!el||!el.isConnected){return true;}'
            . 'if(el.hidden||el.closest("[hidden]")){return true;}'
            . 'var cs=getComputedStyle(el),r=el.getBoundingClientRect();'
            . 'return cs.display==="none"||cs.visibility==="hidden"||r.width===0||r.height===0;}'
            . 'function remember(el){if(!el.hasAttribute(storedTab)){el.setAttribute(storedTab,el.hasAttribute("tabindex")?el.getAttribute("tabindex"):"");}'
            . 'if(!el.hasAttribute(storedAria)){el.setAttribute(storedAria,el.hasAttribute("aria-hidden")?el.getAttribute("aria-hidden"):"");}}'
            . 'function restore(el){if(el.getAttribute(owned)!=="1"){return;}'
            . 'var tab=el.getAttribute(storedTab),aria=el.getAttribute(storedAria);'
            . 'if(tab===""){el.removeAttribute("tabindex");}else if(tab!==null){el.setAttribute("tabindex",tab);}'
            . 'if(aria===""){el.removeAttribute("aria-hidden");}else if(aria!==null){el.setAttribute("aria-hidden",aria);}'
            . 'if("inert" in el){el.inert=false;}el.removeAttribute(owned);el.removeAttribute(storedTab);el.removeAttribute(storedAria);}'
            . 'function hide(el){if(el.getAttribute(owned)==="1"&&el.getAttribute("tabindex")==="-1"&&el.getAttribute("aria-hidden")==="true"){return;}remember(el);el.setAttribute("tabindex","-1");el.setAttribute("aria-hidden","true");'
            . 'if("inert" in el){el.inert=true;}el.setAttribute(owned,"1");}'
            . 'var headerObserverOptions={childList:true,subtree:true,attributes:true,attributeFilter:["class","style","hidden","aria-hidden"]};'
            . 'var headerObserver=null;'
            . 'function sync(){var root=document.querySelector(".awa-site-header");if(!root){return;}'
            . 'if(headerObserver){headerObserver.disconnect();}'
            . 'var nodes=root.querySelectorAll(".action.nav-toggle,.awa-header-mobile-toggle,.awa-header-cart-link,.awa-header-cart-fallback,.awa-minicart-continue,.header-control.header-nav,.header-control.header-nav a,.header-control.header-nav button,.awa-nav-quick-links__link,.title-category-dropdown,.awa-header-account-prompt__icon,.awa-header-account-prompt__link,.awa-top-link-anchor,.header.links a,.top-header a[href]");'
            . 'nodes.forEach(function(el){if(isHidden(el)){hide(el);}else{restore(el);}});'
            . 'if(headerObserver){headerObserver.observe(root,headerObserverOptions);}}'
            . 'function soon(){requestAnimationFrame(sync);setTimeout(sync,250);setTimeout(sync,1200);}'
            . 'var mutationSyncQueued=false;'
            . 'function syncSoonFromMutation(){if(mutationSyncQueued){return;}mutationSyncQueued=true;'
            . 'requestAnimationFrame(function(){mutationSyncQueued=false;sync();});}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",soon,{once:true});}else{soon();}'
            . 'window.addEventListener("load",soon,{once:true,passive:true});window.addEventListener("resize",soon,{passive:true});'
            . 'if(window.MutationObserver){'
            . 'headerObserver=new MutationObserver(function(){syncSoonFromMutation();});'
            . 'var bindHeaderObserver=function(){var root=document.querySelector(".awa-site-header");'
            . 'if(!root){return false;}headerObserver.observe(root,headerObserverOptions);return true;};'
            . 'if(!bindHeaderObserver()){document.addEventListener("DOMContentLoaded",bindHeaderObserver,{once:true});}'
            . '}'
            . '})();</script>';

        $payload = $css;
        if ($injectHiddenFocusSync) {
            $payload .= $script;
        }

        $injected = preg_replace('/<\/body>/i', $payload . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Geometria única do header (3D.2.2B): fonte de verdade para home/PLP/PDP/cart.
     */
    private function buildUnifiedHeaderGeometryAuthorityCss(): string
    {
        $css = <<<'CSS'
@media(min-width:1200px){html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]{--awa-header-main-row-h:68px!important;--awa-header-nav-h:48px!important;--awa-header-shell-pad:24px!important;--awa-header-col-gap:24px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .header.awa-main-header{height:var(--awa-header-main-row-h)!important;min-height:var(--awa-header-main-row-h)!important;max-height:var(--awa-header-main-row-h)!important;padding:0!important;padding-inline:0!important;box-sizing:border-box!important;overflow:visible!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container,.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){height:var(--awa-header-main-row-h)!important;min-height:var(--awa-header-main-row-h)!important;max-height:var(--awa-header-main-row-h)!important;box-sizing:border-box!important;overflow:visible!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){display:grid!important;grid-template-columns:minmax(140px,172px) minmax(360px,1fr) minmax(240px,300px)!important;grid-template-areas:"brand search actions"!important;align-items:center!important;column-gap:var(--awa-header-col-gap)!important;width:min(100%,1280px)!important;max-width:1280px!important;margin-inline:auto!important;padding-inline:var(--awa-header-shell-pad)!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-primary-row{display:contents!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-brand-cell{grid-area:brand!important;display:flex!important;align-items:center!important;justify-content:flex-start!important;height:var(--awa-header-main-row-h)!important;min-height:0!important;max-height:var(--awa-header-main-row-h)!important;overflow:hidden!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col{grid-area:search!important;display:flex!important;align-items:center!important;width:100%!important;min-width:0!important;max-width:none!important;height:var(--awa-header-main-row-h)!important;min-height:0!important;max-height:var(--awa-header-main-row-h)!important;overflow:visible!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col{grid-area:actions!important;display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:8px!important;width:100%!important;min-width:0!important;max-width:300px!important;height:var(--awa-header-main-row-h)!important;min-height:0!important;max-height:var(--awa-header-main-row-h)!important;overflow:visible!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] :is(.header-control.header-nav,.header-control.awa-nav-bar,.awa-nav-bar){height:var(--awa-header-nav-h)!important;min-height:var(--awa-header-nav-h)!important;max-height:var(--awa-header-nav-h)!important;overflow:visible!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .awa-nav-bar__inner{height:var(--awa-header-nav-h)!important;min-height:var(--awa-header-nav-h)!important;max-height:var(--awa-header-nav-h)!important;box-sizing:border-box!important;width:min(100%,1280px)!important;max-width:1280px!important;margin-inline:auto!important;padding-inline:var(--awa-header-shell-pad)!important}}
@media(min-width:992px) and (max-width:1199px){html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]{--awa-header-main-row-h:64px!important;--awa-header-nav-h:46px!important;--awa-header-shell-pad:24px!important;--awa-header-col-gap:16px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){grid-template-columns:minmax(132px,160px) minmax(320px,1fr) minmax(220px,280px)!important;grid-template-areas:"brand search actions"!important;column-gap:var(--awa-header-col-gap)!important;padding-inline:var(--awa-header-shell-pad)!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .header.awa-main-header{height:var(--awa-header-main-row-h)!important;min-height:var(--awa-header-main-row-h)!important;max-height:var(--awa-header-main-row-h)!important;padding:0!important;padding-inline:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container,.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){height:var(--awa-header-main-row-h)!important;min-height:var(--awa-header-main-row-h)!important;max-height:var(--awa-header-main-row-h)!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] :is(.header-control.header-nav,.header-control.awa-nav-bar,.awa-nav-bar){height:var(--awa-header-nav-h)!important;min-height:var(--awa-header-nav-h)!important;max-height:var(--awa-header-nav-h)!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .awa-nav-bar__inner{height:var(--awa-header-nav-h)!important;min-height:var(--awa-header-nav-h)!important;max-height:var(--awa-header-nav-h)!important;padding-inline:var(--awa-header-shell-pad)!important}}
@media(min-width:768px) and (max-width:991px){html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]{--awa-header-main-row-h:56px!important;--awa-header-nav-h:44px!important;--awa-header-shell-pad:16px!important;--awa-header-col-gap:16px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){display:grid!important;grid-template-columns:minmax(112px,148px) minmax(260px,1fr) minmax(180px,240px)!important;grid-template-areas:"brand search actions"!important;column-gap:var(--awa-header-col-gap)!important;padding-inline:var(--awa-header-shell-pad)!important;height:var(--awa-header-main-row-h)!important;min-height:var(--awa-header-main-row-h)!important;max-height:var(--awa-header-main-row-h)!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .header.awa-main-header{height:var(--awa-header-main-row-h)!important;min-height:var(--awa-header-main-row-h)!important;max-height:var(--awa-header-main-row-h)!important;padding:0!important;padding-inline:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-primary-row{display:contents!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-brand-cell{grid-area:brand!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col{grid-area:search!important;max-width:none!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col{grid-area:actions!important;max-width:240px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] :is(.header-control.header-nav,.header-control.awa-nav-bar,.awa-nav-bar){height:var(--awa-header-nav-h)!important;min-height:var(--awa-header-nav-h)!important;max-height:var(--awa-header-nav-h)!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .awa-nav-bar__inner{height:var(--awa-header-nav-h)!important;min-height:var(--awa-header-nav-h)!important;max-height:var(--awa-header-nav-h)!important;padding-inline:var(--awa-header-shell-pad)!important}}
@media(max-width:767px){html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky{height:auto!important;min-height:0!important;max-height:none!important;padding-block:0!important;padding-inline:16px!important;overflow:visible!important;box-sizing:border-box!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container){height:auto!important;min-height:0!important;max-height:none!important;padding:0!important;margin:0!important;overflow:visible!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){display:grid!important;grid-template-areas:"toggle brand cart" "search search search"!important;grid-template-columns:44px minmax(0,1fr) 44px!important;grid-template-rows:auto auto!important;row-gap:12px!important;column-gap:12px!important;height:auto!important;min-height:0!important;max-height:none!important;padding-block:12px!important;padding-inline:0!important;overflow:visible!important;box-sizing:border-box!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-primary-row{display:contents!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-mobile-toggle{grid-area:toggle!important;justify-self:start!important;align-self:center!important;width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-brand-cell{grid-area:brand!important;justify-self:center!important;align-self:center!important;min-width:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-cart-link{grid-area:cart!important;justify-self:end!important;align-self:center!important;width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col{grid-area:search!important;width:100%!important;min-width:0!important;max-width:none!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col form#search_mini_form{display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;grid-template-areas:"field submit"!important;width:100%!important;min-width:0!important;max-width:100%!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col form#search_mini_form .field.search{grid-area:field!important;min-width:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col input#search{height:44px!important;min-height:44px!important;max-height:44px!important;line-height:44px!important;font-size:16px!important;padding-block:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col form#search_mini_form .actions{grid-area:submit!important;width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;margin:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col form#search_mini_form :is(.action.search,button.action.search){width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col{grid-area:search!important;width:100%!important;min-width:0!important;max-width:none!important}}
@media(min-width:1200px){html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container,.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){height:68px!important;min-height:68px!important;max-height:68px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){grid-template-columns:160px minmax(0,1fr) 272px!important;column-gap:28px!important;padding-inline:24px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.awa-header-brand-cell,.awa-header-search-col,.awa-header-right-col){box-sizing:border-box!important;height:68px!important;min-height:68px!important;max-height:68px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-brand-cell{width:160px!important;min-width:160px!important;max-width:160px!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col{align-items:center!important;display:flex!important;padding-inline:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col form#search_mini_form{align-self:center!important;margin-block:auto!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col{width:272px!important;min-width:272px!important;max-width:272px!important}}
/* 2026-07-17 Round3: sticky-h 118 (68+frame50) deixava deadBottom=2px na PLP porque
   a nav resolve para --awa-header-nav-h (48), não 50. SSOT = main-row + nav-h.
   border:0 no wrap evita clip com border-box + border-block-end da PLP. */
@media(min-width:1200px){html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]{--awa-header-nav-frame-h:var(--awa-header-nav-h,48px);--awa-header-sticky-h:calc(var(--awa-header-main-row-h,68px) + var(--awa-header-nav-h,48px))}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky{box-sizing:border-box!important;display:flex!important;flex-direction:column!important;gap:0!important;height:var(--awa-header-sticky-h)!important;min-height:var(--awa-header-sticky-h)!important;max-height:var(--awa-header-sticky-h)!important;margin:0!important;padding:0!important;border:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky>:is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar){box-sizing:border-box!important;flex:0 0 var(--awa-header-nav-h,48px)!important;height:var(--awa-header-nav-h,48px)!important;min-height:var(--awa-header-nav-h,48px)!important;max-height:var(--awa-header-nav-h,48px)!important;margin:0 auto!important;padding:0!important}html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.header-control.awa-nav-bar>.container,.awa-nav-bar__inner){box-sizing:border-box!important;height:var(--awa-header-nav-h,48px)!important;min-height:var(--awa-header-nav-h,48px)!important;max-height:var(--awa-header-nav-h,48px)!important}}
/* AWA 2026-07-16 H4: PLP padding-block:8px on main+header_main + height:68 spilled 16px into nav (sticky).
   2026-07-17: overflow:visible (Round1) — hidden recortava autocomplete/flyout. */
@media(min-width:992px){html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container){padding-block:0!important;padding-top:0!important;padding-bottom:0!important;box-sizing:border-box!important}html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .header.awa-main-header{height:var(--awa-header-main-row-h,68px)!important;min-height:var(--awa-header-main-row-h,68px)!important;max-height:var(--awa-header-main-row-h,68px)!important;overflow:visible!important}html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container,.awa-main-header__inner.wp-header,.awa-header-right-col){height:var(--awa-header-main-row-h,68px)!important;min-height:var(--awa-header-main-row-h,68px)!important;max-height:var(--awa-header-main-row-h,68px)!important;margin-block:0!important}}
CSS;

        return $css;
    }

    private function normalizeHeaderGeometryAuthorityInlineStyles(
        string $html,
        string $fullAction,
        bool $isAuthFocusPage,
        bool $isB2bAccountFocusPage
    ): string {
        if ($isAuthFocusPage || $isB2bAccountFocusPage) {
            return $html;
        }

        // Legacy lock de paridade do catálogo introduz uma segunda fonte de verdade para header.
        $html = preg_replace('/<style id="awa-header-catalog-parity-v1"[^>]*>.*?<\/style>\s*/is', '', $html) ?? $html;

        if ($fullAction === self::HOME_ACTION) {
            foreach ([
                'awa-home-cls-critical-opt21',
                // r2g: NÃO stripar awa-home-critical-cls-shell — é a SSOT CLS da home
                // (carrossel categorias / viewport). Remoção fazia overflow:hidden legado vencer.
                'awa-home-critical-cls-final-lock',
                'awa-header-vtex-clean-critical-20260622',
                ] as $styleId) {
                $html = preg_replace(
                    '/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
                    '',
                    $html
                ) ?? $html;
            }

            // O bloco awa-home-cls-critical-opt21 removido acima era a ÚNICA fonte
            // inline do reset base do body no 1º paint. Sem ele, o UA default
            // body{margin:8px} fica visível até os bundles assíncronos carregarem
            // (evidência runtime e85cbc: footer.rect.left=8 / bodyScrollWidth=424
            // no parse vs 440 após ~3,4s em iPhone real). Reinjeta só o reset.
            if (strpos($html, 'awa-home-first-paint-base-reset') === false) {
                $html = preg_replace(
                    '/<head[^>]*>/i',
                    '$0<style id="awa-home-first-paint-base-reset">'
                        // H75-H79 fix: awa-super-global-20260611m.css declara
                        // "@layer awa-reset,awa-core,awa-layout,awa-components,awa-consistency,
                        // awa-fixes,awa-grid" e a regra de width do .awa-main-header__inner mora
                        // em @layer awa-fixes. Em CSS Cascade Layers, !important SEM layer tem a
                        // MENOR prioridade de todas — por isso o crítico inline (sem layer) perdia
                        // para @layer awa-fixes no first paint (evidência: mainInner width 376px
                        // no render vs 408px no dom-ready, quando o JS/CSS async de maior
                        // especificidade finalmente vence). Declarar este layer aqui, ANTES de
                        // qualquer outro @layer no documento, garante que ele seja o "primeiro
                        // declarado" — e para !important a ordem é invertida (primeiro layer
                        // declarado vence), então este layer passa a vencer awa-fixes também.
                        . '@layer awa-header-first-paint-lock;'
                        . 'html,body#html-body{margin:0!important;padding:0!important}'
                        // r44: scrollbar-gutter evita bodyW 1350→1340 (re-stamp LCP do hero).
                        // r18: overflow-y:scroll (não auto) reserva a barra desde o 1º paint.
                        // r17: gutter+thin ainda dava nav 1415→1430 @1.8s. thin muda a largura usada.
                        . 'html{overflow-x:clip;overflow-y:scroll;scrollbar-gutter:stable}'
                        . 'body{overflow-x:clip;background-color:#ffffff}'
                        // H60-H64: no 1º paint mobile da home, o form de busca em #header
                        // inicia como bloco e a .actions cai para a linha de baixo até o
                        // estado "complete"/scroll. Força grid 1fr+44px já no critical CSS.
                        . '@media(max-width:767px){'
                        . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) form#search_mini_form{display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;grid-template-areas:"field submit"!important;align-items:stretch!important;width:100%!important;min-width:0!important}'
                        . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) form#search_mini_form .field.search{grid-area:field!important;min-width:0!important}'
                        . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) form#search_mini_form .actions{grid-area:submit!important;display:flex!important;width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;justify-content:center!important;align-items:center!important;padding:0!important;margin:0!important}'
                        . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) form#search_mini_form :is(input#search,.input-text){width:100%!important;min-width:0!important;height:44px!important;min-height:44px!important;max-height:44px!important;line-height:44px!important}'
                        . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) form#search_mini_form :is(.action.search,button.action.search){width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important}'
                        // H65: first paint do iPhone ainda recebia estilo nativo (cinza/preto)
                        // em nav-toggle/search/cart; aplica aparência crítica estável.
                        . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .action.nav-toggle.awa-header-mobile-toggle{appearance:none!important;-webkit-appearance:none!important;background:#b73337!important;color:#fff!important;border:0!important;box-shadow:none!important;border-radius:0!important}'
                        // Minicart/lupa/account/Departamentos: ver awa-header-chrome-fouc-global-20260730 (GLOBAL).
                        // H75-H79: trava contrato final do header mobile já no first paint
                        // (evita salto render->dom-ready em sticky/promo/nav). Dentro do layer
                        // declarado acima para vencer @layer awa-fixes do awa-super-global.
                        . '@layer awa-header-first-paint-lock{'
                        . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-wrapper-sticky{padding-inline:16px!important;box-sizing:border-box!important;min-height:84px!important;height:96px!important;max-height:96px!important}'
                        /* BUG-SHELL-MOBILE-STICKY-OVERSIZE-001: vencer critical-stack/align-grid 112 */
                        . 'html body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
                        . '--awa-header-scroll-offset:96px!important;--awa-header-sticky-h:96px!important}'
                        . 'html:has(body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)){'
                        . '--awa-header-scroll-offset:96px!important;--awa-header-sticky-h:96px!important;'
                        . 'scroll-padding-top:96px!important;scroll-padding-block-start:96px!important}'
                        . 'html body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
                        . 'scroll-padding-top:96px!important;scroll-padding-block-start:96px!important}'
                        // H81: a promo bar (#awa-b2b-promo-bar) mora FORA de .header-wrapper-sticky,
                        // dentro de #header.header-container > .header-content. Sem regra própria
                        // aqui, .header-content nasce com padding:0 (left:0,width:440) e só ganha
                        // padding-inline:16px (left:16,width:408) quando o CSS assíncrono chega.
                        . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #header.header-container{box-sizing:border-box!important}'
                        . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #header.header-container .header-content{width:100%!important;max-width:none!important;margin:0!important;padding-inline:16px!important;box-sizing:border-box!important}'
                        . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-wrapper-sticky :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap){width:100%!important;max-width:100%!important;min-height:96px!important;height:96px!important;max-height:96px!important;margin:0!important;padding:0!important;box-sizing:border-box!important}'
                        // H80: wrapper extra ".header-main" (hífen, distinto de ".header_main"
                        // com underscore) fica ENTRE mainWrap e .container->mainInner. Evidência
                        // runtime e85cbc: cadeia real de ancestrais é header-wrapper-sticky >
                        // .header.awa-main-header > .header_main.awa-main-header-inner-wrap >
                        // .header-main > .container > .awa-main-header__inner. O CSS síncrono
                        // "awa-home-impeccable-terminal-lock" aplica nele, sem @media, width:
                        // calc(100% - 32px)+margin-left/right:16px!important (pensado para
                        // desktop) — no first paint mobile isso soma exatamente os 32px de perda
                        // de largura e 16px de deslocamento à esquerda vistos no mainInner
                        // (376px/left:32 no render vs 408px/left:16 no dom-ready).
                        . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-wrapper-sticky .header-main{width:100%!important;max-width:100%!important;margin:0!important;box-sizing:border-box!important}'
                        . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-wrapper-sticky :is(.header-main>.container,.header_main>.container){width:100%!important;max-width:none!important;margin:0!important;padding:0!important;box-sizing:border-box!important}'
                        /* PIXEL-QA B 2026-07-24: sticky já tem padding-inline:16; inner 12px somava eixo a 28. */
                        . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){width:100%!important;max-width:none!important;margin:0!important;padding-inline:0!important;box-sizing:border-box!important}'
                        . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-wrapper-sticky :is(.header-control.header-nav.awa-nav-bar,.header-control.header-nav.header-nav-global.cms_home_1,#awa-primary-navigation){display:none!important;height:0!important;min-height:0!important;max-height:0!important;margin:0!important;padding:0!important;border:0!important;overflow:hidden!important}'
                        . '}'
                        . '}'
                        . '</style>',
                    $html,
                    1
                ) ?? $html;
            }

            // PSI/CrUX CLS: ao remover opt21/shell/final-lock/vtex-clean acima,
            // o ul.togge-menu Departamentos pode pintar expandido (~6k px) até o CSS async,
            // inflando .header-wrapper-sticky e empurrando .content-top-home (lab CLS ~0.88).
            // Guard alinhado à geometria final r40c (promo 44 + sticky 118 = 162).
            // r42: skip-link é filho direto do body; hide em CSS async → first-paint ~22px
            // empurra .page-wrapper (CLS ~0.47). Hide síncrono no <head>.
            $geometryGuardCss = 'html body#html-body > :is(a.awa-skip-link,a.skip-link,a.action.skip,a.action.skip.content,'
                . 'a.action.skip.nav,a.skip-to-main-content):not(:focus):not(:focus-visible),'
                . 'html body#html-body :is(a.awa-skip-link,a.skip-link,a.action.skip,a.action.skip.content,'
                . 'a.action.skip.nav,a.skip-to-main-content):not(:focus):not(:focus-visible){'
                . 'position:fixed!important;top:0!important;left:0!important;inset:0 auto auto 0!important;'
                . 'width:1px!important;height:1px!important;min-width:1px!important;min-height:1px!important;'
                . 'max-width:1px!important;max-height:1px!important;padding:0!important;margin:0!important;'
                . 'overflow:hidden!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;'
                . 'white-space:nowrap!important;border:0!important;font-size:0!important;line-height:0!important;'
                . 'word-break:break-all!important;contain:strict!important;z-index:0!important;'
                . 'pointer-events:none!important}'
                . 'html body#html-body .page-wrapper{margin-block-start:0!important;padding-block-start:0!important;'
                . 'position:relative!important;top:0!important}'
                // 2026-09-01: unlayered full-bleed no 1º paint. max-inline-size:none vence
                // visual-fixes-terminal (max-inline-size:1280) — max-width:none não anula a lógica.
                . 'html body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
                . ' .page-wrapper :is(#maincontent,#maincontent.page-main,#maincontent.page-main.container,.page-main.container,.content-top-home){'
                . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
                . 'margin-inline:0!important;padding-inline:0!important;box-sizing:border-box!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .awa-search-helper-copy,'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .awa-search-helper-copy[aria-hidden="true"]{'
                . 'display:none!important;height:0!important;max-height:0!important;margin:0!important;padding:0!important;'
                . 'overflow:hidden!important;position:absolute!important;visibility:hidden!important;pointer-events:none!important}'
                . '#awa-header-cls-placeholder{display:none!important;height:0!important;margin:0!important;padding:0!important}'
                . 'body.awa-header-is-sticky #awa-header-cls-placeholder{display:block!important;'
                . 'height:var(--awa-header-height,var(--awa-header-sticky-h,116px))!important}'
                // r43e: !important DENTRO do primeiro @layer vence !important unlayered
                // (align-grid/themes redefinem --awa-header-nav-h:44 → sticky calc 112).
                // BUG-SHELL-CLS-GEOM-ALIGN-001 + BUG-SHELL-PROMO-COLLAPSE-ALL-VW-001:
                // nav 48 / sticky 116; stack 160 SÓ com promo aberta (fechada = 116).
                . '@layer awa-header-first-paint-lock{'
                . 'html{overflow-x:clip!important;overflow-y:scroll!important;scrollbar-gutter:stable!important;width:100%!important}'
                . '@media(min-width:992px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header{'
                . '--awa-header-promo-h:0px!important;--awa-header-main-row-h:68px!important;--awa-header-nav-h:48px!important;'
                . '--awa-nav-bar-h:48px!important;--awa-header-sticky-h:116px!important;--awa-header-stack-h:116px!important;'
                . 'box-sizing:border-box!important;min-height:116px!important;'
                . 'height:auto!important;max-height:none!important;overflow:visible!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header:has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
                . '--awa-header-promo-h:44px!important;--awa-header-stack-h:160px!important;'
                . 'min-height:160px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .header-wrapper-sticky{'
                . 'box-sizing:border-box!important;display:flex!important;flex-direction:column!important;'
                . 'height:116px!important;min-height:116px!important;max-height:116px!important;'
                . 'overflow:visible!important;padding:0!important;margin:0!important}'
                /* BUG-SHELL-PROMO-GAP-001 2026-08-10: NÃO lockar #header/.header-content em 44px
                   sem promo aberta — !important nesta @layer vence o collapse unlayered do r40c
                   e deixava vão 44px após dismiss (runtime: stickyTop=44 com aria-hidden=true). */
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(#awa-b2b-promo-bar:not([aria-hidden="true"]),.awa-b2b-promo-bar:not([aria-hidden="true"]),.top-header.awa-b2b-promo-bar:not([aria-hidden="true"])){'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header:has(#awa-b2b-promo-bar:not([aria-hidden="true"]))'
                . ' :is(#header.header-container,#header.header-container .header-content){'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(#awa-b2b-promo-bar:not([aria-hidden="true"]),.awa-b2b-promo-bar:not([aria-hidden="true"]),.header-content:has(#awa-b2b-promo-bar:not([aria-hidden="true"]))){'
                . 'box-sizing:border-box!important;height:var(--awa-header-promo-h)!important;'
                . 'min-height:var(--awa-header-promo-h)!important;max-height:var(--awa-header-promo-h)!important}'
                /* Collapse na MESMA @layer (source order) — unlayered r40c não vence !important layered. */
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header:has(#awa-b2b-promo-bar[aria-hidden="true"])'
                . ' :is(#header.header-container,#header.header-container .header-content),'
                . 'html.awa-b2b-promo-dismissed body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(#header.header-container,#header.header-container .header-content),'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' #header.header-container.awa-b2b-promo-shell--collapsed,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' #header.header-container.awa-b2b-promo-shell--collapsed .header-content{'
                . 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;'
                . 'border:0!important;overflow:hidden!important;line-height:0!important}'
                // sticky 116px literal (10×ID acima) — não usar var(--awa-header-sticky-h) aqui:
                // align-grid redefine o token para calc(68+44)=112 e reabria o CLS.
                // r43: themes/home stack aplica padding-block:var(--awa-space-2,8px) no shell
                // até o CSS async zerar → .header-main pinta em y:53 e depois sobe para y:44 (CLS ~0.038).
                // r55c: NÃO incluir .awa-main-header__inner em margin:0 — isso prendia o rail em x=0
                // enquanto .awa-nav-bar__inner centrava (evidência: main left=0, nav left≈111 @1512).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,'
                . '.header-main>.container){'
                . 'box-sizing:border-box!important;height:var(--awa-header-main-row-h)!important;'
                . 'min-height:var(--awa-header-main-row-h)!important;max-height:var(--awa-header-main-row-h)!important;'
                . 'padding:0!important;padding-block:0!important;padding-top:0!important;padding-bottom:0!important;'
                . 'margin:0!important;width:100%!important;max-width:none!important;overflow:visible!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar){'
                . 'box-sizing:border-box!important;width:100%!important;max-width:none!important;max-inline-size:none!important;'
                . 'margin:0!important;margin-inline:0!important;padding-inline:0!important;'
                . 'background:var(--awa-primary,#b73337)!important;background-color:var(--awa-primary,#b73337)!important;background-image:none!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .header-control.header-nav.awa-nav-bar>.container,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .header-control.awa-nav-bar>.container{'
                . 'box-sizing:border-box!important;width:100%!important;max-width:none!important;max-inline-size:none!important;'
                . 'margin:0!important;padding:0!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
                . 'display:block!important;gap:0!important;column-gap:0!important;background:transparent!important;'
                . 'height:var(--awa-header-nav-h,48px)!important;'
                . 'min-height:var(--awa-header-nav-h,48px)!important;max-height:var(--awa-header-nav-h,48px)!important;'
                . 'overflow:visible!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container)::before,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container)::after{'
                . 'content:none!important;display:none!important;width:0!important;height:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
                . 'box-sizing:border-box!important;height:var(--awa-header-main-row-h)!important;'
                . 'min-height:var(--awa-header-main-row-h)!important;max-height:var(--awa-header-main-row-h)!important;'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;max-inline-size:1280px!important;'
                . 'margin-inline:auto!important;'
                . 'padding-block:0!important;padding-inline:24px!important;overflow:visible!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header :is(.header-control.header-nav,.header-control.awa-nav-bar,.awa-nav-bar,.awa-nav-bar__inner){'
                . 'box-sizing:border-box!important;height:var(--awa-header-nav-h)!important;'
                . 'min-height:var(--awa-header-nav-h)!important;max-height:var(--awa-header-nav-h)!important;overflow:visible!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header :is(.awa-header-categories,.awa-nav-categories,.sections.nav-sections.category-dropdown,.section-items.nav-sections.category-dropdown-items,.section-item-content.nav-sections.category-dropdown-item-content,.navigation.verticalmenu.side-verticalmenu){'
                . 'box-sizing:border-box!important;height:44px!important;min-height:0!important;max-height:44px!important;'
                . 'margin:0!important;padding:0!important;overflow:visible!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header ul.togge-menu.list-category-dropdown:not([data-awa-menu-state="open"]):not(.vmm-open):not(.menu-open),'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header ul.togge-menu.list-category-dropdown[data-awa-menu-state="closed"],'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header ul.togge-menu.list-category-dropdown[aria-hidden="true"]{'
                . 'display:none!important;visibility:hidden!important;opacity:0!important;height:0!important;max-height:0!important;'
                . 'margin:0!important;padding:0!important;overflow:hidden!important;pointer-events:none!important;'
                . 'position:absolute!important;inset-block-start:100%!important;inset-inline-start:0!important}'
                . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home{'
                . 'margin-block-start:0!important}'
                // r43c: categoria encolhe 338→295 tarde e empurra o featured (~0.034 CLS).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel{'
                . 'min-height:220px!important;padding:0!important;padding-block:0!important;'
                . 'box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel > .container{'
                . 'width:100%!important;max-width:100%!important;height:auto!important;'
                . 'min-height:0!important;box-sizing:border-box!important;'
                . 'gap:12px!important;row-gap:12px!important;column-gap:12px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel :is(.awa-category-carousel__header,.awa-section-header){'
                . 'min-height:44px!important;height:44px!important;max-height:44px!important;padding-block:0!important;box-sizing:border-box!important;'
                . 'margin-bottom:0!important;margin-block-end:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.header-control.header-nav,.header-control.awa-nav-bar,.awa-nav-bar){'
                . 'height:48px!important;min-height:48px!important;max-height:48px!important;box-sizing:border-box!important;'
                . 'width:100%!important;max-width:100%!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.header-control.header-nav,.header-control.awa-nav-bar,.awa-nav-bar)> .container{'
                . 'height:48px!important;min-height:48px!important;max-height:48px!important;box-sizing:border-box!important;'
                . 'display:flex!important;align-items:center!important}'
                // r49: quick-links → nav 48 (BUG-SHELL-CLS-GEOM-ALIGN-001) + promo text 17→31 @~900ms (pré-themes)
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.awa-nav-quick-links,.awa-nav-quick-links__list){'
                . 'height:48px!important;min-height:48px!important;max-height:48px!important;box-sizing:border-box!important;overflow:hidden!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-b2b-promo-bar__text,#awa-b2b-promo-bar .awa-b2b-promo-bar__text){'
                . 'min-height:31px!important;line-height:1.45!important;display:flex!important;align-items:center!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-category-carousel{'
                . 'position:relative!important;top:0!important;margin:0!important;'
                . 'min-height:148px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-category-carousel__viewport{'
                . 'margin:0!important;margin-top:0!important;'
                . 'min-height:148px!important;box-sizing:border-box!important}'
                // r50/r76: search/brand — pad FINAL 24px (antes 16→24 @~885ms = CLS ~0.10).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
                . 'display:grid!important;grid-template-columns:160px minmax(0,1fr) 272px!important;'
                . 'grid-template-areas:"brand search actions"!important;column-gap:28px!important;'
                . 'height:68px!important;min-height:68px!important;max-height:68px!important;'
                . 'padding:0 24px!important;padding-inline:24px!important;padding-block:0!important;'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;max-inline-size:1280px!important;'
                . 'margin-inline:auto!important;'
                . 'box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-brand-cell{'
                . 'width:160px!important;min-width:160px!important;max-width:160px!important;'
                . 'height:68px!important;min-height:68px!important;max-height:68px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-search-col{'
                . 'height:68px!important;min-height:68px!important;max-height:68px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-right-col{'
                . 'width:272px!important;min-width:272px!important;max-width:272px!important;'
                . 'height:68px!important;min-height:68px!important;max-height:68px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-nav-bar__inner{'
                . 'height:48px!important;min-height:48px!important;max-height:48px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home{'
                . 'width:100%!important;max-width:100%!important;box-sizing:border-box!important}'
                // r74/r75: first-paint = faixa height:0 do deferred-stack (não inset:0).
                // inset:0 fazia o nav acompanhar altura do carrossel (277→249) e inflava CLS.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-shelf--carousel .awa-carousel{'
                . 'position:relative!important;box-sizing:border-box!important}'
                . '@media(min-width:768px){'
                /* neutralizado na Onda 6B.3 — geometria das setas pertence ao align-grid-terminal */
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-shelf--carousel'
                . ' :is(.awa-owl-nav.awa-carousel__nav,.awa-owl-nav.awa-carousel-chrome-ssr,'
                . '.awa-carousel__nav.awa-carousel-chrome-ssr,'
                . '.awa-owl-nav[data-awa-nav-anchor="viewport"]){'
                // 6B.3: removidos top/inset/height:0/transform — SSOT = align-grid-terminal
                . 'position:absolute!important;left:0!important;right:0!important;'
                . 'display:flex!important;justify-content:space-between!important;align-items:center!important;'
                . 'width:auto!important;margin:0!important;padding:0!important;'
                . 'pointer-events:none!important;z-index:4!important;box-sizing:border-box!important;'
                . 'background:transparent!important;border:0!important;overflow:visible!important}'
                // r77: absolute nas laterais (prev/next) + toggle no canto — sem top/transform das setas
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-shelf--carousel .awa-owl-nav'
                . ' :is(.awa-owl-nav__btn,.awa-carousel__arrow,.awa-carousel__button){'
                // 6B.3: removidos top:50%/transform/inset — SSOT = align-grid-terminal
                . 'position:absolute!important;margin:0!important;pointer-events:auto!important;'
                . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
                . 'box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-shelf--carousel .awa-owl-nav'
                . ' :is(.awa-owl-nav__btn--prev,.awa-carousel__arrow--prev,.awa-carousel__button--prev){'
                . 'left:max(6px,env(safe-area-inset-left,0px))!important;right:auto!important;'
                . 'inset-inline-start:max(6px,env(safe-area-inset-left,0px))!important;inset-inline-end:auto!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-shelf--carousel .awa-owl-nav'
                . ' :is(.awa-owl-nav__btn--next,.awa-carousel__arrow--next,.awa-carousel__button--next){'
                . 'right:max(6px,env(safe-area-inset-right,0px))!important;left:auto!important;'
                . 'inset-inline-end:max(6px,env(safe-area-inset-right,0px))!important;inset-inline-start:auto!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-shelf--carousel .awa-owl-nav .awa-carousel__toggle{'
                . 'top:4px!important;right:max(6px,env(safe-area-inset-right,0px))!important;'
                . 'left:auto!important;inset-inline-end:max(6px,env(safe-area-inset-right,0px))!important;'
                . 'inset-inline-start:auto!important;transform:none!important}'
                . '}'
                // r75: categoria — contain:size + height:auto = título 0px (CLS 60→18).
                // First-paint = densidade final desktop (16/12), sem contain:size.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel .awa-section-header__left{'
                . 'display:flex!important;flex-direction:column!important;gap:2px!important;'
                . 'min-height:36px!important;height:auto!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel .awa-section-header__title{'
                . 'contain:none!important;min-height:1.25em!important;height:auto!important;max-height:none!important;'
                . 'margin:0!important;line-height:1.25!important;font-size:20px!important;'
                . 'overflow:visible!important;white-space:nowrap!important;text-overflow:ellipsis!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel :is(.awa-section-header__subtitle,.awa-category-carousel__subtitle){'
                . 'contain:none!important;min-height:1.3em!important;height:auto!important;max-height:none!important;'
                . 'margin:0!important;line-height:1.3!important;font-size:12px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel .awa-category-carousel__icon,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel .awa-category-carousel__icon :is(picture,img,svg){'
                . 'width:88px!important;height:88px!important;min-width:88px!important;min-height:88px!important;'
                . 'max-width:88px!important;max-height:88px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel .awa-category-carousel__icon{'
                . 'background:var(--awa-bg-muted,var(--awa-bg-soft))!important;isolation:isolate!important;'
                . 'border-radius:var(--awa-radius-sm,6px)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel .awa-category-carousel__icon img{'
                . 'mix-blend-mode:multiply!important;opacity:1!important;visibility:visible!important;'
                . 'object-fit:contain!important;animation:none!important}'
                // r69: progress track fino (4px), independente do chrome nav 32px
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-owl-progress.awa-carousel-chrome-ssr,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-shelf--carousel .awa-owl-progress{'
                . 'height:4px!important;min-height:4px!important;max-height:4px!important;'
                . 'margin-block-start:12px!important;overflow:hidden!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-owl-progress .awa-owl-progress__bar{height:100%!important;max-height:4px!important}'
                // r71: niche — absolute left of panel Ver todos (r69 relative overlapped prev)
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-home-niche-shelves .awa-carousel__toggle:not([hidden]){'
                . 'top:-52px!important;bottom:auto!important;right:125px!important;left:auto!important;'
                . 'position:absolute!important;transform:none!important;z-index:7!important}'
                // r62: trava explícita — root .awa-shelf/.rokan-* com chrome-ssr NUNCA herda 32px
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-shelf,.rokan-newproduct,.rokan-bestseller,.awa-grid-section--featured .awa-shelf)'
                . '.awa-carousel-chrome-ssr{'
                . 'height:auto!important;min-height:0!important;max-height:none!important;'
                . 'max-block-size:none!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-search-col,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-search-col.top-search{'
                // r52: grid track já era 744px mas width:auto mantinha 261→744 @~2.5s
                . 'width:100%!important;min-width:0!important;max-width:none!important;'
                . 'justify-self:stretch!important;align-self:center!important;'
                . 'flex:1 1 auto!important;grid-area:search!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-search-col :is(.block-search,.block-content,form#search_mini_form){'
                . 'width:100%!important;max-width:100%!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(#awa-b2b-promo-bar,.header-content){padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout){'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;margin-inline:auto!important;'
                . 'padding-inline:var(--awa-grid-container-pad,16px)!important;padding-right:52px!important;box-sizing:border-box!important;'
                . 'display:flex!important;align-items:center!important;justify-content:center!important}'
                // r51b: ayo-home5-wrapper 1355→1280 @~2.6s (CLS ~0.11)
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.ayo-home5-wrapper,.ayo-home5-wrapper--template-driven){'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;'
                . 'margin-inline:auto!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-hero__pagination,.swiper-pagination.awa-hero__pagination){'
                . 'height:auto!important;min-height:0!important;max-height:none!important;'
                . 'padding-block:0!important;background:transparent!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-nav-bar__inner{'
                . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
                . 'margin-inline:0!important;padding-inline:24px!important;background:transparent!important;box-sizing:border-box!important}'
                // r58 CLS: items clamp(112px,10vw,152px)=135 @1350 → 144 late (CLS ~0.016).
                // Shell 144 perdia para deferred-stack/themes (flex:0 0 auto + width fluid).
                . '@media(min-width:768px){html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(#awa-cat-carousel .awa-category-carousel__item,#awa-cat-carousel>.awa-category-carousel__item,'
                . '.top-home-content--category-carousel .awa-category-carousel__item){'
                . 'flex:0 0 calc((100% - 4 * 12px) / 5)!important;flex-grow:0!important;flex-shrink:0!important;flex-basis:calc((100% - 4 * 12px) / 5)!important;'
                . 'width:calc((100% - 4 * 12px) / 5)!important;min-width:0!important;max-width:calc((100% - 4 * 12px) / 5)!important;'
                . 'box-sizing:border-box!important}}'
                . '@media(min-width:992px){html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(#awa-cat-carousel .awa-category-carousel__item,#awa-cat-carousel>.awa-category-carousel__item,'
                . '.top-home-content--category-carousel .awa-category-carousel__item){'
                . 'flex:0 0 calc((100% - 7 * 12px) / 8)!important;flex-grow:0!important;flex-shrink:0!important;flex-basis:calc((100% - 7 * 12px) / 8)!important;'
                . 'width:calc((100% - 7 * 12px) / 8)!important;min-width:0!important;max-width:calc((100% - 7 * 12px) / 8)!important;'
                . 'box-sizing:border-box!important}}'
                // r59 CLS: shelf padding 12→0 + container 1248→1232 @~2.5s (CLS ~0.006).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home :is(.awa-carousel-section,.awa-carousel-section--featured,'
                . '.top-home-content.awa-carousel-section){'
                . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
                . 'margin-block:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home :is(.awa-carousel-section,.awa-carousel-section--featured)> .container{'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;margin-inline:auto!important;'
                . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;padding-block:0!important;box-sizing:border-box!important}'
                // 20260818: Mais Vendidos — gutter no TRACK; scroll-padding igual
                // para o snap nativo não comer o espaço das setas (BUG-2463a2).
                . '@media(min-width:768px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-carousel-section--featured .awa-carousel__track{'
                . 'box-sizing:content-box!important;'
                . 'padding-inline:calc(var(--awa-touch-target,44px) + 8px)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .content-top-home .awa-carousel-section--featured .awa-carousel__viewport{'
                . 'scroll-padding-inline:calc(var(--awa-touch-target,44px) + 8px)!important}}'
                // r59: quick-links + account prompt residual width/height churn
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-nav-quick-links{'
                . 'min-height:46px!important;height:46px!important;max-height:46px!important;'
                . 'min-width:410px!important;width:auto!important;max-width:none!important;'
                . 'box-sizing:border-box!important;overflow:visible!important}'
                // r59b/r73b: prefixo DEVE repetir após vírgula — senão __guest fica só `.class` e perde p/ themes/align-grid (gap:1px/overflow:hidden).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-account-prompt__text,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-account-prompt__guest,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__guest{'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
                . 'line-height:1.2!important;overflow:hidden!important;box-sizing:border-box!important;'
                . 'display:flex!important;flex-direction:column!important;justify-content:center!important;'
                . 'align-items:flex-start!important;gap:2px!important;row-gap:2px!important}'
                /* 2026-08-12: guest shrink-wrap (vence critical-shell 220) */
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-contact-links.awa-header-account-prompt,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-account-prompt[data-awa-auth-state="guest"]{'
                . 'flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:230px!important;overflow:hidden!important;gap:8px!important;height:50px!important;min-height:50px!important;max-height:50px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-account-prompt__line2{gap:6px!important;overflow:visible!important;min-height:0!important;height:auto!important;max-height:none!important;flex-wrap:nowrap!important;white-space:nowrap!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' .awa-header-right-col{gap:10px!important;max-width:none!important}'
                // r60 CLS: logo 104→77→126 @~1–3s. Lock contrato shell 104×44.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.logo,.awa-header-brand-cell .logo){'
                . 'width:104px!important;min-width:104px!important;max-width:104px!important;'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
                . 'display:flex!important;align-items:center!important;overflow:hidden!important;'
                . 'box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(.logo img,.awa-header-brand-cell .logo img,.awa-header-brand-cell img){'
                . 'width:104px!important;min-width:104px!important;max-width:104px!important;'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
                . 'object-fit:contain!important;aspect-ratio:auto!important;display:block!important;'
                . 'box-sizing:border-box!important}'
                // r60 legado: height:32 em fluxo no owl-nav. r74: shelves de produto usam
                // overlay viewport — NÃO reaplicar 32px nelas (conflitaria com absolute).
                // Mantém reserva só em featured grid / carrosséis sem data-awa-nav-anchor.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-grid-section--featured .awa-owl-nav.awa-carousel__nav:not([data-awa-nav-anchor="viewport"]){'
                . 'min-height:32px!important;height:32px!important;max-height:32px!important;box-sizing:border-box!important}'
                // BUG-SHELL-HOME-HTML-TOKENS-001: skip do nonhome shell removeu sync em <html>.
                // Probe H5 (vw=1600): sh=68px com header 160px — scroll/placeholder usam :root.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
                . '--awa-header-scroll-offset:116px!important;--awa-header-sticky-h:116px!important;'
                . '--awa-header-stack-h:116px!important}'
                . 'html:has(body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)){'
                . '--awa-header-scroll-offset:116px!important;--awa-header-sticky-h:116px!important;'
                . '--awa-header-stack-h:116px!important;'
                . 'scroll-padding-top:116px!important;scroll-padding-block-start:116px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):has(.page-wrapper .awa-site-header #awa-b2b-promo-bar:not([aria-hidden="true"])){'
                . '--awa-header-scroll-offset:160px!important;--awa-header-stack-h:160px!important}'
                . 'html:has(body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)):has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
                . '--awa-header-scroll-offset:160px!important;--awa-header-stack-h:160px!important;'
                . 'scroll-padding-top:160px!important;scroll-padding-block-start:160px!important}'
                . '}' // fecha @media(min-width:992px)
                // BUG-SHELL-MOBILE-STICKY-OVERSIZE-001 + BUG-SHELL-NONHOME-MIN120-001:
                // HPOL5/align-grid força stack=promo32+main88=120 mesmo com promo fechada
                // → faixa ~22–38px sob a busca em PLP/cart/PDP (home já tinha override).
                // SSOT mobile: sticky 96; promo aberta 44+96=140; tokens stack/promo alinhados.
                . '@media(max-width:767px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
                . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"],'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header{'
                . 'height:auto!important;min-height:96px!important;max-height:none!important;'
                . '--awa-header-main-row-h:96px!important;--awa-header-sticky-h:96px!important;'
                . '--awa-header-promo-h:0px!important;--awa-header-stack-h:96px!important;'
                . 'overflow:visible!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
                . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
                . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])),'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header:has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
                . '--awa-header-promo-h:44px!important;--awa-header-stack-h:140px!important;'
                . 'min-height:140px!important}'
                /* mensagens vazias: mt/mb 8px somavam ~16px (home: dentro de #maincontent, não filho direto). */
                . 'html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index)'
                . ' .page-wrapper > .page.messages:not(:has(.message)),'
                . 'html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index)'
                . ' .page-wrapper #maincontent .page.messages:not(:has(.message)),'
                . 'html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index)'
                . ' .page-wrapper .page-main > .page.messages:not(:has(.message)){'
                . 'display:none!important;margin:0!important;padding:0!important;'
                . 'height:0!important;min-height:0!important;overflow:hidden!important}'
                /* sticky ativo: shell colapsa; placeholder CLS ocupa o slot (evita 120+120 no cart) */
                . 'html body#html-body#html-body.awa-header-is-sticky:not(.checkout-index-index):not(.onepagecheckout-index-index)'
                . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]{'
                . 'min-height:0!important;height:auto!important;max-height:none!important;'
                . '--awa-header-stack-h:var(--awa-header-sticky-h,96px)!important}'
                . '}'
                . '@media(max-width:767px){'
                . 'html body#html-body#html-body:not(.awa-account-operational):not(.b2b-account-shell):not(.b2b-auth-shell){'
                . '--awa-header-scroll-offset:96px!important;--awa-header-sticky-h:96px!important}'
                . 'html:has(body#html-body:not(.awa-account-operational):not(.b2b-account-shell):not(.b2b-auth-shell)){'
                . '--awa-header-scroll-offset:96px!important;'
                . 'scroll-padding-top:96px!important;scroll-padding-block-start:96px!important}'
                . '}' /* BUG-SHELL-SCROLL-OFFSET-GLOBAL-MOBILE-001 */
                . '@media(min-width:768px) and (max-width:991px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header{'
                . 'height:auto!important;min-height:112px!important;max-height:none!important;'
                . '--awa-header-promo-h:0px!important;--awa-header-sticky-h:112px!important;'
                . '--awa-header-stack-h:112px!important;'
                . 'overflow:visible!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header:has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
                . '--awa-header-promo-h:44px!important;--awa-header-stack-h:156px!important;'
                . 'min-height:156px!important}'
                . '}'
                . '@media(max-width:991px){'
                /* BUG-SHELL-PROMO-GAP-001: shell 44 só com promo aberta; collapse na mesma @layer. */
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(#awa-b2b-promo-bar:not([aria-hidden="true"]),.awa-b2b-promo-bar:not([aria-hidden="true"]),.top-header.awa-b2b-promo-bar:not([aria-hidden="true"])){'
                . 'position:relative!important;display:flex!important;top:0!important;inset-block-start:0!important;'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;box-sizing:border-box!important;'
                . 'overflow:hidden!important;margin:0!important;margin-block:0!important;padding-block:0!important;'
                . 'transform:none!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header:has(#awa-b2b-promo-bar:not([aria-hidden="true"]))'
                . ' :is(#header.header-container,#header.header-container .header-content,.header-content:has(#awa-b2b-promo-bar:not([aria-hidden="true"]))){'
                . 'position:relative!important;display:flex!important;top:0!important;inset-block-start:0!important;'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;box-sizing:border-box!important;'
                . 'overflow:hidden!important;margin:0!important;margin-block:0!important;padding-block:0!important;'
                . 'transform:none!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header:has(#awa-b2b-promo-bar[aria-hidden="true"])'
                . ' :is(#header.header-container,#header.header-container .header-content),'
                . 'html.awa-b2b-promo-dismissed body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' :is(#header.header-container,#header.header-container .header-content),'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' #header.header-container.awa-b2b-promo-shell--collapsed,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header'
                . ' #header.header-container.awa-b2b-promo-shell--collapsed .header-content{'
                . 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;'
                . 'border:0!important;overflow:hidden!important;line-height:0!important;display:block!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-b2b-promo-bar__text,#awa-b2b-promo-bar .awa-b2b-promo-bar__text){'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;line-height:1.25!important;'
                . 'display:flex!important;align-items:center!important;overflow:hidden!important;box-sizing:border-box!important}'
                . '}'
                . '@media(max-width:767px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .header-wrapper-sticky{'
                . 'position:relative!important;height:96px!important;min-height:96px!important;max-height:96px!important;'
                /* Gutter 16: padding:0 nesta @layer vence unlayered (busca x=1 vs cart x=29). */
                . 'margin:0!important;padding-block:0!important;padding-inline:16px!important;'
                . 'padding-left:16px!important;padding-right:16px!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
                . '--awa-header-scroll-offset:96px!important;--awa-header-sticky-h:96px!important}'
                . 'html:has(body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)){'
                . '--awa-header-scroll-offset:96px!important;--awa-header-sticky-h:96px!important;'
                . 'scroll-padding-top:96px!important;scroll-padding-block-start:96px!important}'
                . '}'
                . '@media(min-width:768px) and (max-width:991px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .header-wrapper-sticky{'
                . 'position:relative!important;height:112px!important;min-height:112px!important;max-height:112px!important;'
                . 'margin:0!important;padding:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
                . '--awa-header-scroll-offset:128px!important;--awa-header-sticky-h:112px!important}'
                . 'html:has(body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)){'
                . '--awa-header-scroll-offset:128px!important;'
                . 'scroll-padding-top:128px!important;scroll-padding-block-start:128px!important}'
                . '}'
                // r75: desktop first-paint = density 220 (evita 338→220 no featured carousel CLS).
                . '@media(min-width:992px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel{'
                . 'min-height:220px!important;height:auto!important;max-height:none!important;'
                . 'padding:0!important;padding-block:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel > .container{'
                . 'min-height:0!important;height:auto!important;max-height:none!important;'
                . 'box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel :is(.awa-category-carousel__header,.awa-section-header){'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
                . 'padding:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-category-carousel,.awa-category-carousel__viewport){'
                . 'min-height:148px!important}'
                . '}'
                . '@media(max-width:991px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home{'
                . 'margin-block-start:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel{'
                . 'min-height:220px!important;max-height:220px!important;height:220px!important;'
                . 'padding:0!important;padding-block:0!important;padding-top:0!important;padding-bottom:0!important;'
                . 'box-sizing:border-box!important;overflow:hidden!important}'
                // r33 1426e1: cat 338→220 (conteúdo ~223px; void ~115px). Header 60→48.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel :is(.awa-category-carousel__header,.awa-section-header){'
                . 'height:48px!important;min-height:48px!important;max-height:48px!important;'
                . 'padding:0!important;padding-block:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel > .container{'
                . 'height:220px!important;min-height:220px!important;max-height:220px!important;'
                . 'box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout){'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
                . 'box-sizing:border-box!important;align-items:center!important}'
                // r65c: banners mobile ~2.4:1 (~163px @390w). Shell 288+contain = void ~125px.
                // 176 só no slider mobile (.visible-xs). Tablet/desktop (hidden-xs) usa
                // aspect 1920/472 (24.5vw): o lock 176 em ≤991px cortava o hero full-bleed
                // (evidência 86af0b: viewport 956 → hero 176 vs img 237).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.top-home-content--above-fold,.top-home-content--above-fold>.banner-slider.banner-slider2){'
                . 'height:clamp(176px,24.5vw,472px)!important;min-height:clamp(176px,24.5vw,472px)!important;'
                . 'max-height:clamp(176px,24.5vw,472px)!important;'
                . 'padding-block:0!important;box-sizing:border-box!important;overflow:hidden!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs :is(.awa-hero-swiper,.awa-hero-swiper.swiper-initialized,'
                . '.awa-hero-swiper.awa-hero-swiper-ready,.banner_item_bg,.swiper-slide){'
                . 'height:176px!important;min-height:176px!important;max-height:176px!important;'
                . 'box-sizing:border-box!important;overflow:hidden!important;position:relative!important}'
                // r64 FIX: critical-home força .swiper-wrapper{display:block} no mobile → slides
                // empilham fora do shell (overflow:hidden) = faixa branca. Swiper precisa de flex.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs :is(.awa-hero-swiper .swiper-wrapper,.swiper-wrapper){'
                . 'display:flex!important;flex-direction:row!important;align-items:stretch!important;'
                . 'height:176px!important;min-height:176px!important;max-height:176px!important;'
                . 'width:100%!important;box-sizing:border-box!important;overflow:hidden!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs :is(.swiper-slide,.banner_item.swiper-slide,.awa-hero__slide){'
                . 'flex:0 0 100%!important;width:100%!important;max-width:100%!important;'
                . 'height:176px!important;min-height:176px!important;max-height:176px!important;'
                . 'box-sizing:border-box!important;overflow:hidden!important}'
                // r65/r65e: shell 176 ≈ aspect banner mobile. cover em 176 corta ~17px/lado
                // (em 288 cortava ~150px). Preenche slide Guidão (asset 390x95) sem void.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs :is(.banner_item_bg,.awa-hero__slide .banner_item_bg){'
                . 'display:block!important;background:#0b1220!important;background-color:#0b1220!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs :is(.banner_item_bg img,.banner_item_bg picture,img){'
                . 'display:block!important;width:100%!important;height:100%!important;'
                . 'max-height:176px!important;min-height:0!important;'
                . 'object-fit:cover!important;object-position:left center!important;box-sizing:border-box!important}'
                // r67b: left center preserva headline do banner (cover centrado cortava "GUIDÃO").
                // r65e: prev@bottom-left cobria CTA B2B (círculo escuro no botão). Esconde setas;
                // swipe + dots bastam no mobile.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs :is(.swiper-button-prev,.swiper-button-next,'
                . '.awa-hero-swiper__nav,.awa-hero__prev,.awa-hero__next){'
                . 'display:none!important;visibility:hidden!important;pointer-events:none!important;'
                . 'width:0!important;height:0!important;opacity:0!important}'
                // r65e: dots bottom-right — longe do CTA (esq) e do headline (topo-esq).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs :is(.awa-hero__pagination,.swiper-pagination.awa-hero__pagination,'
                . '.swiper-pagination){'
                . 'position:absolute!important;inset:auto 12px 8px auto!important;top:auto!important;bottom:8px!important;'
                . 'left:auto!important;right:12px!important;transform:none!important;'
                . 'height:28px!important;min-height:28px!important;'
                . 'max-height:28px!important;width:auto!important;max-width:40%!important;margin:0!important;'
                . 'display:flex!important;align-items:center!important;justify-content:flex-end!important;'
                . 'gap:8px!important;z-index:4!important;box-sizing:border-box!important;'
                . 'visibility:visible!important;opacity:1!important;pointer-events:auto!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .wrapper_slider.visible-xs .awa-hero-pause-btn{'
                . 'position:absolute!important;top:auto!important;bottom:12px!important;left:auto!important;'
                . 'right:16px!important;width:44px!important;height:44px!important;z-index:3!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper #awa-cat-dots{'
                . 'min-height:44px!important;height:44px!important;box-sizing:border-box!important}'
                // r52: carousel position static→relative @~3.3s move y 528→517 (CLS ~0.064)
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-category-carousel{'
                . 'position:relative!important;top:0!important;left:0!important;'
                . 'margin:0!important;margin-block:0!important;'
                . 'min-height:0!important;height:auto!important;max-height:none!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-category-carousel__viewport{'
                . 'margin:0!important;margin-top:0!important;min-height:0!important;height:auto!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel :is(.awa-category-carousel__header,.awa-section-header){'
                . 'margin:0!important;margin-block:0!important}'
                // r58/r75 mobile CLS: title — sem contain:size (colapsa com height:auto).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel .awa-section-header__title{'
                . 'contain:none!important;min-height:36px!important;height:36px!important;max-height:36px!important;'
                . 'line-height:36px!important;font-size:22px!important;font-weight:700!important;'
                . 'padding:0!important;padding-block:0!important;margin:0!important;'
                . 'width:100%!important;max-width:100%!important;box-sizing:border-box!important;'
                . 'overflow:hidden!important;white-space:nowrap!important;text-overflow:ellipsis!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel :is(.awa-section-header__subtitle,.awa-category-carousel__subtitle){'
                . 'min-height:20px!important;height:20px!important;max-height:20px!important;'
                . 'line-height:20px!important;font-size:13px!important;overflow:hidden!important;'
                . 'white-space:nowrap!important;text-overflow:ellipsis!important;box-sizing:border-box!important;'
                . 'margin:0!important;padding:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel .awa-category-carousel__label{'
                . 'display:block!important;width:100%!important;max-width:100%!important;'
                . 'min-height:15px!important;height:15px!important;max-height:15px!important;'
                . 'line-height:15px!important;font-size:12px!important;font-weight:600!important;'
                . 'font-family:"Source Sans 3",system-ui,sans-serif!important;'
                . 'color:var(--awa-text,#111827)!important;text-decoration:none!important;'
                . 'overflow:hidden!important;white-space:nowrap!important;text-overflow:ellipsis!important;'
                . 'box-sizing:border-box!important;margin:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-category-carousel__item,#awa-cat-carousel .awa-category-carousel__item){'
                . 'flex:0 0 136px!important;width:136px!important;min-width:136px!important;max-width:160px!important;'
                . 'box-sizing:border-box!important}'
                . '}' // fecha @media(max-width:991px)
                . '}' // fecha @layer awa-header-first-paint-lock
                // r24/AA: Magento styles-l html{overflow-x:hidden} vencia o first-paint-reset
                // sem !important e deslocava a nav 1415→1430 @~2.1s.
                . 'html{overflow-x:clip!important;overflow-y:scroll!important;scrollbar-gutter:stable!important;width:100%!important;max-width:none!important}'
                . 'html body#html-body{overflow-x:clip!important;width:100%!important;max-width:none!important}'
                // r63: unlock UNLAYERED (vence qualquer regra layered residual) + strip via JS
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.awa-shelf,.rokan-newproduct,.rokan-bestseller).awa-carousel-chrome-ssr{'
                . 'height:auto!important;min-height:0!important;max-height:none!important;'
                . 'max-block-size:none!important;box-sizing:border-box!important}'
                // r65: labels de categoria herdam azul de link do <a> (rgb(0,0,238)). Força tokens AWA.
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel a.awa-category-carousel__item,'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel a.awa-category-carousel__item:link,'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel a.awa-category-carousel__item:visited{'
                . 'color:var(--awa-text,#111827)!important;text-decoration:none!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel a.awa-category-carousel__item'
                . ' :is(.awa-category-carousel__label,.awa-category-carousel__count){'
                . 'color:inherit!important;text-decoration:none!important}'
                . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--category-carousel a.awa-category-carousel__item .awa-category-carousel__count{'
                . 'color:var(--awa-text-secondary,#6b7280)!important}'
                // r73b UNLAYERED: guest gap/overflow — regras layered perdem p/ themes+align-grid unlayered.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-account-prompt[data-awa-auth-state="guest"]'
                . ' .awa-header-account-prompt__guest,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-account-prompt[data-awa-auth-state="guest"]'
                . ' .awa-header-account-prompt__text{'
                . 'gap:2px!important;row-gap:2px!important;overflow:visible!important;'
                . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
                . 'display:flex!important;flex-direction:column!important;justify-content:center!important;'
                . 'align-items:flex-start!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-contact-links.awa-header-account-prompt,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-account-prompt[data-awa-auth-state="guest"]{'
                . 'flex:0 0 auto!important;width:max-content!important;max-width:none!important;min-width:230px!important;overflow:hidden!important;gap:8px!important;height:50px!important;min-height:50px!important;max-height:50px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-account-prompt__line2{'
                . 'gap:6px!important;overflow:visible!important;min-height:0!important;height:auto!important;max-height:none!important;flex-wrap:nowrap!important;white-space:nowrap!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-right-col{'
                . 'gap:10px!important;max-width:none!important}'
                // r76d/H12 CLS: banner_item 420→335 @~3s + late hero 339→313 (aspect 1920/488 height:auto).
                // 86af0b: lock fluido 1920/472 (24.5vw) — 339px fixo cortava ou esticava fora do asset.
                . '@media(min-width:768px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(.top-home-content--above-fold,.top-home-content--above-fold>.banner-slider.banner-slider2){'
                . 'height:clamp(176px,24.5vw,472px)!important;min-height:clamp(176px,24.5vw,472px)!important;'
                . 'max-height:clamp(176px,24.5vw,472px)!important;'
                . 'overflow:hidden!important;box-sizing:border-box!important;'
                . 'margin-block:0!important;padding-block:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .wrapper_slider.hidden-xs,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .wrapper_slider.hidden-xs'
                . ' :is(.awa-hero-swiper,.swiper-wrapper,.swiper-slide,.banner_item,.banner_item_bg){'
                . 'height:clamp(176px,24.5vw,472px)!important;min-height:clamp(176px,24.5vw,472px)!important;'
                . 'max-height:clamp(176px,24.5vw,472px)!important;'
                . 'aspect-ratio:unset!important;overflow:hidden!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .wrapper_slider.hidden-xs .banner_item_bg'
                . ' :is(a,picture,img){'
                . 'display:block!important;width:100%!important;height:100%!important;'
                . 'min-height:0!important;max-height:none!important;object-fit:cover!important;'
                . 'object-position:center!important;border-radius:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .wrapper_slider.hidden-xs'
                . ' .awa-hero__slide--studio-white .banner_item_bg{'
                . 'background-color:var(--awa-red)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .wrapper_slider.hidden-xs'
                . ' .awa-hero__slide--studio-white .banner_item_bg :is(a,picture){'
                . 'display:flex!important;justify-content:flex-end!important;'
                . 'width:100%!important;height:100%!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .wrapper_slider.hidden-xs'
                . ' .awa-hero__slide--studio-white .banner_item_bg img{'
                . 'width:46%!important;max-width:none!important;height:100%!important;'
                . 'margin-inline-start:auto!important;object-fit:cover!important;'
                . 'object-position:100% 50%!important;transform:none!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .wrapper_slider.hidden-xs'
                . ' .awa-hero__slide--studio-white .text-banner.awa-hero-guidao-copy{'
                . 'inset:0 auto 0 0!important;width:min(54%,36rem)!important;height:100%!important;'
                . 'display:flex!important;flex-direction:column!important;justify-content:center!important;'
                . 'align-items:flex-start!important;gap:6px!important;'
                . 'padding:clamp(12px,2vw,24px) clamp(16px,3vw,40px)!important;'
                . 'background:transparent!important;background-image:none!important;z-index:3!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .wrapper_slider.hidden-xs'
                . ' .awa-hero__slide--studio-white .text-banner.awa-hero-guidao-copy'
                . ' :is(.slide-kicker,.slide-title,.slide-desc){'
                . 'color:var(--awa-bg)!important;background:transparent!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold :is(.awa-hero-swiper__nav,.swiper-button-prev,.swiper-button-next){'
                . 'display:grid!important;place-items:center!important;position:absolute!important;'
                . 'width:40px!important;height:40px!important;min-width:40px!important;min-height:40px!important;'
                . 'max-width:40px!important;max-height:40px!important;box-sizing:border-box!important;'
                . 'opacity:1!important;visibility:visible!important;pointer-events:auto!important;'
                . 'top:50%!important;transform:translateY(-50%)!important;margin:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold :is(.awa-hero-swiper__nav--prev,.swiper-button-prev){'
                . 'left:12px!important;right:auto!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold :is(.awa-hero-swiper__nav--next,.swiper-button-next){'
                . 'right:12px!important;left:auto!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .top-home-content--above-fold .banner_item .text-banner:not(:empty){'
                . 'position:absolute!important;inset:auto 0 0 0!important;height:auto!important;'
                . 'min-height:0!important;padding:clamp(12px,1.6vw,20px) clamp(16px,3vw,40px)!important;'
                . 'box-sizing:border-box!important}'
                . '}'
                // r76e/H13 CLS: .page.messages vazio mt/mb 8→0 (gap 0→22→0). Lock hide desde 1º paint.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(#maincontent .page.messages,.page-main > .page.messages,.page.messages)'
                . ':not(:has(.message)){'
                . 'display:none!important;margin:0!important;margin-block:0!important;padding:0!important;'
                . 'height:0!important;min-height:0!important;max-height:0!important;overflow:hidden!important;'
                . 'border:0!important}'
                // r76f/H14 CLS: #maincontent padding-block 0→6px @~3s (themes/async) → gap 6 + late volta a 0.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' :is(#maincontent.page-main,#maincontent.page-main.container,.page-main.container){'
                . 'padding-block:0!important;padding-top:0!important;padding-bottom:0!important;'
                . 'margin-block:0!important;margin-inline:0!important;box-sizing:border-box!important;'
                . 'width:100%!important;max-width:none!important}'
                // ba97c7 H6: content-visibility:auto no critical + unlock no deferred-stack = CLS 0.94.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' :is(.awa-home-section--below-fold,.awa-grid-section--featured,.awa-home-niche-shelves){'
                . 'content-visibility:visible!important;contain:none!important;contain-intrinsic-size:unset!important}'
                // ba97c7 H7: 11×#id perdia p/ [data-awa-header-mode] overflow:visible (88px CLS).
                // H8: padding-inline:0 perdia; estado final medido = 1280 + pad 24.
                . '@media(min-width:992px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-contact-links.awa-header-account-prompt,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-account-prompt{'
                . 'height:50px!important;min-height:50px!important;max-height:50px!important;'
                . 'align-items:center!important;overflow:hidden!important;flex-wrap:nowrap!important;'
                . 'box-sizing:border-box!important;min-width:230px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-account-prompt__line2{'
                . 'display:inline-flex!important;flex-wrap:nowrap!important;white-space:nowrap!important;'
                . 'height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar){'
                . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
                . 'margin:0!important;margin-inline:0!important;padding-inline:0!important;'
                . 'box-sizing:border-box!important;'
                . 'background:var(--awa-primary,#b73337)!important;background-color:var(--awa-primary,#b73337)!important;background-image:none!important;'
                . 'border:0!important;color:var(--awa-text-inverse,#fff)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .header-control.awa-nav-bar .awa-nav-quick-links__link{'
                . 'color:var(--awa-text-inverse,#fff)!important;'
                . '-webkit-text-fill-color:var(--awa-text-inverse,#fff)!important;'
                . 'background:transparent!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .header-control.awa-nav-bar .awa-nav-quick-links__link:hover{'
                . 'color:var(--awa-text-inverse,#fff)!important;'
                . '-webkit-text-fill-color:var(--awa-text-inverse,#fff)!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container){'
                . 'width:100%!important;max-width:none!important;max-inline-size:none!important;box-sizing:border-box!important;'
                . 'padding:0!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
                . 'margin:0!important;margin-inline:0!important;display:block!important;gap:0!important;column-gap:0!important;background:transparent!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container)::before,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container)::after{'
                . 'content:none!important;display:none!important;width:0!important;height:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header,'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .header-wrapper-sticky{'
                . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
                . 'margin-inline:0!important;padding-inline:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .header-control.awa-nav-bar .awa-nav-bar__inner{'
                . 'width:100%!important;max-width:none!important;max-inline-size:none!important;box-sizing:border-box!important;'
                . 'padding-inline:24px!important;/*r24-gutter-24*/margin-inline:0!important;background:transparent!important;'
                . 'gap:16px!important;column-gap:16px!important;row-gap:16px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;max-inline-size:1280px!important;'
                . 'margin-inline:auto!important;padding-inline:24px!important;/*r24-main-centered*/box-sizing:border-box!important}'
                . '}'
                // ba97c7 HC-CARD: slot+inner both painted (nested card) + NOVO halo pink/ink.
                // 14×#id vence design-system 13×#id (§33.1 / §33.4).
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' .item-product.awa-carousel-card-slot > :is(.content-item-product,.awa-product-card){'
                . 'border:0!important;border-radius:0!important;box-shadow:none!important;'
                . 'background:transparent!important;padding:0!important;overflow:visible!important;'
                . 'min-height:0!important;height:100%!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' .onsale.new-lable{'
                . 'background:var(--awa-text,var(--awa-text-primary))!important;'
                . 'color:var(--awa-bg,Canvas)!important;border:0!important;box-shadow:none!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' .onsale.new-lable .sale-bg{display:none!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' .onsale.new-lable :is(.sale-text,.sale-text label,label){'
                . 'background:transparent!important;color:inherit!important;border:0!important;'
                . 'border-radius:0!important;padding:0!important;box-shadow:none!important}'
                // ba97c7 HC-PROMPT: vtex-clean esconde "Para ver os preços" (display:none) e
                // trava .separator em 44px, o que corta line2 (scrollH 36 > clientH 28).
                // 14×#id unlayered vence 1×#id do vtex-clean e 13×#id do design-system.
                . '@media(min-width:992px){'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]'
                . ' .awa-header-account-prompt__guest{'
                . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;'
                . 'justify-content:center!important;gap:1px!important;height:44px!important;'
                . 'max-height:44px!important;overflow:visible!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]'
                . ' .awa-header-account-prompt__guest .awa-header-account-prompt__line1{'
                . 'display:block!important;visibility:visible!important;opacity:1!important;'
                . 'height:auto!important;max-height:none!important;min-height:0!important;'
                . 'overflow:visible!important;font-size:12px!important;font-weight:600!important;'
                . 'line-height:1.2!important;white-space:nowrap!important;text-transform:none!important;'
                . 'color:var(--awa-text-muted,var(--awa-text-secondary))!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]'
                . ' :is(.awa-header-account-prompt__line2,.awa-header-account-prompt__actions){'
                . 'display:inline-flex!important;align-items:center!important;flex-wrap:nowrap!important;'
                . 'height:auto!important;min-height:0!important;max-height:none!important;'
                . 'overflow:visible!important;line-height:1.2!important;gap:6px!important;padding:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]'
                . ' .awa-header-account-prompt__separator{'
                . 'height:auto!important;min-height:0!important;max-height:none!important;'
                . 'line-height:1.2!important;align-self:center!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]'
                . ' :is(.awa-header-account-prompt__link--login,.awa-header-account-prompt__link--register){'
                . 'height:auto!important;min-height:0!important;min-width:0!important;line-height:1.2!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]'
                . ' :is(.awa-header-account-prompt__icon,.awa-header-account-prompt__icon svg){'
                . 'color:var(--awa-primary,var(--awa-brand))!important;'
                . 'stroke:currentColor!important;fill:none!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header .awa-header-account-prompt[data-awa-auth-state="guest"]{'
                . 'min-width:0!important;width:max-content!important}'
                . '}'
                // ba97c7 HC-IMG: foto preenche o thumb. Evidência 1366: img 121 / slot 187
                // (ratio 0.647) por pad 8 no inner+thumb e inset 8 + max calc(100%-16px)
                // no link e na img. Slot mantém pad 8 (casca). object-fit:contain anti-crop.
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' :is(.awa-carousel-section,.awa-shelf--carousel) .item-product.awa-carousel-card-slot{'
                . '--awa-card-canonical-img-pad:0px!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' .item-product.awa-carousel-card-slot :is(.content-item-product,.awa-product-card){'
                . 'padding:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' :is(.awa-carousel-section,.awa-shelf--carousel) .item-product .product-thumb{'
                . 'padding:0!important;box-sizing:border-box!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' :is(.awa-carousel-section,.awa-shelf--carousel) .item-product .product-thumb'
                . ' :is(.product-thumb-link,.first-thumb,.product-image-container,.product-image-wrapper){'
                . 'inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;'
                . 'padding:0!important;max-width:100%!important;max-height:100%!important;'
                . 'width:100%!important;height:100%!important;margin:0!important}'
                . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home'
                . ' :is(.awa-carousel-section,.awa-shelf--carousel) .item-product .product-thumb'
                . ' :is(img,img.product-image-photo,.product-image-photo){'
                . 'position:absolute!important;inset:0!important;top:0!important;right:0!important;'
                . 'bottom:0!important;left:0!important;width:100%!important;height:100%!important;'
                . 'max-width:100%!important;max-height:100%!important;margin:0!important;padding:0!important;'
                . 'object-fit:contain!important;object-position:center!important}'
                ;
            $html = preg_replace(
                '/<style\s+id="awa-home-cls-geometry-guard"[^>]*>.*?<\/style>\s*/is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<head[^>]*>/i',
                '$0<style id="awa-home-cls-geometry-guard">' . $geometryGuardCss . '</style>',
                $html,
                1
            ) ?? $html;

            // r63/r66f: strip awa-carousel-chrome-ssr from shelf ROOT only (keep on nav/progress).
            // HOME freeze fix (2026-08-17): avoid global class-attribute observer on documentElement.
            // That observer reacts to almost every class toggle on the page and can saturate main thread.
            $chromeStripJs = '(function(){try{'
                . 'var sel=".awa-shelf.awa-carousel-chrome-ssr,.rokan-newproduct.awa-carousel-chrome-ssr,.rokan-bestseller.awa-carousel-chrome-ssr";'
                . 'var mo=null;var queued=false;var runs=0;'
                . 'function strip(){document.querySelectorAll(sel).forEach(function(el){'
                . 'el.classList.remove("awa-carousel-chrome-ssr");el.setAttribute("data-awa-chrome-ssr-host","1");});runs+=1;}'
                . 'function queueStrip(){if(queued){return;}queued=true;requestAnimationFrame(function(){queued=false;strip();'
                . 'if(runs>=24&&mo){mo.disconnect();mo=null;}});}'
                . 'strip();'
                . 'if(window.MutationObserver){'
                . 'var target=document.querySelector(".content-top-home")||document.querySelector(".page-wrapper")||document.body;'
                . 'if(target){mo=new MutationObserver(function(muts){for(var i=0;i<muts.length;i+=1){'
                . 'if(muts[i].addedNodes&&muts[i].addedNodes.length){queueStrip();break;}}});'
                . 'mo.observe(target,{childList:true,subtree:true});setTimeout(function(){if(mo){mo.disconnect();mo=null;}},6000);}}'
                . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",queueStrip,{once:true});'
                . 'window.addEventListener("load",function(){queueStrip();setTimeout(queueStrip,300);},{once:true});'
                . '}catch(e){}})();';
            $html = preg_replace(
                '/<script\s+id="awa-debug-r62-probe">.*?<\/script>\s*/is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<script\s+id="awa-chrome-ssr-strip">.*?<\/script>\s*/is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<style\s+id="awa-nav-fullbleed-lock"[^>]*>.*?<\/style>\s*/is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<style\s+id="awa-nav-fullbleed-lock-body"[^>]*>.*?<\/style>\s*/is',
                '',
                $html
            ) ?? $html;
            $navFullbleedIds = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
                . '#html-body#html-body#html-body#html-body#html-body#html-body';
            $navFullbleedHome = ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
                . ' .awa-site-header[data-awa-header-mode="default"]';
            $navFullbleedLockCss = '@media(min-width:992px){'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar){'
                . 'background:var(--awa-primary,#b73337)!important;background-color:var(--awa-primary,#b73337)!important;background-image:none!important;'
                . 'border:0!important;color:var(--awa-text-inverse,#fff)!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' .header-control.awa-nav-bar .awa-nav-quick-links__link{'
                . 'color:var(--awa-text-inverse,#fff)!important;'
                . '-webkit-text-fill-color:var(--awa-text-inverse,#fff)!important;'
                . 'background:transparent!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' .header-control.awa-nav-bar .awa-nav-quick-links__link:hover{'
                . 'color:var(--awa-text-inverse,#fff)!important;'
                . '-webkit-text-fill-color:var(--awa-text-inverse,#fff)!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container,'
                . '.header-control.awa-nav-bar .awa-nav-bar__inner,.header-control.header-nav.awa-nav-bar .awa-nav-bar__inner){'
                . 'background:transparent!important;background-color:transparent!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container){'
                . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
                . 'margin:0!important;padding:0!important;padding-inline:0!important;'
                . 'padding-left:0!important;padding-right:0!important;'
                . 'display:block!important;gap:0!important;column-gap:0!important;box-sizing:border-box!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container)::before,'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container)::after{'
                . 'content:none!important;display:none!important;width:0!important;height:0!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar .awa-nav-bar__inner,.header-control.header-nav.awa-nav-bar .awa-nav-bar__inner){'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;max-inline-size:1280px!important;'
                . 'min-width:0!important;margin-inline:auto!important;padding-inline:24px!important;box-sizing:border-box!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;max-inline-size:1280px!important;'
                . 'margin-inline:auto!important;padding-inline:24px!important;box-sizing:border-box!important}'
                . '}'
                . '@layer awa-header-first-paint-lock{@media(min-width:992px){'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container){'
                . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
                . 'margin:0!important;padding:0!important;padding-inline:0!important;'
                . 'display:block!important;gap:0!important;box-sizing:border-box!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container)::before,'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar>.container,.header-control.header-nav.awa-nav-bar>.container)::after{'
                . 'content:none!important;display:none!important}'
                . $navFullbleedIds . $navFullbleedHome
                . ' :is(.header-control.awa-nav-bar .awa-nav-bar__inner,.header-control.header-nav.awa-nav-bar .awa-nav-bar__inner){'
                . 'width:min(100%,1280px)!important;max-width:1280px!important;max-inline-size:1280px!important;'
                . 'min-width:0!important;margin-inline:auto!important;padding-inline:24px!important;'
                . 'box-sizing:border-box!important}'
                . '}}';
            $html = preg_replace(
                '/<\/head>/i',
                '<style id="awa-nav-fullbleed-lock">' . $navFullbleedLockCss . '</style>'
                . '<script id="awa-chrome-ssr-strip">' . $chromeStripJs . '</script></head>',
                $html,
                1
            ) ?? $html;

            // ba97c7 HC-IMG terminal (fim do body): vence folhas tardias que
            // reaplicam padding 8px no inner (computed padT/padL=8 apesar do guard).
            // Foto full-bleed no thumb; respiro só no .product-info.
            // Layer first-paint (já declarado no head) + 14 IDs: !important em
            // layer cedo vence @layer tardio; 14 IDs vencem deferred-stack 3 IDs.
            $ids14 = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . '#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home';
            $photoFillCss = '@layer awa-header-first-paint-lock{'
                . $ids14
                . ' :is(.awa-carousel-section,.awa-shelf--carousel) .item-product.awa-carousel-card-slot{'
                . '--awa-card-canonical-img-pad:0px!important;--awa-home-card-pad:0px!important;'
                . 'padding:0!important;padding-block:0!important;padding-inline:0!important}'
                . $ids14
                . ' .item-product.awa-carousel-card-slot :is(.content-item-product,.awa-product-card){'
                . 'padding:0!important;padding-block:0!important;padding-inline:0!important}'
                . $ids14
                . ' :is(.awa-carousel-section,.awa-shelf--carousel) .item-product .product-thumb{'
                . 'padding:0!important;width:100%!important}'
                . $ids14
                . ' :is(.awa-carousel-section,.awa-shelf--carousel) .item-product'
                . ' :is(.product-info,.product-item-actions,.b2b-login-to-see-price){'
                . 'padding:var(--awa-gap-sm,8px)!important;box-sizing:border-box!important}'
                . $ids14
                . ' :is(.awa-carousel-section,.awa-shelf--carousel) .item-product .product-thumb'
                . ' :is(img,img.product-image-photo,.product-image-photo,.product-thumb-link,'
                . '.product-image-container,.product-image-wrapper,.first-thumb){'
                . 'inset:0!important;max-width:100%!important;max-height:100%!important;'
                . 'width:100%!important;height:100%!important}'
                . '}';
            $html = preg_replace(
                '/<style\s+id="awa-home-card-photo-fill"[^>]*>.*?<\/style>\s*/is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<\/body>/i',
                '<style id="awa-nav-fullbleed-lock-body">' . $navFullbleedLockCss . '</style>'
                . '<style id="awa-home-card-photo-fill">' . $photoFillCss . '</style></body>',
                $html,
                1
            ) ?? $html;


            $html = str_replace(
                [
                    'minmax(420px,1fr)',
                    'min-height:84px!important',
                    'height:84px!important',
                    'max-height:84px!important',
                ],
                [
                    'minmax(360px,1fr)',
                    'min-height:68px!important',
                    'height:68px!important',
                    'max-height:68px!important',
                ],
                $html
            );
        }

        // BUG-SHELL-NONHOME-MIN120-001 + BUG-SHELL-PROMO-COLLAPSE-ALL-VW-001:
        // stack-h incluía promo mesmo fechada → faixa 44px (desktop/tablet) / 24px (mobile).
        // SSOT: promo-h:0 + stack=sticky; :has(promo aberta) restaura 44+sticky.
        // Placeholder CLS: hide default global (home guard não roda no cart).
        $nonHomeMobileShellCss = '#awa-header-cls-placeholder{display:none!important;height:0!important;'
            . 'margin:0!important;padding:0!important;min-height:0!important;overflow:hidden!important}'
            . 'body.awa-header-is-sticky #awa-header-cls-placeholder{display:block!important;'
            . 'height:var(--awa-header-height,var(--awa-header-sticky-h,96px))!important;'
            . 'min-height:0!important;overflow:hidden!important}'
            /* all viewports — collapse promo reservation when closed */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]{'
            . '--awa-header-promo-h:0px!important;'
            . '--awa-header-stack-h:var(--awa-header-sticky-h)!important;'
            . 'height:auto!important;min-height:var(--awa-header-sticky-h)!important;max-height:none!important;'
            . 'overflow:visible!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
            . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
            . '--awa-header-promo-h:44px!important;'
            . '--awa-header-stack-h:calc(44px + var(--awa-header-sticky-h))!important;'
            . 'min-height:calc(44px + var(--awa-header-sticky-h))!important}'
            . 'html body#html-body#html-body.awa-header-is-sticky:not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]{'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . '--awa-header-stack-h:var(--awa-header-sticky-h)!important}'
            . 'html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper > .page.messages:not(:has(.message)),'
            . 'html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper #maincontent .page.messages:not(:has(.message)),'
            . 'html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .page-main > .page.messages:not(:has(.message)){'
            . 'display:none!important;margin:0!important;padding:0!important;'
            . 'height:0!important;min-height:0!important;overflow:hidden!important}'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index){'
            . '--awa-header-scroll-offset:96px!important;--awa-header-sticky-h:96px!important}'
            . 'html:has(body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)){'
            . '--awa-header-scroll-offset:96px!important;--awa-header-sticky-h:96px!important;'
            . 'scroll-padding-top:96px!important;scroll-padding-block-start:96px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]{'
            . '--awa-header-main-row-h:96px!important;--awa-header-sticky-h:96px!important;'
            . '--awa-header-promo-h:0px!important;--awa-header-stack-h:96px!important;'
            . 'min-height:96px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
            . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
            . '--awa-header-promo-h:44px!important;--awa-header-stack-h:140px!important;'
            . 'min-height:140px!important}'
            . '}'
            . '@media(min-width:768px) and (max-width:991px){'
            /* BUG-SHELL-TABLET-PLP-MAIN88-001: themes força .header.awa-main-header 88px
             * → sticky 88+nav48=136 (SSOT 64+48=112). Vencer literal 88. */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index){'
            . '--awa-header-scroll-offset:128px!important;--awa-header-sticky-h:112px!important;'
            . '--awa-header-main-row-h:64px!important;--awa-header-nav-h:48px!important}'
            . 'html:has(body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)){'
            . '--awa-header-scroll-offset:128px!important;--awa-header-sticky-h:112px!important;'
            . 'scroll-padding-top:128px!important;scroll-padding-block-start:128px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]{'
            . '--awa-header-main-row-h:64px!important;--awa-header-nav-h:48px!important;'
            . '--awa-header-sticky-h:112px!important;--awa-header-stack-h:112px!important;'
            . 'min-height:112px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
            . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
            . '--awa-header-promo-h:44px!important;--awa-header-stack-h:156px!important;'
            . 'min-height:156px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky{'
            . 'height:112px!important;min-height:112px!important;max-height:112px!important;'
            . 'box-sizing:border-box!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,'
            . '.header-main>.container,.awa-main-header__inner.wp-header,'
            . '.awa-main-header__inner[data-awa-header-row]){'
            . 'height:64px!important;min-height:64px!important;max-height:64px!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
            . ' :is(.header-control.header-nav,.header-control.awa-nav-bar,.awa-nav-bar){'
            . 'height:48px!important;min-height:48px!important;max-height:48px!important;'
            . 'box-sizing:border-box!important}'
            /* BUG-SHELL-TABLET-PLP-MAIN88-001: path idêntico ao condensed 88 (max 3×#html-body no HTML) */
            . 'html body#html-body.catalog-category-view .page-wrapper'
            . ' .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky .header.awa-main-header,'
            . 'html body#html-body.catalogsearch-result-index .page-wrapper'
            . ' .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky .header.awa-main-header,'
            . 'html body#html-body.catalog-product-view .page-wrapper'
            . ' .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky .header.awa-main-header,'
            . 'html body#html-body.cms-index-index .page-wrapper'
            . ' .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky .header.awa-main-header,'
            . 'html body#html-body.cms-home .page-wrapper'
            . ' .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky .header.awa-main-header,'
            . 'html body#html-body.checkout-cart-index .page-wrapper'
            . ' .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky .header.awa-main-header{'
            . 'height:64px!important;min-height:64px!important;max-height:64px!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            . '@media(min-width:992px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index){'
            . '--awa-header-scroll-offset:116px!important;--awa-header-sticky-h:116px!important}'
            . 'html:has(body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)){'
            . '--awa-header-scroll-offset:116px!important;--awa-header-sticky-h:116px!important;'
            . 'scroll-padding-top:116px!important;scroll-padding-block-start:116px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]{'
            . '--awa-header-sticky-h:116px!important;--awa-header-stack-h:116px!important;'
            . 'min-height:116px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
            . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
            . '--awa-header-promo-h:44px!important;--awa-header-stack-h:160px!important;'
            . 'min-height:160px!important}'
            . '}';
        $html = preg_replace(
            '/<style\s+id="awa-nonhome-mobile-shell-min120"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        // Home já tem awa-home-cls-geometry-guard (placeholder + sticky 96/116).
        // Reinjeter o shell non-home duplicava geometria unlayered e vencia o lock da home.
        if ($fullAction !== self::HOME_ACTION) {
            $html = preg_replace(
                '/<head[^>]*>/i',
                '$0<style id="awa-nonhome-mobile-shell-min120">' . $nonHomeMobileShellCss . '</style>',
                $html,
                1
            ) ?? $html;
        }
        // BUG-SHELL-CSS-SOURCE-SSOT-001: geometria tablet/mobile vem da fonte CSS
        // (LESS + bugfix-terminal + contract). Remover paliativos JS/terminal/debug.
        $html = preg_replace(
            '/<style\s+id="awa-shell-tablet-main64-terminal"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script\s+id="awa-shell-tablet-main64-lock"[^>]*>.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script\s+id="awa-shell-css-debug-0d05c9"[^>]*>.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;

        $html = str_replace(
            [
                'grid-template-columns:clamp(128px,13vw,184px) minmax(0,1fr) minmax(260px,max-content)!important;',
                'grid-template-columns:clamp(112px,14vw,148px) minmax(0,1fr) minmax(220px,max-content)!important;',
                'minmax(260px,max-content)',
                'minmax(220px,max-content)',
            ],
            [
                'grid-template-columns:minmax(140px,172px) minmax(360px,1fr) minmax(240px,300px)!important;',
                'grid-template-columns:minmax(112px,148px) minmax(260px,1fr) minmax(180px,240px)!important;',
                'minmax(240px,300px)',
                'minmax(180px,240px)',
            ],
            $html
        );


        // Mobile (<=767): o DOM real expõe toggle/brand/cart como grid items diretos
        // via .awa-header-primary-row{display:contents}; manter o contrato alinhado a isso.
        // FIX 2026-07-06 (Impeccable "cramped padding" div.footer-bottom, no inset no bottom):
        // o padrão 'padding:12px 16px!important;' era substituído via str_replace() SEM escopo,
        // ou seja, batia em QUALQUER ocorrência literal idêntica no $html inteiro — inclusive na
        // regra ".footer-bottom .footer-bottom-inner{...padding:12px 16px!important;...}"
        // (OptimizeHeadStylesPlugin::injectFooterModernRules / HeaderImpeccableCascadeLockCss),
        // que por coincidência usa o mesmo valor. Isso zerava o padding-bottom do footer
        // (virava "4px 12px 0") mesmo fora do contexto de header mobile pretendido. Ancorado
        // com o texto vizinho único do bloco de header (max-height:none...overflow:visible)
        // para que a troca só ocorra dentro do grid mobile do header, nunca no footer.
        $html = str_replace(
            [
                'grid-template-areas:"toggle brand cart" "search search search"!important;',
                'grid-template-columns:44px minmax(0,1fr) 44px!important;',
                'grid-template-rows:auto auto!important;',
                'row-gap:12px!important;column-gap:12px!important;',
                'max-height:none!important;padding:12px 16px!important;overflow:visible!important;',
                '.header-wrapper-sticky .awa-header-primary-row{display:contents!important}',
                '.header-wrapper-sticky .awa-header-right-col{grid-area:search!important;width:100%!important;min-width:0!important;max-width:none!important}',
            ],
            [
                'grid-template-areas:"toggle brand cart" "search search search"!important;',
                'grid-template-columns:44px minmax(0,1fr) 44px!important;',
                'grid-template-rows:44px 44px!important;',
                'row-gap:8px!important;column-gap:8px!important;',
                'max-height:none!important;padding:4px 12px 0!important;overflow:visible!important;',
                '.header-wrapper-sticky .awa-header-primary-row{display:contents!important}',
                '.header-wrapper-sticky .awa-header-right-col{grid-area:cart!important;align-items:center!important;display:flex!important;justify-content:flex-end!important;justify-self:end!important;min-width:44px!important;max-width:44px!important;width:44px!important;height:44px!important;overflow:visible!important}',
            ],
            $html
        );

        // Fase 3D.2.6 — corrige regras @layer awa-fixes mobile que ganham por prioridade de layer:
        // (1) grid-template shorthand legado → propriedades separadas com 3 áreas
        // (2) header-wrapper-sticky e awa-main-header height:96px → auto
        // (3) grid-template-rows:44px 44px → 44px 44px auto (3ª linha para actions)
        $html = str_replace(
            [
                '{box-sizing:border-box!important;display:grid!important;grid-template:"toggle brand cart" 44px "search search search" 44px/44px minmax(0,1fr) 44px!important;gap:4px 8px!important;height:96px!important;min-height:96px!important;max-height:96px!important;padding:4px 16px 0!important;padding-block:4px 0!important;padding-inline:16px!important;align-content:start!important;align-items:center!important;overflow:visible!important}',
                '{box-sizing:border-box!important;display:grid!important;gap:4px 8px!important;grid-template-areas:"primary" "search" "actions"!important;grid-template-columns:minmax(0,1fr)!important;grid-template-rows:44px 44px!important;height:96px!important;min-height:96px!important;max-height:96px!important;overflow:visible!important;padding:4px 16px 0!important;padding-block:4px 0!important;padding-inline:16px!important}',
                'header-wrapper-sticky{box-sizing:border-box!important;height:96px!important;min-height:96px!important;max-height:96px!important;padding:0 16px!important;padding-block:0!important;overflow:visible!important}',
                'box-sizing:border-box!important;display:block!important;height:96px!important;min-height:96px!important;max-height:96px!important;block-size:96px!important;min-block-size:96px!important;max-block-size:96px!important;padding:0!important;margin:0!important;overflow:visible!important}',
            ],
            [
                '{box-sizing:border-box!important;display:grid!important;grid-template:"toggle brand cart" 44px "search search search" 44px/44px minmax(0,1fr) 44px!important;gap:4px 8px!important;height:96px!important;min-height:96px!important;max-height:96px!important;padding:4px 0 0!important;padding-block:4px 0!important;padding-inline:0!important;align-content:start!important;align-items:center!important;overflow:visible!important}',
                '{box-sizing:border-box!important;display:grid!important;gap:4px 8px!important;grid-template:"toggle brand cart" 44px "search search search" 44px/44px minmax(0,1fr) 44px!important;height:96px!important;min-height:96px!important;max-height:96px!important;overflow:visible!important;padding:4px 0 0!important;padding-block:4px 0!important;padding-inline:0!important}',
                /* PIXEL-QA 2026-07-25: manter gutter 16 no sticky (não zerar). */
                'header-wrapper-sticky{box-sizing:border-box!important;height:auto!important;min-height:0!important;max-height:none!important;padding-block:0!important;padding-inline:16px!important;overflow:visible!important}',
                'box-sizing:border-box!important;display:block!important;height:auto!important;min-height:0!important;max-height:none!important;padding:0!important;margin:0!important;overflow:visible!important}',
            ],
            $html
        );

        // Fase 3D.2.7 — corrige height:96px residual em .header.awa-main-header e
        // .header-wrapper-sticky para páginas fora da home (PLP, PDP, etc.):
        $html = str_replace(
            [
                ':is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container){box-sizing:border-box!important;height:96px!important;min-height:96px!important;max-height:96px!important;padding:0!important;margin:0!important;overflow:visible!important}',
                'header.awa-site-header .header-wrapper-sticky{box-sizing:border-box!important;height:96px!important;min-height:96px!important;max-height:96px!important;padding:0!important;margin:0!important;overflow:visible!important}',
            ],
            [
                ':is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container){box-sizing:border-box!important;height:auto!important;min-height:0!important;max-height:none!important;padding:0!important;margin:0!important;overflow:visible!important}',
                /* PIXEL-QA 2026-07-25: gutter mobile 16 no sticky PLP/PDP. */
                'header.awa-site-header .header-wrapper-sticky{box-sizing:border-box!important;height:auto!important;min-height:0!important;max-height:none!important;padding-block:0!important;padding-inline:16px!important;margin:0!important;overflow:visible!important}',
            ],
            $html
        );

        $html = $this->enforcePrimaryRowResponsiveContract($html);

        return $html;
    }

    /**
     * WCAG 2.5.8 / Magento touch target ≥44px.
     * Evidência 2026-07-25: select.sorter com height:auto ignora min-height no Chrome;
     * breadcrumbs a{display:inline} zera o hit-area. Body-end unlayered + force style.
     */
    private function injectTouch44Terminal(string $html): string
    {
        if (!str_contains($html, 'page-wrapper')) {
            return $html;
        }

        $fullAction = (string) $this->request->getFullActionName();
        $skipCatalogRuntimePatch = in_array($fullAction, self::CATALOG_HEADER_ACTIONS, true);

        $html = preg_replace(
            '/<style id="awa-touch-44-terminal">.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script id="awa-touch-44-terminal-js">.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script id="awa-footer-desktop-heading-js">.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="awa-touch-44-terminal">'
            . 'html body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index)'
            . ' .page-wrapper .toolbar.toolbar-products'
            . ' :is(.sorter-options,select.limiter-options,#sorter,#limiter){'
            . 'min-height:44px!important;height:44px!important;max-height:none!important;'
            . 'box-sizing:border-box!important;padding-block:8px!important}'
            /* H-crumbs-compact (2026-08-02): breadcrumb nav textual — 44px inflava
               .items ~50px (CDP search). Sempre compacto (pointer:coarse no Electron
               MCP re-inflava; alvos touch reais ficam nos botões/toolbar). */
            . 'html body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(.breadcrumbs,.nav-breadcrumbs) a{'
            . 'display:inline-flex!important;align-items:center!important;'
            . 'min-height:0!important;min-block-size:0!important;height:auto!important;'
            . 'padding-block:2px!important;box-sizing:border-box!important}'
            . '@media (max-width:991px){'
            . 'html body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(.page_footer,.page-footer)'
            . ' :is(button.awa-footer-section__toggle,.awa-footer-section__toggle){'
            . 'min-height:44px!important;height:auto!important;max-height:none!important;'
            . 'padding-block:10px!important;box-sizing:border-box!important;'
            . 'display:flex!important;align-items:center!important}'
            . '}'
            /* Cart: critical CSS forçava Remover=34px; qty btn w=36 fora de pointer:coarse. */
            . 'html body#html-body#html-body#html-body#html-body.checkout-cart-index'
            . ' .page-wrapper .cart.table-wrapper'
            . ' :is(.action-delete,.action.action-delete){'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'min-height:44px!important;height:44px!important;min-width:44px!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body.checkout-cart-index'
            . ' .page-wrapper :is(.awa-qty-btn,.awa-qty-stepper .awa-qty-btn){'
            . 'min-width:44px!important;width:44px!important;min-height:44px!important;height:44px!important;'
            . 'box-sizing:border-box!important;display:inline-flex!important;'
            . 'align-items:center!important;justify-content:center!important}'
            /* Promo close = 1º button no DOM (Pixel QA sel:"button"). */
            . 'html body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(#awa-b2b-promo-close,button.awa-b2b-promo-close){'
            . 'min-width:44px!important;width:44px!important;max-width:44px!important;'
            . 'min-height:44px!important;height:44px!important;max-height:44px!important;'
            . 'box-sizing:border-box!important;display:inline-flex!important;'
            . 'align-items:center!important;justify-content:center!important;padding:0!important}'
            /* PLP: só o toggle real (data-awa-filter-toggle) precisa alvo 44px. */
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index)'
            . ' .page-wrapper .toolbar.toolbar-products .modes .modes-label[data-awa-filter-toggle="true"]{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'min-height:44px!important;height:auto!important;min-width:44px!important;'
            . 'box-sizing:border-box!important;padding-block:10px!important}'
            /* Empty cart: suggestion/more — min.css quebrava .page-wrapper :is(); base era 36/22. */
            . 'html body#html-body#html-body#html-body#html-body.checkout-cart-index'
            . ' .page-wrapper .cart-empty.awa-cart-empty'
            . ' :is(a.awa-cart-empty__suggestion,a.awa-cart-empty__categories-more){'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'min-height:44px!important;height:auto!important;box-sizing:border-box!important;'
            . 'padding-block:10px!important}'
            /* PDP Compartilhar: round3 async define min-height:38px em `a` genérico;
               .action (Imprimir) já é 44px. !important: folha async carrega depois. */
            . 'html body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product-info-main :is(.product-social-links,.product-addto-links) a{'
            . 'display:inline-flex!important;align-items:center!important;'
            . 'min-height:44px!important;min-width:44px!important;height:auto!important;'
            . 'box-sizing:border-box!important;padding-block:8px!important}'
            /* Contact e-mail: regra v7 cobria tel/wa, omitia mailto; inline display:inline-block. */
            . 'html body#html-body#html-body#html-body#html-body.contact-index-index'
            . ' .page-wrapper .column.main :is(.awa-contact-channel,.contact-info,li.phone)'
            . ' a[href^="mailto:"],'
            . 'html body#html-body#html-body#html-body#html-body.contact-index-index'
            . ' .page-wrapper .column.main a[href^="mailto:"]{'
            . 'display:inline-flex!important;align-items:center!important;flex-wrap:wrap!important;'
            . 'min-height:44px!important;height:auto!important;box-sizing:border-box!important;'
            . 'max-width:100%!important;padding-block:8px!important}'
            . '</style>';

        $js = '';
        if (!$skipCatalogRuntimePatch) {
            /* Element style !important vence qualquer stylesheet author (prova CDP). */
            $js = '<script id="awa-touch-44-terminal-js">(function(){try{'
                . 'var q=function(s){return Array.prototype.slice.call(document.querySelectorAll(s));};'
                . 'var set=function(els,p){els.forEach(function(el){'
                . 'Object.keys(p).forEach(function(k){el.style.setProperty(k,p[k],"important");});'
                . '});};'
                . 'set(q(".toolbar.toolbar-products #sorter,'
                . '.toolbar.toolbar-products select.sorter-options,'
                . '.toolbar.toolbar-products select.limiter-options,'
                . '.toolbar.toolbar-products #limiter"),'
                . '{height:"44px","min-height":"44px","max-height":"none","box-sizing":"border-box"});'
                /* H-crumbs-compact (Onda 5G): compact só ≥768px — mobile precisa alvo ≥36px (Sec22).
                   (CDP: JS antigo/race com forceTouch44 44 inflava .items ~50px). */
                . 'if(window.matchMedia("(min-width: 768px)").matches){'
                . 'var crumbP={display:"inline-flex","align-items":"center","min-height":"0",'
                . '"min-block-size":"0",height:"auto","padding-block":"2px","box-sizing":"border-box"};'
                . 'var crumbBusy=false;'
                . 'var crumbApply=function(){'
                . 'if(crumbBusy){return;}crumbBusy=true;'
                . 'try{set(q(".breadcrumbs a,.nav-breadcrumbs a"),crumbP);'
                . 'var ts=document.getElementById("awa-touch-style");'
                . 'if(ts&&ts.textContent&&/:is\\(\\.breadcrumbs,\\.nav-breadcrumbs\\) a\\{/.test(ts.textContent)){'
                . 'var next=ts.textContent.replace(/(:is\\(\\.breadcrumbs,\\.nav-breadcrumbs\\) a\\{)[^}]*(})/g,'
                . '"$1display:inline-flex!important;align-items:center!important;min-height:0!important;'
                . 'min-block-size:0!important;height:auto!important;padding-block:2px!important;box-sizing:border-box!important$2");'
                . 'if(next!==ts.textContent){ts.textContent=next;}'
                . '}'
                . '}finally{setTimeout(function(){crumbBusy=false;},0);}};'
                . 'crumbApply();'
                . '[0,50,200,800,2000,4000,8000].forEach(function(ms){setTimeout(crumbApply,ms);});'
                . 'if(window.requestAnimationFrame){requestAnimationFrame(crumbApply);}'
                . 'try{if(window.MutationObserver){var mo=new MutationObserver(function(){crumbApply();});'
                . 'var boot=function(){q(".breadcrumbs a,.nav-breadcrumbs a").forEach(function(el){'
                . 'mo.observe(el,{attributes:true,attributeFilter:["style"]});});'
                . 'var ts=document.getElementById("awa-touch-style");'
                . 'if(ts){mo.observe(ts,{childList:true,characterData:true,subtree:true});}};'
                . 'boot();setTimeout(boot,1000);setTimeout(boot,3000);'
                . 'setTimeout(function(){try{mo.disconnect();}catch(e){}},12000);}}catch(e){}'
                . '}'
                . 'if(window.matchMedia("(max-width: 991px)").matches){'
                . 'set(q(".page_footer .awa-footer-section__toggle,'
                . '.page-footer .awa-footer-section__toggle"),'
                . '{"min-height":"44px",height:"auto","max-height":"none",'
                . '"padding-block":"10px",display:"flex","align-items":"center",'
                . '"box-sizing":"border-box"});'
                . '}else{q(".page_footer .awa-footer-section__toggle,.page-footer .awa-footer-section__toggle")'
                . '.forEach(function(el){["min-height","height","max-height","padding-block"]'
                . '.forEach(function(k){el.style.removeProperty(k);});});}'
                . 'set(q(".checkout-cart-index .cart.table-wrapper .action-delete,'
                . '.checkout-cart-index .cart.table-wrapper .action.action-delete"),'
                . '{"min-height":"44px",height:"44px","min-width":"44px",'
                . 'display:"inline-flex","align-items":"center","justify-content":"center",'
                . '"box-sizing":"border-box"});'
                . 'set(q(".checkout-cart-index .awa-qty-btn"),'
                . '{"min-width":"44px",width:"44px","min-height":"44px",height:"44px",'
                . 'display:"inline-flex","align-items":"center","justify-content":"center",'
                . '"box-sizing":"border-box"});'
                . 'set(q("#awa-b2b-promo-close,button.awa-b2b-promo-close"),'
                . '{"min-width":"44px",width:"44px","min-height":"44px",height:"44px",'
                . '"max-width":"44px","max-height":"44px",'
                . 'display:"inline-flex","align-items":"center","justify-content":"center",'
                . '"box-sizing":"border-box"});'
                . 'set(q(".toolbar.toolbar-products .modes .modes-label[data-awa-filter-toggle=true]"),'
                . '{"min-height":"44px",height:"auto","min-width":"44px",'
                . 'display:"inline-flex","align-items":"center","justify-content":"center",'
                . '"padding-block":"10px","box-sizing":"border-box"});'
                . 'set(q(".cart-empty.awa-cart-empty a.awa-cart-empty__suggestion,'
                . '.cart-empty.awa-cart-empty a.awa-cart-empty__categories-more"),'
                . '{"min-height":"44px",height:"auto",'
                . 'display:"inline-flex","align-items":"center","justify-content":"center",'
                . '"padding-block":"10px","box-sizing":"border-box"});'
                . '}catch(e){}})();</script>';
        }

        /* PLP skips awa-touch-44-terminal-js; awa-ux-enhancements forceTouch44
           still writes inline min-height 44 on footer toggles (prova CDP). */
        $footerHeadingJs = '<script id="awa-footer-desktop-heading-js">(function(){try{'
            . 'function c(){if(!window.matchMedia("(min-width: 992px)").matches){return;}'
            . 'document.querySelectorAll(".page_footer .awa-footer-section__toggle,'
            . '.page-footer .awa-footer-section__toggle").forEach(function(el){'
            . '["min-height","height","max-height","padding-block"].forEach(function(k){'
            . 'el.style.removeProperty(k);});});}'
            . 'c();[0,50,200,800,2000,4000,8000].forEach(function(ms){setTimeout(c,ms);});'
            . 'if(window.requestAnimationFrame){requestAnimationFrame(c);}'
            . '}catch(e){}})();</script>';

        $payload = $css . ($js !== '' ? "\n" . $js : '') . "\n" . $footerHeadingJs;
        $injected = preg_replace('/<\/body>/i', $payload . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * §hero-box-r1 — home above-fold full-bleed.
     * Evidência 2026-07-25: content-box + padding-inline 16 → offsetWidth=vw+32,
     * img right=vw+16 (clip); body/html overflow:hidden esconde scroll.
     */
    private function injectHeroBoxTerminal(string $html): string
    {
        if (!preg_match(
            '/<body\b[^>]*\bclass=(["\'])[^"\']*(?:cms-index-index|cms-home|cms-homepage_ayo_home5)/i',
            $html
        )) {
            return $html;
        }

        $html = preg_replace(
            '/<style id="awa-hero-box-terminal">.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="awa-hero-box-terminal">'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(#awa-main-content.top-home-content--above-fold,'
            . '.top-home-content--above-fold.awa-hero){'
            . 'box-sizing:border-box!important;width:100%!important;max-width:100%!important;'
            . 'margin-inline:0!important;padding-inline:0!important;padding-block:0!important;'
            . 'overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home .top-home-content--above-fold'
            . ' :is(.banner-slider,.banner-slider2,.wrapper_slider.visible-xs,'
            . '.awa-hero-swiper,.swiper-slide,.banner_item_bg,picture,img){'
            . 'box-sizing:border-box!important;max-width:100%!important;'
            . 'width:100%!important;margin-inline:0!important}'
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * §plp-col-r1 — contém .col-main dentro de .columns no PLP.
     * Evidência 2026-07-25 (vw 390): .columns=358px mas .col-main=376.5px
     * (flex:0 0 auto de html body.page-products .columns>.col-main no layout-bundle),
     * hero/grid right=392.5 → imgOverflow falso + bleed real clipado.
     */
    private function injectPlpColContainTerminal(string $html): string
    {
        if (!preg_match(
            '/<body\b[^>]*\bclass=(["\'])[^"\']*(?:catalog-category-view|catalogsearch-result-index|page-products)/i',
            $html
        )) {
            return $html;
        }

        $html = preg_replace(
            '/<style id="awa-plp-col-contain-terminal">.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        /* Mobile-only contain (desktop usa display:contents no .col-main).
         * Tablet: esconde coluna de filtro colapsada (era ~26px fantasma em 768). */
        $css = '<style id="awa-plp-col-contain-terminal">'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.page-products)'
            . ' .page-wrapper .page-main > .columns{'
            . 'display:flex!important;flex-wrap:wrap!important;max-width:100%!important;'
            . 'min-width:0!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.page-products)'
            . ' .page-wrapper .page-main > .columns > .col-main{'
            . 'flex:1 1 auto!important;flex-grow:1!important;flex-shrink:1!important;'
            . 'flex-basis:0%!important;width:auto!important;max-width:100%!important;'
            . 'min-width:0!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.page-products)'
            . ' .page-wrapper .page-main > .columns > .col-main'
            . ' :is(.category-view-move,.awa-category-hero,.shop-tab-title,.shop-tab-select,'
            . '.wrapper.grid.products-grid,#layered-ajax-list-products,.product-content-right){'
            . 'max-width:100%!important;min-width:0!important;box-sizing:border-box!important}'
            . '}'
            . '@media(max-width:991px){'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.page-products)'
            . ':not(.awa-plp-filters-expanded)'
            . ' .page-wrapper .page-main > .columns{'
            . 'grid-template-columns:minmax(0,1fr)!important}'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.page-products)'
            . ':not(.awa-plp-filters-expanded)'
            . ' .page-wrapper .page-main > .columns > .col-xs-12.col-sm-3:not(.col-main){'
            . 'display:none!important;width:0!important;min-width:0!important;'
            . 'max-width:0!important;flex:0 0 0!important;overflow:hidden!important;'
            . 'pointer-events:none!important}'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.page-products)'
            . ':not(.awa-plp-filters-expanded)'
            . ' .page-wrapper .page-main > .columns > .col-main{'
            . 'width:100%!important;max-width:100%!important;min-width:0!important;'
            . 'grid-column:1/-1!important}'
            . '}'
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * §header-stack-r1 — empilha título/subtítulo das seções home.
     * Evidência 2026-07-25 (vw 390): flex-wrap:wrap + altura curta colocava
     * .awa-category-carousel__subtitle em left≈326 (fora do viewport).
     */
    private function injectSectionHeaderStackTerminal(string $html): string
    {
        if (!preg_match(
            '/<body\b[^>]*\bclass=(["\'])[^"\']*(?:cms-index-index|cms-home|cms-homepage_ayo_home5)/i',
            $html
        )) {
            return $html;
        }

        $html = preg_replace(
            '/<style id="awa-section-header-stack-terminal">.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;

        $css = '<style id="awa-section-header-stack-terminal">'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(.awa-section-header__left,.awa-shelf__heading,.awa-category-carousel__heading){'
            . 'display:flex!important;flex-direction:column!important;flex-wrap:nowrap!important;'
            . 'align-items:flex-start!important;gap:4px!important;height:auto!important;'
            . 'max-height:none!important;max-width:100%!important;min-width:0!important;'
            . 'overflow:visible!important;width:auto!important;flex:1 1 auto!important}'
            . 'html body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(.awa-section-header__subtitle,.awa-shelf__subtitle,.awa-category-carousel__subtitle){'
            . 'flex:0 1 auto!important;max-width:100%!important;min-width:0!important;'
            . 'width:auto!important;white-space:normal!important;margin:0!important}'
            /* r77 mobile: r76 only covered min-width:768. Niche shelves kept
               flex-direction:column + wrap (unlayered 1–2 ID). CDP 390:
               “Ver todos” x=311 w=113 right=423 > 390. Same row as other
               shelves: nowrap + left min-width:0. !important: unlayered column. */
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(.awa-section-header,.awa-shelf__header,.awa-category-carousel__header,'
            . 'header.awa-section-header){'
            . 'display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;'
            . 'align-items:center!important;justify-content:space-between!important;'
            . 'gap:8px!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(.awa-section-header__left,.awa-shelf__heading,.awa-category-carousel__heading){'
            . 'min-width:0!important;flex:1 1 auto!important;max-width:calc(100% - 7.25rem)!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(.awa-section-header__subtitle,.awa-shelf__subtitle,.awa-category-carousel__subtitle){'
            . 'white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;'
            . 'max-width:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(.awa-section-header__link,.awa-shelf__view-all,.awa-category-carousel__view-all,'
            . '.awa-category-carousel__cta-link,.awa-category-carousel__actions){'
            . 'flex:0 0 auto!important;margin-inline-start:auto!important;white-space:nowrap!important;'
            . 'align-self:center!important;max-width:100%!important}'
            . '}'
            // r76 CLS: align-grid/themes forçam flex-wrap:wrap → “Ver todos” salta de x~1152→86
            // (CLS ~0.045). Desktop: agora = nowrap estável (título|link na mesma linha).
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(.awa-section-header,.awa-shelf__header,.awa-category-carousel__header,'
            . 'header.awa-section-header){'
            . 'display:flex!important;flex-wrap:nowrap!important;flex-direction:row!important;'
            . 'align-items:center!important;justify-content:space-between!important;'
            . 'gap:12px!important;width:100%!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home'
            . ' :is(.awa-section-header__link,.awa-shelf__view-all,.awa-category-carousel__view-all,'
            . '.awa-category-carousel__actions){'
            . 'flex:0 0 auto!important;margin-inline-start:auto!important;white-space:nowrap!important;'
            . 'align-self:center!important}'
            . '}'
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Guest account prompt: shrink-wrap + links textuais.
     * Injeta DEPOIS de normalizeExcessiveHtmlBodySpecificity com 13 IDs
     * para vencer awa-design-system.css (min-width:220px + pílula).
     */
    private function injectHeaderAccountPromptLock(string $html): string
    {
        $styleId = 'awa-header-account-prompt-lock';
        if (str_contains($html, 'id="' . $styleId . '"')) {
            return $html;
        }

        $ids = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body#html-body#html-body';
        $prompt = $ids . ' .page-wrapper .awa-site-header .awa-header-account-prompt';
        $guest = $prompt . '[data-awa-auth-state="guest"]';
        $guestMode = $ids . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
            . ' .awa-header-account-prompt[data-awa-auth-state="guest"]';
        $register = $prompt . ' a.awa-header-account-prompt__link--register';
        $login = $guestMode . ' a.awa-header-account-prompt__link.awa-header-account-prompt__link--login';
        $links = $guestMode . ' :is(.awa-header-account-prompt__link--login,.awa-header-account-prompt__link--register)';

        $css = '<style id="' . $styleId . '">@layer awa-visual-priority{'
            . $guest . '{'
            . 'flex:0 0 auto!important;width:max-content!important;max-width:none!important;'
            . 'min-width:0!important;overflow:visible!important}'
            . $guest . ' :is(.awa-header-account-prompt__text,.awa-header-account-prompt__guest,.awa-header-account-prompt__copy),'
            . $guestMode . ' :is(.awa-header-account-prompt__text,.awa-header-account-prompt__guest,.awa-header-account-prompt__copy){'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'justify-content:center!important;gap:2px!important;flex:0 0 auto!important;'
            . 'width:max-content!important;max-width:none!important;min-width:0!important;'
            . 'height:44px!important;max-height:44px!important;line-height:1.2!important;overflow:visible!important}'
            . $guest . ' :is(.awa-header-account-prompt__line2,.awa-header-account-prompt__actions),'
            . $guestMode . ' :is(.awa-header-account-prompt__line2,.awa-header-account-prompt__actions){'
            . 'min-height:0!important;height:auto!important;max-height:none!important;padding:0!important}'
            . $register . '{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'color:var(--awa-text,CanvasText)!important;border:0!important;border-radius:0!important;'
            . 'padding:0 2px!important;min-height:0!important;height:auto!important;max-height:none!important;'
            . 'margin-inline-start:0!important;min-width:0!important;font-size:12px!important;font-weight:700!important;'
            . 'line-height:1.2!important;text-decoration:none!important;box-shadow:none!important}'
            . $links . ':is(:hover,:focus-visible){'
            . 'color:var(--awa-primary)!important;text-decoration:underline!important;'
            . 'text-underline-offset:2px!important;background:transparent!important;background-color:transparent!important}'
            . $login . '{flex:0 0 auto!important;white-space:nowrap!important;overflow:visible!important;'
            . 'min-height:0!important;height:auto!important;line-height:1.2!important;padding:0 2px!important}'
            . $prompt . '[data-awa-auth-state="customer"]{'
            . 'flex:1 1 auto!important;width:auto!important;max-width:232px!important;min-width:0!important}'
            . '@media(max-width:991px){'
            . $prompt . ',' . $guest . '{'
            . 'flex:0 0 44px!important;width:44px!important;min-width:44px!important;'
            . 'max-width:44px!important;overflow:hidden!important}'
            . '}'
            . '}'
            . '</style>';

        $injected = preg_replace('/<\/body>/i', $css . "\n</body>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * PIXEL-QA r5 — lock terminal de gutter (DEPOIS do audit visual e do
     * normalizeExcessiveHtmlBodySpecificity; senão #html-body colapsa p/ 3× e o
     * padding:12px do audit vence no carrinho).
     */
    private function injectPixelQaGutterLock(string $html): string
    {
        $storefrontHdr = 'html body#html-body#html-body#html-body'
            . ':not(.checkout-index-index):not(.onepagecheckout-index-index)'
            . ':not(.checkout-cart-index):not(.b2b-auth-shell)';
        $catalogHdr = 'html body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view,'
            . '.catalogo-index-index,.awa-catalog-page)';
        /* CMS / 404 / contato / currículo — mesmo gutter 16/24 do eixo storefront. */
        $cmsHdr = 'html body#html-body#html-body#html-body'
            . ':is(.cms-page-view,.cms-noroute-index,.contact-index-index,'
            . '.curriculo-index-index,.curriculo-index-status)';
        $cartHdr = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.checkout-cart-index';
        $homeRoot = 'html:has(body.cms-index-index),html:has(body.cms-home),html:has(body.cms-homepage_ayo_home5),'
            . 'html:has(body#html-body.cms-index-index),html:has(body#html-body.cms-home),'
            . 'html:has(body#html-body.cms-homepage_ayo_home5)';
        /* Dois seletores separados: NÃO concatenar sufixo em lista com vírgula
         * (senão o 1º seletor recebe o padding do descendente — body padava 24). */
        $homeBodyA = 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)';
        $homeBodyB = 'html body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)';
        $homeBody = $homeBodyA . ',' . $homeBodyB;
        $homeDesc = static function (string $suffix) use ($homeBodyA, $homeBodyB): string {
            return $homeBodyA . $suffix . ',' . $homeBodyB . $suffix;
        };
        $homeShell = $homeDesc(' .page-wrapper') . ',' . $homeDesc(' .header-wrapper-sticky');
        /* PIXEL-QA 2026-07-25: contrato gutter mobile 16 / tablet+desktop 24.
         * align-grid terminal fica em media=print (css-gate) — tokens 24 do arquivo
         * NÃO aplicam na tela; este lock no <head> é a fonte de verdade do eixo. */
        $gutterLock = '<style id="awa-pixel-qa-catalog-hdr-gutter-16">'
            /* Layer awa-pixel-qa ANTES de awa-fixes: !important da layer anterior vence o
             * align-grid (mesma layer / ordem tardia na home media=all). */
            . '@layer awa-pixel-qa,awa-fixes;'
            . '@layer awa-pixel-qa{'
            . '@media(max-width:767px){'
            . $homeRoot . '{width:100vw!important;min-width:100vw!important;max-width:100vw!important}'
            . $homeBody . '{width:100%!important;min-width:100%!important;max-width:100%!important;margin:0!important}'
            . $homeShell . '{width:100%!important;max-width:100%!important;box-sizing:border-box!important}'
            . $storefrontHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky,'
            . $storefrontHdr . ' .page-wrapper #header .header-wrapper-sticky{'
            . 'padding-block:0!important;padding-inline:16px!important;box-sizing:border-box!important}'
            . $storefrontHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container,'
            . '.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . $cartHdr . '{margin:0!important;width:100%!important;max-width:100%!important}'
            . $cartHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky{'
            . 'padding-block:0!important;padding-inline:16px!important;box-sizing:border-box!important}'
            . $cartHdr . ' .page-wrapper header.awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container,'
            . '.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            /* Cart: gutter só no page-main — .column.main pad=0 (evita 16+4vw≈32). */
            . $cartHdr . ' .page-wrapper .page-main{'
            . 'padding-inline:16px!important;box-sizing:border-box!important}'
            . $cartHdr . ' .page-wrapper .columns .column.main,'
            . $cartHdr . ' .page-wrapper .column.main,'
            . $cartHdr . ' .page-wrapper .cart-container{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            . '@media(min-width:768px){'
            /* Tokens 24 (align-grid catálogo fica em print/css-gate). */
            . $homeBody . '{'
            . '--awa-page-pad:24px!important;--awa-page-pad-catalog:24px!important;'
            . '--awa-shell-gutter:24px!important;--awa-container-pad:24px!important;'
            . '--awa-container-gutter:24px!important;--awa-plp-shell-pad:24px!important;'
            . '--awa-grid-container-pad:24px!important;--awa-header-shell-pad:24px!important;'
            . '--awa-home-shell-gutter:24px!important}'
            /* Home: body sem pad; eixo único no inner/main/footer. */
            . $homeBody . '{padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . $homeDesc(' .page-wrapper .awa-site-header .header-wrapper-sticky') . '{'
            . 'padding-inline:0!important}'
            . $homeDesc(' .page-wrapper .awa-site-header .header-wrapper-sticky'
                . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
                . '.header-main,.header-main>.container)') . '{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . $homeDesc(' .page-wrapper .awa-site-header .header-wrapper-sticky'
                . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])') . '{'
            . 'padding-inline:24px!important;box-sizing:border-box!important}'
            /* Home ≥1024: vencer clamp/space-4 (5–6 IDs) no inner — pad literal 24. */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]),'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            /* r86 86af0b: home <main> full-bleed — hero 100%; gutter 24 só no wrapper. */
            . $homeDesc(' .page-wrapper :is(.page-main,#maincontent)') . '{'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* Footer: shell full-bleed; eixo 1280 só nos containers internos. */
            . $homeDesc(' .page-wrapper footer.page-footer') . '{'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . $homeDesc(' .page-wrapper footer.page-footer .page_footer') . '{'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . $homeDesc(' .page-wrapper footer.page-footer :is('
                . '.awa-footer-newsletter>.container,.footer-bottom>.container,'
                . '.awa-footer-trust-bar>.container,#footer.footer-container>.container,'
                . 'section.awa-footer-categories-expand>.container,.awa-footer-devby>.container)') . '{'
            . 'max-width:min(100%,1280px)!important;width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:24px!important;box-sizing:border-box!important}'
            . $homeDesc(' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,'
                . '.footer-bottom,.footer-bottom-inner,.awa-footer-newsletter,'
                . 'section.awa-footer-categories-expand,.awa-footer-devby)') . '{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'box-sizing:border-box!important}'
            /* Catálogo/PDP/busca: sticky 0 + inner/main/footer 24. */
            . $catalogHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky{'
            . 'padding-inline:0!important}'
            . $catalogHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . $catalogHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:24px!important;box-sizing:border-box!important}'
            . $catalogHdr . ' .page-wrapper .page-main{'
            . 'padding-inline:24px!important;box-sizing:border-box!important}'
            /* SSOT-1 Onda 5: catálogo/PDP — gutter só no page-main (evita pad duplo). */
            . $catalogHdr . ' .page-wrapper .columns .column.main,'
            . $catalogHdr . ' .page-wrapper .column.main{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . $cmsHdr . ' .page-wrapper .page-main{'
            . 'padding-inline:24px!important;box-sizing:border-box!important}'
            . $cmsHdr . ' .page-wrapper .columns .column.main,'
            . $cmsHdr . ' .page-wrapper .column.main{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* SSOT-1 Onda 2: CMS tablet/desktop — gutter só no inner (24), wrappers 0.
             * Sem isso, align-grid/themes aplicam --awa-header-shell-pad (16) em
             * .header.awa-main-header + inner 24 → rail 40 vs main 24. */
            . $cmsHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky{'
            . 'padding-inline:0!important}'
            . $cmsHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main){'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;'
            . 'padding-right:0!important;margin-inline:0!important;box-sizing:border-box!important}'
            /* SSOT-1 Onda 5: CMS ≥768 — centralizar .container (eixo 1280); gutter só no inner.
             * Pin JS antigo forçava margin 0 no .container → delta header −80@1440. */
            . $cmsHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' .header-main>.container{'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;'
            . 'padding-right:0!important;margin-inline:auto!important;margin-left:auto!important;'
            . 'margin-right:auto!important;max-width:min(100%,1280px)!important;width:100%!important;'
            . 'box-sizing:border-box!important}'
            . $cmsHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            . $catalogHdr . ' .page-wrapper footer.page-footer,'
            . $storefrontHdr . ' .page-wrapper footer.page-footer{'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;box-sizing:border-box!important}'
            . $catalogHdr . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,'
                . '.footer-bottom,.footer-bottom-inner),'
            . $storefrontHdr . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,'
                . '.footer-bottom,.footer-bottom-inner){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* Cart tablet/desktop: mesmo eixo do .page-main (pad 24 + shell 1280). */
            . $cartHdr . '{margin:0!important;width:100%!important;max-width:100%!important;'
            . 'padding-inline:0!important}'
            . $cartHdr . ' .page-wrapper .awa-site-header .header-wrapper-sticky{'
            . 'padding-inline:0!important}'
            . $cartHdr . ' .page-wrapper header.awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container,.header_main>.container){'
            . 'padding:0!important;padding-inline:0!important;margin-inline:0!important;'
            . 'width:100%!important;max-width:100%!important;box-sizing:border-box!important}'
            . $cartHdr . ' .page-wrapper header.awa-site-header .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'width:100%!important;max-width:min(100%,1280px)!important;margin-inline:auto!important;'
            . 'padding-inline:24px!important;box-sizing:border-box!important}'
            . $cartHdr . ' .page-wrapper .page-main{'
            . 'padding-inline:24px!important;box-sizing:border-box!important}'
            . $cartHdr . ' .page-wrapper .columns .column.main,'
            . $cartHdr . ' .page-wrapper .column.main,'
            . $cartHdr . ' .page-wrapper .cart-container{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . $cartHdr . ' .page-wrapper footer.page-footer{'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;box-sizing:border-box!important}'
            . $cartHdr . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,'
                . '.footer-bottom,.footer-bottom-inner){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            /* Mobile: mesmo contrato — shell 16, filhos 0. */
            . '@media(max-width:767px){'
            . $cmsHdr . ' .page-wrapper .page-main{'
            . 'padding-inline:16px!important;box-sizing:border-box!important}'
            /* SSOT-1 Onda 1: CMS filho sem gutter (evita 16+16 no mobile). */
            . $cmsHdr . ' .page-wrapper .columns .column.main,'
            . $cmsHdr . ' .page-wrapper .column.main{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* SSOT-1 Onda 5: catálogo/PDP mobile — filho sem gutter. */
            . $catalogHdr . ' .page-wrapper .page-main{'
            . 'padding-inline:16px!important;box-sizing:border-box!important}'
            . $catalogHdr . ' .page-wrapper .columns .column.main,'
            . $catalogHdr . ' .page-wrapper .column.main{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . $storefrontHdr . ' .page-wrapper footer.page-footer,'
            . $catalogHdr . ' .page-wrapper footer.page-footer,'
            . $cartHdr . ' .page-wrapper footer.page-footer,'
            . $homeDesc(' .page-wrapper footer.page-footer') . '{'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;box-sizing:border-box!important}'
            . $storefrontHdr . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,'
                . '.footer-bottom,.footer-bottom-inner),'
            . $catalogHdr . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,'
                . '.footer-bottom,.footer-bottom-inner),'
            . $cartHdr . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,'
                . '.footer-bottom,.footer-bottom-inner),'
            . $homeDesc(' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,'
                . '.footer-bottom,.footer-bottom-inner)') . '{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            /* PIXEL-QA 2026-07-25: shells B2B/auth — gutter 16/24 (sem header storefront). */
            . '@media(max-width:767px){'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index,.customer-account-login,'
            . '.customer-account-create){'
            . '--b2b-auth-shell-pad-x:16px!important;--awa-page-pad:16px!important}'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index,.customer-account-login,'
            . '.customer-account-create) .page-wrapper :is(.page-main,#maincontent){'
            . 'padding-inline:16px!important;box-sizing:border-box!important}'
            . '}'
            . '@media(min-width:768px){'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index,.customer-account-login,'
            . '.customer-account-create){'
            . '--b2b-auth-shell-pad-x:24px!important;--awa-page-pad:24px!important}'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index,.customer-account-login,'
            . '.customer-account-create) .page-wrapper :is(.page-main,#maincontent){'
            . 'padding-inline:24px!important;box-sizing:border-box!important}'
            . '}'
            /* SSOT-1 Onda 4: B2B account dashboard + OPC checkout — gutter 16/24 fixo. */
            . '@media(max-width:767px){'
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational,.checkout-index-index,.rokanthemes-onepagecheckout,'
            . '.onepagecheckout-index-index){'
            . '--awa-acct-shell-pad:16px!important;--awa-page-pad:16px!important}'
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational,.checkout-index-index,.rokanthemes-onepagecheckout,'
            . '.onepagecheckout-index-index) .page-wrapper :is(.page-main,#maincontent,.page-main.container){'
            . 'padding-inline:16px!important;padding-left:16px!important;padding-right:16px!important;'
            . 'box-sizing:border-box!important}'
            /* SSOT-1 Onda 4b: B2B account header — sticky 16, wrappers/inner 0. */
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational) .page-wrapper .awa-site-header .header-wrapper-sticky{'
            . 'padding-inline:16px!important;box-sizing:border-box!important}'
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational) .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container,'
            . '.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;'
            . 'padding-right:0!important;margin-inline:0!important;margin-left:0!important;'
            . 'margin-right:0!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important}'
            /* CDP 375: page-main 16 + .columns pad 8px 12px → col-main x=28 vs search x=16. */
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational) .page-wrapper :is(.page-main,#maincontent,.page-main.container)'
            . ' > .columns{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            . '@media(min-width:768px){'
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational,.checkout-index-index,.rokanthemes-onepagecheckout,'
            . '.onepagecheckout-index-index){'
            . '--awa-acct-shell-pad:24px!important;--awa-page-pad:24px!important}'
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational,.checkout-index-index,.rokanthemes-onepagecheckout,'
            . '.onepagecheckout-index-index) .page-wrapper :is(.page-main,#maincontent,.page-main.container){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            /* Gutter só no page-main — .columns.row herdava 24 e somava eixo a 48. */
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational) .page-wrapper :is(.page-main,#maincontent,.page-main.container)'
            . ' > .columns{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* SSOT-1 Onda 4b: B2B account header — sticky/wrappers 0, inner 24 (evita 24×N). */
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational) .page-wrapper .awa-site-header .header-wrapper-sticky{'
            . 'padding-inline:0!important}'
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational) .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container){'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;'
            . 'padding-right:0!important;margin-inline:0!important;margin-left:0!important;'
            . 'margin-right:0!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body:is(.account,.b2b-account-shell,.b2b-account-dashboard,'
            . '.awa-account-operational) .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'margin-inline:auto!important;max-width:min(100%,1280px)!important;width:100%!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            . '}'
            . '</style>';

        $html = preg_replace(
            '/<style id="awa-pixel-qa-catalog-hdr-gutter-16">.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<style id="awa-pixel-qa-gutter-terminal">.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script id="awa-pixel-qa-home-gutter-24">.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;
        /* HEAD: first paint (catálogo align-grid em print). */
        $injected = preg_replace('/<\/head>/i', $gutterLock . "\n</head>", $html, 1);
        if (!is_string($injected) || $injected === $html) {
            $injected = $html;
        }
        /* BODY terminal: na home o align-grid é media=all e chega depois do <head>;
         * override final-wins no eixo desktop (sem depender de layer order).
         * Footer axis: depois do site-shell inline (padding 16 em .footer-bottom). */
        $homeTerminal = '<style id="awa-pixel-qa-gutter-terminal">'
            /* Mesma layer do align-grid, depois dele no DOM → final-wins no desktop home. */
            . '@layer awa-fixes{@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]),'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            /* SSOT-1 Onda 3: token shell-pad 24 na home ≥768 (impeccable usava fallback 16). */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
            . '--awa-header-shell-pad:24px!important;--awa-home-shell-gutter:24px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper :is(.page-main,#maincontent){'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home>.top-home-content--above-fold,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home>.top-home-content--above-fold>.banner-slider.banner-slider2{'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important;'
            . 'padding-inline:0!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home>:is(.ayo-home5-wrapper,.ayo-home5-wrapper--template-driven){'
            . 'width:min(100%,var(--awa-home-shell-max,1280px))!important;'
            . 'max-width:min(100%,var(--awa-home-shell-max,1280px))!important;'
            . 'margin-inline:auto!important;padding-inline:var(--awa-home-shell-gutter,24px)!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . '}}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .top-home-content--above-fold .wrapper_slider.hidden-xs'
            . ' .awa-hero__slide--studio-white .banner_item_bg{'
            . 'background-color:var(--awa-red)!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .top-home-content--above-fold .wrapper_slider.hidden-xs'
            . ' .awa-hero__slide--studio-white .banner_item_bg :is(a,picture){'
            . 'display:flex!important;justify-content:flex-end!important;'
            . 'width:100%!important;height:100%!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .top-home-content--above-fold .wrapper_slider.hidden-xs'
            . ' .awa-hero__slide--studio-white .banner_item_bg img{'
            . 'flex:0 0 46%!important;width:46%!important;max-width:46%!important;'
            . 'height:100%!important;margin-inline-start:auto!important;'
            . 'object-fit:cover!important;object-position:100% 50%!important;'
            . 'transform:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .top-home-content--above-fold .wrapper_slider.hidden-xs'
            . ' .awa-hero__slide--studio-white .text-banner.awa-hero-guidao-copy{'
            . 'inset:0 auto 0 0!important;width:min(54%,36rem)!important;height:100%!important;'
            . 'display:flex!important;flex-direction:column!important;justify-content:center!important;'
            . 'background:transparent!important;background-image:none!important;z-index:3!important}'
            . '}'
            /* HOME mobile: o gutter 16px do sticky estava só em @layer awa-pixel-qa.
               Unlayered padding-inline:0 no inner/sticky vencia — busca x=1 vs cart x=29.
               Este bloco é UNLAYERED (id exempt de collapse de #html-body). */
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky{'
            . 'padding-block:0!important;padding-inline:16px!important;'
            . 'padding-left:16px!important;padding-right:16px!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container,'
            . '.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . '}'
            /* PIXEL-QA footer axis — UNLAYERED + após site-shell (unlayered !important vence layer). */
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .page_footer{'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-footer-newsletter>.container,'
            . '.footer-bottom>.container,.awa-footer-trust-bar>.container,'
            . '#footer.footer-container>.container,section.awa-footer-categories-expand>.container){'
            . 'max-width:min(100%,1280px)!important;width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:16px!important;padding-left:16px!important;padding-right:16px!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,#footer.footer-container,'
            . '.footer-container,.footer-bottom,.footer-bottom-inner,.awa-footer-newsletter,'
            . 'section.awa-footer-categories-expand,.awa-footer-devby){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'padding-inline-start:0!important;padding-inline-end:0!important;'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #footer.footer-container .container [class*="col-"],'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .footer-bottom .container [class*="col-"],'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #footer .vela-content{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . '}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .page_footer{'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-footer-newsletter>.container,'
            . '.footer-bottom>.container,.awa-footer-trust-bar>.container,'
            . '#footer.footer-container>.container,section.awa-footer-categories-expand>.container){'
            . 'max-width:min(100%,1280px)!important;width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,#footer.footer-container,'
            . '.footer-container,.footer-bottom,.footer-bottom-inner,.awa-footer-newsletter,'
            . 'section.awa-footer-categories-expand,.awa-footer-devby){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'padding-inline-start:0!important;padding-inline-end:0!important;'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            /* Eixo 1280 literal — depois do bleed das faixas (final-wins). */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-footer-trust-bar>.container,'
            . '.awa-footer-newsletter>.container,#footer.footer-container>.container,'
            . 'section.awa-footer-categories-expand>.container,.footer-bottom>.container,'
            . '.awa-footer-devby>.container){'
            . 'box-sizing:border-box!important;width:min(100%,1280px)!important;'
            . 'max-width:1280px!important;max-inline-size:1280px!important;'
            . 'margin-inline:auto!important;margin-left:auto!important;margin-right:auto!important}'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-footer-trust-bar>.container,'
            . '.awa-footer-newsletter>.container,#footer.footer-container>.container,'
            . 'section.awa-footer-categories-expand>.container,.footer-bottom>.container,'
            . '.awa-footer-devby>.container){'
            . 'padding-inline:16px!important;padding-left:16px!important;padding-right:16px!important}}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-footer-trust-bar>.container,'
            . '.awa-footer-newsletter>.container,#footer.footer-container>.container,'
            . 'section.awa-footer-categories-expand>.container,.footer-bottom>.container,'
            . '.awa-footer-devby>.container){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important}}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .footer-bottom .awa-footer-bottom__row{'
            . 'justify-items:stretch!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .footer-bottom .awa-footer-bottom__logo-col,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .footer-bottom .awa-footer-bottom__row>[class*="col-"]:first-child{'
            . 'justify-self:start!important;text-align:start!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer ul.awa-footer-categories-list a{'
            . 'border-radius:999px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-wrapper{'
            . 'display:flex!important;flex-wrap:nowrap!important;grid-template-columns:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-info{'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'gap:8px!important;grid-template-columns:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-icon{'
            . 'display:inline-flex!important;flex:0 0 24px!important;width:24px!important;height:24px!important;'
            . 'min-width:24px!important;min-height:24px!important;max-width:24px!important;max-height:24px!important;'
            . 'color:var(--awa-primary)!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-newsletter-form-container,.newsletter-footer,'
            . '#newsletter-validate-detail){'
            . 'border:0!important;background:transparent!important;box-shadow:none!important;margin:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-footer-trust-copy{'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;gap:4px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-footer-trust-copy :is(strong,span){'
            . 'display:block!important;position:static!important}'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-wrapper{'
            . 'flex-direction:column!important;align-items:stretch!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-info{'
            . 'flex:1 1 auto!important;max-width:none!important}}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-wrapper{'
            . 'flex-direction:row!important;align-items:center!important;justify-content:flex-start!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-info{'
            . 'flex:0 1 28rem!important;max-width:28rem!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail{'
            . 'display:block!important;width:100%!important;grid-template-columns:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail .field.newsletter{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;'
            . 'grid-template-rows:44px auto!important;align-items:start!important;'
            . 'column-gap:8px!important;row-gap:6px!important;'
            . 'width:100%!important;max-width:100%!important;height:auto!important;min-height:44px!important;'
            . 'padding:0!important;padding-block:0!important;'
            . 'border:0!important;background:transparent!important;box-shadow:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail .control{'
            . 'grid-column:1!important;grid-row:1!important;min-width:0!important;width:auto!important;'
            . 'max-width:none!important;align-self:stretch!important;height:44px!important;'
            . 'margin:0!important;padding:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail input[type=email]{'
            . 'width:100%!important;min-width:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail .actions{'
            . 'grid-column:2!important;grid-row:1!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail :is(.actions,button.action.subscribe){'
            . 'width:auto!important;max-width:none!important;white-space:nowrap!important;'
            . 'align-self:stretch!important;height:44px!important;margin:0!important}}'
            /* CMS/404/contato — UNLAYERED: vence impeccable-refine clamp(12px,2.5vw,24px). */
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-page-view,.cms-noroute-index,.contact-index-index,'
            . '.curriculo-index-index,.curriculo-index-status)'
            . ' .page-wrapper :is(.page-main,#maincontent.page-main,.page-main.container){'
            . 'padding-inline:16px!important;padding-left:16px!important;padding-right:16px!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-page-view,.cms-noroute-index,.contact-index-index,'
            . '.curriculo-index-index,.curriculo-index-status)'
            . ' .page-wrapper :is(.columns .column.main,.column.main){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-page-view,.cms-noroute-index,.contact-index-index,'
            . '.curriculo-index-index,.curriculo-index-status)'
            . ' .page-wrapper :is(.page-main,#maincontent.page-main,.page-main.container){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-page-view,.cms-noroute-index,.contact-index-index,'
            . '.curriculo-index-index,.curriculo-index-status)'
            . ' .page-wrapper :is(.columns .column.main,.column.main){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* SSOT-1 Onda 2: unlayered — zera pad do wrapper do header no CMS ≥768. */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-page-view,.cms-noroute-index,.contact-index-index,'
            . '.curriculo-index-index,.curriculo-index-status)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main){'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;'
            . 'padding-right:0!important;margin-inline:0!important;box-sizing:border-box!important}'
            /* SSOT-1 Onda 5 UNLAYERED: CMS ≥768 — .container centrado; gutter no inner. */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-page-view,.cms-noroute-index,.contact-index-index,'
            . '.curriculo-index-index,.curriculo-index-status)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky .header-main>.container{'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;'
            . 'padding-right:0!important;margin-inline:auto!important;margin-left:auto!important;'
            . 'margin-right:auto!important;max-width:min(100%,1280px)!important;width:100%!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-page-view,.cms-noroute-index,.contact-index-index,'
            . '.curriculo-index-index,.curriculo-index-status)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            /* SSOT-1 Onda 5 UNLAYERED: catálogo/PDP — .column.main sem pad horizontal. */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view,'
            . '.catalogo-index-index,.awa-catalog-page)'
            . ' .page-wrapper :is(.columns .column.main,.column.main){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* Cart: .column.main / .cart-container sem pad horizontal (gutter só no page-main). */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '.checkout-cart-index .page-wrapper .columns .column.main,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '.checkout-cart-index .page-wrapper .column.main,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '.checkout-cart-index .page-wrapper .cart-container{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* SSOT-1 Onda 4 UNLAYERED: B2B account + OPC — vence align-grid 16@768. */
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.account,.b2b-account-shell,.b2b-account-dashboard,.awa-account-operational,'
            . '.checkout-index-index,.rokanthemes-onepagecheckout,.onepagecheckout-index-index)'
            . ' .page-wrapper :is(.page-main,#maincontent,.page-main.container){'
            . 'padding-inline:16px!important;padding-left:16px!important;padding-right:16px!important;'
            . 'box-sizing:border-box!important}}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.account,.b2b-account-shell,.b2b-account-dashboard,.awa-account-operational,'
            . '.checkout-index-index,.rokanthemes-onepagecheckout,.onepagecheckout-index-index)'
            . ' .page-wrapper :is(.page-main,#maincontent,.page-main.container){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            /* SSOT-1 Onda 4b UNLAYERED: B2B account header axis. */
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.account,.b2b-account-shell,.b2b-account-dashboard,.awa-account-operational)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky{padding-inline:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.account,.b2b-account-shell,.b2b-account-dashboard,.awa-account-operational)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.header-main,.header-main>.container){'
            . 'padding:0!important;padding-inline:0!important;padding-left:0!important;'
            . 'padding-right:0!important;margin-inline:0!important;margin-left:0!important;'
            . 'margin-right:0!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.account,.b2b-account-shell,.b2b-account-dashboard,.awa-account-operational)'
            . ' .page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'margin-inline:auto!important;max-width:min(100%,1280px)!important;width:100%!important;'
            . 'box-sizing:border-box!important}}'
            . '</style>'
            /* ≥768: awa-visual-priority (deferred-stack) vence stylesheet no padding do
             * inner a partir de 992px; só style="" !important supera.
             * Footer: pin do impeccable recoloca <style> por último — setProperty vence. */
            . '<script id="awa-pixel-qa-home-gutter-24">'
            . '(function(){function a(){var on=window.matchMedia("(min-width:768px)").matches;'
            . 'var pad=on?"24px":"16px";'
            . 'var n=document.querySelectorAll(".awa-site-header .awa-main-header__inner");'
            . 'for(var i=0;i<n.length;i++){}'
            . 'var foot=document.querySelector("footer.page-footer");'
            . 'if(foot){foot.style.setProperty("max-width","none","important");'
            . 'foot.style.setProperty("width","100%","important");'
            . 'foot.style.setProperty("margin-left","0","important");'
            . 'foot.style.setProperty("margin-right","0","important");'
            . 'foot.style.setProperty("margin-inline","0","important");'
            . 'foot.style.setProperty("padding-inline","0","important");'
            . 'foot.style.setProperty("padding-left","0","important");'
            . 'foot.style.setProperty("padding-right","0","important");'
            . 'foot.style.setProperty("box-sizing","border-box","important");}'
            . 'var pageFooter=document.querySelector("footer.page-footer .page_footer");'
            . 'if(pageFooter){pageFooter.style.setProperty("max-width","none","important");'
            . 'pageFooter.style.setProperty("width","100%","important");'
            . 'pageFooter.style.setProperty("margin-left","0","important");'
            . 'pageFooter.style.setProperty("margin-right","0","important");'
            . 'pageFooter.style.setProperty("margin-inline","0","important");'
            . 'pageFooter.style.setProperty("padding-inline","0px","important");'
            . 'pageFooter.style.setProperty("padding-left","0px","important");'
            . 'pageFooter.style.setProperty("padding-right","0px","important");'
            . 'pageFooter.style.setProperty("box-sizing","border-box","important");}'
            . 'var kids=document.querySelectorAll("footer.page-footer #footer,'
            . 'footer.page-footer .footer-container,footer.page-footer .footer-bottom,'
            . 'footer.page-footer .footer-bottom-inner");'
            . 'for(var k=0;k<kids.length;k++){'
            . 'kids[k].style.setProperty("padding-inline","0px","important");'
            . 'kids[k].style.setProperty("padding-left","0px","important");'
            . 'kids[k].style.setProperty("padding-right","0px","important");'
            . 'if(kids[k].classList&&(kids[k].classList.contains("footer-bottom")||kids[k].id==="footer"'
            . '||kids[k].classList.contains("footer-container"))){'
            . 'kids[k].style.setProperty("margin-inline","0px","important");'
            . 'kids[k].style.setProperty("max-width","100%","important");'
            . 'kids[k].style.setProperty("width","100%","important");}}'
            . 'var b=document.body&&document.body.classList;'
            . 'if(b&&(b.contains("cms-page-view")||b.contains("cms-noroute-index")'
            . '||b.contains("contact-index-index")||b.contains("curriculo-index-index")'
            . '||b.contains("curriculo-index-status")'
            . '||b.contains("b2b-account-shell")||b.contains("b2b-account-dashboard")'
            . '||b.contains("awa-account-operational")||b.contains("account"))){'
            . 'var mains=document.querySelectorAll(".page-wrapper .page-main,#maincontent.page-main");'
            . 'for(var m=0;m<mains.length;m++){'
            . 'if(b.contains("cms-page-view")||b.contains("cms-noroute-index")'
            . '||b.contains("contact-index-index")||b.contains("curriculo-index-index")'
            . '||b.contains("curriculo-index-status")'
            . '||b.contains("b2b-account-shell")||b.contains("b2b-account-dashboard")'
            . '||b.contains("awa-account-operational")){'
            . 'mains[m].style.setProperty("padding-inline",pad,"important");'
            . 'mains[m].style.setProperty("padding-left",pad,"important");'
            . 'mains[m].style.setProperty("padding-right",pad,"important");'
            . 'mains[m].style.setProperty("box-sizing","border-box","important");}}'
            /* SSOT-1 Onda 1: CMS .column.main pad-inline 0 (gutter só no page-main). */
            . 'if(b.contains("cms-page-view")||b.contains("cms-noroute-index")'
            . '||b.contains("contact-index-index")||b.contains("curriculo-index-index")'
            . '||b.contains("curriculo-index-status")){'
            . 'var cmsCols=document.querySelectorAll(".page-wrapper .columns .column.main,'
            . '.page-wrapper .column.main");'
            . 'for(var cc=0;cc<cmsCols.length;cc++){'
            . 'cmsCols[cc].style.setProperty("padding-inline","0px","important");'
            . 'cmsCols[cc].style.setProperty("padding-left","0px","important");'
            . 'cmsCols[cc].style.setProperty("padding-right","0px","important");}}'
            /* SSOT-1 Onda 2/4b/5: wrappers do header sem pad; gutter no inner/container.
             * NÃO zerar margin no .header-main>.container (quebrava eixo CMS @1440). */
            . 'if(b.contains("cms-page-view")||b.contains("cms-noroute-index")'
            . '||b.contains("contact-index-index")||b.contains("curriculo-index-index")'
            . '||b.contains("curriculo-index-status")'
            . '||b.contains("b2b-account-shell")||b.contains("b2b-account-dashboard")'
            . '||b.contains("awa-account-operational")){'
            . 'var cmsHdrWrap=document.querySelectorAll(".page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' .header.awa-main-header,.page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' .header_main.awa-main-header-inner-wrap,.page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' .header-main");'
            . 'for(var hw=0;hw<cmsHdrWrap.length;hw++){'
            . 'cmsHdrWrap[hw].style.setProperty("padding-inline","0px","important");'
            . 'cmsHdrWrap[hw].style.setProperty("padding-left","0px","important");'
            . 'cmsHdrWrap[hw].style.setProperty("padding-right","0px","important");'
            . 'cmsHdrWrap[hw].style.setProperty("margin-inline","0px","important");'
            . 'cmsHdrWrap[hw].style.setProperty("margin-left","0px","important");'
            . 'cmsHdrWrap[hw].style.setProperty("margin-right","0px","important");}'
            . 'var stickyEl=document.querySelector(".page-wrapper .awa-site-header .header-wrapper-sticky");'
            . 'if(stickyEl){stickyEl.style.setProperty("padding-inline",on?"0px":"16px","important");'
            . 'stickyEl.style.setProperty("padding-left",on?"0px":"16px","important");'
            . 'stickyEl.style.setProperty("padding-right",on?"0px":"16px","important");}'
            /* SSOT-1 Onda 5: CMS — centrar .container ≥768; gutter 24 só no inner. */
            . 'if(b.contains("cms-page-view")||b.contains("cms-noroute-index")'
            . '||b.contains("contact-index-index")||b.contains("curriculo-index-index")'
            . '||b.contains("curriculo-index-status")){'
            . 'var cmsCont=document.querySelectorAll(".page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' .header-main>.container");'
            . 'for(var cc2=0;cc2<cmsCont.length;cc2++){'
            . 'cmsCont[cc2].style.setProperty("padding-inline","0px","important");'
            . 'cmsCont[cc2].style.setProperty("padding-left","0px","important");'
            . 'cmsCont[cc2].style.setProperty("padding-right","0px","important");'
            . 'if(on){cmsCont[cc2].style.setProperty("margin-inline","auto","important");'
            . 'cmsCont[cc2].style.setProperty("margin-left","auto","important");'
            . 'cmsCont[cc2].style.setProperty("margin-right","auto","important");'
            . 'cmsCont[cc2].style.setProperty("max-width","min(100%,1280px)","important");'
            . 'cmsCont[cc2].style.setProperty("width","100%","important");}'
            . 'else{cmsCont[cc2].style.setProperty("margin-inline","0px","important");}}'
            . 'var cmsInner=document.querySelectorAll(".page-wrapper .awa-site-header .header-wrapper-sticky'
            . ' .awa-main-header__inner");'
            . 'for(var ci=0;ci<cmsInner.length;ci++){'
            . 'if(on){cmsInner[ci].style.setProperty("padding-inline","24px","important");'
            . 'cmsInner[ci].style.setProperty("padding-left","24px","important");'
            . 'cmsInner[ci].style.setProperty("padding-right","24px","important");}'
            . 'else{cmsInner[ci].style.setProperty("padding-inline","0px","important");'
            . 'cmsInner[ci].style.setProperty("padding-left","0px","important");'
            . 'cmsInner[ci].style.setProperty("padding-right","0px","important");}}}}}'
            . 'if(b&&b.contains("checkout-cart-index")){'
            . 'var cols=document.querySelectorAll(".page-wrapper .columns .column.main,'
            . '.page-wrapper .column.main,.page-wrapper .cart-container");'
            . 'for(var c=0;c<cols.length;c++){'
            . 'cols[c].style.setProperty("padding-inline","0px","important");'
            . 'cols[c].style.setProperty("padding-left","0px","important");'
            . 'cols[c].style.setProperty("padding-right","0px","important");}}'
            /* SSOT-1 Onda 5: PDP/PLP/search — .column.main pad 0 (vence impeccable clamp). */
            . 'if(b&&(b.contains("catalog-product-view")||b.contains("catalog-category-view")'
            . '||b.contains("catalogsearch-result-index")||b.contains("catalogo-index-index")'
            . '||b.contains("awa-catalog-page"))){'
            . 'var catCols=document.querySelectorAll(".page-wrapper .columns .column.main,'
            . '.page-wrapper .column.main");'
            . 'for(var cx=0;cx<catCols.length;cx++){'
            . 'catCols[cx].style.setProperty("padding-inline","0px","important");'
            . 'catCols[cx].style.setProperty("padding-left","0px","important");'
            . 'catCols[cx].style.setProperty("padding-right","0px","important");}}'
            . '}'
            . 'var __awaPxQueued=false,__awaPxLastKey="";'
            . 'function runA(){__awaPxQueued=false;'
            . 'var b=(document.body&&document.body.className)||"";'
            . 'var k=(window.matchMedia&&window.matchMedia("(min-width:768px)").matches?1:0)'
            . '+"|"+b+"|"+document.querySelectorAll(".awa-site-header .awa-main-header__inner").length;'
            . 'if(k===__awaPxLastKey){return;}__awaPxLastKey=k;a();}'
            . 'function scheduleA(){if(__awaPxQueued){return;}__awaPxQueued=true;'
            . 'if(window.requestAnimationFrame){window.requestAnimationFrame(runA);}else{window.setTimeout(runA,0);}}'
            . 'if(document.readyState==="loading"){'
            . 'document.addEventListener("DOMContentLoaded",scheduleA,{once:true});'
            . '}else{scheduleA();}'
            . 'window.addEventListener("resize",scheduleA,{passive:true});'
            . 'window.addEventListener("load",scheduleA,{once:true,passive:true});'
            . 'window.setTimeout(scheduleA,900);window.setTimeout(scheduleA,2500);})();</script>';
        $withTerminal = preg_replace('/<\/body>/i', $homeTerminal . "\n</body>", $injected, 1);
        $htmlOut = is_string($withTerminal) ? $withTerminal : (is_string($injected) ? $injected : $html);

        /* PIXEL-QA: neutraliza dense-inline do footer (phtml/boot) que força padding 2px 16px. */
        $htmlOut = str_replace(
            [
                "setProperty('padding','2px 16px','important')",
                'setProperty("padding","2px 16px","important")',
            ],
            [
                "setProperty('padding','2px 0','important')",
                'setProperty("padding","2px 0","important")',
            ],
            $htmlOut
        );

        /* Garante PIXEL-QA footer axis em TODOS os blocos impeccable (getElementById = 1º). */
        $footerAxisAppend = HeaderImpeccableCascadeLockCss::pixelQaFooterAxisTerminalRules();
        $htmlOut = preg_replace_callback(
            '/(<style\s+id="' . preg_quote(HeaderImpeccableCascadeLockCss::STYLE_ID, '/') . '"[^>]*>)(.*?)(<\/style>)/is',
            static function (array $m) use ($footerAxisAppend): string {
                if (str_contains($m[2], 'PIXEL-QA footer-axis-terminal')) {
                    return $m[0];
                }

                return $m[1] . $m[2] . $footerAxisAppend . $m[3];
            },
            $htmlOut
        ) ?? $htmlOut;

        return $htmlOut;
    }

    /**
     * Header mobile: mantém o grid de topo fiel ao DOM real
     * (toggle/brand/cart como grid items diretos; busca na segunda linha).
     */
    private function enforcePrimaryRowResponsiveContract(string $html): string
    {
        $marker = 'awa-primary-row-responsive-contract-v20260704';
        if (str_contains($html, $marker)) {
            return $html;
        }

        $contractCss = '/* ' . $marker . ' */'
            . '@media(min-width:768px){html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-primary-row{display:contents!important}}'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){display:grid!important;grid-template:"toggle brand cart" 44px "search search search" 44px/44px minmax(0,1fr) 44px!important;gap:4px 8px!important;height:96px!important;min-height:96px!important;max-height:96px!important;padding:4px 0 0!important;padding-inline:0!important;align-content:start!important;align-items:center!important;overflow:visible!important}'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-primary-row{display:contents!important}'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-mobile-toggle{grid-area:toggle!important;justify-self:start!important;align-self:center!important;width:44px!important;height:44px!important}'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-brand-cell{grid-area:brand!important;justify-self:center!important;align-self:center!important;min-width:0!important;max-width:160px!important;height:44px!important;overflow:visible!important}'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-search-col{grid-area:search!important;display:block!important;justify-self:stretch!important;min-width:0!important;max-width:100%!important;width:100%!important;height:44px!important}'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col{grid-area:cart!important;align-items:center!important;display:flex!important;justify-content:flex-end!important;justify-self:end!important;min-width:44px!important;max-width:44px!important;width:44px!important;height:44px!important;overflow:visible!important}'
            /* BUG-B2B-PANEL-MOBILE-2026-07-07: quando o painel B2B (.b2b-status-panel) está
             * presente, a coluna "cart" do grid mobile precisa de mais espaço que os 44px
             * fixos usados apenas para o ícone do carrinho — senão o botão "Olá, Fernando"
             * fica com width:0 (display:flex, mas invisível) neste breakpoint. */
            /* 2026-07-16: grid-template shorthand (44px na 3ª track) vencia grid-template-columns
             * sozinho — painel B2B estourava p/ esquerda sobre o logo. Reaplica o shorthand completo. */
            . 'html body#html-body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky:has(.b2b-status-panel) :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){grid-template:"toggle brand cart" 44px "search search search" 44px/44px minmax(0,1fr) minmax(96px,auto)!important;grid-template-columns:44px minmax(0,1fr) minmax(96px,auto)!important}'
            . 'html body#html-body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel){min-width:96px!important;max-width:min(140px,38vw)!important;width:auto!important;gap:2px!important;overflow:hidden!important;justify-self:end!important}'
            . 'html body#html-body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky:has(.b2b-status-panel) .awa-header-brand-cell{max-width:min(120px,32vw)!important;overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel) .b2b-status-panel{width:auto!important;max-width:min(88px,24vw)!important;min-width:0!important;overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel) .b2b-status-trigger{color:var(--awa-text-primary,#0f172a)!important;max-width:100%!important}'
            . 'html body#html-body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel) :is(.b2b-status-trigger__line1,.b2b-status-trigger__text){color:var(--awa-text-primary,#0f172a)!important;max-width:6ch!important}'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-minicart:not(:has(.minicart-wrapper.active)):not(:has(.minicart-wrapper.show)):not(:has(.minicart-wrapper.is-open)),'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .minicart-wrapper:not(.active):not(.show):not(.is-open),'
            . 'html body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .minicart-wrapper:not(.active):not(.show):not(.is-open) :is(.action.showcart,a.showcart.header-mini-cart){align-items:center!important;display:inline-flex!important;float:none!important;justify-content:center!important;justify-self:auto!important;place-self:center!important;transform:none!important;width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;margin:0!important;flex-shrink:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper header.awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky :is(.awa-header-minicart,.minicart-wrapper){position:relative!important;overflow:visible!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper header.awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-minicart .minicart-wrapper :is(.action.showcart,a.showcart.header-mini-cart){float:none!important;position:relative!important;inset:auto!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;transform:none!important;place-self:center!important;justify-self:auto!important;margin:0!important}'
            . 'html body#html-body:not(#__awa-no-match):not(#__awa-no-match):not(#__awa-no-match):not(#__awa-no-match):not(.b2b-auth-shell):not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper header.awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky .awa-header-minicart .minicart-wrapper :is(.action.showcart,a.showcart.header-mini-cart){float:none!important;position:relative!important;inset:auto!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;transform:none!important;place-self:center!important;justify-self:auto!important;margin:0!important}'
            . '}';

        $patched = preg_replace_callback(
            '/(<style id="awa-align-grid-inline-lock[^"]*"[^>]*>)(.*?)(<\/style>)/is',
            static function (array $matches) use ($contractCss): string {
                return $matches[1] . $matches[2] . $contractCss . $matches[3];
            },
            $html,
            1
        );

        return is_string($patched) ? $patched : $html;
    }

    /**
     * PLP/PDP/carrinho/auth: remove bloco legacy de head-preload (sem id, ~70–78KB).
     * Home e painel B2B logado preservam o comportamento atual (header/footer no DOM).
     */
    private function stripHeavyHeadPreloadInlineStyleOutsideHome(
        string $html,
        string $fullAction,
        bool $isAuthFocusPage,
        bool $isB2bAccountFocusPage
    ): string {
        if ($fullAction === self::HOME_ACTION || ($isB2bAccountFocusPage && !$isAuthFocusPage)) {
            return $html;
        }

        if (!str_contains($html, '--head-preload-c1:')) {
            return $html;
        }

        $stripped = preg_replace_callback(
            '/<style\b([^>]*)>(.*?)<\/style>\s*/is',
            static function (array $matches): string {
                $attrs = $matches[1] ?? '';
                $body = $matches[2] ?? '';

                if (preg_match('/\bid\s*=/i', $attrs)) {
                    return $matches[0];
                }

                if (!str_contains($body, '--head-preload-c1:')
                    || !str_contains($body, '--head-preload-footer-surface:')
                ) {
                    return $matches[0];
                }

                return '';
            },
            $html
        );

        return is_string($stripped) ? $stripped : $html;
    }

    /**
     * Única instância do align-grid — remove head/body-end duplicados e reinjeta só antes de </body>
     * (final-wins sobre bundles async). Economiza ~37KB de download duplicado na home/PLP.
     */
    private function consolidateAlignGridToBodyTerminal(string $html, string $fullAction): string
    {
        $html = $this->normalizeAlignGridStylesheetVersion($html);
        // PSI 2026-07-17: home também defer (665KB align-grid + density). Inline lock cobre 1º paint.
        $deferAlignGrid = in_array($fullAction, self::DEFER_ALIGN_GRID_ACTIONS, true);

        if (!str_contains($html, 'awa-align-grid-terminal-2026-06-11')) {
            return $this->injectAlignGridBodyTerminalIfMissing($html, $deferAlignGrid, $fullAction);
        }

        $pattern = '/<link\s[^>]*awa-align-grid-terminal-2026-06-11[^>]*\/?>\s*/i';
        $html = preg_replace($pattern, '', $html) ?? $html;

        return $this->injectAlignGridBodyTerminalIfMissing($html, $deferAlignGrid, $fullAction);
    }

    /**
     * BUG-SHELL-SSOT-DUP-001 — uma única instância de awa-m2-visual-ssot após align-grid.
     *
     * Remove cópias early (layout XML / FPC / loader) e reinjeta terminal com
     * data-awa-m2-visual-ssot, imediatamente após o último link align-grid quando
     * existir (Home/PLP sem loader também ficam cobertos).
     */
    private function consolidateM2VisualSsotTerminal(string $html, string $fullAction): string
    {
        $html = preg_replace('/<link\s[^>]*awa-m2-visual-ssot[^>]*\/?>\s*/i', '', $html) ?? $html;

        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $href = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . HeaderImpeccableCascadeLockCss::M2_VISUAL_SSOT_FILE
            . HeaderImpeccableCascadeLockCss::M2_VISUAL_SSOT_QUERY;

        $tag = '<link rel="stylesheet" href="' . $href . '" media="all"'
            . ' data-awa-m2-visual-ssot="1" data-awa-bundle="m2-visual-ssot"/>';

        if (preg_match_all('/<link\s[^>]*awa-align-grid-terminal[^>]*\/?>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            $last = $matches[0][array_key_last($matches[0])];
            $pos = $last[1] + strlen($last[0]);

            return substr($html, 0, $pos) . "\n" . $tag . substr($html, $pos);
        }

        $isHome = $fullAction === self::HOME_ACTION;
        $injectionPattern = $isHome ? '/<\/head>/i' : '/<\/body>/i';
        $closingTag = $isHome ? '</head>' : '</body>';
        $injected = preg_replace($injectionPattern, $tag . "\n" . $closingTag, $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Normaliza query string stale do align-grid (evita 2 versões no mesmo HTML).
     */
    private function normalizeAlignGridStylesheetVersion(string $html): string
    {
        $file = HeaderImpeccableCascadeLockCss::ALIGN_GRID_CSS_FILE;
        $query = HeaderImpeccableCascadeLockCss::ALIGN_GRID_QUERY;
        $canonical = $file . $query;

        $html = preg_replace(
            '/awa-align-grid-terminal-2026-06-11(?:\.min)?\.css\?v=[^"\'&\s>]+/',
            $canonical,
            $html
        ) ?? $html;

        // Mantém só o link ativo (body-terminal > head sync); remove duplicatas pós-normalização.
        $pattern = '/<link\s[^>]*href=(["\'])' . preg_quote($canonical, '/') . '\1[^>]*\/?>\s*/i';
        if (!preg_match_all($pattern, $html, $matches) || count($matches[0]) < 2) {
            return $html;
        }

        $keepBody = null;
        foreach ($matches[0] as $tag) {
            if (str_contains($tag, 'data-awa-align-grid-body-terminal')) {
                $keepBody = $tag;
                break;
            }
        }

        $keep = $keepBody ?? $matches[0][array_key_last($matches[0])];
        $first = true;

        return preg_replace_callback(
            $pattern,
            static function (array $m) use (&$first, $keep): string {
                if ($first) {
                    $first = false;

                    return $keep;
                }

                return '';
            },
            $html
        ) ?? $html;
    }

    /**
     * Última camada CSS — reinjeta align-grid antes de </body> para vencer body-end/polish-type.
     */
    private function injectAlignGridBodyTerminalIfMissing(string $html, bool $defer = false, string $fullAction = ''): string
    {
        $hasAlignGridBodyTerminal = str_contains($html, 'data-awa-align-grid-body-terminal="1"');
        $hasHeaderContractBodyTerminal = str_contains($html, 'data-awa-header-contract-grid-body-terminal="1"');
        if ($hasAlignGridBodyTerminal && $hasHeaderContractBodyTerminal) {
            return $html;
        }

        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $href = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . HeaderImpeccableCascadeLockCss::ALIGN_GRID_CSS_FILE
            . HeaderImpeccableCascadeLockCss::ALIGN_GRID_QUERY;
        $contractHref = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . $this->manifest->file('header-contract-grid')
            . $this->manifest->query('header-contract-grid');
        $isHome = $fullAction === self::HOME_ACTION;
        $isCatalogDensity = in_array($fullAction, self::CATALOG_STACK_ACTIONS, true)
            || in_array($fullAction, self::CATALOG_HEADER_ACTIONS, true);
        $hasHomeDensityHref = $this->manifest->file('home-density-grid') !== '';
        $hasCatalogDensityHref = $this->manifest->file('catalog-density-grid') !== '';
        $homeDensityHref = $hasHomeDensityHref
            ? '/static/' . $versionMatch[1]
                . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
                . $this->manifest->file('home-density-grid')
                . $this->manifest->query('home-density-grid')
            : '';
        $catalogDensityHref = $hasCatalogDensityHref
            ? '/static/' . $versionMatch[1]
                . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
                . $this->manifest->file('catalog-density-grid')
                . $this->manifest->query('catalog-density-grid')
            : '';

        if ($defer) {
            /* Home/PLP/busca: inline lock cobre container/grid no 1º paint — folha completa async */
            $tag = '';
            if (!$hasAlignGridBodyTerminal) {
                if ($isHome) {
                    /* 51.58: css-gate stripped on home (51.52 hang). media=print+data-awa-gate
                       left align-grid stuck print-only — screen never got grid locks.
                       Serve media=all from the start (no MO / no body-class observer). */
                    $tag .= '<link rel="stylesheet" href="' . $href . '" media="all"'
                        . ' data-awa-align-grid-body-terminal="1"'
                        . ' data-awa-bundle="align-grid-terminal"/>';
                } else {
                    /* Cart (defer path): print→all via onload; no css-gate on these routes. */
                    $tag .= '<link rel="stylesheet" href="' . $href . '" media="print" onload="this.media=\'all\'"'
                        . ' data-awa-align-grid-body-terminal="1"'
                        . ' data-awa-bundle="align-grid-terminal" data-awa-defer="1"/>';
                }
            }
            /* home-density-grid é exclusivo da home (carregado no stack dedicado);
               não reinjetar em PLP/PDP para evitar custo de parse/reflow cross-page. */
            if ($hasCatalogDensityHref
                && $isCatalogDensity
                && !str_contains($html, 'data-awa-catalog-density-grid-body-terminal="1"')
            ) {
                $tag .= '<link rel="stylesheet" href="' . $catalogDensityHref . '" media="all"'
                    . ' data-awa-catalog-density-grid-body-terminal="1" data-awa-bundle="catalog-density-grid"/>';
            }
            if (!$hasHeaderContractBodyTerminal) {
                /* Home: media=all no head (tokens geom no 1º paint). Demais: print→all. */
                if ($isHome) {
                    $tag .= '<link rel="stylesheet" href="' . $contractHref . '" media="all"'
                        . ' data-awa-header-contract-grid-body-terminal="1" data-awa-bundle="header-contract-grid"/>';
                } else {
                    $tag .= '<link rel="stylesheet" href="' . $contractHref . '" media="print" onload="this.media=\'all\'"'
                        . ' data-awa-header-contract-grid-body-terminal="1" data-awa-bundle="header-contract-grid" data-awa-defer="1"/>';
                }
            }
        } else {
            $tag = '';
            if (!$hasAlignGridBodyTerminal) {
                $tag .= '<link rel="stylesheet" href="' . $href . '" media="all"'
                    . ' data-awa-align-grid-body-terminal="1" data-awa-bundle="align-grid-terminal"/>';
            }
            if ($hasHomeDensityHref
                && $isHome
                && !str_contains($html, 'data-awa-home-density-grid-body-terminal="1"')
            ) {
                $tag .= '<link rel="stylesheet" href="' . $homeDensityHref . '" media="all"'
                    . ' data-awa-home-density-grid-body-terminal="1" data-awa-bundle="home-density-grid"/>';
            }
            if ($hasCatalogDensityHref
                && $isCatalogDensity
                && !str_contains($html, 'data-awa-catalog-density-grid-body-terminal="1"')
            ) {
                $tag .= '<link rel="stylesheet" href="' . $catalogDensityHref . '" media="all"'
                    . ' data-awa-catalog-density-grid-body-terminal="1" data-awa-bundle="catalog-density-grid"/>';
            }
            if (!$hasHeaderContractBodyTerminal) {
                $tag .= '<link rel="stylesheet" href="' . $contractHref . '" media="all"'
                    . ' data-awa-header-contract-grid-body-terminal="1" data-awa-bundle="header-contract-grid"/>';
            }
        }

        if ($tag === '') {
            return $html;
        }

        /* PLP/PDP/busca: media=all no </head> — body-end print→all deslocava a grade após o 1º paint. */
        $injectInHead = $isHome || $isCatalogDensity;
        $injectionPattern = $injectInHead ? '/<\/head>/i' : '/<\/body>/i';
        $closingTag = $injectInHead ? '</head>' : '</body>';
        $injected = preg_replace($injectionPattern, $tag . "\n" . $closingTag, $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * PLP/busca/PDP: folhas não críticas para header/above-fold no 1º paint.
     */
    private function deferCatalogNonCriticalStylesheets(string $html): string
    {
        return $this->deferStylesheetsByFragments($html, $this->manifest->catalogDeferFragments());
    }

    /**
     * Converte stylesheets síncronos em async (media=print + onload).
     *
     * @param string[] $fragments
     */
    private function deferStylesheetsByFragments(string $html, array $fragments): string
    {
        foreach ($fragments as $fragment) {
            $escaped = preg_quote($fragment, '/');
            $html = preg_replace_callback(
                '/<link\s+([^>]*?' . $escaped . '[^>]*?)\s*\/?>/i',
                static function (array $matches): string {
                    $attrs = $matches[1];
                    if (!preg_match('/rel=["\']stylesheet["\']/i', $attrs)) {
                        return $matches[0];
                    }
                    if (preg_match('/media=["\']print["\']/i', $attrs) || preg_match('/onload=/i', $attrs)) {
                        return $matches[0];
                    }
                    /* Guard CLS/LCP (05/10/2026 - lead, opcao B). O template awa-home-critical-stack.phtml
                       declara a criticidade de first paint no proprio <link> com data-awa-defer="home-lcp".
                       Este metodo e um FALLBACK para o caso de o layout nao remover um <link> bloqueante
                       orfao (FPC velho) e por isso NAO pode reescrever um link que se declarou
                       first-paint-critical: quem declara a criticidade e o template, nao o fallback.
                       SEM este guard o <link id="awa-design-system-home"> saia media="print"
                       onload="this.media='all'" e a home media CLS 1.7012 (um unico shift de 0.9931 em
                       t=2017ms, FCP=1612ms; o header inteiro saltava: body margin 8px -> 0).
                       Meta: CLS <= 0.10. Nao remover sem medir de novo.
                       O atributo chega DUAS vezes no mesmo tag (home-lcp e catalog-noncritical), por isso
                       o teste e por ATRIBUTO (procura o valor home-lcp dentro de um data-awa-defer), nunca
                       pela tag inteira. */
                    if (preg_match('/data-awa-defer=["\'][^"\']*(?<![A-Za-z0-9_-])home-lcp(?![A-Za-z0-9_-])[^"\']*["\']/i', $attrs)) {
                        return $matches[0];
                    }

                    $attrs = preg_replace('/\s*media=["\']all["\']\s*/i', ' ', $attrs) ?? $attrs;
                    $attrs = trim(preg_replace('/\s+/', ' ', $attrs) ?? $attrs);
                    $attrs = rtrim($attrs, '/');

                    return '<link ' . $attrs . ' media="print" onload="this.media=\'all\'" data-awa-defer="catalog-noncritical"/>';
                },
                $html
            ) ?? $html;
        }

        return $html;
    }

    /**
     * PLP/busca/carrinho: templates ainda referenciam v=11; normaliza em toda resposta HTML com header.
     */
    private function normalizeHeaderTerminalStylesheetVersion(string $html): string
    {
        $terminalV = HeaderImpeccableCascadeLockCss::HEADER_TERMINAL_VERSION;

        if (!str_contains($html, 'awa-header-refine-terminal.min.css')) {
            return $html;
        }

        return preg_replace(
            '/awa-header-refine-terminal\.min\.css(?:\?v=[^"\'&\s>]+)?/',
            'awa-header-refine-terminal.min.css?v=' . $terminalV,
            $html
        ) ?? $html;
    }

    private function isHomeNeverGateFragment(string $fragment): bool
    {
        foreach ($this->manifest->homeNeverGateFragments() as $never) {
            if (str_contains($fragment, $never)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Home: com stack consolidado ativo, remove folhas críticas legadas (FPC stale).
     */
    private function stripStaleHomeCriticalStylesheets(string $html): string
    {
        if (!str_contains($html, 'awa-home-critical-stack')) {
            return $html;
        }

        return $this->stripStylesheetFragments($html, [
            'awa-header-mobile-grid-critical',
            'awa-header-home-light-lock-v1',
            'awa-bundle-async-distill-lock',
            // 'awa-menu-v2-dept-open-fix' VIVO (GrupoAwamotos_Theme web/css) — carregamento
            // async próprio via observer; não strip aqui.
        ]);
    }

    private function patchStaleHomeHeaderAssets(string $html): string
    {
        $gateJs = 'awa-css-gate.min.js?v=' . HeaderImpeccableCascadeLockCss::GATE_SCRIPT_QUERY;
        $html = str_replace(
            [
                'awa-css-gate.min.js?v=20260528-loader',
                'awa-css-gate.min.js?v=20260531-impeccable-v10',
                'awa-css-gate.min.js?v=20260531-impeccable-v11',
                'awa-css-gate.min.js?v=20260531-impeccable-v12',
                'awa-css-gate.min.js?v=20260601-home-opt3',
                'awa-css-gate.min.js?v=20260601-home-opt4',
                'awa-css-gate.min.js?v=20260601-home-opt5',
                'awa-css-gate.min.js?v=20260601-home-opt6',
                'awa-css-gate.min.js?v=20260601-home-opt7',
                'awa-css-gate.min.js?v=20260601-home-opt8',
                'awa-css-gate.min.js?v=20260601-carousel13',
                'awa-css-gate.min.js?v=20260601-home-opt17',
                'awa-css-gate.min.js?v=20260531-optimize',
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

        $terminalV = HeaderImpeccableCascadeLockCss::HEADER_TERMINAL_VERSION;
        $html = preg_replace(
            '/awa-home-critical-stack-2026-06-11\.min\.css\?v=[^"\'&\s>]+/',
            HeaderImpeccableCascadeLockCss::HOME_CRITICAL_STACK_FILE
                . HeaderImpeccableCascadeLockCss::HOME_CRITICAL_STACK_QUERY,
            $html
        ) ?? $html;
        if (!str_contains($html, 'awa-header-refine-terminal.min.css?v=')) {
            $html = str_replace(
                'awa-header-refine-terminal.min.css">',
                'awa-header-refine-terminal.min.css?v=' . $terminalV . '">',
                $html
            );
        }

        return preg_replace(
            '/awa-header-refine-terminal\.min\.css\?v=[^"\'&\s>]+/',
            'awa-header-refine-terminal.min.css?v=' . $terminalV,
            $html
        ) ?? $html;
    }

    private function injectHeaderTerminalFixStyle(string $html): string
    {
        return HeaderImpeccableCascadeLockCss::injectBeforeBodyClose($html);
    }

    private function injectHeaderTerminalStylesheetIfMissing(string $html): string
    {
        /* header-refine-terminal migrado para styles-l — não reinjetar folha standalone. */
        return $html;
    }

    /**
     * Home: garante terminal CSS + fila gate no HTML (preload pode omitir quando $__gatedCssUrls vazio).
     */
    private function injectHomeHeaderTerminalAssets(string $html): string
    {
        $html = $this->injectHeaderTerminalStylesheetIfMissing($html);
        // Home freeze 2026-09-02: do not inject #awa-css-gate-queue or awa-css-gate.min.js
        // on cms_index_index. QUEUE_HOME_LOAD_LAST + documentElement MutationObserver
        // froze the main thread (scrollbar visible, scrollTop stuck at 0).
        return $html;
    }


    /**
     * Home: troca terminais CSS/JS inline pesados por <link>/<script src> cacheáveis.
     * Header locks permanecem media=all (anti-FOUC). Polish/bugfix abaixo da dobra
     * usam media=print onload (GTmetrix 2026-07-23 — reduzir CSS sync no critical path).
     */
    private function convertHomeHeavyTerminalsToExternal(string $html): string
    {
        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $staticBase = '/static/' . $versionMatch[1] . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/';
        /* P2.1 2026-07-28: bust deferred visual-audit (home nav gutter 24)
           r5 2026-08-05: upgrade condensed border fix para auditLock (0,13,4). */
        $query = '?v=20260904-premium-ui-w9';

        // Sync: header geometry / CLS locks mínimos (above-fold).
        $syncCssIds = [
            'awa-header-simplify-ui-terminal-lock',
            'awa-align-grid-inline-lock-20260626-phase3d22b',
        ];
        // Deferred: polish / spacing / bugfix / visual-audit (GTmetrix 2026-07-23 — −48KB sync).
        $deferredCssIds = [
            'awa-header-visual-audit-fixes-20260630',
            'awa-home-impeccable-terminal-v1',
            'awa-home-product-design-audit-polish-20260629',
            'awa-home-compact-spacing-terminal-20260707',
            'awa-visual-bugfix-terminal-20260707',
        ];

        $linkTags = '';
        $cssStagger = 'window.__awaCssQ?__awaCssQ(this):(/themes\\.min|third-party-bundle|visual-bugfix|deferred-stack/.test(this.href||\'\')?(window.__awaCssPend=window.__awaCssPend||[]).push(this):(this.media=\'all\'))';

        foreach (array_merge($syncCssIds, $deferredCssIds) as $styleId) {
            if (!str_contains($html, 'id="' . $styleId . '"')) {
                continue;
            }
            $html = preg_replace(
                '/<style id="' . preg_quote($styleId, '/') . '"[^>]*>.*?<\/style>\s*/is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace(
                '/<link[^>]*\bid="' . preg_quote($styleId, '/') . '"[^>]*>\s*/is',
                '',
                $html
            ) ?? $html;
            // P1A 2026-07-27: buster dedicado — card de categoria height 144->min-height 176
            // (assets são immutable/1y; bump por arquivo, sem re-bust do grupo footer-touch).
            $cssQuery = match ($styleId) {
                'awa-home-product-design-audit-polish-20260629' => '?v=20260727-p1a-card-min-height-r1',
                'awa-home-impeccable-terminal-v1' => '?v=20260818-home-shelf-opt-r1',
                default => $query,
            };
            // Production minify=1: pub/static only has .min.css for these terminals.
            $href = $staticBase . 'css/' . $styleId . '.min.css' . $cssQuery;
            if (in_array($styleId, $deferredCssIds, true)) {
                $linkTags .= '<link rel="stylesheet" href="' . $href . '" media="print"'
                    . ' onload="' . $cssStagger . '" id="' . $styleId . '"/>' . "\n";
            } else {
                $linkTags .= '<link rel="stylesheet" href="' . $href . '" media="all" id="' . $styleId . '"/>' . "\n";
            }
        }

        if ($linkTags !== '') {
            $replaced = preg_replace('/<\/head>/i', $linkTags . '</head>', $html, 1);
            if (is_string($replaced)) {
                $html = $replaced;
            }
        }

        $html = preg_replace(
            '/<script[^>]*\bid="awa-visual-bugfix-terminal-runtime-20260707"[^>]*>.*?<\/script>\s*/is',
            '',
            $html
        ) ?? $html;

        $jsIds = [
            'awa-minicart-anchor-terminal-20260801v22',
        ];
        $scriptTags = '';
        foreach ($jsIds as $scriptId) {
            if (!str_contains($html, 'id="' . $scriptId . '"')
                && !($scriptId === 'awa-minicart-anchor-terminal-20260801v22'
                    && (str_contains($html, 'id="awa-minicart-anchor-terminal-20260717i"')
                        || str_contains($html, 'awa-minicart-anchor-terminal-20260717')))
            ) {
                continue;
            }
            $html = preg_replace(
                '/<script[^>]*\bid="' . preg_quote($scriptId, '/') . '"[^>]*>.*?<\/script>\s*/is',
                '',
                $html
            ) ?? $html;
            // Remover id legado se estamos injetando o v22.
            if ($scriptId === 'awa-minicart-anchor-terminal-20260801v22') {
                $html = preg_replace(
                    '/<script id="awa-minicart-anchor-terminal-20260717[a-z0-9]*"[^>]*>.*?<\/script>\s*/is',
                    '',
                    $html
                ) ?? $html;
                $html = preg_replace(
                    '/<script[^>]*id="awa-minicart-anchor-terminal-20260717[a-z0-9]*"[^>]*><\/script>\s*/is',
                    '',
                    $html
                ) ?? $html;
            }
            $scriptQuery = $scriptId === 'awa-minicart-anchor-terminal-20260801v22'
                ? MinicartAssetVersion::query()
                : ($scriptId === 'awa-visual-bugfix-terminal-runtime-20260707'
                    ? '?v=20260827-footer-axis-bp'
                    : $query);
            $href = $staticBase . 'js/' . $scriptId . '.js' . $scriptQuery;
            $scriptTags .= '<script defer src="' . $href . '" id="' . $scriptId . '"></script>' . "\n";
        }

        if ($scriptTags !== '') {
            $pos = stripos($html, '</body>');
            if ($pos === false) {
                $html .= "\n" . $scriptTags;
            } else {
                $html = substr($html, 0, $pos) . $scriptTags . substr($html, $pos);
            }
        }

        return $html;
    }

    /**
     * Converte o bloco footer terminal inline (~65KB) para CSS externo síncrono e
     * preserva um contrato estrutural crítico após a folha. O footer é below-fold,
     * mas toda a sua geometria precisa existir antes do primeiro layout para evitar CLS.
     *
     * Também garante o lock crítico em páginas sem o bloco inline (ex.: PLP/categoria),
     * onde o bundle deferido deixava o logo do rodapé em 0×0 (content-visibility:auto).
     */
    private function convertFooterCssToAsync(string $html, string $fullAction = ''): string
    {
        $hasInlineFooterTerminal = str_contains(
            $html,
            'id="' . HeaderImpeccableCascadeLockCss::FOOTER_STYLE_ID . '"'
        );
        $hasFooterMarkup = str_contains($html, 'footer-bottom')
            || str_contains($html, 'page-footer')
            || str_contains($html, 'page_footer');

        if (!$hasInlineFooterTerminal && !$hasFooterMarkup) {
            return $html;
        }

        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $href = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . HeaderImpeccableCascadeLockCss::FOOTER_CSS_FILE
            . HeaderImpeccableCascadeLockCss::FOOTER_CSS_QUERY;

        // Remove bloco inline (quando existir) e critical anterior para reinserir no head.
        $html = HeaderImpeccableCascadeLockCss::stripFooterTerminalFromHtml($html);
        $html = preg_replace(
            '/<style\s+id="'
            . preg_quote(HeaderImpeccableCascadeLockCss::FOOTER_CRITICAL_STYLE_ID, '/')
            . '"[^>]*>.*?<\/style>\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<link\s[^>]*data-awa-footer-sync=["\']1["\'][^>]*>\s*/i',
            '',
            $html
        ) ?? $html;

        // Folha completa async (print→all); critical inline cobre estabilidade do footer.
        // Home: esqueleto CLS (~3KB) + print→all imediato — a folha não entra no stack idle.
        // Demais rotas: critical completo (56KB) porque a folha ainda passa pelo __awaCssQ.
        $isHome = $fullAction === self::HOME_ACTION;
        $criticalCss = $isHome
            ? HeaderImpeccableCascadeLockCss::footerCriticalStabilityRulesHome()
            : HeaderImpeccableCascadeLockCss::footerCriticalStabilityRules();
        $criticalTag = '<style id="' . HeaderImpeccableCascadeLockCss::FOOTER_CRITICAL_STYLE_ID . '">'
            . $criticalCss
            . '</style>';
        $onload = $isHome
            ? 'this.media=\'all\''
            : 'window.__awaCssQ?__awaCssQ(this):(/themes\\.min|third-party-bundle|visual-bugfix|deferred-stack/.test(this.href||\'\')?(window.__awaCssPend=window.__awaCssPend||[]).push(this):(this.media=\'all\'))';
        $stylesheetTag = '<link rel="stylesheet" href="' . $href . '" media="print"'
            . ' onload="' . $onload . '"'
            . ' data-awa-footer-sync="1"/>';
        $criticalInserted = false;
        if (stripos($html, '</head>') !== false) {
            $withCritical = preg_replace(
                '/<\/head>/i',
                $stylesheetTag . "\n" . $criticalTag . "\n</head>",
                $html,
                1
            );
            if (is_string($withCritical)) {
                $html = $withCritical;
                $criticalInserted = true;
            }
        }

        if (!$criticalInserted) {
            return $html . "\n" . $stylesheetTag . $criticalTag;
        }

        return $html;
    }


    private function injectHomeAlignGridStylesheetsIfMissing(string $html): string
    {
        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $staticBase = '/static/' . $versionMatch[1] . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/';
        $tags = '';

        if (!str_contains($html, 'awa-home-critical-stack')
            && !str_contains($html, 'awa-bundle-async-distill-lock')
        ) {
            $tags .= '<link rel="stylesheet" href="' . $staticBase
                . 'awa-bundle-async-distill-lock.min.css' . HeaderImpeccableCascadeLockCss::HOME_DISTILL_LOCK_QUERY
                . '" media="all" data-awa-bundle="async-distill-lock"/>' . "\n";
        }

        /* align-grid: só via consolidateAlignGridToBodyTerminal (evita duplicata head + body) */

        if ($tags === '') {
            return $html;
        }

        $injected = preg_replace('/<\/head>/i', $tags . '</head>', $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * PLP/PDP/carrinho: garante align-grid terminal quando o loader body-end não renderiza (FPC).
     */
    private function injectAlignGridStylesheetIfMissing(string $html): string
    {
        if (str_contains($html, 'awa-align-grid-terminal-2026-06-11')) {
            return $html;
        }

        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $href = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . HeaderImpeccableCascadeLockCss::ALIGN_GRID_CSS_FILE
            . HeaderImpeccableCascadeLockCss::ALIGN_GRID_QUERY;

        $tag = '<link rel="stylesheet" href="' . $href . '" media="all"'
            . ' data-awa-align-grid-terminal="1" data-awa-bundle="align-grid-terminal"/>';

        $injected = preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
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

    /**
     * PDP: normaliza versões stale (OPcache / block_html) para terminal round 6.
     */
    private function normalizePdpTerminalStylesheets(string $html): string
    {
        $html = preg_replace(
            '/awa-bundle-async-distill-lock\.min\.css(?:\?v=[^"\'&\s>]+)?/',
            'awa-bundle-async-distill-lock.min.css' . HeaderImpeccableCascadeLockCss::PDP_DISTILL_LOCK_QUERY,
            $html
        ) ?? $html;

        return preg_replace(
            '/awa-ui-simplify-terminal\.min\.css(?:\?v=[^"\'&\s>]+)?/',
            'awa-ui-simplify-terminal.min.css' . HeaderImpeccableCascadeLockCss::PDP_UI_SIMPLIFY_QUERY,
            $html
        ) ?? $html;
    }

    /**
     * @param string[] $fragments
     */
    /**
     * Home: drop parent themes.min.css (2.3MB) + third-party-bundle if Magento layout
     * or awa-head-preload still emitted a print link. Parsing those mid-scroll froze Chrome.
     */
    private function stripHomeHeavyParentStylesheets(string $html): string
    {
        $html = $this->stripStylesheetFragments($html, [
            'themes.min.css',
            'themes.css',
            'awa-third-party-bundle',
            'awa-home-deferred-stack',
            'awa-commerce-impeccable-refine',
            'awa-b2b-status-panel',
            'css/header/status-panel',
        ]);

        $urls = $this->extractGateQueueUrls($html);
        if ($urls === []) {
            return $html;
        }

        $filtered = [];
        $changed = false;
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            if (str_contains($url, 'themes.min.css')
                || str_contains($url, '/css/themes.css')
                || str_contains($url, 'awa-third-party-bundle')
                || str_contains($url, 'awa-home-deferred-stack')
                || str_contains($url, 'awa-commerce-impeccable-refine')
                || str_contains($url, 'awa-b2b-status-panel')
                || str_contains($url, 'css/header/status-panel')
            ) {
                $changed = true;
                continue;
            }
            $filtered[] = $url;
        }

        return $changed ? $this->replaceCssGateQueue($html, $filtered) : $html;
    }

    private function stripStylesheetFragments(string $html, array $fragments): string
    {
        // Fase 6: PageConfig já corta a maioria dos src. Não rodar preg se o
        // fragmento não aparece no HTML (safety-net só quando ainda há match).
        $present = [];
        foreach ($fragments as $fragment) {
            if ($fragment !== '' && str_contains($html, $fragment)) {
                $present[] = $fragment;
            }
        }
        if ($present === []) {
            return $html;
        }

        foreach ($present as $fragment) {
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

    private function stripScriptFragments(string $html, array $fragments): string
    {
        foreach ($fragments as $fragment) {
            $html = preg_replace(
                '/<script\b[^>]*\bsrc=["\'][^"\']*' . preg_quote($fragment, '/') . '(?:\?[^"\']*)?["\'][^>]*>\s*<\/script>\s*/i',
                '',
                $html
            ) ?? $html;
        }

        return $html;
    }

    private function injectGlobalRefineStylesheetIfMissing(string $html, string $fullAction): string
    {
        // Home: SEÇÃO 53 overflow:visible is lost if this sheet loads (overflow-x:clip
        // on html/body/.page-wrapper). cms_index_index layout remove + never-gate.
        if ($fullAction === self::HOME_ACTION) {
            return $html;
        }

        // Fragmento sem extensão: detecta tanto .css quanto .min.css.
        if (str_contains($html, $this->manifest->fragment('refine'))) {
            return $html;
        }

        if (!preg_match('#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#', $html, $versionMatch)) {
            return $html;
        }

        $href = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . HeaderImpeccableCascadeLockCss::REFINE_CSS_FILE
            . HeaderImpeccableCascadeLockCss::REFINE_QUERY;

        // PSI 2026-07-17: home também em print→all (critical-home + anti-FOUC cobrem 1º paint).
        $tag = '<link rel="stylesheet" href="' . $href . '" media="print"'
            . ' onload="this.media=\'all\'"'
            . ' data-awa-impeccable-terminal="refine"/>';

        $injected = preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }


    /**
     * Normaliza handlers onload legados (inline ~250B × N) para __awaCssQ(this) + bootstrap único.
     * Sempre reancora o bootstrap no </head>: Magento move scripts de head.additional para o body.
     */
    private function normalizeCssStaggerOnloadHandlers(string $html, bool $isHome = false): string
    {
        $legacy = 'var F=window.__awaCssQ;if(!F){var q=[],M=96;F=window.__awaCssQ=function(e){if(q.length<M){q.push(e);if(!F._r){F._r=1;requestAnimationFrame(function(){!function n(){q.length?(q.shift().media=\'all\',requestAnimationFrame(function(){setTimeout(n,32)})):F._r=0}()})}}}};F(this)';
        if (str_contains($html, $legacy)) {
            $html = str_replace($legacy, '__awaCssQ(this)', $html);
        }

        return $this->ensureCssStaggerBootstrapInHead($html, $isHome);
    }

    private function cssStaggerBootstrapScript(bool $isHome = false): string
    {
        if ($isHome) {
            return <<<'HTML'
<script id="awa-css-stagger-boot" data-awa-stagger="20260902-home-noscroll">!function(w){var q=w.__awaCssPend||(w.__awaCssPend=[]),r=0,d=w.document,gap=500;function isHeavy(el){return /themes\.min|third-party-bundle|visual-bugfix|deferred-stack/.test((el&&el.href)||"")}function applyEl(el){if(el)el.media="all"}function releaseGate(){if(w.__awaCssPaintGate==="go")return;w.__awaCssPaintGate="go";kick()}function armGate(){if(w.__awaCssGateArmed)return;w.__awaCssGateArmed=1;w.addEventListener("pointerdown",function(){releaseGate()},{once:true,passive:true,capture:true});w.addEventListener("keydown",function(){releaseGate()},{once:true,capture:true});function startIdle(){var run=function(){releaseGate()};if(w.requestIdleCallback)w.requestIdleCallback(run,{timeout:8000});else w.setTimeout(run,8000)}if(d.readyState==="complete")startIdle();else w.addEventListener("load",startIdle,{once:true})}function kick(){if(r)return;r=1;(function t(){var i,el;for(i=q.length-1;i>=0;i--){if(!isHeavy(q[i])){applyEl(q.splice(i,1)[0])}}if(!q.length){r=0;return}el=q[0];if(isHeavy(el)&&w.__awaCssPaintGate!=="go"){w.__awaCssPaintGate=w.__awaCssPaintGate||"wait";r=0;armGate();return}q.shift();applyEl(el);w.requestAnimationFrame(function(){setTimeout(t,gap)})})()}w.__awaCssQ=function(e){if(!e)return;if(!isHeavy(e)){applyEl(e);return}if(q.length<96){q.push(e);kick()}};if(q.length){w.requestAnimationFrame(function(){w.requestAnimationFrame(kick)})}}(window);</script>
HTML;
        }

        return <<<'HTML'
<script id="awa-css-stagger-boot">!function(w){var q=w.__awaCssPend||(w.__awaCssPend=[]),r=0,d=w.document;function isHeavy(el){return /themes\.min|third-party-bundle|visual-bugfix|deferred-stack/.test((el&&el.href)||"")}function applyEl(el){if(el)el.media="all"}function releaseGate(){if(w.__awaCssPaintGate==="go")return;w.__awaCssPaintGate="go";kick()}function armGate(){if(w.__awaCssGateArmed)return;w.__awaCssGateArmed=1;function onScroll(){var y=w.pageYOffset||0;if(y<160)return;w.removeEventListener("scroll",onScroll,true);releaseGate()}w.addEventListener("scroll",onScroll,{passive:true,capture:true});w.addEventListener("pointerdown",function(){releaseGate()},{once:true,passive:true,capture:true});w.addEventListener("keydown",function(){releaseGate()},{once:true,capture:true});if(d.readyState!=="loading"){releaseGate();return}d.addEventListener("DOMContentLoaded",function(){releaseGate()},{once:true});w.setTimeout(function(){releaseGate()},0)}function kick(){if(r)return;r=1;(function t(){var i,el;for(i=q.length-1;i>=0;i--){if(!isHeavy(q[i])){applyEl(q.splice(i,1)[0])}}if(!q.length){r=0;return}el=q[0];if(isHeavy(el)&&w.__awaCssPaintGate!=="go"){w.__awaCssPaintGate=w.__awaCssPaintGate||"wait";r=0;armGate();return}q.shift();applyEl(el);w.requestAnimationFrame(function(){setTimeout(t,200)})})()}w.__awaCssQ=function(e){if(!e)return;if(!isHeavy(e)){applyEl(e);return}if(q.length<96){q.push(e);kick()}};if(q.length){w.requestAnimationFrame(function(){w.requestAnimationFrame(kick)})}}(window);</script>
HTML;
    }

    private function ensureCssStaggerBootstrapInHead(string $html, bool $isHome = false): string
    {
        $stripped = preg_replace(
            '/<script(?:\s[^>]*)?>!function\(w\)\{var q=w\.__awaCssPend[\s\S]*?\}\(window\);<\/script>\s*/',
            '',
            $html
        );
        if (is_string($stripped)) {
            $html = $stripped;
        }

        $boot = $this->cssStaggerBootstrapScript($isHome);
        if (stripos($html, '</head>') === false) {
            return $boot . $html;
        }

        $injected = preg_replace('/<\/head>/i', $boot . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    private function stripRedundantAsyncNoscript(string $html): string
    {
        $pattern = '/(<link\s(?=[^>]*rel=["\']stylesheet["\'])'
            . '(?=[^>]*media=["\']print["\'])[^>]*href=(["\'])([^"\']+)\2[^>]*\/?>)'
            . '\s*<noscript>\s*<link\s[^>]*href=\2\3\2[^>]*\/?>\s*<\/noscript>/i';

        $previous = null;
        while ($previous !== $html) {
            $previous = $html;
            $html = preg_replace($pattern, '$1', $html) ?? $html;
        }

        return $html;
    }

    private function stripStandaloneStylesheetNoscript(string $html): string
    {
        $pattern = '/<noscript>\s*<link\s[^>]*rel=["\']stylesheet["\'][^>]*\/?>\s*<\/noscript>/i';

        return preg_replace($pattern, '', $html) ?? $html;
    }


    private function stripStylePreloadDuplicates(string $html): string
    {
        if (!preg_match_all('/<link\s[^>]*rel=["\']stylesheet["\'][^>]*href=(["\'])([^"\']+)\1[^>]*\/?>/i', $html, $stylesheetMatches)) {
            return $html;
        }

        $stylesheetHrefs = [];
        foreach ($stylesheetMatches[2] as $href) {
            if (!is_string($href) || $href === '') {
                continue;
            }

            $stylesheetHrefs[html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8')] = true;
        }

        if ($stylesheetHrefs === []) {
            return $html;
        }

        return preg_replace_callback(
            '/<link\s(?=[^>]*rel=["\']preload["\'])(?=[^>]*as=["\']style["\'])[^>]*href=(["\'])([^"\']+)\1[^>]*\/?>\s*/i',
            static function (array $matches) use ($stylesheetHrefs): string {
                $href = html_entity_decode($matches[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($href !== '' && isset($stylesheetHrefs[$href])) {
                    return '';
                }

                return $matches[0];
            },
            $html
        ) ?? $html;
    }

    private function injectGlobalFocusVisibleFallback(string $html): string
    {
        if (str_contains($html, 'id="awa-focus-visible-global-guard"')) {
            return $html;
        }

        $style = '<style id="awa-focus-visible-global-guard">'
            . ':where(a,button,[role="button"],input,select,textarea,[tabindex]:not([tabindex="-1"])):focus-visible{outline:2px solid var(--awa-primary,#b73337)!important;outline-offset:2px!important}'
            /* task-66 NOVO-1 (WCAG 2.4.7, REGRESSAO): o botao "Departamentos"
             * (button.title-category-dropdown / .our_categories, 206x44) recebe foco
             * (matches(':focus-visible')=true, vira activeElement) mas SEM mudanca
             * visual: 4 folhas + 2 blocos inline aplicam outline:0px!important,
             * box-shadow:none!important e background fixo em (min-width:992px).
             * Para !important a CAMADA vence a especificidade; a @layer awa-fixes e
             * declarada ANTES de awa-visual-priority (e entre importants a camada
             * primeira vence), entao esta regra ganha das 4+2 sem especificidade
             * absurda — e o !important e inevitavel porque os resets tambem sao. */
            . '@layer awa-fixes{@media (min-width:992px){'
            . 'html body#html-body .page-wrapper .awa-site-header :is(button,.our_categories).title-category-dropdown:focus-visible,'
            . 'html body#html-body .page-wrapper button.title-category-dropdown:focus-visible{'
            . 'outline:2px solid var(--awa-primary,var(--awa-brand,#b73337))!important;'
            . 'outline-offset:2px!important}'
            . '}}'
            . '</style>';

        $injected = preg_replace('/<\/head>/i', $style . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * PLP/busca desktop: reserva a geometria FINAL no &lt;head&gt; real.
     *
     * Evidência puppeteer cold load 1350×940 (2026-08-17): CLS 0.71, shift 0.64 em 1410ms.
     * MAIN#maincontent y 227→187; .nav-breadcrumbs h 54→27; header w 1280→1340.
     * Compactação dos crumbs / full-bleed do header vivem em CSS que aterra no
     * &lt;body&gt; (head.additional). Este lock é &lt;style&gt; síncrono no &lt;/head&gt;
     * — render-blocking, sem download extra, FPC-safe. Só ≥768px.
     */
    /**
     * PDP: reserva a geometria FINAL do breadcrumbs no &lt;head&gt; real.
     *
     * Evidencia 10/10/2026 (probe-cwv --throttle psi, 1365x900):
     *   /ret-titan-2000-03-d-e-501.html  CLS 1,3572
     *   div.breadcrumbs 172,77 -&gt; 160,22 - a altura colapsava de 77px para 22px
     *   porque `ul.items` nao era flex no 1o paint (os &lt;li&gt; empilhavam).
     *
     * O lock equivalente de PLP/busca existe desde 2026-08-17 mas esta gated em
     * CATALOG_HEADER_ACTIONS, que NAO inclui catalog_product_view. Este lock usa a
     * MESMA tecnica (style sincrono no &lt;/head&gt;, render-blocking, FPC-safe) e o
     * MESMO booster de 24x #html-body, mas contem SO o breadcrumbs - para nao herdar
     * as ~200 regras de grid/header do lock de PLP.
     *
     * PRESERVADO de proposito: NAO tocamos em display/max-width/overflow/
     * white-space de `a` nem de `.item`. A protecao de ellipsis do nome do produto
     * vive em awa-super-global-20260611m.css (max-width 120/220px) e
     * awa-defer-global-bundle.css, e um display:flex a destruiria.
     */
    /**
     * PLP/busca: alinha breadcrumb + H1 + contagem a borda do container.
     *
     * Evidencia 10/10/2026 (probe-hero-alinhamento.js, artefato servido):
     *   PLP 1365 ref=62,5 -&gt; .breadcrumbs / .awa-category-hero__content /
     *   .awa-category-hero__title / .awa-category-hero__count todos em 86,5 (+24)
     *   PLP 390  ref=16   -&gt; os mesmos 4 em 28 (+12)
     *   .mst_categorySearch, .products-grid e .toolbar-products ja estavam em 0
     *   PDP ret-titan 1365: .breadcrumbs em 61,5 (+0) - nao pode regredir
     *
     * Cadeia medida: .category-view-move(pad 8, desktop) &gt; .awa-category-hero(0)
     * &gt; .awa-category-hero__content(pad-left 16 desktop / 12 mobile). 8+16=24;
     * 0+12=12.
     *
     * Por que NAO zeramos o padding do .category-view-move: aquela regra e
     * compartilhada (':is(.toolbar-sorter.sorter,.field.limiter&gt;.control,
     * #layered-ajax-filter-block,.block.filter,.category-view-move)') e governa
     * tambem a sidebar de filtros, que hoje esta em delta +8 e nao deve mudar.
     * Cancelamos os 8px no proprio hero, filho unico daquele wrapper.
     */
    private function injectCategoryHeroAlignLock(string $html): string
    {
        if (str_contains($html, 'id="awa-category-hero-align-lock"')) {
            return $html;
        }
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $cat = 'html body' . str_repeat('#html-body', 24)
            . ':is(.catalog-category-view,.catalogsearch-result-index)';
        $sel = $cat . ' .page-wrapper ';
        $css = '<style id="awa-category-hero-align-lock">'
            . '@layer awa-visual-priority{'
            . '/* (a) o card do hero nao re-indenta crumbs + H1 + contagem */'
            . $sel . '.awa-category-hero__content{padding-left:0!important}'
            . '/* task-71: LANDING de busca — cadeia .nav-breadcrumbs > .container > .row >
             * .col-sm-12 > .breadcrumbs. O .nav-breadcrumbs da a borda canonica (24/16) e
             * o .container RE-APLICA o mesmo padding (duplicado); o .breadcrumbs ainda
             * trazia 24px proprios. Cancelamos no alvo mais estreito: o .container SO
             * quando envolve crumbs (:has) e o proprio .breadcrumbs — sem tocar na regra
             * compartilhada do .container. Onde ja e 0 (PLP/PDP), e no-op. */'
            . '/* Fecha a camada para por a regra do .container em awa-fixes (abaixo):
             * a vencedora do padding do .container e awa-design-system.min.css NA MESMA
             * camada awa-visual-priority, com 13 repeticoes de #html-body (esp 13-8-2).
             * Para !important entre camadas a PRIMEIRA vence — awa-fixes vem antes —
             * entao ganhamos por CAMADA, sem repetir #html-body nenhuma vez. */'
            . '}'
            . '@layer awa-fixes{'
            . 'html body#html-body .page-wrapper .nav-breadcrumbs .container:has(.breadcrumbs){'
            . 'padding-inline:0!important}'
            . '}'
            . '@layer awa-visual-priority{'
            . 'html body#html-body .page-wrapper .nav-breadcrumbs .breadcrumbs{'
            . 'padding-inline:0!important}'
            . '@media (min-width:768px){'
            . '/* (b) os 8px do wrapper, cancelados no hero (nao na regra compartilhada) */'
            . $sel . '.category-view-move > .awa-category-hero{margin-inline-start:-8px!important}'
            . '}'
            . '}</style>';

        $injetado = preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1);

        return is_string($injetado) ? $injetado : $html;
    }

    /**
     * PDP anonima: cadastro = PRIMARIO, "Entrar para Comprar" = SECUNDARIO.
     *
     * Evidencia 11/10/2026 (probe-t73-pdp-cta.js, 1365 e 390):
     *   "Ver preco atacado" ....... link de texto 125x44
     *   "Cadastre-se gratis ->" ... link de texto 219x44 @13px
     *   "Entrar para Comprar" ..... UNICO preenchido, bg rgb(183,51,55), 574x44/332x44
     *
     * A hierarquia e VISUAL: nao reordenamos o DOM (menos risco). Os dois caminhos
     * (login e cadastro) seguem visiveis, focaveis e com tabindex >= 0.
     */
    private function injectPdpCtaHierarchy(string $html): string
    {
        if (str_contains($html, 'id="awa-pdp-cta-hierarchy"')) {
            return $html;
        }
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $p = 'html body#html-body.catalog-product-view .page-wrapper ';
        $prim = 'display:inline-flex!important;align-items:center!important;'
            . 'justify-content:center!important;min-height:44px!important;'
            . 'padding:10px 18px!important;border-radius:8px!important;'
            . 'text-decoration:none!important;font-size:14px!important;font-weight:600!important;'
            . 'box-sizing:border-box!important;';
        $sec = 'display:inline-flex!important;align-items:center!important;'
            . 'justify-content:center!important;min-height:44px!important;'
            . 'padding:10px 18px!important;border-radius:8px!important;'
            . 'text-decoration:none!important;font-size:14px!important;font-weight:600!important;'
            . 'box-sizing:border-box!important;background:transparent!important;'
            . 'border:1px solid currentColor!important;';
        $cor = 'var(--awa-primary,var(--awa-brand,#b73337))';
        $css = '<style id="awa-pdp-cta-hierarchy">'
            . '@layer awa-visual-priority{'
            . $p . '.b2b-login-to-see-price a.b2b-register-cta{'
            . $prim . 'background:' . $cor . '!important;color:#fff!important}'
            . $p . '.b2b-login-to-see-price a:not(.b2b-register-cta){'
            . $sec . 'color:' . $cor . '!important}'
            . $p . '.box-tocart.b2b-login-to-buy-mode .b2b-login-to-buy-btn{'
            . 'background:transparent!important;color:' . $cor . '!important;'
            . 'border:1px solid currentColor!important}'
            . '}'
            . '</style>';

        $injetado = preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1);

        return is_string($injetado) ? $injetado : $html;
    }

    private function injectPdpBreadcrumbGeometryLock(string $html): string
    {
        if (str_contains($html, 'id="awa-pdp-breadcrumb-lock"')) {
            return $html;
        }
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $pdp = 'html body' . str_repeat('#html-body', 24) . '.catalog-product-view';
        $sel = $pdp . ' .page-wrapper ';
        $css = '<style id="awa-pdp-breadcrumb-lock">'
            . '@layer awa-visual-priority{'
            . $sel . ':is(.nav-breadcrumbs,.awa-category-hero__crumbs) .breadcrumbs{'
            . 'display:block!important;box-sizing:border-box!important;'
            . 'margin:0 0 12px!important;padding:0!important;'
            . 'font-size:12px!important;line-height:1.25!important;'
            . 'min-height:24px!important;height:auto!important;max-height:none!important}'
            . $sel . '.breadcrumbs ul.items{'
            . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;'
            . 'gap:4px 8px!important;margin:0!important;padding:0!important;'
            . 'list-style:none!important;'
            . 'min-height:24px!important;height:auto!important;max-height:none!important}'
            . $sel . '.breadcrumbs ul.items > .item{'
            . 'display:inline-flex!important;align-items:center!important;'
            . 'margin:0!important;padding:0!important;'
            . 'min-height:24px!important;height:auto!important;max-height:none!important}'
            . $sel . '.breadcrumbs ul.items > .item :is(a,strong,span){'
            . 'font-size:inherit!important;line-height:1.25!important}'
            . '}'
            . '@media (max-width:767px){'
            . $sel . '.breadcrumbs ul.items,'
            . $sel . '.breadcrumbs ul.items > .item{min-height:44px!important}'
            . '}'
            . '</style>';

        $injetado = preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1);

        return is_string($injetado) ? $injetado : $html;
    }

    private function injectCatalogDesktopClsGeometryLock(string $html): string
    {
        if (str_contains($html, 'id="awa-plp-desktop-cls-lock"')) {
            return $html;
        }

        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $plp = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index)';
        $css = '<style id="awa-plp-desktop-cls-lock">'
            . '@media (min-width:768px){'
            . $plp . ' .page-wrapper{'
            . 'width:100%!important;max-width:none!important;box-sizing:border-box!important;'
            . 'margin:0!important;padding:0!important}'
            . $plp . ' .page-wrapper .awa-site-header{'
            . 'width:100%!important;max-width:none!important;box-sizing:border-box!important;'
            . 'margin:0!important;padding:0!important;'
            . 'height:auto!important;min-height:116px!important;max-height:none!important;contain:layout!important}'
            . $plp . ' .page-wrapper .awa-site-header #header.header-container,'
            . $plp . ' .page-wrapper .awa-site-header #header.header-container>.header-content,'
            . $plp . ' .page-wrapper .awa-site-header #awa-b2b-promo-bar,'
            . $plp . ' .page-wrapper .awa-site-header .awa-b2b-promo-bar,'
            . $plp . ' .page-wrapper .awa-site-header .top-header.awa-b2b-promo-bar{'
            . 'display:none!important;min-height:0!important;height:0!important;max-height:0!important;'
            . 'width:100%!important;max-width:none!important;box-sizing:border-box!important;'
            . 'margin:0!important;padding:0!important;overflow:hidden!important;border:0!important}'
            . $plp . ' .page-wrapper .awa-site-header:has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
            . 'min-height:160px!important}'
            . $plp . ' .page-wrapper .awa-site-header:has(#awa-b2b-promo-bar:not([aria-hidden="true"]))'
            . ' :is(#header.header-container,#header.header-container>.header-content,#awa-b2b-promo-bar,.awa-b2b-promo-bar,.top-header.awa-b2b-promo-bar){'
            . 'display:flex!important;min-height:44px!important;height:44px!important;max-height:44px!important;'
            . 'overflow:hidden!important;padding:0!important;margin:0!important}'
            . $plp . ' .page-wrapper .awa-site-header .awa-b2b-promo-bar__inner,'
            . $plp . ' .page-wrapper .awa-site-header .awa-b2b-promo-bar__layout,'
            . $plp . ' .page-wrapper .awa-site-header .awa-b2b-promo-bar__text{'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'box-sizing:border-box!important;margin:0!important;padding-block:0!important}'
            . $plp . ' .page-wrapper .awa-main-header__inner{'
            . 'min-height:68px!important;height:68px!important;max-height:68px!important;'
            . 'box-sizing:border-box!important;display:grid!important;align-items:center!important;'
            . 'grid-template-columns:160px minmax(0,1fr) minmax(240px,280px)!important;'
            . 'column-gap:12px!important;row-gap:0!important}'
            . $plp . ' .page-wrapper .awa-header-search-col{'
            . 'min-width:0!important;width:100%!important;max-width:none!important}'
            . $plp . ' .page-wrapper .awa-header-account-prompt{'
            . 'height:44px!important;min-height:44px!important;overflow:visible!important;'
            . 'flex-shrink:0!important;box-sizing:border-box!important;align-items:center!important}'
            . $plp . ' .page-wrapper .awa-header-account-prompt :is(.awa-header-account-prompt__text,.awa-header-account-prompt__guest,.awa-header-account-prompt__copy){'
            . 'height:44px!important;min-height:0!important;max-height:44px!important;line-height:1.2!important;'
            . 'white-space:nowrap!important;overflow:visible!important;display:flex!important;'
            . 'flex-direction:column!important;align-items:flex-start!important;justify-content:center!important;'
            . 'gap:2px!important}'
            . $plp . ' .page-wrapper .awa-header-account-prompt :is(.awa-header-account-prompt__line2,.awa-header-account-prompt__actions){'
            . 'min-height:0!important;height:auto!important;max-height:none!important;padding:0!important}'
            . $plp . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
            . ' .awa-header-account-prompt[data-awa-auth-state="guest"]'
            . ' a.awa-header-account-prompt__link.awa-header-account-prompt__link--login,'
            . $plp . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"]'
            . ' .awa-header-account-prompt[data-awa-auth-state="guest"]'
            . ' a.awa-header-account-prompt__link.awa-header-account-prompt__link--register{'
            . 'min-height:0!important;height:auto!important;line-height:1.2!important;padding:0 2px!important}'
            . $plp . ' .page-wrapper :is(#search_mini_form,.form.minisearch){'
            . 'height:44px!important;max-height:44px!important;margin:0!important;box-sizing:border-box!important}'
            . $plp . ' :is(.action.skip,.awa-skip-link){'
            . 'position:absolute!important;width:1px!important;height:1px!important;padding:0!important;'
            . 'overflow:hidden!important;clip:rect(0 0 0 0)!important}'
            . $plp . ' .page-wrapper :is(.header-nav,.awa-nav-bar){'
            . 'min-height:48px!important;height:48px!important;box-sizing:border-box!important}'
            /* 24px ≈ seleção original (23px): 36px/lh 36 deixava glifo 20px
             * com 8px vazios acima e abaixo (CDP preview 1236px). */
            /* margin-inline:auto centra o max-width 1280px (CDP: margin:0
             * deixava nav em left:0 / 1280px, logo/hero em ~128). */
            . '@layer awa-visual-priority{@media (min-width:768px){'
            . $plp . ' .page-wrapper .nav-breadcrumbs:not(:has(ul.items)){'
            . 'display:none!important;height:0!important;min-height:0!important;max-height:0!important;'
            . 'padding:0!important;margin:0!important;overflow:hidden!important;border:0!important}'
            . $plp . ' .page-wrapper .nav-breadcrumbs:has(ul.items){'
            . 'box-sizing:border-box!important;margin-block:0!important;margin-inline:auto!important;'
            . 'width:100%!important;max-width:min(100%,1280px)!important;'
            . 'padding-block:0!important;'
            . 'padding-inline:var(--awa-shell-pad,clamp(16px,3vw,24px))!important;'
            . 'min-height:24px!important;height:24px!important;max-height:24px!important;'
            . 'overflow:hidden!important;line-height:24px!important;font-size:14px!important}'
            . $plp . ' .page-wrapper .awa-category-hero .breadcrumbs,'
            . $plp . ' .page-wrapper .awa-category-hero__crumbs{'
            . 'margin:0 0 8px!important;padding:0!important;height:auto!important;min-height:0!important;'
            . 'max-height:none!important;overflow:visible!important}'
            . $plp . ' .page-wrapper :is(.nav-breadcrumbs .container,.nav-breadcrumbs .row,.nav-breadcrumbs [class*="col-"]){'
            . 'box-sizing:border-box!important;margin:0!important;padding:0!important;'
            . 'width:100%!important;max-width:none!important;'
            . 'min-height:24px!important;height:24px!important;max-height:24px!important;'
            . 'display:flex!important;align-items:center!important;overflow:hidden!important;'
            . 'font-size:14px!important;line-height:24px!important}'
            . $plp . ' .page-wrapper :is(.nav-breadcrumbs .breadcrumbs,.breadcrumbs){'
            . 'box-sizing:border-box!important;margin:0!important;padding:0!important;'
            . 'width:auto!important;max-width:100%!important;flex:0 0 auto!important;'
            . 'min-height:24px!important;height:auto!important;max-height:none!important;'
            . 'display:block!important;align-items:center!important;overflow:hidden!important;'
            . 'border:0!important;box-shadow:none!important;'
            . 'font-size:12px!important;line-height:1.25!important}'
            /* CDP: ul width:100% + .item:last-child flex-grow:1 + max-width:220px
             * → caixa 1232px com texto ~79px (emptyRight 932, last 220). */
            . $plp . ' .page-wrapper :is(.nav-breadcrumbs,.breadcrumbs) ul.items{'
            . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;gap:4px 8px!important;'
            . 'box-sizing:border-box!important;margin:0!important;padding:0!important;'
            . 'min-height:24px!important;height:auto!important;max-height:none!important;'
            . 'width:max-content!important;max-width:100%!important;flex:0 0 auto!important;'
            . 'overflow:hidden!important;list-style:none!important;'
            . 'border:0!important;box-shadow:none!important;'
            . 'font-size:12px!important;line-height:1.25!important}'
            . $plp . ' .page-wrapper :is(.nav-breadcrumbs,.breadcrumbs) ul.items > .item,'
            . $plp . ' .page-wrapper :is(.nav-breadcrumbs,.breadcrumbs) ul.items > .item:last-child{'
            . 'display:inline-flex!important;align-items:center!important;'
            . 'flex:0 1 auto!important;width:auto!important;max-width:none!important;min-width:0!important;'
            . 'min-height:24px!important;height:auto!important;max-height:none!important;'
            . 'font-size:12px!important;line-height:1.25!important;padding:0!important;'
            . 'overflow:hidden!important;margin:0!important}'
            . $plp . ' .page-wrapper :is(.nav-breadcrumbs,.breadcrumbs) ul.items > .item :is(a,span,strong){'
            . 'display:inline-flex!important;align-items:center!important;'
            . 'font-size:inherit!important;line-height:1.25!important;'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'padding-block:0!important;overflow:visible!important}'
            /* CDP AM/AN 2026-08-21: lock height:104/76 overflow:hidden clipped
             * banner (125>104) and related-search recs (346>76). */
            . $plp . ' .page-wrapper .awa-plp-b2b-gate-banner{'
            . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;'
            . 'gap:8px!important;padding:12px!important;box-sizing:border-box!important;'
            . 'min-height:104px!important;height:auto!important;max-height:none!important;'
            . 'overflow:visible!important;margin:0 0 20px!important}'
            . $plp . ' .page-wrapper .awa-plp-b2b-gate-banner :is(p,.awa-plp-b2b-gate-banner__title,.awa-plp-b2b-gate-banner__desc){'
            . 'display:block!important;margin:0!important}'
            . $plp . ' .page-wrapper .awa-plp-b2b-gate-banner__badge{order:1!important;flex:0 0 auto!important}'
            . $plp . ' .page-wrapper .awa-plp-b2b-gate-banner__title{flex:0 1 auto!important;min-width:0!important;order:2!important}'
            . $plp . ' .page-wrapper .awa-plp-b2b-gate-banner__desc{flex:1 0 100%!important;order:4!important}'
            . $plp . ' .page-wrapper .awa-plp-b2b-gate-banner__actions{'
            . 'display:flex!important;flex-wrap:wrap!important;gap:8px!important;width:auto!important;'
            . 'margin-inline-start:auto!important;order:3!important}'
            . $plp . ' .page-wrapper .awa-plp-b2b-gate-banner__action{'
            . 'box-sizing:border-box!important;min-height:44px!important;height:auto!important;'
            . 'width:auto!important;max-width:100%!important;padding:8px 16px!important}'
            . $plp . ' .page-wrapper .shop-tab-title{'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'overflow:visible!important;box-sizing:border-box!important}'
            . $plp . ' .page-wrapper .mst-search__misc-recommendations{'
            . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;'
            . 'gap:8px!important;height:auto!important;margin:8px 0 0!important;padding:0!important;'
            . 'background:transparent!important;border:0!important}'
            . $plp . ' .page-wrapper .mst-search__misc-recommendations :is(dt,.title){'
            . 'flex:1 0 100%!important;font-size:12px!important;font-weight:700!important;'
            . 'color:var(--awa-text-muted)!important;margin:0!important}'
            . $plp . ' .page-wrapper .mst-search__misc-recommendations dd.item{'
            . 'display:inline-flex!important;align-items:center!important;gap:4px!important;'
            . 'margin:0!important;padding:5px 8px!important;max-width:11.25rem!important;min-width:0!important;'
            . 'box-sizing:border-box!important;min-height:32px!important;'
            . 'border:1px solid var(--awa-border)!important;border-radius:var(--awa-control-radius)!important;'
            . 'background:var(--awa-surface,var(--awa-bg,Canvas))!important}'
            . $plp . ' .page-wrapper .mst-search__misc-recommendations dd.item a{'
            . 'display:block!important;box-sizing:border-box!important;'
            . 'min-height:0!important;padding:0!important;font-size:12px!important;font-weight:500!important;'
            . 'max-width:100%!important;min-width:0!important;flex:1 1 auto!important;'
            . 'white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;'
            . 'border:0!important;background:transparent!important;'
            . 'text-decoration:none!important;color:var(--awa-ink,var(--awa-text))!important}'
            . $plp . ' .page-wrapper .mst-search__misc-recommendations dd.item .count{'
            . 'flex:0 0 auto!important;font-size:11px!important;font-weight:400!important;'
            . 'line-height:1.25!important;color:var(--awa-text-muted)!important}'
            /* CDP AO/AP/AQ 2026-08-21: 4 duplicate strokes — crumb box+bottom,
             * filter panel+ol.items, tab pills+ul bottom, grid mode border+spread. */
            . $plp . ' .page-wrapper .mst-search__result-tabs{'
            . 'border:0!important;border-bottom:0!important;box-shadow:none!important;'
            . 'align-items:center!important}'
            . $plp . ' .page-wrapper .mst-search__result-tabs > li{'
            . 'display:flex!important;align-items:center!important;list-style:none!important;margin:0!important}'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:link,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:visited,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:hover,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:focus,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:active{'
            . 'text-decoration:none!important;text-decoration-line:none!important}'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a{'
            . 'box-sizing:border-box!important;min-height:44px!important;height:auto!important;'
            . 'padding:8px 16px!important}'
            . $plp . ' .page-wrapper .toolbar-products .modes .modes-mode.active,'
            . $plp . ' .page-wrapper .toolbar-products .modes .modes-mode:hover,'
            . $plp . ' .page-wrapper .toolbar-products .modes .modes-mode:focus-visible{'
            . 'box-shadow:none!important}'
            . $plp . ' .page-wrapper .block.filter .filter-options-content ol.items{'
            . 'border:0!important;box-shadow:none!important;background:transparent!important}'
            . '}}'
            . $plp . ' .page-wrapper .awa-category-hero:not(.awa-category-hero--has-image){'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'width:100%!important;max-width:100%!important;box-sizing:border-box!important;'
            . 'margin:0!important;display:flex!important;align-items:flex-start!important;'
            . 'padding-block:var(--awa-space-2,8px)!important;overflow:visible!important}'
            . $plp . ' .page-wrapper :is(.page_footer,.page-footer) .awa-footer-trust-bar{'
            . 'padding-block:16px!important}'
            . $plp . ' .page-wrapper .item-product :is(.info-price,.price-box){'
            . 'min-height:0!important;padding-block:0!important}'
            . $plp . ' .page-wrapper .item-product .product-info-cart{'
            . 'margin-block-start:8px!important}'
            /* AWA visual-audit 2026-08-26 lote2: CLS lock 112px recortava cat-*.png.
             * First-paint = 220 (attrs img 1216×220) para não gerar CLS ao soltar o lock. */
            . $plp . ' .page-wrapper .awa-category-hero.awa-category-hero--has-image{'
            . 'position:relative!important;min-height:180px!important;height:220px!important;max-height:220px!important;'
            . 'padding-block:16px 20px!important;align-items:flex-end!important}'
            . $plp . ' .page-wrapper .awa-category-hero--has-image .awa-category-hero__bg-image{'
            . 'position:absolute!important;inset:0!important;width:100%!important;height:100%!important;'
            . 'min-height:100%!important;max-height:none!important;object-fit:cover!important;'
            . 'object-position:center 35%!important}'
            . $plp . ' .page-wrapper .awa-category-hero__content{'
            . 'align-self:flex-start!important;padding-block-start:0!important}'
            /* align-grid §12512 padding:8px no .category-view-move; plp-shell-final zera
             * depois (hero 1216→1232, y −8px, cluster CLS ~0.18). */
            . $plp . ' .page-wrapper .category-view-move{'
            . 'padding:0!important;margin:0 0 12px!important;box-sizing:border-box!important;'
            . 'min-height:0!important;height:auto!important;width:100%!important;max-width:100%!important}'
            /* Shell + gutter: 1º paint usa padding 32px (1216px) até align-grid
             * definir --awa-shell-pad=24px (1232px) — cluster CLS 0.18–0.28. */
            . 'html:has(body:is(.catalog-category-view,.catalogsearch-result-index)){'
            . 'scrollbar-gutter:stable;margin:0!important;padding:0!important}'
            . $plp . '{'
            . '--awa-shell-max:min(100%,1280px);'
            . '--awa-shell-pad:clamp(16px,3vw,24px);'
            . '--awa-shell-gap-wide:16px;'
            . '--awa-sidebar-w:clamp(236px,20vw,260px);'
            . '--awa-plp-stack-gap:12px;'
            . 'scrollbar-gutter:stable;margin:0!important;padding:0!important}'
            . $plp . ' .page-wrapper :is(.page-main,main.page-main,.page-main.container){'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:var(--awa-shell-max)!important;'
            . 'padding-inline:var(--awa-shell-pad)!important;'
            . 'padding-block-start:0!important;width:100%!important}'
            . $plp . ' .page-wrapper .page-main>.columns{'
            . 'box-sizing:border-box!important;margin:0!important;padding:0!important;'
            . 'max-width:100%!important;width:100%!important;min-width:0!important}'
            . $plp . ' .page-wrapper .page-main>.columns.layout-2-col{'
            . 'display:grid!important;align-items:start!important;'
            . 'row-gap:0!important;column-gap:12px!important;'
            . 'grid-template-columns:260px minmax(0,1fr)!important}'
            . $plp . '.catalog-category-view .page-wrapper .page-main>.columns.layout-2-col>.col-main{'
            . 'display:contents!important}'
            /* Busca: contents faz filhos do col-main virarem itens do grid de
             * 6 áreas; .search.results auto-place estica plp-head (~1451px) e
             * o .block.filter cai em top≈1735 (CDP 2026-08-21). Category
             * continua contents; busca é um bloco em plp-main. */
            . $plp . '.catalogsearch-result-index .page-wrapper .page-main>.columns.layout-2-col{'
            . 'grid-template-areas:"plp-side plp-main"!important;'
            . 'grid-template-rows:auto!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .page-main>.columns.layout-2-col>.col-main{'
            . 'display:block!important;grid-area:plp-main!important;min-width:0!important;width:100%!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .page-main>.columns.layout-2-col>:first-child:not(.col-main){'
            . 'grid-area:plp-side!important;align-self:start!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .page-main>.columns.layout-2-col .search.results{'
            . 'min-width:0!important;width:100%!important;max-width:100%!important}'
            /* Chrome do filtro nativo: HTML "Filtrar por"/"Comprar por"/"Categoria";
             * computed uppercase via layout-bundle + styles-l (CDP 2026-08-21).
             * §35.1 não casa dt/dd (sem .filter-options-item). Categoria ?cat=
             * duplica chips Mirasvit. Injetado no lock (não no CSS immutable). */
            . $plp . ' .page-wrapper .block.filter :is(.block-title.filter-title strong,.filter-options-title){'
            . 'text-transform:none!important}'
            . $plp . ' .page-wrapper .block.filter>.block-title.filter-title,'
            . $plp . ' .page-wrapper .block.filter .block-content>.block-title.filter-title{'
            . 'display:none!important}'
            . $plp . ' .page-wrapper .block.filter .block-subtitle.filter-subtitle{'
            . 'display:none!important}'
            . $plp . ' .page-wrapper .block.filter dt.filter-options-title:has(+ dd.filter-options-content a[href*="?cat="]),'
            . $plp . ' .page-wrapper .block.filter dt.filter-options-title:has(+ dd.filter-options-content a[href*="&cat="]),'
            . $plp . ' .page-wrapper .block.filter dt.filter-options-title:has(+ dd.filter-options-content a[href*="?cat="])+dd,'
            . $plp . ' .page-wrapper .block.filter dt.filter-options-title:has(+ dd.filter-options-content a[href*="&cat="])+dd{'
            . 'display:none!important;visibility:hidden!important;height:0!important;'
            . 'max-height:0!important;margin:0!important;padding:0!important;'
            . 'overflow:hidden!important;pointer-events:none!important}'
            /* Buscar em: no screenshot original os chips ocupavam a rail de 260px
             * (CDP: wrapper 960×77, gap:normal, col-main). Gap + título 100%
             * no lock; o script move o wrapper para a sidebar ≥768. */
            . $plp . '.catalogsearch-result-index .mst-search-in__wrapper{'
            . 'display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;'
            . 'gap:8px!important;box-sizing:border-box!important;'
            . 'width:100%!important;max-width:100%!important;min-width:0!important;'
            . 'justify-self:stretch!important;justify-items:stretch!important;'
            . 'margin:0 0 12px!important;align-items:stretch!important}'
            . $plp . '.catalogsearch-result-index .col-main>.mst-search-in__wrapper{'
            . 'display:none!important}'
            . $plp . '.catalogsearch-result-index .mst-search-in__title{'
            . 'grid-column:1/-1!important;flex:none!important;text-transform:none!important;'
            . 'font-size:14px!important;font-weight:700!important;color:var(--awa-text)!important}'
            . $plp . '.catalogsearch-result-index a.mst-search-in__item{'
            . 'display:inline-flex!important;align-items:flex-start!important;gap:4px!important;'
            . 'box-sizing:border-box!important;'
            . 'min-width:0!important;width:100%!important;max-width:100%!important;'
            . 'min-height:32px!important;padding:5px 8px!important;'
            . 'font-size:12px!important;line-height:1.25!important;'
            . 'border:1px solid var(--awa-border)!important;'
            . 'border-radius:var(--awa-control-radius)!important;'
            . 'text-decoration:none!important;color:var(--awa-ink,var(--awa-text))!important}'
            . $plp . '.catalogsearch-result-index a.mst-search-in__item .name{'
            . 'min-width:0!important;flex:1 1 auto!important;'
            . 'display:-webkit-box!important;-webkit-line-clamp:2!important;'
            . '-webkit-box-orient:vertical!important;overflow:hidden!important;'
            . 'white-space:normal!important;font-size:12px!important;line-height:1.25!important;'
            . 'text-overflow:unset!important;color:inherit!important}'
            . $plp . '.catalogsearch-result-index a.mst-search-in__item .count{'
            . 'flex:0 0 auto!important;align-self:center!important;'
            . 'font-size:11px!important;line-height:1.25!important;'
            . 'text-decoration:none!important;color:var(--awa-text-muted)!important}'
            . $plp . '.catalogsearch-result-index a.mst-search-in__item .count::before{content:" ("}'
            . $plp . '.catalogsearch-result-index a.mst-search-in__item .count::after{content:")"}'
            . $plp . ' .page-wrapper .page-main>.columns.layout-2-col'
            . '>:is(.sidebar.sidebar-main,.sidebar-main,[class*="col-sm-3"]){'
            . 'width:100%!important;max-width:none!important;'
            . 'min-width:0!important;box-sizing:border-box!important;'
            . 'scrollbar-gutter:auto!important}'
            /* Cor: link layered width 196px + pad 12px 0 empilha swatches
             * (CDP AL: 2×70px → dd 172px; opção 44px). */
            . $plp . ' .page-wrapper .block.filter .filter-options-content:has(.swatch-layered){'
            . 'height:auto!important;min-height:0!important;max-height:none!important}'
            . $plp . ' .page-wrapper .block.filter .swatch-attribute.swatch-layered,'
            . $plp . ' .page-wrapper .block.filter .swatch-attribute-options{'
            . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;'
            . 'gap:8px!important;height:auto!important;min-height:0!important;width:100%!important}'
            . $plp . ' .page-wrapper .block.filter .swatch-option-link-layered{'
            . 'display:inline-flex!important;width:auto!important;max-width:none!important;'
            . 'min-width:0!important;height:auto!important;padding:4px!important;flex:0 0 auto!important}'
            . $plp . ' .page-wrapper .block.filter .swatch-option.color{'
            . 'width:32px!important;height:32px!important;min-width:32px!important;'
            . 'min-height:32px!important;box-sizing:border-box!important}'
            /* Marca ol.items: UA padding-left:40px + list-style:decimal;
             * links min-height:44px + li padding 5px → 62px×5=312px (CDP AC/AD). */
            . $plp . ' .page-wrapper .block.filter .filter-options-content ol.items{'
            . 'list-style:none!important;margin:0!important;padding:0!important;'
            . 'border:0!important;box-shadow:none!important;background:transparent!important}'
            . $plp . ' .page-wrapper :is(.nav-breadcrumbs .breadcrumbs,.breadcrumbs),'
            . $plp . ' .page-wrapper :is(.nav-breadcrumbs,.breadcrumbs) ul.items{'
            . 'border:0!important;box-shadow:none!important}'
            . $plp . ' .page-wrapper .mst-search__result-tabs{'
            . 'border:0!important;border-bottom:0!important;box-shadow:none!important;'
            . 'align-items:center!important}'
            . $plp . ' .page-wrapper .mst-search__result-tabs > li{'
            . 'display:flex!important;align-items:center!important;list-style:none!important;margin:0!important}'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:link,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:visited,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:hover,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:focus,'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a:active{'
            . 'text-decoration:none!important;text-decoration-line:none!important}'
            . $plp . ' .page-wrapper .col-main .mst-search__result-tabs a{'
            . 'box-sizing:border-box!important;min-height:44px!important;height:auto!important;'
            . 'padding:8px 16px!important}'
            . $plp . ' .page-wrapper .toolbar-products .modes .modes-mode.active,'
            . $plp . ' .page-wrapper .toolbar-products .modes .modes-mode:hover,'
            . $plp . ' .page-wrapper .toolbar-products .modes .modes-mode:focus-visible{'
            . 'box-shadow:none!important}'
            . $plp . ' .page-wrapper .block.filter .filter-options-content .item{'
            . 'padding:0!important;margin:0!important;min-height:0!important}'
            . $plp . ' .page-wrapper .block.filter .filter-options-content .item a{'
            . 'box-sizing:border-box!important;min-height:32px!important;height:32px!important;'
            . 'padding:6px 0!important;line-height:1.25!important}'
            /* 36.16 hierarchy: lock §2774 sets h1 20/600; .base paints 26/700
             * primary. Recs/Buscar em 14/700 = product h2. Equal 12px stack. */
            . $plp . '.catalogsearch-result-index .page-wrapper .page-title-wrapper .page-title,'
            . $plp . '.catalogsearch-result-index .page-wrapper .page-title-wrapper .page-title .base{'
            . 'font-family:var(--awa-font-heading,"Rubik",system-ui,sans-serif)!important;'
            . 'font-size:clamp(1.5rem,1.25rem + .9vw,1.75rem)!important;'
            . 'font-weight:700!important;line-height:1.2!important;letter-spacing:-.02em!important;'
            . 'color:var(--awa-ink,var(--awa-text))!important}'
            . $plp . '.catalogsearch-result-index .mst-search-in__title,'
            . $plp . '.catalogsearch-result-index .page-wrapper .mst-search__misc-recommendations dt.title,'
            . $plp . '.catalogsearch-result-index .page-wrapper .block.filter dt.filter-options-title{'
            . 'font-size:12px!important;font-weight:700!important;'
            . 'color:var(--awa-text-muted)!important;letter-spacing:.01em!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .search.results .awa-plp-b2b-gate-banner,'
            . $plp . '.catalogsearch-result-index .page-wrapper .awa-plp-b2b-gate-banner{margin:0 0 20px!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .awa-plp-b2b-gate-banner__title{'
            . 'font-size:16px!important;font-weight:700!important;line-height:1.3!important;'
            . 'color:var(--awa-ink,var(--awa-text))!important;flex:0 1 auto!important;min-width:0!important;order:2!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .awa-plp-b2b-gate-banner__desc{'
            . 'font-size:13px!important;font-weight:400!important;'
            . 'color:var(--awa-text-muted)!important;flex:1 0 100%!important;order:4!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .awa-plp-b2b-gate-banner__actions{'
            . 'margin-inline-start:auto!important;order:3!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .awa-plp-b2b-gate-banner__badge{order:1!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .mst-search__misc-recommendations dd.item{'
            . 'max-width:11.25rem!important;min-width:0!important;'
            . 'padding:5px 8px!important;border:1px solid var(--awa-border)!important;'
            . 'border-radius:var(--awa-control-radius)!important;'
            . 'background:var(--awa-surface,var(--awa-bg,Canvas))!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .mst-search__misc-recommendations dd.item a{'
            . 'max-width:100%!important;min-width:0!important;flex:1 1 auto!important;'
            . 'padding:0!important;border:0!important;background:transparent!important;'
            . 'white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;'
            . 'color:var(--awa-ink,var(--awa-text))!important;font-weight:500!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .mst-search__misc-recommendations dd.item .count{'
            . 'flex:0 0 auto!important;font-size:11px!important;font-weight:400!important;'
            . 'color:var(--awa-text-muted)!important}'
            . $plp . '.catalogsearch-result-index a.mst-search-in__item{'
            . 'color:var(--awa-ink,var(--awa-text))!important}'
            . $plp . ' .page-wrapper .item-product a.product-item-link{'
            . 'font-weight:600!important;color:var(--awa-ink,var(--awa-text))!important}'
            . $plp . ' .page-wrapper .item-product :is(.awa-b2b-sku,.awa-b2b-sku__label,.awa-b2b-sku__value){'
            . 'font-weight:500!important;color:var(--awa-text-muted)!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .col-main .mst-search__result-tabs a:not(.active),'
            . $plp . '.catalogsearch-result-index .page-wrapper .col-main .mst-search__result-tabs a:not(.active):link,'
            . $plp . '.catalogsearch-result-index .page-wrapper .col-main .mst-search__result-tabs a:not(.active):visited,'
            . $plp . '.catalogsearch-result-index .page-wrapper .col-main .mst-search__result-tabs a:not(.active):hover,'
            . $plp . '.catalogsearch-result-index .page-wrapper .col-main .mst-search__result-tabs a:not(.active):focus{'
            . 'color:var(--awa-ink,var(--awa-text))!important;'
            . 'background:var(--awa-surface,var(--awa-bg,Canvas))!important;'
            . 'border-color:var(--awa-border)!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .awa-plp-b2b-gate-banner__action--secondary,'
            . $plp . '.catalogsearch-result-index .page-wrapper .awa-plp-b2b-gate-banner__action--secondary:link,'
            . $plp . '.catalogsearch-result-index .page-wrapper .awa-plp-b2b-gate-banner__action--secondary:visited{'
            . 'color:var(--awa-ink,var(--awa-text))!important;'
            . 'background:transparent!important;border-color:var(--awa-border)!important}'
            . $plp . '.catalogsearch-result-index .page-wrapper .block.filter .block-content>.block-title.filter-title{'
            . 'display:none!important}'
            . '}'
            . '@media (min-width:768px) and (max-width:991px){'
            . $plp . ' .page-wrapper .page-main>.columns.layout-2-col{'
            . 'gap:12px!important;'
            . 'grid-template-columns:minmax(0,1fr)!important}'
            . '}'
            . '</style>'
            . '<script id="awa-plp-search-in-place">'
            . '(function(){'
            . 'function go(){'
            . 'var w=document.querySelector(".mst-search-in__wrapper");'
            . 'var s=document.querySelector(".page-main>.columns.layout-2-col>:first-child:not(.col-main)");'
            . 'var desk=window.matchMedia("(min-width:768px)").matches;'
            . 'if(!w||!s||!desk){return;}'
            . 'if(w.parentNode!==s){s.insertBefore(w,s.firstChild);}'
            . '}'
            . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",go);'
            . 'else go();'
            . '})();'
            . '</script>'
            ;

        $injected = preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * PLP/busca: emite só styles-l como CSS parser-inserted no &lt;head&gt; real
     * quando o viewport é desktop.
     *
     * Evidência Lighthouse --preset=desktop (2026-08-17): FCP 0.4–0.5s / LCP 0.8s
     * com CLS 0.68–0.87 quando styles-l chegava ~1.2s (depois do 1º paint).
     * O lock geométrico cobre header/hero/grade; themes (~2.3MB decode),
     * third-party e super-global no document.write inflavam Style & Layout
     * (~15s) e TBT lab ~12–14s. Esses três ficam em intent-only via
     * delayCatalogLegacyDesktopCssInject().
     *
     * document.write + matchMedia(min-width:768px) em script síncrono no &lt;head&gt;:
     * desktop bloqueia o 1º paint até styles-l (CLS); mobile não baixa os ~2MB decode.
     * FPC-safe (mesmo HTML para os dois viewports).
     */
    private function injectCatalogDesktopBlockingCss(string $html): string
    {
        if (str_contains($html, 'id="awa-plp-desktop-css-blocking"')) {
            return $html;
        }

        if (stripos($html, '</head>') === false) {
            return $html;
        }

        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $origin = '';
        if (preg_match('#(https?://[^/"\']+)/static/version#', $html, $originMatch)) {
            $origin = $originMatch[1];
        }

        $base = $origin . '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/';

        $links = [
            [
                'href' => $base . 'styles-l.min.css',
                'attrs' => ' data-awa-styles-l="catalog"',
            ],
        ];

        $htmlLinks = '';
        foreach ($links as $link) {
            $href = htmlspecialchars($link['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $htmlLinks .= '<link rel="stylesheet" href="' . $href
                . '" media="screen and (min-width: 768px)"'
                . $link['attrs'] . '>';
        }

        $script = '<script id="awa-plp-desktop-css-blocking">'
            . '(function(w,d){"use strict";'
            . 'if(!w.matchMedia||!w.matchMedia("(min-width: 768px)").matches){return;}'
            . 'd.write('
            . json_encode(
                $htmlLinks,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
            )
            . ');'
            . '})(window,document);'
            . '</script>';

        $injected = preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * PLP/busca: mantém boot por intenção e remove o fallback idle do defer-init.
     * Lighthouse --preset=desktop espera até 120s; timeout 3.5s/18s ainda entra
     * na janela de TBT. Clique no carrinho continua disparando appendDeferScript().
     */
    private function delayCatalogMinicartDeferIdleBoot(string $html): string
    {
        if (!str_contains($html, 'deferOnCatalogIntentOnly')) {
            return $html;
        }

        return str_replace(
            [
                'idleCallbackId = w.requestIdleCallback(bootFromIdle, { timeout: 3500 });',
                'idleCallbackId = w.requestIdleCallback(bootFromIdle, { timeout: 18000 });',
                'idleTimerId = w.setTimeout(bootFromIdle, 3500);',
                'idleTimerId = w.setTimeout(bootFromIdle, 18000);',
            ],
            '',
            $html
        );
    }

    /**
     * PLP/busca: intent loaders (minicart-ui-v2 / a11y) sem idle desktop.
     * O fallback de 2200–3000ms compete com TTI lab.
     */
    private function disableCatalogIntentDesktopIdle(string $html): string
    {
        if (!str_contains($html, 'var allowDesktopIdle = true;')) {
            return $html;
        }

        return str_replace(
            'var allowDesktopIdle = true;',
            'var allowDesktopIdle = false;',
            $html
        );
    }

    /**
     * PLP/busca: themes/third-party/super-global (e o fallback body de styles-l)
     * eram injetados no desktop assim que o script no body rodava. Decode
     * combinado ~5.5MB + Style & Layout ~15s (Lighthouse idle18 2026-08-17).
     * Passa a carregar só após pointer/keydown/touch — mesmo contrato da home
     * para styles-l. Mobile (viewport &lt; 768) permanece no listener de change.
     */
    private function delayCatalogLegacyDesktopCssInject(string $html): string
    {
        $needle = "    if (w.matchMedia('(min-width: 768px)').matches) {\n"
            . "        inject();\n"
            . "    } else {";

        if (!str_contains($html, $needle)) {
            return $html;
        }

        $replacement = "    if (w.matchMedia('(min-width: 768px)').matches) {\n"
            . "        /* awa-plp-legacy-css-intent */\n"
            . "        var intentEvents = ['pointerdown', 'keydown', 'touchstart'];\n"
            . "        var onIntent = function () {\n"
            . "            intentEvents.forEach(function (name) {\n"
            . "                w.removeEventListener(name, onIntent, true);\n"
            . "            });\n"
            . "            inject();\n"
            . "        };\n"
            . "        intentEvents.forEach(function (name) {\n"
            . "            w.addEventListener(name, onIntent, { capture: true, passive: name !== 'keydown' });\n"
            . "        });\n"
            . "    } else {";

        return str_replace($needle, $replacement, $html);
    }

    /**
     * BUG-OPS-VIEWTRANSITION-020 (2026-07-09) — suprime o console error
     * "DOMException: AbortError: Transition was skipped", disparado pela
     * View Transitions API nativa do Chromium (@view-transition CSS, ver
     * awa-view-transitions.phtml) quando a navegacao envolve um redirect
     * do servidor (ex.: login B2B -> dashboard). Runtime evidence via CDP
     * (Runtime.exceptionThrown) mostrou que a rejeicao dispara essencial-
     * mente no commit da navegacao, antes de qualquer <script> no fim do
     * <body> ter chance de registrar o listener a tempo.
     *
     * 2026-09-15: ampliado para cobrir tambem "InvalidStateError: Transition
     * was aborted because of invalid state." — variante do mesmo problema
     * (Chromium aborta a transicao quando a aba perde visibilidade/foco
     * durante a navegacao). Mesma causa raiz (transicao nunca chega a
     * comitar), nome de excecao diferente; sem impacto funcional real,
     * apenas ruido de console.
     *
     * Injetado aqui (e nao via bloco de layout head.additional) porque
     * runtime evidence via curl (offset do script vs. offset de "</head>"
     * no HTML final) confirmou que blocos de head.additional sao fisica-
     * mente realocados para o <body> nesta resposta — o preg_replace
     * abaixo, na mesma tecnica de injectGlobalFocusVisibleFallback()/
     * injectGlobalWebVitalsRum(), e a unica forma com garantia empirica
     * de aterrissar dentro do <head> real.
     */
    private function injectViewTransitionGuardScript(string $html): string
    {
        if (str_contains($html, 'id="awa-view-transition-guard"')) {
            return $html;
        }

        $script = '<script id="awa-view-transition-guard">'
            . '(function(w){"use strict";w.addEventListener("unhandledrejection",function(event){'
            . 'var reason=event.reason;'
            . 'if(!(reason instanceof DOMException)){return;}'
            . 'if(reason.name==="AbortError"&&reason.message==="Transition was skipped"){'
            . 'event.preventDefault();return;'
            . '}'
            . 'if(reason.name==="InvalidStateError"&&reason.message.indexOf("Transition was aborted")===0){'
            . 'event.preventDefault();'
            . '}'
            . '});'
            . '})(window);'
            . '</script>';

        $injected = preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    private function injectGlobalWebVitalsRum(string $html): string
    {
        if (str_contains($html, 'id="awa-web-vitals-rum"')) {
            return $html;
        }

        $script = '<script id="awa-web-vitals-rum">'
            . '(function(w,d,p){"use strict";if(w.__awaWebVitalsRumInit){return;}w.__awaWebVitalsRumInit=1;'
            . 'var s=(w.PerformanceObserver&&w.PerformanceObserver.supportedEntryTypes)?w.PerformanceObserver.supportedEntryTypes:[],sent={},cls=0,lcp=null,inp=0,inpSrc="event",path=(w.location&&w.location.pathname)?w.location.pathname:"/",low=(path||"").toLowerCase(),kind=(low==="/"||low==="/index.php")?"home":"internal",nav=(p&&typeof p.getEntriesByType==="function")?p.getEntriesByType("navigation")[0]:null,done=false;'
            . 'function r(v,dg){var f;if(typeof v!=="number"||!isFinite(v)){return null;}f=Math.pow(10,dg||0);return Math.round(v*f)/f;}'
            . 'function rate(n,v){if(n==="LCP"){return v<=2500?"good":(v<=4000?"needs-improvement":"poor");}if(n==="CLS"){return v<=0.1?"good":(v<=0.25?"needs-improvement":"poor");}if(n==="INP"){return v<=200?"good":(v<=500?"needs-improvement":"poor");}if(n==="FCP"){return v<=1800?"good":(v<=3000?"needs-improvement":"poor");}if(n==="TTFB"){return v<=800?"good":(v<=1800?"needs-improvement":"poor");}return "unknown";}'
            . 'function push(n,v,e){var pld,val=r(v,n==="CLS"?4:0);if(val===null||sent[n]){return;}sent[n]=1;w.dataLayer=w.dataLayer||[];pld={event:"awa_web_vital",web_vital_name:n,web_vital_value:val,web_vital_rating:rate(n,val),web_vital_page_path:path,web_vital_page_kind:kind};if(e&&typeof e==="object"){if(e.id){pld.web_vital_id=e.id;}if(e.delta!==undefined&&e.delta!==null){pld.web_vital_delta=r(e.delta,n==="CLS"?4:0);}if(e.navigationType){pld.web_vital_navigation_type=e.navigationType;}if(e.source){pld.web_vital_source=e.source;}}try{w.dataLayer.push(pld);}catch(_){}}'
            . 'function fin(){if(done){return;}done=true;if(lcp&&typeof lcp.startTime==="number"){push("LCP",lcp.startTime,{id:lcp.id});}push("CLS",cls);if(inp>0){push("INP",inp,{source:inpSrc});}}'
            . 'if(nav&&typeof nav.responseStart==="number"){push("TTFB",nav.responseStart,{navigationType:nav.type||"navigate"});}'
            . 'if(s.indexOf("paint")!==-1){try{(new w.PerformanceObserver(function(list){list.getEntries().forEach(function(en){if(en&&en.name==="first-contentful-paint"){push("FCP",en.startTime,{id:en.name});}});})).observe({type:"paint",buffered:true});}catch(_){}}'
            . 'if(s.indexOf("largest-contentful-paint")!==-1){try{(new w.PerformanceObserver(function(list){var es=list.getEntries();if(es.length){lcp=es[es.length-1];}})).observe({type:"largest-contentful-paint",buffered:true});}catch(_){}}'
            . 'if(s.indexOf("layout-shift")!==-1){try{(new w.PerformanceObserver(function(list){list.getEntries().forEach(function(en){if(en&&!en.hadRecentInput){cls+=en.value;}});})).observe({type:"layout-shift",buffered:true});}catch(_){}}'
            . 'if(s.indexOf("event")!==-1){try{(new w.PerformanceObserver(function(list){list.getEntries().forEach(function(en){if(en&&en.interactionId&&en.duration>inp){inp=en.duration;}});})).observe({type:"event",buffered:true,durationThreshold:40});}catch(_){}}else if(s.indexOf("first-input")!==-1){inpSrc="first-input";try{(new w.PerformanceObserver(function(list){var fi=list.getEntries()[0];if(fi&&fi.duration>inp){inp=fi.duration;}})).observe({type:"first-input",buffered:true});}catch(_){}}'
            . 'w.addEventListener("pagehide",fin,{capture:true,once:true});d.addEventListener("visibilitychange",function(){if(d.visibilityState==="hidden"){fin();}},{capture:true,once:true});w.addEventListener("load",function(){w.setTimeout(fin,15000);},{once:true});'
            . '})(window,document,window.performance||null);'
            . '</script>';

        $injected = preg_replace('/<\/head>/i', $script . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    private function gateHomeLargeStylesheets(string $html): string
    {
        if (!str_contains($html, 'id="awa-css-gate-queue"')) {
            return $html;
        }

        $urlsToGate = [];
        $fragmentPattern = static function (string $fragment): string {
            return preg_quote($fragment, '/');
        };

        foreach ($this->manifest->homeGateFragments() as $fragment) {
            if ($this->isHomeNeverGateFragment($fragment)) {
                continue;
            }

            if (preg_match(
                '/href=(["\'])([^"\']*' . $fragmentPattern($fragment) . '[^"\']*)\1/is',
                $html,
                $match
            )
            ) {
                $urlsToGate[] = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            /* Uma tag <link> por match — sem [\s\S] livre (evita comer o documento inteiro). */
            $html = preg_replace(
                '/<noscript>\s*<link\s[^>]*' . $fragmentPattern($fragment) . '[^>]*\/?>\s*<\/noscript>\s*/i',
                '',
                $html
            ) ?? $html;
        }

        $urlsToGate = array_values(array_filter(array_unique($urlsToGate)));

        if ($urlsToGate === []) {
            return $html;
        }

        return $this->mergeIntoCssGateQueue($html, $urlsToGate);
    }

    private function synchronizeHomeRefineStylesheet(string $html): string
    {
        // PSI 2026-07-17: não forçar media=all no refine da home (mantém print→all).
        return $html;
    }

    /**
     * Home freeze 2026-09-02 r8: do NOT inject awa-home-deferred-stack.min.css.
     * That sheet is 559KB with 42 :has() (incl. :has(.awa-carousel-ready) on ~48
     * shelves). Matching those selectors on every scroll/style recalc freezes
     * Chrome when the red trust strip hits the viewport. Design-system already
     * forces content-visibility:visible on .awa-home-section--below-fold and
     * owns footer 51.9. Still strip the fragment sheets the stack used to
     * absorb, so they cannot leak as individual print→all links. Catalog keeps
     * the source files (expensive :has() neutralized there).
     */
    private function consolidateHomeDeferredStylesheetStack(string $html): string
    {
        $deferredStackFragments = $this->manifest->homeDeferredStackFragments();
        $html = $this->stripStylesheetFragments($html, $deferredStackFragments);
        $html = $this->stripStylesheetFragments($html, [
            HeaderImpeccableCascadeLockCss::HOME_DEFERRED_STACK_FILE,
            'awa-home-deferred-stack.css',
            'data-awa-home-deferred-stack',
        ]);

        return $html;
    }

    /**
     * Home: awa-home-deferred-stack absorve awa-header-contract-grid (tokens antigos).
     * Reinjeta o SSOT geom imediatamente após o link do stack para vencer a cascade.
     * BUG-SHELL-GEOM-TOKEN-001.
     */
    private function injectHeaderContractGeomSsotForHome(string $html): string
    {
        if (str_contains($html, 'data-awa-header-contract-grid-body-terminal="1"')
            && str_contains($html, $this->manifest->query('header-contract-grid'))
        ) {
            return $html;
        }

        $html = preg_replace('/<link\s[^>]*awa-header-contract-grid-20260626[^>]*\/?>\s*/i', '', $html) ?? $html;

        if (!preg_match(
            '#/static/(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $versionMatch
        )
        ) {
            return $html;
        }

        $contractHref = '/static/' . $versionMatch[1]
            . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/css/'
            . $this->manifest->file('header-contract-grid')
            . $this->manifest->query('header-contract-grid');

        $tag = '<link rel="stylesheet" href="' . $contractHref . '" media="all"'
            . ' data-awa-header-contract-grid-body-terminal="1"'
            . ' data-awa-bundle="header-contract-grid"'
            . ' data-awa-geom-ssot="1"/>';

        if (preg_match('/<link\s[^>]*data-awa-home-deferred-stack="1"[^>]*\/?>/i', $html) === 1) {
            $injected = preg_replace(
                '/(<link\s[^>]*data-awa-home-deferred-stack="1"[^>]*\/?>)/i',
                '$1' . "\n" . $tag,
                $html,
                1
            );

            return is_string($injected) ? $injected : $html;
        }

        $injected = preg_replace('/<\/head>/i', $tag . "\n</head>", $html, 1);

        return is_string($injected) ? $injected : $html;
    }

    /**
     * Home: custom_default.css (ThemeOption) força Montserrat 14px e a/button vermelhos.
     * Estava na fila idle — no clique ou ~20s o storefront inteiro saltava (FOUC).
     */
    private function stripHomeThemeOptionLateFouc(string $html): string
    {
        $hadLink = str_contains($html, 'custom_default.css');
        $urls = $this->extractGateQueueUrls($html);
        $hadQueue = false;
        $filtered = [];
        foreach ($urls as $url) {
            if (is_string($url) && str_contains($url, 'custom_default.css')) {
                $hadQueue = true;
                continue;
            }
            $filtered[] = $url;
        }

        if ($hadLink) {
            $html = $this->stripStylesheetFragments($html, ['custom_default.css']);
        }
        if ($hadQueue) {
            $html = $this->replaceCssGateQueue($html, $filtered);
        }


        return $html;
    }

    /**
     * Home: remove da fila gate folhas omitidas no preload (consolidadas em critical-home / gate-polish).
     */
    private function pruneHomeNeverGateQueueUrls(string $html): string
    {
        $urls = $this->extractGateQueueUrls($html);
        if ($urls === []) {
            return $html;
        }

        $filtered = array_values(array_filter(
            $urls,
            function ($url): bool {
                if (!is_string($url)) {
                    return false;
                }
                foreach ($this->manifest->homeNeverGateFragments() as $never) {
                    if (str_contains($url, $never)) {
                        return false;
                    }
                }

                return true;
            }
        ));

        if (count($filtered) === count($urls)) {
            return $html;
        }

        return $this->replaceCssGateQueue($html, $filtered);
    }

    /**
     * @param string   $html
     * @param string[] $urls
     */
    private function replaceCssGateQueue(string $html, array $urls): string
    {
        $stylesM = [];
        $rest = [];
        foreach ($urls as $url) {
            if (is_string($url) && str_contains($url, 'styles-m.css')) {
                $stylesM[] = $url;
            } else {
                $rest[] = $url;
            }
        }
        $ordered = array_merge($rest, $stylesM);

        return preg_replace_callback(
            '/<script\s+id="awa-css-gate-queue"\s+type="application\/json"'
            . '(?:\s+data-awa-terminal-href="([^"]*)")?>(\[[^\]]*\])<\/script>/i',
            static function (array $matches) use ($ordered): string {
                $terminalAttr = isset($matches[1]) && $matches[1] !== ''
                    ? ' data-awa-terminal-href="' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                    : '';

                return '<script id="awa-css-gate-queue" type="application/json"' . $terminalAttr . '>'
                    . json_encode($ordered, JSON_UNESCAPED_SLASHES)
                    . '</script>';
            },
            $html,
            1
        ) ?? $html;
    }

    /**
     * Remove blocos <noscript> só com folhas já na fila gate (evita fetch duplicado no HTML).
     */
    private function stripHomeGatedNoscriptFallbacks(string $html): string
    {
        $gateFragments = $this->manifest->homeGateFragments();
        $pattern = '/<noscript>\s*((?:<link\s[^>]*\/?>\s*)+)<\/noscript>/i';

        return preg_replace_callback(
            $pattern,
            static function (array $matches) use ($gateFragments): string {
                $block = $matches[1];
                if (!preg_match_all('/href=(["\'])([^"\']+)\1/i', $block, $hrefMatches)) {
                    return $matches[0];
                }

                foreach ($hrefMatches[2] as $href) {
                    $isGated = false;
                    foreach ($gateFragments as $fragment) {
                        if (str_contains($href, $fragment)) {
                            $isGated = true;
                            break;
                        }
                    }
                    if (!$isGated) {
                        return $matches[0];
                    }
                }

                return '';
            },
            $html
        ) ?? $html;
    }

    private function removePrintLinksListedInGateQueue(string $html): string
    {
        foreach ($this->extractGateQueueUrls($html) as $url) {
            $escaped = preg_quote($url, '/');
            $html = preg_replace(
                '/<link\s[^>]*rel=["\']stylesheet["\'][^>]*href=["\']' . $escaped . '["\'][^>]*\/?>\s*/i',
                '',
                $html
            ) ?? $html;
        }

        return $this->removePreloadsListedInGateQueue($html);
    }

    /** Evita preload de folhas já na fila idle (gate) — economiza ~150KB no 1º paint. */
    private function removePreloadsListedInGateQueue(string $html): string
    {
        foreach ($this->extractGateQueueUrls($html) as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            if ($path === '') {
                continue;
            }
            $base = basename($path);
            if ($base === '') {
                continue;
            }
            $escaped = preg_quote($base, '/');
            $html = preg_replace(
                '/<link\s[^>]*rel=["\']preload["\'][^>]*href=["\'][^"\']*' . $escaped . '[^"\']*["\'][^>]*\/?>\s*/i',
                '',
                $html
            ) ?? $html;
        }

        return $html;
    }

    /**
     * @return string[]
     */
    private function extractGateQueueUrls(string $html): array
    {
        if (!preg_match(
            '/<script\s+id="awa-css-gate-queue"\s+type="application\/json"'
                . '(?:\s+data-awa-terminal-href="[^"]*")?>(\[[^\]]*\])<\/script>/i',
            $html,
            $match
        )
        ) {
            return [];
        }

        $decoded = json_decode($match[1], true);

        return is_array($decoded) ? $decoded : [];
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
     * @param string   $html
     * @param string[] $urls
     */
    private function mergeIntoCssGateQueue(string $html, array $urls): string
    {
        return preg_replace_callback(
            '/<script\s+id="awa-css-gate-queue"\s+type="application\/json"'
            . '(?:\s+data-awa-terminal-href="([^"]*)")?>(\[[^\]]*\])<\/script>/i',
            static function (array $matches) use ($urls): string {
                $existing = json_decode($matches[2], true);
                if (!is_array($existing)) {
                    $existing = [];
                }
                $merged = array_values(array_unique(array_merge($urls, $existing)));
                $stylesM = [];
                $rest = [];
                foreach ($merged as $url) {
                    if (is_string($url) && str_contains($url, 'styles-m.css')) {
                        $stylesM[] = $url;
                    } else {
                        $rest[] = $url;
                    }
                }
                $merged = array_merge($rest, $stylesM);
                $terminalAttr = isset($matches[1]) && $matches[1] !== ''
                    ? ' data-awa-terminal-href="' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                    : '';

                return '<script id="awa-css-gate-queue" type="application/json"' . $terminalAttr . '>'
                    . json_encode($merged, JSON_UNESCAPED_SLASHES)
                    . '</script>';
            },
            $html,
            1
        ) ?? $html;
    }
}
