<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Model;

/**
 * Fonte única dos nomes/fragmentos/queries dos assets da cascata CSS (Fase 3).
 *
 * A injeção/ordenação/dedup de CSS faz match por NOME de arquivo via
 * str_contains/regex em OptimizeHeadStylesPlugin, OptimizeHtmlResponseObserver,
 * PatchHomeHeaderHtmlPlugin e HomeCssGateParity. Antes desta classe, cada ponto
 * tinha seu literal próprio — renomear um CSS exigia editar ~15 lugares e um
 * esquecido quebrava a injeção silenciosamente.
 *
 * Convenções:
 * - file():     nome canônico com extensão ('foo.min.css'); '' quando o asset
 *               está aposentado e não tem href próprio.
 * - fragment(): string usada em str_contains/preg_quote — SEM extensão quando o
 *               match deve cobrir '.css' e '.min.css', COM extensão quando o
 *               comportamento vigente mirava uma variante específica.
 * - query():    sufixo de cache-bust COMPLETO ('?v=...'), pronto para concatenar
 *               após file(); '' quando não há bust.
 *
 * Valores com const canônica em {@see HeaderImpeccableCascadeLockCss} (ou no
 * gerado CascadeAssetVersionConsts, via ela) são REFERENCIADOS, não duplicados.
 *
 * Os grupos (homeGate, authStrip, ...) espelham EXATAMENTE as listas que cada
 * consumidor usava antes da centralização — inclusive divergências intencionais
 * (ver grupo cartStripObserver). Renomeie um asset editando ENTRIES; os grupos
 * e todos os consumidores seguem automaticamente.
 */
final class CascadeAssetManifest
{
    /**
     * Fragmentos expostos como const para consumidores em contexto de constante
     * (ex.: HomeCssGateParity::ACTIVE_GATE_FRAGMENTS). Os mesmos valores aparecem
     * em ENTRIES — aqui é a única cópia literal.
     */
    public const FRAGMENT_ALIGN_GRID_TERMINAL = 'awa-align-grid-terminal-2026-06-11';
    public const FRAGMENT_REFINE = 'awa-commerce-impeccable-refine';
    public const FRAGMENT_CUSTOM_DEFAULT = 'custom_default.css';
    public const FRAGMENT_LOGIN_TO_CART = 'product/login-to-cart';
    public const FRAGMENT_STATUS_PANEL = 'css/header/status-panel';
    public const FRAGMENT_B2B_STATUS_PANEL = 'awa-b2b-status-panel';

    /**
     * @var array<string, array{file: string, fragment: string, query: string, queryPdp?: string}>
     */
    private const ENTRIES = [
        'super-global' => [
            'file' => HeaderImpeccableCascadeLockCss::SUPER_GLOBAL_CSS_FILE,
            'fragment' => 'awa-super-global-20260611m',
            'query' => HeaderImpeccableCascadeLockCss::SUPER_GLOBAL_QUERY,
        ],
        'layout-bundle' => [
            'file' => 'awa-layout-bundle-20260611m.min.css',
            'fragment' => 'awa-layout-bundle-20260611m',
            'query' => '',
        ],
        'refine' => [
            'file' => HeaderImpeccableCascadeLockCss::REFINE_CSS_FILE,
            'fragment' => self::FRAGMENT_REFINE,
            'query' => HeaderImpeccableCascadeLockCss::REFINE_QUERY,
        ],
        'align-grid-terminal' => [
            'file' => HeaderImpeccableCascadeLockCss::ALIGN_GRID_CSS_FILE,
            'fragment' => self::FRAGMENT_ALIGN_GRID_TERMINAL,
            'query' => HeaderImpeccableCascadeLockCss::ALIGN_GRID_QUERY,
        ],
        // Fragmento genérico — casa qualquer versão datada do align-grid.
        'align-grid-base' => [
            'file' => '',
            'fragment' => 'awa-align-grid-terminal',
            'query' => '',
        ],
        'm2-visual-ssot' => [
            'file' => HeaderImpeccableCascadeLockCss::M2_VISUAL_SSOT_FILE,
            'fragment' => 'awa-m2-visual-ssot',
            'query' => HeaderImpeccableCascadeLockCss::M2_VISUAL_SSOT_QUERY,
        ],
        'home-critical-stack' => [
            'file' => HeaderImpeccableCascadeLockCss::HOME_CRITICAL_STACK_FILE,
            'fragment' => 'awa-home-critical-stack',
            'query' => HeaderImpeccableCascadeLockCss::HOME_CRITICAL_STACK_QUERY,
        ],
        'home-critical-stack-dated' => [
            'file' => HeaderImpeccableCascadeLockCss::HOME_CRITICAL_STACK_FILE,
            'fragment' => 'awa-home-critical-stack-2026-06-11',
            'query' => HeaderImpeccableCascadeLockCss::HOME_CRITICAL_STACK_QUERY,
        ],
        'home-deferred-stack' => [
            'file' => HeaderImpeccableCascadeLockCss::HOME_DEFERRED_STACK_FILE,
            'fragment' => 'awa-home-deferred-stack',
            'query' => HeaderImpeccableCascadeLockCss::HOME_DEFERRED_STACK_QUERY,
        ],
        'visual-fixes' => [
            'file' => 'awa-visual-fixes-2026-06-29-final.min.css',
            'fragment' => 'awa-visual-fixes-2026-06-29-final',
            'query' => '?v=' . HeaderImpeccableCascadeLockCss::VISUAL_FIXES_CSS_QUERY,
        ],
        'header-contract-grid' => [
            'file' => 'awa-header-contract-grid-20260626.min.css',
            'fragment' => 'awa-header-contract-grid-20260626',
            'query' => '?v=20260903-secao51-53-p0-gutter-delivery',
        ],
        'home-density-grid' => [
            'file' => 'awa-home-density-grid-20260611.min.css',
            'fragment' => 'awa-home-density-grid-20260611',
            'query' => '?v=20260803-pdp-shell24c',
        ],
        // MORTO: awa-catalog-density-grid-20260611 → _deprecated/ (sem href próprio).
        'catalog-density-grid' => [
            'file' => '',
            'fragment' => '',
            'query' => '',
        ],
        'footer-terminal-lock' => [
            'file' => HeaderImpeccableCascadeLockCss::FOOTER_CSS_FILE,
            'fragment' => 'awa-footer-terminal-lock-v1',
            'query' => HeaderImpeccableCascadeLockCss::FOOTER_CSS_QUERY,
        ],
        'header-simplify-ui-terminal-lock' => [
            'file' => 'awa-header-simplify-ui-terminal-lock.min.css',
            'fragment' => 'awa-header-simplify-ui-terminal-lock',
            'query' => '',
        ],
        'header-visual-audit-fixes' => [
            'file' => 'awa-header-visual-audit-fixes-20260630.min.css',
            'fragment' => 'awa-header-visual-audit-fixes-20260630',
            'query' => '',
        ],
        'home-impeccable-terminal' => [
            'file' => 'awa-home-impeccable-terminal-v1.min.css',
            'fragment' => 'awa-home-impeccable-terminal-v1',
            'query' => '',
        ],
        'home-product-design-audit-polish' => [
            'file' => 'awa-home-product-design-audit-polish-20260629.min.css',
            'fragment' => 'awa-home-product-design-audit-polish-20260629',
            'query' => '',
        ],
        'home-compact-spacing-terminal' => [
            'file' => 'awa-home-compact-spacing-terminal-20260707.min.css',
            'fragment' => 'awa-home-compact-spacing-terminal-20260707',
            'query' => '',
        ],
        'visual-bugfix-terminal-20260707' => [
            'file' => 'awa-visual-bugfix-terminal-20260707.min.css',
            'fragment' => 'awa-visual-bugfix-terminal-20260707',
            'query' => '',
        ],
        'align-grid-inline-lock-phase3' => [
            'file' => 'awa-align-grid-inline-lock-20260626-phase3d22b.min.css',
            'fragment' => 'awa-align-grid-inline-lock-20260626-phase3d22b',
            'query' => '',
        ],
        'critical-fold' => [
            'file' => 'awa-critical-fold.min.css',
            'fragment' => 'awa-critical-fold',
            'query' => '',
        ],
        'cls-nav-fix' => [
            'file' => 'awa-cls-nav-fix.min.css',
            'fragment' => 'awa-cls-nav-fix',
            'query' => '',
        ],
        'header-refine-terminal' => [
            'file' => 'awa-header-refine-terminal.min.css',
            'fragment' => 'awa-header-refine-terminal',
            'query' => '?v=' . HeaderImpeccableCascadeLockCss::HEADER_TERMINAL_VERSION,
        ],
        'bundle-async-distill-lock' => [
            'file' => 'awa-bundle-async-distill-lock.min.css',
            'fragment' => 'awa-bundle-async-distill-lock',
            'query' => HeaderImpeccableCascadeLockCss::HOME_DISTILL_LOCK_QUERY,
            'queryPdp' => HeaderImpeccableCascadeLockCss::PDP_DISTILL_LOCK_QUERY,
        ],
        'ui-simplify-terminal' => [
            'file' => 'awa-ui-simplify-terminal.min.css',
            'fragment' => 'awa-ui-simplify-terminal',
            'query' => HeaderImpeccableCascadeLockCss::PDP_UI_SIMPLIFY_QUERY,
        ],
        'impeccable-audit' => [
            'file' => 'awa-impeccable-audit-2026-05-28.min.css',
            'fragment' => 'awa-impeccable-audit-2026-05-28',
            'query' => '',
        ],
        'impeccable-audit-min' => [
            'file' => 'awa-impeccable-audit-2026-05-28.min.css',
            'fragment' => 'awa-impeccable-audit-2026-05-28.min.css',
            'query' => '',
        ],
        'impeccable-audit-css' => [
            'file' => 'awa-impeccable-audit-2026-05-28.css',
            'fragment' => 'awa-impeccable-audit-2026-05-28.css',
            'query' => '',
        ],
        'home-standardize-terminal-wins' => [
            // MORTO 2026-08-13: saiu de web/css após desligar o injetor no CSS gate.
            'file' => '',
            'fragment' => 'awa-home-standardize-terminal-wins',
            'query' => '',
        ],
        'carousel-bundle' => [
            'file' => 'awa-carousel-bundle.min.css',
            'fragment' => 'awa-carousel-bundle',
            'query' => '',
        ],
        'head-tail-bundle' => [
            'file' => 'awa-head-tail-bundle.min.css',
            'fragment' => 'awa-head-tail-bundle',
            'query' => '',
        ],
        'head-tail-bundle-min' => [
            'file' => 'awa-head-tail-bundle.min.css',
            'fragment' => 'awa-head-tail-bundle.min.css',
            'query' => '',
        ],
        'modern-optimizations' => [
            'file' => 'awa-modern-optimizations-2026.min.css',
            'fragment' => 'awa-modern-optimizations-2026',
            'query' => '',
        ],
        'defer-global-bundle' => [
            'file' => 'awa-defer-global-bundle.min.css',
            'fragment' => 'awa-defer-global-bundle',
            'query' => '',
        ],
        'third-party-bundle' => [
            'file' => 'awa-third-party-bundle.min.css',
            'fragment' => 'awa-third-party-bundle',
            'query' => '',
        ],
        'custom-default' => [
            'file' => self::FRAGMENT_CUSTOM_DEFAULT,
            'fragment' => self::FRAGMENT_CUSTOM_DEFAULT,
            'query' => '',
        ],
        'login-to-cart' => [
            'file' => '',
            'fragment' => self::FRAGMENT_LOGIN_TO_CART,
            'query' => '',
        ],
        'status-panel' => [
            'file' => '',
            'fragment' => self::FRAGMENT_STATUS_PANEL,
            'query' => '',
        ],
        'b2b-status-panel' => [
            'file' => 'awa-b2b-status-panel.css',
            'fragment' => self::FRAGMENT_B2B_STATUS_PANEL,
            'query' => '',
        ],
        'focus-visible' => [
            'file' => '',
            'fragment' => 'awa-focus-visible',
            'query' => '',
        ],
        'styles-m' => [
            'file' => 'styles-m.css',
            'fragment' => 'styles-m.css',
            'query' => '',
        ],
        'styles-l' => [
            'file' => 'styles-l.css',
            'fragment' => 'styles-l.css',
            'query' => '',
        ],
        'themes-min' => [
            'file' => 'themes.min.css',
            'fragment' => 'themes.min.css',
            'query' => '',
        ],
        'themes-css' => [
            'file' => 'themes.css',
            'fragment' => 'themes.css',
            'query' => '',
        ],
        'visual-bugfix' => [
            'file' => 'awa-visual-bugfix.min.css',
            'fragment' => 'awa-visual-bugfix',
            'query' => '',
        ],
        'visual-bugfix-min' => [
            'file' => 'awa-visual-bugfix.min.css',
            'fragment' => 'awa-visual-bugfix.min.css',
            'query' => '',
        ],
        'visual-bugfix-css' => [
            'file' => 'awa-visual-bugfix.css',
            'fragment' => 'awa-visual-bugfix.css',
            'query' => '',
        ],
        'visual-noise' => [
            'file' => '',
            'fragment' => 'awa-visual-noise-2026-07-15',
            'query' => '',
        ],
        'cookie-fab-collision-fix' => [
            'file' => '',
            'fragment' => 'awa-cookie-fab-collision-fix',
            'query' => '',
        ],
        'cookie-consent-fix' => [
            'file' => 'awa-cookie-consent-fix.min.css',
            'fragment' => 'awa-cookie-consent-fix',
            'query' => '',
        ],
        'home-visual-bugfixes' => [
            'file' => 'awa-home-visual-bugfixes-2026-06-28.min.css',
            'fragment' => 'awa-home-visual-bugfixes',
            'query' => '',
        ],
        'impeccable-layout' => [
            'file' => 'awa-impeccable-layout-2026-06-16.min.css',
            'fragment' => 'awa-impeccable-layout-2026-06-16',
            'query' => '',
        ],
        'design-system' => [
            'file' => 'awa-design-system.min.css',
            'fragment' => 'awa-design-system',
            'query' => '?v=20260903-secao51-53-p0-gutter-delivery',
        ],
        'plp-shell' => [
            'file' => 'awa-plp-shell-final.min.css',
            'fragment' => 'awa-plp-shell-final',
            'query' => '?v=20260827-visual-lote5',
        ],
        'ai-assistant' => [
            'file' => 'GrupoAwamotos_AiAssistant/css/ai-assistant.min.css',
            'fragment' => 'AiAssistant/css/ai-assistant',
            'query' => '?v=20260821-ai-chat-chips-r24',
        ],
        'visual-qa-fixes' => [
            'file' => 'awa-visual-qa-fixes-2026-06-17.min.css',
            'fragment' => 'awa-visual-qa-fixes',
            'query' => '',
        ],
        'home-swiper-cls-fix' => [
            'file' => 'awa-home-swiper-cls-fix.min.css',
            'fragment' => 'awa-home-swiper-cls-fix',
            'query' => '',
        ],
        'shelf-carousel' => [
            'file' => 'awa-shelf-carousel.min.css',
            'fragment' => 'awa-shelf-carousel',
            'query' => '',
        ],
        'carousel-contract' => [
            'file' => 'awa-carousel-contract.min.css',
            'fragment' => 'awa-carousel-contract',
            'query' => '',
        ],
        'home-corporate-density-grid' => [
            'file' => 'awa-home-corporate-density-grid-2026-06.min.css',
            'fragment' => 'awa-home-corporate-density-grid',
            'query' => '',
        ],
        'bugfix-terminal' => [
            'file' => 'awa-bugfix-terminal-2026-06-12.min.css',
            'fragment' => 'awa-bugfix-terminal-2026-06-12',
            'query' => '',
        ],
        'plp-distill' => [
            'file' => '',
            'fragment' => 'awa-plp-distill',
            'query' => '',
        ],
        'audit-bundle' => [
            'file' => 'awa-audit-bundle.min.css',
            'fragment' => 'awa-audit-bundle',
            'query' => '',
        ],
        'audit-bundle-min' => [
            'file' => 'awa-audit-bundle.min.css',
            'fragment' => 'awa-audit-bundle.min.css',
            'query' => '',
        ],
        'audit-bundle-css' => [
            'file' => 'awa-audit-bundle.css',
            'fragment' => 'awa-audit-bundle.css',
            'query' => '',
        ],
        'social-proof' => [
            'file' => 'social-proof.css',
            'fragment' => 'social-proof',
            'query' => '',
        ],
        'round9-post-themeoption' => [
            'file' => 'awa-round9-post-themeoption-overrides.min.css',
            'fragment' => 'awa-round9-post-themeoption-overrides',
            'query' => '',
        ],
        'b2b-foundation' => [
            'file' => 'awa-components-b2b-foundation.min.css',
            'fragment' => 'awa-components-b2b-foundation',
            'query' => '',
        ],
        'compat-b2b-nav-plp-cart-checkout' => [
            'file' => 'awa-compat-b2b-nav-plp-cart-checkout.min.css',
            'fragment' => 'awa-compat-b2b-nav-plp-cart-checkout',
            'query' => '',
        ],
        'page-home-category-premium' => [
            'file' => 'awa-page-home-category-premium.min.css',
            'fragment' => 'awa-page-home-category-premium',
            'query' => '',
        ],
        'gallery' => [
            'file' => 'mage/gallery/gallery.min.css',
            'fragment' => 'mage/gallery/gallery',
            'query' => '',
        ],
        'pdp-shell-final' => [
            // Só existe em _deprecated/ — fragmento serve para defer/strip de URL stale.
            'file' => '',
            'fragment' => 'awa-pdp-shell-final',
            'query' => '',
        ],
        'plp-critical-fixes' => [
            'file' => 'awa-plp-critical-fixes.min.css',
            'fragment' => 'awa-plp-critical-fixes',
            'query' => '',
        ],
        'plp-final-polish' => [
            'file' => '',
            'fragment' => 'awa-plp-final-polish',
            'query' => '',
        ],
        'ui-promax-bundle' => [
            'file' => 'awa-ui-promax-bundle.min.css',
            'fragment' => 'awa-ui-promax-bundle',
            'query' => '',
        ],
        'ui-promax-bundle-min' => [
            'file' => 'awa-ui-promax-bundle.min.css',
            'fragment' => 'awa-ui-promax-bundle.min.css',
            'query' => '',
        ],
        // Variante não-min: arquivo aposentado, mas URLs stale em FPC/browser ainda
        // aparecem — o observer (safety net) faz strip dela; o plugin não.
        'ui-promax-bundle-css' => [
            'file' => '',
            'fragment' => 'awa-ui-promax-bundle.css',
            'query' => '',
        ],
        'ux-pro-max-header' => [
            'file' => '',
            'fragment' => 'awa-ui-ux-pro-max-header',
            'query' => '',
        ],
        'ux-pro-max-header-dated' => [
            'file' => 'awa-ui-ux-pro-max-header-2026-05-19.min.css',
            'fragment' => 'awa-ui-ux-pro-max-header-2026-05-19',
            'query' => '',
        ],
        'structural-fix' => [
            'file' => 'awa-structural-fix-2026-05-20.min.css',
            'fragment' => 'awa-structural-fix-2026-05-20',
            'query' => '',
        ],
        'home-hover-lock-min' => [
            // MORTO 2026-08-13: saiu de web/css. Fragmento só para strip/gate de URL stale.
            'file' => '',
            'fragment' => 'awa-home-hover-lock.min.css',
            'query' => '',
        ],
        'home-hover-lock-css' => [
            // Só existe a variante .min.css; a plain é strip de URL stale (gate home).
            'file' => '',
            'fragment' => 'awa-home-hover-lock.css',
            'query' => '',
        ],
        'home-flex-grid-flow' => [
            // MORTO 2026-08-13: saiu de web/css. Fragmento só para strip/gate de URL stale.
            'file' => '',
            'fragment' => 'awa-home-flex-grid-flow.min.css',
            'query' => '',
        ],
        'home-light-lock' => [
            'file' => HeaderImpeccableCascadeLockCss::HOME_LIGHT_CSS_FILE,
            'fragment' => 'awa-header-home-light-lock-v1',
            'query' => HeaderImpeccableCascadeLockCss::HOME_LIGHT_QUERY,
        ],
        'header-mobile-grid-critical' => [
            'file' => '',
            'fragment' => 'awa-header-mobile-grid-critical',
            'query' => '',
        ],
        'menu-v2-dept-open-fix-css' => [
            'file' => 'awa-menu-v2-dept-open-fix.css',
            'fragment' => 'awa-menu-v2-dept-open-fix.css',
            'query' => '',
        ],
        'visual-polish-r2-css' => [
            'file' => 'awa-visual-polish-r2.css',
            'fragment' => 'awa-visual-polish-r2.css',
            'query' => '',
        ],
        'master-fix-js' => [
            'file' => 'awa-master-fix.js',
            'fragment' => 'awa-master-fix.js',
            'query' => '?v=20260815-hygiene-r1',
        ],
        'master-fix-js-min' => [
            'file' => 'awa-master-fix.min.js',
            'fragment' => 'awa-master-fix.min.js',
            'query' => '',
        ],
        'css-gate-js' => [
            'file' => 'awa-css-gate.min.js',
            'fragment' => 'awa-css-gate.min.js',
            'query' => '?v=' . HeaderImpeccableCascadeLockCss::GATE_SCRIPT_QUERY,
        ],
        'b2b-panel-hydrate-js' => [
            'file' => 'GrupoAwamotos_B2B/js/b2b-panel-hydrate.js',
            'fragment' => 'GrupoAwamotos_B2B/js/b2b-panel-hydrate.js',
            'query' => '',
        ],
        'b2b-panel-hydrate-js-min' => [
            'file' => 'GrupoAwamotos_B2B/js/b2b-panel-hydrate.min.js',
            'fragment' => 'GrupoAwamotos_B2B/js/b2b-panel-hydrate.min.js',
            'query' => '',
        ],
    ];

    /**
     * Grupos de match por contexto/rota — listas ordenadas de chaves de ENTRIES.
     * A ORDEM e o CONJUNTO reproduzem 1:1 as consts que cada consumidor tinha
     * antes da Fase 3 (comportamento do HTML servido inalterado).
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        // OptimizeHeadStylesPlugin + OptimizeHtmlResponseObserver (idênticas).
        'catalogStripImpeccable' => ['impeccable-audit', 'refine'],
        'catalogStripLegacyBundle' => [
            'ui-simplify-terminal',
            'head-tail-bundle',
            'bundle-async-distill-lock',
            'plp-distill',
            'gallery',
        ],
        'catalogDefer' => [
            'impeccable-audit',
            'audit-bundle-css',
            'audit-bundle-min',
            'social-proof',
            'gallery',
            'pdp-shell-final',
            'round9-post-themeoption',
            'b2b-foundation',
            'compat-b2b-nav-plp-cart-checkout',
            'page-home-category-premium',
        ],
        'homeDefer' => [
            'visual-fixes',
            'visual-noise',
            'cookie-fab-collision-fix',
            'home-visual-bugfixes',
            'impeccable-layout',
            'visual-qa-fixes',
            'home-swiper-cls-fix',
            'shelf-carousel',
            'carousel-contract',
            'home-corporate-density-grid',
            // Home LCP: convert leftover blocking XML <link> if layout remove misses (FPC stale).
            'design-system',
        ],
        'homeDeferredStack' => [
            'bugfix-terminal',
            'home-swiper-cls-fix',
            'carousel-bundle',
            'shelf-carousel',
            'carousel-contract',
            'home-visual-bugfixes',
            'impeccable-layout',
            'visual-qa-fixes',
            'visual-noise',
            'cookie-fab-collision-fix',
            'cookie-consent-fix',
            'home-corporate-density-grid',
            'home-density-grid',
            'header-contract-grid',
            'visual-fixes',
            // footer-terminal-lock fica FORA do stack: convertFooterCssToAsync
            // injeta a folha (8 KB gzip) print→all imediato + esqueleto CLS inline.
            // Absorver no stack obrigava 56 KB de footer-critical no HTML.
            'header-simplify-ui-terminal-lock',
            'header-visual-audit-fixes',
            'home-impeccable-terminal',
            'home-product-design-audit-polish',
            'home-compact-spacing-terminal',
            'visual-bugfix-terminal-20260707',
            'align-grid-inline-lock-phase3',
            'critical-fold',
            'cls-nav-fix',
        ],
        // Menu v2 — lista vazia hoje (awa-menu-v2-dept-open-fix tem loader próprio).
        'menuDefer' => [],
        'plpDefer' => [],
        'norouteDefer' => [
            'align-grid-terminal',
            'header-contract-grid',
            'audit-bundle',
            'refine',
            'defer-global-bundle',
            'focus-visible',
            'head-tail-bundle',
            'impeccable-audit',
            'structural-fix',
            'ui-promax-bundle',
            'ui-simplify-terminal',
            'ux-pro-max-header-dated',
            'visual-bugfix',
        ],
        // Plugin (consolidador). Sem a variante .css do promax — ver cartStripObserver.
        'cartStrip' => ['ui-promax-bundle-min', 'refine', 'focus-visible'],
        // Observer (safety net): além da lista do plugin, faz strip da variante
        // .css aposentada do promax (URLs stale em FPC/cache de browser).
        'cartStripObserver' => [
            'ui-promax-bundle-min',
            'ui-promax-bundle-css',
            'refine',
            'focus-visible',
        ],
        'authStrip' => [
            'plp-final-polish',
            'ui-promax-bundle',
            'refine',
            'ux-pro-max-header',
            'structural-fix',
            'custom-default',
            'styles-m',
            'styles-l',
            'design-system',
            'impeccable-layout',
            'ai-assistant',
        ],
        'b2bAccountStrip' => ['plp-final-polish', 'audit-bundle', 'carousel-bundle'],
        'migratedHeader' => ['header-refine-terminal'],
        'homeGate' => [
            'home-hover-lock-min',
            'home-hover-lock-css',
            'impeccable-audit-min',
            'impeccable-audit-css',
            'home-flex-grid-flow',
            'head-tail-bundle-min',
            'defer-global-bundle',
            'ui-simplify-terminal',
            'home-standardize-terminal-wins',
            'footer-terminal-lock',
            'align-grid-terminal',
            'login-to-cart',
            'status-panel',
            'b2b-status-panel',
        ],
        'homeNeverGate' => [
            'focus-visible',
            'styles-l',
            'carousel-bundle',
            'shelf-carousel',
            'home-critical-stack-dated',
            'home-critical-stack',
            'bundle-async-distill-lock',
            'visual-bugfix-min',
            'visual-bugfix-css',
            'ux-pro-max-header-dated',
            'structural-fix',
            'home-deferred-stack',
            'third-party-bundle',
            'themes-min',
            'themes-css',
            'refine',
            // Must print->all immediately -- never idle-gate (FOUC + LCP).
            'design-system',
        ],
        'heavyLegacyScripts' => ['master-fix-js', 'master-fix-js-min'],
        'checkoutAuthStripScripts' => ['b2b-panel-hydrate-js-min', 'b2b-panel-hydrate-js'],
        // Home: strips de folhas críticas legadas quando o stack consolidado está ativo.
        'homeStaleCriticalStrip' => [
            'header-mobile-grid-critical',
            'home-light-lock',
            'bundle-async-distill-lock',
        ],
        // Observer: preloads stale de folhas async (print/onload) — warning no Chrome.
        'asyncDeferredPreloadStrip' => ['visual-polish-r2-css', 'audit-bundle-min', 'audit-bundle-css'],
    ];

    /**
     * Queries stale do awa-css-gate.min.js normalizadas para a canônica
     * (plugin patchStaleHomeHeaderAssets + observer homônimo — lista única).
     *
     * @var list<string>
     */
    private const STALE_GATE_JS_URLS = [
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
    ];

    /**
     * @param array<string, string> $fragmentOverrides Costura de teste (rename
     *        simulado): sobrescreve o fragmento de um asset. Produção usa [].
     */
    public function __construct(
        private readonly array $fragmentOverrides = [],
    ) {
    }

    public function file(string $key): string
    {
        return self::ENTRIES[$key]['file'] ?? throw new \InvalidArgumentException(
            sprintf('CascadeAssetManifest: asset desconhecido "%s".', $key)
        );
    }

    public function fragment(string $key): string
    {
        if (isset($this->fragmentOverrides[$key])) {
            return $this->fragmentOverrides[$key];
        }

        return self::ENTRIES[$key]['fragment'] ?? throw new \InvalidArgumentException(
            sprintf('CascadeAssetManifest: asset desconhecido "%s".', $key)
        );
    }

    public function query(string $key): string
    {
        return self::ENTRIES[$key]['query'] ?? throw new \InvalidArgumentException(
            sprintf('CascadeAssetManifest: asset desconhecido "%s".', $key)
        );
    }

    /**
     * Query de cache-bust usada na normalização PDP (normalizePdpTerminalStylesheets).
     * Cai na query padrão quando o asset não tem variante PDP.
     */
    public function queryPdp(string $key): string
    {
        return self::ENTRIES[$key]['queryPdp'] ?? $this->query($key);
    }

    /**
     * @return list<string>
     */
    public function staleGateJsUrls(): array
    {
        return self::STALE_GATE_JS_URLS;
    }

    /**
     * Fragmentos de um grupo, na ordem canônica.
     *
     * @return list<string>
     */
    public function group(string $name): array
    {
        $keys = self::GROUPS[$name] ?? throw new \InvalidArgumentException(
            sprintf('CascadeAssetManifest: grupo desconhecido "%s".', $name)
        );

        return array_map($this->fragment(...), $keys);
    }

    /** @return list<string> */
    public function homeGateFragments(): array
    {
        return $this->group('homeGate');
    }

    /** @return list<string> */
    public function homeNeverGateFragments(): array
    {
        return $this->group('homeNeverGate');
    }

    /** @return list<string> */
    public function homeDeferFragments(): array
    {
        return $this->group('homeDefer');
    }

    /** @return list<string> */
    public function homeDeferredStackFragments(): array
    {
        return $this->group('homeDeferredStack');
    }

    /** @return list<string> */
    public function homeStaleCriticalStripFragments(): array
    {
        return $this->group('homeStaleCriticalStrip');
    }

    /** @return list<string> */
    public function catalogStripImpeccableFragments(): array
    {
        return $this->group('catalogStripImpeccable');
    }

    /** @return list<string> */
    public function catalogStripLegacyBundleFragments(): array
    {
        return $this->group('catalogStripLegacyBundle');
    }

    /** @return list<string> */
    public function catalogDeferFragments(): array
    {
        return $this->group('catalogDefer');
    }

    /** @return list<string> */
    public function menuDeferFragments(): array
    {
        return $this->group('menuDefer');
    }

    /** @return list<string> */
    public function plpDeferFragments(): array
    {
        return $this->group('plpDefer');
    }

    /** @return list<string> */
    public function norouteDeferFragments(): array
    {
        return $this->group('norouteDefer');
    }

    /** @return list<string> */
    public function cartStripFragments(): array
    {
        return $this->group('cartStrip');
    }

    /** @return list<string> */
    public function cartStripObserverFragments(): array
    {
        return $this->group('cartStripObserver');
    }

    /** @return list<string> */
    public function authStripFragments(): array
    {
        return $this->group('authStrip');
    }

    /** @return list<string> */
    public function b2bAccountStripFragments(): array
    {
        return $this->group('b2bAccountStrip');
    }

    /** @return list<string> */
    public function migratedHeaderFragments(): array
    {
        return $this->group('migratedHeader');
    }

    /** @return list<string> */
    public function heavyLegacyScriptFragments(): array
    {
        return $this->group('heavyLegacyScripts');
    }

    /** @return list<string> */
    public function checkoutAuthStripScriptFragments(): array
    {
        return $this->group('checkoutAuthStripScripts');
    }

    /** @return list<string> */
    public function asyncDeferredPreloadStripFragments(): array
    {
        return $this->group('asyncDeferredPreloadStrip');
    }
}
