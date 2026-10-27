<?php

declare(strict_types=1);

namespace GrupoAwamotos\Theme\Model;

use GrupoAwamotos\Theme\Model\Generated\CascadeAssetVersionConsts;

/**
 * CSS terminal único — vence super-global (promo vermelho), visual-audit (72–88px / grid 4 col mobile)
 * e folhas injetadas pelo CSS gate após </body> (pin script mantém este bloco por último).
 */
final class HeaderImpeccableCascadeLockCss
{
    public const STYLE_ID = 'awa-header-impeccable-cascade-lock-v21';
    public const CATALOG_PARITY_STYLE_ID = 'awa-header-catalog-parity-v1';

    /** Lock leve (~4KB) — footer em PLP/PDP/checkout onde o cascade-lock do header é omitido. */
    public const FOOTER_STYLE_ID = 'awa-footer-terminal-lock-v1';

    /** Regras estruturais críticas que impedem CLS enquanto o CSS completo do footer carrega. */
    public const FOOTER_CRITICAL_STYLE_ID = 'awa-footer-critical-stability-v1';

    /** Arquivo CSS externo gerado de footerTerminalRules() — carregado async no body. */
    public const FOOTER_CSS_FILE = 'awa-footer-terminal-lock-v1.min.css';

    public const FOOTER_CSS_QUERY = CascadeAssetVersionConsts::FOOTER;

    public const CROSSPAGE_SHELL_STYLE_ID = 'awa-crosspage-shell-lock-r68';

    public const HOME_IMPECCABLE_TERMINAL_STYLE_ID = 'awa-home-impeccable-terminal-v1';

    /** Home — subset terminal do header (~11KB) quando o cascade-lock completo é omitido. */
    public const HOME_LIGHT_STYLE_ID = 'awa-header-home-light-lock-v1';

    public const HOME_LIGHT_CSS_FILE = 'awa-header-home-light-lock-v1.min.css';

    public const HOME_LIGHT_QUERY = '?v=20260616-adapt';

    public const HOME_GUARD_SCRIPT_ID = 'awa-header-mobile-grid-guard';

    public const DISTILL_MOBILE_GRID_SCRIPT_ID = 'awa-header-distill-mobile-grid-20260616c';

    public const HEADER_NAV_AXIS_LOCK_SCRIPT_ID = 'awa-header-nav-axis-lock-v1';

    public const HEADER_TERMINAL_VERSION = '25-visual-bugs-r1';

    /** Subset leve (~6KB) — home/PLP/carrinho onde o cascade-lock completo é omitido. */
    public const HEADER_ESSENTIAL_STYLE_ID = 'awa-header-essential-terminal-v1';

    /**
     * Audit30 Média+Baixa (~7KB) — home/carrinho omitem o cascade-lock completo;
     * este bloco garante dark header, skip/busca/cookie/toggle sem os ~112KB.
     */
    public const AUDIT30_SURFACE_STYLE_ID = 'awa-audit30-surface-lock-v1';

    /**
     * 2026-07-20: alinha o shell externo do footer-bottom ao container de 1280px.
     * O padding interno preserva a largura útil de 1248px sem recuo duplo.
     */
    public const GATE_SCRIPT_QUERY = '20260902-home-norefine';

    /**
     * Cache-busting para awa-visual-fixes-2026-06-29-final.min.css, aplicado
     * via GrupoAwamotos\Theme\Plugin\Response\OptimizeHeadStylesPlugin
     * (pós-processamento do HTML já renderizado pelo Renderer::renderHeadAssets()).
     *
     * NUNCA colocar esta query diretamente no atributo `src` de <css> no
     * layout XML: o core resolve o content-type do asset via
     * `pathinfo($path, PATHINFO_EXTENSION)` (Asset\Source::getContentType),
     * e "arquivo.min.css?v=xxx" quebra essa extração (retorna "css?v=xxx"),
     * fazendo Renderer::addDefaultAttributes() omitir `rel="stylesheet"` do
     * <link> gerado — o navegador então ignora o arquivo inteiro. Ver
     * comentário completo em default_head_blocks.xml.
     *
     * 2026-07-03: bump obrigatório após fix de flex-basis/gap dos carrosséis
     * (5º card cortado em "Linhas em destaque" e demais awa-shelf--carousel).
     * O asset é servido com Cache-Control: public, max-age=31536000, immutable
     * — sem trocar a query, visitantes com o CSS antigo em cache do navegador
     * NUNCA revalidariam e continuariam vendo o bug por até 1 ano.
     */
    // 2026-07-27: bump P1A — badge de estoque #15803d->#166534 (WCAG AA 4.5:1).
    // 2026-07-31 H27b: bust obrigatório — .br stale ainda servia CTA min-height:56px.
    // 2026-08-02 H-pager-rwd: bust — pager padronizado mobile/tablet/desktop.
    // 2026-08-02 H-badge-clip: bust — hot-onsale max-width 50px → texto legível.
    // 2026-08-02 H-badge-clip2: bust + cascade-lock inline (vence CSS imutável em cache).
    public const VISUAL_FIXES_CSS_QUERY = '20260904-pdp-related-price-w6';

    /**
     * Cache-busting para awa-master-fix.js após tornar o init não bloqueante
     * para DOMContentLoaded em PLP/PDP.
     */
    public const MASTER_FIX_JS_QUERY = '20260815-hygiene-r1';

    public const HARDEN_TERMINAL_ID = 'awa-header-harden-terminal-20260616';

    public const LAYOUT_TERMINAL_ID = 'awa-header-layout-terminal-20260616';

    public const POLISH_TERMINAL_ID = 'awa-header-polish-terminal-20260616';

    public const DISTILL_TERMINAL_ID = 'awa-header-distill-terminal-20260616e';

    /** Blocos header legados substituídos pelo distill terminal (2026-06-16). */
    public const LEGACY_HEADER_TERMINAL_IDS = [
        'awa-header-mobile-grid-body-terminal',
        'awa-header-vis-fix-20260615',
        'awa-header-layout-sync-terminal-20260616',
        'awa-header-adapt-terminal-20260616',
        'awa-header-harden-terminal-20260616',
        'awa-header-layout-terminal-20260616',
        'awa-header-polish-terminal-20260616',
        'awa-header-distill-terminal-20260616',
        'awa-header-distill-terminal-20260616b',
    ];

    public const LEGACY_HEADER_TERMINAL_SCRIPT_IDS = [
        'awa-header-harden-script-20260616',
        'awa-header-a11y-polish-20260616',
    ];

    /** Blocos duplicados cobertos pelo distill — remover do HTML (head PHTML + home-light stale). */
    public const LEGACY_HEADER_DUPLICATE_IDS = [
        'awa-header-layout-sync-20260616',
        'awa-header-account-hierarchy-terminal-inline',
        'awa-align-grid-header-container-terminal',
        self::HEADER_ESSENTIAL_STYLE_ID,
        self::HOME_LIGHT_STYLE_ID,
    ];

    /** Critical global inline — duplica distill + mobile-grid-critical em PLP/busca. */
    public const HEADER_CRITICAL_GLOBAL_ID = 'awa-header-impeccable-critical-global';

    public const REFINE_CSS_FILE = 'awa-commerce-impeccable-refine.min.css';

    public const REFINE_QUERY = CascadeAssetVersionConsts::REFINE;

    /** PDP terminal — ui-simplify + distill-lock (round 6, 2026-06-10). */
    public const PDP_DISTILL_LOCK_QUERY = '?v=20260610-pdp';

    public const PDP_UI_SIMPLIFY_QUERY = '?v=20260904-cart-currentcolor';

    /** Grid/alinhamento terminal — última camada SSOT (2026-06-11). */
    public const ALIGN_GRID_CSS_FILE = 'awa-align-grid-terminal-2026-06-11.min.css';

    /** Grid terminal sem hotfixes visuais; conteúdo novo exige URL nova por ser immutable. */
    public const ALIGN_GRID_QUERY = CascadeAssetVersionConsts::ALIGN_GRID;

    /** Autoridade visual carregada imediatamente após o align-grid. */
    public const M2_VISUAL_SSOT_FILE = 'awa-m2-visual-ssot.min.css';

    public const M2_VISUAL_SSOT_QUERY = CascadeAssetVersionConsts::M2_VISUAL_SSOT;

    public const HOME_CRITICAL_STACK_FILE = 'awa-home-critical-stack-2026-06-11.min.css';

    public const HOME_CRITICAL_STACK_QUERY = CascadeAssetVersionConsts::HOME_CRITICAL_STACK;

    public const HOME_DEFERRED_STACK_FILE = 'awa-home-deferred-stack.min.css';

    public const HOME_DEFERRED_STACK_QUERY = CascadeAssetVersionConsts::HOME_DEFERRED_STACK;

    public const BODY_END_QUERY = '?v=20260622-carousel-pro-controls-v4';

    public const SUPER_GLOBAL_CSS_FILE = 'awa-super-global-20260611m.min.css';

    /** Cache-bust após reconciliar fonte→pub (Fase 2: bottom FAB 84px + tokens). */
    public const SUPER_GLOBAL_QUERY = CascadeAssetVersionConsts::SUPER_GLOBAL;

    public const HOME_DISTILL_LOCK_QUERY = '?v=20260610-pdp';

    /** Rotas de auth/light-shell que devem omitir o cascade lock global. */
    public const AUTH_FOCUS_ACTIONS = [
        'b2b_account_login',
        'b2b_register_index',
        'b2b_register_success',
        'b2b_account_forgotpassword',
        'b2b_account_claim',
        'customer_account_login',
        'customer_account_create',
    ];

    /** Rotas operacionais B2B logadas (dashboard/conta/pedidos/cotações). */
    public const B2B_ACCOUNT_FOCUS_ACTIONS = [
        'b2b_account_index',
        'b2b_account_dashboard',
        'b2b_account_orders',
        'b2b_credit_index',
        'b2b_shoppinglist_index',
        'b2b_shoppinglist_view',
        'b2b_reorder_history',
        'b2b_quote_index',
        'b2b_quote_history',
        'b2b_quote_view',
        'b2b_company_index',
        'b2b_erporders_index',
        'b2b_erporders_view',
        'b2b_cotacao_index',
        'b2b_cotacao_view',
        'b2b_quickorder_index',
        'b2b_subscription_index',
        'b2b_approval_index',
        'b2b_finance_index',
        'b2b_catalog_index',
        'sales_order_history',
        'sales_order_view',
        'erpintegration_customer_suggestedcart',
        'erpintegration_customer_suggestions',
    ];

    private const LEGACY_STYLE_IDS = [
        'awa-header-impeccable-terminal-fix',
        'awa-header-impeccable-cascade-lock-v11',
        'awa-header-impeccable-cascade-lock-v12',
        'awa-header-impeccable-cascade-lock-v13',
        'awa-header-impeccable-cascade-lock-v14',
        'awa-header-impeccable-cascade-lock-v15',
        'awa-header-impeccable-cascade-lock-v16',
        'awa-header-impeccable-cascade-lock-v17',
        'awa-header-impeccable-cascade-lock-v18',
        'awa-header-impeccable-cascade-lock-v19',
        'awa-header-impeccable-cascade-lock-v20',
    ];

    private const GUARD_SCRIPT_ID = 'awa-header-cascade-lock-guard';

    public static function headerA11yRules(): string
    {
        return 'html body#html-body .awa-site-header :is(a,button,[role="button"],[tabindex="0"]):focus:not(:focus-visible){outline:none!important}'
            . 'html body#html-body .awa-site-header :is(a,button,[role="button"],[tabindex="0"]):focus-visible{'
            . 'outline:2px solid var(--awa-primary,oklch(48% .14 20))!important;outline-offset:3px!important;border-radius:3px}'
            . 'html body#html-body .awa-site-header form#search_mini_form input:focus-visible{'
            . 'outline:2px solid var(--awa-primary,oklch(48% .14 20))!important;outline-offset:0!important}'
            . 'html body#html-body .awa-site-header :is(.awa-skip-link,.skip-link):focus-visible{'
            . 'position:fixed!important;inset-block-start:8px!important;inset-inline-start:8px!important;'
            . 'z-index:10000!important;padding:8px 12px!important;background:var(--awa-bg-subtle,oklch(97.5% .006 20))!important;'
            . 'color:var(--awa-text,oklch(22% .02 20))!important;clip:auto!important;width:auto!important;height:auto!important}'
            . 'html body#html-body:has(.awa-site-header){scroll-padding-block-start:var(--awa-header-scroll-offset,116px)}'
            . 'html body#html-body .awa-site-header #awa-main-content{scroll-margin-block-start:var(--awa-header-scroll-offset,116px)}'
            . '@media (max-width:991px){'
            . 'html body#html-body .awa-site-header .header-wrapper-sticky :is(.nav-toggle,.action.showcart,.awa-header-cart-link,button.our_categories){'
            . 'min-width:44px!important;min-height:44px!important;touch-action:manipulation!important}'
            . '}'
            . '@media (prefers-reduced-motion:reduce){'
            . 'html body#html-body .awa-site-header .header-wrapper-sticky,'
            . 'html body#html-body .awa-site-header .logo,'
            . 'html body#html-body .awa-site-header .logo img{transition:none!important}'
            . '}';
    }

    /**
     * SSOT do chrome interativo do header.
     *
     * É usado tanto no critical do head quanto no cascade-lock terminal. Os
     * fallbacks concretos são intencionais: no primeiro paint os tokens ainda
     * podem não ter sido resolvidos.
     */
    public static function headerChromeCanonicalRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body:not(.checkout-index-index)'
            . ':not(.onepagecheckout-index-index):not(.rokanthemes-onepagecheckout)'
            . ' .page-wrapper .awa-site-header';
        $cart = $root . ' .awa-header-minicart .minicart-wrapper'
            . ' :is(.action.showcart,a.showcart.header-mini-cart,.awa-minicart-trigger)';
        $search = $root . ' :is(.block-search,.awa-professional-search,form#search_mini_form)'
            . ' :is(button.action.search,.action.search)';
        $primary = 'var(--awa-primary,var(--awa-brand,#b73337))';
        $inverse = 'var(--awa-text-inverse,var(--awa-white,#fff))';

        return $cart . '{'
            . 'appearance:none!important;-webkit-appearance:none!important;'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'color:' . $primary . '!important;border:0!important;border-color:transparent!important;'
            . 'box-shadow:none!important;border-radius:0!important;opacity:1!important}'
            . $cart . ':is(:hover,:focus,:focus-visible,:active){'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'color:' . $primary . '!important;box-shadow:none!important}'
            . $cart . ' :is(.awa-minicart-icon,svg){'
            . 'stroke:' . $primary . '!important;color:' . $primary . '!important;fill:none!important}'
            . $cart . ' .awa-minicart-icon circle{fill:' . $primary . '!important;stroke:none!important}'
            . $cart . '::before,' . $cart . '::after{color:' . $primary . '!important}'
            . $search . '{'
            . 'appearance:none!important;-webkit-appearance:none!important;'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'color:' . $primary . '!important;border:0!important;box-shadow:none!important;border-radius:0!important}'
            . $search . ':is(:hover,:focus,:focus-visible,:active){'
            . 'background:transparent!important;background-color:transparent!important;color:' . $primary . '!important}'
            . $search . '::before,' . $search . '::after{color:' . $primary . '!important}'
            . '@media(min-width:992px){'
            . $root . ' .awa-header-account-prompt .awa-header-account-prompt__customer,'
            . $root . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__customer{'
            . 'display:none!important;visibility:hidden!important;opacity:0!important;height:0!important;'
            . 'max-height:0!important;min-height:0!important;overflow:hidden!important;'
            . 'pointer-events:none!important;position:absolute!important;width:0!important}'
            . $root . ' .awa-header-account-prompt[data-awa-auth-state="customer"] .awa-header-account-prompt__guest{'
            . 'display:none!important;visibility:hidden!important;height:0!important;overflow:hidden!important;'
            . 'pointer-events:none!important}'
            . $root . ' button.title-category-dropdown.our_categories,'
            . $root . ' .our_categories.title-category-dropdown{'
            . 'appearance:none!important;-webkit-appearance:none!important;'
            . 'background:' . $primary . '!important;background-color:' . $primary . '!important;'
            . 'border:0!important;border-color:transparent!important;box-shadow:none!important;'
            . 'color:' . $inverse . '!important;outline:0!important;position:relative!important;top:auto!important;'
            . 'inset:auto!important;transform:none!important}'
            . '}';
    }

    /**
     * Terminal header contrast/layout lock.
     *
     * Uses high specificity and !important because Magento critical inline CSS and
     * retired Ayo bundles still compete after static deployment on cached pages.
     */
    public static function headerBolderActionContrastLockRules(): string
    {
        $shell = 'html body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header';
        /* Lupa ghost (ícone primary) — SSOT = awa-header-impeccable-critical-global + FOUC r4.
         * CTA vermelho sólido aqui lutava com critical/FOUC e gerava flash. Minicart: ghost. */
        $actions = $shell . ' :is('
            . '.block-search button.action.search,'
            . '.block-search .action.search,'
            . 'form#search_mini_form button.action.search,'
            . 'form#search_mini_form button.action.search[disabled]'
            . ')';
        $promo = $shell . ' :is(#awa-b2b-promo-bar,.awa-b2b-promo-bar)';
        $searchForm = $shell . ' form#search_mini_form';
        $searchShell = $shell . ' .awa-header-search-col > .block-search';

        return $actions . '{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border-color:transparent!important;'
            . 'color:var(--awa-primary,var(--awa-red))!important;'
            . 'opacity:1!important}'
            . $actions . ':is(:disabled,[disabled]){'
            . 'color:var(--awa-primary,var(--awa-red))!important;'
            . 'opacity:1!important}'
            . $actions . '::before{'
            . 'color:currentColor!important;opacity:1!important}'
            . $actions . ' :is(svg,path){'
            . 'color:currentColor!important;stroke:currentColor!important;opacity:1!important}'
            // Desktop: o botão de busca traz SVG inline + lupa desenhada via ::before/::after (círculo+cabo).
            // Em ≥992px suprimimos a lupa-pseudo e mantemos apenas o SVG, evitando lupa duplicada/deslocada (home e catálogo).
            . '@media(min-width:992px){'
            . $searchForm . ' button.action.search::before,'
            . $searchForm . ' button.action.search::after,'
            . $searchForm . ' .actions button.action.search::before,'
            . $searchForm . ' .actions button.action.search::after{'
            . 'content:none!important;display:none!important;border:0!important;background:none!important}'
            . $searchForm . ' button.action.search svg{display:block!important;margin:0 auto!important}'
            . '}'
            . '@media(max-width:767px){'
            . $promo . '{'
            . 'align-items:center!important;background:var(--awa-primary,var(--awa-red))!important;'
            . 'background-color:var(--awa-primary,var(--awa-red))!important;box-sizing:border-box!important;'
            . 'color:var(--awa-text-inverse,var(--awa-white))!important;display:flex!important;'
            . 'flex:1 1 100%!important;height:44px!important;justify-content:center!important;line-height:44px!important;'
            . 'inline-size:auto!important;margin:0!important;max-height:44px!important;max-inline-size:100%!important;'
            . 'max-width:100%!important;min-height:44px!important;min-inline-size:0!important;'
            . 'overflow:hidden!important;padding:0 44px 0 12px!important;position:relative!important;width:auto!important}'
            . $promo . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout){'
            . 'align-items:center!important;background:transparent!important;box-sizing:border-box!important;'
            . 'display:flex!important;height:44px!important;justify-content:center!important;'
            . 'line-height:44px!important;max-height:44px!important;min-height:44px!important;'
            . 'overflow:hidden!important;padding:0!important;width:100%!important}'
            . $promo . ' :is(*,a,strong,span,p,.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta *){'
            . 'color:var(--awa-text-inverse,var(--awa-white))!important;opacity:1!important}'
            . $promo . ' :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__separator,.awa-b2b-promo-bar__cta){'
            . 'display:block!important;line-height:44px!important;margin:0!important;max-width:100%!important;'
            . 'min-width:0!important;overflow:hidden!important;padding:0 4px!important;text-overflow:ellipsis!important;'
            . 'white-space:nowrap!important}'
            /* §promo-close-44: WCAG 2.5.8 — Pixel QA mede o 1º button do DOM (= promo-close). */
            . $shell . ' :is(.awa-b2b-promo-close,#awa-b2b-promo-close){'
            . 'align-items:center!important;display:inline-flex!important;height:44px!important;inset-block-start:0!important;'
            . 'inset-inline-end:0!important;justify-content:center!important;margin:0!important;min-height:44px!important;'
            . 'min-width:44px!important;max-height:44px!important;max-width:44px!important;'
            . 'padding:0!important;position:absolute!important;width:44px!important;box-sizing:border-box!important}'
            . $searchShell . '{'
            . 'background:transparent!important;border:0!important;border-radius:0!important;'
            . 'box-shadow:none!important;box-sizing:border-box!important;outline:none!important;overflow:visible!important}'
            . $searchForm . '{'
            . 'align-items:stretch!important;background:var(--awa-bg,var(--awa-white))!important;'
            . 'border:1px solid color-mix(in srgb,var(--awa-primary,var(--awa-red)) 24%,var(--awa-border,#e5e7eb))!important;'
            . 'border-radius:var(--awa-radius-sm)!important;'
            . 'box-shadow:none!important;box-sizing:border-box!important;outline:none!important;'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;'
            . 'height:44px!important;max-height:44px!important;min-height:44px!important;overflow:hidden!important;padding:0!important}'
            . $searchForm . ' :is(.field.search,.field.search .control){'
            . 'display:block!important;height:40px!important;max-height:40px!important;min-height:40px!important;'
            . 'min-width:0!important;overflow:hidden!important;padding:0!important}'
            . $searchForm . ' input#search{'
            . 'background:transparent!important;border:0!important;box-shadow:none!important;font-size:16px!important;'
            . 'height:40px!important;line-height:40px!important;padding:0 12px!important;width:100%!important}'
            . $searchForm . ' .actions{'
            . 'display:flex!important;height:40px!important;max-height:40px!important;min-height:40px!important;'
            . 'width:44px!important}'
            . $searchForm . ' button.action.search{'
            . 'align-items:center!important;background:transparent!important;background-color:transparent!important;border:0!important;'
            . 'border-radius:0 var(--awa-radius-sm) var(--awa-radius-sm) 0!important;'
            . 'color:var(--awa-primary,var(--awa-red))!important;display:flex!important;height:40px!important;'
            . 'justify-content:center!important;max-height:40px!important;min-height:40px!important;opacity:1!important;width:44px!important}'
            . 'html body#html-body:has(nav.fixed-bottom.hidden-sm.hidden-md.hidden-lg) .page-wrapper{'
            . 'padding-bottom:calc(88px + env(safe-area-inset-bottom,0px))!important}'
            . '}';
    }

    public static function minicartInteractionRules(): string
    {
        return 'html body#html-body .page-wrapper .awa-site-header '
            . '.minicart-wrapper:not(.active):not(.is-open):not(.show) .block-minicart:not(._active){'
            . 'display:none!important;visibility:hidden!important;opacity:0!important;'
            . 'pointer-events:none!important;width:0!important;min-width:0!important;max-width:0!important;'
            . 'height:0!important;min-height:0!important;max-height:0!important;overflow:hidden!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-header-minicart--expanded .block-minicart,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.minicart-wrapper:is(.active,.is-open,.show) .block-minicart,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.minicart-wrapper .block-minicart._active,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '[data-role=dropdownDialog].block-minicart._active{'
            . 'display:flex!important;visibility:visible!important;opacity:1!important;'
            . 'pointer-events:auto!important;position:absolute!important;z-index:100130!important;'
            . 'box-sizing:border-box!important;background:var(--awa-bg-surface,var(--awa-bg))!important;'
            . 'border:0!important;border-top-width:0!important;border-radius:var(--awa-radius-lg)!important;'
            . 'box-shadow:var(--awa-shadow-xl)!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .block-minicart '
            . ':is(.action.checkout,.action.primary.checkout,.action.viewcart,#top-cart-btn-checkout){'
            . 'min-height:44px!important;border-radius:var(--awa-radius-md,8px)!important;text-transform:none!important}'
            . '@media(max-width:767px){'
            . '/* Mobile minicart: escapa o containing block de 44px para evitar painel off-canvas. */'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-header-minicart--expanded .minicart-wrapper,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.minicart-wrapper:is(.active,.is-open,.show){'
            . 'contain:none!important;overflow:visible!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-header-minicart--expanded .block-minicart,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.minicart-wrapper:is(.active,.is-open,.show) .block-minicart,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.minicart-wrapper .block-minicart._active,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '[data-role=dropdownDialog].block-minicart._active{'
            . 'position:fixed!important;top:calc(var(--awa-header-main-row-h,96px) + 8px)!important;'
            . 'right:var(--awa-header-shell-pad,16px)!important;left:var(--awa-header-shell-pad,16px)!important;'
            . 'width:calc(100vw - (var(--awa-header-shell-pad,16px) * 2))!important;'
            . 'min-width:0!important;max-width:none!important;transform:none!important;'
            . 'max-height:min(78vh,720px)!important;overflow:visible!important}'
            . '}'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-header-minicart '
            . '.minicart-wrapper .action.showcart,'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-header-minicart '
            . '.minicart-wrapper a.showcart.header-mini-cart{'
            . 'pointer-events:auto!important;cursor:pointer!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper .awa-site-header .awa-header-minicart .minicart-wrapper '
            . ':is(.action.showcart,a.showcart.header-mini-cart){'
            . 'float:none!important;position:absolute!important;inset:auto!important;left:auto!important;right:0!important;'
            . 'top:0!important;bottom:auto!important;transform:none!important;place-self:center!important;'
            . 'justify-self:auto!important;margin:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper .awa-site-header .awa-header-minicart :is(.minicart-wrapper,.mini-carts){'
            . 'position:relative!important;overflow:visible!important}';
    }

    public static function promoBarRules(): string
    {
        /* Spec 5×#html-body — vence awa-plp-terminal-lock-inline (padding:8px no bag PLP). */
        $root = 'html body#html-body#html-body#html-body#html-body#html-body';
        $wrap = $root . ' .page-wrapper';

        return $wrap . ' #header :is('
            . '#awa-b2b-promo-bar,'
            . '.top-header.awa-b2b-promo-bar,'
            . '.awa-b2b-promo-bar[data-awa-header-utility]'
            . '),'
            . $wrap . ' :is('
            . '#awa-b2b-promo-bar,'
            . '#header.header-container[data-awa-header-shell="true"] .top-header.awa-b2b-promo-bar,'
            . '#header.header-container[data-awa-header-shell="true"] .awa-b2b-promo-bar[data-awa-header-utility],'
            . '#header .top-header.awa-b2b-promo-bar,'
            . '#header .awa-b2b-promo-bar[data-awa-header-utility],'
            . '.awa-site-header .top-header.awa-b2b-promo-bar,'
            . '.awa-site-header .awa-b2b-promo-bar[data-awa-header-utility]'
            . '){'
            . 'background:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'background-color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'background-image:none!important;'
            . 'border:0!important;box-shadow:none!important;'
            /* r41 CLS: shell promo = 44px (alinha critical + r40c; evita shift 32→44) */
            . 'box-sizing:border-box!important;min-height:44px!important;max-height:44px!important;'
            . 'height:44px!important;'
            . 'padding:0!important;'
            . 'padding-block:0!important;padding-inline:0!important;'
            . 'overflow:hidden!important;position:relative!important;'
            . 'display:flex!important;align-items:center!important;'
            . 'flex:1 1 100%!important;min-width:0!important;width:100%!important;max-width:100%!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . $wrap . ' .awa-site-header :is('
            . '#header.header-container[data-awa-header-shell="true"],'
            . '#header.header-container[data-awa-header-shell="true"] > .header-content'
            . '){height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;padding-block:0!important;margin:0!important;'
            . 'border:0!important;border-bottom:0!important;border-block-end:0!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'box-shadow:none!important;overflow:hidden!important;box-sizing:border-box!important}'
            . $wrap . ' .awa-site-header > #header.header-container[data-awa-header-shell="true"]{'
            . 'background:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'background-color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'border:0!important;border-bottom:0!important;height:44px!important;'
            . 'min-height:44px!important;max-height:44px!important}'
            . $wrap . ' :is('
            . '.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout'
            . '){width:100%!important;max-width:var(--awa-container-catalog,var(--awa-container-max,1280px))!important;'
            . 'margin-inline:auto!important;justify-content:center!important;'
            . 'align-items:center!important;display:flex!important;'
            . 'border:0!important;box-shadow:none!important;background:transparent!important;'
            . 'border-radius:0!important;overflow:hidden!important;'
            . 'padding:0 52px 0 24px!important;padding-block:0!important;padding-inline:24px 52px!important;box-sizing:border-box!important;'
            . 'min-height:44px!important;max-height:44px!important;height:44px!important}'
            . $wrap . ' :is(#awa-b2b-promo-bar,.awa-b2b-promo-bar) .awa-b2b-promo-close,'
            . $wrap . ' button.awa-b2b-promo-close{'
            . 'position:absolute!important;inset-block:0!important;inset-inline-end:0!important;'
            . 'transform:none!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'inline-size:44px!important;block-size:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'max-width:44px!important;max-height:44px!important;width:44px!important;height:44px!important;'
            . 'box-sizing:border-box!important;padding:0!important;margin:0!important;border-radius:0!important;'
            . 'border:0!important;'
            . 'border-inline-start:1px solid color-mix(in srgb,var(--awa-text-inverse,oklch(99% .002 20)) 28%,transparent)!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important;opacity:.92!important}'
            . $wrap . ' :is('
            . '.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__tail'
            . '){border:0!important;box-shadow:none!important;background:transparent!important;'
            . 'border-radius:0!important;overflow:visible!important;padding:0!important;'
            . 'height:auto!important;min-height:0!important;max-height:none!important;'
            . 'line-height:1.25!important;font-size:13px!important}'
            . $wrap . ' .awa-site-header .awa-b2b-promo-bar__cta,'
            . $wrap . ' #header .awa-b2b-promo-bar__cta{'
            . 'display:inline!important;padding:0!important;border:0!important;border-radius:0!important;'
            . 'background:transparent!important;height:auto!important;min-height:0!important;max-height:none!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}';
    }

    public static function headerPolishRules(): string
    {
        return 'html body#html-body .page-wrapper .awa-site-header{'
            . '--awa-header-polish-ease:cubic-bezier(.22,1,.36,1);'
            . '--awa-header-polish-hover:oklch(42% .13 20);'
            . '--awa-header-polish-ring:color-mix(in srgb,var(--awa-primary,oklch(48% .14 20)) 26%,transparent)}'
            . 'html body#html-body .page-wrapper .awa-site-header :is('
            . '.top-header.awa-b2b-promo-bar,.awa-b2b-promo-bar[data-awa-header-utility]'
            . ') :is(a,span,strong,p,button){'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . 'html body#html-body .page-wrapper .awa-site-header :is('
            . '.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.awa-nav-bar'
            . ') :is(.awa-nav-quick-links__link,.custommenu.main-nav a,.navigation.custommenu a){'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important;'
            . 'font-weight:650!important;text-decoration:none!important}'
            . 'html body#html-body .page-wrapper .awa-site-header :is('
            . '.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.awa-nav-bar'
            . ') :is(.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]){'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important;'
            . 'font-weight:600!important}'
            . 'html body#html-body .page-wrapper .awa-site-header :is('
            . 'form#search_mini_form,.action.search,.action.showcart,.showcart.header-mini-cart,'
            . '.awa-minicart-trigger,.awa-nav-quick-links__link,'
            . '.custommenu.main-nav a,.navigation.custommenu a,'
            . '.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]'
            . '){transition:background-color .18s var(--awa-header-polish-ease),'
            . 'border-color .18s var(--awa-header-polish-ease),'
            . 'box-shadow .18s var(--awa-header-polish-ease),'
            . 'color .18s var(--awa-header-polish-ease),'
            . 'transform .12s var(--awa-header-polish-ease)!important}'
            . 'html body#html-body .page-wrapper .awa-site-header form#search_mini_form:hover{'
            . 'border-color:color-mix(in srgb,var(--awa-primary,oklch(48% .14 20)) 38%,var(--awa-border,oklch(90% .008 20)))!important}'
            . 'html body#html-body .page-wrapper .awa-site-header form#search_mini_form:focus-within{'
            . 'border-color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'box-shadow:0 0 0 3px var(--awa-header-polish-ring)!important}'
            . 'html body#html-body .page-wrapper .awa-site-header form#search_mini_form input#search::placeholder{'
            . 'color:oklch(50% .018 20)!important;opacity:1!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .action.search:hover{'
            . 'background:var(--awa-header-polish-hover)!important;'
            . 'box-shadow:0 6px 14px rgb(15 23 42/14%)!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .action.search:active{'
            . 'transform:translateY(1px)!important;box-shadow:none!important}'
            . 'html body#html-body .page-wrapper .awa-site-header :is('
            . '.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger'
            . ') .counter.qty{'
            . 'position:absolute!important;inset-block-start:-5px!important;inset-inline-end:-6px!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'min-width:18px!important;height:18px!important;padding:0 5px!important;'
            . 'border-radius:999px!important;background:oklch(99% .002 20)!important;'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'font-size:11px!important;font-weight:800!important;line-height:18px!important;'
            . 'box-shadow:0 0 0 2px var(--awa-primary,oklch(48% .14 20))!important}'
            . 'html body#html-body .page-wrapper .awa-site-header :is('
            . '.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger'
            . ') .counter.qty.empty{display:none!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .counter.qty .counter-number{'
            . 'display:inline!important;position:static!important;width:auto!important;height:auto!important;'
            . 'clip:auto!important;clip-path:none!important;overflow:visible!important;white-space:nowrap!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .counter.qty .counter-label{'
            . 'position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;'
            . 'clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;white-space:nowrap!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header :is('
            . '.awa-nav-quick-links__link,.custommenu.main-nav a,.navigation.custommenu a'
            . '):hover,'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header :is('
            . '.awa-nav-quick-links__link,.custommenu.main-nav a,.navigation.custommenu a'
            . '):focus-visible{'
            . 'background:color-mix(in srgb,var(--awa-text-inverse,oklch(99% .002 20)) 14%,transparent)!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important;'
            . 'border-radius:8px!important;text-decoration:none!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header :is('
            . '.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]'
            . '):hover,'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header :is('
            . '.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]'
            . '):focus-visible{'
            . 'background:oklch(35% .11 20)!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . '@media (min-width:992px){'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index):not(.b2b-account-dashboard) '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt .awa-header-account-prompt__line1{'
            . 'color:oklch(38% .022 20)!important;font-weight:500!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index):not(.b2b-account-dashboard) '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt .awa-header-account-prompt__separator{'
            . 'color:oklch(52% .018 20)!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index):not(.b2b-account-dashboard) '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt '
            . '.awa-header-account-prompt__link:not(.awa-header-account-prompt__link--register){'
            . 'color:oklch(28% .024 20)!important;font-weight:650!important}'
            . '}'
            . '@media (prefers-reduced-motion:reduce){'
            . 'html body#html-body .page-wrapper .awa-site-header :is('
            . 'form#search_mini_form,.action.search,.action.showcart,.showcart.header-mini-cart,'
            . '.awa-minicart-trigger,.awa-nav-quick-links__link,'
            . '.custommenu.main-nav a,.navigation.custommenu a,'
            . '.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]'
            . '){transition:none!important;transform:none!important}'
            . '}';
    }

    public static function impeccableSurfaceRules(): string
    {
        return 'html body#html-body .page-wrapper :is('
            . '.navigation.verticalmenu .submenu.navigation__submenu,'
            . '.navigation.verticalmenu div[id^="submenu-menu-"],'
            . '.awa-vmf-portal.navigation__submenu'
            . '){border:0!important;box-shadow:0 4px 12px rgb(15 23 42/10%)!important}'
            . 'html body#html-body .page-wrapper :is(#search_autocomplete,.search-autocomplete,.searchsuite-autocomplete,.mst-searchautocomplete__autocomplete){'
            . 'border:0!important;box-shadow:0 4px 16px rgb(15 23 42/12%)!important}'
            /* Mobile nav lives outside .page-wrapper — no ancestor requirement */
            . 'html body#html-body :is(nav.fixed-bottom.hidden-sm,nav.fixed-bottom,.fixed-bottom,.awa-mobile-bottom-nav){'
            . 'border:0!important;border-top:0!important;'
            . 'box-shadow:0 -2px 8px rgb(15 23 42/8%)!important}'
            . '@media(max-width:991px){'
            . 'html:has(body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view)){'
            . 'height:100%!important;min-height:100%!important}'
            . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view){'
            . 'height:100%!important;min-height:100%!important}'
            . '}'
            /* H24A: bottom nav só em mobile real (≤767). Em tablet o header já cobre navegação. */
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . ':is(nav.fixed-bottom.hidden-sm.hidden-md.hidden-lg,nav.fixed-bottom.mobile-bottom-nav,nav.fixed-bottom.awa-mobile-bottom-nav){'
            . 'position:fixed!important;inset:auto 0 0 0!important;top:auto!important;right:0!important;bottom:0!important;left:0!important;'
            . 'display:block!important;visibility:visible!important;opacity:1!important;'
            . 'width:100%!important;max-width:100%!important;height:calc(64px + env(safe-area-inset-bottom,0px))!important;'
            . 'min-height:64px!important;max-height:calc(72px + env(safe-area-inset-bottom,0px))!important;'
            . 'margin:0!important;padding:0!important;background:var(--awa-bg,#fff)!important;'
            . 'z-index:998!important;transform:none!important;translate:none!important}'
            . 'html body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . ':is(nav.fixed-bottom.hidden-sm.hidden-md.hidden-lg,nav.fixed-bottom.mobile-bottom-nav,nav.fixed-bottom.awa-mobile-bottom-nav) .mobile-bottom-link{'
            . 'align-items:stretch!important;display:flex!important;gap:0!important;height:100%!important;'
            . 'justify-content:space-between!important;margin:0!important;padding:4px 10px calc(4px + env(safe-area-inset-bottom,0px))!important}'
            . '}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body :is(nav.fixed-bottom.hidden-sm.hidden-md.hidden-lg,nav.fixed-bottom.mobile-bottom-nav,nav.fixed-bottom.awa-mobile-bottom-nav,.awa-mobile-bottom-nav){'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important}'
            . '}'
            . 'html body#html-body nav.fixed-bottom .mobile-bottom-link>li>a,'
            . 'html body#html-body nav.fixed-bottom .mobile-bottom-link>li>button{'
            . 'display:inline-flex!important;flex-direction:column!important;align-items:center!important;'
            . 'justify-content:center!important;gap:2px!important;line-height:1!important}'
            . 'html body#html-body nav.fixed-bottom .mobile-bottom-link>li>a :is(.icon,span.icon),'
            . 'html body#html-body nav.fixed-bottom .mobile-bottom-link>li>button :is(.icon,span.icon){'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'line-height:1!important;margin:0!important}'
            . 'html body#html-body #awa-cookie-banner,'
            . 'html body#html-body #awa-cookie-banner.awa-cookie-banner--visible{'
            . 'border:0!important;box-shadow:0 -4px 12px rgb(15 23 42/10%)!important}'
            . 'html body#html-body .page-wrapper #b2b-login-modal .b2b-login-modal{'
            . 'padding:0!important;border:1px solid var(--awa-border,#e5e5e5)!important;'
            . 'box-shadow:inset 0 3px 0 0 var(--awa-primary),0 20px 32px rgb(15 23 42/12%)!important;'
            . 'overflow:auto!important}'
            . 'html body#html-body .page-wrapper :is(#header .header-content,.header-container .header-content,.header-content){'
            . 'border:0!important;box-shadow:none!important;background:var(--awa-bg,oklch(99% .002 20))!important}'
            . 'html body#html-body .page-wrapper .awa-header-categories.menu_left_home1,'
            . 'html body#html-body .page-wrapper .awa-header-categories.menu_left_home1 '
            . ':is(.navigation.verticalmenu,.our_categories.title-category-dropdown){'
            . 'border:0!important;box-shadow:none!important;background:transparent!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .content-top-home .awa-section-header__eyebrow{'
            . 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'overflow:hidden!important;margin:0!important;padding:0!important;border:0!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . ':is(.awa-category-carousel__item,.awa-category-carousel__item--compact) .awa-category-carousel__icon,'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-category-carousel__item:hover .awa-category-carousel__icon{'
            . 'transition:transform .24s cubic-bezier(.22,1,.36,1)!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-category-carousel__item{'
            . 'transition:border-color .2s cubic-bezier(.22,1,.36,1),'
            . 'background-color .2s cubic-bezier(.22,1,.36,1),'
            . 'box-shadow .2s cubic-bezier(.22,1,.36,1)!important}'
            . 'html body#html-body .page-wrapper .awa-hero-trust-strip__text{'
            . 'font-size:max(12px,.75rem)!important;line-height:1.4!important}'
            . '@media (min-width:576px) and (max-width:991px){'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;'
            . 'gap:20px!important;align-items:stretch!important;margin:16px 0 0!important;padding:0!important;'
            . 'list-style:none!important;overflow:visible!important;flex-flow:unset!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip__item{display:flex!important;flex-direction:column!important;'
            . 'align-items:center!important;gap:16px!important;flex:1 1 auto!important;min-height:48px!important;'
            . 'max-width:none!important;padding:24px 16px!important;text-align:center!important;list-style:none!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . 'svg.awa-hero-trust-strip__icon,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-trust-strip__icon{display:block!important;flex:0 0 48px!important;width:48px!important;'
            . 'min-width:48px!important;max-width:48px!important;height:48px!important;min-height:48px!important;'
            . 'max-height:48px!important}}'
            . '@media (min-width:992px){'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;'
            . 'gap:24px!important;align-items:stretch!important;margin:16px 0 0!important;padding:0!important;'
            . 'list-style:none!important;overflow:visible!important;flex-flow:unset!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip__item{display:flex!important;flex-direction:column!important;'
            . 'align-items:center!important;gap:16px!important;flex:1 1 auto!important;min-height:48px!important;'
            . 'max-width:none!important;padding:24px 16px!important;text-align:center!important;list-style:none!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . 'svg.awa-hero-trust-strip__icon,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-trust-strip__icon{display:block!important;flex:0 0 48px!important;width:48px!important;'
            . 'min-width:48px!important;max-width:48px!important;height:48px!important;min-height:48px!important;'
            . 'max-height:48px!important}}'
            . 'html body#html-body .page-wrapper .page_footer :is(h2,h3).awa-newsletter-title{'
            . 'color:var(--awa-ink,oklch(22% .02 20))!important}'
            /* BUG-CONTRAST-FOOTER-TRUST (2026-07-06): --awa-ink resolve para
             * --awa-oklch-ink = oklch(22% .012 27), token de "tinta escura para
             * fundo claro". Aplicado (por engano, copiado de outra regra) ao
             * titulo em negrito do selo de confianca, que fica sobre o fundo
             * VERMELHO ESCURO do rodape (.page_footer). Contraste medido: 2.96:1,
             * abaixo do minimo WCAG AA (4.5:1) — texto quase ilegivel. Trocado
             * para var(--awa-white,#fff), mesmo tom que o span irmao ja usa com
             * sucesso (5.19:1). Esta regra e injetada inline (maior prioridade
             * de cascata) — corrigir apenas nos bundles CSS externos nao surtia
             * efeito. */
            . 'html body#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-copy strong,'
            . 'html body#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-item .awa-footer-trust-copy strong{'
            . 'color:var(--awa-white,#fff)!important}'
            . 'html body#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-copy span{'
            . 'color:color-mix(in srgb,#fff 88%,transparent)!important}'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento p.awa-footer-atendimento__label,'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento p.awa-footer-atendimento__label--social,'
            . 'html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__label,'
            . 'html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__label--social{'
            . 'color:oklch(45% .02 20)!important}'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store{'
            . 'background:transparent!important;border:0!important;'
            . 'padding:8px 0!important;border-radius:0!important}'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store '
            . 'p.awa-footer-atendimento__store-name,'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store '
            . 'p.awa-footer-atendimento__store-address,'
            . 'html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__store-name,'
            . 'html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__store-address,'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento__store-name,'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento__store-address{'
            . 'color:oklch(22% .01 20)!important}'
            . '@media (min-width:768px){html body#html-body:not(.cms-index-index):not(.cms-home):not(.cms-homepage_ayo_home5) .page-wrapper .header-wrapper-sticky{padding-block:0!important;padding:0!important}}'
            . 'html body#html-body:not(.cms-index-index):not(.cms-home):not(.cms-homepage_ayo_home5) .page-wrapper .header_main.awa-main-header-inner-wrap{padding-inline:0!important}'
            . 'html body#html-body .page-wrapper .awa-b2b-min-order-progress--minicart{padding-top:8px!important}'
            . 'html body#html-body .page-wrapper .header-control.header-nav.awa-nav-bar{'
            . 'overflow:visible!important}'
            . 'html body#html-body .page-wrapper .header-control.header-nav .container{'
            . 'overflow:visible!important}'
            . 'html body#html-body .page-wrapper .awa-nav-bar__inner{'
            . 'overflow:visible!important}'
            . 'html body#html-body .page-wrapper .awa-site-header form#search_mini_form.minisearch,'
            . 'html body#html-body .page-wrapper .awa-site-header form#search_mini_form.minisearch .control,'
            . 'html body#html-body .page-wrapper .awa-site-header a.action.showcart.header-mini-cart,'
            . 'html body#html-body .page-wrapper .awa-site-header .minicart-wrapper .action.showcart{'
            . 'overflow:visible!important;position:relative!important}'
            . 'html body#html-body .page-wrapper .custommenu.main-nav .ui-menu-item.navigation__item>a,'
            . 'html body#html-body .page-wrapper .navigation.custommenu .ui-menu-item.navigation__item>a{'
            . 'padding-block:10px!important}'
            . 'html body#html-body .page-wrapper .subchildmenu.navigation__inner-list{padding-right:12px!important}'
            . 'html body#html-body .page-wrapper .subchildmenu.navigation__inner-list>li.ui-menu-item.navigation{'
            . 'padding:8px 12px!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-category-carousel__viewport{overflow-x:hidden!important;overflow-y:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.product-thumb{padding-block:8px 4px!important}';
    }

    public static function homeImpeccablePolishRules(): string
    {
        return 'html body#html-body :is('
            . '.awa-sr-only,.sr-only,.visually-hidden,label.visually-hidden,.awa-carousel-live.visually-hidden'
            . '):not(.awa-skip-link){'
            . 'position:fixed!important;top:0!important;left:0!important;width:1px!important;height:1px!important;'
            . 'min-width:1px!important;min-height:1px!important;max-width:1px!important;max-height:1px!important;'
            . 'margin:0!important;padding:0!important;border:0!important;overflow:hidden!important;'
            . 'clip:rect(0 0 0 0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;'
            . 'font-size:0!important;line-height:0!important;word-break:break-all!important;contain:strict!important}'
            . 'html body#html-body :is(.awa-skip-link,a.action.skip):not(:focus):not(:focus-visible){'
            . 'position:fixed!important;top:0!important;left:0!important;width:1px!important;height:1px!important;'
            . 'min-width:1px!important;min-height:1px!important;max-width:1px!important;max-height:1px!important;'
            . 'margin:0!important;padding:0!important;border:0!important;overflow:hidden!important;'
            . 'clip:rect(0 0 0 0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;'
            . 'font-size:0!important;line-height:0!important;word-break:break-all!important}'
            . 'html body#html-body#html-body :is(.awa-skip-link,a.action.skip,a.action.skip.content,'
            . 'a.action.skip.nav,a.skip-to-main-content):is(:focus,:focus-visible){'
            . 'position:fixed!important;top:8px!important;left:8px!important;inset:auto!important;'
            . 'width:auto!important;height:auto!important;min-width:44px!important;min-height:44px!important;'
            . 'max-width:none!important;max-height:none!important;margin:0!important;padding:10px 14px!important;'
            . 'overflow:visible!important;clip:auto!important;clip-path:none!important;white-space:normal!important;'
            . 'font-size:14px!important;line-height:1.3!important;word-break:normal!important;'
            . 'display:inline-flex!important;align-items:center!important;z-index:100000!important;'
            . 'background:#b73337!important;color:#fff!important;border-radius:8px!important;font-weight:600!important;'
            . 'text-decoration:none!important;pointer-events:auto!important;opacity:1!important;visibility:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5),'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper{'
            . 'font-family:var(--awa-font-family,"Source Sans 3",system-ui,sans-serif)!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is('
            . 'h1,h2,h3,h4,h5,h6,.title-catthum,.awa-section-header__title,.rokan-product-heading h2,.block-title strong,'
            . '.awa-section-header h2,.awa-shelf__header h2,.awa-carousel-section .section-title h2,'
            . '.awa-category-carousel__header h2'
            . '){font-family:var(--awa-font-heading,"Rubik",system-ui,sans-serif)!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is('
            . '.navigation.verticalmenu,.navigation.verticalmenu a,.navigation.verticalmenu .our_categories,'
            . '.awa-site-header,.awa-site-header .header,.header-control.header-nav,#footer,.footer-bottom,'
            . '.awa-footer-newsletter,.top-home-content,.awa-benefits-bar,.page_footer,.awa-footer-trust-bar'
            . '){font-family:var(--awa-font-family,"Source Sans 3",system-ui,sans-serif)!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){margin:0!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.content-top-home{padding-inline:0!important;box-sizing:border-box!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . ':is(.home-main,.awa-hero-b2b-cta){'
            . 'max-width:min(100%,1280px)!important;margin-inline:auto!important;'
            . 'padding-inline:clamp(16px,3vw,24px)!important;box-sizing:border-box!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.content-top-home .ayo-home5-wrapper--template-driven>.awa-carousel-section:not(.top-home-content--above-fold){'
            . 'padding-inline:0!important;box-sizing:border-box!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . ':is(.awa-footer-business-contact__copy,.awa-newsletter-desc,#b2b-login-desc,.awa-hero-b2b-cta__lead){'
            . 'max-width:min(72ch,100%)!important;overflow-wrap:anywhere!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.footer-bottom :is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer){'
            . 'max-width:min(72ch,100%)!important;width:100%!important;margin-inline:auto!important;'
            . 'overflow-wrap:anywhere!important;text-align:center!important;line-height:1.4!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.page_footer :is(.footer-bottom,.footer-bottom-inner){padding-block:12px!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .content-top-home '
            . '.ayo-home5-wrapper--template-driven>.awa-carousel-section{'
            . 'overflow:visible!important;contain:layout!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . ':is(.awa-carousel-section,.top-home-content.awa-home-section){overflow:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header :is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar){'
            . 'padding-block:0!important;padding-inline:0!important;box-sizing:border-box!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header form#search_mini_form,'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header form#search_mini_form .field.search,'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header form#search_mini_form .control{overflow:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . ':is(.page-footer,.page_footer) .footer-bottom,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .footer-bottom{max-width:min(100%,1280px)!important;margin-inline:auto!important;'
            . 'padding:12px clamp(16px,3vw,24px)!important;box-sizing:border-box!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . ':is(.page-footer,.page_footer) :is(.footer-container,#footer){'
            . 'padding-block:16px!important;box-sizing:border-box!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
            . '--awa-font-family:"Source Sans 3",system-ui,sans-serif!important;'
            . '--awa-font-heading:"Rubik",system-ui,sans-serif!important;'
            . '--awa-font-body:"Source Sans 3",system-ui,sans-serif!important;'
            . '--awa-font-display:"Rubik",system-ui,sans-serif!important;'
            . '--vm-font:"Source Sans 3",system-ui,sans-serif!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is('
            . '.awa-search-helper-copy,.awa-category-carousel__subtitle,.awa-section-header__subtitle,'
            . '.awa-hero-b2b-cta__lead,.product-thumb .hot-onsale .onsale .sale-text){'
            . 'font-size:max(12px,.75rem)!important;line-height:1.45!important}'
            . 'html body#html-body .page-wrapper :is(.page-footer,.page_footer) :is('
            . '.velaFooterTitle,h4.velaFooterTitle,[id^="awa-footer-title-"]){'
            . 'color:oklch(22% .01 20)!important;background:transparent!important}'
            . 'html body#html-body .page-wrapper :is(.page-footer,.page_footer) :is('
            . '.vela-content,.vela-content.velaFooterMenu,.footer-container .col-lg-3,.footer-container .col-md-6){'
            . 'background:transparent!important;background-color:transparent!important}'
            . 'html body#html-body .page-wrapper :is('
            . '.awa-footer-atendimento__store,.awa-footer-atendimento__store-name,'
            . '.awa-footer-atendimento__store-address,p.awa-footer-atendimento__store-name,'
            . 'p.awa-footer-atendimento__store-address){color:oklch(22% .01 20)!important}'
            . 'html body#html-body .page-wrapper .awa-footer-atendimento__store{'
            . 'background:transparent!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is('
            . '.awa-carousel-section,.top-home-content.awa-home-section,.ayo-home5-wrapper,.awa-shelf--carousel,'
            . '.item-product.awa-carousel-card-slot,.content-item-product.awa-product-card,'
            . '.rokan-bestseller.awa-shelf,.rokan-newproduct.awa-shelf){overflow:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is('
            . '.awa-carousel__viewport,.awa-shelf-swiper,.swiper:not(.awa-hero-swiper),.swiper-wrapper,'
            . '.product-thumb,.wrapper_slider .swiper,.wrapper_slider .owl){overflow:hidden!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is('
            . '.wrapper_slider,.banner-slider,.banner-slider2){overflow:visible!important}'
            . 'html body#html-body .page-wrapper .awa-owl-progress{overflow:visible!important}'
            . 'html body#html-body .page-wrapper .awa-owl-progress__bar{'
            . 'width:100%!important;transform:scaleX(var(--awa-progress,.2))!important;'
            . 'transform-origin:left center!important;'
            . 'transition:transform .22s cubic-bezier(.22,1,.36,1)!important}'
            . 'html body#html-body .page-wrapper .awa-site-header form#search_mini_form .field.search{'
            . 'padding:4px 8px!important;box-sizing:border-box!important}'
            /* §109 shelf-carousel.css owns tier rhythm (featured/standard/compact); padding-block-start here caused 36/48 asymmetry on desktop */
            . 'html body#html-body .page-wrapper #b2b-login-modal.b2b-login-modal-overlay{'
            . 'padding:16px!important;box-sizing:border-box!important}'
            . 'html body#html-body .page-wrapper #b2b-login-modal .b2b-login-modal{'
            . 'padding:0!important;box-sizing:border-box!important}'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu ul.togge-menu.list-category-dropdown'
            . ':is(.vmm-open,.menu-open,[aria-hidden="false"]){overflow:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper{'
            . 'overflow-x:visible!important;overflow-y:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . ':is(#maincontent,.columns,.column.main){overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
            . 'html body#html-body .page-wrapper .product-thumb .hot-onsale .onsale{padding:4px 8px!important}'
            . 'html body#html-body .page-wrapper .product-thumb .hot-onsale .onsale .sale-text{'
            . 'padding:2px 4px!important;line-height:1.35!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.item-product.awa-carousel-card-slot,'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.content-item-product.awa-product-card{overflow:visible!important}'
            . '@media (max-width:767px){'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;'
            . 'gap:8px!important;align-items:stretch!important;margin:16px 0 0!important;padding:0!important;'
            . 'list-style:none!important;overflow:visible!important;flex-flow:unset!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip__item{display:flex!important;flex-direction:column!important;'
            . 'align-items:center!important;gap:8px!important;flex:1 1 auto!important;min-height:44px!important;'
            . 'max-width:none!important;padding:12px 8px!important;text-align:center!important;list-style:none!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip__item:last-child:nth-child(odd){'
            . 'grid-column:1/-1!important;max-width:calc(50% - 4px)!important;margin-inline:auto!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . 'svg.awa-hero-trust-strip__icon,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-trust-strip__icon{display:block!important;flex:0 0 40px!important;width:40px!important;'
            . 'min-width:40px!important;max-width:40px!important;height:40px!important;min-height:40px!important;'
            . 'max-height:40px!important}}'
            . '@media (min-width:576px){'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;'
            . 'gap:16px!important;align-items:stretch!important;margin:16px 0 0!important;padding:0!important;'
            . 'list-style:none!important;overflow:visible!important;flex-flow:unset!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip__item{display:flex!important;flex-direction:column!important;'
            . 'align-items:center!important;gap:12px!important;flex:1 1 auto!important;min-height:48px!important;'
            . 'max-width:none!important;padding:20px 12px!important;text-align:center!important;list-style:none!important;'
            . 'border:1px solid var(--awa-border,#e5e5e5)!important;border-radius:8px!important;'
            . 'background:var(--awa-bg,#fff)!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip__item:last-child:nth-child(odd){'
            . 'grid-column:auto!important;max-width:none!important;margin-inline:0!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . 'svg.awa-hero-trust-strip__icon,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-trust-strip__icon{display:block!important;flex:0 0 40px!important;width:40px!important;'
            . 'min-width:40px!important;max-width:40px!important;height:40px!important;min-height:40px!important;'
            . 'max-height:40px!important}}'
            . '@media (min-width:768px){'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip{gap:20px!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip__item{padding:24px 16px!important;gap:16px!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . 'svg.awa-hero-trust-strip__icon,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-trust-strip__icon{flex:0 0 48px!important;width:48px!important;min-width:48px!important;'
            . 'max-width:48px!important;height:48px!important;min-height:48px!important;max-height:48px!important}}'
            . '@media (min-width:992px){'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.awa-hero-b2b-cta .awa-hero-trust-strip{gap:24px!important}}'
            . 'html body#html-body .page-wrapper :is('
            . 'button,input,select,textarea,.action,.b2b-login-to-buy-btn,.b2b--listing,'
            . '.b2b-login-to-see-price,.b2b-login-to-see-price a){'
            . 'font-family:var(--awa-font-family,"Source Sans 3",system-ui,sans-serif)!important}'
            . 'html body#html-body :is(.toggle-nav-footer,.awa-cookie-banner__btn,.awa-back-to-top,'
            . '.b2b-login-modal-close,#awa-cookie-banner button){'
            . 'font-family:var(--awa-font-family,"Source Sans 3",system-ui,sans-serif)!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.navigation.verticalmenu.side-verticalmenu>ul.togge-menu.list-category-dropdown{'
            . 'overflow:visible!important}'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu :is('
            . 'ul.togge-menu.list-category-dropdown,.side-verticalmenu>ul.togge-menu){'
            . 'overflow:visible!important;padding:8px 12px!important;box-sizing:border-box!important}'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu :is('
            . '.submenu,.level0.submenu,.navigation__submenu,.subchildmenu,.navigation__inner-list){'
            . 'overflow:visible!important}'
            . 'html body#html-body .page-wrapper .page_footer.awa-footer-exp-control{'
            . 'padding-block:clamp(16px,2vw,24px)!important;'
            . 'padding-inline:clamp(16px,3vw,24px)!important;box-sizing:border-box!important}'
            . 'html body#html-body .page-wrapper .page_footer.awa-footer-exp-control>:is('
            . 'section,.container,.footer-container,#footer,.footer-bottom){'
            . 'padding-inline:clamp(8px,1.5vw,16px)!important;box-sizing:border-box!important}'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu,'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu '
            . '.togge-menu.list-category-dropdown,'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu '
            . '>ul.togge-menu.list-category-dropdown{contain:layout style!important;overflow:visible!important}'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu '
            . ':is(.level0.submenu,.level0>.level0.submenu,.subchildmenu,.navigation__submenu,'
            . '.navigation__inner-list){overflow:visible!important}'
            . '@media (min-width:992px){'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu '
            . '>ul.togge-menu.list-category-dropdown:is(.menu-open,.vmm-open,[aria-hidden="false"],'
            . '[data-awa-menu-state="open"]),'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu '
            . '.togge-menu.list-category-dropdown{overflow:visible!important;overflow-x:visible!important;'
            . 'contain:layout style!important}'
            . 'html body#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu '
            . '>ul.togge-menu.list-category-dropdown>li.ui-menu-item.level0{overflow:visible!important}}'
            . '@media (min-width:992px){'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            . '.menu_left_home1 .verticalmenu.side-verticalmenu>ul.togge-menu.list-category-dropdown:is('
            . '.menu-open,.vmm-open,[aria-hidden="false"],[data-awa-menu-state="open"]){'
            . 'overflow:visible!important;overflow-x:visible!important;contain:layout style!important}}';
    }

    public static function b2bRegisterSurfaceRules(): string
    {
        return 'html body#html-body.b2b-register-index .page-wrapper #b2b-register-shell{'
            . 'background:oklch(98.5% .004 20)!important;border:0!important;box-shadow:none!important;'
            . 'padding:clamp(20px,4vw,34px)!important;border-radius:16px!important}'
            . 'html body#html-body.b2b-register-index .page-wrapper #b2b-register-shell '
            . ':is(.b2b-register-container,.b2b-register-page){'
            . 'border:0!important;box-shadow:none!important;background:transparent!important;padding:0!important}'
            . 'html body#html-body.b2b-register-index .page-wrapper #b2b-register-shell .b2b-register-progress{'
            . 'border:0!important;background:transparent!important;box-shadow:none!important;padding:0 0 12px!important}'
            . 'html body#html-body.b2b-register-index .page-wrapper #b2b-register-shell .form-section{'
            . 'border:0!important;background:transparent!important;box-shadow:none!important;padding:0 0 16px!important}'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper{'
            . 'overflow-x:clip!important;overflow-y:visible!important}'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper .awa-skip-link:not(:focus):not(:focus-visible){'
            . 'left:0!important;width:1px!important;height:1px!important;clip-path:inset(50%)!important;'
            . 'overflow:hidden!important;margin:-1px!important}'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper '
            . ':is(#awa-search-label,#awa-search-panel-a11y,.mst-searchautocomplete__autocomplete){'
            . 'overflow:visible!important}'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper '
            . 'li.ui-menu-item.navigation__item--parent{overflow:visible!important}'
            . 'html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper '
            . ':is(.level0.submenu.navigation__submenu,.subchildmenu.navigation__inner-list){'
            . 'overflow:visible!important;padding-right:12px!important}';
    }

    public static function b2bDashboardSurfaceRules(): string
    {
        return 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns{'
            . 'display:grid!important;grid-template-columns:1fr!important;align-items:start!important;'
            . 'width:100%!important;gap:8px!important}'
            . '@media (min-width:769px){'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns{'
            . 'grid-template-columns:220px minmax(0,1fr)!important;column-gap:32px!important;gap:32px!important}}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns>'
            . ':is(.sidebar-main,.sidebar.sidebar-main){grid-column:1!important;grid-row:1!important;'
            . 'min-width:0!important;max-width:100%!important;width:100%!important}'
            . '@media (min-width:769px){'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns>'
            . ':is(.sidebar-main,.sidebar.sidebar-main){min-width:220px!important;max-width:220px!important;'
            . 'width:220px!important;overflow:visible!important}}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns>.column.main{'
            . 'grid-column:1!important;grid-row:1!important;min-width:0!important}'
            . '@media (min-width:769px){'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns>.column.main{'
            . 'grid-column:2!important;padding-inline:0!important}}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .block-collapsible-nav{'
            . 'padding:8px!important;border:0!important;box-shadow:none!important;'
            . 'background:oklch(98.5% .004 20)!important;border-radius:4px!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .block-collapsible-nav .item>a,'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .block-collapsible-nav .nav.item>a{'
            . 'padding:10px 14px!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .summary-card{'
            . 'box-shadow:none!important;border:1px solid oklch(92% .01 20)!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .summary-card:hover{'
            . 'transform:none!important;box-shadow:none!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper '
            . '.b2b-dashboard-lazy-panel[data-lazy-loaded="loading"]{'
            . 'padding:12px 16px!important;min-height:0!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper '
            . '.b2b-dashboard-lazy-panel[data-lazy-loaded="error"],'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper '
            . '.b2b-dashboard-lazy-panel.b2b-dashboard-lazy-panel--error{'
            . 'border:0!important;background:transparent!important;padding:8px 0!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main.container{'
            . 'max-width:var(--awa-page-dash,1280px)!important;width:100%!important;'
            . 'margin-inline:auto!important;padding-inline:var(--awa-page-pad,24px)!important;'
            . 'box-sizing:border-box!important}'
            . '@media (min-width:1025px){'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>'
            . ':is(.columns.layout.row,.columns.row){display:grid!important;'
            . 'grid-template-columns:minmax(248px,260px) minmax(0,1fr)!important;'
            . 'column-gap:28px!important;gap:28px!important;align-items:start!important;'
            . 'width:100%!important;margin:0!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>'
            . ':is(.columns.layout.row,.columns.row)>:first-child{grid-column:1!important;'
            . 'width:260px!important;min-width:260px!important;max-width:260px!important;'
            . 'padding:0!important;float:none!important;box-sizing:border-box!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>'
            . ':is(.columns.layout.row,.columns.row)>.col-main{grid-column:2!important;'
            . 'width:100%!important;max-width:none!important;min-width:0!important;'
            . 'padding:0!important;float:none!important;box-sizing:border-box!important}}'
            . '@media (min-width:769px) and (max-width:1024px){'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>'
            . ':is(.columns.layout.row,.columns.row){display:grid!important;'
            . 'grid-template-columns:minmax(232px,240px) minmax(0,1fr)!important;'
            . 'column-gap:20px!important;gap:20px!important;margin:0!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>'
            . ':is(.columns.layout.row,.columns.row)>:first-child{grid-column:1!important;'
            . 'width:240px!important;min-width:240px!important;max-width:240px!important;'
            . 'padding:0!important;float:none!important;box-sizing:border-box!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .page-main>'
            . ':is(.columns.layout.row,.columns.row)>.col-main{grid-column:2!important;'
            . 'width:100%!important;min-width:0!important;padding:0!important;float:none!important}}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper #block-collapsible-nav '
            . ':is(ul.nav.items,.account-nav-items){display:flex!important;flex-direction:column!important;'
            . 'gap:2px!important;width:100%!important;margin:0!important;padding:0!important;'
            . 'list-style:none!important;columns:1!important;column-count:1!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper #block-collapsible-nav '
            . ':is(li.nav.item,.account-nav-item,.account-nav-section){display:block!important;'
            . 'width:100%!important;max-width:100%!important;float:none!important;clear:both!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper #block-collapsible-nav '
            . ':is(li.nav.item>a,li.nav.item>strong,.account-nav-link,.account-nav-text){'
            . 'display:block!important;width:100%!important;min-height:36px!important;'
            . 'padding:8px 10px!important;border-radius:4px!important;font-size:13px!important;'
            . 'line-height:1.35!important;white-space:normal!important;overflow:visible!important;'
            . 'text-overflow:clip!important;word-break:normal!important;overflow-wrap:normal!important;'
            . 'text-align:left!important;box-sizing:border-box!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper '
            . ':is(.sidebar-additional1,.sidebar .block-reorder){display:none!important}';
    }

    /**
     * Painel B2B ativo: oculta o prompt legado em qualquer superfície (conta, pedidos, cotações…).
     */
    public static function headerB2bPanelCoexistenceRules(): string
    {
        return 'html body#html-body .page-wrapper .awa-site-header '
            . '.awa-header-right-col:has(.b2b-status-panel)>:is(.awa-header-account-prompt,.awa-header-contact-links),'
            . 'html body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . ':is(.top-account.awa-header-account-nav,nav.top-account.has-child.awa-header-account-nav),'
            . 'html body#html-body .page-wrapper .awa-site-header .b2b-status-panel~'
            . ':is(.top-account.awa-header-account-nav,nav.top-account.has-child.awa-header-account-nav){'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important;'
            . 'width:0!important;min-width:0!important;max-width:0!important;margin:0!important;padding:0!important;'
            . 'height:0!important;min-height:0!important;max-height:0!important;'
            . 'overflow:hidden!important;opacity:0!important;position:absolute!important;'
            . 'clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important}'
            // awa-super-global aplica gradiente/pill no trigger — reset visual SEM zerar hit-target 44px
            . 'html body#html-body .page-wrapper .awa-site-header .b2b-status-panel .b2b-status-trigger{'
            . 'background:transparent!important;background-image:none!important;'
            . 'border:0!important;border-radius:0!important;box-shadow:none!important;'
            . 'padding:0!important;padding-inline:0!important;'
            . 'min-block-size:44px!important;min-height:44px!important;height:44px!important;max-height:44px!important;'
            . 'align-items:center!important;color:var(--awa-text-primary)!important}'
            // 2026-07-16: mobile logado — grid cart=44px + status panel (~140px) invadia o logo.
            // right-col volta a ser flex na área cart; brand permanece centrada sem overlap.
            . '@media (max-width:767px){'
            // Reaplica grid-template completo — columns sozinho perde p/ shorthand 44px.
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'grid-template:"toggle brand cart" 44px "search search search" 44px/'
            . '44px minmax(0,1fr) minmax(96px,auto)!important;'
            . 'grid-template-columns:44px minmax(0,1fr) minmax(96px,auto)!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . '.awa-header-right-col.awa-header-right--logged{'
            . 'display:flex!important;grid-area:cart!important;align-items:center!important;'
            . 'justify-content:flex-end!important;justify-self:end!important;gap:2px!important;'
            . 'width:auto!important;max-width:min(140px,38vw)!important;min-width:0!important;'
            . 'overflow:hidden!important;height:44px!important;margin:0!important;padding:0!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . ':is(.awa-header-brand,.awa-header-brand-cell){max-width:min(120px,32vw)!important;'
            . 'overflow:hidden!important;min-width:0!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . '.awa-header-right-col .b2b-status-panel{'
            . 'max-width:min(88px,24vw)!important;min-width:0!important;overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . '.b2b-status-trigger{max-width:100%!important;gap:4px!important;'
            . 'color:var(--awa-text-primary,#0f172a)!important;background:transparent!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . ':is(.b2b-status-trigger__text,.b2b-status-trigger__line1){'
            . 'max-width:6ch!important;min-width:0!important;overflow:hidden!important;'
            . 'text-overflow:ellipsis!important;white-space:nowrap!important;'
            . 'color:var(--awa-text-primary)!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . ':is(.b2b-status-trigger__line2,.b2b-status-trigger__chevron){display:none!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header:has(.b2b-status-panel) '
            . ':is(.awa-header-minicart,.mini-cart-wrapper,.minicart-wrapper){'
            . 'grid-area:auto!important;width:44px!important;min-width:44px!important;'
            . 'flex:0 0 44px!important}'
            . '}';
    }

    /**
     * Prompt logado em FPC (home/PLP/carrinho) — sem card/pill; painel B2B cobre páginas de conta.
     */
    public static function headerLoggedPromptCleanRules(): string
    {
        return '@media (min-width:992px){'
            . 'html body#html-body:not(.b2b-account-dashboard) .page-wrapper .awa-site-header '
            . '.awa-header-account-prompt[data-awa-auth-state="customer"]{'
            . 'padding:0!important;border:0!important;background:transparent!important;'
            . 'background-image:none!important;box-shadow:none!important;border-radius:0!important}'
            . 'html body#html-body:not(.b2b-account-dashboard) .page-wrapper .awa-site-header '
            . '.awa-header-account-prompt[data-awa-auth-state="customer"] '
            . ':is(.awa-account-dropdown__trigger,.awa-header-account-prompt__link){'
            . 'padding:0!important;border:0!important;background:transparent!important;'
            . 'background-image:none!important;box-shadow:none!important;border-radius:0!important}'
            . '}';
    }

    /**
     * BUG-04: prompt de conta desktop — min 44px touch, altura auto para não clipar 2 linhas.
     */
    public static function headerAccountPromptCompactRules(): string
    {
        return '@media (min-width:992px){'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.awa-header-contact-links.awa-header-account-prompt,'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-header-account-prompt{'
            . 'display:inline-flex!important;align-items:flex-start!important;'
            . 'height:auto!important;min-height:44px!important;max-height:none!important;'
            . 'padding-block:6px!important;padding-inline:0!important;margin:0!important;'
            . 'overflow:visible!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-header-account-prompt__icon{'
            . 'width:28px!important;min-width:28px!important;height:28px!important;'
            . 'min-height:28px!important;display:inline-flex!important;align-items:center!important}'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.awa-header-account-prompt__text,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.awa-header-account-prompt__guest,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.awa-header-account-prompt__customer{'
            . 'display:flex!important;flex-direction:column!important;justify-content:center!important;'
            . 'gap:1px!important;min-height:0!important;line-height:1.35!important;'
            . 'overflow:visible!important}'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.awa-header-account-prompt__line1{'
            . 'font-size:12px!important;line-height:1.35!important;white-space:nowrap!important}'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . '.awa-header-account-prompt__line2{'
            . 'font-size:13px!important;line-height:1.35!important;white-space:nowrap!important}'
            . '}';
    }

    /**
     * Regras mínimas de header para superfícies sem cascade-lock (~112KB).
     */
    public static function headerEssentialTerminalRules(): string
    {
        return self::headerB2bPanelCoexistenceRules()
            . self::headerMinicartGhostTerminalRules()
            . self::headerLoggedPromptCleanRules()
            . self::headerAccountPromptCompactRules()
            . self::headerStickyPositionFixRules()
            . self::altaVisualAudit30Rules();
    }

    /**
     * AUDIT30 (2026-07-16): fixes Alta com CSS inline (vence cache immutable de styles-l/themes).
     * Evidência CDP: .price-container.weee display:none; grid-template 3ª track 44px; promo logado.
     */
    public static function altaVisualAudit30Rules(): string
    {
        return 'html body#html-body#html-body .page-wrapper .item-product .price-container.weee,'
            . 'html body#html-body#html-body .page-wrapper .item-product .price-container.price-final_price,'
            . 'html body#html-body#html-body .page-wrapper .item-deal-product .price-container.weee{'
            . 'display:inline!important;visibility:visible!important}'
            . 'html body#html-body#html-body .page-wrapper .item-product .weee:not(.price-container),'
            . 'html body#html-body#html-body .page-wrapper .item-deal-product .weee:not(.price-container){'
            . 'display:none!important}'
            . 'html body#html-body.customer-logged-in #awa-b2b-promo-bar,'
            . 'html body#html-body.customer-logged-in .awa-b2b-promo-bar,'
            . 'html body#html-body:has(.b2b-status-panel) #awa-b2b-promo-bar,'
            . 'html body#html-body:has(.b2b-status-panel) .awa-b2b-promo-bar{'
            . 'display:none!important;visibility:hidden!important;height:0!important;max-height:0!important;'
            . 'overflow:hidden!important;margin:0!important;padding:0!important;border:0!important}'
            . 'html body#html-body.awa-dark-mode.checkout-cart-index .page-wrapper '
            . ':is(h1.page-title,.page-title-wrapper .page-title,.page-title){'
            . 'color:var(--awa-text-primary,#f1f5f9)!important}'
            . '@media (max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header:has(.b2b-status-panel) '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'grid-template:"toggle brand cart" 44px "search search search" 44px/'
            . '44px minmax(0,1fr) minmax(96px,auto)!important;'
            . 'grid-template-columns:44px minmax(0,1fr) minmax(96px,auto)!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header:has(.b2b-status-panel) .awa-header-right-col{'
            . 'max-width:min(140px,38vw)!important;width:auto!important;min-width:0!important;'
            . 'overflow:hidden!important;justify-self:end!important;gap:2px!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header:has(.b2b-status-panel) :is(.awa-header-brand,.awa-header-brand-cell){'
            . 'max-width:min(120px,32vw)!important;overflow:hidden!important;min-width:0!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header .b2b-status-panel .b2b-status-trigger,'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header .b2b-status-panel :is(.b2b-status-trigger__line1,.b2b-status-trigger__text){'
            . 'color:var(--awa-text-primary,#0f172a)!important;background:transparent!important;'
            . 'background-image:none!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . 'nav.fixed-bottom .mobile-bottom-link>li>button.toggle-nav-footer{'
            . 'color:var(--awa-text-secondary,#475569)!important;background:transparent!important}'
            . '}'
            /* Alta #11: ocultar "Sair da tela cheia" fora do fullscreen Fotorama */
            . 'html body#html-body.catalog-product-view .page-wrapper '
            . '.fotorama__fullscreen-icon:not(.fotorama--fullscreen .fotorama__fullscreen-icon),'
            . 'html body#html-body.catalog-product-view:not(:has(.fotorama--fullscreen)) .page-wrapper '
            . '.fotorama__fullscreen-icon{'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important}'
            /* Alta #12: ícone Início da bottom nav legível */
            . 'html body#html-body nav.fixed-bottom .mobile-bottom-link>li>a.active,'
            . 'html body#html-body nav.fixed-bottom .mobile-bottom-link>li>a[aria-current="page"],'
            . 'html body#html-body nav.fixed-bottom .mobile-bottom-link>li>a:first-child{'
            . 'color:var(--awa-text-primary)!important}'
            . 'html body#html-body nav.fixed-bottom .mobile-bottom-link>li>a :is(.icon,span.icon,svg){'
            . 'color:inherit!important;opacity:1!important;fill:currentColor!important}'
            . 'html body#html-body.awa-dark-mode nav.fixed-bottom .mobile-bottom-link>li>a{'
            . 'color:var(--awa-text-primary,#e2e8f0)!important}'
            /* Alta #10: hero PLP — vencer awa-plp-distill opacity:0.32!important */
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index) '
            . '.page-wrapper .awa-category-hero--has-image .awa-category-hero__bg-image,'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper '
            . '.awa-category-hero .awa-category-hero__bg-image,'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .category-image img,'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .category-image .image,'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .awa-category-hero img{'
            . 'opacity:1!important;filter:none!important;-webkit-filter:none!important;'
            . 'mix-blend-mode:normal!important;visibility:visible!important}'
            /* Alta #9: cart — espaço para painel B2B hidratado */
            . '@media(max-width:767px){'
            . 'html body#html-body.checkout-cart-index .page-wrapper '
            . '.awa-site-header .awa-header-right-col:has(.b2b-status-panel){'
            . 'min-width:96px!important;max-width:min(140px,38vw)!important;width:auto!important;'
            . 'overflow:hidden!important}'
            . '}'
            . self::mediaVisualAudit30Rules()
            . self::baixaVisualAudit30Rules();
    }

    /**
     * Audit visual — prioridade Média (13–24): PLP lupa/filtros, truncamentos, carrossel, FAB, header dark.
     */
    public static function mediaVisualAudit30Rules(): string
    {
        return
            /* #18: lupa Mirasvit dentro do input (não órfã abaixo) */
            'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) '
            . '.page-wrapper .mst_categorySearch{position:relative!important}'
            . 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index) '
            . '.page-wrapper .mst_categorySearch .mst_categorySearch_searchIcon{'
            . 'position:absolute!important;right:12px!important;top:22px!important;'
            . 'transform:translateY(-50%)!important;width:16px!important;height:16px!important;'
            . 'margin:0!important;display:inline-flex!important;align-items:center!important;'
            . 'justify-content:center!important;pointer-events:none!important;color:var(--awa-text-secondary,#64748b)!important;'
            . 'z-index:2!important}'
            /* #19: um controle de filtro — toolbar modes-label; esconder FAB/duplicate */
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index) '
            . '.page-wrapper :is(.awa-plp-filter-button,.awa-filter-toggle){'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important;'
            . 'height:0!important;width:0!important;overflow:hidden!important;margin:0!important;padding:0!important;'
            . 'border:0!important}'
            . '}'
            /* #16: placeholder da busca do header legível */
            . '@media(max-width:767px){'
            . 'html body#html-body .page-wrapper .awa-site-header input#search::placeholder,'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-header-search input.input-text::placeholder{'
            . 'font-size:13px!important;letter-spacing:0!important;opacity:1!important;'
            . 'color:var(--awa-text-secondary,#64748b)!important;text-overflow:ellipsis!important}'
            /* #17: nome B2B — priorizar "Olá, Nome" sem truncar demais */
            . 'html body#html-body .page-wrapper .awa-site-header .b2b-status-panel .b2b-status-trigger{'
            . 'min-width:0!important;max-width:100%!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .b2b-status-panel '
            . '.b2b-status-trigger__line1{overflow:hidden!important;text-overflow:ellipsis!important;'
            . 'white-space:nowrap!important;max-width:100%!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .b2b-status-panel '
            . '.b2b-status-trigger__line2{display:none!important}'
            . '}'
            /* #20: setas do carrossel de categorias fora das cards */
            . '@media(max-width:767px){'
            . 'html body#html-body.cms-index-index .page-wrapper '
            . '.awa-category-carousel .awa-owl-nav,'
            . 'html body#html-body.cms-index-index .page-wrapper '
            . '.awa-carousel-shell:has(.awa-category-carousel) .awa-owl-nav{'
            . 'position:static!important;display:flex!important;justify-content:center!important;'
            . 'gap:8px!important;margin-top:8px!important;pointer-events:auto!important}'
            . 'html body#html-body.cms-index-index .page-wrapper '
            . '.awa-category-carousel .awa-owl-nav__btn,'
            . 'html body#html-body.cms-index-index .page-wrapper '
            . '.awa-carousel-shell:has(.awa-category-carousel) .awa-owl-nav__btn{'
            . 'position:static!important;inset:auto!important;transform:none!important;'
            . 'margin:0!important}'
            . '}'
            /* #21: FAB voltar ao topo — não cobrir conteúdo crítico */
            . '@media(max-width:767px){'
            . 'html body#html-body .page-wrapper .back-to-top,'
            . 'html body#html-body .page-wrapper #back-to-top,'
            . 'html body#html-body button[aria-label="Voltar ao topo"]{'
            . 'bottom:calc(var(--awa-mobile-bottom-nav-h,64px) + 16px + env(safe-area-inset-bottom,0px))!important;'
            . 'right:12px!important;z-index:40!important}'
            . '}'
            /* #23: desktop 24px/14px — 36px/lh36 deixava 8px vazios no ul (CDP). */
            . '@layer awa-visual-priority{'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs,'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .nav-breadcrumbs,'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs .items{'
            . 'min-height:24px!important;height:auto!important;max-height:none!important;'
            . 'padding-block:0!important;margin-block:0!important;line-height:1.25!important;'
            . 'font-size:12px!important;background:transparent!important;border:0!important;'
            . 'box-shadow:none!important;overflow:hidden!important}'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .nav-breadcrumbs{'
            . 'padding-inline:var(--awa-shell-pad,clamp(16px,3vw,24px))!important;'
            . 'margin-block:0!important;margin-inline:auto!important;width:100%!important;'
            . 'max-width:min(100%,1280px)!important}'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs .items{'
            . 'display:flex!important;flex-wrap:wrap!important;gap:4px 8px!important;align-items:center!important;'
            . 'width:max-content!important;max-width:100%!important;flex:0 0 auto!important}'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs '
            . ':is(.item,.item:last-child){'
            . 'flex:0 1 auto!important;width:auto!important;max-width:none!important;min-width:0!important}'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs '
            . ':is(.item,a,strong,span){'
            . 'min-height:24px!important;height:auto!important;max-height:none!important;'
            . 'padding:0!important;margin:0!important;font-size:inherit!important;line-height:1.25!important;'
            . 'display:inline-flex!important;align-items:center!important}'
            . '}'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs,'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .nav-breadcrumbs,'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs .items{'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'padding:2px 0!important;margin:0 0 6px!important;line-height:1.25!important;'
            . 'background:transparent!important;border:0!important;box-shadow:none!important}'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs .items{'
            . 'display:flex!important;flex-wrap:wrap!important;gap:2px 6px!important;align-items:center!important}'
            . 'html body#html-body#html-body.catalog-category-view .page-wrapper .breadcrumbs '
            . ':is(.item,a,strong,span){'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'padding:0!important;margin:0!important;font-size:12px!important;line-height:1.25!important;'
            . 'display:inline!important}'
            . '}'
            . '}'
            /* #24: tokens dark + header com cores literais (vence visual-fixes sticky branco) */
            . 'html body#html-body#html-body.awa-dark-mode{'
            . '--awa-bg-page:#0f172a!important;--awa-bg-surface:#1e293b!important;'
            . '--awa-bg-elevated:#334155!important;--awa-text-primary:#f1f5f9!important;'
            . '--awa-text-secondary:#cbd5e1!important;--awa-text-muted:#94a3b8!important;'
            . '--awa-border:#334155!important;--awa-border-subtle:#475569!important;'
            . 'background-color:#0f172a!important;color:#f1f5f9!important}'
            . 'html body#html-body#html-body.awa-dark-mode .page-wrapper .awa-site-header,'
            . 'html body#html-body#html-body.awa-dark-mode .page-wrapper .header-wrapper-sticky,'
            . 'html body#html-body#html-body.awa-dark-mode .page-wrapper .header-wrapper-sticky.is-sticky,'
            . 'html body#html-body#html-body.awa-dark-mode .page-wrapper .awa-main-header,'
            . 'html body#html-body#html-body.awa-dark-mode .page-wrapper .header_main,'
            . 'html body#html-body#html-body.awa-dark-mode .page-wrapper #header,'
            . 'html body#html-body#html-body.awa-dark-mode .page-wrapper .header-content{'
            . 'background:#1e293b!important;background-color:#1e293b!important;'
            . 'color:#f1f5f9!important;border-color:#334155!important;'
            . 'backdrop-filter:none!important;-webkit-backdrop-filter:none!important}'
            . 'html body#html-body#html-body.awa-dark-mode .page-wrapper .awa-site-header '
            . ':is(.b2b-status-trigger,.b2b-status-trigger__line1,.b2b-status-trigger__text){'
            . 'color:#f1f5f9!important}';
    }

    /**
     * Audit visual — prioridade Baixa (25–30).
     */
    public static function baixaVisualAudit30Rules(): string
    {
        return
            /* #25: botão buscar desabilitado — estado intencional, não “morto” */
            'html body#html-body .page-wrapper .awa-site-header '
            . 'form#search_mini_form button.action.search:disabled,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . 'form#search_mini_form button.action.search[aria-disabled="true"]{'
            . 'opacity:1!important;filter:none!important;cursor:not-allowed!important;'
            . 'background:transparent!important;color:var(--awa-primary,#b73337)!important}'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . 'form#search_mini_form button.action.search:disabled svg,'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . 'form#search_mini_form button.action.search[aria-disabled="true"] svg{'
            . 'opacity:.85!important;stroke:var(--awa-primary,#b73337)!important}'
            /* #26: skip links (awa-skip-link + action.skip) — filhos do body */
            . 'html body#html-body :is(a.awa-skip-link,a.skip-link,a.action.skip,a.action.skip.content,'
            . 'a.action.skip.nav,a.skip-to-main-content):is(:focus,:focus-visible){'
            . 'position:fixed!important;left:8px!important;top:8px!important;z-index:100000!important;'
            . 'width:auto!important;min-width:44px!important;height:auto!important;min-height:44px!important;'
            . 'max-width:none!important;max-height:none!important;'
            . 'clip:auto!important;clip-path:none!important;overflow:visible!important;contain:none!important;'
            . 'padding:10px 14px!important;display:inline-flex!important;align-items:center!important;'
            . 'background:#b73337!important;color:#fff!important;border-radius:8px!important;'
            . 'font-weight:600!important;text-decoration:none!important;white-space:normal!important;'
            . 'font-size:14px!important;line-height:1.3!important;opacity:1!important;visibility:visible!important}'
            /* #27: lupa centralizada no botão vermelho/header */
            . 'html body#html-body .page-wrapper .awa-site-header '
            . 'form#search_mini_form button.action.search{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'line-height:0!important}'
            . 'html body#html-body .page-wrapper .awa-site-header '
            . 'form#search_mini_form button.action.search svg{'
            . 'display:block!important;margin:0 auto!important;width:20px!important;height:20px!important;'
            . 'position:static!important;inset:auto!important;transform:none!important}'
            /* #28: cookie acima da bottom nav mobile */
            . '@media(max-width:767px){'
            . 'html body#html-body #awa-cookie-banner.awa-cookie-banner--visible{'
            . 'bottom:calc(var(--awa-mobile-bottom-nav-h,64px) + env(safe-area-inset-bottom,0px))!important}'
            . 'html body#html-body.awa-pwa-install-open .awa-pwa-install-prompt__card{'
            . 'margin-bottom:calc(var(--awa-mobile-bottom-nav-h,64px) + 12px)!important}'
            . '}'
            /* #29: toggle dark flutuante — esconder no carrinho/checkout */
            . 'html body#html-body:is(.checkout-cart-index,.checkout-index-index,.onepagecheckout-index-index) '
            . '.awa-dark-mode-toggle{'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important;'
            . 'width:0!important;height:0!important;overflow:hidden!important}'
            /* #30: badge CNPJ sem emoji (fallback se template em cache) */
            . 'html body#html-body .page-wrapper .awa-footer-cnpj-badge__icon{'
            . 'display:none!important}';
    }

    /**
     * FIX 2026-07-06 (Impeccable "Nested cards" div.header-wrapper-sticky.is-sticky):
     * o CSS que troca position:sticky -> position:fixed vive em
     * awa-align-grid-terminal-2026-06-11.min.css, mas esse arquivo só é carregado
     * pelo awa-css-gate (JS) após interação real do usuário ou timeout de 18s
     * (ver bootstrap inline em OptimizeHeadStylesPlugin). Isso deixa o header
     * "quebrado" (scrolla junto com a página) nos primeiros segundos de qualquer
     * visita — inclusive para o crawler do Impeccable, que não interage.
     * Solução: duplicar a regra crítica aqui, no CSS síncrono (inline, sem gate),
     * para que o header já nasça fixo assim que .is-sticky é aplicado pelo JS de
     * scroll (awa-header-sticky.js), sem depender do carregamento adiado.
     */
    public static function headerStickyPositionFixRules(): string
    {
        return 'html body#html-body:not(.onepagecheckout-index-index):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header[data-awa-header-mode=default] .header-wrapper-sticky.is-sticky{'
            . 'position:fixed!important;top:0!important;left:0!important;right:0!important;'
            . 'width:100%!important;z-index:1000!important;overflow:visible!important;contain:none!important}';
    }

    /**
     * Minicart ghost (ícone vermelho outline) — todas as páginas exceto checkout e dashboard B2B.
     * O botão vermelho sólido fica restrito a b2bDashboardHeaderRules().
     */
    public static function headerMinicartGhostTerminalRules(): string
    {
        /* 5×#html-body — alinhado ao contrast lock / FOUC (vence themes.min CTA vermelho). */
        $root = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':not(.checkout-index-index):not(.onepagecheckout-index-index):not(.b2b-account-dashboard)';
        $cart = $root . ' .page-wrapper .awa-site-header .awa-header-minicart .minicart-wrapper '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger)';

        return '@media (min-width:992px){'
            . $cart . '{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;min-width:44px!important;height:44px!important;min-height:44px!important;'
            . 'padding:0!important;border:0!important;border-radius:0!important;'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'box-shadow:none!important}'
            . $cart . ':is(:hover,:focus-visible){'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'box-shadow:none!important;opacity:.88!important}'
            . $cart . ' :is(svg,.awa-minicart-icon){'
            . 'display:inline-block!important;visibility:visible!important;opacity:1!important}'
            . $cart . ' :is(svg,.awa-minicart-icon),'
            . $cart . ' :is(svg,.awa-minicart-icon) path{'
            . 'width:24px!important;height:24px!important;min-width:24px!important;min-height:24px!important;'
            . 'stroke:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important;fill:none!important}'
            . $cart . ' :is(svg,.awa-minicart-icon) circle{'
            . 'fill:var(--awa-primary,oklch(48% .14 20))!important;stroke:none!important}'
            . $cart . ' .counter.qty:not(.empty){'
            . 'position:absolute!important;top:auto!important;bottom:2px!important;right:0!important;'
            . 'width:auto!important;min-width:14px!important;max-width:34px!important;height:14px!important;'
            . 'padding:0 4px!important;font-size:10px!important;font-weight:700!important;line-height:1!important;'
            . 'background:var(--awa-bg-surface,#fff)!important;'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'border:1px solid var(--awa-primary,oklch(48% .14 20))!important;'
            . 'box-shadow:none!important;border-radius:999px!important}'
            . '}';
    }

    public static function b2bDashboardHeaderRules(): string
    {
        return '@media (min-width:992px){'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header{'
            . 'background:var(--awa-surface,oklch(99% .002 20))!important;'
            . 'box-shadow:0 1px 0 var(--awa-border,oklch(90% .008 20))!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.header-wrapper-sticky{min-height:68px!important;max-height:68px!important;'
            . 'padding-block:0!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap){'
            . 'min-height:68px!important;height:68px!important;max-height:68px!important;'
            . 'padding:0 clamp(16px,3vw,24px)!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'display:grid!important;grid-template-areas:"brand search actions"!important;'
            . 'grid-template-columns:minmax(104px,148px) minmax(360px,620px) minmax(88px,180px)!important;'
            . 'align-items:center!important;justify-content:center!important;gap:24px!important;'
            . 'width:100%!important;max-width:1224px!important;height:68px!important;'
            . 'min-height:68px!important;max-height:68px!important;margin-inline:auto!important;'
            . 'padding:0!important;box-sizing:border-box!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-primary-row,.awa-header-right-col){display:contents!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-brand-cell,.col-md-2.awa-header-brand){'
            . 'grid-area:brand!important;display:flex!important;align-items:center!important;'
            . 'justify-content:flex-start!important;height:68px!important;min-height:68px!important;'
            . 'max-height:68px!important;min-width:0!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-brand-cell,.col-md-2.awa-header-brand) :is(.logo,.logo a){'
            . 'display:flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'height:56px!important;max-height:56px!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-brand-cell,.col-md-2.awa-header-brand) .logo img{'
            . 'display:block!important;width:auto!important;height:44px!important;max-height:44px!important;'
            . 'object-fit:contain!important;object-position:center!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-search-col,.top-search){'
            . 'grid-area:search!important;display:flex!important;align-items:center!important;'
            . 'width:100%!important;min-width:0!important;max-width:620px!important;margin:0!important;'
            . 'padding:0!important;align-self:center!important;overflow:visible!important;'
            . 'height:68px!important;min-height:68px!important;max-height:68px!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-search-col,.top-search) :is(.block-search,.block-content){'
            . 'display:block!important;width:100%!important;min-width:0!important;'
            . 'max-width:100%!important;height:44px!important;min-height:44px!important;'
            . 'max-height:44px!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-search-col,.top-search) :is(form#search_mini_form,form.minisearch){'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;'
            . 'grid-template-areas:"field submit"!important;align-items:stretch!important;'
            . 'width:100%!important;min-width:0!important;max-width:100%!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'margin:0!important;padding:0!important;background:var(--awa-surface,oklch(99% .002 20))!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'border-radius:10px!important;box-shadow:none!important;overflow:hidden!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . 'form#search_mini_form :is(.field.search,.field.search .control){'
            . 'grid-area:field!important;display:flex!important;width:100%!important;'
            . 'min-width:0!important;height:44px!important;min-height:44px!important;'
            . 'margin:0!important;padding:0!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . 'form#search_mini_form :is(input#search,input[name=q]){'
            . 'position:static!important;width:100%!important;min-width:0!important;'
            . 'height:44px!important;min-height:44px!important;border:0!important;'
            . 'box-shadow:none!important;padding:0 14px!important;font-size:14px!important;'
            . 'line-height:44px!important;background:transparent!important;color:var(--awa-text,oklch(22% .02 20))!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . 'form#search_mini_form .actions{'
            . 'grid-area:submit!important;display:flex!important;align-items:stretch!important;'
            . 'justify-content:center!important;width:44px!important;height:44px!important;'
            . 'min-width:44px!important;min-height:44px!important;margin:0!important;padding:0!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . 'form#search_mini_form .action.search{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;min-width:44px!important;height:44px!important;min-height:44px!important;'
            . 'margin:0!important;padding:0!important;border:0!important;border-radius:0!important;'
            . 'background:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-minicart,.mini-cart-wrapper,.minicart-wrapper){'
            . 'grid-area:actions!important;justify-self:start!important;align-self:center!important;'
            . 'position:relative!important;display:flex!important;align-items:center!important;'
            . 'justify-content:flex-start!important;width:auto!important;min-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger){'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;min-width:44px!important;height:44px!important;min-height:44px!important;'
            . 'margin:0!important;padding:0!important;border-radius:10px!important;'
            . 'background:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            // Especificidade boostada (.awa-header-minicart .minicart-wrapper) para vencer o
            // contrato ghost do _awa-header-stack.less §5, que força stroke vermelho no ícone
            // — vermelho sobre o botão vermelho deixava o cart como bloco sólido sem ícone.
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.awa-header-minicart .minicart-wrapper '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger) :is(svg,.awa-minicart-icon),'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger) :is(svg,.awa-minicart-icon){'
            . 'display:inline-block!important;visibility:visible!important;opacity:1!important;'
            . 'width:20px!important;height:20px!important;min-width:20px!important;min-height:20px!important;'
            . 'fill:none!important;stroke:currentColor!important;color:currentColor!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.awa-header-minicart .minicart-wrapper '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger) :is(svg,.awa-minicart-icon) path{'
            . 'stroke:currentColor!important;color:currentColor!important;fill:none!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.awa-header-minicart .minicart-wrapper '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger) :is(svg,.awa-minicart-icon) circle{'
            . 'fill:currentColor!important;stroke:none!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.awa-header-minicart .minicart-wrapper '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger) .counter.qty:not(.empty){'
            . 'position:absolute!important;top:-6px!important;bottom:auto!important;right:-6px!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:auto!important;min-width:18px!important;max-width:34px!important;'
            . 'height:18px!important;padding:0 4px!important;box-sizing:border-box!important;'
            . 'border-radius:999px!important;overflow:hidden!important;'
            . 'background:var(--awa-text-inverse,oklch(99% .002 20))!important;'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'border:1px solid var(--awa-primary,oklch(48% .14 20))!important;'
            . 'font-size:10px!important;font-weight:800!important;line-height:16px!important;white-space:nowrap!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.awa-header-minicart .minicart-wrapper '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger) .counter.qty:not(.empty) '
            . ':is(.total-mini-cart-item,.counter-number){'
            . 'position:static!important;display:inline!important;width:auto!important;height:auto!important;'
            . 'color:inherit!important;font-size:inherit!important;line-height:inherit!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.awa-header-minicart .minicart-wrapper '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-minicart-trigger) .counter.qty.empty{'
            . 'display:none!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.awa-header-right-col:has(.b2b-status-panel)>:is(.awa-header-account-prompt,.awa-header-contact-links),'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-header-account-prompt,.awa-header-contact-links,.top-account.awa-header-account-nav,'
            . 'nav.top-account.has-child.awa-header-account-nav){'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important;'
            . 'width:0!important;min-width:0!important;max-width:0!important;margin:0!important;padding:0!important;'
            . 'height:0!important;min-height:0!important;max-height:0!important;'
            . 'overflow:hidden!important;opacity:0!important;position:absolute!important;'
            . 'clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.awa-nav-bar){'
            . 'display:block!important;background:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'border:0!important;box-shadow:none!important;min-height:48px!important;'
            . 'height:48px!important;max-height:48px!important;padding:0 clamp(16px,3vw,24px)!important;'
            . 'overflow:visible!important;box-sizing:border-box!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.awa-nav-bar) '
            . ':is(.container,.row,.awa-nav-bar__inner){'
            . 'display:flex!important;align-items:center!important;justify-content:space-between!important;'
            . 'width:100%!important;max-width:1224px!important;height:48px!important;'
            . 'min-height:48px!important;max-height:48px!important;margin-inline:auto!important;'
            . 'padding:0!important;box-sizing:border-box!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]){'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'height:44px!important;min-height:44px!important;padding:0 16px!important;'
            . 'background:color-mix(in srgb,var(--awa-primary,oklch(48% .14 20)) 86%,oklch(20% .02 20))!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important;border:0!important;'
            . 'border-radius:8px!important;font-weight:600!important;line-height:1!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-nav-quick-links,.custommenu.main-nav,.navigation.custommenu){'
            . 'display:flex!important;align-items:center!important;justify-content:flex-end!important;'
            . 'height:48px!important;min-height:48px!important;margin-left:auto!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-nav-quick-links__list,.custommenu.main-nav>ul,.navigation.custommenu>ul){'
            . 'display:flex!important;align-items:center!important;gap:clamp(18px,3vw,48px)!important;'
            . 'height:48px!important;min-height:48px!important;margin:0!important;padding:0!important;'
            . 'list-style:none!important;overflow:visible!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.awa-nav-quick-links__link,.custommenu.main-nav a,.navigation.custommenu a){'
            . 'display:inline-flex!important;align-items:center!important;min-height:44px!important;'
            . 'padding:0 4px!important;color:var(--awa-text-inverse,oklch(99% .002 20))!important;'
            . 'font-size:13px!important;font-weight:600!important;line-height:1.2!important;text-decoration:none!important}'
            . '}'
            . '@media (max-width:991px){'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . ':is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.awa-nav-bar){'
            . 'display:none!important;visibility:hidden!important;height:0!important;min-height:0!important;'
            . 'max-height:0!important;padding:0!important;overflow:hidden!important}'
            . 'html body#html-body.b2b-account-dashboard .page-wrapper .awa-site-header '
            . '.header-wrapper-sticky{min-height:96px!important;max-height:none!important}'
            . '}';
    }

    public static function headerNavShellRules(): string
    {
        return '@media (min-width:992px){'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .header-control.awa-nav-bar .awa-header-primary-nav.menu_primary[data-awa-topnav-empty="1"],'
            . '.page-wrapper .header-control.awa-nav-bar .awa-header-primary-nav.menu_primary:has(nav.top-menu:empty){'
            . 'display:none!important;visibility:hidden!important;flex:0 0 0!important;'
            . 'width:0!important;min-width:0!important;max-width:0!important;height:0!important;'
            . 'min-height:0!important;max-height:0!important;margin:0!important;padding:0!important;'
            . 'overflow:hidden!important;pointer-events:none!important;border:0!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'display:flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'gap:16px!important;width:min(100%,1280px)!important;max-width:1280px!important;'
            . 'min-height:var(--awa-nav-bar-h,48px)!important;height:var(--awa-nav-bar-h,48px)!important;'
            . 'max-height:var(--awa-nav-bar-h,48px)!important;margin-inline:auto!important;'
            . 'padding-inline:24px!important;box-sizing:border-box!important;overflow:visible!important}'
            /* Quick links ao lado do menu vertical (Departamentos) — não à extrema direita. */
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .header-control.awa-nav-bar .awa-nav-quick-links{'
            . 'display:flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'flex:0 0 auto!important;min-width:0!important;max-width:none!important;width:auto!important;'
            . 'margin:0!important;margin-left:0!important;margin-inline:0!important;'
            . 'justify-self:start!important;grid-column:auto!important;position:static!important;'
            . 'overflow:visible!important;height:var(--awa-nav-bar-h,48px)!important;background:transparent!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .header-control.awa-nav-bar .awa-nav-quick-links__list{'
            . 'display:flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'gap:clamp(16px,1.6vw,28px)!important;min-width:0!important;max-width:none!important;'
            . 'overflow:visible!important;margin:0!important;padding:0!important;list-style:none!important}'
            . 'html body#html-body.catalog-product-view:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'justify-content:flex-start!important;gap:clamp(16px,1.5vw,24px)!important}'
            . 'html body#html-body.catalog-product-view:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar .awa-nav-quick-links{'
            . 'flex:0 0 auto!important;max-width:none!important;overflow:visible!important;'
            . 'margin:0!important;justify-content:flex-start!important;justify-self:start!important;grid-column:auto!important}'
            . 'html body#html-body.catalog-product-view:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar .awa-nav-quick-links__list{'
            . 'justify-content:flex-start!important;gap:clamp(16px,1.6vw,28px)!important;'
            . 'overflow:visible!important;max-width:none!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header :is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar){'
            . 'width:100%!important;max-width:none!important;'
            . 'margin-inline:0!important;margin-block:0!important;padding:0!important;'
            . 'border:0!important;box-shadow:none!important;overflow:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar > .container{'
            . 'width:100%!important;max-width:none!important;'
            . 'margin-inline:0!important;padding-inline:0!important;'
            . 'background:transparent!important;border:0!important;overflow:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'background:transparent!important;border:0!important;overflow:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar '
            . ':is(.awa-header-categories,.awa-nav-quick-links,.sections.nav-sections,.section-items){'
            . 'background:transparent!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar .awa-header-categories.menu_left_home1{'
            . 'flex:0 0 auto!important;max-width:none!important;min-width:0!important;background:transparent!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar '
            . ':is(.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]){'
            . 'display:inline-flex!important;align-items:center!important;gap:8px!important;'
            . 'height:var(--awa-nav-bar-h,48px)!important;min-height:var(--awa-nav-bar-h,48px)!important;'
            . 'max-height:var(--awa-nav-bar-h,48px)!important;padding:0 18px!important;margin:0!important;'
            . 'border:0!important;border-radius:0!important;box-shadow:none!important;'
            . 'background:transparent!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar '
            . ':is(.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]):hover,'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar '
            . ':is(.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]):focus-visible{'
            . 'background:color-mix(in srgb,var(--awa-text-inverse,oklch(99% .002 20)) 12%,transparent)!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar '
            . ':is(.awa-nav-quick-links__link,.header-wrapper-sticky .awa-nav-quick-links__link){'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar .awa-nav-quick-links__link::after{'
            . 'background:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5):not(.checkout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.awa-nav-bar .awa-nav-quick-links__link:hover{'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . '}';
    }

    public static function headerVisualStandardRules(): string
    {
        return 'html body#html-body .page-wrapper .awa-site-header{'
            . '--awa-header-control-h:44px;--awa-header-control-radius:10px;'
            . '--awa-header-main-row-h:68px;--awa-header-row-h:var(--awa-header-main-row-h);'
            . '--awa-header-nav-h:48px;--awa-nav-bar-h:var(--awa-header-nav-h)}'
            . '@media (min-width:992px){'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .header_main.awa-main-header-inner-wrap .container{'
            . 'max-width:var(--awa-container-catalog,var(--awa-container-max,1280px))!important;'
            . 'width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:clamp(16px,3vw,24px)!important;box-sizing:border-box!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'max-width:var(--awa-container-catalog,var(--awa-container-max,1280px))!important;'
            . 'width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:clamp(16px,3vw,24px)!important;box-sizing:border-box!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header form#search_mini_form{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) var(--awa-header-control-h)!important;'
            . 'grid-template-areas:"field submit"!important;align-items:stretch!important;'
            . 'height:var(--awa-header-control-h)!important;'
            . 'min-height:var(--awa-header-control-h)!important;max-height:var(--awa-header-control-h)!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'border-radius:var(--awa-header-control-radius)!important;overflow:hidden!important;'
            . 'background:var(--awa-surface,oklch(99% .002 20))!important;box-shadow:none!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header form#search_mini_form :is(.field.search,.field.search .control){'
            . 'grid-area:field!important;display:flex!important;width:100%!important;min-width:0!important;'
            . 'max-width:100%!important;height:var(--awa-header-control-h)!important;'
            . 'min-height:var(--awa-header-control-h)!important;margin:0!important;padding:0!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header form#search_mini_form input#search{'
            . 'position:static!important;width:100%!important;min-width:0!important;max-width:100%!important;'
            . 'flex:1 1 auto!important;box-sizing:border-box!important;'
            . 'height:var(--awa-header-control-h)!important;min-height:var(--awa-header-control-h)!important;'
            . 'line-height:var(--awa-header-control-h)!important;font-size:14px!important;border:0!important;'
            . 'box-shadow:none!important;padding:0 14px!important;background:transparent!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header form#search_mini_form .actions{'
            . 'grid-area:submit!important;display:flex!important;align-items:stretch!important;'
            . 'justify-content:center!important;width:var(--awa-header-control-h)!important;'
            . 'height:var(--awa-header-control-h)!important;min-width:var(--awa-header-control-h)!important;'
            . 'min-height:var(--awa-header-control-h)!important;margin:0!important;padding:0!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header form#search_mini_form .action.search{'
            . 'width:var(--awa-header-control-h)!important;min-width:var(--awa-header-control-h)!important;'
            . 'height:var(--awa-header-control-h)!important;min-height:var(--awa-header-control-h)!important;'
            . 'border-radius:0!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .awa-header-search-col{'
            . 'align-self:center!important;height:var(--awa-header-control-h)!important;'
            . 'min-height:var(--awa-header-control-h)!important;max-height:var(--awa-header-control-h)!important;'
            . 'overflow:visible!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .awa-header-search-col .block-search{'
            . 'height:var(--awa-header-control-h)!important;min-height:var(--awa-header-control-h)!important;'
            . 'max-height:var(--awa-header-control-h)!important;overflow:visible!important}'
            /* Beat _extend.less height:32px no stack de busca (desalinha vs logo/B2B na row 68). */
            . 'html body#html-body#html-body#html-body#html-body#html-body:not(.checkout-index-index)'
            . ':not(.onepagecheckout-index-index):not(.b2b-account-dashboard) .page-wrapper .awa-site-header '
            . '.awa-header-search-col{'
            . 'display:flex!important;align-items:center!important;align-self:center!important;'
            . 'height:var(--awa-header-main-row-h,68px)!important;'
            . 'min-height:var(--awa-header-main-row-h,68px)!important;'
            . 'max-height:var(--awa-header-main-row-h,68px)!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body:not(.checkout-index-index)'
            . ':not(.onepagecheckout-index-index):not(.b2b-account-dashboard) .page-wrapper .awa-site-header '
            . '.awa-header-search-col :is(.block-search,.block-content,.awa-search-action-wrapper,'
            . 'form#search_mini_form,form.minisearch){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'width:100%!important;margin:0!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body:not(.checkout-index-index)'
            . ':not(.onepagecheckout-index-index):not(.b2b-account-dashboard) .page-wrapper .awa-site-header '
            . '.awa-header-search-col form#search_mini_form :is(.field.search,.field.search .control,.actions),'
            . 'html body#html-body#html-body#html-body#html-body#html-body:not(.checkout-index-index)'
            . ':not(.onepagecheckout-index-index):not(.b2b-account-dashboard) .page-wrapper .awa-site-header '
            . '.awa-header-search-col form#search_mini_form :is(input#search,button.action.search){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'line-height:1.25!important;box-sizing:border-box!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .awa-header-search-col :is(.block-search .label,.block-search .block-title,.nested){'
            . 'display:none!important;visibility:hidden!important;height:0!important;max-height:0!important;'
            . 'overflow:hidden!important;pointer-events:none!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .awa-header-search-col .awa-search-helper-copy{'
            . 'display:none!important;visibility:hidden!important;height:0!important;max-height:0!important;'
            . 'margin:0!important;overflow:hidden!important;pointer-events:none!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt[data-awa-is-home="1"] '
            . ':is(.awa-header-account-prompt__link--register,.awa-header-account-prompt__separator){'
            . 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'overflow:hidden!important;margin:0!important;padding:0!important;pointer-events:none!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .awa-header-account-prompt__icon svg{'
            . 'width:24px!important;height:24px!important;display:block!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .awa-header-right-col{'
            . 'display:flex!important;align-items:center!important;gap:clamp(8px,1.2vw,14px)!important}'
            . '}';
    }

    public static function verticalMenuTerminalRules(): string
    {
        return '@media (min-width:992px){'
            . 'html body#html-body .page-wrapper .awa-site-header .navigation.verticalmenu .awa-vmenu-search-wrap{'
            . 'position:relative!important;display:flex!important;align-items:center!important;width:100%!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .navigation.verticalmenu .awa-vmenu-search-icon{'
            . 'position:absolute!important;left:10px!important;top:50%!important;transform:translateY(-50%)!important;'
            . 'width:14px!important;height:14px!important;max-width:14px!important;max-height:14px!important;'
            . 'flex:0 0 14px!important;pointer-events:none!important;overflow:hidden!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .navigation.verticalmenu .awa-vmenu-search-icon svg{'
            . 'display:block!important;width:14px!important;height:14px!important;'
            . 'max-width:14px!important;max-height:14px!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .navigation.verticalmenu .awa-vmenu-search-input{'
            . 'width:100%!important;min-height:34px!important;height:auto!important;max-height:none!important;'
            . 'padding:7px 10px 7px 32px!important;font-size:12.5px!important;box-sizing:border-box!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .navigation.verticalmenu '
            . '[data-role="awa-vmenu-search-row"]{display:block!important;list-style:none!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .navigation.verticalmenu.side-verticalmenu '
            . '> ul.togge-menu.list-category-dropdown:is(.vmm-open,.menu-open,[aria-hidden="false"]){'
            . 'position:absolute!important;inset-block-start:100%!important;inset-inline-start:0!important;'
            . 'z-index:100100!important;width:290px!important;min-width:290px!important;max-width:290px!important;'
            . 'overflow-x:hidden!important;overflow-y:auto!important;box-sizing:border-box!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .navigation.verticalmenu.side-verticalmenu '
            . '> ul.togge-menu.list-category-dropdown:is(.vmm-open,.menu-open,[aria-hidden="false"]) svg:not('
            . '.awa-vmenu-trigger-icon svg,.awa-vmenu-search-icon svg,.awa-vmenu-search-empty-icon svg'
            . '){max-width:20px!important;max-height:20px!important}'
            . '}';
    }

    public static function headerStickyShellRules(): string
    {
        return '@media (min-width:768px) and (max-width:991px){'
            . 'html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky,'
            . 'html body#html-body .page-wrapper #header .header-wrapper-sticky{'
            . 'height:auto!important;min-height:96px!important;max-height:none!important;'
            . 'overflow:visible!important;contain:none!important;padding-block:0!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body:has(.awa-site-header){scroll-padding-block-start:128px!important}'
            . 'html body#html-body .awa-site-header #awa-main-content{scroll-margin-block-start:128px!important}'
            . 'html{scroll-padding-top:128px!important}'
            . ':root{--awa-header-scroll-offset:128px!important;--awa-header-site-shell-h:128px!important;'
            . '--awa-header-sticky-h:96px!important}'
            . '}'
            // 2026-07-17 Round4: offsets 136/104 estavam stale — sticky real = 68+48=116
            // (evidência CDP: scrollPaddingTop 136 vs wrapH 116). SSOT via tokens.
            . '@media (min-width:992px){'
            . ':root{--awa-header-main-row-h:68px;--awa-header-nav-h:48px;'
            . '--awa-header-scroll-offset:calc(var(--awa-header-main-row-h) + var(--awa-header-nav-h))!important;'
            . '--awa-header-site-shell-h:calc(var(--awa-header-main-row-h) + var(--awa-header-nav-h))!important;'
            . '--awa-header-sticky-h:calc(var(--awa-header-main-row-h) + var(--awa-header-nav-h))!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky,'
            . 'html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky.is-sticky,'
            . 'html body#html-body .page-wrapper #header .header-wrapper-sticky,'
            . 'html body#html-body .page-wrapper #header .header-wrapper-sticky.is-sticky{'
            . 'overflow:visible!important;contain:none!important;padding-block:0!important;'
            . 'box-sizing:border-box!important;'
            // Round5: vencer leftovers 124px (vtex-clean/polish/home preload)
            . 'height:var(--awa-header-sticky-h)!important;'
            . 'min-height:var(--awa-header-sticky-h)!important;'
            . 'max-height:var(--awa-header-sticky-h)!important}'
            // Round5: align-grid usa #html-body×2/3 + min-height:var(--awa-header-stack-h)
            // (124px). Precisa de especificidade ≥ para colapsar o shell no sticky.
            . 'html body#html-body#html-body#html-body.awa-header-is-sticky .page-wrapper .awa-site-header,'
            . 'html body#html-body#html-body#html-body.awa-header-is-sticky .page-wrapper .awa-site-header[data-awa-header-mode="default"]{'
            . '--awa-header-stack-h:var(--awa-header-sticky-h)!important;'
            . 'min-height:0!important;height:auto!important;max-height:none!important}'
            . 'html body#html-body:has(.awa-site-header){scroll-padding-block-start:var(--awa-header-scroll-offset)!important}'
            . 'html body#html-body .awa-site-header #awa-main-content{scroll-margin-block-start:var(--awa-header-scroll-offset)!important}'
            . 'html{scroll-padding-top:var(--awa-header-scroll-offset)!important}'
            . '}';
    }

    public static function mobileHeaderSearchLayoutRules(): string
    {
        return '';
    }

    /**
     * Mobile ≤767 — stack 112px (32 promo + 80 main). Padding só no inner row.
     * Sem !important; escopo .awa-site-header apenas.
     */
    public static function mobileHeaderCompact112Rules(): string
    {
        $root = '.awa-site-header[data-awa-header-mode="default"]';
        $sticky = $root . ' .header-wrapper-sticky';
        $inner = $sticky . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';

        return '@media (max-width:767px){'
            . $root . '{'
            . '--awa-header-promo-h:44px;--awa-header-main-row-h:96px;--awa-header-shell-pad:12px;'
            . '--awa-header-stack-h:calc(var(--awa-header-promo-h)+var(--awa-header-main-row-h));'
            . '--awa-header-scroll-offset:140px;--awa-header-sticky-h:96px'
            . '}'
            . $root . ' .header-container:has(.awa-b2b-promo-bar),'
            . $root . ' .header-container:has(.awa-b2b-promo-bar) .header-content{'
            . 'height:var(--awa-header-promo-h);min-height:var(--awa-header-promo-h);'
            . 'max-height:var(--awa-header-promo-h);padding-block:0;margin-block:0;box-sizing:border-box'
            . '}'
            . $sticky . '{'
            . 'min-height:var(--awa-header-main-row-h);height:auto;max-height:none;'
            . 'padding-block:0;margin-block:0;box-sizing:border-box;overflow:visible'
            . '}'
            . $sticky . ' :is(.header.awa-main-header,.header-main,.header_main,.header-main>.container,.header_main>.container){'
            . 'display:block;height:var(--awa-header-main-row-h);min-height:var(--awa-header-main-row-h);'
            . 'max-height:var(--awa-header-main-row-h);margin:0;padding:0;border:0;'
            . 'box-sizing:border-box;overflow:visible'
            . '}'
            . $sticky . ' .header.awa-main-header{padding-block:0}'
            . $inner . '{'
            . 'display:grid;grid-template-areas:"toggle brand cart" "search search search";'
            . 'grid-template-columns:44px minmax(0,1fr) 44px;grid-template-rows:44px 44px;gap:4px 8px;'
            . 'width:100%;max-width:min(100%,1280px);margin-inline:auto;'
            . 'padding-inline:var(--awa-header-shell-pad);padding-block:0;'
            . 'height:var(--awa-header-main-row-h);min-height:var(--awa-header-main-row-h);'
            . 'max-height:var(--awa-header-main-row-h);box-sizing:border-box;overflow:visible'
            . '}'
            . $sticky . ' .awa-header-primary-row{display:contents}'
            . $sticky . ' .awa-header-right-col{display:contents}'
            . $sticky . ' :is(.awa-header-mobile-toggle,.action.nav-toggle,[data-action="toggle-nav"]){'
            . 'grid-area:toggle;width:44px;min-width:44px;height:44px;min-height:44px;'
            . 'margin:0;padding:0;align-self:center;justify-self:start'
            . '}'
            . $sticky . ' :is(.awa-header-brand-cell,.col-md-2.awa-header-brand){'
            . 'grid-area:brand;align-self:center;justify-self:center;'
            . 'height:44px;max-height:44px;margin:0;padding:0;overflow:visible'
            . '}'
            . $sticky . ' :is(.awa-header-brand-cell,.col-md-2.awa-header-brand) :is(.logo,.logo a){'
            . 'display:flex;align-items:center;justify-content:flex-start;'
            . 'height:44px;margin:0;padding:0'
            . '}'
            . $sticky . ' .awa-header-brand-cell .logo img{'
            . 'height:32px;max-height:32px;width:auto;margin:0;padding:0;'
            . 'object-fit:contain;object-position:left center'
            . '}'
            . $sticky . ' .awa-header-search-col{'
            . 'grid-area:search;grid-column:1/-1;width:100%;min-width:0;'
            . 'height:44px;min-height:44px;max-height:44px;margin:0;padding:0'
            . '}'
            . $sticky . ' .awa-header-search-col :is(.block-search,.block-content){'
            . 'width:100%;height:44px;min-height:44px;max-height:44px;margin:0;padding:0'
            . '}'
            . $sticky . ' .awa-header-search-col form#search_mini_form{'
            . 'display:grid;grid-template-columns:minmax(0,1fr) 44px;'
            . 'grid-template-areas:"field submit";width:100%;height:44px;min-height:44px;'
            . 'max-height:44px;margin:0;padding:0;box-sizing:border-box;overflow:hidden;'
            . 'background:var(--awa-bg,#fff);border:1px solid var(--awa-border);border-radius:8px'
            . '}'
            . $sticky . ' .awa-header-search-col form#search_mini_form :is(.field.search,.field.search .control){'
            . 'grid-area:field;display:flex;width:100%;height:44px;min-height:44px;margin:0;padding:0'
            . '}'
            . $sticky . ' .awa-header-search-col form#search_mini_form input#search{'
            . 'width:100%;height:44px;min-height:44px;box-sizing:border-box;padding:0 8px;font-size:16px'
            . '}'
            . $sticky . ' .awa-header-search-col form#search_mini_form .actions{'
            . 'grid-area:submit;display:flex;width:44px;min-width:44px;height:44px;margin:0;padding:0'
            . '}'
            . $sticky . ' .awa-header-search-col form#search_mini_form .action.search{'
            . 'width:44px;min-width:44px;height:44px;min-height:44px;padding:0'
            . '}'
            . $sticky . ' :is(.awa-header-minicart,.mini-cart-wrapper,.minicart-wrapper){'
            . 'grid-area:cart;width:44px;min-width:44px;height:44px;min-height:44px;'
            . 'align-self:center;justify-self:end;margin:0;padding:0'
            . '}'
            . $sticky . ' :is(.awa-header-minicart,.mini-cart-wrapper,.minicart-wrapper) '
            . ':is(.action.showcart,.showcart.header-mini-cart,.awa-header-cart-fallback){'
            . 'width:44px;min-width:44px;height:44px;min-height:44px;'
            . 'display:inline-flex;align-items:center;justify-content:center;margin:0;padding:0'
            . '}'
            . $sticky . ' :is(.awa-header-account-prompt,.awa-b2b-mode-badge,.awa-header-account-nav){'
            . 'display:none;visibility:hidden;width:0;height:0;overflow:hidden;'
            . 'pointer-events:none;margin:0;padding:0'
            . '}'
            . $root . ' .header-control.awa-nav-bar{'
            . 'height:0;min-height:0;max-height:0;margin:0;padding:0;border:0;'
            . 'overflow:hidden;visibility:hidden;pointer-events:none'
            . '}'
            . ':root{--awa-header-scroll-offset:140px;--awa-header-sticky-h:96px}'
            . 'html{scroll-padding-top:140px}'
            . 'html body#html-body:has(.awa-site-header){scroll-padding-block-start:140px}'
            . 'html body#html-body .awa-site-header #awa-main-content{scroll-margin-block-start:140px}'
            . '}';
    }


    public static function visualCrawlSystemicRules(): string
    {
        return 'html body#html-body .page-wrapper .grid-mode-show-type-products a,'
            . 'html body#html-body .page-wrapper :is(.awa-b2b-gate-card__action,.b2b-login-to-see-price .price-label a,.b2b-login-to-see-price a),'
            . 'html body#html-body .page-wrapper div.b2b-login-to-see-price.price-box span.price-label>a,'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(.awa-footer-section__toggle,.velaFooterLinks a,.awa-footer-atendimento__actions a,.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link,.awa-seal),'
            . 'html body#html-body .page-wrapper .block-search .action.search{'
            . 'align-items:center!important;box-sizing:border-box!important;display:inline-flex!important;'
            . 'justify-content:center!important;line-height:1.2!important;min-height:44px!important;'
            . 'min-width:44px!important;text-align:center!important;text-decoration:none!important}'
            . 'html body#html-body .page-wrapper .grid-mode-show-type-products a,'
            . 'html body#html-body .page-wrapper .block-search .action.search{height:44px!important;width:44px!important}'
            . 'html body#html-body .page-wrapper :is(.awa-b2b-gate-card__action,.b2b-login-to-see-price .price-label a,.b2b-login-to-see-price a){padding:8px 14px!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(.awa-footer-section__toggle,.velaFooterLinks a,.awa-footer-atendimento__actions a,.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link){padding-block:7px!important}'
            . 'html body#html-body .page-wrapper .awa-category-hero__title{background-color:rgba(0,0,0,.56)!important;'
            . 'border-radius:var(--awa-radius-sm,8px)!important;color:rgb(255,255,255)!important;'
            . 'display:inline-block!important;padding:6px 12px!important;text-shadow:none!important}'
            . 'html body#html-body .page-wrapper :is(.awa-b2b-sku__label,.awa-nav-quick-links__item,.awa-nav-quick-links__link,.product-info-stock-sku .stock.available span){'
            . 'color:var(--awa-ink,oklch(22% .02 20))!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-trust-item{'
            . 'background-color:transparent!important;color:var(--awa-text-muted,oklch(45% .02 20))!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-atendimento__store-badge{'
            . 'background-color:rgba(183,51,55,.08)!important;color:var(--awa-primary,oklch(48% .14 20))!important;text-shadow:none!important}'
            . 'html body#html-body .page-wrapper .awa-pdp-whatsapp-cta{background-color:rgba(0,96,48,.92)!important;color:rgb(255,255,255)!important}'
            . 'html body#html-body .page-wrapper .awa-pdp-whatsapp-cta span{color:rgb(255,255,255)!important}'
            . 'html body#html-body .page-wrapper .awa-owl-nav__btn{background-color:rgba(0,0,0,.72)!important;color:rgb(255,255,255)!important;border-color:rgba(255,255,255,.32)!important}'
            . 'html body#html-body .page-wrapper .awa-owl-nav__btn svg{stroke:currentColor!important}'
            . 'html body#html-body .page-wrapper .awa-category-hero__eyebrow{background-color:rgba(0,0,0,.72)!important;border-radius:999px!important;color:rgb(255,255,255)!important;display:inline-block!important;padding:4px 10px!important;text-shadow:none!important}'
            . 'html body#html-body .page-wrapper :is(.b2b-login-forgot__text,.b2b-login-divider span){background-color:rgb(255,255,255)!important;color:rgb(51,51,51)!important;text-shadow:none!important}'
            . 'html body#html-body .page-wrapper .awa-sr-only{background-color:transparent!important;color:transparent!important}';
    }

    /**
     * Tipografia trust bar + atendimento (extraído de impeccableSurfaceRules).
     */
    public static function footerTrustSurfaceRules(): string
    {
        return 'html body#html-body .page-wrapper .page_footer :is(h2,h3).awa-newsletter-title{'
            . 'color:var(--awa-ink,oklch(22% .02 20))!important}'
            // BUG-CONTRAST-FOOTER-TRUST (2026-07-06): ver comentario em impeccableSurfaceRules().
            . 'html body#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-copy strong,'
            . 'html body#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-item .awa-footer-trust-copy strong{'
            . 'color:var(--awa-white,#fff)!important}'
            . 'html body#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-copy span{'
            . 'color:color-mix(in srgb,#fff 88%,transparent)!important}'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento p.awa-footer-atendimento__label,'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento p.awa-footer-atendimento__label--social,'
            . 'html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__label,'
            . 'html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__label--social{'
            . 'color:oklch(45% .02 20)!important}'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store{'
            . 'background:transparent!important;border:0!important;'
            . 'padding:8px 0!important;border-radius:0!important}'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store '
            . 'p.awa-footer-atendimento__store-name,'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store '
            . 'p.awa-footer-atendimento__store-address,'
            . 'html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__store-name,'
            . 'html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__store-address,'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento__store-name,'
            . 'html body#html-body .page-wrapper .page_footer .awa-footer-atendimento__store-address{'
            . 'color:oklch(22% .01 20)!important}';
    }


    /**
     * Neutraliza containment legado do footer para evitar placeholder/altura fantasma.
     */
    public static function footerStructuralContainmentRules(): string
    {
        $scope = 'html body#html-body#html-body#html-body .page-wrapper .page-footer .page_footer';

        return $scope . ','
            . $scope . ' #footer,'
            . $scope . ' .footer-bottom,'
            . $scope . ' .awa-footer-devby,'
            . $scope . ' .awa-footer-categories-expand{'
            . 'content-visibility:visible!important;contain:none!important;'
            . 'contain-intrinsic-size:unset!important;box-sizing:border-box!important}'
            . $scope . '{display:flow-root!important;height:auto!important;min-height:0!important;'
            . 'max-height:none!important;overflow:visible!important}'
            . $scope . ' .awa-footer-devby{max-height:64px!important;overflow:hidden!important}';
    }


    /**
     * Compactacao do footer em CSS para remover dependencia de estilos inline tardios.
     */
    public static function footerNoJsCompactRules(): string
    {
        $scope = 'html body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)';
        $shell = 'html body#html-body#html-body#html-body#html-body#html-body .page-wrapper footer.page-footer';
        $root = $shell . ' .page_footer';
        $hard = $root . ' #footer.footer-container';

        return '/*awa-footer-no-js-compact-v1*/'
            . $scope . ','
            . $scope . ' #footer,'
            . $scope . ' .footer-bottom{'
            . 'content-visibility:visible!important;contain:none!important;contain-intrinsic-size:unset!important;'
            . 'height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important}'
            . $scope . ' a{height:auto!important;min-width:0!important;overflow-wrap:anywhere!important}'
            . $scope . ' .footer-bottom{box-sizing:border-box!important;padding-block:16px!important;padding-inline:0!important}'
            . $scope . ' .footer-bottom-inner{'
            . 'box-sizing:border-box!important;display:grid!important;grid-template-columns:minmax(0,1fr)!important;'
            . 'gap:8px!important;height:auto!important;min-height:0!important;max-height:none!important;padding:0!important}'
            . $scope . ' .footer-bottom > .container{'
            . 'display:block!important;height:auto!important;margin-inline:auto!important;max-width:100%!important;'
            . 'min-height:0!important;padding:0!important;width:min(100%,1280px)!important}'
            . $scope . ' :is(.velaFooterLinks a,.awa-footer-atendimento__actions a){'
            . 'height:auto!important;min-height:0!important;min-width:0!important;padding-block:4px!important}'
            . $scope . ' :is(.velaFooterTitle,.awa-footer-section__toggle){'
            . 'height:auto!important;min-height:0!important;margin-bottom:4px!important;padding-bottom:4px!important}'
            . $scope . ' :is(.awa-footer-atendimento,.awa-footer-atendimento .velaContent){'
            . 'gap:4px!important;height:auto!important;min-height:0!important;max-height:none!important}'
            . $scope . ' :is(.awa-footer-atendimento__phone,.awa-footer-atendimento__email,'
            . '.awa-footer-atendimento__store-address){'
            . 'height:auto!important;min-height:0!important;margin:0 0 4px!important;'
            . 'padding:0!important;line-height:1.25!important}'
            . $scope . ' :is(.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a){'
            . 'height:auto!important;min-height:0!important;margin:0!important;padding:4px 0!important}'
            . $scope . ' .awa-footer-atendimento__store{'
            . 'height:auto!important;min-height:0!important;margin-bottom:8px!important;padding:8px!important;gap:2px!important}'
            . $scope . ' .awa-footer-categories-expand{'
            . 'height:auto!important;min-height:0!important;max-height:none!important;margin-top:8px!important;'
            . 'padding:8px 0 4px!important}'
            . $scope . ' .awa-footer-categories-expand__inner{'
            . 'display:grid!important;grid-template-columns:auto minmax(0,1fr)!important;align-items:center!important;'
            . 'gap:10px!important;height:auto!important;min-height:0!important;max-height:none!important;padding:0!important}'
            . $scope . ' .awa-footer-categories-expand__panel{'
            . 'display:block!important;width:100%!important;min-width:0!important;height:auto!important;'
            . 'min-height:0!important;max-height:none!important;padding:0!important}'
            . $scope . ' .awa-footer-categories-list{'
            . 'display:flex!important;flex-wrap:wrap!important;gap:6px!important;width:100%!important;'
            . 'height:auto!important;min-height:0!important;margin:0!important;padding:0!important;list-style:none!important}'
            . $scope . ' .awa-footer-categories-list li{list-style:none!important;margin:0!important;padding:0!important}'
            . $scope . ' .awa-footer-categories-list a{'
            . 'height:32px!important;min-height:32px!important;padding:6px 10px!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright{'
            . 'box-sizing:border-box!important;grid-column:1 / -1!important;flex:0 0 100%!important;width:100%!important;'
            . 'max-width:none!important;height:auto!important;margin-top:0!important;padding:6px 0 0!important;'
            . 'border-top:1px solid var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas))!important;'
            . 'text-align:left!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright :is(p,.awa-footer-copyright__legal,'
            . '.awa-footer-copyright__disclaimer){'
            . 'box-sizing:border-box!important;width:100%!important;max-width:none!important;margin:0!important;'
            . 'padding:0!important;font-size:11px!important;line-height:1.3!important}'
            . $scope . ' .footer-bottom .awa-footer-copyright__disclaimer{margin-top:2px!important}'
            . $scope . ' .awa-footer-devby{'
            . 'block-size:56px!important;height:56px!important;min-height:0!important;max-height:56px!important;'
            . 'padding:6px 0!important;overflow:hidden!important}'
            . $scope . ' .awa-footer-devby .container,'
            . $scope . ' .awa-footer-devby__inner{'
            . 'block-size:36px!important;height:36px!important;min-height:36px!important;max-height:36px!important}'
            . '@media (max-width:767px){'
            . $scope . ' a{min-height:44px!important}'
            . $scope . ' #footer.footer-container{padding:10px 16px 8px!important}'
            . $scope . ' .awa-footer-trust-bar{padding:12px 0!important;margin-bottom:8px!important}'
            . $scope . ' .awa-footer-trust-grid{gap:8px!important;padding:8px 0!important}'
            . $scope . ' .awa-footer-trust-item{'
            . 'display:grid!important;grid-template-columns:36px minmax(0,1fr)!important;align-items:center!important;'
            . 'gap:10px!important;min-height:56px!important;height:auto!important;padding:8px 10px!important}'
            . $scope . ' .awa-footer-trust-icon{width:36px!important;height:36px!important;min-width:36px!important}'
            . $scope . ' .awa-footer-trust-copy{'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'gap:0!important;width:100%!important}'
            . $scope . ' .awa-footer-newsletter{margin-bottom:8px!important;padding:10px 12px!important}'
            . $scope . ' :is(.awa-newsletter-wrapper,.awa-newsletter-form-container,.newsletter-footer,'
            . '.form.subscribe,.field.newsletter){'
            . 'box-sizing:border-box!important;height:auto!important;min-height:0!important;max-height:none!important;'
            . 'margin:0!important;padding:0!important;max-width:min(100%,calc(100vw - 32px))!important}'
            . $scope . ' :is(.awa-footer-newsletter,.awa-footer-newsletter>.container,.awa-newsletter-wrapper){'
            . 'box-sizing:border-box!important;width:100%!important;max-width:min(100%,calc(100vw - 32px))!important;'
            . 'margin-inline:auto!important;overflow:visible!important}'
            . $scope . ' :is(.awa-newsletter-wrapper,.form.subscribe){gap:8px!important}'
            . $scope . ' .awa-newsletter-info{'
            . 'display:grid!important;grid-template-columns:40px minmax(0,1fr)!important;align-items:center!important;'
            . 'gap:10px!important;height:auto!important;min-height:0!important}'
            . $scope . ' .awa-newsletter-icon{width:40px!important;height:40px!important;margin:0!important}'
            . $scope . ' .awa-newsletter-title{margin-bottom:4px!important;font-size:15px!important;line-height:1.25!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer #newsletter-validate-detail.form.subscribe{'
            . 'display:block!important;width:100%!important;height:auto!important;min-height:0!important;overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer #newsletter-validate-detail .field.newsletter{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) 118px!important;align-items:stretch!important;'
            . 'gap:8px!important;height:44px!important;min-height:44px!important;width:100%!important;min-width:0!important;overflow:hidden!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer #newsletter-validate-detail :is(.control,.actions){'
            . 'height:44px!important;min-height:44px!important;min-width:0!important;width:auto!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer #newsletter-validate-detail input[type=email]{'
            . 'height:44px!important;min-height:44px!important;width:100%!important;min-width:0!important;font-size:14px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer #newsletter-validate-detail button.action.subscribe{'
            . 'height:44px!important;min-height:44px!important;width:118px!important;max-width:118px!important;'
            . 'padding-inline:8px!important;white-space:normal!important;font-size:12px!important;line-height:1.15!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer .awa-footer-trust-grid{'
            . 'display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer .awa-footer-trust-item{'
            . 'display:grid!important;grid-template-columns:1fr!important;justify-items:start!important;align-content:start!important;'
            . 'min-height:96px!important;height:auto!important;padding:10px!important;gap:6px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer .awa-footer-trust-icon{'
            . 'width:32px!important;height:32px!important;min-width:32px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer .awa-footer-trust-copy{gap:2px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer .awa-footer-trust-copy strong{'
            . 'font-size:12px!important;line-height:1.25!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer .page_footer .awa-footer-trust-copy span{'
            . 'font-size:11px!important;line-height:1.3!important}'
            . $scope . ' #footer.footer-container>.container{padding:10px 16px!important}'
            . $scope . ' #footer.footer-container .rowFlexMargin{gap:8px!important;padding:8px 0!important}'
            . $scope . ' .vela-content.velaFooterMenu{height:auto!important;padding:4px 8px!important}'
            . $scope . ' .velaFooterTitle{height:auto!important;min-height:52px!important;padding:4px 0!important}'
            . $scope . ' .awa-footer-categories-expand{'
            . 'height:auto!important;min-height:0!important;max-height:none!important;margin-top:8px!important;'
            . 'padding:8px 0 4px!important}'
            . $scope . ' .awa-footer-categories-expand__panel[hidden]{'
            . 'display:none!important;height:0!important;min-height:0!important;max-height:0!important;'
            . 'padding:0!important;overflow:hidden!important}'
            . $scope . ' .awa-footer-categories-expand:has(.awa-footer-categories-expand__panel[hidden]){'
            . 'max-height:64px!important;overflow:hidden!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-pay-logos,.awa-footer-sec-seals){'
            . 'gap:8px!important;margin:0!important;padding:0!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-pay-logos,.awa-footer-sec-seals)>li{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:auto!important;min-width:44px!important;min-height:32px!important;margin:0!important;'
            . 'padding:0!important;list-style:none!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright{'
            . 'width:100%!important;max-width:none!important;margin:8px 0 0!important;padding:8px 0 0!important;'
            . 'text-align:left!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright :is(p,.awa-footer-copyright__legal,'
            . '.awa-footer-copyright__disclaimer){'
            . 'width:100%!important;max-width:none!important;margin:0!important;padding:0!important;'
            . 'font-size:10.5px!important;line-height:1.35!important}'
            . $scope . ' .footer-bottom .awa-footer-copyright__disclaimer{margin-top:2px!important}'
            . $scope . ' .awa-footer-devby{'
            . 'height:auto!important;min-height:0!important;max-height:64px!important;padding:8px 0!important;'
            . 'overflow:hidden!important}'
            . '}'
            . '@media (max-width:575px){'
            . $scope . ' .awa-footer-pay-logos{grid-template-columns:repeat(4,minmax(40px,1fr))!important}'
            . '}'
            . '@media (min-width:768px){'
            . $hard . '{padding-block:8px!important}'
            . $root . ' .awa-footer-newsletter{padding-block:14px!important;margin-bottom:12px!important}'
            . $root . ' .awa-footer-trust-bar{padding-block:16px!important;margin-bottom:16px!important}'
            . $root . ' .awa-footer-trust-grid{padding-block:10px!important;gap:12px!important}'
            . $root . ' .awa-footer-trust-item{min-height:48px!important;padding-block:4px!important}'
            . $hard . ' .row.rowFlexMargin{gap:12px!important;padding-block:4px!important}'
            . $hard . ' .vela-content.velaFooterMenu{padding:6px 8px!important}'
            . $hard . ' .velaFooterTitle{height:auto!important;min-height:0!important;margin-bottom:4px!important;padding-bottom:4px!important}'
            . $hard . ' .awa-footer-section__toggle{align-items:center!important;display:flex!important;min-height:0!important;height:auto!important;margin-bottom:4px!important;padding:0!important}'
            . $hard . ' .awa-footer-atendimento .velaContent{gap:4px!important}'
            . $hard . ' .awa-footer-atendimento :is(.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a){'
            . 'display:inline-flex!important;align-items:center!important;height:auto!important;min-height:0!important;padding:4px 0!important}'
            . $hard . ' .velaFooterLinks a{align-items:center!important;display:flex!important;min-height:0!important;padding-block:1px!important}'
            . $hard . ' .velaFooterLinks li{margin:0!important;padding:0!important}'
            . $hard . ' .awa-footer-atendimento :is(.awa-footer-atendimento__phone,.awa-footer-atendimento__email){'
            . 'height:auto!important;min-height:0!important;margin-bottom:4px!important}'
            . $hard . ' .awa-footer-atendimento__store{padding:8px!important;margin-bottom:6px!important;gap:2px!important}'
            . $hard . ' .awa-footer-atendimento__store-badge{margin:2px 0 4px!important;padding:3px 6px!important}'
            . $hard . ' .awa-footer-atendimento__actions{gap:4px!important}'
            . $hard . ' .awa-footer-pro__social{gap:8px!important;height:auto!important;min-height:44px!important}'
            . $hard . ' .awa-footer-pro__social-link{align-items:center!important;display:inline-flex!important;height:44px!important;justify-content:center!important;min-height:44px!important;min-width:44px!important;width:44px!important;padding:6px!important}'
            . $shell . ' .footer-bottom{padding-block:16px!important;padding-inline:0!important;margin-top:8px!important}'
            . $shell . ' .footer-bottom .footer-bottom-inner{gap:8px!important}'
            . '}';
    }

    /**
     * Touch targets e trust items planos (extraído de visualCrawlSystemicRules).
     */
    public static function footerTouchAndTrustRules(): string
    {
        return 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(.awa-footer-section__toggle,.velaFooterLinks a,.awa-footer-atendimento__actions a,.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link,.awa-seal){'
            . 'align-items:center!important;box-sizing:border-box!important;display:inline-flex!important;'
            . 'justify-content:flex-start!important;line-height:1.45!important;'
            . 'min-width:0!important;text-align:start!important;text-decoration:none!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(.awa-footer-section__toggle,.velaFooterLinks a,.awa-footer-atendimento__actions a,.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link){'
            . 'padding-block:4px!important}'
            . '@media (max-width:767px){'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(.awa-footer-section__toggle,.velaFooterLinks a,.awa-footer-atendimento__actions a,.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link,.awa-seal){'
            . 'min-height:44px!important;min-width:44px!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(.awa-footer-section__toggle,.velaFooterLinks a,.awa-footer-atendimento__actions a,.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link){'
            . 'padding-block:7px!important}'
            . '}'
            . '@media (min-width:768px){'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(.velaFooterLinks a,.awa-footer-atendimento__actions a,.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link){'
            . 'min-height:44px!important;min-width:0!important;padding-block:10px!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .velaFooterLinks li{margin:0!important;padding:0!important}'
            . '}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-trust-item{'
            . 'background-color:transparent!important;color:var(--awa-text-muted,oklch(45% .02 20))!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-atendimento__store-badge{'
            . 'background-color:rgba(183,51,55,.08)!important;color:var(--awa-primary,oklch(48% .14 20))!important;text-shadow:none!important}';
    }

    /**
     * Regras terminais do footer para páginas sem cascade-lock do header (PLP/PDP/checkout).
     */
    /**
     * Shell global do .page-wrapper + sticky header — mesma geometria/cor em home e catálogo.
     * Evidência: home (refine) usava overflow-x:clip; PLP (refine removido) usava visible.
     * Sticky home resolvia #f7f7f7 vs PLP #fff ao navegar.
     */
    public static function crossPageShellLockRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body';
        $pw = $root . ' .page-wrapper';
        $sticky = $pw . ' :is(.header-wrapper-sticky,.header-wrapper-sticky.is-sticky,'
            . '.awa-site-header .header-wrapper-sticky,.awa-site-header .header-wrapper-sticky.is-sticky)';
        // Surface canônica branca (sem chroma rosa). Antes: oklch(0.992 0.006 27) tingia cards.
        $surface = '#fff';

        return '/*awa-crosspage-shell-lock-r68*/'
            . $root . '{--awa-bg:' . $surface . ';--awa-bg-surface:' . $surface . '}'
            . $pw . '{'
            . 'overflow-x:clip!important;overflow-y:visible!important;'
            . 'background:' . $surface . '!important;background-color:' . $surface . '!important;'
            . 'max-width:100%!important}'
            . $sticky . '{'
            . 'background:' . $surface . '!important;background-color:' . $surface . '!important;'
            . 'backdrop-filter:none!important;-webkit-backdrop-filter:none!important}'
            // Importante em awa-fixes: o contrato legado dessa camada vence regras unlayered.
            . '@layer awa-fixes{'
            /* ThemeOption custom_default.css pinta `button` de vermelho sólido
               (CDP: rgb(183,51,55), pad 8px 50px). Toggles da sidebar de conta
               devem ser rótulos, não CTA. !important layered vence `button`. */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .account-nav-section__toggle{'
            . 'appearance:none!important;-webkit-appearance:none!important;'
            . 'display:flex!important;align-items:center!important;justify-content:space-between!important;'
            . 'width:100%!important;max-width:100%!important;box-sizing:border-box!important;'
            . 'min-height:44px!important;margin:4px 0 0!important;padding:8px 12px!important;'
            . 'border:0!important;border-radius:4px!important;box-shadow:none!important;'
            . 'background:color-mix(in srgb,var(--awa-primary) 8%,var(--awa-bg))!important;'
            . 'background-color:color-mix(in srgb,var(--awa-primary) 8%,var(--awa-bg))!important;'
            . 'background-image:none!important;'
            . 'color:var(--awa-text)!important;font-size:13px!important;font-weight:600!important;'
            . 'text-align:left!important;cursor:pointer!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .account-nav-section__toggle:is(:hover,:focus-visible){'
            . 'background:color-mix(in srgb,var(--awa-primary) 12%,var(--awa-bg))!important;'
            . 'background-color:color-mix(in srgb,var(--awa-primary) 12%,var(--awa-bg))!important;'
            . 'color:var(--awa-text)!important;border-color:transparent!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .account-nav-section__toggle::after{'
            . 'content:""!important;display:block!important;width:0.45rem!important;height:0.45rem!important;'
            . 'border:0!important;border-right:2px solid currentColor!important;'
            . 'border-bottom:2px solid currentColor!important;background:transparent!important;'
            . 'transform:rotate(45deg)!important;flex-shrink:0!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .account-nav-section--accordion.is-open .account-nav-section__toggle::after{'
            . 'transform:rotate(-135deg)!important}'
            /* ThemeOption `a { color:#b73337 !important }` (unlayered). Links de
               texto no main da conta não são CTA. Hover volta ao primary. */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper :is(.column.main,.col-main,.b2b-dashboard) a:not(.action):not(.button){'
            . 'color:var(--awa-text)!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper :is(.column.main,.col-main,.b2b-dashboard) a:not(.action):not(.button):is(:hover,:focus-visible){'
            . 'color:var(--awa-primary)!important}'
            . '@media(min-width:768px){'
            . $sticky . '{box-sizing:border-box!important;height:116px!important;'
            . 'min-height:116px!important;max-height:116px!important}'
            /* Conta B2B: nav display:none; 116 layered deixava vão 48px (header 68 vs sticky 116). */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .awa-site-header.awa-account-header'
            . ' :is(.header-wrapper-sticky,.header-wrapper-sticky.is-sticky){'
            . 'height:68px!important;min-height:68px!important;max-height:68px!important;'
            . 'padding-block:0!important;padding-top:0!important;padding-bottom:0!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .awa-site-header.awa-account-header'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important;'
            . 'box-sizing:border-box!important}'
            . '}'
            . '@media(max-width:767px){'
            . $sticky . '{box-sizing:border-box!important;height:96px!important;'
            . 'min-height:96px!important;max-height:96px!important}'
            /* Conta: shell-final trava .awa-account-header e .awa-main-header em 68px
               em todas as viewports. Sticky layered já é 96 (2 linhas), mas o pai
               permanece 68 no fluxo — breadcrumbs y=84 sob a busca (overlap 14px). */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .awa-site-header.awa-account-header{'
            . 'height:96px!important;min-height:96px!important;max-height:96px!important;'
            . '--awa-header-sticky-h:96px!important;--awa-header-site-h:96px!important;'
            . '--awa-header-main-row-h:96px!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .awa-site-header.awa-account-header'
            . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap){'
            . 'height:96px!important;min-height:96px!important;max-height:96px!important}'
            /* Conta B2B mobile: .b2b-status-panel ("Olá, Nome") fica visível na célula
               cart de 44px (audit-fixes não injeta nesta rota). CDP 375: col=112,
               logoR=254, panelL=247, overlap=7. Bottom-nav Conta + saudação do painel
               cobrem identidade — ocultar o trigger no header. !important: vence
               headerB2bPanelCoexistence + first-paint 4×ID unlayered. */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .awa-site-header.awa-account-header .header-wrapper-sticky .b2b-status-panel{'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important;'
            . 'width:0!important;min-width:0!important;max-width:0!important;'
            . 'height:0!important;min-height:0!important;max-height:0!important;'
            . 'overflow:hidden!important;margin:0!important;padding:0!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .awa-site-header.awa-account-header .header-wrapper-sticky .awa-header-right-col{'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'gap:0!important;overflow:visible!important;justify-self:end!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper .awa-site-header.awa-account-header'
            . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            /* CDP 375: .columns pad 12px + page-main 16 → col-main x=28. Search x=16. */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper :is(.page-main,#maincontent) > .columns{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            /* AH: themes.css `.layout-2-col .col-md-3{margin-bottom:50px}` (unlayered).
               CDP 375 accordion fechado: wrapH=94, mb=50, gap=12 → dead=62, h1y=389.
               Live inject @layer awa-fixes: mb=0, dead=12, h1y=339. Desktop mb já era 0. */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper :is(.page-main,#maincontent) > .columns.layout-2-col'
            . ' > :is(.col-md-3,[class*="col-sm-3"],.col-lg-2){'
            . 'margin-bottom:0!important;margin-block-end:0!important}'
            /* AE/AF: nav-mobile.min.js em cache imutável ainda faz expanded=true
               por .current (navH=365, dashY=664 em 375). Conteúdo só com
               .awa-nav--user-open (bind no PHTML, independente do .min). */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper :is(.block-collapsible-nav,#block-collapsible-nav) > .content{'
            . 'display:none!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper :is(.block-collapsible-nav,#block-collapsible-nav).awa-nav--user-open > .content{'
            . 'display:block!important}'
            . '}}'
            /* Unlayered: ThemeOption `a{color}` é unlayered !important e vence
               a camada awa-fixes. 7×#html-body no main da conta. */
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper :is(.column.main,.col-main,.b2b-dashboard) a:not(.action):not(.button){'
            . 'color:var(--awa-text)!important}'
            . $root . ':is(.account,.b2b-account-shell,.awa-account-operational,.b2b-account-dashboard)'
            . ' .page-wrapper :is(.column.main,.col-main,.b2b-dashboard) a:not(.action):not(.button):is(:hover,:focus-visible){'
            . 'color:var(--awa-primary)!important}'
            /* AR: carrinho sem footer-terminal-lock. Form 480px + margin auto
               deixava leftover 159px (CDP 1286: right 1112 vs eixo 1247).
               Live inject max-width:none → right 1247, padRight 0. Unlayered
               para vencer Magento .form.subscribe (unlayered). */
            . '@media(min-width:768px){'
            . $root . ' .page-wrapper footer.page-footer :is(#newsletter-validate-detail,.form.subscribe){'
            . 'max-width:none!important;width:100%!important;margin:0!important;'
            . 'margin-inline:0!important;margin-left:0!important;margin-right:0!important}'
            . $root . ' .page-wrapper footer.page-footer .awa-newsletter-form-container{'
            . 'max-width:none!important}}';
    }

    /**
     * Superfície única do footer em todas as rotas (home/PLP/PDP/busca).
     * Evita soft-bg só na home + trust vermelho só fora da home + round10 transparente.
     */
    public static function footerCrossPageSurfaceRules(): string
    {
        /* FIX 12/10/2026 (FASE 2 + BYTES): o escopo era um HACK DE ESPECIFICIDADE com o
         * MESMO id repetido 8x (`html body#html-body#html-body#html-body#html-body#html-body
         * #html-body#html-body .page-wrapper ...`) — 160 caracteres REUSADOS em cada regra,
         * o que inflava este bloco para ~59KB de CSS INLINE no HTML (medido: o CSS inline
         * e 474KB dos 779KB da pagina).
         * O hack existia porque, SEM camadas, so a especificidade decidia entre os !important.
         * Com a ordem de @layer agora UNIFORME em todas as paginas (awa-layer-order.css), a
         * declaracao vai para `awa-cta-green` — a camada MAIS FORTE para !important — e o
         * escopo pode ser o seletor natural. MESMAS declaracoes, mesma intencao, ~4x menos bytes. */
        $scope = ':is(footer.page-footer,.page_footer,.page-footer)';

        return '@layer awa-cta-green{/*awa-footer-crosspage-surface-r63*/'
            . $scope . '{'
            . 'background:#fff!important;'
            . 'background-color:#fff!important;'
            . 'color:var(--awa-text,oklch(0.32 0.012 27))!important;'
            . 'border-top:0!important}'
            . $scope . ' :is(#footer,.footer-container,.footer.content,.vela-content,.velaFooterMenu){'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'background-image:none!important;color:var(--awa-text,oklch(0.32 0.012 27))!important}'
            . $scope . ' :is(.footer-bottom,.awa-footer-devby){'
            . 'background:#fff!important;'
            . 'background-color:#fff!important;'
            . 'border:0!important;border-radius:0!important;'
            . 'color:var(--awa-text,oklch(0.32 0.012 27))!important}'
            . $scope . ' section.awa-footer-categories-expand{'
            . 'background:#fff!important;'
            . 'background-color:#fff!important;'
            . 'border:0!important;border-top:0!important;border-bottom:0!important;'
            . 'box-shadow:none!important;color:var(--awa-text,oklch(0.32 0.012 27))!important}'
            . $scope . ' section.awa-footer-trust-bar,'
            . $scope . ' .awa-footer-trust-bar{'
            . 'display:block!important;background:var(--awa-primary,#b73337)!important;'
            . 'background-color:var(--awa-primary,#b73337)!important;color:#fff!important;'
            . 'border:0!important;box-shadow:none!important}'
            . $scope . ' .awa-footer-trust-bar .awa-footer-trust-copy strong{'
            . 'color:#fff!important}'
            . $scope . ' .awa-footer-trust-bar .awa-footer-trust-copy span{'
            . 'color:color-mix(in srgb,#fff 88%,transparent)!important}'
            . $scope . ' .awa-footer-trust-bar .awa-footer-trust-icon,'
            . $scope . ' .awa-footer-trust-bar .awa-footer-trust-icon svg{'
            . 'color:#fff!important;fill:currentColor!important}}';
    }

    public static function footerTerminalRules(): string
    {
        return self::footerStructuralContainmentRules()
            . self::footerTrustSurfaceRules()
            . self::footerTouchAndTrustRules()
            . self::footerLightLayoutRules()
            . self::footerBottomModernRules()
            . self::footerInteractionPolishRules()
            . self::footerAtendimentoPolishRules()
            . self::footerBusinessBandsRules()
            . self::footerSealImgPolishRules()
            . self::footerAlignShellRules()
            . self::footerNoJsCompactRules()
            . self::footerVtexNeutralTerminalRules()
            . self::footerCrossPageSurfaceRules()
            . self::footerCategoriesGridFillRules()
            . self::footerCategoriesDesktopLayoutRules()
            . self::footerDenseDesktopR67Rules()
            . self::footerDesktopTouch44R1Rules()
            . self::footerCategoriesWhiteR16Rules()
            . self::footerNewsletterWhiteR17Rules()
            . self::footerCopyrightFlatR20Rules()
            . self::footerAtendimentoStoreFlatR21Rules()
            . self::footerImpeccablePolishR66Rules()
            . self::footerContentAxisRules();
    }

    /**
     * r66 — Impeccable home audit (2026-08-04).
     * Typeset: LH>=1.35 em address/copyright; line length legal/disclaimer <=72ch.
     * Newsletter field: inset mínimo. Dropdown: sem hairline+wide shadow.
     * Trust contrast NÃO alterado (5.93:1 no bar vermelho; falso positivo Impeccable).
     */
    public static function footerImpeccablePolishR66Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(.page_footer,.page-footer)';
        $footerRoot = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer > .page_footer';
        $hdr = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header';

        return '/*§footer-impeccable-r66*/'
            . $scope . ' .awa-footer-atendimento__store-address,'
            . $scope . ' p.awa-footer-atendimento__store-address,'
            . $footerRoot . ' .awa-footer-atendimento__store-address{'
            . 'line-height:1.4!important;white-space:normal!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer,'
            . 'p.awa-footer-copyright__legal,p.awa-footer-copyright__disclaimer),'
            . $footerRoot . ' .awa-footer-bottom__copyright '
            . ':is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer,p.awa-footer-copyright__legal,p.awa-footer-copyright__disclaimer),'
            . $footerRoot . ' .footer-bottom :is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer){'
            . 'display:block!important;-webkit-box-orient:initial!important;-webkit-line-clamp:unset!important;'
            . 'line-height:1.4!important;max-width:min(72ch,100%)!important;'
            . 'max-height:none!important;overflow:visible!important;'
            . 'width:100%!important;margin-inline:auto!important;overflow-wrap:anywhere!important}'
            . '@media (min-width:992px){'
            . $footerRoot . ' .awa-footer-bottom__copyright '
            . ':is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer,p.awa-footer-copyright__legal,p.awa-footer-copyright__disclaimer),'
            . $footerRoot . ' .footer-bottom :is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer){'
            . 'display:block!important;-webkit-line-clamp:unset!important;'
            . 'line-height:1.4!important;max-height:none!important;overflow:visible!important;'
            . 'max-width:min(72ch,100%)!important}}'
            . $scope . ' #newsletter-validate-detail .field.newsletter{'
            . 'padding-block:0!important;padding-inline:0!important;box-sizing:border-box!important}'
            . $scope . ' .awa-footer-trust-copy strong{line-height:1.35!important}'
            . $scope . ' .awa-footer-trust-copy span{line-height:1.35!important}'
            . $hdr . ' .awa-account-dropdown__menu,'
            . $hdr . ' .awa-site-header[data-awa-header-mode="default"] .awa-account-dropdown__menu{'
            . 'border:0!important;border-width:0!important;border-style:none!important;'
            . 'box-shadow:0 4px 12px color-mix(in srgb,CanvasText 12%,transparent)!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header '
            . ':is(#awa-b2b-promo-bar,.awa-b2b-promo-bar) '
            . ':is(.awa-b2b-promo-bar__lead,.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__lead-short,'
            . '.awa-b2b-promo-bar__text){line-height:1.4!important}';
    }

    /**
     * 2026-08-27 — eixo canónico do footer.
     *
     * Faixas (trust, newsletter, colunas, categorias, bottom) full-bleed;
     * miolo no mesmo shell do header: 1280px + --awa-page-main-pad.
     * !important terminal: vence r67–r72, align-shell, visual-bugfix e
     * estilos inline legados. Sem hex de marca — só tokens var(--awa-*).
     */
    public static function footerContentAxisRules(): string
    {
        $any = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)';
        $root = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer';
        $inner = $root . ' > .page_footer';
        $pad = 'var(--awa-page-main-pad,clamp(16px,3vw,24px))';
        $max = '1280px';
        $bleed = 'box-sizing:border-box!important;max-width:none!important;width:100%!important;'
            . 'margin-inline:0!important;margin-left:0!important;margin-right:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important';
        $shell = 'box-sizing:border-box!important;width:min(100%,' . $max . ')!important;'
            . 'max-width:min(100%,' . $max . ')!important;margin-inline:auto!important;'
            . 'margin-left:auto!important;margin-right:auto!important;'
            . 'padding-inline:' . $pad . '!important;padding-left:' . $pad . '!important;'
            . 'padding-right:' . $pad . '!important';
        $bands = ':is(section.awa-footer-trust-bar,.awa-footer-trust-bar,.awa-footer-newsletter,'
            . '#footer.footer-container,.footer-container,section.awa-footer-categories-expand,'
            . '.footer-bottom,.awa-footer-devby)';
        $containers = ':is(section.awa-footer-trust-bar>.container,.awa-footer-trust-bar>.container,'
            . '.awa-footer-newsletter>.container,#footer.footer-container>.container,'
            . '.footer-container>.container,section.awa-footer-categories-expand>.container,'
            . '.footer-bottom>.container,.awa-footer-devby>.container)';

        return '/*§footer-content-axis-bp-20260827*/'
            . $root . ',' . $inner . '{' . $bleed . ';height:auto!important;min-height:0!important}'
            . $any . ' ' . $bands . '{' . $bleed
            . ';height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important}'
            . $any . ' ' . $containers . '{' . $shell . '}'
            . $any . ' .footer-bottom .footer-bottom-inner{'
            . 'box-sizing:border-box!important;width:100%!important;max-width:none!important;'
            . 'margin:0!important;padding:0!important}'
            . $any . ' :is(.footer-container .container>.row.rowFlexMargin>[class*="col-"],'
            . '.footer-bottom .awa-footer-bottom__row>[class*="col-"]){'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'float:none!important;max-width:none!important}'
            . $any . ' .footer-bottom .awa-footer-bottom__row{'
            . 'justify-items:stretch!important}'
            . $any . ' .footer-bottom .awa-footer-bottom__row>[class*="col-"]:first-child,'
            . $any . ' .footer-bottom .awa-footer-bottom__logo-col{'
            . 'justify-self:start!important;text-align:start!important}'
            . $any . ' .footer-bottom .awa-footer-bottom__logo-img{'
            . 'margin-inline:0!important}'
            . $any . ' section.awa-footer-trust-bar,'
            . $any . ' .awa-footer-trust-bar{'
            . 'padding-block:clamp(12px,1.8vw,18px)!important;margin:0!important}'
            . $any . ' .awa-footer-trust-grid{'
            . 'padding-block:0!important;gap:clamp(12px,2vw,24px)!important}'
            . $any . ' .awa-footer-trust-item{'
            . 'min-height:0!important;padding:0!important;gap:12px!important;align-items:center!important}'
            . $any . ' .awa-footer-trust-icon,'
            . $any . ' .awa-footer-trust-icon svg{'
            . 'width:28px!important;height:28px!important;min-width:28px!important;min-height:28px!important}'
            . $any . ' .awa-footer-trust-copy strong{'
            . 'font-size:14px!important;line-height:1.35!important}'
            . $any . ' .awa-footer-trust-copy span{'
            . 'font-size:13px!important;line-height:1.4!important}'
            . $any . ' .awa-footer-newsletter{'
            . 'padding-block:clamp(16px,2.2vw,24px)!important;margin:0!important;'
            . 'background:color-mix(in srgb,var(--awa-primary) 7%,var(--awa-bg,Canvas))!important;'
            . 'background-color:color-mix(in srgb,var(--awa-primary) 7%,var(--awa-bg,Canvas))!important;'
            . 'background-image:none!important;border:0!important;border-radius:0!important;box-shadow:none!important}'
            . $any . ' .awa-newsletter-wrapper{'
            . 'display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;'
            . 'align-items:center!important;justify-content:flex-start!important;'
            . 'gap:clamp(16px,2vw,28px)!important;width:100%!important;max-width:none!important;'
            . 'grid-template-columns:none!important}'
            . $any . ' .awa-newsletter-info{'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'gap:8px!important;grid-template-columns:none!important;min-width:0!important;'
            . 'flex:0 1 28rem!important;max-width:28rem!important}'
            . $any . ' .awa-newsletter-icon{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'flex:0 0 24px!important;width:24px!important;height:24px!important;'
            . 'min-width:24px!important;min-height:24px!important;max-width:24px!important;max-height:24px!important;'
            . 'margin:0!important;padding:0!important;overflow:hidden!important;'
            . 'background:transparent!important;border:0!important;box-shadow:none!important;'
            . 'color:var(--awa-primary)!important;grid-template-columns:none!important}'
            . $any . ' .awa-newsletter-icon :is(svg,img){'
            . 'width:24px!important;height:24px!important;min-width:24px!important;min-height:24px!important;'
            . 'max-width:24px!important;max-height:24px!important;display:block!important;flex:0 0 24px!important;'
            . 'color:var(--awa-primary)!important;stroke:currentColor!important;fill:none!important}'
            . $any . ' .awa-newsletter-form-container{'
            . 'flex:1 1 280px!important;width:auto!important;min-width:0!important;max-width:none!important;'
            . 'border:0!important;background:transparent!important;box-shadow:none!important;padding:0!important}'
            . $any . ' .newsletter-footer{'
            . 'border:0!important;background:transparent!important;box-shadow:none!important;'
            . 'padding:0!important;margin:0!important}'
            . $any . ' #newsletter-validate-detail{'
            . 'display:block!important;width:100%!important;max-width:none!important;'
            . 'margin:0!important;grid-template-columns:none!important;height:auto!important}'
            . $any . ' #newsletter-validate-detail .field.newsletter{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;'
            . 'grid-template-rows:44px auto!important;grid-auto-flow:row!important;'
            . 'flex-direction:row!important;flex-wrap:nowrap!important;align-items:start!important;'
            . 'column-gap:8px!important;row-gap:6px!important;width:100%!important;max-width:100%!important;'
            . 'height:auto!important;min-height:44px!important;padding:0!important;padding-block:0!important;'
            . 'border:0!important;background:transparent!important;box-shadow:none!important;outline:0!important;'
            . 'box-sizing:border-box!important}'
            . $any . ' #newsletter-validate-detail .control{'
            . 'grid-column:1!important;grid-row:1!important;min-width:0!important;width:auto!important;'
            . 'max-width:none!important;align-self:stretch!important;margin:0!important;padding:0!important;'
            . 'height:44px!important}'
            . $any . ' #newsletter-validate-detail .actions{'
            . 'grid-column:2!important;grid-row:1!important;width:auto!important;max-width:none!important;'
            . 'flex:0 0 auto!important;align-self:stretch!important;margin:0!important;padding:0!important;'
            . 'height:44px!important}'
            . $any . ' #newsletter-validate-detail .field.newsletter::after{'
            . 'grid-column:1/-1!important;grid-row:2!important}'
            . $any . ' :is(.awa-newsletter-title,.velaNewsletterTitle){'
            . 'font-size:16px!important;line-height:1.3!important;margin:0!important;font-weight:700!important}'
            . $any . ' .awa-newsletter-desc{'
            . 'font-size:13px!important;line-height:1.4!important;margin:0!important}'
            . $any . ' #newsletter-validate-detail :is(input[type=email],button.action.subscribe){'
            . 'height:44px!important;min-height:44px!important;box-sizing:border-box!important}'
            . $any . ' #newsletter-validate-detail input[type=email]{'
            . 'width:100%!important;max-width:none!important;min-width:0!important}'
            . $any . ' #newsletter-validate-detail input[type=email]:focus-visible,'
            . $any . ' #newsletter-validate-detail button.action.subscribe:focus-visible{'
            . 'outline:2px solid var(--awa-primary)!important;outline-offset:2px!important}'
            . $any . ' ul.awa-footer-categories-list a{'
            . 'transition:background-color .15s ease,border-color .15s ease,color .15s ease!important}'
            . $any . ' #newsletter-validate-detail button.action.subscribe{'
            . 'width:auto!important;max-width:none!important;white-space:nowrap!important;'
            . 'padding-inline:16px!important}'
            . $any . ' .awa-footer-trust-copy{'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'gap:4px!important;min-width:0!important}'
            . $any . ' .awa-footer-trust-copy strong,'
            . $any . ' .awa-footer-trust-copy span{'
            . 'display:block!important;position:static!important;width:auto!important;max-width:100%!important}'
            . $any . ' .awa-footer-trust-copy strong{'
            . 'font-size:14px!important;line-height:1.35!important}'
            . $any . ' .awa-footer-trust-copy span{'
            . 'font-size:13px!important;line-height:1.4!important}'
            . $any . ' #footer.footer-container,'
            . $any . ' .footer-container{'
            . 'padding-block:clamp(12px,1.8vw,20px) clamp(8px,1vw,12px)!important}'
            . $any . ' .footer-container .container>.row.rowFlexMargin{'
            . 'padding-block:0!important;gap:clamp(16px,2vw,28px)!important}'
            . $any . ' :is(.velaFooterTitle,.awa-footer-section__toggle){'
            . 'margin:0 0 10px!important;padding-bottom:8px!important}'
            . $any . ' ul.velaFooterLinks a,'
            . $any . ' .awa-footer-atendimento__actions a{'
            . 'min-height:44px!important;height:auto!important;padding-block:10px!important;line-height:1.35!important}'
            . $any . ' .awa-footer-atendimento__store-address{'
            . 'white-space:normal!important;overflow:visible!important;text-overflow:unset!important;line-height:1.4!important}'
            . $any . ' :is(p.awa-footer-atendimento__label,p.awa-footer-atendimento__label--social){'
            . 'position:static!important;width:auto!important;height:auto!important;clip:auto!important;'
            . 'overflow:visible!important;margin:12px 0 6px!important;white-space:normal!important}'
            . $any . ' section.awa-footer-categories-expand{'
            . 'padding-block:clamp(12px,1.8vw,20px)!important;margin:0!important;'
            . 'background:var(--awa-bg,Canvas)!important}'
            . $any . ' ul.awa-footer-categories-list>li>a,'
            . $any . ' ul.awa-footer-categories-list a{'
            . 'border-radius:999px!important;min-height:32px!important;padding:6px 12px!important;'
            . 'background:var(--awa-bg,Canvas)!important;border:1px solid var(--awa-border)!important;'
            . 'color:var(--awa-text)!important}'
            . $any . ' ul.awa-footer-categories-list a:hover,'
            . $any . ' ul.awa-footer-categories-list a:focus-visible{'
            . 'background:var(--awa-primary)!important;border-color:var(--awa-primary)!important;'
            . 'color:var(--awa-white,Canvas)!important}'
            . $any . ' .footer-bottom{'
            . 'padding-block:clamp(16px,2vw,24px)!important;background:var(--awa-bg,Canvas)!important}'
            . $any . ' .footer-bottom :is(.awa-footer-pay-sec__label,.awa-footer-muted-label){'
            . 'display:block!important}'
            . $any . ' .awa-footer-bottom__logo-img{'
            . 'height:auto!important;max-height:48px!important;width:auto!important}'
            . $any . ' .awa-footer-devby{'
            . 'max-height:none!important;padding-block:10px!important}'
            . $any . ' .awa-footer-devby__inner{'
            . 'height:auto!important;min-height:0!important}'
            . $any . ' .awa-footer-copyright__disclaimer{'
            . 'font-size:12px!important;line-height:1.45!important;max-width:min(72ch,100%)!important}'
            . '@media (min-width:768px){'
            . $any . ' section.awa-footer-categories-expand .awa-footer-categories-expand__inner{'
            . 'display:grid!important;grid-template-columns:minmax(96px,max-content) minmax(0,1fr)!important;'
            . 'align-items:center!important;column-gap:clamp(14px,2vw,28px)!important;row-gap:10px!important}'
            . $any . ' section.awa-footer-categories-expand .awa-footer-categories-expand__toggle{'
            . 'display:none!important}'
            . $any . ' section.awa-footer-categories-expand .awa-footer-categories-expand__heading{'
            . 'grid-column:1!important;grid-row:1!important}'
            . $any . ' section.awa-footer-categories-expand .awa-footer-categories-expand__panel,'
            . $any . ' section.awa-footer-categories-expand .awa-footer-categories-expand__panel[hidden]{'
            . 'grid-column:2!important;grid-row:1!important;display:block!important;'
            . 'visibility:visible!important;max-height:none!important}'
            . '}'
            . '@media (max-width:767px){'
            . $any . ' .awa-newsletter-wrapper{'
            . 'flex-direction:column!important;align-items:stretch!important;'
            . 'grid-template-columns:minmax(0,1fr)!important}'
            . $any . ' .awa-newsletter-info{'
            . 'flex:1 1 auto!important;max-width:none!important}'
            . $any . ' .awa-newsletter-form-container{'
            . 'flex:1 1 auto!important;width:100%!important;max-width:none!important}'
            . $any . ' .awa-newsletter-form-container .field.newsletter{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr)!important;gap:8px!important}'
            . $any . ' .awa-newsletter-form-container .field.newsletter :is(.control,.actions),'
            . $any . ' .awa-newsletter-form-container .field.newsletter .action.subscribe{'
            . 'grid-column:1!important;width:100%!important;max-width:100%!important}'
            . $any . ' section.awa-footer-categories-expand .awa-footer-categories-expand__panel{'
            . 'display:none!important}'
            . $any . ' section.awa-footer-categories-expand:has([data-awa-categories-toggle][aria-expanded="true"]) '
            . '.awa-footer-categories-expand__panel{'
            . 'display:block!important}'
            . '}';
    }

    /**
     * Audit 4.2 — desktop footer links must keep ≥44px touch targets.
     * Runtime: footerTouchAndTrust + footerDenseDesktopR67 set min-height:0 !important
     * (≥768/992) and override FIX-9 / footerTouchAndTrust mobile-only 44px.
     * This block loads last in footerTerminalRules with higher #html-body specificity.
     */
    public static function footerDesktopTouch44R1Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(.page_footer,.page-footer)';

        return '/*§footer-touch-4.2-r1*/'
            . '@media (min-width:768px){'
            . $scope . ' :is('
            . 'ul.velaFooterLinks a,'
            . '.awa-footer-atendimento__actions a,'
            . '.awa-footer-atendimento__phone a,'
            . '.awa-footer-atendimento__email a,'
            . '.awa-footer-pro__social a,'
            . '.awa-footer-pro__social-link,'
            . 'ul.awa-footer-categories-list a,'
            . 'ul.awa-footer-categories-list>li>a,'
            . '.awa-footer-devby__link'
            . '){'
            . 'display:inline-flex!important;align-items:center!important;'
            . 'box-sizing:border-box!important;min-height:44px!important;height:auto!important;'
            . 'padding-block:10px!important;padding-top:10px!important;padding-bottom:10px!important;'
            . 'line-height:1.25!important}'
            . $scope . ' :is(.awa-footer-pro__social a,.awa-footer-pro__social-link){'
            . 'min-width:44px!important;justify-content:center!important}'
            . '}';
    }

    /**
     * Desktop ≥992 — densifica categories/newsletter/bottom/atendimento.
     * Evidência r66: page~908; cats a minH 44 (critical+gridFill); newsletter icon 56.
     */

    /**
     * Categories surface white — @layer awa-visual-priority (layered !important
     * from deferred-stack soft/border beats unlayered locks). Served via inline
     * footer terminal rules to bypass immutable static CSS cache.
     */
    public static function footerCategoriesWhiteR16Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)';

        return '/*§footer-cat-white-r16-inline*/'
            . '@layer awa-visual-priority{'
            . $scope . ' section.awa-footer-categories-expand,'
            . $scope . ' section.awa-footer-categories-expand>.container,'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__inner,'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__panel,'
            . $scope . ' section.awa-footer-categories-expand ul.awa-footer-categories-list{'
            . 'background:#fff!important;background-color:#fff!important;background-image:none!important;'
            . 'border:0!important;border-top:0!important;border-right:0!important;'
            . 'border-bottom:0!important;border-left:0!important;'
            . 'border-radius:0!important;box-shadow:none!important;outline:0!important}'
            . $scope . ' section.awa-footer-categories-expand ul.awa-footer-categories-list{'
            . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;'
            . 'justify-content:flex-start!important;gap:6px 8px!important;'
            . 'grid-template-columns:none!important;width:auto!important;max-width:100%!important;'
            . 'margin:0!important;padding:0!important;list-style:none!important}'
            . $scope . ' section.awa-footer-categories-expand ul.awa-footer-categories-list>li{'
            . 'width:auto!important;max-width:none!important;flex:0 0 auto!important;'
            . 'margin:0!important;padding:0!important}'
            . $scope . ' section.awa-footer-categories-expand ul.awa-footer-categories-list a,'
            . $scope . ' section.awa-footer-categories-expand ul.awa-footer-categories-list>li>a{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:auto!important;min-width:0!important;max-width:none!important;flex:0 0 auto!important;'
            . 'box-sizing:border-box!important;min-height:44px!important;height:44px!important;'
            . 'padding:4px 10px!important;margin:0!important;'
            . 'background:#fff!important;background-color:#fff!important;'
            . 'border:1px solid var(--awa-border,#e5e5e5)!important;border-radius:999px!important;'
            . 'box-shadow:none!important;outline:0!important;line-height:1.25!important;font-size:12px!important}'
            . '@media (max-width:767px){'
            . $scope . ' section.awa-footer-categories-expand,'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__inner{'
            . 'background:#fff!important;background-color:#fff!important;border:0!important;border-top:0!important}'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__toggle{'
            . 'background:#fff!important;background-color:#fff!important;'
            . 'border:1px solid var(--awa-border,#e5e5e5)!important;border-radius:6px!important}'
            . '}'
            . '}';
    }


    /**
     * Newsletter band — flatten soft card (bg-soft + 1px + radius) that broke
     * visual continuity with white footer columns. Must use @layer awa-visual-priority.
     */
    public static function footerNewsletterWhiteR17Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)';

        return '/*§footer-news-white-r17-inline*/'
            . '@layer awa-visual-priority{'
            . $scope . ' .awa-footer-newsletter,'
            . $scope . ' .awa-footer-newsletter>.container{'
            . 'background:#fff!important;background-color:#fff!important;background-image:none!important;'
            . 'border:0!important;border-top:0!important;border-bottom:0!important;'
            . 'border-block:0!important;border-radius:0!important;box-shadow:none!important}'
            . '}';
    }

    /**
     * Mobile shelf CTA: hide --desktop-only + kill ::after "deslize" truncation
     * (impeccable ver-todos-fit-r7 forced visible + max-width:7rem).
     */
    /**
     * Mobile product carousels: hide overlay prev/next/pause chrome.
     * align-grid terminal forces display:inline-grid on .awa-owl-nav__btn
     * (5–6 #html-body) and beats impeccable unlayered display:none.
     * Must live in @layer awa-visual-priority.
     */
    public static function shelfCarouselMobileChromeHideR19Rules(): string
    {
        $h = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home';

        return '/*§carousel-chrome-mobile-hide-r19-inline*/'
            . '@layer awa-visual-priority{'
            . '@media (max-width:767px){'
            . $h . ' .awa-shelf--carousel > .awa-owl-nav,'
            . $h . ' .awa-shelf--carousel .awa-owl-nav.awa-carousel__nav,'
            . $h . ' .awa-carousel-section .awa-shelf--carousel > .awa-owl-nav,'
            . $h . ' .awa-carousel-section :is(.awa-owl-nav.awa-carousel__nav,.awa-owl-nav){'
            . 'display:none!important;visibility:hidden!important;opacity:0!important;'
            . 'pointer-events:none!important;width:0!important;height:0!important;'
            . 'min-width:0!important;min-height:0!important;max-width:0!important;max-height:0!important;'
            . 'overflow:hidden!important;position:absolute!important}'
            . $h . ' .awa-shelf--carousel :is(button.awa-owl-nav__btn,.awa-owl-nav__btn,'
            . 'button.awa-carousel__arrow,.awa-carousel__arrow,.awa-carousel__toggle,'
            . '.owl-prev,.owl-next),'
            . $h . ' .awa-carousel-section :is(button.awa-owl-nav__btn,.awa-owl-nav__btn,'
            . 'button.awa-carousel__arrow,.awa-carousel__arrow,.awa-carousel__toggle,'
            . '.owl-prev,.owl-next){'
            . 'display:none!important;visibility:hidden!important;opacity:0!important;'
            . 'pointer-events:none!important;width:0!important;height:0!important;'
            . 'min-width:0!important;min-height:0!important;max-width:0!important;max-height:0!important;'
            . 'overflow:hidden!important}'
            . '}'
            . '}';
    }

    public static function shelfViewAllMobileHideR18Rules(): string
    {
        $h = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home';

        return '/*§ver-todos-mobile-hide-r18-inline*/'
            . '@layer awa-visual-priority{'
            . '@media (max-width:767px){'
            . $h . ' .awa-shelf-view-all--desktop-only,'
            . $h . ' a.awa-shelf-view-all--desktop-only,'
            . $h . ' :is(.awa-section-header__link,.awa-shelf__view-all,.awa-shelf-view-all).awa-shelf-view-all--desktop-only{'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important;'
            . 'opacity:0!important;width:0!important;height:0!important;min-width:0!important;min-height:0!important;'
            . 'max-width:0!important;max-height:0!important;margin:0!important;padding:0!important;'
            . 'overflow:hidden!important;border:0!important;font-size:0!important;line-height:0!important;'
            . 'position:absolute!important;inset:auto!important;clip:rect(0,0,0,0)!important}'
            . $h . ' .awa-shelf-view-all--desktop-only::after,'
            . $h . ' :is(.awa-section-header__link,.awa-shelf__view-all).awa-shelf-view-all--desktop-only::after{'
            . 'content:none!important;display:none!important}'
            . $h . ' :is(.awa-section-header__left,.awa-shelf__header-left){max-width:100%!important}'
            . '}'
            . '}';
    }

    /**
     * Copyright band — flatten soft card (var(--awa-bg) soft + 1px + radius)
     * from footerVtexNeutralTerminalRules. Layered to beat deferred soft surfaces.
     */
    /**
     * Category carousel desktop gutter r22c — 48px pad in @layer awa-visual-priority
     * so prev/next sit in gutters (match product shelf geometry).
     */
    public static function categoryCarouselDesktopGutterR22Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper';
        $header = $scope . ' .top-home-content--category-carousel .awa-category-carousel__header';
        $left = $scope . ' .top-home-content--category-carousel .awa-section-header__left';
        $actions = $scope . ' .top-home-content--category-carousel .awa-category-carousel__actions';
        $nav = $scope . ' .top-home-content--category-carousel .awa-category-carousel__nav';
        $btn = $scope . ' .top-home-content--category-carousel .awa-category-carousel__nav'
            . ' :is(.awa-category-carousel__prev,.awa-category-carousel__next)';
        $cta = $scope . ' .top-home-content--category-carousel .awa-category-carousel__cta-link';
        $wrap = $scope . ' .top-home-content--category-carousel .awa-category-carousel';
        $vp = $scope . ' .top-home-content--category-carousel .awa-category-carousel__viewport';

        $dot = $scope . ' .top-home-content--category-carousel .awa-category-carousel__dot';
        $dots = $scope . ' .top-home-content--category-carousel #awa-cat-dots,'
            . $scope . ' .top-home-content--category-carousel .awa-category-carousel__dots';

        // r22j: title left + (nav+Ver todos) right cluster; never wrap nav under subtitle.
        // r24: mobile dots — keep 44px hit area, paint only ::before (8/22px); kill btn fill + scaleX.
        return '/*§cat-dots-r24b-gutter-inline*/'
            . '@media (min-width:768px){'
            . '@layer awa-visual-priority{'
            . $header . '{display:flex!important;flex-wrap:nowrap!important;align-items:center!important;'
            . 'justify-content:space-between!important;gap:16px!important;width:100%!important}'
            . $left . '{flex:1 1 auto!important;width:auto!important;max-width:none!important;min-width:0!important;'
            . 'float:none!important;display:flex!important;flex-direction:column!important}'
            . $actions . '{display:inline-flex!important;align-items:center!important;gap:12px!important;'
            . 'flex:0 0 auto!important;margin-inline-start:auto!important;order:unset!important}'
            . $nav . '{display:inline-flex!important;align-items:center!important;gap:8px!important;'
            . 'flex:0 0 auto!important;margin:0!important;order:unset!important}'
            . $btn . '{position:static!important;left:auto!important;right:auto!important;top:auto!important;'
            . 'transform:none!important;inset:auto!important;margin:0!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important;'
            . 'border-radius:10px!important;z-index:1!important;flex:0 0 36px!important}'
            . $cta . '{order:unset!important;margin:0!important;align-self:center!important;white-space:nowrap!important}'
            . $wrap . '{position:relative!important;padding-inline:0!important;--awa-home-carousel-gutter:0px;'
            . 'width:100%!important;margin-top:16px!important}'
            . $vp . '{padding-inline:0!important;margin-inline:0!important;overflow:hidden!important;'
            . 'overflow-x:hidden!important;width:100%!important;position:relative!important}'
            . '}'
            . '}'
            . '@media (max-width:767px){'
            . '@layer awa-visual-priority{'
            . $nav . '{display:none!important}'
            . $actions . '{display:inline-flex!important;margin-inline-start:auto!important}'
            . $dots . '{display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'gap:6px!important;min-height:18px!important;height:auto!important;margin-block:8px 0!important;'
            . 'padding:0!important;width:100%!important}'
            . $dot . '{appearance:none!important;background:transparent!important;background-color:transparent!important;'
            . 'background-image:none!important;border:0!important;border-radius:0!important;box-shadow:none!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'max-width:44px!important;max-height:44px!important;padding:0!important;margin:0!important;'
            . 'flex:0 0 44px!important;transform:none!important;overflow:visible!important}'
            . $dot . ':is(.active,[aria-current="page"]){'
            . 'background:transparent!important;background-color:transparent!important;width:44px!important;'
            . 'max-width:44px!important;transform:none!important;scale:none!important}'
            . $dot . '::before{content:""!important;display:block!important;width:8px!important;height:8px!important;'
            . 'border-radius:9999px!important;'
            . 'background:color-mix(in srgb,var(--awa-text-muted,CanvasText) 42%,transparent)!important;'
            . 'box-shadow:none!important;transform:none!important}'
            . $dot . ':is(.active,[aria-current="page"])::before{'
            . 'width:22px!important;height:8px!important;'
            . 'background:var(--awa-primary,var(--awa-brand,#b73337))!important}'
            . '}'
            . '}'
            . '@media (min-width:768px){'
            . '@layer awa-visual-priority{'
            . $scope . ' .top-home-content--category-carousel.is-awa-cat-nav-idle .awa-category-carousel__nav{display:none!important}'
            . $scope . ' .top-home-content--category-carousel.is-awa-cat-nav-idle #awa-cat-dots{display:none!important}'
            . $scope . ' .top-home-content--category-carousel .awa-category-carousel__label{'
            . 'min-height:2.6em!important;display:-webkit-box!important;-webkit-line-clamp:2!important;'
            . '-webkit-box-orient:vertical!important;overflow:hidden!important;text-align:center!important;line-height:1.3!important}'
            . $scope . ' .top-home-content--category-carousel .awa-category-carousel__count{margin-top:auto!important}'
            . $scope . ' .top-home-content--category-carousel .awa-category-carousel__item{'
            . 'display:flex!important;flex-direction:column!important;justify-content:flex-start!important}'
            . '}'
            . '}'
            . '@media (min-width:1280px){'
            . '@layer awa-visual-priority{'
            /* r23a: 8×~152px cards fit; hide header nav/dots without waiting for JS idle */
            . $scope . ' .top-home-content--category-carousel .awa-category-carousel__nav{display:none!important}'
            . $scope . ' .top-home-content--category-carousel #awa-cat-dots{display:none!important}'
            . '}'
            . '}';
    }

    /**
     * r24b: mobile category dots — dedicated injectable rules.
     * Keep 44px hit area; paint only ::before (8/22px). Kill btn fill + scaleX(2.75).
     * Unlayered + layered: link id awa-home-impeccable-terminal-v1 blocks PHP style re-inject.
     */
    public static function categoryCarouselDotsCompactR24Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper'
            . ' .top-home-content--category-carousel';
        $dot = $scope . ' .awa-category-carousel__dot';
        $dots = $scope . ' #awa-cat-dots,' . $scope . ' .awa-category-carousel__dots';

        $core = $dots . '{display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'gap:6px!important;min-height:18px!important;height:auto!important;margin-block:8px 0!important;'
            . 'padding:0!important;width:100%!important}'
            . $dot . '{appearance:none!important;background:transparent!important;background-color:transparent!important;'
            . 'background-image:none!important;border:0!important;border-radius:0!important;box-shadow:none!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'max-width:44px!important;max-height:44px!important;padding:0!important;margin:0!important;'
            . 'flex:0 0 44px!important;transform:none!important;overflow:visible!important}'
            . $dot . ':is(.active,[aria-current="page"]){'
            . 'background:transparent!important;background-color:transparent!important;width:44px!important;'
            . 'max-width:44px!important;transform:none!important}'
            . $dot . '::before{content:""!important;display:block!important;width:8px!important;height:8px!important;'
            . 'border-radius:9999px!important;'
            . 'background:color-mix(in srgb,var(--awa-text-muted,CanvasText) 42%,transparent)!important;'
            . 'box-shadow:none!important;transform:none!important}'
            . $dot . ':is(.active,[aria-current="page"])::before{'
            . 'width:22px!important;height:8px!important;'
            . 'background:var(--awa-primary,var(--awa-brand,#b73337))!important}';

        return '/*§cat-dots-r24b-inline*/'
            . '@media (max-width:767px){'
            . '@layer awa-visual-priority{' . $core . '}'
            . $core
            . '}';
    }









    public static function footerAtendimentoStoreFlatR21Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)';
        $flat = $scope . ' .awa-footer-atendimento__store,'
            . $scope . ' .awa-footer-atendimento .awa-footer-atendimento__store{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-top:0!important;border-right:0!important;border-bottom:0!important;border-left:0!important;'
            . 'border-radius:0!important;box-shadow:none!important;outline:0!important}';

        // Layered (beats most author CSS) + unlayered twin (beats late unlayered
        // align-grid/visual-bugfix --awa-bg soft card when document order loses).
        return '/*§footer-store-flat-r21-inline*/'
            . '@layer awa-visual-priority{' . $flat . '}'
            ;
    }

    public static function footerCopyrightFlatR20Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)';

        return '/*§footer-copyright-flat-r20-inline*/'
            . '@layer awa-visual-priority{'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright,'
            . $scope . ' .awa-footer-bottom__copyright{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-top:0!important;border-right:0!important;border-bottom:0!important;border-left:0!important;'
            . 'border-radius:0!important;box-shadow:none!important;outline:0!important}'
            . '}';
    }

    /**
     * r26: flatten CNPJ badge soft-card (tint + border from visual-fixes ROUND 15).
     * Layered + unlayered twin — same pattern as store-flat r21b.
     */
    public static function footerCnpjBadgeFlatR26Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)';

        $flat = $scope . ' .footer-bottom .awa-footer-cnpj-badge,'
            . $scope . ' .awa-footer-bottom__copyright .awa-footer-cnpj-badge,'
            . $scope . ' .awa-footer-cnpj-badge{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-top:0!important;border-right:0!important;border-bottom:0!important;border-left:0!important;'
            . 'border-radius:0!important;box-shadow:none!important;outline:0!important;'
            . 'color:var(--awa-text-secondary,var(--awa-text-muted,var(--awa-text,#333)))!important}'
            . $scope . ' .awa-footer-cnpj-badge__text{'
            . 'background:transparent!important;border:0!important;'
            . 'color:inherit!important}'
            . $scope . ' .awa-footer-cnpj-badge__icon{'
            . 'background:transparent!important;border:0!important;'
            . 'color:var(--awa-primary,#b73337)!important}';

        return '/*§footer-cnpj-flat-r26-inline*/'
            . '@layer awa-visual-priority{' . $flat . '}'
            ;
    }

    /**
     * r27: mobile B2B promo bar is light (css-gate/align) but cascade-lock still
     * paints __lead-short / __cta-short white (era of solid red bar) → invisible.
     * Align mobile with desktop: light bar + dark text; CTA brand underline.
     */
    public static function b2bPromoContrastR27Rules(): string
    {
        $bar = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header :is(#awa-b2b-promo-bar,.awa-b2b-promo-bar)';

        $inkDark = 'var(--awa-text-primary,#111827)';
        $inkCta = 'var(--awa-primary,#b73337)';
        $inkMuted = 'var(--awa-text-secondary,#475569)';
        $rules = '@media (max-width:767px){'
            . $bar . '{'
            . 'background:#fff!important;background-color:#fff!important;background-image:none!important;'
            . 'color:' . $inkDark . '!important;-webkit-text-fill-color:' . $inkDark . '!important}'
            . $bar . ' :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,'
            . '.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__lead-short,'
            . '.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__separator,'
            . '.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta-long,.awa-b2b-promo-bar__cta-short,'
            . '.awa-b2b-promo-bar__cta strong,span,p,a,strong){'
            . 'color:' . $inkDark . '!important;-webkit-text-fill-color:' . $inkDark . '!important;'
            . 'opacity:1!important;line-height:1.4!important}'
            . $bar . ' :is(.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta-short,.awa-b2b-promo-bar__cta-long,'
            . 'a.awa-b2b-promo-bar__cta){'
            . 'color:' . $inkCta . '!important;-webkit-text-fill-color:' . $inkCta . '!important;'
            . 'font-weight:700!important;'
            . 'text-decoration:underline!important;text-underline-offset:2px!important}'
            . $bar . ' :is(.awa-b2b-promo-close,#awa-b2b-promo-close){'
            . 'color:' . $inkMuted . '!important;-webkit-text-fill-color:' . $inkMuted . '!important}'
            . '}';

        return '/*§b2b-promo-contrast-r27-inline*/'
            . '@layer awa-visual-priority{' . $rules . '}'
            ;
    }

    /**
     * r40c: desktop B2B promo shell — 44px primary FULL-BLEED.
     * 2026-08-14: mata o legado "soft white" + CTA chip (padding 4×12, radius 999)
     * que ganhava o kill-card por ordem (mesmo 11 IDs, injetado no </body>).
     * Eixo: barra padding 0; inner 1280 + 24px/52px (alinha logo, reserva o fechar).
     * Mobile ≤767 permanece em r27 (barra clara). 2026-08-17: o chrome de pílula
     * (DS 5a radius 999 + visual-fixes 6px/borda) vazava em ≤767 porque o reset
     * de CTA do r40 era só ≥768. SSOT align-grid: link textual, não chip.
     */
    public static function b2bPromoShellR40Rules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body';
        $site = $root . ' .page-wrapper .awa-site-header';
        $bar = $site . ' :is(#awa-b2b-promo-bar,.awa-b2b-promo-bar,.top-header.awa-b2b-promo-bar)';
        $inner = $bar . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout)';
        $text = $bar . ' :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,'
            . '.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__lead-short,'
            . '.awa-b2b-promo-bar__tail,'
            . '.awa-b2b-promo-bar__separator,span,p)';
        $ctaLink = $bar . ' :is(a.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta)';
        $ctaText = $bar . ' :is(.awa-b2b-promo-bar__cta strong,.awa-b2b-promo-bar__cta-long,'
            . '.awa-b2b-promo-bar__cta-short)';
        $close = $bar . ' :is(.awa-b2b-promo-close,#awa-b2b-promo-close)';
        $fill = 'var(--awa-primary,oklch(48% .14 20))';
        /* On-primary: --awa-text-inverse no super-global aponta a --awa-bg (ciclo/:root
         * invalid) e o H16B do align-grid pinta -webkit-text-fill-color escuro depois
         * do gate. Token --awa-white + fill-color travam contraste no fundo primary. */
        $ink = 'var(--awa-white,#fff)';

        /* BUG-SHELL-PROMO-TOKEN-CLOSED-001: tokens 44/160 só com promo aberta.
         * Antes: forçava --awa-header-promo-h:44 e stack 160 mesmo com aria-hidden=true. */
        $rules = '@media (min-width:768px){'
            . $site . '{'
            . '--awa-header-promo-h:0px!important;'
            . '--awa-header-stack-h:var(--awa-header-sticky-h,112px)!important}'
            . $site . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
            . '--awa-header-promo-h:44px!important;'
            . '--awa-header-stack-h:calc(44px + var(--awa-header-sticky-h,116px))!important}'
            . $site . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])) ' . '#header.header-container:not(.awa-checkout-header-shell):not(.awa-auth-header-shell){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'width:100%!important;max-width:none!important;margin:0!important;padding:0!important;'
            . 'overflow:hidden!important;background:' . $fill . '!important;background-color:' . $fill . '!important}'
            . $site . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])) '
            . '#header.header-container:not(.awa-checkout-header-shell):not(.awa-auth-header-shell) .header-content:not(.awa-checkout-header-content):not(.awa-auth-header-content){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'width:100%!important;max-width:none!important;margin:0!important;'
            . 'padding:0!important;align-items:center!important;overflow:hidden!important;'
            . 'background:transparent!important;background-color:transparent!important}'
            . $bar . '{'
            . 'position:relative!important;'
            . 'display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'height:var(--awa-header-promo-h,0px)!important;'
            . 'min-height:var(--awa-header-promo-h,0px)!important;'
            . 'max-height:var(--awa-header-promo-h,0px)!important;'
            . 'width:100%!important;max-width:none!important;min-width:100%!important;'
            . 'margin:0!important;margin-inline:0!important;margin-left:0!important;margin-right:0!important;'
            . 'left:auto!important;right:auto!important;inset-inline:auto!important;'
            . 'padding:0!important;padding-inline:0!important;box-sizing:border-box!important;'
            . 'background:' . $fill . '!important;background-color:' . $fill . '!important;background-image:none!important;'
            . 'color:' . $ink . '!important;-webkit-text-fill-color:' . $ink . '!important;'
            . 'border:0!important;border-bottom:0!important;'
            . 'line-height:1.2!important;overflow:hidden!important}'
            . $site . ':has(#awa-b2b-promo-bar[aria-hidden="true"]) ' . '#header.header-container,'
            . $site . ':has(#awa-b2b-promo-bar[aria-hidden="true"]) '
            . '#header.header-container:not(.awa-checkout-header-shell):not(.awa-auth-header-shell) .header-content:not(.awa-checkout-header-content):not(.awa-auth-header-content){'
            . 'height:0!important;min-height:0!important;max-height:0!important;'
            . 'overflow:hidden!important;margin:0!important;padding:0!important;border:0!important}'
            . $inner . '{'
            . 'height:var(--awa-header-promo-h,0px)!important;'
            . 'min-height:var(--awa-header-promo-h,0px)!important;'
            . 'max-height:var(--awa-header-promo-h,0px)!important;'
            . 'width:min(100%,1280px)!important;max-width:1280px!important;'
            . 'margin:0 auto!important;margin-inline:auto!important;'
            . 'display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'padding:0 52px 0 24px!important;padding-inline:24px 52px!important;box-sizing:border-box!important;'
            . 'background:transparent!important;color:inherit!important;'
            . '-webkit-text-fill-color:inherit!important}'
            . $text . '{'
            . 'color:' . $ink . '!important;-webkit-text-fill-color:' . $ink . '!important;opacity:1!important;'
            . 'line-height:1.2!important;font-size:13px!important;font-weight:600!important;'
            . 'letter-spacing:.01em!important;'
            . 'padding:0!important;max-width:none!important;width:100%!important}'
            . $ctaLink . '{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'color:' . $ink . '!important;-webkit-text-fill-color:' . $ink . '!important;'
            . 'font-weight:700!important;font-size:13px!important;'
            . 'text-decoration:underline!important;text-underline-offset:3px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0 12px!important;border:0!important;border-radius:0!important;'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'box-shadow:none!important;max-width:none!important;'
            . 'box-sizing:border-box!important;white-space:nowrap!important;line-height:1.2!important}'
            . $ctaLink . ':hover,' . $ctaLink . ':focus-visible{'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'color:' . $ink . '!important;-webkit-text-fill-color:' . $ink . '!important;'
            . 'text-decoration:underline!important}'
            . $ctaText . '{'
            . 'color:inherit!important;-webkit-text-fill-color:inherit!important;'
            . 'text-decoration:inherit!important;'
            . 'background:transparent!important;border:0!important;padding:0!important;'
            . 'font-weight:700!important}'
            . $close . '{'
            . 'position:absolute!important;top:0!important;inset-block:0!important;'
            . 'right:0!important;inset-inline-end:0!important;transform:none!important;'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'box-sizing:border-box!important;display:inline-flex!important;'
            . 'align-items:center!important;justify-content:center!important;'
            . 'border:0!important;border-radius:0!important;'
            . 'border-inline-start:1px solid color-mix(in srgb,' . $ink . ' 28%,transparent)!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'color:' . $ink . '!important;-webkit-text-fill-color:' . $ink . '!important;'
            . 'font-size:22px!important;'
            . 'font-weight:600!important;line-height:1!important;padding:0!important;margin:0!important}'
            . '}'
            . '@media (max-width:767px){'
            /* P2-PROMO-CTA-PILL: r40 chrome (radius 0) era ≥768. Mobile herdava DS 5a
             * pill + visual-fixes chip. 11 IDs vencem DS/inline/css-gate (7 IDs).
             * Não copiar height 44 / ink inverso — barra mobile é clara (r27). */
            . $ctaLink . '{'
            . 'display:inline!important;align-items:unset!important;justify-content:unset!important;'
            . 'height:auto!important;min-height:0!important;max-height:none!important;'
            . 'padding:0!important;margin:0!important;line-height:1.25!important;'
            . 'border:0!important;border-radius:0!important;box-shadow:none!important;'
            . 'background-color:transparent!important;background-image:none!important;'
            . 'color:var(--awa-primary,#b73337)!important;'
            . '-webkit-text-fill-color:var(--awa-primary,#b73337)!important;'
            . 'font-weight:700!important;'
            . 'text-decoration:underline!important;text-underline-offset:2px!important;'
            . 'white-space:nowrap!important}'
            . $ctaLink . ':hover,' . $ctaLink . ':focus-visible{'
            . 'background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-radius:0!important;'
            . 'color:var(--awa-primary,#b73337)!important;'
            . '-webkit-text-fill-color:var(--awa-primary,#b73337)!important;'
            . 'text-decoration:underline!important}'
            . $ctaText . '{'
            . 'background-color:transparent!important;border:0!important;border-radius:0!important;'
            . 'padding:0!important;color:inherit!important;-webkit-text-fill-color:inherit!important}'
            . '}';

        return '/*§b2b-promo-shell-r40c-inline*/'
            . '@layer awa-visual-priority{' . $rules . '}'
            ;
    }

    /**
     * r28: flatten footer trust/pay soft-cards (density-grid surface+border+radius).
     * Images remain; chrome (bg/border/radius/shadow) removed — same pattern as CNPJ r26.
     */
    public static function footerSealsPayFlatR28Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)';

        $flat = $scope . ' :is(.awa-seal,.awa-footer-sec-seals li,.awa-footer-devby__logo,'
            . '.awa-pay-logo,.awa-footer-pay-logos li){'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-top:0!important;border-right:0!important;border-bottom:0!important;border-left:0!important;'
            . 'border-radius:0!important;box-shadow:none!important;outline:0!important}'
            . $scope . ' .awa-seal{padding:0!important}'
            . $scope . ' .awa-seal__img,'
            . $scope . ' .awa-footer-sec-seals img,'
            . $scope . ' .awa-footer-pay-logos img,'
            . $scope . ' .awa-footer-devby__logo{'
            . 'background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;'
            . 'opacity:1!important;visibility:visible!important}';

        return '/*§footer-seals-pay-flat-r28-inline*/'
            . '@layer awa-visual-priority{' . $flat . '}'
            ;
    }

    /**
     * r29: newsletter form polish —
     * 1) kill 3px left accent (visual-fixes ROUND 24) → uniform 1px border
     * 2) mobile stack field so CTA text does not wrap in 118px grid cell
     * 3) flatten soft-tint on .awa-newsletter-icon
     */
    public static function footerNewsletterFormR29Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)';

        $rules = $scope . ' .awa-newsletter-icon{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-radius:0!important;box-shadow:none!important}'
            . $scope . ' #newsletter-validate-detail input[type=email],'
            . $scope . ' #newsletter-validate-detail input#newsletter,'
            . $scope . ' .newsletter-footer input[type=email],'
            . $scope . ' .awa-footer-newsletter input[type=email]{'
            . 'border:1px solid var(--awa-border,#e5e5e5)!important;'
            . 'border-top:1px solid var(--awa-border,#e5e5e5)!important;'
            . 'border-right:1px solid var(--awa-border,#e5e5e5)!important;'
            . 'border-bottom:1px solid var(--awa-border,#e5e5e5)!important;'
            . 'border-left:1px solid var(--awa-border,#e5e5e5)!important;'
            . 'box-shadow:none!important}'
            . $scope . ' #newsletter-validate-detail button.action.subscribe{'
            . 'white-space:nowrap!important;line-height:1.2!important}'
            . '@media (max-width:767px){'
            . $scope . ' #newsletter-validate-detail .field.newsletter{'
            . 'display:flex!important;flex-direction:column!important;align-items:stretch!important;'
            . 'grid-template-columns:none!important;grid-template-rows:none!important;'
            . 'gap:8px!important;width:100%!important;height:auto!important;min-height:0!important;'
            . 'border:0!important}'
            . $scope . ' #newsletter-validate-detail :is(.control,.actions){'
            . 'width:100%!important;max-width:none!important;flex:0 0 auto!important;'
            . 'display:block!important}'
            . $scope . ' #newsletter-validate-detail input[type=email],'
            . $scope . ' #newsletter-validate-detail input#newsletter,'
            . $scope . ' #newsletter-validate-detail button.action.subscribe{'
            . 'width:100%!important;max-width:none!important;'
            . 'min-height:44px!important;height:44px!important}'
            . $scope . ' #newsletter-validate-detail button.action.subscribe{'
            . 'white-space:nowrap!important;font-size:13px!important;'
            . 'padding-inline:16px!important}'
            . '}';

        return '/*§footer-newsletter-r29-inline*/'
            . '@layer awa-visual-priority{' . $rules . '}'
            ;
    }

    /**
     * r30: stack back-to-top above chat FAB on mobile. WA slot is now #awa-ai-chat-root
     * (56). Sound moved into the panel header — BTT sits nav+12+56+12. z-index 998 < chat 9999.
     * PDP/PLP load super-global @layer awa-fixes; layered !important beats unlayered
     * !important (CSS Cascade 5). Same-layer awa-fixes block at body-end wins 140px lock.
     */
    public static function mobileFabStackR30Rules(): string
    {
        $wa = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' :is(a.awa-whatsapp-float,.awa-whatsapp-float)';
        $top = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' :is(#awa-back-to-top,.awa-back-to-top).is-visible:not([hidden])';
        $cookieRoot = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body.awa-cookie-banner-active';
        $nav = 'var(--awa-mobile-bottom-nav-h,72px)';
        $safe = 'env(safe-area-inset-bottom,0px)';
        $cookie = 'var(--awa-cookie-banner-height,0px)';

        $rules = '@media (max-width:991px){'
            . $wa . '{'
            . 'position:fixed!important;right:16px!important;left:auto!important;'
            . 'bottom:calc(' . $nav . ' + 12px + ' . $safe . ')!important;'
            . 'inset-block-end:calc(' . $nav . ' + 12px + ' . $safe . ')!important;'
            . 'z-index:10002!important}'
            . $top . '{'
            . 'position:fixed!important;right:16px!important;left:auto!important;'
            . 'bottom:calc(' . $nav . ' + 12px + 56px + 12px + ' . $safe . ')!important;'
            . 'inset-block-end:calc(' . $nav . ' + 12px + 56px + 12px + ' . $safe . ')!important;'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'max-width:44px!important;max-height:44px!important;padding:0!important;'
            . 'box-sizing:border-box!important;z-index:998!important}'
            . $cookieRoot . ' :is(a.awa-whatsapp-float,.awa-whatsapp-float){'
            . 'bottom:calc(' . $nav . ' + 12px + ' . $cookie . ' + ' . $safe . ')!important;'
            . 'inset-block-end:calc(' . $nav . ' + 12px + ' . $cookie . ' + ' . $safe . ')!important}'
            . $cookieRoot . ' :is(#awa-back-to-top,.awa-back-to-top).is-visible:not([hidden]){'
            . 'bottom:calc(' . $nav . ' + 12px + 56px + 12px + ' . $cookie . ' + ' . $safe . ')!important;'
            . 'inset-block-end:calc(' . $nav . ' + 12px + 56px + 12px + ' . $cookie . ' + ' . $safe . ')!important}'
            . '}';

        // CookieConsent owns the measured offset; keep its choice buttons unobstructed.
        $rules .= '@media(max-width:640px){'
            . $cookieRoot . ' .awa-ai-chat__toggle{'
            . 'bottom:max(24px,var(--awa-cookie-banner-top-gap,0px))!important;'
            . 'inset-block-end:max(24px,var(--awa-cookie-banner-top-gap,0px))!important}'
            . '}';

        return '/*§mobile-fab-stack-r30-inline*/'
            . '@layer awa-fixes{' . $rules . '}'
            . '@layer awa-visual-priority{' . $rules . '}'
            . '/*§mobile-fab-stack-r30-unlayered*/' . $rules;
    }

    /**
     * r31: flatten trust-bar icon soft chips (rgba white/red 0.08 + pill).
     * Keep glyph white on primary bar; remove chip chrome only.
     */
    public static function footerTrustIconFlatR31Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer)'
            . ' .awa-footer-trust-bar';

        $flat = $scope . ' .awa-footer-trust-icon{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-radius:0!important;box-shadow:none!important;outline:0!important;'
            . 'color:#fff!important}'
            . $scope . ' .awa-footer-trust-icon :is(svg,img,i){'
            . 'background:transparent!important;border:0!important;border-radius:0!important;'
            . 'color:#fff!important}'
            . $scope . ' .awa-footer-trust-icon svg{'
            . 'stroke:currentColor!important}';

        return '/*§footer-trust-icon-flat-r31-inline*/'
            . '@layer awa-visual-priority{' . $flat . '}'
            ;
    }


    /**
     * r33: desktop FAB stack — r30 only covered max-width:991px.
     * WA + back-to-top both at bottom:24px/right:16px overlapped (~2376px²).
     * Keep WA at corner; raise back-to-top by WA height + 12px gap.
     */
    public static function desktopFabStackR33Rules(): string
    {
        $wa = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' :is(a.awa-whatsapp-float,.awa-whatsapp-float)';
        $top = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' :is(#awa-back-to-top,.awa-back-to-top).is-visible:not([hidden])';
        $cookieRoot = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body.awa-cookie-banner-active';
        $safe = 'env(safe-area-inset-bottom,0px)';
        $cookie = 'var(--awa-cookie-banner-height,0px)';
        $hide = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body .page-wrapper'
            . ' :is(#awa-back-to-top,.awa-back-to-top)[hidden],'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body .page-wrapper'
            . ' :is(#awa-back-to-top,.awa-back-to-top)[aria-hidden="true"]:not(.is-visible){'
            . 'display:none!important;visibility:hidden!important;opacity:0!important;'
            . 'pointer-events:none!important;width:0!important;height:0!important;'
            . 'min-width:0!important;min-height:0!important;padding:0!important;'
            . 'overflow:hidden!important;box-shadow:none!important}';

        $rules = '@media (min-width:992px){'
            . $wa . '{'
            . 'position:fixed!important;right:16px!important;left:auto!important;'
            . 'bottom:calc(24px + ' . $safe . ')!important;'
            . 'inset-block-end:calc(24px + ' . $safe . ')!important;'
            . 'z-index:10002!important}'
            . $top . '{'
            . 'position:fixed!important;right:16px!important;left:auto!important;'
            . 'bottom:calc(24px + 56px + 12px + ' . $safe . ')!important;'
            . 'inset-block-end:calc(24px + 56px + 12px + ' . $safe . ')!important;'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'max-width:44px!important;max-height:44px!important;padding:0!important;'
            . 'box-sizing:border-box!important;z-index:10001!important}'
            . $cookieRoot . ' :is(a.awa-whatsapp-float,.awa-whatsapp-float){'
            . 'bottom:calc(24px + ' . $cookie . ' + ' . $safe . ')!important;'
            . 'inset-block-end:calc(24px + ' . $cookie . ' + ' . $safe . ')!important}'
            . $cookieRoot . ' :is(#awa-back-to-top,.awa-back-to-top).is-visible:not([hidden]){'
            . 'bottom:calc(24px + 56px + 12px + ' . $cookie . ' + ' . $safe . ')!important;'
            . 'inset-block-end:calc(24px + 56px + 12px + ' . $cookie . ' + ' . $safe . ')!important}'
            . '}';

        return '/*§desktop-fab-stack-r33-inline*/'
            . '@layer awa-visual-priority{' . $hide . $rules . '}'
            . '/*§desktop-fab-stack-r33-unlayered*/' . $hide . $rules;
    }

    /**
     * r34: mobile header hamburger — flatten soft chip (bg+border+radius)
     * to match flat cart icon. Keep 44px hit area and primary glyph color.
     * Expanded state keeps solid primary for open feedback.
     */
    public static function mobileToggleFlatR34Rules(): string
    {
        $toggle = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header'
            . ' :is(.awa-header-mobile-toggle,.action.nav-toggle,[data-action="toggle-nav"])';

        $flat = '@media (max-width:991px){'
            . $toggle . '{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-width:0!important;border-color:transparent!important;'
            . 'border-radius:0!important;box-shadow:none!important;outline:0!important;'
            . 'color:var(--awa-primary,var(--awa-brand,#b73337))!important;'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'padding:0!important}'
            . $toggle . ' :is(svg,i,span,::before,::after){'
            . 'background:transparent!important;border:0!important;box-shadow:none!important;'
            . 'color:inherit!important}'
            . $toggle . '[aria-expanded="true"]{'
            . 'background:var(--awa-primary,var(--awa-brand,#b73337))!important;'
            . 'background-color:var(--awa-primary,var(--awa-brand,#b73337))!important;'
            . 'color:#fff!important;border-radius:var(--awa-radius,8px)!important}'
            . $toggle . '[aria-expanded="true"] :is(svg,i,span,::before,::after){'
            . 'color:#fff!important}'
            . '}';

        return '/*§ham-flat-r34-inline*/'
            . '@layer awa-visual-priority{' . $flat . '}'
            ;
    }

    /**
     * r35a: mobile bottom nav — equal 4 columns.
     * space-between + intrinsic widths (Deptos ~85px vs Início 44px) made gaps uneven (118/77/106).
     */
    public static function mobileBottomNavEqualR35Rules(): string
    {
        $nav = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' nav.fixed-bottom';
        $ul = $nav . ' ul.mobile-bottom-link';
        $li = $ul . ' > li';
        $hit = $li . ' > :is(a,button)';

        $core = $nav . '{width:100%!important}'
            . $nav . ' .link-on-bottom{width:100%!important;display:block!important}'
            . $ul . '{'
            . 'display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;'
            . 'width:100%!important;max-width:100%!important;margin:0!important;padding:0!important;'
            . 'gap:0!important;justify-content:normal!important;align-items:stretch!important;'
            . 'list-style:none!important}'
            . $li . '{'
            . 'display:flex!important;justify-content:center!important;align-items:stretch!important;'
            . 'width:auto!important;min-width:0!important;max-width:none!important;'
            . 'flex:unset!important;margin:0!important;padding:0!important}'
            . $hit . '{'
            . 'display:inline-flex!important;flex-direction:column!important;'
            . 'align-items:center!important;justify-content:center!important;'
            . 'width:100%!important;min-width:0!important;max-width:100%!important;'
            . 'box-sizing:border-box!important;text-align:center!important;'
            . 'padding-inline:2px!important}';

        $media = '@media (max-width:767px){' . $core . '}';

        return '/*§bottom-nav-equal-r35-inline*/'
            . '@layer awa-visual-priority{' . $media . '}'
            ;
    }

    /**
     * r35b: shelf progress track — replace pill 9999px with control radius 8px (match desktop).
     */
    public static function owlProgressRadiusR35Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper';
        $track = $scope . ' .awa-owl-progress';
        $bar = $scope . ' .awa-owl-progress__bar';

        $core = $track . ',' . $bar . '{'
            . 'border-radius:var(--awa-radius,8px)!important;'
            . '-webkit-border-radius:var(--awa-radius,8px)!important}';

        return '/*§owl-progress-radius-r35-inline*/'
            . '@layer awa-visual-priority{' . $core . '}'
            ;
    }

    /**
     * r36: shelf carousel nav buttons — replace pill/circle (999px/50%) with 8px radius.
     * Keep surface/border for contrast on product imagery; only kill stadium/circle chrome.
     */
    public static function carouselNavRadiusR36Rules(): string
    {
        $btn = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper'
            . ' :is(.awa-owl-nav__btn,.awa-carousel__arrow,.awa-carousel__toggle,.awa-carousel__button)';

        $core = $btn . '{'
            . 'border-radius:var(--awa-radius,8px)!important;'
            . '-webkit-border-radius:var(--awa-radius,8px)!important}'
            . $btn . ':is(:hover,:focus,:focus-visible,:active){'
            . 'border-radius:var(--awa-radius,8px)!important;'
            . '-webkit-border-radius:var(--awa-radius,8px)!important}';

        return '/*§carousel-nav-radius-r36-inline*/'
            . '@layer awa-visual-priority{' . $core . '}'
            ;
    }

    /**
     * r38: autoplay toggle was parked at top-right gutter (same x as next),
     * visually glued to the last full card / NOVO badge. Park it in the
     * right gutter BELOW the card image band (bottom) + force 8px radius
     * (launches-toggle-fix still ships 999px pill). Desktop only — mobile
     * chrome is already hidden (r19).
     */
    public static function carouselToggleGutterR38Rules(): string
    {
        $home = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .content-top-home';
        $car = $home . ' .awa-shelf--carousel .awa-carousel.has-carousel-autoplay-toggle';
        $toggle = $car . ' > .awa-owl-nav.awa-carousel__nav .awa-carousel__toggle:not([hidden])';

        /* r38c/r71: park pause on the header row, LEFT of "Ver todos"
         * (link ~100px + gap => right:125px). r71: top -56→-63 aligns Y with
         * the flex-centered view-all (header 58 / link 44 / gap 12).
         * Niche panel-head sits closer (desc+viewAll 44px) => top:-52. */
        $core = '@media (min-width:768px){'
            . $car . '{'
            . 'padding-top:0!important}'
            . $toggle . '{'
            . 'top:-63px!important;'
            . 'bottom:auto!important;'
            . 'right:125px!important;'
            . 'left:auto!important;'
            . 'transform:none!important;'
            . 'border-radius:var(--awa-radius,8px)!important;'
            . '-webkit-border-radius:var(--awa-radius,8px)!important;'
            . 'z-index:7!important}'
            /* r71: niche — absolute left of panel Ver todos (not relative; that
             * overlapped prev arrow after r69). */
            . $home . ' .awa-home-niche-shelves .awa-carousel__toggle:not([hidden]){'
            . 'top:-52px!important;right:125px!important;left:auto!important;bottom:auto!important;'
            . 'position:absolute!important;transform:none!important;z-index:7!important}'
            . '}';

        return '/*§carousel-toggle-gutter-r71-inline*/'
            . '@layer awa-visual-priority{' . $core . '}'
            ;
    }

    /**
     * r37: mobile bottom nav inactive hierarchy.
     * themes.min.css forced ALL links to rgb(183,51,55); only active should be primary.
     * Inactive = slate secondary (visible on white); active / Deptos expanded = brand.
     */
    public static function mobileBottomNavInactiveR37Rules(): string
    {
        $nav = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body'
            . ' nav.fixed-bottom';
        $hit = $nav . ' ul.mobile-bottom-link > li > :is(a,button)';
        $icon = $hit . ' :is(.icon,i,svg)';
        $active = $nav . ' ul.mobile-bottom-link > li.active > a,'
            . $nav . ' ul.mobile-bottom-link > li > a[aria-current="page"]';
        $activeIcon = $nav . ' ul.mobile-bottom-link > li.active > a :is(.icon,i,svg),'
            . $nav . ' ul.mobile-bottom-link > li > a[aria-current="page"] :is(.icon,i,svg)';
        $dept = $nav . ' ul.mobile-bottom-link > li > button.toggle-nav-footer';
        $deptOpen = $dept . '[aria-expanded="true"]';
        $deptClosed = $dept . ':not([aria-expanded="true"])';
        $deptOpenIcon = $deptOpen . ' :is(.icon,i,svg)';
        $deptClosedIcon = $deptClosed . ' :is(.icon,i,svg)';
        /* Literal slate — --awa-text-secondary resolves to #475569 and splits Conta vs Deptos. */
        $muted = '#64748b';
        $brand = 'var(--awa-primary,var(--awa-brand,#b73337))';

        $core = $hit . '{color:' . $muted . '!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'box-shadow:none!important;border-color:transparent!important}'
            . $icon . '{color:inherit!important}'
            . $active . '{color:' . $brand . '!important}'
            . $activeIcon . '{color:' . $brand . '!important}'
            . $deptClosed . '{color:' . $muted . '!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'box-shadow:none!important;outline:none!important}'
            . $deptClosedIcon . '{color:' . $muted . '!important}'
            . $deptOpen . '{color:' . $brand . '!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'box-shadow:none!important}'
            . $deptOpenIcon . '{color:' . $brand . '!important}';

        $media = '@media (max-width:767px){' . $core . '}';

        return '/*§bottom-nav-inactive-r37c-inline*/'
            . '@layer awa-visual-priority{' . $media . '}'
            ;
    }

    public static function footerDenseDesktopR67Rules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper :is(.page_footer,.page-footer)';

        return '@media (min-width:992px){'
            . $scope . ' ul.awa-footer-categories-list>li>a,'
            . $scope . ' ul.awa-footer-categories-list a{'
            . 'min-height:28px!important;height:auto!important;padding:4px 8px!important;'
            . 'line-height:1.25!important;font-size:12px!important}'
            . $scope . ' ul.awa-footer-categories-list{gap:4px 8px!important;row-gap:4px!important}'
            . $scope . ' .awa-footer-newsletter{'
            . 'padding:6px 12px!important;padding-block:6px!important;margin-bottom:6px!important}'
            . $scope . ' .awa-footer-newsletter .awa-newsletter-wrapper{gap:12px!important}'
            . $scope . ' .awa-newsletter-info{'
            . 'display:grid!important;grid-template-columns:32px minmax(0,1fr)!important;'
            . 'gap:10px!important;align-items:center!important;min-height:0!important}'
            . $scope . ' .awa-newsletter-icon{'
            . 'width:32px!important;height:32px!important;min-width:32px!important;'
            . 'min-height:32px!important;max-width:32px!important;max-height:32px!important;margin:0!important}'
            . $scope . ' :is(.awa-newsletter-title,.velaNewsletterTitle){'
            . 'font-size:15px!important;line-height:1.2!important;margin:0 0 2px!important}'
            . $scope . ' .awa-newsletter-desc{margin:0!important;font-size:12px!important;line-height:1.3!important}'
            . $scope . ' #newsletter-validate-detail :is(.field.newsletter,.control,.actions,'
            . 'input[type=email],button.action.subscribe){'
            . 'height:44px!important;min-height:44px!important}'
            . $scope . ' .awa-footer-bottom__logo-img{height:36px!important;max-height:36px!important}'
            . $scope . ' .awa-footer-sec-seals :is(li,img,.awa-seal){min-height:0!important;max-height:36px!important}'
            . $scope . ' .awa-footer-sec-seals img{height:36px!important;max-height:36px!important;width:auto!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__row{padding:4px 0!important;gap:8px 12px!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright{padding:4px 6px!important}'
            . $scope . ' .awa-footer-atendimento__store{padding:4px 6px!important;margin:0!important}'
            . $scope . ' .awa-footer-atendimento__store-badge{margin:0 0 2px!important;padding:2px 6px!important}'
            . $scope . ' .awa-footer-atendimento :is(.awa-footer-atendimento__phone,'
            . '.awa-footer-atendimento__email){margin-bottom:2px!important}'
            . $scope . ' .footer-container .vela-content.velaFooterMenu{padding:6px 8px!important}'
            . $scope . ' .footer-container .row.rowFlexMargin{padding-block:2px!important;gap:10px 14px!important}'
            /* r68: trust/atendimento/copyright — page~793 ainda alto (atend 286, trust items 44, disc 33) */
            . $scope . ' .awa-footer-trust-bar{padding-block:4px!important;margin-bottom:4px!important}'
            . $scope . ' .awa-footer-trust-grid{gap:6px 10px!important;padding-block:2px!important}'
            . $scope . ' .awa-footer-trust-item{padding:2px 0!important;gap:8px!important;min-height:0!important}'
            . $scope . ' .awa-footer-trust-icon{'
            . 'flex:0 0 24px!important;width:24px!important;height:24px!important;'
            . 'min-width:24px!important;min-height:24px!important;max-height:24px!important}'
            . $scope . ' .awa-footer-trust-copy{gap:0!important;min-height:0!important}'
            . $scope . ' .awa-footer-trust-copy strong{font-size:12px!important;line-height:1.2!important}'
            . $scope . ' .awa-footer-trust-copy span{'
            . 'font-size:11px!important;line-height:1.2!important;min-height:0!important;'
            . 'max-height:none!important}'
            . $scope . ' .awa-footer-trust-copy span:empty{display:none!important}'
            . $scope . ' .awa-footer-atendimento{gap:2px!important}'
            . $scope . ' .awa-footer-atendimento .velaFooterTitle,'
            . $scope . ' .awa-footer-atendimento .awa-footer-section__toggle{'
            . 'margin:0 0 2px!important;margin-bottom:2px!important}'
            . $scope . ' .awa-footer-atendimento__store{'
            . 'padding:2px 6px!important;gap:1px!important}'
            . $scope . ' .awa-footer-atendimento__store-address{'
            . 'font-size:11px!important;line-height:1.25!important;margin:0!important}'
            . $scope . ' .awa-footer-atendimento__store-badge{'
            . 'margin:0!important;padding:1px 5px!important;font-size:11px!important;line-height:1.2!important}'
            . $scope . ' .awa-footer-atendimento :is(.awa-footer-atendimento__phone,'
            . '.awa-footer-atendimento__email){margin:0!important}'
            . $scope . ' .awa-footer-atendimento :is(.awa-footer-atendimento__phone a,'
            . '.awa-footer-atendimento__email a){padding-block:2px!important;font-size:12.5px!important}'
            . $scope . ' .awa-footer-newsletter{'
            . 'padding:4px 12px!important;padding-block:4px!important;margin-bottom:4px!important}'
            . $scope . ' .footer-bottom{padding:6px 16px!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright{padding:2px 4px!important}'
            . $scope . ' .footer-bottom .awa-footer-copyright__disclaimer{'
            . 'font-size:10px!important;line-height:1.25!important;margin:0!important}'
            . $scope . ' .footer-bottom .awa-footer-copyright__legal{'
            . 'font-size:10.5px!important;line-height:1.25!important;gap:4px 8px!important}'
            . $scope . ' .awa-footer-devby{padding-block:2px!important;min-height:0!important}'
            . $scope . ' .awa-footer-devby__inner{min-height:0!important;gap:8px!important}'
            . $scope . ' section.awa-footer-categories-expand{'
            . 'margin-top:2px!important;padding:4px 0 2px!important}'
            /* r69: labels/CNPJ/seals/disclaimer — page~699 (atend labels 40, cnpj 32, disc 11px) */
            . $scope . ' #footer.footer-container{padding:4px 16px!important}'
            . $scope . ' #footer.footer-container .row.rowFlexMargin,'
            . $scope . ' .footer-container .row.rowFlexMargin{'
            . 'gap:8px 12px!important;padding-block:0!important}'
            . $scope . ' .awa-footer-atendimento .velaFooterTitle,'
            . $scope . ' .awa-footer-atendimento .awa-footer-section__toggle,'
            . $scope . ' .footer-container .velaFooterTitle{'
            . 'margin:0 0 2px!important;margin-bottom:2px!important;padding-bottom:0!important}'
            . $scope . ' .awa-footer-atendimento :is(p.awa-footer-atendimento__label,'
            . 'p.awa-footer-atendimento__label--social){'
            . 'position:absolute!important;width:1px!important;height:1px!important;'
            . 'padding:0!important;margin:-1px!important;overflow:hidden!important;'
            . 'clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}'
            . $scope . ' .awa-footer-atendimento{position:relative!important;gap:2px!important}'
            . $scope . ' .awa-footer-atendimento :is(.awa-footer-atendimento__phone a,'
            . '.awa-footer-atendimento__email a){'
            . 'padding-block:1px!important;line-height:1.25!important;font-size:12.5px!important}'
            . $scope . ' .awa-footer-pro__social{margin-top:0!important;min-height:0!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-pay-sec__label,.awa-footer-muted-label,'
            . '.awa-footer-sec__label){display:none!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-pay-sec,.awa-footer-sec){'
            . 'gap:4px!important}'
            . $scope . ' .awa-footer-sec-seals :is(li,img,.awa-seal){'
            . 'min-height:0!important;max-height:28px!important;height:auto!important}'
            . $scope . ' .awa-footer-sec-seals img{height:28px!important;max-height:28px!important}'
            . $scope . ' .awa-footer-bottom__logo-img{height:32px!important;max-height:32px!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__row{padding:2px 0!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright{padding:2px 0!important}'
            . $scope . ' .footer-bottom :is(p.awa-footer-copyright__disclaimer,'
            . '.awa-footer-copyright__disclaimer){'
            . 'font-size:10px!important;line-height:1.25!important;margin:0!important}'
            . $scope . ' .footer-bottom :is(p.awa-footer-copyright__legal,'
            . '.awa-footer-copyright__legal){'
            . 'font-size:10px!important;line-height:1.25!important;gap:4px 6px!important;'
            . 'align-items:center!important;min-height:0!important}'
            . $scope . ' .footer-bottom .awa-footer-cnpj-badge{'
            . 'font-size:10px!important;line-height:1.2!important;padding:1px 6px!important;'
            . 'min-height:0!important;height:auto!important;margin:0!important}'
            . $scope . ' .velaFooterLinks a{padding-block:2px!important;min-height:0!important;'
            . 'line-height:1.3!important}'
            . $scope . ' .awa-footer-newsletter{margin-bottom:2px!important}'
            . $scope . ' .awa-footer-trust-bar{margin-bottom:2px!important}'
            /* r70: links/disclaimer/store — page~606 (suporte 197=links 26px, disc 11px) */
            . $scope . '{'
            . 'margin-top:8px!important}'
            . $scope . ' :is(.velaFooterLinks a,.awa-footer-atendimento__actions a,'
            . '.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a){'
            . 'padding-block:1px!important;padding-top:1px!important;padding-bottom:1px!important;'
            . 'min-height:0!important;line-height:1.25!important}'
            . $scope . ' .footer-bottom{padding:4px 16px!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner{gap:4px!important}'
            . $scope . ' .awa-footer-bottom__copyright '
            . ':is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer),'
            . $scope . ' .footer-bottom :is(p.awa-footer-copyright__legal,p.awa-footer-copyright__disclaimer){'
            . 'font-size:10px!important;line-height:1.4!important}'
            . $scope . ' .footer-bottom p.awa-footer-copyright__disclaimer,'
            . $scope . ' .awa-footer-bottom__copyright .awa-footer-copyright__disclaimer{'
            . 'display:block!important;overflow:visible!important;'
            . 'font-size:10px!important;line-height:1.4!important;max-height:none!important}'
            . $scope . ' .awa-footer-atendimento__store-badge{display:none!important}'
            . $scope . ' .awa-footer-atendimento__store-address{'
            . 'white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;'
            . 'font-size:11px!important;line-height:1.2!important;max-width:100%!important}'
            . $scope . ' .awa-footer-atendimento__store{padding:2px 4px!important;gap:0!important}'
            . $scope . ' section.awa-footer-categories-expand{'
            . 'margin-top:0!important;padding:2px 0!important}'
            . $scope . ' ul.awa-footer-categories-list{gap:2px 6px!important}'
            . $scope . ' .awa-footer-devby{padding-block:0!important;max-height:36px!important}'
            . $scope . ' .awa-footer-devby__inner{min-height:32px!important;height:32px!important}'
            . $scope . ' .awa-footer-newsletter{padding-block:2px!important}'
            . $scope . ' .awa-footer-trust-bar{padding-block:2px!important;margin-bottom:2px!important}'
            /* r72: container 24/24 (~48px) + colunas 171 + news info 58 — evidência Playwright */
            . $scope . ' #footer.footer-container>.container,'
            . $scope . ' .footer-container>.container{'
            . 'padding:4px 16px!important;padding-top:4px!important;padding-bottom:4px!important;'
            . 'padding-block:4px!important}'
            . $scope . ' .awa-newsletter-info{'
            . 'grid-template-columns:24px minmax(0,1fr)!important;gap:8px!important;min-height:0!important;'
            . 'max-height:none!important;overflow:visible!important}'
            . $scope . ' .awa-newsletter-icon{'
            . 'width:24px!important;height:24px!important;min-width:24px!important;'
            . 'min-height:24px!important;max-height:24px!important}'
            . $scope . ' :is(.awa-newsletter-title,.velaNewsletterTitle){'
            . 'font-size:13px!important;line-height:1.15!important;margin:0!important}'
            . $scope . ' .awa-newsletter-desc{'
            . 'font-size:11px!important;line-height:1.35!important;margin:0!important;'
            . 'max-height:none!important;overflow:visible!important;white-space:normal!important;'
            . 'text-overflow:clip!important}'
            . $scope . ' #newsletter-validate-detail :is(.field.newsletter,.control,.actions,'
            . 'input[type=email],button.action.subscribe){'
            . 'height:44px!important;min-height:44px!important}'
            . $scope . ' .awa-footer-newsletter{padding:2px 12px!important;margin-bottom:0!important}'
            . $scope . ' .awa-footer-trust-bar{padding-block:0!important;margin-bottom:0!important}'
            . $scope . ' .awa-footer-trust-grid{padding-block:0!important;gap:4px 8px!important}'
            . $scope . ' .footer-container .vela-content.velaFooterMenu{padding:0 4px!important}'
            . $scope . ' .velaFooterLinks a{padding-block:0!important;line-height:1.2!important;font-size:12.5px!important}'
            . $scope . ' .awa-footer-atendimento__actions{gap:2px!important;margin:0!important}'
            . $scope . ' .awa-footer-pro__social{margin:2px 0 0!important;gap:4px!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright{padding:0!important;margin:0!important}'
            . $scope . ' .awa-footer-devby__title{margin:0!important;font-size:11px!important}'
            . $scope . ' .awa-footer-devby{max-height:28px!important}'
            . $scope . ' .awa-footer-devby__inner{min-height:24px!important;height:24px!important}}';
    }

    /**
     * Contrato visual mínimo do footer aplicado de forma síncrona.
     *
     * O align-grid e o footer terminal completos são carregados abaixo da dobra. Estas regras
     * usam a geometria final desde o primeiro layout e vencem o content-visibility tardio,
     * evitando logos 0x0, grids flex provisórios e categorias ilegíveis.
     */
    /**
     * Home: só geometria/CLS do footer (~3KB). A folha terminal (8KB gzip)
     * carrega print→all imediato e traz o restante.
     */
    public static function footerCriticalStabilityRulesHome(): string
    {
        $footerRoot = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper footer.page-footer';
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            . 'footer.page-footer > .page_footer';

        return self::footerCrossPageSurfaceRules()
            . $footerRoot . '{'
            . 'padding:0!important;padding-block:0!important;'
            . 'min-height:0!important;height:auto!important;max-height:none!important}'
            . $scope . '{'
            . 'margin-top:16px!important;margin-bottom:0!important;'
            . 'padding:0!important;padding-block:0!important;'
            . 'min-height:0!important;height:auto!important;max-height:none!important}'
            . $scope . ' :is(.footer-bottom,.awa-footer-devby,section.awa-footer-categories-expand){'
            . 'content-visibility:visible!important;contain:none!important;'
            . 'contain-intrinsic-size:unset!important;box-sizing:border-box!important}'
            . $scope . ' :is(.awa-footer-bottom__logo-img,.awa-footer-devby__logo,'
            . '.awa-footer-pay-logos img,.awa-footer-sec-seals img){'
            . 'content-visibility:visible!important;contain:none!important}'
            . $scope . ' .awa-footer-pay-logos{'
            . 'min-height:34px!important;height:34px!important}'
            . $scope . ' .awa-footer-pay-logos img{'
            . 'display:block!important;width:46px!important;height:28px!important;'
            . 'max-width:46px!important;max-height:28px!important;object-fit:contain!important}'
            . $scope . ' .awa-footer-bottom__logo-img{'
            . 'display:block!important;width:auto!important;height:44px!important;'
            . 'max-width:142px!important;max-height:44px!important;'
            . 'opacity:1!important}'
            . $scope . ' .awa-footer-devby{'
            . 'display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'min-height:56px!important}'
            . $scope . ' .awa-footer-devby__logo{'
            . 'display:block!important;width:57px!important;height:30px!important;'
            . 'min-width:57px!important;max-width:57px!important;max-height:30px!important;'
            . 'object-fit:contain!important;opacity:1!important;visibility:visible!important}'
            . self::footerContentAxisRules();
    }

    public static function footerCriticalStabilityRules(): string
    {
        return '@layer awa-cta-green;';
    }

    public static function footerCategoriesGridFillRules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper :is(.page_footer,.page-footer)';

        return '@media (max-width:767px){'
            . $scope . ' ul.awa-footer-categories-list>li{'
            . 'display:block!important;min-width:0!important}'
            . $scope . ' ul.awa-footer-categories-list>li>a{'
            . 'box-sizing:border-box!important;display:flex!important;'
            . 'justify-content:center!important;align-items:center!important;'
            . 'width:100%!important;max-width:100%!important;min-width:0!important;'
            . 'min-height:44px!important}}'
            . '@media (min-width:768px){'
            . $scope . ' ul.awa-footer-categories-list>li>a{'
            . 'box-sizing:border-box!important;display:flex!important;'
            . 'justify-content:center!important;align-items:center!important;'
            . 'width:100%!important;max-width:100%!important;min-width:0!important;'
            . 'min-height:28px!important;height:auto!important;padding:4px 8px!important}}';
    }

    /**
     * Desktop: esconde toggle mobile e usa grid 2 colunas (título | pills).
     * Evidência: visual-fixes 3-col + align-grid inline-flex deixavam "Ver todas" visível em 1564px.
     */
    public static function footerCategoriesDesktopLayoutRules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper :is(.page_footer,.page-footer)';

        return '@media (min-width:768px){'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__inner{'
            . 'display:grid!important;'
            . 'grid-template-columns:minmax(96px,max-content) minmax(0,1fr)!important;'
            . 'align-items:center!important;column-gap:clamp(14px,2vw,28px)!important;row-gap:10px!important}'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__toggle{'
            . 'display:none!important;visibility:hidden!important;pointer-events:none!important}'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__heading{'
            . 'grid-column:1!important;grid-row:1!important}'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__panel{'
            . 'grid-column:2!important;grid-row:1!important;display:block!important;'
            . 'max-height:none!important;height:auto!important;visibility:visible!important}'
            . $scope . ' section.awa-footer-categories-expand .awa-footer-categories-expand__panel[hidden]{'
            . 'display:block!important}'
            . $scope . ' ul.awa-footer-categories-list{'
            . 'display:flex!important;flex-wrap:wrap!important;grid-template-columns:none!important;'
            . 'gap:6px 8px!important;width:auto!important;height:auto!important}}';
    }

    public static function footerCategoriesDesktopLayoutScriptTag(): string
    {
        $js = <<<'JS'
(function (w, d) {
    'use strict';
    function isFooterMobile() {
        return w.matchMedia && w.matchMedia('(max-width: 767px)').matches;
    }
    function syncFooterCategoriesShell() {
        var mobile = isFooterMobile();
        d.querySelectorAll('[data-awa-categories-toggle], .awa-footer-categories-expand__toggle').forEach(function (btn) {
            var panelId = btn.getAttribute('aria-controls');
            var panel = panelId ? d.getElementById(panelId) : null;
            if (!panel) {
                return;
            }
            if (mobile) {
                if (btn.getAttribute('aria-expanded') !== 'true') {
                    btn.setAttribute('aria-expanded', 'false');
                    btn.classList.remove('is-expanded');
                    panel.setAttribute('aria-hidden', 'true');
                }
                return;
            }
            btn.setAttribute('aria-expanded', 'true');
            btn.classList.add('is-expanded');
            panel.removeAttribute('hidden');
            panel.removeAttribute('inert');
            panel.setAttribute('aria-hidden', 'false');
        });
    }
    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', syncFooterCategoriesShell, { once: true });
    } else {
        syncFooterCategoriesShell();
    }
    w.addEventListener('resize', syncFooterCategoriesShell, { passive: true });
})(window, document);
JS;

        return '<script id="awa-footer-categories-desktop-layout-v2">' . $js . '</script>';
    }

    public static function injectFooterCategoriesDesktopLayoutScript(string $html): string
    {
        if (!str_contains($html, 'awa-footer-categories-expand')) {
            return $html;
        }

        $html = preg_replace(
            '/<script\\s+id="awa-footer-categories-desktop-layout(?:-v2)?"[^>]*>.*?<\\/script>\\s*/is',
            '',
            $html
        ) ?? $html;

        $tag = self::footerCategoriesDesktopLayoutScriptTag();
        $pos = stripos($html, '</body>');
        if ($pos === false) {
            return $html . $tag;
        }

        return substr($html, 0, $pos) . $tag . substr($html, $pos);
    }

    /**
     * Lock final do footer em superficie neutra: vence locks antigos vermelhos sem alterar markup.
     */
    public static function footerVtexNeutralTerminalRules(): string
    {
        $scope = 'html body#html-body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper :is(.page_footer,.page-footer)';
        $hard = $scope . ' #footer.footer-container';

        return '/*awa-footer-vtex-neutral-terminal-v1*/'
            . $scope . '{'
            . 'background:var(--awa-bg,Canvas)!important;background-color:var(--awa-bg,Canvas)!important;'
            . 'border-top:1px solid var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas))!important;'
            . 'color:var(--awa-text,CanvasText)!important;font-size:13px!important;line-height:1.4!important;'
            . 'text-shadow:none!important}'
            . $scope . ' :is(#footer.footer-container,.footer-container,.container,.vela-content,.velaFooterMenu,.velaBlock){'
            . 'background:transparent!important;background-color:transparent!important;color:var(--awa-text,CanvasText)!important;'
            . 'box-shadow:none!important;text-shadow:none!important}'
            . $hard . '{'
            . 'box-sizing:border-box!important;margin-inline:auto!important;max-width:100%!important;'
            . 'padding-block:16px!important;padding-inline:0!important;width:100%!important}'
            . $hard . ' :is(a,p,li,span,strong,.velaFooterTitle,h4,.awa-footer-section__toggle){'
            . 'color:var(--awa-text,CanvasText)!important;text-shadow:none!important}'
            . $hard . ' :is(.velaFooterLinks a,.awa-footer-atendimento__actions a,'
            . '.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link){'
            . 'align-items:center!important;color:var(--awa-text-secondary,var(--awa-text-muted,CanvasText))!important;'
            . 'display:inline-flex!important;font-size:13px!important;line-height:1.35!important;min-height:0!important;'
            . 'padding-block:4px!important;text-decoration:none!important}'
            . '@media (min-width:768px){'
            . $hard . ' .velaFooterLinks li{margin:0!important;padding:0!important}'
            . $hard . ' :is(.velaFooterLinks a,.awa-footer-atendimento__actions a,'
            . '.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link){'
            . 'min-height:0!important;padding-block:4px!important}'
            . '}'
            . $hard . ' :is(.velaFooterLinks a,.awa-footer-atendimento__actions a,'
            . '.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link):hover{'
            . 'color:var(--awa-primary,var(--awa-red,currentColor))!important}'
            . $hard . ' .row.rowFlexMargin{'
            . 'align-items:start!important;display:grid!important;gap:16px!important;'
            . 'grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(280px,.86fr)!important;'
            . 'padding-block:8px!important}'
            . $hard . ' :is(.vela-content.velaFooterMenu,.awa-footer-atendimento){'
            . 'border:0!important;box-shadow:none!important;min-width:0!important;padding:8px!important}'
            . $hard . ' .awa-footer-atendimento__store{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-radius:0!important;box-shadow:none!important;'
            . 'color:var(--awa-text,CanvasText)!important;padding:8px 0!important}'
            . $scope . ' .awa-footer-newsletter{'
            . 'background:#fff!important;background-color:#fff!important;'
            . 'border:0!important;border-radius:0!important;'
            . 'margin:0 0 12px!important;padding:14px 16px!important}'
            . $scope . ' :is(.footer-bottom,.awa-footer-devby){'
            . 'background:#fff!important;'
            . 'background-color:#fff!important;'
            . 'border:0!important;border-top:0!important;'
            . 'color:var(--awa-text,CanvasText)!important;text-shadow:none!important}'
            . $scope . ' section.awa-footer-categories-expand{'
            . 'background:var(--awa-bg,#fff)!important;'
            . 'background-color:var(--awa-bg,#fff)!important;'
            . 'border:0!important;border-top:0!important;border-bottom:0!important;'
            . 'box-shadow:none!important;color:var(--awa-text,CanvasText)!important;text-shadow:none!important}'
            . $scope . ' .footer-bottom{'
            . 'box-sizing:border-box!important;margin:0!important;margin-left:0!important;margin-right:0!important;'
            . 'max-width:100%!important;padding:0!important;padding-left:0!important;padding-right:0!important;'
            . 'width:100%!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner{'
            . 'box-sizing:border-box!important;display:grid!important;gap:10px!important;'
            . 'grid-template-columns:minmax(0,1fr)!important;justify-items:stretch!important;'
            . 'margin-inline:0!important;max-width:none!important;'
            . 'padding:12px 16px!important;width:100%!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row{'
            . 'box-sizing:border-box!important;display:grid!important;'
            . 'grid-template-columns:minmax(96px,auto) minmax(0,1fr) minmax(0,1fr)!important;'
            . 'align-items:start!important;justify-items:center!important;gap:10px 24px!important;'
            . 'justify-self:stretch!important;margin:0!important;max-width:none!important;'
            . 'min-width:0!important;width:100%!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row>[class*="col-"]:first-child{'
            . 'align-self:center!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row>[class*="col-"]{'
            . 'box-sizing:border-box!important;float:none!important;margin:0!important;max-width:none!important;'
            . 'min-width:0!important;padding-inline:0!important;width:auto!important;'
            . 'justify-self:center!important;text-align:center!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-pay-sec,.awa-footer-sec){'
            . 'height:auto!important;margin:0!important;min-height:0!important;'
            . 'display:flex!important;flex-direction:column!important;align-items:center!important;'
            . 'text-align:center!important;width:100%!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-pay-logos,.awa-footer-sec-seals){'
            . 'gap:6px 8px!important;margin:0!important;padding:0!important;'
            . 'display:flex!important;justify-content:center!important;align-items:center!important;'
            . 'flex-wrap:wrap!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'border:0!important;border-top:0!important;border-radius:0!important;box-shadow:none!important;'
            . 'color:var(--awa-text-secondary,var(--awa-text-muted,CanvasText))!important;'
            . 'box-sizing:border-box!important;justify-self:stretch!important;margin:0!important;'
            . 'max-width:none!important;padding:8px 0!important;text-align:center!important;width:100%!important}'
            . $scope . ' .footer-bottom .awa-footer-cnpj-badge{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:fit-content!important;max-width:100%!important;margin-inline:auto!important;'
            . 'margin-block:0!important;vertical-align:baseline!important}'
            . $scope . ' .footer-bottom .awa-footer-copyright__legal{'
            . 'display:flex!important;align-items:center!important;'
            . 'justify-content:center!important;gap:6px 10px!important;text-align:center!important}'
            . '@media (min-width:992px){'
            . $scope . ' .footer-bottom .awa-footer-copyright__legal{'
            . 'flex-direction:row!important;flex-wrap:wrap!important}'
            . $scope . ' .footer-bottom .awa-footer-sec-seals .awa-seal{'
            . 'min-height:36px!important;min-width:36px!important;padding:4px 8px!important}}'
            . '@media (max-width:991px){'
            . $scope . ' .footer-bottom .awa-footer-copyright__legal{flex-direction:column!important}'
            . '}'
            . $scope . ' .footer-bottom :is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer){'
            . 'box-sizing:border-box!important;width:100%!important;'
            . 'max-width:none!important;margin-inline:0!important;text-align:center!important;'
            . 'line-height:1.35!important}'
            . $scope . ' .footer-bottom .awa-footer-copyright__disclaimer{'
            . 'display:block!important;font-size:10.5px!important;margin-top:2px!important}'
            . '@media (max-width:991px){'
            . $hard . ' .row.rowFlexMargin{grid-template-columns:minmax(0,1fr)!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner{grid-template-columns:minmax(0,1fr)!important;text-align:left!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row{'
            . 'grid-template-columns:minmax(0,1fr)!important;justify-items:center!important;'
            . 'max-width:100%!important;text-align:center!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-pay-logos,.awa-footer-sec-seals){'
            . 'justify-content:center!important}'
            . '}'
            . '@media (max-width:767px){'
            . $scope . ' :is(.velaFooterLinks a,.awa-footer-section__toggle,.awa-footer-atendimento__actions a,'
            . '.awa-footer-atendimento__phone a,.awa-footer-atendimento__email a,.awa-footer-devby__link){'
            . 'min-height:44px!important}'
            . $hard . '{padding:12px 16px!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner{padding:12px 16px!important}'
            . '}';
    }

    /**
     * Selos img externas, contraste de labels e chevron de categorias — final-wins pós refine/bugfix.
     */
    public static function footerSealImgPolishRules(): string
    {
        $scope = 'html body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)';

        return $scope . ' .footer-bottom :is(.awa-footer-pay-logos,.awa-footer-sec-seals){'
            . 'opacity:1!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-muted-label,.awa-footer-pay-sec__label){'
            . 'color:var(--awa-text,#333333)!important;font-size:12px!important;font-weight:600!important;'
            . 'letter-spacing:.02em!important;line-height:1.35!important;margin-block:0 8px!important}'
            . $scope . ' .footer-bottom .awa-footer-sec-seals{'
            . 'display:flex!important;flex-wrap:wrap!important;gap:8px 10px!important;'
            . 'align-items:center!important;list-style:none!important;margin:0!important;padding:0!important}'
            . $scope . ' .footer-bottom .awa-footer-sec-seals .awa-seal{'
            . 'background:var(--awa-bg,#ffffff)!important;'
            . 'border:1px solid var(--awa-border,#e5e5e5)!important;border-radius:4px!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'min-height:44px!important;min-width:44px!important;padding:6px 10px!important;'
            . 'text-decoration:none!important}'
            . $scope . ' .footer-bottom .awa-footer-sec-seals .awa-seal:hover{'
            . 'border-color:color-mix(in srgb,var(--awa-primary,#b73337) 45%,var(--awa-border,#e5e5e5))!important}'
            . $scope . ' .footer-bottom .awa-footer-sec-seals .awa-seal__img{'
            . 'display:block!important;height:auto!important;max-width:100%!important;object-fit:contain!important}'
            . $scope . ' .footer-bottom .awa-footer-copyright__legal{'
            . 'color:var(--awa-text,#333333)!important;text-wrap:pretty!important}'
            . $scope . ' .footer-bottom .awa-footer-copyright__disclaimer{'
            . 'color:color-mix(in srgb,var(--awa-text,#333333) 78%,transparent)!important;'
            . 'text-wrap:pretty!important}'
            . $scope . ' .awa-footer-categories-expand__toggle .awa-footer-categories-expand__icon{'
            . 'display:inline-flex!important;transition:transform 200ms cubic-bezier(.25,1,.5,1)!important}'
            . $scope . ' .awa-footer-categories-expand__toggle.is-expanded .awa-footer-categories-expand__icon{'
            . 'transform:rotate(180deg)!important}'
            . '@media (prefers-reduced-motion:reduce){' . $scope
            . ' .awa-footer-categories-expand__toggle .awa-footer-categories-expand__icon{'
            . 'transition:none!important}}'
            . '@media (max-width:767px){' . $scope . ' .footer-bottom .awa-footer-pay-logos{'
            . 'display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;'
            . 'gap:8px!important;justify-items:center!important;width:100%!important}}';
    }

    /**
     * Eixo 1280px único: neutraliza .container Bootstrap aninhado e alinha categorias/bottom ao header.
     */
    public static function footerAlignShellRules(): string
    {
        $scope = 'html body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer)';
        $home = 'html body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper :is(.page_footer,.page-footer)';
        $shell = 'max-width:var(--awa-home-terminal-shell,min(100%,1280px))!important;'
            . 'width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important';
        $innerReset = 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important';

        $innerContainer = '#footer.footer-container > .container,'
            . '#footer.footer-container .awa-footer-newsletter > .container';

        // Physical properties (padding-left/right) alongside logical padding-inline to guarantee
        // override against awa-layout-bundle which uses physical `padding: 0 16px !important`.
        $shellPadReset = 'padding-inline:0!important;padding-left:0!important;padding-right:0!important';

        // Trust-bar inner container must be aligned to the 1280px axis.
        $trustBar = 'max-width:var(--awa-site-shell,min(100%,1280px))!important;'
            . 'width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:var(--awa-page-pad,24px)!important;box-sizing:border-box!important';

        return $scope . ' #footer.footer-container{' . $shellPadReset . '}'
            . $scope . ' ' . $innerContainer . '{' . $innerReset . '}'
            . $home . ' ' . $innerContainer . '{' . $innerReset . '}'
            . $scope . ' section.awa-footer-categories-expand > .container{' . $shell . '}'
            . $scope . ' .footer-bottom > .container,'
            . $scope . ' .footer-bottom .footer-bottom-inner{' . $innerReset . '}'
            . $scope . ' .awa-footer-trust-bar > .container{' . $trustBar . '}'
            . $scope . ' #footer .row.rowFlexMargin{gap:16px!important}'
            . '@media (min-width:768px){' . $scope . ' .velaFooterLinks li{margin:0!important;padding:0!important}'
            . $scope . ' .velaFooterLinks a{align-items:center!important;display:inline-flex!important;'
            . 'min-height:0!important;padding-block:4px!important}'
            . $scope . ' .awa-footer-atendimento .velaContent{gap:6px!important;display:flex!important;'
            . 'flex-direction:column!important}'
            . $scope . ' section.awa-footer-categories-expand{margin-top:12px!important;padding-top:12px!important}'
            . $scope . ' .velaFooterTitle{width:100%!important}}';
    }

    /**
     * Footer light/flat layout terminal: largura catalogo (1440), grid de colunas
     * responsivo (1/2/3) e titulos em sentence-case. Vence bundles tardios
     * (awa-visual-bugfix 800px, awa-ui-simplify-terminal col 50%).
     */
    public static function footerLightLayoutRules(): string
    {
        return 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-container{'
            . 'max-width:var(--awa-layout-max,1280px)!important;width:100%!important;margin-inline:auto!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-container .container>.row.rowFlexMargin{'
            . 'display:grid!important;grid-template-columns:1fr!important;gap:var(--awa-s-3,16px)!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-container .container>.row.rowFlexMargin::before,'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-container .container>.row.rowFlexMargin::after{'
            . 'display:none!important;content:none!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-container .container>.row.rowFlexMargin>[class*="col-"]{'
            . 'flex:initial!important;width:auto!important;max-width:none!important;min-width:0!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(.velaFooterTitle,.velaFooterMenu .velaFooterTitle){'
            . 'text-transform:none!important;letter-spacing:-.01em!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-trust-item{'
            . 'background:transparent!important;border:0!important;box-shadow:none!important;border-radius:0!important;'
            . 'padding-block:8px!important;min-height:0!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-trust-grid{'
            . 'padding-block:16px!important;gap:16px!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-newsletter{'
            . 'padding-block:24px!important;gap:24px!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(h2,h3).awa-newsletter-title{'
            . 'font-size:clamp(17px,1.4vw,20px)!important;line-height:1.25!important;font-weight:700!important;'
            . 'text-transform:none!important;letter-spacing:-.01em!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-newsletter-desc{'
            . 'font-size:15px!important;line-height:1.45!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) ul.velaFooterLinks a{'
            . 'text-align:start!important;justify-content:flex-start!important;line-height:1.45!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-newsletter-icon{'
            . 'background-color:rgba(183,51,55,.1)!important}'
            . '@media (min-width:768px){'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-container .container>.row.rowFlexMargin{'
            . 'grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:var(--awa-s-4,24px)!important}}'
            . '@media (min-width:992px){'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-container .container>.row.rowFlexMargin{'
            . 'grid-template-columns:repeat(3,minmax(0,1fr))!important}'
            . 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-newsletter{'
            . 'padding-block:6px!important;margin-bottom:6px!important}}';
    }

    /**
     * Footer bottom (logo/pagamentos/selos), trust grid responsivo, newsletter desktop,
     * categorias expand e focus-visible. Vence awa-super-global (flex no bottom row).
     */
    public static function footerBottomModernRules(): string
    {
        $scope = 'html body#html-body .page-wrapper :is(.page_footer,.page-footer)';

        return $scope . ' .awa-footer-trust-grid{'
            . 'display:grid!important;grid-template-columns:1fr!important;'
            . 'align-items:center!important;gap:16px!important}'
            . '@media (min-width:576px){' . $scope . ' .awa-footer-trust-grid{'
            . 'grid-template-columns:repeat(2,minmax(0,1fr))!important}}'
            . '@media (min-width:992px){' . $scope . ' .awa-footer-trust-grid{'
            . 'grid-template-columns:repeat(4,minmax(0,1fr))!important}}'
            . '@media (min-width:768px){' . $scope . ' .awa-newsletter-wrapper{'
            . 'display:flex!important;align-items:center!important;'
            . 'gap:16px!important;flex-wrap:nowrap!important}'
            . $scope . ' .awa-newsletter-form-container{'
            . 'flex:1!important;min-width:0!important;max-width:480px!important}}'
            . '@media (min-width:768px){' . $scope . ' .footer-bottom .awa-footer-bottom__row{'
            . 'display:grid!important;'
            . 'grid-template-columns:minmax(120px,1fr) minmax(0,2fr) minmax(0,1.4fr)!important;'
            . 'align-items:start!important;gap:16px!important;flex-wrap:nowrap!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__row::before,'
            . $scope . ' .footer-bottom .awa-footer-bottom__row::after{'
            . 'display:none!important;content:none!important}'
            . $scope . ' .footer-bottom .awa-footer-bottom__row>[class*="col-"]{'
            . 'flex:initial!important;width:auto!important;max-width:none!important;'
            . 'float:none!important;padding-inline:0!important}}'
            . '@media (max-width:767px){' . $scope . ' .footer-bottom .awa-footer-bottom__row{'
            . 'display:grid!important;grid-template-columns:1fr!important;'
            . 'justify-items:center!important;text-align:center!important;gap:20px!important}'
            . $scope . ' .footer-bottom :is(.awa-footer-pay-logos,.awa-footer-sec-seals){'
            . 'justify-content:center!important}}'
            . $scope . ' :is(.awa-footer-pay-sec__label,.awa-footer-muted-label){'
            . 'text-transform:none!important;letter-spacing:0!important;'
            . 'font-weight:600!important;font-size:12px!important}'
            . $scope . ' :is(.awa-footer-pay-logos,.awa-footer-sec-seals){'
            . 'display:flex!important;flex-wrap:wrap!important;'
            . 'gap:8px!important;align-items:center!important}'
            . $scope . ' .awa-footer-bottom__logo-img{'
            . 'max-height:44px!important;width:auto!important;opacity:.94!important}'
            . $scope . ' .awa-footer-bottom__copyright{'
            . 'margin-top:8px!important;padding-top:8px!important;'
            . 'border-top:0!important}'
            . '@media (min-width:992px){' . $scope . ' .footer-bottom .footer-bottom-inner{'
            . 'gap:12px!important;display:grid!important;grid-template-columns:minmax(0,1fr)!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner>.row.awa-footer-bottom__row{'
            . 'min-width:0!important;max-width:none!important;width:100%!important}'
            . $scope . ' .footer-bottom .footer-bottom-inner>.awa-footer-bottom__copyright{'
            . 'min-width:0!important;max-width:none!important;width:100%!important;'
            . 'margin-top:0!important;padding-top:8px!important;justify-self:stretch!important;'
            . 'text-align:center!important}}'
            . $scope . ' .awa-footer-bottom__copyright p{'
            . 'font-size:12px!important;line-height:1.45!important}'
            . $scope . ' section.awa-footer-categories-expand{'
            . 'border:0!important;border-top:0!important;'
            . 'background:var(--awa-bg,#fff)!important;'
            . 'background-color:var(--awa-bg,#fff)!important}'
            . '@media (min-width:768px){' . $scope . ' .awa-footer-categories-expand__toggle{'
            . 'display:none!important}'
            . $scope . ' .awa-footer-categories-expand__panel,'
            . $scope . ' .awa-footer-categories-expand__panel[hidden]{'
            . 'display:block!important;max-height:none!important;visibility:visible!important}'
            . $scope . ' ul.awa-footer-categories-list{'
            . 'display:flex!important;flex-wrap:wrap!important;gap:8px!important}}'
            . '@media (max-width:767px){' . $scope . ' .awa-footer-categories-expand__toggle{'
            . 'min-height:44px!important;width:100%!important;'
            . 'justify-content:space-between!important;font-weight:600!important}}'
            . '@media (max-width:575px){' . $scope . ' .awa-footer-trust-copy span{'
            . 'display:block!important;font-size:11px!important;line-height:1.35!important}}'
            . $scope . ' :is(.velaFooterLinks a,.awa-footer-section__toggle,.awa-seal,'
            . '.awa-footer-devby__link,.awa-footer-pro__social-link,'
            . '.awa-footer-bottom__logo-col a,.awa-footer-categories-expand__toggle):focus-visible{'
            . 'outline:2px solid var(--awa-primary,oklch(48% .14 20))!important;'
            . 'outline-offset:2px!important}'
            . $scope . ' #newsletter-validate-detail button.action.subscribe:focus-visible{'
            . 'outline:2px solid var(--awa-primary,oklch(48% .14 20))!important;'
            . 'outline-offset:2px!important}';
    }

    /**
     * Newsletter form, copyright/devby, pay pills e categorias — vence bundles tardios
     * (postaudit 0.75rem copyright, devby opacity .5, super-global pay pills).
     */
    public static function footerInteractionPolishRules(): string
    {
        $scope = 'html body#html-body .page-wrapper :is(.page_footer,.page-footer)';

        return $scope . ' .awa-newsletter-wrapper{gap:16px!important}'
            . $scope . ' .awa-newsletter-info{'
            . 'display:flex!important;align-items:center!important;gap:12px!important;'
            . 'flex:1!important;min-width:0!important}'
            . $scope . ' #newsletter-validate-detail{'
            . 'display:flex!important;gap:8px!important;align-items:stretch!important;width:100%!important}'
            . $scope . ' #newsletter-validate-detail :is(input[type=email],button.action.subscribe){'
            . 'min-height:44px!important;height:44px!important;box-sizing:border-box!important;'
            . 'border-radius:8px!important}'
            . $scope . ' #newsletter-validate-detail input[type=email]{'
            . 'flex:1!important;min-width:0!important;font-size:16px!important}'
            . $scope . ' #newsletter-validate-detail button.action.subscribe{'
            . 'flex:0 0 auto!important;min-width:44px!important;padding-inline:16px!important}'
            . '@media (min-width:768px){' . $scope . ' .awa-newsletter-wrapper{gap:16px!important}}'
            . '@media (max-width:991px){' . $scope . ' .page_footer .awa-newsletter-wrapper,'
            . $scope . ' .page-footer .awa-newsletter-wrapper{'
            . 'flex-direction:column!important;align-items:flex-start!important;'
            . 'text-align:start!important;width:100%!important}'
            . $scope . ' .awa-newsletter-form-container{width:100%!important;max-width:none!important}'
            . $scope . ' #newsletter-validate-detail{flex-direction:column!important;width:100%!important}}'
            . $scope . ' .footer-bottom .awa-footer-bottom__copyright{'
            . 'text-align:center!important}'
            . $scope . ' .footer-bottom :is(p.awa-footer-copyright__legal,p.awa-footer-copyright__disclaimer){'
            . 'box-sizing:border-box!important;display:block!important;width:100%!important;'
            . 'max-width:none!important;margin-inline:0!important;text-align:center!important;'
            . 'font-size:10px!important;line-height:1.25!important;'
            . 'color:var(--awa-text-muted,oklch(45% .02 20))!important}'
            . $scope . ' .footer-bottom p.awa-footer-copyright__disclaimer{'
            . 'font-size:10px!important;opacity:.92!important}'
            . '@media (min-width:992px){' . $scope . ' .footer-bottom p.awa-footer-copyright__disclaimer{'
            . 'display:block!important;overflow:visible!important;line-height:1.4!important;max-height:none!important}}'
            . '@media (max-width:767px){' . $scope . ' .footer-bottom .awa-footer-bottom__copyright{'
            . 'text-align:center!important}'
            . $scope . ' .footer-bottom :is(p.awa-footer-copyright__legal,p.awa-footer-copyright__disclaimer){'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important;text-align:center!important}}'
            . $scope . ' .awa-footer-devby{'
            . 'border-top:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'padding-block:12px!important;opacity:1!important;text-align:center!important}'
            . $scope . ' .awa-footer-devby__inner{'
            . 'display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'gap:8px!important;min-height:44px!important}'
            . $scope . ' .awa-footer-devby__label{'
            . 'font-size:11px!important;letter-spacing:0!important;text-transform:none!important;'
            . 'color:var(--awa-text-muted,oklch(45% .02 20))!important}'
            . $scope . ' .awa-footer-devby__logo{max-height:30px!important;width:auto!important}'
            . $scope . ' .awa-pay-logo{'
            . 'background:transparent!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'border-radius:6px!important;padding:4px 6px!important;'
            . 'min-height:28px!important;display:inline-flex!important;align-items:center!important}'
            . $scope . ' ul.awa-footer-categories-list a{'
            . 'background:transparent!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'color:var(--awa-text-muted,oklch(45% .02 20))!important;'
            . 'border-radius:999px!important;padding:6px 10px!important;'
            . 'min-height:36px!important;display:inline-flex!important;align-items:center!important;'
            . 'font-size:13px!important;line-height:1.35!important}'
            . $scope . ' ul.awa-footer-categories-list a:hover,'
            . $scope . ' ul.awa-footer-categories-list a:focus-visible{'
            . 'background:rgba(183,51,55,.06)!important;'
            . 'border-color:rgba(183,51,55,.35)!important;'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important}';
    }

    /**
     * Coluna Atendimento — hierarquia tipográfica, card da loja e touch targets.
     */
    public static function footerAtendimentoPolishRules(): string
    {
        $scope = 'html body#html-body .page-wrapper :is(.page_footer,.page-footer)';

        return $scope . ' .awa-footer-atendimento{'
            . 'display:flex!important;flex-direction:column!important;gap:6px!important}'
            . $scope . ' :is(.awa-footer-atendimento__label,.awa-footer-atendimento__label--social){'
            . 'text-transform:none!important;letter-spacing:0!important;'
            . 'font-size:13px!important;font-weight:600!important;'
            . 'color:var(--awa-text-muted,oklch(45% .02 20))!important;'
            . 'margin:0 0 2px!important}'
            . $scope . ' .awa-footer-atendimento__phone :is(a,span){'
            . 'font-size:15px!important;font-weight:600!important;'
            . 'color:var(--awa-secondary,oklch(35% .03 20))!important}'
            . $scope . ' .awa-footer-atendimento__email a{'
            . 'font-size:14px!important;color:var(--awa-text-muted,oklch(45% .02 20))!important;'
            . 'text-decoration:underline!important;text-underline-offset:2px!important}'
            . $scope . ' .awa-footer-atendimento__email a:hover,'
            . $scope . ' .awa-footer-atendimento__email a:focus-visible{'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important}'
            . $scope . ' .awa-footer-atendimento__store{'
            . 'display:flex!important;flex-direction:column!important;gap:2px!important;'
            . 'padding:8px 0!important;margin:4px 0!important;border-radius:0!important;'
            . 'background:transparent!important;background-image:none!important;border:0!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important}'
            . $scope . ' :is(.awa-footer-atendimento__store-name,.awa-footer-atendimento__store-address){'
            . 'margin:0!important;line-height:1.35!important;'
            . 'color:var(--awa-text-muted,oklch(45% .02 20))!important}'
            . $scope . ' ul.awa-footer-atendimento__actions{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr)!important;gap:4px!important;'
            . 'margin:4px 0 0!important;padding:0!important;list-style:none!important}'
            . $scope . ' ul.awa-footer-atendimento__actions a{'
            . 'display:inline-flex!important;align-items:center!important;gap:6px!important;'
            . 'min-height:0!important;padding-block:4px!important}'
            . $scope . ' .awa-footer-atendimento__icon{'
            . 'flex:0 0 20px!important;width:20px!important;min-width:20px!important;max-width:20px!important;'
            . 'height:20px!important;min-height:20px!important;max-height:20px!important;flex-shrink:0!important}'
            . $scope . ' .awa-footer-atendimento__icon :is(svg,img){'
            . 'width:16px!important;height:16px!important;min-width:16px!important;min-height:16px!important;'
            . 'max-width:16px!important;max-height:16px!important}'
            // Visual audit Jul/2026 — ícones sociais visíveis (vence super-global transparent)
            . $scope . ' .awa-footer-pro__social-link{'
            . 'background:#374151!important;'
            . 'background:color-mix(in srgb,var(--awa-neutral-700,#374151) 88%,#fff)!important;'
            . 'border:1.5px solid color-mix(in srgb,#fff 22%,transparent)!important;'
            . 'color:#fff!important;border-radius:50%!important}'
            . $scope . ' .awa-footer-pro__social-link :is(svg,svg path){fill:currentColor!important}'
            . $scope . ' .awa-footer-pro__social-link:hover,'
            . $scope . ' .awa-footer-pro__social-link:focus-visible{'
            . 'background:var(--awa-primary,#b73337)!important;'
            . 'border-color:var(--awa-primary,#b73337)!important;color:#fff!important}'
            . '@media (min-width:768px){'
            . $scope . ' .velaFooterTitle,'
            . $scope . ' #footer.footer-container .velaFooterTitle{'
            . 'margin:0 0 6px!important;margin-bottom:6px!important;padding-bottom:4px!important}'
            . $scope . ' ul.awa-footer-atendimento__actions{'
            . 'grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:6px 8px!important}'
            . $scope . ' ul.awa-footer-atendimento__actions > li{min-width:0!important}'
            . $scope . ' ul.awa-footer-atendimento__actions a{width:100%!important}'
            . $scope . ' .awa-footer-atendimento__store{padding:8px!important;gap:2px!important;margin:4px 0!important}'
            . $scope . ' .awa-footer-atendimento :is(.awa-footer-atendimento__phone a,'
            . '.awa-footer-atendimento__email a,.awa-footer-atendimento__actions a){min-height:0!important}'
            . '}'
            . '@media (min-width:992px){' . $scope . ' .footer-container .vela-content.velaFooterMenu{'
            . 'padding:12px!important}'
            . $scope . ' .footer-container .row.rowFlexMargin{'
            . 'padding-block:8px!important}'
            . $scope . ' .awa-footer-atendimento .velaContent.active{'
            . 'gap:6px!important}'
            . $scope . ' .awa-footer-atendimento__store-badge{'
            . 'margin:2px 0 4px!important}}'
            . '@media (max-width:767px){'
            . $scope . ' .awa-footer-atendimento__icon{'
            . 'flex:0 0 44px!important;width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important}'
            . $scope . ' .awa-footer-atendimento__icon :is(svg,img){'
            . 'width:22px!important;height:22px!important;min-width:22px!important;min-height:22px!important}'
            . $scope . ' ul.awa-footer-atendimento__actions a{min-height:44px!important}'
            . '}';
    }

    /**
     * Faixa comercial B2B e tags do footer — grid shell, pills e eyebrow sentence-case.
     */
    public static function footerBusinessBandsRules(): string
    {
        $scope = 'html body#html-body .page-wrapper :is(.page_footer,.page-footer)';

        return $scope . ' section.awa-footer-business-contact{'
            . 'padding-block:20px!important;'
            . 'border-top:1px solid var(--awa-border,oklch(90% .008 20))!important}'
            . $scope . ' .awa-footer-business-contact__eyebrow{'
            . 'text-transform:none!important;letter-spacing:0!important;'
            . 'font-size:12px!important;font-weight:600!important}'
            . $scope . ' .awa-footer-business-contact__shell{'
            . 'display:flex!important;flex-direction:column!important;gap:16px!important;'
            . 'padding:16px!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'border-radius:8px!important;background:oklch(99% .002 20)!important;'
            . 'box-shadow:none!important}'
            . '@media (min-width:768px){' . $scope . ' .awa-footer-business-contact__shell{'
            . 'display:grid!important;grid-template-columns:minmax(0,1.1fr) minmax(0,1.6fr)!important;'
            . 'align-items:start!important;gap:16px!important}}'
            . $scope . ' .awa-footer-business-contact__actions{'
            . 'display:grid!important;gap:8px!important}'
            . '@media (min-width:576px){' . $scope . ' .awa-footer-business-contact__actions{'
            . 'grid-template-columns:repeat(auto-fit,minmax(min(100%,11rem),1fr))!important}}'
            . $scope . ' .awa-footer-business-contact__title{'
            . 'text-transform:none!important;letter-spacing:0!important;'
            . 'font-size:16px!important;line-height:1.35!important;margin:0!important}'
            . $scope . ' .awa-footer-business-contact__copy{'
            . 'font-size:14px!important;line-height:1.45!important;margin:0!important}'
            . $scope . ' .awa-footer-business-contact__action{'
            . 'display:inline-flex!important;align-items:center!important;gap:10px!important;'
            . 'min-height:44px!important;padding:10px 14px!important;'
            . 'border-radius:8px!important;text-decoration:none!important}'
            . $scope . ' .awa-footer-business-contact__action--primary{'
            . 'background:rgba(183,51,55,.08)!important;'
            . 'border:1px solid rgba(183,51,55,.28)!important}'
            . $scope . ' .awa-footer-business-contact__action-copy strong{'
            . 'display:block!important;font-size:14px!important;line-height:1.3!important}'
            . $scope . ' .awa-footer-business-contact__action-copy small{'
            . 'display:block!important;font-size:12px!important;line-height:1.35!important;'
            . 'color:var(--awa-text-muted,oklch(45% .02 20))!important}'
            . $scope . ' section.awa-footer-tags{'
            . 'padding-block:16px!important;'
            . 'border-top:1px solid var(--awa-border,oklch(90% .008 20))!important}'
            . $scope . ' .awa-footer-tags__inner{'
            . 'display:flex!important;flex-wrap:wrap!important;align-items:center!important;'
            . 'gap:12px!important}'
            . $scope . ' .awa-footer-tags__label{'
            . 'font-size:13px!important;font-weight:600!important;margin:0!important}'
            . $scope . ' .awa-footer-tags__cloud{'
            . 'display:flex!important;flex-wrap:wrap!important;gap:8px!important}'
            . $scope . ' .awa-footer-tags__cloud a{'
            . 'display:inline-flex!important;align-items:center!important;min-height:36px!important;'
            . 'padding:6px 10px!important;border-radius:6px!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'font-size:13px!important;line-height:1.35!important}'
            . $scope . ' .awa-footer-tags__cloud a:hover,'
            . $scope . ' .awa-footer-tags__cloud a:focus-visible{'
            . 'background:rgba(183,51,55,.06)!important;'
            . 'border-color:rgba(183,51,55,.35)!important;'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important}';
    }

    public static function rules(): string
    {
        return self::headerA11yRules()
            . self::minicartInteractionRules()
            . self::promoBarRules()
            . self::headerPolishRules()
            . self::headerNavShellRules()
            . self::headerVisualStandardRules()
            . self::verticalMenuTerminalRules()
            . self::impeccableSurfaceRules()
            . self::b2bRegisterSurfaceRules()
            . self::b2bDashboardSurfaceRules()
            . self::headerEssentialTerminalRules()
            . self::tabletHeaderCompactRules()
            . self::headerVisualBugsFixRules()
            . '@media (min-width:992px){'
            . 'html body#html-body#html-body#html-body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header{'
            . '--awa-header-main-row-h:68px!important;--awa-header-row-h:var(--awa-header-main-row-h)!important;'
            . '--awa-header-nav-h:48px!important;--awa-nav-bar-h:var(--awa-header-nav-h)!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-main-header__inner.wp-header,'
            . 'html body#html-body .page-wrapper .awa-site-header .awa-main-header__inner[data-awa-header-row],'
            . 'html body#html-body .page-wrapper .awa-site-header .header.awa-main-header{'
            . 'min-height:var(--awa-header-main-row-h,68px)!important;'
            . 'height:var(--awa-header-main-row-h,68px)!important;'
            . 'max-height:var(--awa-header-main-row-h,68px)!important;padding-block:0!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header .awa-main-header__inner.wp-header,'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header .awa-main-header__inner[data-awa-header-row]{'
            . 'display:grid!important;'
            . 'grid-template-columns:minmax(132px,176px) minmax(360px,1fr) minmax(300px,auto)!important;'
            . 'grid-template-areas:"brand search actions"!important;'
            . 'align-items:center!important;column-gap:clamp(16px,2vw,28px)!important;'
            . 'overflow:visible!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header .awa-header-primary-row{display:contents!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header :is(.awa-header-mobile-toggle,.action.nav-toggle,[data-action="toggle-nav"]){'
            . 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'overflow:hidden!important;pointer-events:none!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header .awa-header-brand-cell{'
            . 'grid-area:brand!important;grid-column:auto!important;width:auto!important;max-width:176px!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header .awa-header-search-col{'
            . 'grid-area:search!important;grid-column:auto!important;min-width:0!important;width:100%!important;max-width:none!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header .awa-header-search-col .block-search{'
            . 'width:100%!important;max-width:760px!important;margin-inline:auto!important}'
            . 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .awa-site-header .awa-header-right-col{'
            . 'grid-area:actions!important;grid-column:auto!important;width:auto!important;'
            . 'max-width:360px!important;justify-self:end!important;align-self:center!important}'
            . 'html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky '
            . ':is(.awa-header-brand-cell,.col-md-2.awa-header-brand){'
            . 'align-self:center!important;height:auto!important;min-height:0!important;max-height:56px!important}'
            . 'html body#html-body .page-wrapper .header-control.header-nav.awa-nav-bar,'
            . 'html body#html-body .page-wrapper .header-control.awa-nav-bar,'
            . 'html body#html-body .page-wrapper .awa-nav-bar,'
            . 'html body#html-body .page-wrapper .header-control.awa-nav-bar > .container,'
            . 'html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-nav-bar__inner,'
            . 'html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-nav-quick-links,'
            . 'html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-nav-quick-links__list{'
            . 'min-height:var(--awa-nav-bar-h,48px)!important;max-height:var(--awa-nav-bar-h,48px)!important;'
            . 'height:var(--awa-nav-bar-h,48px)!important;box-sizing:border-box!important}'
            . 'html body#html-body .page-wrapper .header-control.awa-nav-bar .our_categories.title-category-dropdown,'
            . 'html body#html-body .page-wrapper .header-control.awa-nav-bar button[data-role=awa-vertical-menu-trigger]{'
            . 'min-height:var(--awa-nav-bar-h,48px)!important;max-height:var(--awa-nav-bar-h,48px)!important;'
            . 'height:var(--awa-nav-bar-h,48px)!important;box-sizing:border-box!important}'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar,'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar > .container,'
            . 'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) '
            . '.page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar .awa-nav-bar__inner{'
            . 'min-height:var(--awa-nav-bar-h,48px)!important;max-height:var(--awa-nav-bar-h,48px)!important;'
            . 'height:var(--awa-nav-bar-h,48px)!important;box-sizing:border-box!important}'
            . '}'
            . self::mobileHeaderSearchLayoutRules()
            . self::headerStickyShellRules()
            . self::homeImpeccablePolishRules()
            . self::visualCrawlSystemicRules()
            . self::mobileSearchTerminalRules()
            . self::mobileHeaderCompact112Rules()
            . self::footerLightLayoutRules()
            . self::footerBottomModernRules()
            . self::footerInteractionPolishRules()
            . self::footerAtendimentoPolishRules()
            . self::footerBusinessBandsRules()
            . self::footerSealImgPolishRules()
            . self::footerAlignShellRules()
            . self::homePostAuditTerminalRules()
            . self::headerBolderActionContrastLockRules()
            /* Ghost DEPOIS do contrast lock — minicart não é CTA vermelho no storefront. */
            . self::headerMinicartGhostTerminalRules()
            . self::plpB2bGuestGateTerminalRules()
            . self::plpMobileToolbarTerminalRules();
    }

    /**
     * PLP mobile toolbar + chrome — última camada body-end (cc2a40).
     * Reforça grid estável e remove overlays que cobrem cards.
     */
    public static function plpMobileToolbarTerminalRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index)';
        $tb = $root . ' .page-wrapper .shop-tab-title .toolbar.toolbar-products';

        return '@layer awa-visual-priority{@media (max-width:767px){'
            . '/* §PLP-MOBILE-TOOLBAR r7 — @layer vence Round19 display:inline-flex */'
            . $root . ' .page-wrapper .shop-tab-title{height:auto!important;min-height:0!important;max-height:none!important}'
            . $tb . '{height:auto!important;min-height:0!important;max-height:none!important;display:flex!important;flex-direction:column!important}'
            . $tb . '>.center{display:grid!important;grid-template-columns:minmax(0,1fr)!important;grid-template-rows:auto auto!important;'
            . 'gap:8px!important;position:static!important;width:100%!important;height:auto!important;min-height:0!important}'
            . $tb . ' .modes{position:static!important;display:flex!important;flex-wrap:wrap!important;align-items:center!important;'
            . 'gap:8px!important;width:100%!important;min-height:44px!important;opacity:1!important;visibility:visible!important;'
            . 'clip-path:none!important;pointer-events:auto!important;overflow:visible!important}'
            . $tb . ' .pages,:is(' . $tb . ' .pages .pages-items,' . $tb . ' .pages .item){display:none!important;visibility:hidden!important}'
            . $tb . ' .toolbar-sorter.sorter{display:flex!important;width:100%!important;min-height:44px!important;grid-row:2!important}'
            . $root . ' .page-wrapper .products-grid li.item-product .actions-secondary{display:none!important}'
            . $root . ' .page-wrapper .awa-dark-mode-toggle{display:none!important;visibility:hidden!important;pointer-events:none!important}'
            . '}}';
    }

    /**
     * PLP guest B2B gate — última camada (body-end lock).
     * 2026-07-26 r6 (bug 2.2 UI audit): gate secundário ao título do produto.
     * Antes: min-height:64 + title fw:700 + fundo rosa (competia com o nome).
     * Evidência Playwright: altura do gate ~66px vs título 14px/600.
     */
    public static function plpB2bGuestGateTerminalRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index)';
        $info = $root . ' .page-wrapper :is(.products-grid,.wrapper.grid.products-grid)'
            . ' li.item-product .info-price';
        $gate = $info . ' :is(.b2b-login-to-see-price.price-box,.b2b-login-to-see-price)';

        return '/* §PLP-B2B-GATE — guest card quiet (2026-07-26 r6) */'
            . $info . '{min-height:0!important}'
            . $gate . '{'
            . 'display:flex!important;flex-direction:column!important;flex-wrap:nowrap!important;'
            . 'justify-content:flex-start!important;align-items:flex-start!important;gap:2px!important;'
            . 'box-sizing:border-box!important;width:100%!important;max-width:100%!important;'
            . 'min-height:0!important;height:auto!important;margin:4px 0 0!important;padding:0!important;'
            . 'text-align:start!important;text-transform:none!important;letter-spacing:normal!important;'
            . 'font-size:12px!important;line-height:1.3!important;'
            . 'background:transparent!important;border:0!important;border-radius:0!important;'
            . 'box-shadow:none!important}'
            . $gate . ' .b2b-login-to-see-price__title{'
            . 'margin:0!important;width:100%!important;font-size:11px!important;font-weight:500!important;'
            . 'line-height:1.3!important;text-align:start!important;text-transform:none!important;'
            . 'letter-spacing:normal!important;color:var(--awa-text-muted,#64748b)!important}'
            . $gate . ' .b2b-login-to-see-price__message{'
            . 'margin:0!important;width:100%!important;font-size:12px!important;font-weight:600!important;'
            . 'line-height:1.35!important;text-align:start!important;text-transform:none!important;'
            . 'letter-spacing:normal!important;color:var(--awa-primary,#b73337)!important;'
            . 'display:-webkit-box!important;-webkit-box-orient:vertical!important;'
            . '-webkit-line-clamp:1!important;line-clamp:1!important;overflow:hidden!important}';
    }

    /**
     * Home pós-auditoria Product Design 2026-06-18.
     * Regras terminais porque o HTML real da home mobile usa cascade-lock inline,
     * não o bundle home-terminal em todos os contextos de cache/user-agent.
     */
    public static function homePostAuditTerminalRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)';
        $wrap = $root . ' .page-wrapper';
        $header = $root . ' .awa-site-header';

        return '/* HEADER POST-AUDIT 2026-06-19: !important necessário para vencer bundles tardios e seletores legados com .page-wrapper. */'
            . '@media (min-width:992px){'
            . $header . '{background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;border-bottom:0!important}'
            . $header . ' #header.header-container{height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'overflow:hidden!important;background:transparent!important}'
            . $header . ' #header.header-container .header-content{height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;align-items:center!important;overflow:hidden!important;background:transparent!important}'
            /* BUG 2026-08-10: após dismiss da promo, #header só tinha a barra — CSS 44px
               deixava vão vazio. Colapsa o shell sem aria-hidden no container. */
            . 'html.awa-b2b-promo-dismissed' . substr($header, 4) . ' #header.header-container,'
            . 'html.awa-b2b-promo-dismissed' . substr($header, 4) . ' #header.header-container.awa-b2b-promo-shell--collapsed,'
            . 'html.awa-b2b-promo-dismissed' . substr($header, 4) . ' #header.header-container .header-content,'
            . $header . ' #header.header-container:has(#awa-b2b-promo-bar[aria-hidden="true"]),'
            . $header . ' #header.header-container:has(#awa-b2b-promo-bar[aria-hidden="true"]) .header-content{'
            . 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;'
            . 'border:0!important;overflow:hidden!important;line-height:0!important}'
            . $header . ' #awa-b2b-promo-bar{position:relative!important;height:44px!important;min-height:44px!important;'
            . 'max-height:44px!important;padding:0!important;padding-inline:0!important;border:0!important;'
            . 'background:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'background-color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important;line-height:1.2!important;'
            . 'box-sizing:border-box!important}'
            . $header . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__inner{position:static!important;width:min(100%,1280px)!important;'
            . 'max-width:1280px!important;margin:0 auto!important;padding:0 52px 0 24px!important;height:44px!important;min-height:44px!important;'
            . 'max-height:44px!important;align-items:center!important;justify-content:center!important;box-sizing:border-box!important}'
            . $header . ' #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,'
            . '.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__separator,'
            . '.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta strong){'
            . 'color:var(--awa-text-inverse,#fff)!important;line-height:1.25!important;font-size:13px!important}'
            . $header . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__cta{'
            . 'font-weight:700!important;color:inherit!important;text-decoration:underline!important;text-underline-offset:2px!important}'
            . $header . ' #awa-b2b-promo-bar .awa-b2b-promo-close{'
            . 'position:absolute!important;top:0!important;right:0!important;inset-block-start:0!important;'
            . 'inset-inline-end:0!important;'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'box-sizing:border-box!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'border:0!important;border-radius:0!important;background:transparent!important;'
            . 'color:inherit!important;font-size:16px!important;'
            . 'font-weight:600!important;line-height:1!important;transform:none!important}'
            . $header . ' .header-wrapper-sticky{display:block!important;height:auto!important;min-height:0!important;max-height:none!important;'
            . 'padding:0!important;margin:0!important;background:transparent!important;box-shadow:none!important}'
            . $header . ' .header.awa-main-header{height:74px!important;min-height:74px!important;max-height:74px!important;'
            . 'padding:0 16px!important;margin:0!important;background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;'
            . 'border-bottom:0!important}'
            . $header . ' :is(.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container,'
            . '.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'height:74px!important;min-height:74px!important;max-height:74px!important;padding-block:0!important;'
            . 'box-sizing:border-box!important;overflow:visible!important}'
            . $header . ' .header_main.awa-main-header-inner-wrap{border-block:0!important}'
            . $header . ' .header-main>.container{display:block!important;max-width:1280px!important;margin-inline:auto!important;'
            . 'padding-inline:16px!important}'
            . $header . ' .awa-main-header__inner.wp-header{display:grid!important;'
            . 'grid-template-columns:minmax(132px,176px) minmax(420px,1fr) minmax(236px,300px)!important;'
            . 'grid-template-areas:"brand search actions"!important;align-items:center!important;column-gap:24px!important;'
            . 'padding-block:9px!important}'
            . $header . ' .awa-header-primary-row{display:contents!important}'
            . $header . ' .awa-header-brand-cell{grid-area:brand!important;align-self:center!important;height:56px!important;'
            . 'min-height:0!important;max-height:56px!important;display:flex!important;align-items:center!important;justify-content:flex-start!important}'
            . $header . ' .awa-header-brand-cell .logo,'
            . $header . ' .awa-header-brand-cell .logo a{display:flex!important;align-items:center!important;height:56px!important;min-height:0!important}'
            . $header . ' .awa-header-brand-cell .logo img{width:104px!important;height:44px!important;max-width:104px!important;'
            . 'max-height:44px!important;object-fit:contain!important}'
            . $header . ' .awa-header-search-col{grid-area:search!important;align-self:center!important;height:56px!important;'
            . 'display:flex!important;align-items:center!important;min-width:0!important}'
            . $header . ' .awa-header-search-col :is(.block-search,#search_mini_form,form.minisearch){'
            . 'width:100%!important;max-width:760px!important;margin-inline:auto!important;height:44px!important;min-height:44px!important;'
            . 'max-height:44px!important}'
            . $header . ' form#search_mini_form.minisearch{'
            . 'background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;'
            . 'border:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;'
            . 'border-radius:var(--awa-radius-md,8px)!important;box-shadow:none!important;overflow:hidden!important}'
            . $header . ' form#search_mini_form.minisearch :is(input,#search){'
            . 'background:transparent!important;box-shadow:none!important}'
            . $header . ' .awa-header-right-col{grid-area:actions!important;justify-self:end!important;align-self:center!important;'
            . 'height:56px!important;min-height:0!important;max-height:56px!important;display:flex!important;align-items:center!important;'
            . 'gap:10px!important}'
            . $header . ' .awa-header-account-prompt{height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;align-items:center!important;gap:8px!important;border-radius:0!important;'
            . 'background:transparent!important;border:0!important;box-shadow:none!important;'
            . 'width:max-content!important;min-width:0!important;max-width:none!important}'
            . $header . ' .awa-header-account-prompt__icon{width:20px!important;min-width:20px!important;'
            . 'padding:0!important;color:var(--awa-text-muted,var(--awa-text-secondary,CanvasText))!important}'
            . $header . ' .awa-header-account-prompt__guest{gap:2px!important;line-height:1.1!important}'
            . $header . ' .awa-header-account-prompt__line1{font-size:11px!important;line-height:1.05!important;'
            . 'font-weight:600!important;color:var(--awa-text-muted,var(--awa-text-secondary,CanvasText))!important}'
            . $header . ' .awa-header-account-prompt__line2{font-size:13px!important;line-height:1.1!important;font-weight:600!important}'
            . $header . ' .awa-header-account-prompt__link--login{font-size:13px!important;font-weight:700!important;'
            . 'display:inline-flex!important;align-items:center!important;min-height:0!important;height:auto!important;'
            . 'padding:0 2px!important;color:var(--awa-text,CanvasText)!important;background:transparent!important}'
            . $header . ' .awa-header-account-prompt__separator{font-size:10px!important;font-weight:500!important;'
            . 'padding-inline:1px!important;color:var(--awa-text-muted,var(--awa-text-secondary,CanvasText))!important}'
            /* 2026-08-12: cadastre-se = link textual igual a Entrar (sem pílula). */
            . $header . ' .awa-header-account-prompt__link--register{font-size:12px!important;font-weight:700!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'padding:0 2px!important;border-radius:0!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'color:var(--awa-text,CanvasText)!important;border:0!important;'
            . 'text-decoration:none!important;box-sizing:border-box!important}'
            . $header . ' .awa-header-account-prompt{border:0!important;border-radius:0!important;background:transparent!important;'
            . 'padding:0!important;box-shadow:none!important;min-height:44px!important;height:44px!important}'
            . $header . ' .awa-header-account-prompt__line2{overflow:visible!important;min-height:0!important;'
            . 'align-items:center!important;gap:6px!important}'
            . $header . ' .minicart-wrapper:not(.active):not(.show):not(.is-open),'
            . $header . ' .awa-header-minicart:not(:has(.minicart-wrapper.active)):not(:has(.minicart-wrapper.show)):not(:has(.minicart-wrapper.is-open)),'
            . $header . ' .minicart-wrapper:not(.active):not(.show):not(.is-open) .action.showcart{height:44px!important;min-height:44px!important;'
            . 'max-height:44px!important;width:44px!important;min-width:44px!important;max-width:44px!important;align-self:center!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important}'
            . $header . ' :is(.awa-header-minicart .action.showcart,.awa-minicart-trigger){border-radius:8px!important;box-shadow:none!important}'
            . $header . ' .header-control.header-nav.awa-nav-bar{height:48px!important;min-height:48px!important;max-height:48px!important;'
            . 'padding:0 16px!important;margin:0!important;background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;'
            . 'border-block:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;'
            . 'border-inline:0!important}'
            . $header . ' .header-control.awa-nav-bar>.container{height:48px!important;min-height:48px!important;'
            . 'max-height:48px!important;width:100%!important;max-width:none!important;margin-inline:0!important;'
            . 'padding:0!important;box-sizing:border-box!important}'
            . $header . ' .header-control.awa-nav-bar .awa-nav-bar__inner{height:48px!important;min-height:48px!important;'
            . 'max-height:48px!important;width:min(100%,1280px)!important;max-width:1280px!important;'
            . 'margin-inline:auto!important;padding-inline:24px!important;box-sizing:border-box!important;'
            . 'display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:16px!important}'
            . $header . ' .awa-header-categories.menu_left_home1{flex:0 0 auto!important;width:206px!important;height:40px!important;'
            . 'min-height:40px!important;max-height:40px!important;padding:0!important;align-self:center!important}'
            . $header . ' .header-control.awa-nav-bar button.our_categories.title-category-dropdown[data-role="awa-vertical-menu-trigger"],'
            . $header . ' .header-control.awa-nav-bar .our_categories.title-category-dropdown{height:40px!important;min-height:40px!important;'
            . 'max-height:40px!important;border-radius:8px!important;box-shadow:none!important}'
            . $header . ' .awa-nav-quick-links{flex:0 0 auto!important;margin:0!important;margin-inline-start:0!important;'
            . 'justify-self:start!important;justify-content:flex-start!important;height:48px!important;'
            . 'min-height:48px!important;max-height:48px!important;align-items:center!important}'
            . $header . ' .awa-nav-quick-links__list{height:48px!important;min-height:48px!important;max-height:48px!important;'
            . 'align-items:center!important;justify-content:flex-start!important;gap:16px!important}'
            . $header . ' .awa-nav-quick-links__link{height:40px!important;min-height:40px!important;'
            . 'padding:0 6px!important;border-radius:8px!important;font-size:13px!important;'
            . 'font-weight:600!important;color:var(--awa-text,CanvasText)!important}'
            . '}'
            . '@media (min-width:768px) and (max-width:991px){'
            . $header . '{min-height:calc(44px + 56px + 48px)!important;height:auto!important;max-height:none!important;overflow:visible!important}'
            . $header . ' .header-wrapper-sticky{display:flex!important;flex-direction:column!important;'
            . 'height:auto!important;min-height:calc(56px + 48px)!important;max-height:none!important;overflow:visible!important}'
            . $header . ' .header.awa-main-header{height:56px!important;min-height:56px!important;max-height:56px!important;flex-shrink:0!important}'
            . $header . ' :is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar){'
            . 'display:flex!important;visibility:visible!important;height:48px!important;min-height:48px!important;'
            . 'max-height:48px!important;overflow:visible!important;pointer-events:auto!important;flex-shrink:0!important}'
            . $header . ' #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,'
            . '.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__tail){'
            . 'color:var(--awa-text-primary,#111827)!important}'
            . '}'
            . $wrap . ' :is(.page_footer,.page-footer) #footer.footer-container{'
            . 'background:var(--awa-bg,Canvas)!important;background-color:var(--awa-bg,Canvas)!important;'
            . 'border-top:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;'
            . 'color:var(--awa-text,CanvasText)!important;'
            . 'padding-block:clamp(16px,3vw,24px)!important}'
            . $wrap . ' :is(.page_footer,.page-footer) #footer.footer-container :is(a,p,li,span,strong,.velaFooterTitle){'
            . 'color:var(--awa-text,CanvasText)!important;text-shadow:none!important}'
            . $wrap . ' :is(.page_footer,.page-footer) #footer.footer-container :is(.container,.row.rowFlexMargin){'
            . 'max-width:var(--awa-home-terminal-shell,min(100%,1280px))!important;'
            . 'margin-inline:auto!important;box-sizing:border-box!important}'
            /* H25B: grid responsivo 1/2/3 — evita 3 colunas esmagadas em tablet (~914px) */
            . $wrap . ' :is(.page_footer,.page-footer) #footer.footer-container .row.rowFlexMargin{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr)!important;'
            . 'gap:clamp(14px,2vw,20px)!important;align-items:start!important}'
            . '@media(min-width:768px) and (max-width:991px){'
            . $wrap . ' :is(.page_footer,.page-footer) #footer.footer-container .row.rowFlexMargin{'
            . 'grid-template-columns:repeat(2,minmax(0,1fr))!important}}'
            . '@media(min-width:992px){'
            . $wrap . ' :is(.page_footer,.page-footer) #footer.footer-container .row.rowFlexMargin{'
            . 'grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(280px,.86fr)!important}}'
            . $wrap . ' :is(.page_footer,.page-footer) #footer.footer-container :is(.velaFooterMenu,.awa-footer-atendimento,.velaBlock){'
            . 'background:transparent!important;border:0!important;box-shadow:none!important;min-width:0!important;padding:8px!important}'
            . $wrap . ' :is(.page_footer,.page-footer) .footer-bottom{'
            . 'background:#fff!important;background-color:#fff!important;border:0!important}'
            . $wrap . ' :is(.page_footer,.page-footer) .footer-bottom .footer-bottom-inner{'
            . 'padding-block:clamp(14px,2vw,18px)!important}'
            . $wrap . ' :is(.page_footer,.page-footer) .awa-footer-bottom__copyright{'
            . 'background:var(--awa-bg,oklch(99% .002 20))!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'border-radius:var(--awa-radius-md,8px)!important;'
            . 'color:var(--awa-text,oklch(22% .02 20))!important;'
            . 'padding:var(--awa-gap-lg,16px)!important}'
            . $wrap . ' :is(.page_footer,.page-footer) .awa-footer-bottom__copyright :is(p,span){'
            . 'color:var(--awa-text,oklch(22% .02 20))!important}'
            . $root . ' #awa-cookie-banner.awa-cookie-banner--visible{'
            . 'background:var(--awa-bg,oklch(99% .002 20))!important;'
            . 'color:var(--awa-text,oklch(22% .02 20))!important;'
            . 'border:1px solid var(--awa-border,oklch(90% .008 20))!important;'
            . 'box-shadow:0 4px 16px rgb(15 23 42 / 10%)!important}'
            . $root . ' #awa-cookie-banner.awa-cookie-banner--visible :is(.awa-cookie-banner__text,#awa-cookie-desc){'
            . 'color:var(--awa-text,oklch(22% .02 20))!important}'
            . $root . ' #awa-cookie-banner.awa-cookie-banner--visible .awa-cookie-banner__link{'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important}'
            . '@media(max-width:767px){'
            . $header . ' .awa-header-minicart:not(:has(.minicart-wrapper.active)):not(:has(.minicart-wrapper.show)):not(:has(.minicart-wrapper.is-open)),'
            . $header . ' .minicart-wrapper:not(.active):not(.show):not(.is-open),'
            . $header . ' .minicart-wrapper:not(.active):not(.show):not(.is-open) .action.showcart{'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;'
            . 'min-height:44px!important;max-height:44px!important;display:inline-flex!important;align-items:center!important;'
            . 'justify-content:center!important;box-sizing:border-box!important}'
            . $wrap . ' .top-home-content--above-fold .wrapper_slider.visible-xs .banner_item_bg :is(picture,img){'
            . 'height:clamp(132px,36vw,170px)!important;min-height:clamp(132px,36vw,170px)!important;'
            . 'object-fit:contain!important;object-position:center center!important;'
            . 'background:var(--awa-bg,oklch(99% .002 20))!important}'
            . $wrap . ' .top-home-content--category-carousel .awa-category-carousel__header.awa-section-header{'
            . 'display:grid!important;grid-template-columns:1fr!important;gap:var(--awa-gap-sm,8px)!important;'
            . 'align-items:start!important}'
            . $wrap . ' .top-home-content--category-carousel .awa-category-carousel__cta-link{'
            . 'justify-self:stretch!important;order:2!important;margin-block-start:var(--awa-gap-xs,4px)!important}'
            . $wrap . ' :is(.page_footer,.page-footer) #footer.footer-container .row.rowFlexMargin{'
            . 'grid-template-columns:1fr!important}'
            . $root . ' #awa-cookie-banner.awa-cookie-banner--visible{'
            . 'left:var(--awa-gap-md,12px)!important;right:var(--awa-gap-md,12px)!important;'
            . 'bottom:calc(var(--awa-mobile-bottom-nav-h,64px) + var(--awa-gap-sm,8px) + env(safe-area-inset-bottom,0px))!important;'
            . 'max-height:min(34vh,220px)!important;border-radius:var(--awa-radius-md,8px)!important}'
            . $root . ' #awa-cookie-banner.awa-cookie-banner--visible .awa-cookie-banner__inner{'
            . 'gap:var(--awa-gap-sm,8px)!important;padding:var(--awa-gap-md,12px)!important}'
            . $root . ' #awa-cookie-banner.awa-cookie-banner--visible :is(.awa-cookie-banner__text,#awa-cookie-desc){'
            . 'font-size:max(12px,.75rem)!important;line-height:1.4!important}'
            . $root . ' #awa-cookie-banner.awa-cookie-banner--visible .awa-cookie-banner__actions{'
            . 'grid-template-columns:1fr 1fr!important}'
            . $root . ' #awa-cookie-banner.awa-cookie-banner--visible .awa-cookie-banner__btn{'
            . 'min-height:44px!important;padding:var(--awa-gap-sm,8px) var(--awa-gap-md,12px)!important}'
            . '}';
    }

    /**
     * Desktop/tablet — prompt de conta: Entrar e cadastre-se como links textuais.
     */
    public static function headerVisFixTerminalRules(): string
    {
        return '@media (min-width:992px){'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .awa-header-account-prompt{'
            . 'max-width:none!important;overflow:visible!important;flex-shrink:1!important;'
            . 'border:0!important;background:transparent!important;padding:0!important;box-shadow:none!important;'
            . 'min-height:44px!important;height:44px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header .awa-header-account-prompt__link--register{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:flex-start!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'color:var(--awa-text,CanvasText)!important;border:0!important;'
            . 'border-radius:0!important;padding:0 2px!important;min-height:0!important;height:auto!important;'
            . 'max-height:none!important;min-width:0!important;font-size:12px!important;font-weight:700!important;'
            . 'line-height:1.2!important;text-decoration:none!important;box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header .awa-header-account-prompt__line2{'
            . 'overflow:visible!important;min-height:0!important;align-items:center!important;gap:6px!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .awa-header-minicart{flex-shrink:0!important}'
            . '}'
            . '@media (min-width:768px) and (max-width:991px){'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .awa-header-account-prompt{'
            . 'position:static!important;width:auto!important;max-width:none!important;overflow:visible!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .awa-header-account-prompt '
            . ':is(.awa-header-account-prompt__icon,.awa-header-account-prompt__text,.awa-header-account-prompt__guest){'
            . 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;overflow:hidden!important;'
            . 'pointer-events:none!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .awa-header-account-prompt__mobile-link{'
            . 'display:inline-flex!important;visibility:visible!important;pointer-events:auto!important;'
            . 'align-items:center!important;justify-content:center!important;width:44px!important;min-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;color:var(--awa-primary,#b73337)!important}'
            . '}';
    }

    /**
     * Carrinho único no header — Magento minicart (`Magento_Checkout::cart/minicart.phtml`)
     * é o único CTA. O fallback LCP e o `.awa-header-cart-link` legado não podem
     * participar do flex/grid: 6×#html-body vence align-grid (5×) que reabre
     * `display:inline-flex` e empurra `.action.showcart` para fora do inner 1280.
     */
    public static function headerCartDedupeRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header';

        $legacyCart = $root . ' .awa-header-primary-row>.awa-header-cart-link,'
            . $root . ' a.awa-header-cart-link';

        $fallbackHide = $root . ' .awa-header-minicart:has(.minicart-wrapper .action.showcart)'
            . '>.awa-header-cart-fallback,'
            . $root . ' .awa-header-minicart:has(.minicart-wrapper .action.showcart)'
            . '>.awa-header-cart-fallback .awa-header-cart-fallback__icon';

        $shell = $root . ' .awa-header-minicart';
        $slot = $shell . '>.mini-carts,' . $shell . ' .minicart-wrapper';
        $showcart = $shell . ' .minicart-wrapper'
            . ' :is(.action.showcart,a.showcart.header-mini-cart)';

        $registerReset = $root . ' .awa-header-account-prompt :is(.awa-header-account-prompt__link--register,'
            . '.awa-header-account-prompt__line2 .awa-header-account-prompt__link)';

        $hide = 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'min-width:0!important;min-height:0!important;overflow:hidden!important;'
            . 'pointer-events:none!important;position:absolute!important;'
            . 'clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;margin:0!important;padding:0!important';

        return $legacyCart . '{' . $hide . '}'
            . $fallbackHide . '{' . $hide . '}'
            . $shell . '{'
            . 'position:relative!important;display:flex!important;align-items:center!important;'
            . 'justify-content:center!important;width:44px!important;min-width:44px!important;'
            . 'max-width:44px!important;height:44px!important;min-height:44px!important;'
            . 'max-height:44px!important;overflow:visible!important;flex:0 0 44px!important}'
            . $slot . '{'
            . 'position:relative!important;display:flex!important;align-items:center!important;'
            . 'justify-content:center!important;width:44px!important;min-width:44px!important;'
            . 'max-width:44px!important;height:44px!important;min-height:44px!important;'
            . 'margin:0!important;flex:0 0 44px!important}'
            . $showcart . '{'
            . 'position:relative!important;inset:auto!important;left:auto!important;right:auto!important;'
            . 'top:auto!important;bottom:auto!important;float:none!important;'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;margin:0!important}'
            . '@media (max-width:991px){'
            . $registerReset . '{'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'border:none!important;border-radius:0!important;padding:0!important;min-height:0!important}'
            . '}'
            . self::headerTabletShellRestoreRules();
    }

    /**
     * Tablet 768–991 — themes.min.css esconde a nav (visibility:hidden / display:none)
     * e o lock da conta (13× #html-body, width:max-content) deixa um buraco invisível
     * de ~264px entre busca e carrinho. Sem Departamentos não há menu de categorias.
     * 13× #html-body vence o prompt-lock e o themes (1–2×).
     */
    public static function headerTabletShellRestoreRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper .awa-site-header';
        $hide = 'display:none!important;visibility:hidden!important;opacity:0!important;'
            . 'width:0!important;height:0!important;min-width:0!important;min-height:0!important;'
            . 'overflow:hidden!important;pointer-events:none!important;margin:0!important;padding:0!important';
        $paint = 'visibility:visible!important;opacity:1!important;pointer-events:auto!important;'
            . 'clip:auto!important;clip-path:none!important';

        return '@media (min-width:768px) and (max-width:991px){'
            . $root . ' .header-control.header-nav.awa-nav-bar,'
            . $root . ' .header-control.awa-nav-bar{'
            . 'display:flex!important;' . $paint
            . ';height:48px!important;min-height:48px!important;max-height:48px!important;'
            . 'overflow:visible!important;align-items:center!important;'
            . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
            . 'margin-inline:0!important;box-sizing:border-box!important}'
            . $root . ' :is(.header-control.header-nav.awa-nav-bar>.container,.header-control.awa-nav-bar>.container){'
            . 'display:block!important;' . $paint
            . ';height:48px!important;min-height:48px!important;max-height:48px!important;'
            . 'overflow:visible!important;'
            . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
            . 'margin:0!important;padding:0!important;padding-inline:0!important;box-sizing:border-box!important}'
            . $root . ' .awa-nav-bar__inner{'
            . 'display:flex!important;' . $paint
            . ';height:48px!important;min-height:48px!important;max-height:48px!important;'
            . 'overflow:visible!important;align-items:center!important;'
            . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
            . 'margin-inline:0!important;padding-inline:max(24px,calc((100% - 1280px)/2 + 24px))!important;'
            . 'box-sizing:border-box!important}'
            . $root . ' .header-control.header-nav .menu_left_home1,'
            . $root . ' .awa-header-categories.menu_left_home1{'
            . 'display:flex!important;' . $paint
            . ';width:auto!important;max-width:206px!important;height:44px!important;'
            . 'min-height:44px!important;flex:0 0 auto!important;overflow:visible!important;'
            . 'position:relative!important}'
            . $root . ' .awa-header-categories.menu_left_home1 :is('
            . '.awa-nav-categories,.sections.nav-sections.category-dropdown,'
            . '.section-items.nav-sections.category-dropdown-items,'
            . '#awa-category-navigation,#awa-category-navigation.awa-header-primary-nav,'
            . '#menu\\.vertical,.section-item-content.nav-sections.category-dropdown-item-content,'
            . '.navigation.verticalmenu.side-verticalmenu){'
            . 'display:contents!important;position:static!important;transform:none!important;'
            . 'translate:none!important;left:auto!important;top:auto!important;right:auto!important;'
            . 'inset:auto!important;width:auto!important;max-width:none!important;min-width:0!important;'
            . 'height:auto!important;max-height:none!important;overflow:visible!important;'
            . 'background:transparent!important;box-shadow:none!important;z-index:auto!important}'
            . $root . ' .awa-header-categories.menu_left_home1 ul.togge-menu.list-category-dropdown,'
            . $root . ' .awa-header-categories.menu_left_home1 [data-role="awa-vertical-menu-panel"]{'
            . 'display:none!important;position:absolute!important;top:44px!important;left:0!important;'
            . 'right:auto!important;bottom:auto!important;transform:none!important;translate:none!important;'
            . 'width:min(304px,calc(100vw - 32px))!important;max-width:min(304px,calc(100vw - 32px))!important;'
            . 'height:auto!important;max-height:min(70vh,560px)!important;overflow-x:hidden!important;'
            . 'overflow-y:auto!important;z-index:100150!important;'
            . 'background:var(--awa-bg,Canvas)!important;box-shadow:0 8px 24px rgb(15 23 42 / 12%)!important}'
            . $root . ' .awa-header-categories.menu_left_home1 ul.togge-menu.list-category-dropdown'
            . ':is(.menu-open,.vmm-open,[aria-hidden="false"]),'
            . $root . ' .awa-header-categories.menu_left_home1 [data-role="awa-vertical-menu-panel"]'
            . ':is(.menu-open,.vmm-open,[aria-hidden="false"],[data-awa-menu-state="open"]){'
            . 'display:flex!important;flex-direction:column!important;' . $paint
            . ';position:absolute!important;transform:none!important;top:44px!important;left:8px!important;'
            . 'height:auto!important;max-height:min(70vh,560px)!important;overflow-y:auto!important}'
            . $root . ' button.our_categories.title-category-dropdown,'
            . $root . ' button.our_categories,'
            . $root . ' .awa-header-categories.menu_left_home1 button.our_categories{'
            . 'display:inline-flex!important;' . $paint
            . ';position:static!important;transform:none!important;translate:none!important;'
            . 'width:auto!important;min-width:0!important;max-width:206px!important;'
            . 'height:44px!important;min-height:44px!important;align-items:center!important}'
            . $root . ' .awa-nav-quick-links,'
            . $root . ' .awa-nav-quick-links__list{'
            . 'display:flex!important;' . $paint
            . ';position:static!important;max-width:none!important;min-width:0!important;'
            . 'width:auto!important;flex-direction:row!important;align-items:center!important;'
            . 'flex:1 1 auto!important;height:44px!important;max-height:44px!important;'
            . 'gap:8px!important;overflow:hidden!important}'
            . $root . ' .awa-nav-quick-links__link{'
            . 'display:inline-flex!important;' . $paint
            . ';height:44px!important;align-items:center!important;padding-inline:10px!important;'
            . 'white-space:nowrap!important}'
            . $root . ' .awa-header-account-prompt,'
            . $root . ' .awa-header-contact-links.awa-header-account-prompt,'
            . $root . ' .awa-header-account-prompt[data-awa-auth-state="guest"]{'
            . 'display:inline-flex!important;' . $paint
            . ';width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;overflow:hidden!important;'
            . 'flex:0 0 44px!important;position:relative!important}'
            . $root . ' .awa-header-account-prompt :is('
            . '.awa-header-account-prompt__icon,.awa-header-account-prompt__text,'
            . '.awa-header-account-prompt__guest,.awa-header-account-prompt__customer,'
            . '.awa-header-account-prompt__live,.awa-header-account-prompt__copy){'
            . $hide . '}'
            . $root . ' .awa-header-account-prompt__mobile-link{'
            . 'display:inline-flex!important;' . $paint
            . ';align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;min-width:44px!important;height:44px!important;'
            . 'min-height:44px!important;position:relative!important;'
            . 'color:var(--awa-primary)!important}'
            . $root . ' .header-wrapper-sticky :is('
            . '.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){'
            . 'grid-template-columns:minmax(112px,148px) minmax(0,1fr) 98px!important;'
            . 'column-gap:16px!important}'
            . $root . ' .header-wrapper-sticky .awa-header-right-col{'
            . 'display:flex!important;width:98px!important;min-width:98px!important;'
            . 'max-width:98px!important;gap:10px!important;justify-content:flex-end!important;'
            . 'overflow:visible!important}'
            . '}'
            . '@media (min-width:992px) and (max-width:1023px){'
            . $root . ' .header-control.header-nav .menu_left_home1,'
            . $root . ' .awa-header-categories.menu_left_home1{'
            . 'display:flex!important;' . $paint
            . ';width:auto!important;max-width:206px!important;height:44px!important;'
            . 'overflow:visible!important;position:relative!important}'
            . $root . ' .awa-header-categories.menu_left_home1 :is('
            . '.awa-nav-categories,.sections.nav-sections.category-dropdown,'
            . '.section-items.nav-sections.category-dropdown-items,'
            . '#awa-category-navigation,#awa-category-navigation.awa-header-primary-nav,'
            . '#menu\\.vertical,.section-item-content.nav-sections.category-dropdown-item-content,'
            . '.navigation.verticalmenu.side-verticalmenu){'
            . 'display:contents!important;position:static!important;transform:none!important;'
            . 'translate:none!important;inset:auto!important;width:auto!important}'
            . $root . ' button.our_categories.title-category-dropdown,'
            . $root . ' button.our_categories{'
            . 'display:inline-flex!important;' . $paint
            . ';position:static!important;transform:none!important;'
            . 'height:44px!important;min-height:44px!important}'
            . '}';
    }

    /**
     * Terminal sync — promo BUG-20 + painel B2B mobile (vence align-grid 4x html-body + account-hierarchy).
     */
    public static function headerLayoutSyncTerminalRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body .page-wrapper';
        $promo = $root . ' :is('
            . '#awa-b2b-promo-bar,'
            . '#header.header-container[data-awa-header-shell="true"] .top-header.awa-b2b-promo-bar,'
            . '#header.header-container[data-awa-header-shell="true"] .awa-b2b-promo-bar[data-awa-header-utility],'
            . '#header .top-header.awa-b2b-promo-bar,'
            . '#header .awa-b2b-promo-bar[data-awa-header-utility],'
            . '.awa-site-header .top-header.awa-b2b-promo-bar,'
            . '.awa-site-header .awa-b2b-promo-bar[data-awa-header-utility]'
            . ')';

        return $promo . '{'
            . 'background:#ffffff!important;'
            . 'background-color:#ffffff!important;'
            . 'background-image:none!important;'
            . 'color:var(--awa-text-primary,#111827)!important;'
            . 'min-height:44px!important;max-height:44px!important;height:44px!important}'
            . $root . ' .awa-site-header .awa-b2b-promo-bar :is('
            . '.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,.awa-b2b-promo-bar__tail,'
            . '.awa-b2b-promo-bar__separator){color:inherit!important;line-height:1.4!important}'
            . $root . ' .awa-site-header .awa-b2b-promo-bar :is('
            . '.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta strong){'
            . 'color:inherit!important;line-height:1.4!important;text-decoration:underline!important;'
            . 'text-underline-offset:2px!important}'
            . $root . ' .awa-site-header button.awa-b2b-promo-close,'
            . $root . ' #header button.awa-b2b-promo-close{'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'max-height:44px!important;padding:0!important;box-sizing:border-box!important;border:0!important;'
            . 'background:transparent!important;color:inherit!important;opacity:1!important}'
            . $root . ' .awa-site-header .b2b-status-panel .b2b-status-trigger{'
            . 'min-height:44px!important;min-width:44px!important;box-sizing:border-box!important;'
            . 'align-items:center!important}'
            . '@media(max-width:767px){'
            . $root . ' #header .header-wrapper-sticky .awa-header-right-col:not(:has(.b2b-status-panel)),'
            . $root . ' .awa-site-header .header-wrapper-sticky .awa-header-right-col:not(:has(.b2b-status-panel)){'
            . 'display:contents!important}'
            . $root . ' #header .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel),'
            . $root . ' .awa-site-header .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel){'
            . 'display:flex!important;grid-area:cart!important;align-items:center!important;'
            . 'justify-content:flex-end!important;gap:2px!important;flex-wrap:nowrap!important;'
            . 'max-width:min(200px,52vw)!important;min-width:0!important;overflow:hidden!important}'
            . $root . ' #header .header-wrapper-sticky .awa-header-right-col > .b2b-status-panel,'
            . $root . ' .awa-site-header .header-wrapper-sticky .awa-header-right-col > .b2b-status-panel{'
            . 'display:flex!important;visibility:visible!important;pointer-events:auto!important;'
            . 'width:auto!important;min-width:0!important;max-width:min(140px,38vw)!important;'
            . 'height:auto!important;overflow:hidden!important;align-items:center!important;flex:1 1 auto!important}'
            . $root . ' .awa-site-header .header-wrapper-sticky .awa-header-right-col:has(.b2b-status-panel) .awa-header-minicart{'
            . 'grid-area:unset!important;flex:0 0 44px!important;width:44px!important;'
            . 'min-width:44px!important;max-width:44px!important}'
            . '}';
    }

    /**
     * Adapt responsivo terminal — grid 44px, promo 44px, busca 16px mobile, safe-area.
     * Vence align-grid 32px promo + mobile-grid-critical 40px colunas na home.
     */
    public static function headerAdaptResponsiveRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body .page-wrapper';
        $shell = $root . ' .awa-site-header';
        $promo = $root . ' :is('
            . '#awa-b2b-promo-bar,'
            . '#header .top-header.awa-b2b-promo-bar,'
            . '#header .awa-b2b-promo-bar[data-awa-header-utility],'
            . '.awa-site-header .top-header.awa-b2b-promo-bar,'
            . '.awa-site-header .awa-b2b-promo-bar[data-awa-header-utility]'
            . ')';
        $row = $shell . ' .header-wrapper-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $searchInput = $shell . ' .awa-header-search-col form#search_mini_form input#search';

        return $promo . '{'
            . 'min-height:44px!important;max-height:44px!important;height:44px!important;'
            . 'box-sizing:border-box!important}'
            . $promo . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout){'
            . 'min-height:44px!important;max-height:44px!important;height:44px!important;'
            . 'align-items:center!important;box-sizing:border-box!important}'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header:not(.awa-header-condensed) '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'display:grid!important;grid-template-columns:44px minmax(0,1fr) 44px!important;'
            . 'grid-template-rows:44px 44px!important;'
            . 'grid-template-areas:"toggle brand cart" "search search search"!important;'
            . 'gap:4px 8px!important;height:96px!important;min-height:96px!important;max-height:96px!important;'
            . 'padding:4px 16px 0!important;box-sizing:border-box!important}'
            . $shell . ':not(.awa-header-condensed) .header-wrapper-sticky{'
            . 'min-height:96px!important;height:96px!important;max-height:96px!important;'
            . 'padding-top:max(0px,env(safe-area-inset-top,0px))!important;box-sizing:border-box!important}'
            . $shell . ':not(.awa-header-condensed) .header-wrapper-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'grid-template-columns:44px minmax(0,1fr) 44px!important;'
            . 'grid-template-rows:44px 44px!important;'
            . 'grid-template-areas:"toggle brand cart" "search search search"!important;'
            . 'gap:4px 8px!important;max-height:96px!important;height:96px!important;min-height:96px!important;'
            . 'padding:4px 16px 0!important;box-sizing:border-box!important}'
            . $shell . ' .header-wrapper-sticky :is(.awa-header-brand-cell,.col-md-2.awa-header-brand){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important}'
            . $shell . ' .header-wrapper-sticky .awa-header-mobile-toggle{'
            . 'display:inline-flex!important;width:44px!important;min-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;touch-action:manipulation!important}'
            . $searchInput . '{font-size:max(16px,1rem)!important;line-height:1.25!important}'
            . $shell . ' :is(.awa-header-mobile-toggle,button.awa-b2b-promo-close,'
            . '.awa-header-minicart .action.showcart,form#search_mini_form button.action.search,'
            . '.b2b-status-trigger,.awa-header-account-prompt__mobile-link){'
            . 'touch-action:manipulation!important}'
            . '}'
            . '@media(min-width:768px) and (max-width:991px){'
            . $searchInput . '{font-size:max(16px,1rem)!important}'
            . $shell . ' .header-wrapper-sticky .awa-header-right-col .awa-header-account-prompt{'
            . 'min-height:44px!important;align-items:center!important}'
            . $shell . ' .header-wrapper-sticky .awa-header-right-col '
            . '.awa-header-account-prompt__mobile-link{'
            . 'min-width:44px!important;min-height:44px!important}'
            . '}'
            . '@media(max-width:480px){'
            . $row . '{'
            . 'padding-inline:max(16px,env(safe-area-inset-left,0px)) '
            . 'max(16px,env(safe-area-inset-right,0px))!important}'
            . '}'
            . '@media(min-width:992px){'
            . $searchInput . '{font-size:14px!important;line-height:1.35!important}'
            . '}';
    }

    /**
     * Harden terminal — overflow/i18n/edge cases (vence adapt; promo, B2B, busca, minicart).
     */
    public static function headerHardenCssRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body .page-wrapper';
        $shell = $root . ' .awa-site-header';
        $promo = $root . ' :is('
            . '#awa-b2b-promo-bar,'
            . '#header .top-header.awa-b2b-promo-bar,'
            . '#header .awa-b2b-promo-bar[data-awa-header-utility],'
            . '.awa-site-header .top-header.awa-b2b-promo-bar,'
            . '.awa-site-header .awa-b2b-promo-bar[data-awa-header-utility]'
            . ')';
        $promoText = $promo . ' :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,'
            . '.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__separator,.awa-b2b-promo-bar__cta)';
        $account = $shell . ' .awa-header-account-prompt';
        $b2bTrigger = $shell . ' .b2b-status-panel .b2b-status-trigger';
        $minicartBadge = $shell . ' .awa-header-minicart .counter.qty,.minicart-wrapper .counter-number';

        return $promo . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout){'
            . 'min-width:0!important;width:100%!important;overflow:hidden!important}'
            . $promoText . '{min-width:0!important;overflow:hidden!important;'
            . 'text-overflow:ellipsis!important;white-space:nowrap!important}'
            . $promo . '{padding-inline:max(40px,env(safe-area-inset-left,0px)+36px) '
            . 'max(12px,env(safe-area-inset-right,0px))!important}'
            . $b2bTrigger . '{min-width:0!important;max-width:100%!important;overflow:hidden!important}'
            . $shell . ' .b2b-status-panel .status-info{min-width:0!important;flex:1 1 auto!important}'
            . $shell . ' .b2b-status-panel :is(.status-greeting,.status-company){'
            . 'max-width:min(14ch,100%)!important}'
            . $account . ' .awa-header-account-prompt__text{min-width:0!important;max-width:100%!important}'
            . $account . '--long-name .awa-header-account-prompt__customer .awa-header-account-prompt__line1{'
            . 'display:block!important;max-width:min(14ch,100%)!important;overflow:hidden!important;'
            . 'text-overflow:ellipsis!important;white-space:nowrap!important}'
            . $shell . ' .awa-account-dropdown__menu{'
            . 'min-width:12rem!important;max-width:min(18rem,calc(100vw - 24px))!important}'
            . $shell . ' .awa-account-dropdown__item{overflow:hidden!important;'
            . 'text-overflow:ellipsis!important;white-space:nowrap!important}'
            . $minicartBadge . '{max-width:2.5ch!important;overflow:hidden!important;'
            . 'text-overflow:clip!important;font-size:max(10px,0.625rem)!important}'
            . '@media(max-width:767px){'
            . $shell . ' .b2b-status-panel{min-width:0!important;max-width:100%!important}'
            . $b2bTrigger . '{padding-inline:8px!important;gap:6px!important}'
            . $shell . ' .b2b-status-panel .status-group{min-width:0!important;overflow:hidden!important}'
            . $account . '{min-width:0!important;max-width:100%!important}'
            . $account . ' .awa-header-account-prompt__line1{'
            . 'max-width:min(12ch,100%)!important;overflow:hidden!important;'
            . 'text-overflow:ellipsis!important;white-space:nowrap!important}'
            . '}'
            . '@media(min-width:992px){'
            . $account . ' .awa-header-account-prompt__text{min-width:0!important}'
            . $account . '[data-awa-auth-state="customer"] .awa-header-account-prompt__customer{'
            . 'min-width:0!important;max-width:100%!important}'
            . '}'
            . '@media(prefers-reduced-motion:reduce){'
            . $promo . '.is-dismissing{transition:none!important;animation:none!important;opacity:0!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-pending="true"] '
            . '.awa-header-account-prompt__guest{transition:none!important}'
            . '}';
    }

    /**
     * Harden síncrono — busca vazia, promo dismiss FOUC, live region.
     */
    public static function headerHardenScript(): string
    {
        return '<script id="awa-header-harden-script-20260616">'
            . '(function(){'
            . 'if(window.__awaHeaderHardenInit){return;}'
            . 'window.__awaHeaderHardenInit=true;'
            . 'var bar=document.getElementById("awa-b2b-promo-bar");'
            . 'var btn=document.getElementById("awa-b2b-promo-close");'
            . 'var reduced=window.matchMedia("(prefers-reduced-motion: reduce)").matches;'
            . 'function hidePromo(){if(!bar){return;}'
            . 'bar.style.display="none";bar.setAttribute("aria-hidden","true");'
            . 'document.documentElement.classList.add("awa-b2b-promo-dismissed");'
            . 'var shell=bar.closest("#header.header-container");'
            . 'if(shell){shell.classList.add("awa-b2b-promo-shell--collapsed");'
            . 'shell.style.removeProperty("display");shell.removeAttribute("aria-hidden");shell.removeAttribute("inert");}'
            . 'try{localStorage.setItem("awa_b2b_promo_dismissed","1");}catch(e){}}'
            . 'try{if(localStorage.getItem("awa_b2b_promo_dismissed")==="1"){hidePromo();}}catch(e){}'
            . 'if(bar&&btn&&!window.__awaPromoDismissInit){'
            . 'window.__awaPromoDismissInit=true;var dismissing=false;'
            . 'btn.addEventListener("click",function(){'
            . 'if(dismissing||bar.getAttribute("aria-hidden")==="true"){return;}'
            . 'dismissing=true;btn.disabled=true;btn.setAttribute("aria-busy","true");'
            . 'if(reduced){hidePromo();return;}'
            . 'bar.classList.add("is-dismissing");window.setTimeout(hidePromo,320);'
            . '});}'
            . 'var form=document.querySelector(".awa-site-header form#search_mini_form");'
            . 'if(!form){return;}'
            . 'form.addEventListener("submit",function(ev){'
            . 'var input=form.querySelector("#search");'
            . 'if(!input){return;}'
            . 'if(!String(input.value||"").trim()){'
            . 'ev.preventDefault();ev.stopImmediatePropagation();input.setAttribute("aria-invalid","true");input.focus();'
            . 'var live=document.getElementById("awa-header-search-live");'
            . 'if(!live){live=document.createElement("div");live.id="awa-header-search-live";'
            . 'live.className="awa-sr-live";live.setAttribute("role","status");'
            . 'live.setAttribute("aria-live","polite");form.appendChild(live);}'
            . 'live.textContent="Digite um termo para buscar";'
            . 'window.setTimeout(function(){input.removeAttribute("aria-invalid");live.textContent="";},3000);'
            . '}},true);'
            . '})();'
            . '</script>';
    }

    /**
     * Layout terminal — tokens de ritmo, seam promo/main, col-gap e nav flush.
     * Corrige header-container 36px vs promo 44px (overlap ~8px medido em runtime).
     */
    public static function headerLayoutSpacingRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body .page-wrapper';
        $shell = $root . ' .awa-site-header';
        $promo = $root . ' :is('
            . '#awa-b2b-promo-bar,'
            . '#header .top-header.awa-b2b-promo-bar,'
            . '#header .awa-b2b-promo-bar[data-awa-header-utility],'
            . '.awa-site-header .top-header.awa-b2b-promo-bar,'
            . '.awa-site-header .awa-b2b-promo-bar[data-awa-header-utility]'
            . ')';
        $headerContainer = $shell . ' .header-container:has(.awa-b2b-promo-bar),'
            . $shell . ' .header-container:has(#awa-b2b-promo-bar),'
            . $root . ' #header.header-container:has(.awa-b2b-promo-bar),'
            . $root . ' #header.header-container:has(#awa-b2b-promo-bar)';
        $sticky = $shell . ' .header-wrapper-sticky';
        $row = $sticky . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $nav = $shell . ' :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar)';
        $rightCol = $shell . ' .awa-header-right-col';
        $promoInner = $promo . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout,.header-content)';

        return $shell . '{'
            . '--awa-header-promo-h:44px;--awa-header-nav-h:48px;'
            . '--awa-header-shell-pad:16px;--awa-header-col-gap:clamp(16px,2vw,24px)}'
            . '@media(max-width:767px){' . $shell . '{'
            . '--awa-header-main-row-h:96px;--awa-header-shell-pad:16px;--awa-header-col-gap:8px;'
            . '--awa-header-stack-h:calc(var(--awa-header-promo-h)+var(--awa-header-main-row-h))}}'
            . '@media(min-width:768px) and (max-width:991px){' . $shell . '{'
            . '--awa-header-main-row-h:56px;--awa-header-col-gap:12px;'
            . '--awa-header-stack-h:calc(var(--awa-header-promo-h)+var(--awa-header-main-row-h)+var(--awa-header-nav-h))}}'
            . '@media(min-width:992px){' . $shell . '{'
            . '--awa-header-main-row-h:76px;'
            . '--awa-header-stack-h:calc(var(--awa-header-promo-h)+var(--awa-header-main-row-h)+var(--awa-header-nav-h));'
            . '--awa-header-scroll-offset:calc(var(--awa-header-stack-h)+8px)}}'
            . $headerContainer . '{'
            . 'height:var(--awa-header-promo-h)!important;min-height:var(--awa-header-promo-h)!important;'
            . 'max-height:var(--awa-header-promo-h)!important;overflow:visible!important;'
            . 'padding-block:8px!important;margin-block:0!important;box-sizing:border-box!important}'
            . $promo . '{'
            . 'height:var(--awa-header-promo-h)!important;min-height:var(--awa-header-promo-h)!important;'
            . 'max-height:var(--awa-header-promo-h)!important;'
            . 'padding-block:8px!important;margin-block:0!important;box-sizing:border-box!important}'
            . $promoInner . '{'
            . 'height:100%!important;min-height:0!important;max-height:100%!important;'
            . 'align-items:center!important;padding-block:8px!important;box-sizing:border-box!important}'
            . $sticky . '{margin-block-start:0!important;padding-block-start:0!important;box-sizing:border-box!important}'
            . '@media(min-width:768px){' . $row . '{'
            . 'column-gap:var(--awa-header-col-gap)!important;row-gap:0!important;'
            . 'padding-inline:var(--awa-header-shell-pad)!important}}'
            . $rightCol . '{display:inline-flex!important;align-items:center!important;'
            . 'gap:min(var(--awa-header-col-gap,12px),12px)!important;flex-wrap:nowrap!important}'
            . '@media(min-width:992px){' . $sticky . '{'
            . 'display:flex!important;flex-direction:column!important;gap:0!important}'
            . $nav . '{margin-block-start:1px!important;padding-block-start:0!important;flex-shrink:0!important}'
            . $shell . ' .header-wrapper-sticky :is(.header-main,.header_main){'
            . 'margin-block-end:0!important;padding-block-end:0!important}}'
            . '@media(min-width:768px) and (max-width:991px){'
            . $shell . ' .awa-header-search-col,'
            . $shell . ' .awa-header-search-col form#search_mini_form{'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important}'
            . '}';
    }

    /**
     * Polish terminal — tokens live, estados de interação, seam main/nav, focus rings.
     * headerPolishRules do cascade-lock nem sempre está no HTML (home/PLP omit).
     */
    public static function headerPolishTerminalRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body .page-wrapper';
        $shell = $root . ' .awa-site-header';
        $row = $shell . ' .header-wrapper-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $mainHeader = $shell . ' .header-wrapper-sticky .header.awa-main-header';
        $nav = $shell . ' :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar)';
        $searchForm = $shell . ' form#search_mini_form';
        $searchBtn = $shell . ' form#search_mini_form button.action.search';
        $promoClose = $root . ' button.awa-b2b-promo-close,#awa-b2b-promo-close';
        $mobileToggle = $shell . ' :is(.awa-header-mobile-toggle,.nav-toggle,[data-action="toggle-nav"])';
        $interactive = $shell . ' :is('
            . 'form#search_mini_form,button.action.search,.action.showcart,.awa-minicart-trigger,'
            . '.awa-nav-quick-links__link,.custommenu.main-nav a,.navigation.custommenu a,'
            . 'button.awa-b2b-promo-close,.awa-header-mobile-toggle,'
            . 'button[data-role=awa-vertical-menu-trigger],.our_categories.title-category-dropdown'
            . ')';

        return $shell . '{'
            . '--awa-header-polish-ease:cubic-bezier(.22,1,.36,1);'
            . '--awa-header-polish-hover:oklch(42% .13 20);'
            . '--awa-header-polish-ring:color-mix(in srgb,var(--awa-primary,oklch(48% .14 20)) 26%,transparent)}'
            . $interactive . '{'
            . 'transition:background-color .18s var(--awa-header-polish-ease),'
            . 'border-color .18s var(--awa-header-polish-ease),'
            . 'box-shadow .18s var(--awa-header-polish-ease),'
            . 'color .18s var(--awa-header-polish-ease),'
            . 'transform .12s var(--awa-header-polish-ease)!important}'
            . $searchForm . ' input#search::placeholder{'
            . 'color:oklch(50% .018 20)!important;opacity:1!important}'
            . $searchForm . ':hover{'
            . 'border-color:color-mix(in srgb,var(--awa-primary,oklch(48% .14 20)) 38%,var(--awa-border,oklch(90% .008 20)))!important}'
            . $searchForm . ':focus-within{'
            . 'border-color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'box-shadow:0 0 0 3px var(--awa-header-polish-ring)!important}'
            . $searchBtn . ':hover{background:var(--awa-header-polish-hover)!important;'
            . 'box-shadow:0 6px 14px rgb(15 23 42/14%)!important}'
            . $searchBtn . ':active{transform:translateY(1px)!important;box-shadow:none!important}'
            . $searchBtn . ':focus-visible{outline:2px solid var(--awa-primary,oklch(48% .14 20))!important;'
            . 'outline-offset:2px!important}'
            . $promoClose . ':focus-visible{outline:2px solid var(--awa-text-inverse,oklch(99% .002 20))!important;'
            . 'outline-offset:2px!important}'
            . $mobileToggle . ':focus-visible{outline:2px solid var(--awa-primary,oklch(48% .14 20))!important;'
            . 'outline-offset:2px!important}'
            . $nav . ' :is(a,button):focus-visible{'
            . 'outline:2px solid var(--awa-text-inverse,oklch(99% .002 20))!important;'
            . 'outline-offset:2px!important;border-radius:8px!important}'
            . $nav . ' :is(a,button):hover{'
            . 'background:color-mix(in srgb,var(--awa-text-inverse,oklch(99% .002 20)) 14%,transparent)!important;'
            . 'border-radius:8px!important;text-decoration:none!important}'
            . $shell . ' :is(.action.showcart,.awa-minicart-trigger) .counter.qty{'
            . 'position:absolute!important;inset-block-start:-5px!important;inset-inline-end:-6px!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'min-width:18px!important;height:18px!important;padding:0 5px!important;'
            . 'border-radius:999px!important;background:oklch(99% .002 20)!important;'
            . 'color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'font-size:11px!important;font-weight:800!important;line-height:18px!important;'
            . 'box-shadow:0 0 0 2px var(--awa-primary,oklch(48% .14 20))!important}'
            . '@media(max-width:991px){' . $shell . ':not(.awa-header-condensed) ' . $row . '{'
            . 'overflow:visible!important}'
            . $shell . ' :is(.awa-header-mobile-toggle,.nav-toggle,[data-action="toggle-nav"])[aria-expanded="true"]{'
            . 'background:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'border-color:var(--awa-primary,oklch(48% .14 20))!important;color:oklch(99% .002 20)!important}'
            . $shell . ' :is(.awa-header-mobile-toggle,.nav-toggle,[data-action="toggle-nav"])[aria-expanded="true"] svg{'
            . 'stroke:oklch(99% .002 20)!important;color:oklch(99% .002 20)!important}'
            . '}'
            . '@media(min-width:992px){' . $mainHeader . '{'
            . 'display:flex!important;align-items:center!important;'
            . 'height:var(--awa-header-main-row-h,72px)!important;'
            . 'min-height:var(--awa-header-main-row-h,72px)!important;'
            . 'max-height:var(--awa-header-main-row-h,72px)!important;'
            . 'overflow:hidden!important;line-height:1!important;padding-block:0!important;margin-block:0!important;'
            . 'box-sizing:border-box!important}'
            . $row . '{height:var(--awa-header-main-row-h,72px)!important;'
            . 'max-height:var(--awa-header-main-row-h,72px)!important;min-height:0!important;'
            . 'margin-block:0!important;padding-block:0!important;box-sizing:border-box!important;'
            . 'align-self:center!important}'
            . $nav . '{margin-block-start:0!important;padding-block-start:0!important;transform:translateY(1px)!important}'
            . $shell . ' .awa-header-brand-cell :is(a,img){'
            . 'display:flex!important;align-items:center!important;'
            . 'max-height:calc(var(--awa-header-main-row-h,72px) - 16px)!important}'
            . '}'
            . '@media(prefers-reduced-motion:reduce){' . $interactive . '{transition:none!important}'
            . $searchBtn . ':active{transform:none!important}'
            . '}';
    }

    /**
     * Container pad zero — mobile header inner (vence themes.min.css tardio).
     */
    public static function headerAlignGridContainerPadRules(): string
    {
        return 'html body#html-body#html-body .page-wrapper .header .header-main>.container,'
            . 'html body#html-body#html-body .page-wrapper .header .header_main>.container,'
            . 'html body#html-body#html-body .page-wrapper .header .header-main .container,'
            . 'html body#html-body#html-body .page-wrapper .header .header_main .container,'
            . 'html body#html-body#html-body .page-wrapper .header-main>.container,'
            . 'html body#html-body#html-body .page-wrapper .header_main>.container,'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header-main>.container,'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header_main>.container'
            . '{padding:0!important;padding-inline:0!important;padding-left:0!important;padding-right:0!important}';
    }

    public static function headerAlignGridContainerPadScript(): string
    {
        return '<script id="awa-header-container-pad-zero">(function(){'
            . '"use strict";function z(){if(window.innerWidth>991)return;'
            . 'document.querySelectorAll(".page-wrapper .header-main>.container,.page-wrapper .header_main>.container").forEach(function(el){'
            . 'el.style.removeProperty("padding");'
            . 'el.style.removeProperty("padding-inline");'
            . 'el.style.removeProperty("padding-left");'
            . 'el.style.removeProperty("padding-right");'
            . 'if(!el.getAttribute("style"))el.removeAttribute("style");});}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",z,{once:true});}'
            . 'else{z();}window.addEventListener("load",z,{once:true,passive:true});})();</script>';
    }

    /**
     * Distill terminal — SSOT único: substitui 6 blocos inline (~90KB) por 1 (~35KB).
     *
     * @param bool $includeHomeSupplement regras home-only (a11y, minicart, compact) quando omitimos home-light-lock
     */
    public static function headerDistillTerminalCss(bool $includeHomeSupplement = false): string
    {
        $css = self::headerLayoutAlignRules()
            . self::mobileSearchTerminalRules()
            . self::headerVisFixTerminalRules()
            . self::headerCartDedupeRules()
            . self::headerLayoutSyncTerminalRules()
            . self::headerAdaptResponsiveRules()
            . self::headerLayoutSpacingRules()
            . self::headerHardenCssRules()
            . self::headerPolishTerminalRules()
            . self::headerEssentialTerminalRules()
            . self::headerAlignGridContainerPadRules();

        if ($includeHomeSupplement) {
            $css .= self::headerDistillHomeSupplementRules();
        }

        $css .= self::headerLayoutTerminalLockRules()
            . self::headerImpeccableCommercePaddingRules()
            . self::homeHeaderRailTerminalRules()
            . self::plpImpeccableTerminalLockRules()
            . self::catalogMobileHeaderClampRules(self::checkoutImpeccableLockRoot())
            . self::headerBolderActionContrastLockRules()
            /* Ghost DEPOIS do contrast lock — minicart não é CTA vermelho no storefront. */
            . self::headerMinicartGhostTerminalRules()
            . self::headerAccountVtexCleanTerminalRules()
            . self::headerSimplifyUiTerminalRules()
            . self::headerVisualBugsFixRules()
            . self::headerGuestChromeKillCardRules();

        return $css;
    }

    /**
     * 2026-07-16 — bugs visuais remanescentes (PDP/PLP/mobile):
     * Departamentos 14/600, B2B trigger 44px, search control overflow,
     * lupa duplicada, truncamento da conta no mobile.
     */
    public static function headerVisualBugsFixRules(): string
    {
        // 5× #html-body — vence align-grid terminal (mesma especificidade, ordem final).
        $h = 'html body#html-body#html-body#html-body#html-body#html-body '
            . '.page-wrapper .awa-site-header[data-awa-header-mode="default"]';
        $search = $h . ' .awa-header-search-col';
        $dept = $h . ' :is(.our_categories.title-category-dropdown,.title-category-dropdown.our_categories,'
            . 'button[data-role="awa-vertical-menu-trigger"])';
        $b2b = $h . ' .b2b-status-panel .b2b-status-trigger';

        return $dept . '{'
            . 'font-size:14px!important;font-weight:600!important;'
            . 'letter-spacing:0!important}'
            . $dept . ' .awa-vmenu-trigger-text{'
            . 'font-size:14px!important;font-weight:600!important}'
            // B2B trigger: altura 44px alinhada à busca/carrinho (desktop).
            . '@media(min-width:992px){'
            . $b2b . '{'
            . 'display:inline-flex!important;align-items:center!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'box-sizing:border-box!important;padding-block:0!important}'
            . $b2b . ' .b2b-status-trigger__text{'
            . 'gap:0!important;justify-content:center!important;min-width:0!important}'
            . $b2b . ' .b2b-status-trigger__line1{'
            . 'font-size:11px!important;line-height:1.1!important;font-weight:400!important}'
            . $b2b . ' .b2b-status-trigger__line2{'
            . 'font-size:12px!important;line-height:1.15!important;font-weight:600!important}'
            . '}'
            // Search control: clip no X (não força Y=auto como overflow-x:hidden+y:visible).
            . $search . ' :is(.block-search .control,.field.search .control,form#search_mini_form .control){'
            . 'overflow-x:clip!important;overflow-y:visible!important}'
            // Lupa duplicada: esconder label Magento + background do input em todas as viewports.
            . $search . ' :is(.block-search .label,.block-search .block-title,label.search,label[for="search"],.nested){'
            . 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'max-height:0!important;overflow:hidden!important;pointer-events:none!important;'
            . 'position:absolute!important;clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important}'
            . $search . ' form#search_mini_form :is(input#search,input[type="text"],.input-text){'
            . 'background-image:none!important;padding-inline-start:12px!important}'
            . $search . ' form#search_mini_form :is(.field.search,.control)::before,'
            . $search . ' form#search_mini_form :is(.field.search,.control)::after{'
            . 'content:none!important;display:none!important}'
            // Mobile: mostrar primeiro nome (line1), ocultar empresa truncada (line2).
            . '@media(max-width:767px){'
            . $b2b . '{'
            . 'max-width:min(160px,42vw)!important;min-width:0!important;overflow:hidden!important}'
            . $b2b . ' .b2b-status-trigger__line1{'
            . 'display:block!important;max-width:min(12ch,100%)!important;'
            . 'font-size:12px!important;font-weight:600!important;line-height:1.2!important;'
            . 'overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important}'
            . $b2b . ' .b2b-status-trigger__line2{'
            . 'display:none!important}'
            . $h . ' .header-wrapper-sticky .awa-header-right-col > .b2b-status-panel{'
            . 'max-width:min(160px,42vw)!important}'
            . '}';
    }

    /**
     * UI simplificada — menos ruído visual, uma linha por zona, sem duplicatas.
     */
    public const HEADER_SIMPLIFY_UI_STYLE_ID = 'awa-header-simplify-ui-terminal-lock';

    /**
     * Tag terminal — injetada após align-grid/header-container (final-wins sobre home 10× #html-body).
     */
    public static function headerSimplifyUiTerminalStyleTag(): string
    {
        return '<style id="' . self::HEADER_SIMPLIFY_UI_STYLE_ID . '">'
            . self::headerSimplifyUiTerminalRules()
            . self::headerVisualBugsFixRules()
            . '</style>';
    }

    public static function headerSimplifyUiTerminalRules(): string
    {
        $shell = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header[data-awa-header-mode="default"]';
        $homeShell = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5).page-layout-1column .page-wrapper '
            . '.awa-site-header[data-awa-header-mode="default"]';
        $visuallyGone = 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'min-width:0!important;min-height:0!important;max-width:0!important;max-height:0!important;'
            . 'flex:0 0 0!important;margin:0!important;padding:0!important;overflow:hidden!important;'
            . 'pointer-events:none!important;position:absolute!important;clip:rect(0,0,0,0)!important;'
            . 'clip-path:inset(50%)!important';

        return '@media(min-width:768px){'
            . $shell . ' .awa-header-primary-row>.awa-header-cart-link,' . $shell . ' .awa-header-cart-link{'
            . $visuallyGone . '}'
            . $shell . ' .top-account.awa-header-account-nav[hidden]{' . $visuallyGone . '}'
            . $shell . ' .header-control.awa-nav-bar{background:var(--awa-bg,Canvas)!important;'
            . 'border-block-start:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;'
            . 'border-block-end:0!important;box-shadow:none!important}'
            . $shell . ' .awa-nav-quick-links__link{color:var(--awa-text-secondary,CanvasText)!important;'
            . 'font-weight:500!important;text-decoration:none!important}'
            . $shell . ' .awa-nav-quick-links__link:hover{color:var(--awa-primary,CanvasText)!important}'
            . $shell . ' form#search_mini_form{box-shadow:none!important;'
            . 'border:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;'
            . 'background:var(--awa-bg,var(--awa-white,Canvas))!important}'
            . $shell . ' form#search_mini_form:focus-within{background:var(--awa-bg,Canvas)!important;'
            . 'border-color:var(--awa-primary,CanvasText)!important}'
            . $shell . ' form#search_mini_form button.action.search{background:transparent!important;'
            . 'color:var(--awa-primary,CanvasText)!important;box-shadow:none!important}'
            . '}'
            . '@media(min-width:992px){'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"],'
            . $homeShell . ' .awa-header-account-prompt[data-awa-auth-state="guest"]{'
            . 'display:inline-flex!important;align-items:center!important;gap:8px!important;'
            . 'grid-template-columns:unset!important;max-width:none!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] a.awa-header-account-prompt__icon,'
            . $homeShell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] a.awa-header-account-prompt__icon{'
            . 'display:inline-flex!important;visibility:visible!important;align-items:center!important;'
            . 'justify-content:center!important;flex:0 0 auto!important;width:auto!important;height:auto!important;'
            . 'min-width:0!important;min-height:0!important;max-width:none!important;max-height:none!important;'
            . 'margin:0!important;padding:0!important;overflow:visible!important;pointer-events:auto!important;'
            . 'position:static!important;clip:auto!important;clip-path:none!important;'
            . 'color:var(--awa-primary,#b73337)!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] a.awa-header-account-prompt__icon svg,'
            . $homeShell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] a.awa-header-account-prompt__icon svg{'
            . 'display:block!important;width:24px!important;height:24px!important;color:var(--awa-primary,#b73337)!important}'
            . $shell . ' .awa-header-account-prompt :is(.awa-header-account-prompt__text,.awa-header-account-prompt__guest),'
            . $homeShell . ' .awa-header-account-prompt :is(.awa-header-account-prompt__text,.awa-header-account-prompt__guest){'
            . 'display:inline-flex!important;flex-direction:row!important;align-items:center!important;'
            . 'gap:0!important;height:44px!important;max-height:44px!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__guest,'
            . $homeShell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__guest{'
            . 'display:inline-flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'justify-content:center!important;gap:1px!important;height:44px!important;max-height:44px!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="customer"] .awa-header-account-prompt__line1,'
            . $homeShell . ' .awa-header-account-prompt[data-awa-auth-state="customer"] .awa-header-account-prompt__line1{'
            . 'display:none!important;height:0!important;overflow:hidden!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__line1,'
            . $homeShell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__line1{'
            . 'display:block!important;height:auto!important;max-height:none!important;overflow:visible!important;'
            . 'white-space:nowrap!important;font-size:12px!important;line-height:1.3!important;'
            . 'font-weight:600!important;color:var(--awa-text,#334155)!important;'
            . 'text-transform:none!important}'
            . $shell . ' .awa-header-account-prompt :is(.awa-header-account-prompt__line2,.awa-header-account-prompt__actions),'
            . $homeShell . ' .awa-header-account-prompt :is(.awa-header-account-prompt__line2,.awa-header-account-prompt__actions){'
            . 'display:inline-flex!important;align-items:center!important;gap:4px!important;height:auto!important;'
            . 'line-height:1.3!important;font-size:13px!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__link,'
            . $homeShell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__link{'
            . 'color:var(--awa-text,#1a1a1a)!important;font-weight:700!important;text-decoration:none!important}'
            . $shell . ' .awa-header-account-prompt__separator,' . $homeShell . ' .awa-header-account-prompt__separator{'
            . 'color:var(--awa-text-muted,CanvasText)!important;font-weight:400!important;padding-inline:2px!important}'
            . $shell . ' .awa-header-primary-nav.menu_primary[data-awa-topnav-empty="1"],'
            . $shell . ' .awa-header-primary-nav.menu_primary:has(nav.top-menu:empty){display:none!important;'
            . 'visibility:hidden!important;width:0!important;height:0!important;overflow:hidden!important;'
            . 'pointer-events:none!important;flex:0 0 0!important}'
            . $shell . ' .awa-header-right-col{gap:6px!important;max-width:none!important;min-width:0!important}'
            . $shell . ' .awa-nav-bar__inner{gap:16px!important}'
            . $shell . ' button.title-category-dropdown.our_categories{border-radius:var(--awa-radius-md,8px)!important;'
            . 'font-weight:600!important;box-shadow:none!important}'
            . $shell . ' .header-control.awa-nav-bar[data-awa-header-nav="true"] button.title-category-dropdown.our_categories{border-radius:0!important}'
            . '}';
    }

    /**
     * 2026-08-14: mata card/pílula do guest e a linha dupla do main header.
     * 11× #html-body vence o css-gate.min.js antigo em pub/static (6 IDs + pílula 1 ID).
     */
    public static function headerGuestChromeKillCardRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . '#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header';

        return '/* awa-header-guest-chrome-kill-20260814 */'
            . '@media(min-width:992px){'
            . $root . ' :is(.awa-header-contact-links.awa-header-account-prompt,.awa-header-account-prompt){'
            . 'border:0!important;border-radius:0!important;outline:0!important;'
            . 'background:transparent!important;background-image:none!important;box-shadow:none!important;'
            . 'padding:0!important;min-width:0!important;max-width:none!important;width:max-content!important;'
            . 'height:50px!important;min-height:50px!important;max-height:50px!important;overflow:hidden!important;'
            . 'flex-wrap:nowrap!important;align-items:center!important}'
            . $root . ' .awa-header-account-prompt__line2{'
            . 'display:inline-flex!important;flex-wrap:nowrap!important;white-space:nowrap!important;'
            . 'height:28px!important;min-height:28px!important;max-height:28px!important;overflow:hidden!important}'
            . $root . ' .header-control.awa-nav-bar > .container{'
            . 'box-sizing:border-box!important;width:100%!important;max-width:none!important;'
            . 'max-inline-size:none!important;margin-inline:0!important;padding-inline:0!important;'
            . 'padding-left:0!important;padding-right:0!important;display:block!important;gap:0!important}'
            . $root . ' .header-control.awa-nav-bar > .container::before,'
            . $root . ' .header-control.awa-nav-bar > .container::after{'
            . 'content:none!important;display:none!important;width:0!important;height:0!important}'
            . $root . ' .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'box-sizing:border-box!important;min-width:0!important;'
            . 'width:100%!important;max-width:none!important;max-inline-size:none!important;'
            . 'margin-inline:0!important;padding-inline:max(24px,calc((100% - 1280px)/2 + 24px))!important}'
            . $root . ' a.awa-header-account-prompt__link--register,'
            . $root . ' .awa-header-account-prompt a.awa-header-account-prompt__link--register{'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'color:var(--awa-text,CanvasText)!important;border:0!important;border-radius:0!important;'
            . 'padding:0 2px!important;min-height:0!important;height:auto!important;max-height:none!important;'
            . 'box-shadow:none!important;font-size:12px!important;font-weight:700!important;'
            . 'line-height:1.2!important;text-decoration:none!important}'
            . $root . '{border-bottom:0!important;box-shadow:none!important}'
            . $root . ' .header-wrapper-sticky{border-bottom:0!important;box-shadow:none!important}'
            . $root . ' .header.awa-main-header{border-bottom:0!important;box-shadow:none!important}'
            . $root . ' :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar){'
            . 'border-block-start:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;'
            . 'border-block-end:0!important;border-inline:0!important;box-shadow:none!important}'
            /* Promo B2B: casco + barra + texto = 44px (mata lock 32px do css-gate/vtex-clean). */
            . $root . ' :is(#header.header-container,#header.header-container>.header-content,#awa-b2b-promo-bar){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'margin:0!important;box-sizing:border-box!important;'
            . 'width:100%!important;max-width:none!important;'
            . 'border:0!important;border-bottom:0!important;overflow:hidden!important}'
            . $root . '{--awa-header-promo-h:44px!important;--awa-header-stack-h:160px!important}'
            . $root . ':has(#awa-b2b-promo-bar:not([aria-hidden="true"])){'
            . 'height:160px!important;min-height:160px!important;max-height:160px!important}'
            . 'html.awa-b2b-promo-dismissed' . substr($root, 4) . ','
            . $root . ':has(#awa-b2b-promo-bar[aria-hidden="true"]){'
            . 'height:116px!important;min-height:116px!important;max-height:116px!important;'
            . '--awa-header-promo-h:0px!important;--awa-header-stack-h:116px!important}'
            . $root . ':has(.b2b-status-panel){'
            . 'height:auto!important;min-height:0!important;max-height:none!important;'
            . '--awa-header-promo-h:0px!important;--awa-header-stack-h:auto!important}'
            . $root . ' :is(#header.header-container,#awa-b2b-promo-bar){'
            . 'background:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'background-color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . $root . ' #header.header-container>.header-content{background:transparent!important;'
            . 'width:100%!important;max-width:none!important;display:flex!important;align-items:center!important}'
            . $root . ' #awa-b2b-promo-bar:not([aria-hidden="true"]){display:flex!important;align-items:center!important;'
            . 'justify-content:center!important;width:100%!important;max-width:none!important;padding:0!important}'
            . $root . ' #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:min(100%,1280px)!important;max-width:1280px!important;margin-inline:auto!important;'
            . 'padding:0 52px 0 24px!important;box-sizing:border-box!important;line-height:1.2!important}'
            . $root . ' #awa-b2b-promo-bar .awa-b2b-promo-bar__text{'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;align-items:center!important;'
            . 'width:100%!important;max-width:none!important;margin:0!important;padding:0!important;'
            . 'box-sizing:border-box!important;line-height:1.2!important}'
            . $root . ' #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout,.awa-b2b-promo-bar__text,'
            . '.awa-b2b-promo-bar__lead,.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__lead-short,'
            . '.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__separator,span,p){'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . $root . ' #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta strong){'
            . 'background:transparent!important;background-color:transparent!important;background-image:none!important;'
            . 'box-shadow:none!important;border:0!important;border-radius:0!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0 12px!important;max-width:none!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . $root . ' #awa-b2b-promo-bar .awa-b2b-promo-close{'
            . 'position:absolute!important;inset-block:0!important;inset-inline-end:0!important;'
            . 'top:0!important;right:0!important;transform:none!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'padding:0!important;margin:0!important;border-radius:0!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'border:0!important;'
            . 'border-inline-start:1px solid color-mix(in srgb,var(--awa-text-inverse,oklch(99% .002 20)) 28%,transparent)!important;'
            . 'color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            . 'html.awa-b2b-promo-dismissed' . substr($root, 4)
            . ' :is(#header.header-container,#header.header-container>.header-content,#awa-b2b-promo-bar),'
            . $root . ' #header.header-container.awa-b2b-promo-shell--collapsed,'
            . $root . ' #header.header-container.awa-b2b-promo-shell--collapsed .header-content,'
            . $root . ' #header.header-container:has(#awa-b2b-promo-bar[aria-hidden="true"]),'
            . $root . ' #header.header-container:has(#awa-b2b-promo-bar[aria-hidden="true"]) .header-content{'
            . 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;'
            . 'border:0!important;overflow:hidden!important;line-height:0!important}'
            /* Magento 2 search = 1 chrome no form (Luma). !important: vence refine 2px+sombra e .input-text. */
            . $root . ','
            . $root . ' :is(.header-wrapper-sticky,.header.awa-main-header,'
            . '.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar){'
            . 'background:var(--awa-bg,var(--awa-white,Canvas))!important;'
            . 'background-color:var(--awa-bg,var(--awa-white,Canvas))!important}'
            . $root . ' form#search_mini_form{'
            . 'align-items:stretch!important;'
            . 'border:1px solid var(--awa-border,color-mix(in srgb,CanvasText 12%,Canvas))!important;'
            . 'box-shadow:none!important;box-sizing:border-box!important;'
            . 'background:var(--awa-bg,var(--awa-white,Canvas))!important;'
            . 'background-color:var(--awa-bg,var(--awa-white,Canvas))!important;'
            . 'overflow:visible!important}'
            . $root . ' form#search_mini_form:focus-within{'
            . 'border-color:var(--awa-primary,oklch(48% .14 20))!important;'
            . 'box-shadow:0 0 0 3px color-mix(in srgb,var(--awa-primary,oklch(48% .14 20)) 18%,transparent)!important}'
            . $root . ' form#search_mini_form :is(.field.search,.field.search .control,.actions,'
            . 'input#search,input#search.input-text,button.action.search){'
            . 'border:0!important;box-shadow:none!important;border-radius:0!important;'
            . 'height:auto!important;min-height:0!important;max-height:none!important;'
            . 'align-self:stretch!important;box-sizing:border-box!important}'
            . $root . ' form#search_mini_form :is(input#search,input#search.input-text){'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'padding-block:0!important;line-height:normal!important}'
            . $root . ' form#search_mini_form .field.search .control{'
            . 'overflow-x:hidden!important;overflow-y:visible!important}'
            . $root . ' :is(button.our_categories,button.title-category-dropdown.our_categories,'
            . '.our_categories.title-category-dropdown){'
            . 'border:0!important;border-color:transparent!important;box-shadow:none!important}'
            . $root . ' :is(.minicart-wrapper,.mini-carts){'
            . 'border-radius:0!important;background:transparent!important;box-shadow:none!important}'
            . '}';
    }

    /**
     * Conta B2B compacta — última camada do distill para remover card alto do header desktop.
     */
    public static function headerAccountVtexCleanTerminalRules(): string
    {
        $shell = 'html body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header';
        $account = $shell . ' :is(.awa-header-contact-links.awa-header-account-prompt,.awa-header-account-prompt)';
        $text = $shell . ' .awa-header-account-prompt :is(.awa-header-account-prompt__text,'
            . '.awa-header-account-prompt__guest,.awa-header-account-prompt__customer)';

        return '@media(min-width:992px){'
            . $shell . ' .awa-header-right-col{align-items:center!important;height:44px!important;max-height:44px!important;'
            . 'overflow:visible!important;position:relative!important;z-index:100270!important}'
            . $account . '{align-items:center!important;background:transparent!important;border:0!important;box-shadow:none!important;'
            . 'box-sizing:border-box!important;display:inline-flex!important;flex:0 0 auto!important;gap:6px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;max-width:none!important;'
            . 'min-width:0!important;overflow:visible!important;padding:0!important;position:relative!important;'
            . 'width:max-content!important;z-index:100260!important}'
            . $shell . ' .awa-header-account-prompt__icon{align-items:center!important;display:flex!important;flex:0 0 44px!important;'
            . 'height:44px!important;justify-content:center!important;max-height:44px!important;max-width:44px!important;'
            . 'min-height:44px!important;min-width:44px!important;padding:0!important;width:44px!important}'
            . $text . '{box-sizing:border-box!important;display:flex!important;flex:0 0 auto!important;flex-direction:column!important;'
            . 'height:44px!important;justify-content:center!important;line-height:1.1!important;max-height:44px!important;'
            . 'max-width:none!important;min-height:44px!important;min-width:0!important;overflow:visible!important;width:max-content!important;'
            . 'gap:1px!important}'
            . $account . '[data-awa-auth-state="customer"]{flex:1 1 auto!important;width:auto!important;max-width:232px!important;min-width:0!important}'
            . $account . '[data-awa-auth-state="customer"] :is(.awa-header-account-prompt__text,.awa-header-account-prompt__customer){'
            . 'flex:1 1 auto!important;width:auto!important;max-width:180px!important;min-width:0!important}'
            // FOUC 2026-07-30: nunca forçar .customer flex no estado guest (vence [hidden]).
            . $shell . ' .awa-header-account-prompt .awa-header-account-prompt__customer,'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__customer{'
            . 'display:none!important;visibility:hidden!important;height:0!important;max-height:0!important;min-height:0!important;'
            . 'opacity:0!important;overflow:hidden!important;pointer-events:none!important;position:absolute!important;width:0!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="customer"] .awa-header-account-prompt__customer{'
            . 'display:flex!important;visibility:visible!important;height:44px!important;max-height:44px!important;min-height:44px!important;'
            . 'opacity:1!important;overflow:visible!important;pointer-events:auto!important;position:static!important;width:auto!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="customer"] .awa-header-account-prompt__guest{'
            . 'display:none!important;visibility:hidden!important;height:0!important;overflow:hidden!important;pointer-events:none!important}'
            // 2026-07-30: guest line2/links/separator em altura natural (não 30px).
            // O 30px + justify center desalinhava o stack "Para ver preços / Entrar ou".
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__line1{'
            . 'display:block!important;font-size:12px!important;font-weight:600!important;height:auto!important;'
            . 'line-height:1.2!important;margin:0!important;max-height:16px!important;overflow:hidden!important;'
            . 'color:var(--awa-text,#334155)!important;text-transform:none!important;white-space:nowrap!important;'
            . 'text-align:start!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="customer"] .awa-header-account-prompt__line1{'
            . 'display:none!important;height:0!important;overflow:hidden!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] '
            . ':is(.awa-header-account-prompt__line2,.awa-header-account-prompt__actions){'
            . 'align-items:center!important;box-sizing:border-box!important;display:inline-flex!important;flex-wrap:nowrap!important;'
            . 'justify-content:flex-start!important;gap:2px!important;height:auto!important;line-height:1.2!important;'
            . 'max-height:none!important;min-height:0!important;min-width:0!important;overflow:visible!important;'
            . 'width:auto!important;padding:0!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] '
            . ':is(a,.awa-header-account-prompt__link,.awa-header-account-prompt__link--login):not(.awa-header-account-prompt__link--register){'
            . 'align-items:center!important;box-sizing:border-box!important;display:inline-flex!important;'
            . 'height:auto!important;line-height:1.2!important;min-height:0!important;max-height:none!important;'
            . 'min-width:0!important;width:auto!important;max-width:none!important;padding:0 2px!important;'
            . 'white-space:nowrap!important;font-size:12px!important;font-weight:700!important;'
            . 'justify-content:flex-start!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] '
            . '.awa-header-account-prompt__link--register{align-items:center!important;justify-content:flex-start!important;'
            . 'box-sizing:border-box!important;display:inline-flex!important;height:auto!important;line-height:1.2!important;'
            . 'min-height:0!important;max-height:none!important;min-width:0!important;width:auto!important;'
            . 'padding:0 2px!important;white-space:nowrap!important;font-size:12px!important;font-weight:700!important;'
            . 'border-radius:0!important;background:transparent!important;'
            . 'background-color:transparent!important;color:var(--awa-text,CanvasText)!important;'
            . 'border:0!important;text-decoration:none!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__separator{'
            . 'height:auto!important;line-height:1.2!important;margin:0 2px!important;padding-inline:0!important;'
            . 'display:inline-flex!important;align-items:center!important;min-width:0!important;width:auto!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__guest{'
            . 'align-items:flex-start!important;justify-content:center!important;text-align:start!important}'
            . '@media(min-width:992px){'
            . $shell . ' .awa-header-account-prompt__mobile-link{display:none!important;visibility:hidden!important;'
            . 'width:0!important;height:0!important;min-width:0!important;min-height:0!important;overflow:hidden!important;'
            . 'pointer-events:none!important;margin:0!important;padding:0!important}}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="customer"] '
            . ':is(.awa-header-account-prompt__line2,.awa-header-account-prompt__actions){'
            . 'align-items:center!important;display:flex!important;height:44px!important;max-height:44px!important;'
            . 'min-height:44px!important;line-height:44px!important}'
            . $shell . ' .awa-header-account-prompt[data-awa-auth-state="customer"] '
            . ':is(a,.awa-header-account-prompt__link){align-items:center!important;display:inline-flex!important;'
            . 'height:44px!important;min-height:44px!important;line-height:44px!important;padding:0 2px!important}'
            . $shell . ' .awa-account-dropdown{overflow:visible!important;position:relative!important;z-index:100270!important}'
            . $shell . ' .awa-account-dropdown__trigger{position:relative!important;z-index:100280!important}'
            // r66 impeccable: hairline+wide shadow = AI tell. Sombra só, blur curto.
            . $shell . ' .awa-account-dropdown__menu{background:var(--awa-bg,Canvas)!important;'
            . 'border:0!important;border-radius:8px!important;'
            . 'box-shadow:0 4px 12px color-mix(in srgb,CanvasText 12%,transparent)!important;'
            . 'box-sizing:border-box!important;display:none!important;inset-block-start:calc(100% - 1px)!important;'
            . 'inset-inline-end:0!important;margin:0!important;margin-block-start:0!important;'
            . 'min-width:12rem!important;max-width:min(18rem,calc(100vw - 24px))!important;'
            . 'overflow:hidden!important;padding:6px!important;position:absolute!important;'
            . 'top:calc(100% - 1px)!important;transform:none!important;visibility:hidden!important;'
            . 'z-index:100300!important}'
            . $shell . ' .awa-account-dropdown__menu[aria-hidden="false"]{'
            . 'display:grid!important;opacity:1!important;pointer-events:auto!important;visibility:visible!important}'
            . $shell . ' .awa-account-dropdown__item{align-items:center!important;box-sizing:border-box!important;'
            . 'display:grid!important;grid-template-columns:18px minmax(0,1fr)!important;gap:8px!important;'
            . 'min-height:40px!important;padding:8px 10px!important}'
            . $shell . ' .awa-account-dropdown__item svg{height:18px!important;width:18px!important}'
            . $shell . '{position:relative!important;z-index:100120!important}'
            . $shell . ':has(.awa-account-dropdown__trigger[aria-expanded="true"]){z-index:100260!important}'
            . $shell . ' .header-wrapper-sticky{z-index:100130!important}'
            . $shell . ':has(.awa-account-dropdown__trigger[aria-expanded="true"]) .header-wrapper-sticky{z-index:100260!important}'
            . $shell . ':has(.awa-account-dropdown__trigger[aria-expanded="true"]) '
            . ':is(.header-wrapper-sticky,.header.awa-main-header,.header-main,.header-main>.container,'
            . '.awa-main-header__inner,.awa-header-right-col){'
            . 'overflow:visible!important;position:relative!important}'
            . $shell . ' .header-control.awa-nav-bar{height:48px!important;min-height:48px!important;max-height:48px!important;overflow:visible!important;position:relative!important;z-index:100140!important}'
            . $shell . ':has(.awa-account-dropdown__trigger[aria-expanded="true"]) .header-control.awa-nav-bar{z-index:100120!important}'
            . $shell . ' .header-control.awa-nav-bar :is(.container,.awa-nav-bar__inner){align-items:center!important;height:48px!important;min-height:48px!important;max-height:48px!important;overflow:visible!important;position:relative!important;z-index:100140!important}'
            . $shell . ':has(.awa-account-dropdown__trigger[aria-expanded="true"]) .header-control.awa-nav-bar :is(.container,.awa-nav-bar__inner){z-index:100120!important}'
            . $shell . ' .awa-header-categories.menu_left_home1{height:44px!important;min-height:44px!important;max-height:44px!important;overflow:visible!important;position:relative!important}'
            . $shell . ' .awa-header-categories.menu_left_home1>nav.awa-nav-categories{height:44px!important;left:auto!important;max-height:44px!important;overflow:visible!important;position:relative!important;top:auto!important;width:100%!important;z-index:100140!important}'
            . $shell . ' .awa-header-categories.menu_left_home1>nav.awa-nav-categories>.sections.nav-sections.category-dropdown{box-sizing:border-box!important;height:44px!important;left:auto!important;max-height:44px!important;overflow:visible!important;position:relative!important;top:auto!important;width:100%!important;z-index:100140!important}'
            . $shell . ' .awa-header-categories.menu_left_home1 :is(.section-items.nav-sections.category-dropdown-items,.section-item-content.nav-sections.category-dropdown-item-content,.navigation.verticalmenu.side-verticalmenu){box-sizing:border-box!important;height:44px!important;max-height:44px!important;overflow:visible!important;position:relative!important;width:100%!important}'
            . $shell . ' .awa-header-categories.menu_left_home1 .navigation.verticalmenu.side-verticalmenu{background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;display:flex!important;max-width:206px!important}'
            . $shell . ' .awa-header-categories.menu_left_home1 .navigation.verticalmenu.side-verticalmenu :is(.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]){height:44px!important;left:auto!important;max-height:44px!important;min-height:44px!important;position:relative!important;top:auto!important;width:206px!important}'
            . $shell . ' .awa-header-categories.menu_left_home1 ul.togge-menu.list-category-dropdown{background:var(--awa-bg,Canvas)!important;border:1px solid var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas))!important;border-radius:0 0 8px 8px!important;box-shadow:0 4px 10px color-mix(in srgb,CanvasText 10%,transparent)!important;box-sizing:border-box!important;display:none!important;height:auto!important;left:0!important;max-height:min(70vh,560px)!important;overflow-x:hidden!important;overflow-y:auto!important;position:absolute!important;top:44px!important;width:min(304px,calc(100vw - 32px))!important;z-index:100150!important}'
            . $shell . ' .awa-header-categories.menu_left_home1:is(:hover,.is-open,.active,.awa-vmf-active) ul.togge-menu.list-category-dropdown,'
            . $shell . ' .awa-header-categories.menu_left_home1 ul.togge-menu.list-category-dropdown:is(.menu-open,.vmm-open,[aria-hidden="false"]){display:block!important;height:auto!important;max-height:min(70vh,560px)!important;opacity:1!important;overflow-x:hidden!important;overflow-y:auto!important;visibility:visible!important}'
            . $shell . ' .awa-header-categories.menu_left_home1:is(:hover,.is-open,.active,.awa-vmf-active) ul.togge-menu.list-category-dropdown>li{box-sizing:border-box!important;display:block!important;height:auto!important;min-height:40px!important;opacity:1!important;overflow:visible!important;visibility:visible!important}'
            . $shell . ' .awa-header-categories.menu_left_home1:is(:hover,.is-open,.active,.awa-vmf-active) ul.togge-menu.list-category-dropdown>li>a{align-items:center!important;display:flex!important;min-height:40px!important;padding:10px 12px!important}'
            . '}'
            . '@media(min-width:768px) and (max-width:991px){'
            . $account . '{height:44px!important;min-height:44px!important;max-height:44px!important;overflow:hidden!important}'
            . '}';
    }

    /**
     * Terminal lock — última camada do distill (logo, busca desktop, busca mobile fill, pad mobile).
     */
    public static function headerLayoutTerminalLockRules(): string
    {
        $shell = 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header';
        $row = $shell . ' .header-wrapper-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $searchInput = $shell . ' .awa-header-search-col form#search_mini_form input#search';

        return '@media(min-width:992px){'
            . $shell . ' :is(.awa-header-brand-cell,.col-md-2.awa-header-brand) .logo img{'
            . 'width:104px!important;max-width:104px!important;min-width:0!important;height:44px!important;max-height:44px!important;'
            . 'aspect-ratio:auto!important;object-fit:contain!important;object-position:left center!important}'
            . $searchInput . '{font-size:14px!important;line-height:1.35!important}'
            . '}'
            . '@media(max-width:767px){'
            . $row . '{padding-inline:16px!important;box-sizing:border-box!important}'
            . $shell . ' .awa-header-search-col{display:block!important;grid-template-columns:none!important;'
            . 'width:100%!important;min-width:0!important;max-width:100%!important;box-sizing:border-box!important}'
            . $shell . ' .awa-header-search-col :is(.block-search,.block-content){'
            . 'display:block!important;width:100%!important;max-width:100%!important;min-width:0!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;'
            . 'grid-template-areas:"field submit"!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important;margin:0!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form input#search{'
            . 'width:100%!important;min-width:0!important;max-width:100%!important}'
            . '}'
            . '@media(min-width:992px){'
            . $shell . ' :is(.awa-header-contact-links.awa-header-account-prompt,.awa-header-account-prompt){'
            . 'display:inline-flex!important;align-items:flex-start!important;'
            . 'height:auto!important;min-height:44px!important;max-height:none!important;'
            . 'padding-block:6px!important;padding-inline:0!important;margin:0!important;'
            . 'overflow:visible!important;box-sizing:border-box!important}'
            . $shell . ' .awa-header-account-prompt :is(.awa-header-account-prompt__text,'
            . '.awa-header-account-prompt__guest,.awa-header-account-prompt__customer){'
            . 'overflow:visible!important;line-height:1.35!important}'
            . '}';
    }

    /**
     * Impeccable commerce padding — última camada do distill (promo, minicart, footer, kbd).
     */
    public static function headerImpeccableCommercePaddingRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body .page-wrapper';
        $rootNonHome = 'html body#html-body#html-body#html-body#html-body'
            . ':not(.cms-index-index):not(.cms-home):not(.cms-homepage_ayo_home5):not(.checkout-cart-index) .page-wrapper';
        $promo = $root . ' :is('
            . '#awa-b2b-promo-bar,'
            . '#header .top-header.awa-b2b-promo-bar,'
            . '#header .awa-b2b-promo-bar[data-awa-header-utility],'
            . '.awa-site-header .top-header.awa-b2b-promo-bar,'
            . '.awa-site-header .awa-b2b-promo-bar[data-awa-header-utility]'
            . ')';
        $promoInner = $promo . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout)';
        $headerShell = $rootNonHome . ' #header.header-container:has(.awa-b2b-promo-bar),'
            . $rootNonHome . ' #header.header-container:has(#awa-b2b-promo-bar)';
        $stickyWrap = $rootNonHome . ' .awa-site-header .header-wrapper-sticky,'
            . $rootNonHome . ' .awa-site-header .header-wrapper-sticky.is-sticky';
        $stickyInner = $stickyWrap . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.awa-main-header__inner)';
        $navItem = $rootNonHome . ' .awa-site-header '
            . ':is(.header-control.header-nav,.header-control.awa-nav-bar) .ui-menu-item.navigation__item';
        $navBar = $rootNonHome . ' .awa-site-header '
            . ':is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.header-control.header-nav)';
        $navContainer = $navBar . ' > .container';
        $mainRow = $rootNonHome . ' .awa-site-header .header-wrapper-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $mainHeader = $rootNonHome . ' .awa-site-header '
            . ':is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap)';
        $mainHeaderSticky = $rootNonHome . ' .awa-site-header .header-wrapper-sticky '
            . ':is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main,.header_main)';
        $searchBlockContent = $rootNonHome . ' .awa-site-header '
            . ':is(.block.block-search>.block-content,.awa-header-search-col .block-content)';
        $cartRoot = 'html body#html-body#html-body#html-body#html-body#html-body'
            . '.checkout-cart-index.checkout-cart-index .page-wrapper';
        $cartHeaderShell = $cartRoot . ' #header.header-container:has(.awa-b2b-promo-bar),'
            . $cartRoot . ' #header.header-container:has(#awa-b2b-promo-bar)';
        $cartHeaderContent = $cartRoot . ' #header.header-container[data-awa-header-shell="true"] .header-content';
        $cartPromo = $cartRoot . ' :is(#awa-b2b-promo-bar,.awa-b2b-promo-bar)';
        $cartPromoInner = $cartPromo . ' :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout)';
        $cartPromoClose = $cartPromo . ' .awa-b2b-promo-close';
        $cartSticky = $cartRoot . ' .awa-site-header .header-wrapper-sticky,'
            . $cartRoot . ' .header-wrapper-sticky';
        $cartStickyInner = $cartRoot . ' .awa-site-header .header-wrapper-sticky '
            . ':is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.awa-main-header__inner,'
            . '.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $cartNav = $cartRoot . ' .awa-site-header '
            . ':is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.header-control.header-nav)';
        $cartNavContainer = $cartNav . ' > .container';
        $cartNavInner = $cartRoot . ' .awa-site-header .awa-nav-bar__inner';
        $cartStrongRoot = 'html body#html-body.checkout-cart-index.page-layout-1column'
            . ':not(.onepagecheckout-index-index):not(.checkout-index-index)'
            . ':not(.cms-index-index):not(.cms-home):not(.cms-homepage_ayo_home5) '
            . '.page-wrapper:has(#header.header-container[data-awa-header-shell="true"]):has(#awa-b2b-promo-bar)';
        $cartStrongSticky = $cartStrongRoot
            . ' .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky';
        $cartStrongClose = $cartStrongRoot . ' #awa-b2b-promo-bar .awa-b2b-promo-close';

        $kbd = 'html body#html-body#html-body#html-body#html-body '
            . ':is(.page-wrapper,.awa-ks-trigger-hint,#awa-shortcuts-modal) kbd';

        return $headerShell . '{padding:8px!important;box-sizing:border-box!important}'
            . $stickyWrap . '{'
            . 'padding:8px!important;padding-block:8px!important;box-sizing:border-box!important;'
            . 'box-shadow:none!important;border:0!important;border-block-end:1px solid var(--awa-border,#e5e7eb)!important;'
            . 'border-radius:0!important;overflow:visible!important}'
            . $stickyInner . '{'
            . 'padding:8px!important;padding-block:8px!important;border:0!important;box-shadow:none!important;'
            . 'border-radius:0!important;background:var(--awa-bg,#fff)!important;box-sizing:border-box!important;'
            . 'overflow:visible!important}'
            . $mainHeaderSticky . '{'
            . 'padding:8px!important;padding-block:8px!important;padding-inline:8px!important;'
            . 'box-sizing:border-box!important;overflow:visible!important;'
            . 'height:auto!important;max-height:none!important;min-height:0!important}'
            . $stickyWrap . ' :is(.header-main,.header_main,.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'height:auto!important;max-height:none!important;'
            . 'min-height:var(--awa-header-main-row-h,56px)!important;box-sizing:border-box!important}'
            . $searchBlockContent . '{'
            . 'padding:8px!important;padding-block:8px!important;box-sizing:border-box!important;'
            . 'overflow:visible!important;height:auto!important;min-height:60px!important}'
            . $root . ' .awa-site-header .awa-header-search-col :is(.block-search,form#search_mini_form){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'box-sizing:border-box!important}'
            . $promo . '{padding:8px!important;box-sizing:border-box!important}'
            . $promoInner . '{padding:8px!important;box-sizing:border-box!important}'
            . $navBar . '{padding:0!important;padding-inline:0!important;box-sizing:border-box!important;'
            . 'width:min(100%,1280px)!important;max-width:1280px!important;margin-inline:auto!important}'
            . $navContainer . '{padding:0!important;padding-inline:0!important;box-sizing:border-box!important;'
            . 'width:100%!important;max-width:100%!important;margin-inline:0!important}'
            . $navItem . '{padding:8px!important;box-sizing:border-box!important}'
            . $mainHeader . '{padding:8px!important;box-sizing:border-box!important}'
            . '@media(min-width:768px){' . $mainRow . '{padding:12px 8px!important;padding-block:12px!important;box-sizing:border-box!important}}'
            . $root . ' .block-minicart .awa-minicart-empty__hint{'
            . 'padding:8px!important;font-size:12px!important;line-height:1.45!important;box-sizing:border-box!important}'
            . $root . ' :is(.page_footer,.page-footer) #footer.footer-container .vela-content.velaFooterMenu{'
            . 'padding:8px!important;box-sizing:border-box!important}'
            . $kbd . '{'
            . 'padding-block:8px!important;padding-inline:6px!important;box-sizing:border-box!important;'
            . 'color:var(--awa-text-primary,#333)!important;'
            . 'background:var(--awa-bg-muted,#f3f4f6)!important;'
            . 'border:1px solid var(--awa-border,#e5e7eb)!important}'
            . $cartHeaderShell . '{'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'margin:0!important;box-sizing:border-box!important;overflow:visible!important}'
            . $cartHeaderContent . '{'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;box-sizing:border-box!important}'
            . $cartPromo . '{'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'margin:0!important;box-sizing:border-box!important}'
            . $cartPromoInner . '{'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0 52px 0 24px!important;box-sizing:border-box!important}'
            . $cartPromoClose . '{'
            . 'top:0!important;right:0!important;bottom:0!important;left:auto!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;margin:0!important;transform:none!important;box-sizing:border-box!important}'
            . $cartSticky . '{'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'box-sizing:border-box!important;overflow:visible!important}'
            . $cartStickyInner . '{'
            . 'padding:8px 0!important;padding-block:8px!important;padding-inline:0!important;'
            . 'box-sizing:border-box!important}'
            . $cartNav . '{padding:0!important;padding-block:0!important;padding-inline:0!important;box-sizing:border-box!important;'
            . 'width:min(100%,1280px)!important;max-width:1280px!important;margin-inline:auto!important}'
            . $cartNavContainer . '{padding:0!important;padding-inline:0!important;box-sizing:border-box!important;'
            . 'width:100%!important;max-width:100%!important;margin-inline:0!important}'
            . $cartNavInner . '{padding:0!important;box-sizing:border-box!important}'
            . $cartStrongSticky . '{'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'box-sizing:border-box!important;overflow:visible!important}'
            . $cartStrongSticky . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,'
            . '.awa-main-header__inner,.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding:8px 0!important;padding-block:8px!important;padding-inline:0!important;box-sizing:border-box!important}'
            . $cartStrongClose . '{'
            . 'top:0!important;right:0!important;bottom:0!important;left:auto!important;'
            . 'height:40px!important;min-height:40px!important;max-height:40px!important;'
            . 'padding:0!important;margin:0!important;transform:none!important;box-sizing:border-box!important}';
    }

    /**
     * Home header rail — vence commerce-padding 8px aninhados; logo/nav no eixo 16px.
     */
    public static function homeHeaderRailTerminalRules(): string
    {
        $home = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)';
        $shell = $home . ' .page-wrapper .awa-site-header';
        $zeroChain = $shell . ' :is('
            . '.header-wrapper-sticky:not(.is-sticky),'
            . '.header.awa-main-header,'
            . '.header_main.awa-main-header-inner-wrap,'
            . '.header-main,'
            . '.header_main,'
            . '.header-main>.container,'
            . '.header_main>.container'
            . ')';
        $row = $shell . ' .header-wrapper-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $navBar = $shell . ' :is(.header-control.awa-nav-bar,.header-control.header-nav)';
        $navContainer = $navBar . '>.container';
        $navInner = $shell . ' .awa-nav-bar__inner';
        $stickyHome = $shell . ' .header-wrapper-sticky.is-sticky '
            . ':is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap)';
        $stickyInner = $shell . ' .header-wrapper-sticky.is-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row],.awa-main-header__inner)';

        return $home . '{--awa-home-shell-max:1280px}'
            . $zeroChain . '{'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'margin-inline:0!important;box-sizing:border-box!important}'
            . $shell . ' :is(.header-main>.container,.header_main>.container){'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:min(100%,var(--awa-home-shell-max,1280px))!important;'
            . 'padding-inline:0!important;width:100%!important}'
            . $stickyHome . '{'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'box-sizing:border-box!important}'
            . $stickyInner . '{padding-inline:16px!important;box-sizing:border-box!important}'
            . $navBar . '{padding:0!important;padding-inline:0!important;box-sizing:border-box!important}'
            . $navContainer . '{'
            . 'padding:0!important;padding-inline:0!important;'
            . 'max-width:var(--awa-home-shell-max,1280px)!important;'
            . 'width:100%!important;margin-inline:auto!important;'
            . 'box-sizing:border-box!important}'
            . $navInner . '{'
            . 'padding-inline:var(--awa-home-shell-gutter,16px)!important;max-width:var(--awa-home-shell-max,1280px)!important;'
            . 'width:100%!important;'
            . 'margin-inline:auto!important;box-sizing:border-box!important}'
            . '@media(max-width:767px){'
            . $row . '{padding-inline:16px!important;box-sizing:border-box!important}'
            . $shell . ' .awa-header-brand-cell{justify-self:start!important;max-width:none!important}'
            . $shell . ' .awa-header-brand-cell :is(.logo,.logo a){justify-content:flex-start!important}'
            . '}'
            . '@media(min-width:992px){'
            . $row . '{max-width:100%!important;width:100%!important;margin-inline:auto!important;'
            . 'grid-template-rows:var(--awa-header-main-row-h,68px)!important;'
            . 'padding:0 16px!important;padding-inline:16px!important;box-sizing:border-box!important}}'
            . '@media(min-width:768px) and (max-width:991px){'
            . $row . '{padding-inline:16px!important;max-width:100%!important;margin-inline:auto!important}}'
            . $shell . ' .header-wrapper-sticky.is-sticky{'
            . 'box-sizing:border-box!important;'
            . 'padding-block-start:4px!important;'
            . 'padding-inline:max(16px,calc((100% - min(100%,var(--awa-home-shell-max,1280px)))/2))!important}'
            . $home . ' .page-wrapper :is(.page_footer,.page-footer) #footer.footer-container{'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:var(--awa-home-shell-max,1280px)!important;width:100%!important}'
            . '@media(min-width:1600px){'
            . $home . '{--awa-home-shell-max:1440px!important}}';
    }

    public static function homeImpeccablePolishTerminalRules(): string
    {
        $home = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)';
        $wrap = $home . ' .page-wrapper';

        return $wrap . ' .awa-site-header .header-wrapper-sticky.is-sticky{'
            . 'box-sizing:border-box!important;'
            . 'padding-block-start:4px!important;'
            . 'padding-inline:max(16px,calc((100% - min(100%,1280px))/2))!important}'
            . $wrap . ' .awa-site-header :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap){'
            . 'overflow:visible!important}'
            . $wrap . ' .awa-site-header .header-wrapper-sticky.is-sticky '
            . ':is(.awa-main-header__inner,.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-inline:16px!important}'
            . $wrap . ' .content-top-home>.ayo-home5-wrapper.ayo-home5-wrapper--template-driven{'
            . 'padding-inline:0!important}'
            . $wrap . ' .content-top-home :is(.awa-hero-b2b-cta,.awa-home-pricing-notice,'
            . '.ayo-home5-wrapper--template-driven>:is(.top-home-content,.awa-home-section,.awa-carousel-section,#awa-home-niche-shelves)){'
            . 'padding-inline:16px!important;box-sizing:border-box!important}'
            . $wrap . ' .awa-site-header .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'padding-block:8px!important;padding-inline:var(--awa-home-shell-gutter,16px)!important}'
            . $wrap . ' .content-top-home>.top-home-content--above-fold>.banner-slider.banner-slider2{'
            . 'overflow:visible!important}'
            . $wrap . ' :is(.page_footer,.page-footer) .awa-footer-newsletter{padding-inline:0!important}';
    }

    /**
     * Home shell center — content-top-home + header container no eixo 1280 (viewports >1280px).
     */
    public static function homeShellCenterTerminalRules(): string
    {
        $home = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)';
        $wrap = $home . ' .page-wrapper';

        return $wrap . ' .content-top-home{'
            . 'box-sizing:border-box!important;margin-inline:0!important;'
            . 'max-width:none!important;padding-inline:0!important;width:100%!important}'
            . $wrap . ' .content-top-home>.ayo-home5-wrapper.ayo-home5-wrapper--template-driven{'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:min(100%,var(--awa-home-shell-max,1280px))!important;width:100%!important}'
            . $wrap . ' .awa-site-header :is(.header-main>.container,.header_main>.container){'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:min(100%,var(--awa-home-shell-max,1280px))!important;'
            . 'padding-inline:0!important;width:100%!important}'
            . $wrap . ' .awa-site-header '
            . ':is(.awa-main-header__inner,.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'margin-inline:auto!important;max-width:100%!important;width:100%!important}';
    }

    /**
     * Seletor PLP com especificidade máxima (vence headerLayoutAlignRules padding-block:0).
     */
    private static function plpImpeccableLockRoot(): string
    {
        return 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view)';
    }

    /**
     * PDP-only root — regras que não devem vazar para PLP/busca.
     */
    private static function pdpImpeccableLockRoot(): string
    {
        return 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view';
    }

    /**
     * SR/hidden copy — display:none evita overflow Impeccable em caixa 1×1 com texto longo.
     */
    private static function plpImpeccableSrOnlyHideRules(string $wrap): string
    {
        $hide = $wrap . ' :is('
            . '.pages :is(span.label,.label,strong.label.pages-label,#paging-label-bottom),'
            . '.toolbar .modes-mode>span,'
            . 'button.action.search .awa-sr-only,'
            . 'a.action.showcart .awa-sr-only,'
            . '.minicart-wrapper .action.showcart .awa-sr-only,'
            . '.awa-category-carousel__count.awa-sr-only,'
            . ':is(.page_footer,.page-footer) label.visually-hidden,'
            . '.awa-whatsapp-float__label'
            . '){display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'max-width:0!important;max-height:0!important;overflow:hidden!important;'
            . 'position:absolute!important;margin:0!important;padding:0!important;border:0!important;'
            . 'clip:rect(0,0,0,0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;'
            . 'font-size:0!important;line-height:0!important;background:transparent!important;color:transparent!important}';

        $shell = $wrap . ' .awa-site-header';

        return $hide
            . $wrap . ' .awa-sr-only{background:transparent!important;color:transparent!important}'
            . $shell . ' .awa-search-helper-copy{'
            . 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;'
            . 'overflow:hidden!important;position:absolute!important;margin:0!important;padding:0!important}'
            . $shell . ' button.action.search{overflow:visible!important;position:relative!important}'
            . $shell . ' a.action.showcart{overflow:visible!important;position:relative!important}';
    }

    /**
     * Mobile catalog/checkout — busca não pode escapar do sticky (vence commerce-padding + PLP impeccable).
     */
    private static function catalogMobileHeaderClampRules(string $pageRoot): string
    {
        $wrap = $pageRoot . ' .page-wrapper';
        $sticky = $wrap . ' .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky';
        $chain = $sticky . ' :is('
            . '.header.awa-main-header,'
            . '.header_main.awa-main-header-inner-wrap,'
            . '.header-main,'
            . '.header_main,'
            . '.header-main>.container,'
            . '.header_main>.container'
            . ')';
        $child = $sticky . ' .header_main.awa-main-header-inner-wrap>:is(.header-main,.header_main)';
        $searchContent = $wrap . ' .awa-site-header '
            . ':is(.block.block-search>.block-content,.awa-header-search-col .block-content)';

        /* PIXEL-QA 2026-07-25: gutter mobile 16 no sticky (toggle/minicart alinhados ao page-main).
           padding:0 zerava o eixo horizontal; inner permanece 0 via first-paint-lock. */
        return '@media(max-width:767px){'
            . $sticky . '{'
            . 'box-sizing:border-box!important;height:96px!important;max-height:96px!important;'
            . 'min-height:96px!important;padding-block:0!important;padding-inline:16px!important;'
            . 'margin:0!important;overflow:hidden!important}'
            . $chain . '{'
            . 'box-sizing:border-box!important;height:96px!important;max-height:96px!important;'
            . 'min-height:96px!important;margin:0!important;padding:0!important;overflow:hidden!important}'
            . $child . '{'
            . 'margin:0!important;padding:0!important;height:96px!important;max-height:96px!important;'
            . 'min-height:96px!important;overflow:hidden!important}'
            . $searchContent . '{'
            . 'padding:0!important;min-height:0!important;height:auto!important;'
            . 'max-height:96px!important;overflow:hidden!important;box-sizing:border-box!important}'
            . '}';
    }

    /**
     * Padding shell header (PLP/PDP/busca) — zero vertical no sticky/main.
     * FIX 2026-07-15: padding:8px / padding-block:12px abria gap sob a promo e
     * estourava a row 68px (busca desalinhada + sticky max-height curto).
     */
    private static function plpImpeccableHeaderPaddingRules(string $wrap): string
    {
        $shell = $wrap . ' .awa-site-header';
        $sticky = $shell . ' .header-wrapper-sticky';
        $header = $sticky . ' .header.awa-main-header';
        $headerMain = $sticky . ' .header_main.awa-main-header-inner-wrap';
        $inner = $sticky . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $searchContent = $shell . ' :is(.block.block-search>.block-content,.awa-header-search-col .block-content)';
        $rowH = 'var(--awa-header-main-row-h,68px)';

        /* PIXEL-QA 2026-07-25: sticky carrega gutter mobile 16; desktop ≥768 usa shell-pad no header. */
        return $sticky . '{'
            . 'padding-block:0!important;padding-inline:16px!important;'
            . 'box-sizing:border-box!important;overflow:visible!important}'
            . '@media(min-width:768px){' . $sticky . '{padding-inline:0!important}}'
            . $header . '{'
            . 'padding-block:0!important;padding-inline:0!important;'
            . 'box-sizing:border-box!important;overflow:visible!important;'
            . 'height:' . $rowH . '!important;min-height:' . $rowH . '!important;max-height:' . $rowH . '!important}'
            . '@media(min-width:768px){' . $header . '{'
            . 'padding-inline:var(--awa-header-shell-pad,24px)!important}}'
            . $headerMain . '{'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'box-sizing:border-box!important;overflow:visible!important;'
            . 'height:' . $rowH . '!important;min-height:' . $rowH . '!important;max-height:' . $rowH . '!important}'
            . '@media(min-width:768px){'
            . $headerMain . '>:is(.header-main,.header_main){'
            . 'margin:0!important;box-sizing:border-box!important;'
            . 'height:' . $rowH . '!important;min-height:' . $rowH . '!important;max-height:' . $rowH . '!important}}'
            . $inner . '{'
            . 'height:' . $rowH . '!important;min-height:' . $rowH . '!important;max-height:' . $rowH . '!important;'
            . 'padding-block:0!important;box-sizing:border-box!important}'
            . $searchContent . '{'
            . 'padding:0!important;padding-block:0!important;box-sizing:border-box!important;'
            . 'overflow:visible!important;height:44px!important;min-height:44px!important;max-height:44px!important}'
            . $shell . ' .awa-header-search-col :is(.block-search,form#search_mini_form){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'box-sizing:border-box!important}'
            /* H8F: wrapper da busca sem borda (só o form); nav sem border-top */
            . $shell . ' .awa-header-search-col :is(.block.block-search,.block-search.awa-professional-search){'
            . 'border:0!important;box-shadow:none!important;background:transparent!important;'
            . 'padding:0!important;margin:0!important;display:flex!important;align-items:center!important}'
            . $shell . ' .awa-header-search-col .block-content{'
            . 'display:flex!important;align-items:center!important;margin:0!important;border:0!important;'
            . 'padding:0!important;height:44px!important;min-height:44px!important;max-height:44px!important}'
            . $shell . ' :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar){'
            . 'border-top:0!important;border-block-start:0!important}'
            /* H8G: .container da nav tinha border 1px → navInner a top=69 */
            . $shell . ' :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar) > .container{'
            . 'border:0!important;border-top:0!important;border-bottom:0!important;'
            . 'border-block-start:0!important;border-block-end:0!important;box-shadow:none!important}'
            . $shell . ' .awa-nav-bar__inner{margin-top:0!important;padding-top:0!important;border-top:0!important}'
            . '@media(min-width:992px){'
            . $header . '{display:flex!important;align-items:center!important;'
            . 'height:' . $rowH . '!important;min-height:' . $rowH . '!important;max-height:' . $rowH . '!important;'
            . 'overflow:visible!important;padding-block:0!important;margin-block:0!important}'
            . $headerMain . '{height:' . $rowH . '!important;max-height:' . $rowH . '!important;'
            . 'overflow:visible!important;padding:0!important}'
            . $inner . '{height:' . $rowH . '!important;max-height:' . $rowH . '!important;'
            . 'min-height:' . $rowH . '!important;padding-block:0!important}'
            . '}';
    }

    /**
     * PLP toolbar — vence --m-text-sm (0.8125rem × html 10px = 8.125px).
     */
    private static function plpImpeccableToolbarTypeRules(string $wrap): string
    {
        return $wrap . ' .toolbar.toolbar-products{'
            . 'font-size:13px!important;line-height:1.35!important}'
            . $wrap . ' .toolbar.toolbar-products :is(.toolbar-sorter,.sorter,.field.limiter,.modes,.toolbar-amount){'
            . 'font-size:13px!important;line-height:1.35!important}'
            . $wrap . ' .toolbar.toolbar-products :is(.modes .modes-mode,a.modes-mode,strong.modes-mode,'
            . '.mode-grid,.mode-list){align-items:center!important;box-sizing:border-box!important;'
            . 'display:inline-flex!important;height:44px!important;justify-content:center!important;'
            . 'line-height:1!important;margin:0!important;max-height:44px!important;max-width:44px!important;'
            . 'min-height:44px!important;min-width:44px!important;padding:0!important;width:44px!important}';
    }

    /**
     * PLP head critical — 1º paint (Impeccable scan antes do align-grid/distill async).
     */
    public static function plpImpeccableHeadCriticalCss(): string
    {
        $root = self::plpImpeccableLockRoot();
        $wrap = $root . ' .page-wrapper';
        $shell = $wrap . ' .awa-site-header';

        return $root . ','
            . $wrap . '{font-family:"Source Sans 3",system-ui,-apple-system,sans-serif!important}'
            . $wrap . '{'
            . '--awa-plp-card-radius:8px;--awa-plp-chrome-radius:8px;--awa-modern-card-radius:8px;'
            . 'overflow-x:clip!important;overflow-y:visible!important;'
            . 'font-family:"Source Sans 3",system-ui,-apple-system,sans-serif!important}'
            . $wrap . ' :is(.page_footer,.page-footer,.page-footer *){'
            . 'font-family:"Source Sans 3",system-ui,-apple-system,sans-serif!important}'
            . self::plpImpeccableHeaderPaddingRules($wrap)
            . self::catalogMobileHeaderClampRules(self::plpImpeccableLockRoot())
            . $wrap . ' .wrapper.grid.products-grid .item-product{border-radius:8px!important}'
            . $wrap . ' :is('
            . '.shop-tab-select .toolbar.toolbar-products:not(.toolbar-products--bottom-slim),'
            . '#layered-ajax-filter-block.block.filter,.awa-plp-b2b-gate-banner){border-radius:8px!important}'
            . $shell . ' :is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.header-control.header-nav){'
            . 'padding:8px!important;box-sizing:border-box!important}'
            . $shell . ' :is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar)>.container{'
            . 'padding:8px!important;box-sizing:border-box!important}'
            /* Promo NÃO entra no bag padding:8px — shell DS = 32px / pad-block 0 (2026-07-15). */
            /* FIX 2026-07-19 H6: .page-main>.columns NÃO entra no bag — somava 8px ao
               page-main (16px) → doubleGutterPx=24. Chrome 8px fica em filter/toolbar/etc. */
            . $wrap . ' :is('
            . '.toolbar-sorter.sorter,.field.limiter>.control,'
            . '#layered-ajax-filter-block,.block.filter,.category-view-move){'
            . 'padding:8px!important;box-sizing:border-box!important}'
            . $wrap . ' :is(.page-main>.columns,.columns.layout,.columns.layout.layout-2-col){'
            . 'padding:0!important;box-sizing:border-box!important}'
            . $wrap . ' :is(#awa-b2b-promo-bar,.awa-b2b-promo-bar,.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout){'
            . 'padding:0!important;padding-block:0!important;box-sizing:border-box!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important}'
            . $wrap . ' .category-view-move{overflow:visible!important;height:auto!important;'
            . 'max-height:none!important;margin-block-end:12px!important}'
            . $wrap . ' .awa-category-hero--has-image .awa-category-hero__content{'
            . 'background:#fff!important;backdrop-filter:none!important;-webkit-backdrop-filter:none!important}'
            . $wrap . ' h1.awa-category-hero__title,'
            . $wrap . ' .awa-category-hero--has-image h1.awa-category-hero__title{'
            . 'color:#333333!important;text-shadow:none!important}'
            . $wrap . ' .awa-category-hero--has-image :is(.awa-category-hero__title,.awa-category-hero__count){'
            . 'color:#333!important;text-shadow:none!important}'
            . $wrap . ' .awa-category-hero--has-image .awa-category-hero__count{color:#666!important}'
            . self::plpImpeccableSrOnlyHideRules($wrap)
            . self::plpSkuDensityRules($wrap)
            . $wrap . ' :is('
            . '#search_mini_form,#search_mini_form .field.search,'
            . '.mst-searchautocomplete__autocomplete,#awa-b2b-promo-bar,'
            . '.awa-b2b-promo-bar__inner,.block-minicart,.block-minicart.ui-dialog-content){'
            . 'overflow:visible!important}'
            . '@media(min-width:992px){' . $wrap . ' .shop-tab-select '
            . '.toolbar.toolbar-products:not(.toolbar-products--bottom-slim){'
            . 'min-height:52px!important;max-height:56px!important;padding:8px!important;'
            . 'box-sizing:border-box!important;align-items:center!important}}'
            /* CTA promo: cabe no shell 32px — não forçar touch 44px (estoura a barra). */
            . $wrap . ' .awa-site-header :is(.awa-b2b-promo-bar,#awa-b2b-promo-bar) :is(a.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta){'
            . 'display:inline!important;align-items:unset!important;justify-content:unset!important;'
            . 'width:auto!important;min-width:0!important;height:auto!important;min-height:0!important;'
            . 'max-height:none!important;padding:0!important;margin-block:0!important;'
            . 'box-sizing:border-box!important;line-height:1.25!important;white-space:nowrap!important}'
            . self::plpImpeccableToolbarTypeRules($wrap)
            /* H-pager-rwd (2026-08-02): CDP tablet/desktop — border-block 1px
               (themes + defer :last-of-type), amount abaixo dos botões, chrome
               inconsistente vs mobile. Padroniza todos os breakpoints. */
            . $wrap . ' .toolbar.toolbar-products--bottom-slim{'
            . 'border:0!important;border-block:0!important;border-block-start:0!important;'
            . 'border-block-end:0!important;border-inline:0!important;'
            . 'background:transparent!important;background-color:transparent!important;'
            . 'box-shadow:none!important;border-radius:0!important;'
            . 'display:flex!important;flex-direction:column!important;align-items:center!important;'
            . 'justify-content:center!important;gap:8px!important;'
            . 'width:100%!important;max-width:100%!important;box-sizing:border-box!important;'
            . 'min-height:0!important;height:auto!important;'
            . 'margin-block:12px 0!important;padding:0!important;padding-block:0!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim > .center{'
            . 'display:flex!important;flex-direction:column!important;align-items:center!important;'
            . 'justify-content:center!important;gap:8px!important;width:100%!important;'
            . 'max-width:100%!important;box-sizing:border-box!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .toolbar-amount{'
            . 'order:-1!important;margin:0!important;padding:0!important;border:0!important;'
            . 'background:transparent!important;text-align:center!important;width:100%!important;'
            . 'font-size:13px!important;line-height:1.35!important;'
            . 'color:var(--awa-text-muted,var(--awa-text-secondary,#64748b))!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages{'
            . 'margin:0!important;padding:0!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items{'
            . 'display:flex!important;flex-wrap:nowrap!important;justify-content:center!important;'
            . 'align-items:center!important;gap:8px!important;width:100%!important;'
            . 'max-width:100%!important;margin:0!important;padding:0!important;'
            . 'border:0!important;border-radius:0!important;background:transparent!important;'
            . 'box-shadow:none!important;box-sizing:border-box!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items > li.item{'
            . 'margin:0!important;padding:0!important;border:0!important;background:transparent!important;'
            . 'box-shadow:none!important;flex:0 0 auto!important;width:auto!important;min-width:0!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items > li.item'
            . ' :is(a,strong,a.page,strong.page,a.action){margin:0!important;margin-inline:0!important;'
            . 'box-sizing:border-box!important;border-radius:8px!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'text-decoration:none!important}'
            /* Tablet/desktop: alvo 40px (conforto + WCAG); mobile: 36px (cabe em 375). */
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items'
            . ' > li.item:not(.pages-item-next):not(.pages-item-previous) :is(a,strong){'
            . 'width:40px!important;min-width:40px!important;max-width:40px!important;'
            . 'height:40px!important;min-height:40px!important;padding:0!important;'
            . 'line-height:38px!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items'
            . ' > li.pages-item-next a.action.next,'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items'
            . ' > li.pages-item-previous a.action.previous{'
            . 'width:auto!important;min-width:0!important;max-width:none!important;'
            . 'height:40px!important;min-height:40px!important;padding:0 14px!important;'
            . 'white-space:nowrap!important;line-height:38px!important}'
            . '@media (max-width:767px){'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim{'
            . 'margin-block:8px 0!important;gap:8px!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim > .center{gap:8px!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items{gap:4px!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items'
            . ' > li.item:not(.pages-item-next):not(.pages-item-previous) :is(a,strong){'
            . 'width:36px!important;min-width:36px!important;max-width:36px!important;'
            . 'height:36px!important;min-height:36px!important;line-height:34px!important}'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items'
            . ' > li.pages-item-next a.action.next,'
            . $wrap . ' .toolbar.toolbar-products--bottom-slim .pages .items.pages-items'
            . ' > li.pages-item-previous a.action.previous{'
            . 'height:36px!important;min-height:36px!important;padding:0 10px!important;'
            . 'line-height:34px!important}}'
            /* H-badge-clip2 (2026-08-02): themes .hot-onsale{max-width:50px} truncava
               "LANÇAMENTO DISTRIBUIDORA". Inline terminal vence CSS estático cached/immutable. */
            . 'html body#html-body .page-wrapper .item-product .hot-onsale{'
            . 'max-width:calc(100% - 16px)!important;width:auto!important;z-index:5!important;'
            . 'overflow:visible!important}'
            . 'html body#html-body .page-wrapper .item-product .hot-onsale '
            . ':is(.onsale,.onsale.new-lable,.new-lable){'
            . 'max-width:100%!important;width:auto!important;white-space:normal!important;'
            . 'overflow:visible!important;text-overflow:unset!important;line-height:1.25!important}'
            /* H-pdp-thumbs-row2: nowrap + hide dots mobile + scroll-x */
            . 'html body#html-body.catalog-product-view .page-wrapper .product.media'
            . ' .fotorama__nav--dots .fotorama__nav__shaft{'
            . 'display:flex!important;flex-wrap:nowrap!important;justify-content:flex-start!important;'
            . 'max-inline-size:100%!important;max-width:100%!important;'
            . 'overflow-x:auto!important;overflow-y:hidden!important}'
            . '@media (max-width:767px){'
            . 'html body#html-body.catalog-product-view .page-wrapper .product.media'
            . ' .fotorama__nav--dots .fotorama__nav__frame--dot{'
            . 'display:none!important;width:0!important;height:0!important;margin:0!important;'
            . 'padding:0!important;overflow:hidden!important}}'
            . 'html body#html-body.catalog-product-view .page-wrapper .product.media'
            . ' .fotorama__nav--dots{max-height:88px!important;overflow:hidden!important}'
            /* H-pdp-gallery-lcp: LCP visivel durante loading — 5x #html-body
               vence awa-align-grid-terminal :has(.fotorama-item){width:0;display:none}. */
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product.media .gallery-placeholder._block-content-loading,'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-main .product.media .gallery-placeholder._block-content-loading{'
            . 'min-height:clamp(300px,82vw,460px)!important;height:auto!important;'
            . 'overflow:hidden!important;display:block!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product.media .gallery-placeholder._block-content-loading>'
            . ':is(img.gallery-placeholder__image,.gallery-placeholder__image),'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-main .product.media .gallery-placeholder._block-content-loading>'
            . ':is(img.gallery-placeholder__image,.gallery-placeholder__image){'
            . 'display:block!important;width:100%!important;height:auto!important;'
            . 'max-width:100%!important;max-height:none!important;min-height:0!important;'
            . 'opacity:1!important;visibility:visible!important;overflow:visible!important;'
            . 'object-fit:contain!important}'
            /* H-pdp-gallery-race: LCP fica ate existir .fotorama__img (nao so .fotorama-item). */
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .gallery-placeholder:not(:has(.fotorama__img))>'
            . ':is(img.gallery-placeholder__image,.gallery-placeholder__image),'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-main .gallery-placeholder:not(:has(.fotorama__img))>'
            . ':is(img.gallery-placeholder__image,.gallery-placeholder__image){'
            . 'display:block!important;width:100%!important;height:auto!important;'
            . 'max-width:100%!important;opacity:1!important;visibility:visible!important;'
            . 'object-fit:contain!important}'
            /* H-pdp-tabs-shell6: moldura tripla detailed+items+content — outer zero */
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product.info.detailed,'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product.data.items{'
            . 'border:0!important;box-shadow:none!important;background:transparent!important;'
            . 'padding:0!important;border-radius:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product.data.items>.item.content{'
            . 'border:1px solid var(--awa-border)!important;box-sizing:border-box!important}'
            /* H-plp-pager-moldura-shell6: toolbar/ul outer chrome */
            . 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
            . ' .toolbar.toolbar-products,'
            . 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
            . ' .toolbar.toolbar-products--bottom-slim{'
            . 'border:0!important;border-block:0!important;box-shadow:none!important;'
            . 'background:transparent!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
            . ' .pages .items.pages-items,'
            . 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper'
            . ' ul.pages-items{'
            . 'border:0!important;box-shadow:none!important;background:transparent!important;'
            . 'border-radius:0!important;padding:0!important}'
            /* H-pdp-shell7: attr nest + spacing + thumb inner */
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product.data.items>.item.content'
            . ' .additional-attributes-wrapper.table-wrapper{'
            . 'border:0!important;box-shadow:none!important;background:transparent!important;'
            . 'padding:0!important;margin:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .awa-pdp-faq{border:0!important;box-shadow:none!important;'
            . 'background:transparent!important;padding-inline:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .main-detail{padding-inline:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product-info-main{padding:12px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .awa-pdp-related{padding-top:12px!important;padding-block-start:12px!important;'
            . 'border:0!important;box-shadow:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .rx-pdp-crosssell{border:0!important;box-shadow:none!important;'
            . 'padding-block-start:12px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body.catalog-product-view'
            . ' .page-wrapper .product.media .fotorama__nav__frame--thumb .fotorama__thumb{'
            . 'border:0!important;box-shadow:none!important;outline:none!important}'
            . self::plpImpeccablePass2Rules();
    }

    /**
     * H9A — SKU badge compacto (padding só no wrapper; filhos com 8px empilhavam ~52px).
     */
    private static function plpSkuDensityRules(string $wrap): string
    {
        return $wrap . ' .wrapper.grid.products-grid .item-product .awa-b2b-sku{'
            . 'display:inline-flex!important;align-items:center!important;gap:4px!important;'
            . 'padding:2px 8px!important;font-size:11px!important;line-height:1.2!important;'
            . 'box-sizing:border-box!important;min-height:0!important;height:auto!important;'
            . 'max-height:none!important}'
            . $wrap . ' .wrapper.grid.products-grid .item-product '
            . ':is(.awa-b2b-sku__label,.awa-b2b-sku__value){'
            . 'padding:0!important;margin:0!important;font-size:inherit!important;'
            . 'line-height:inherit!important;min-height:0!important;height:auto!important;'
            . 'display:inline!important;gap:0!important;box-sizing:border-box!important}';
    }

    /**
     * PDP head critical — attr-product, gallery, carousel, colunas (1º paint antes do async pai).
     */
    public static function pdpImpeccableHeadCriticalCss(): string
    {
        $root = self::pdpImpeccableLockRoot();
        $wrap = $root . ' .page-wrapper';
        $shell = $wrap . ' .awa-site-header';

        return $root . ','
            . $wrap . '{font-family:"Source Sans 3",system-ui,-apple-system,sans-serif!important}'
            . self::plpImpeccableHeaderPaddingRules($wrap)
            . self::catalogMobileHeaderClampRules(self::pdpImpeccableLockRoot())
            . self::plpImpeccableSrOnlyHideRules($wrap)
            . $shell . ' .header-content{padding-block:8px!important;box-sizing:border-box!important}'
            . $wrap . ' .product-info-main .attr-product{'
            . 'border:0!important;box-shadow:none!important;background:transparent!important;'
            . 'padding:0!important;overflow:visible!important;box-sizing:border-box!important}'
            . $wrap . ' :is(.product.attribute,.product-info-stock-sku .product.attribute){'
            . 'padding-block:8px!important;box-sizing:border-box!important}'
            . $wrap . ' .product-info-price .price-label{padding:4px 8px!important;box-sizing:border-box!important}'
            /* FIX 2026-07-19 H-C/H6: padding:8px em columns+column.main somava ao page-main */
            . $wrap . ' :is(.columns,.column.main){padding:0!important;box-sizing:border-box!important}'
            . $wrap . ' :is(.product-view,.column.main){overflow:visible!important}'
            . $wrap . ' .product.media{'
            . 'box-sizing:border-box!important;isolation:isolate!important;max-width:100%!important;'
            . 'min-width:0!important;overflow:hidden!important;position:relative!important;z-index:1!important}'
            . $wrap . ' .product-info-main{'
            . 'isolation:isolate!important;position:relative!important;z-index:2!important;min-width:0!important}'
            . $wrap . ' .product-info-main .page-title-wrapper{'
            . 'border-block-end:1px solid var(--awa-border)!important;'
            . 'margin:0!important;padding-block:8px 12px!important;gap:0!important}'
            /* SSOT PDP title 2026-07-30: títulos longos B2B ~18–22px (não 28px). */
            . $wrap . ' .product-info-main .page-title-wrapper :is(h1.page-title,.page-title,.page-title .base){'
            . 'font-family:var(--awa-font-heading,"Rubik",system-ui,sans-serif)!important;'
            . 'font-size:clamp(1.125rem,1.05rem + .45vw,1.375rem)!important;font-weight:700!important;'
            . 'line-height:1.3!important;letter-spacing:-.01em!important;color:var(--awa-text)!important;'
            . 'text-wrap:balance!important;margin:0!important;margin-block:0!important;padding:0!important;'
            . 'hyphens:none!important;-webkit-hyphens:none!important;border-block-end:0!important}'
            . '@media(min-width:992px){'
            . $wrap . ' .main-detail>.row>.col-md-6:first-child{'
            . 'min-width:0!important;overflow:hidden!important}}'
            . '@media(max-width:991px){'
            /* P2-PDP-STACK: overflow:hidden + min-height:0 no flex item colapsa a coluna
             * (~1px) e clipa a galeria. Clip fica em .product.media. */
            . $wrap . ' .main-detail>.row{height:auto!important;min-height:0!important}'
            . $wrap . ' .main-detail>.row>:is(.col-md-6,.col-sm-6){'
            . 'flex:none!important;flex-basis:auto!important;width:100%!important;max-width:100%!important;'
            . 'height:auto!important;min-height:auto!important;overflow:visible!important;float:none!important}'
            . $wrap . ' .main-detail>.row>.col-md-6:first-child{overflow:visible!important}}'
            . $wrap . ' .gallery-placeholder{'
            . 'padding:8px!important;box-shadow:none!important;overflow:hidden!important;'
            . 'border:1px solid var(--awa-border,#e5e7eb)!important;'
            . 'background:var(--awa-surface,#fff)!important;box-sizing:border-box!important}'
            . $wrap . ' .product.media :is(.gallery-placeholder,.fotorama-item,.fotorama,.fotorama__wrap,'
            . '.fotorama__stage,.fotorama__stage__shaft,.fotorama__stage__frame){overflow:hidden!important}'
            . $wrap . ' :is(.awa-carousel__viewport,[class*="awa-carousel__viewport"]){'
            . 'padding-inline:8px!important;box-sizing:border-box!important}'
            . $wrap . ' .item-product.awa-carousel-card-slot{'
            . 'padding-block:8px!important;overflow:hidden!important;box-sizing:border-box!important}'
            . $wrap . ' .item-product.awa-carousel-card-slot .product-thumb{overflow:clip!important;padding:8px!important}'
            . $wrap . ' :is(#menu\\.vertical\\.extra,.awa-vertical-extra){padding-block-start:8px!important}'
            . '@media(min-width:992px){' . $shell . ' .header-content:has(.awa-b2b-promo-bar){'
            . 'align-items:stretch!important;width:100%!important;max-width:min(100%,1280px)!important}'
            . $shell . ' :is(.top-header,.awa-b2b-promo-bar){width:100%!important;max-width:100%!important}'
            . $shell . ' .awa-b2b-promo-bar__inner{width:100%!important;max-width:min(100%,1280px)!important}}'
            . $shell . ' .awa-header-search-col :is(.block-content,.block-search){'
            . 'padding:0!important;margin:0!important;box-sizing:border-box!important}'
            . $shell . ' :is(#search_mini_form,.mst-searchautocomplete__autocomplete,button.action.search,'
            . '#awa-b2b-promo-bar,.awa-b2b-promo-bar__inner){overflow:visible!important}'
            . self::pdpImpeccablePass2Rules();
    }

    /**
     * PDP Impeccable pass 2 — overflow allowlist, ruído 10px inline, Inter→Source Sans 3, fotorama anim.
     */
    public static function pdpImpeccablePass2Rules(): string
    {
        $pdp = self::pdpImpeccableLockRoot();
        $wrap = $pdp . ' .page-wrapper';

        $structural = ':is('
            . '#awa-pdp-terminal-lock-inline,#awa-plp-terminal-lock-inline,#awa-critical-inline-site,'
            . '#awa-cls-nav-fix,#awa-bugfix-terminal-inline,#awa-cookie-consent-critical,head>style,head>noscript,title'
            . '){font-size:0!important;line-height:0!important}';

        $overflow = $pdp . '{overflow-x:visible!important;overflow-y:visible!important}'
            . $wrap . '{overflow-x:visible!important;overflow-y:visible!important}'
            . $wrap . ' :is('
            . '#awa-b2b-promo-bar,.awa-b2b-promo-bar,.awa-b2b-promo-bar__inner,'
            . '#search_mini_form,.mst-searchautocomplete__autocomplete,'
            . 'ul.togge-menu.list-category-dropdown,[id^="awa-vertical-menu-"],'
            . '[id^="submenu-menu-"],.navigation__submenu,.navigation__inner-list,'
            . '.columns,.column.main,.product-view,'
            . '.attr-product,.item-product.awa-carousel-card-slot,.content-item-product.awa-product-card,'
            . '.product-thumb,footer.page-footer,.page_footer,#footer,.footer-container,'
            . 'a.awa-whatsapp-float,.awa-header-categories.menu_left_home1'
            . '){overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}';

        $galleryClip = $wrap . ' .product.media,'
            . $wrap . ' .product.media :is(.gallery-placeholder,.fotorama-item,.fotorama,.fotorama__wrap,'
            . '.fotorama__stage,.fotorama__stage__shaft,.fotorama__stage__frame,'
            . '.fotorama__nav-wrap,.fotorama__nav,.fotorama__nav__shaft,.fotorama__nav__frame){'
            . 'overflow:hidden!important}'
            . $wrap . ' .gallery-placeholder{overflow:hidden!important}'
            . '@media(min-width:992px){'
            . $wrap . ' .main-detail>.row>.col-md-6:first-child{overflow:hidden!important}}'
            . '@media(max-width:991px){'
            . $wrap . ' .main-detail>.row>.col-md-6:first-child{'
            . 'overflow:visible!important;height:auto!important;min-height:auto!important}}';

        $fotoramaAnim = $wrap . ' :is(.fotorama__stage__shaft,.fotorama__nav__shaft,.fotorama__thumb-border){'
            . 'transition-property:opacity,transform!important}';

        $type = $wrap . ' .navigation.verticalmenu.side-verticalmenu{'
            . '--vm-font:"Source Sans 3",system-ui,-apple-system,sans-serif!important}'
            . $wrap . ' :is('
            . '.navigation.verticalmenu,.our_categories.title-category-dropdown,'
            . '.awa-vmenu-trigger-text,.navigation__link,.navigation__label,'
            . '.navigation__inner-link,ul.togge-menu.list-category-dropdown,'
            . '[id^="submenu-menu-"] *'
            . '){font-family:"Source Sans 3",system-ui,-apple-system,sans-serif!important}';

        return $structural . $overflow . $galleryClip . $fotoramaAnim . $type;
    }

    /**
     * Checkout-only root — carrinho, OPC e sucesso (sr-only, overflow, touch targets).
     */
    private static function checkoutImpeccableLockRoot(): string
    {
        return 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.checkout-cart-index,.checkout-index-index,.rokanthemes-onepagecheckout,'
            . '.onepagecheckout-index-index,.checkout-onepage-success)';
    }

    /**
     * Home-only root — regras Impeccable head critical (sr-only, overflow chrome).
     */
    private static function homeImpeccableLockRoot(): string
    {
        return 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)';
    }

    /**
     * Checkout head critical — sr-only, overflow allowlist, chips/OPC 44px (1º paint).
     */
    public static function checkoutImpeccableHeadCriticalCss(): string
    {
        $root = self::checkoutImpeccableLockRoot();
        $wrap = $root . ' .page-wrapper';
        $shell = $wrap . ' .awa-site-header';
        $opc = ':is(body.checkout-index-index,body.rokanthemes-onepagecheckout,.onepagecheckout-index-index)';

        return self::plpImpeccableSrOnlyHideRules($wrap)
            . self::catalogMobileHeaderClampRules(self::checkoutImpeccableLockRoot())
            . $root . ','
            . $wrap . '{overflow-x:visible!important;overflow-y:visible!important}'
            . '@media(min-width:992px){'
            . $wrap . ' :is(#cart-summary,.cart-summary,.cart-summary._sticky){'
            . 'max-height:none!important;height:auto!important;'
            . 'overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}}'
            . $wrap . ' :is('
            . '#search_mini_form,.mst-searchautocomplete__autocomplete,'
            . '#awa-b2b-promo-bar,.awa-b2b-promo-bar,.awa-b2b-promo-bar__inner,'
            . '.cart-container,.cart-summary,.checkout-container,.opc-wrapper,#checkout,'
            . '.block.block-minicart,.block-minicart.ui-dialog-content,.minicart-wrapper .mage-dropdown-dialog,'
            . 'ul.togge-menu.list-category-dropdown,.navigation__submenu,.navigation__inner-list,'
            . 'footer.page-footer,.page_footer,#footer,.footer-container,a.awa-whatsapp-float'
            . '){overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
            . $wrap . ' section.awa-footer-trust-bar{padding-block:14px 16px!important}'
            . $wrap . ' :is(.page_footer,.page-footer) .footer-bottom .footer-bottom-inner{'
            . 'padding-inline:max(16px,env(safe-area-inset-left),env(safe-area-inset-right))!important}'
            . $shell . ' :is(#search_mini_form,button.action.search,a.action.showcart){'
            . 'overflow:visible!important;position:relative!important}'
            . $wrap . ' :is(.awa-cart-empty__category-chip,.awa-order-success__category-chip){'
            . 'min-height:44px!important;padding-block:10px!important;box-sizing:border-box!important;'
            . 'display:inline-flex!important;align-items:center!important}'
            . $opc . ' .opc-progress-bar-item>span::before,'
            . $opc . ' .opc-progress-bar-item>span:before{'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'line-height:40px!important;box-sizing:border-box!important}'
            . $opc . ' .opc-wrapper .step-title{font-weight:700!important}'
            . $shell . ' .awa-header-account-prompt :is(a,.awa-header-account-prompt__link){'
            . 'min-height:44px!important;display:inline-flex!important;align-items:center!important;'
            . 'padding-block:8px!important;box-sizing:border-box!important}'
            . $shell . ' .awa-header-account-prompt__icon{'
            . 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            . 'flex:0 0 44px!important}'
            . '/* AWA 2026-08-04: H1 de página oculto no empty simples (título no hero). */'
            . $wrap . ':has(.awa-cart-empty--simple) .page-title-wrapper{'
            . 'position:absolute!important;width:1px!important;height:1px!important;padding:0!important;'
            . 'margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;'
            . 'clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important}'
            . $wrap . ':has(.awa-cart-empty--simple) .page-title-wrapper :is(h1,.page-title,.page-title .base){'
            . 'position:static!important;display:inline!important;width:auto!important;height:auto!important;'
            . 'padding:0!important;margin:0!important;overflow:hidden!important;'
            . 'font-size:inherit!important;line-height:inherit!important}'
            . self::checkoutSearchImpeccableDistillRules()
            . self::checkoutCartMobileMinicartTerminalRules()
            . self::checkoutOpenModalTerminalRules()
            . self::checkoutAgreementsModalTerminalRules();
    }

    /**
     * Cart mobile minicart — opened dropdown must escape the 44px header shell.
     */
    private static function checkoutCartMobileMinicartTerminalRules(): string
    {
        $root = 'html body#html-body.checkout-cart-index .page-wrapper';
        $open = $root . ' .minicart-wrapper:is(.is-open,.active,.show)';
        $panel = $open . ' .block-minicart:is(._active,.active,.is-open)';

        return '@media(max-width:767px){'
            . $root . ' .awa-header-minicart,' . $open . '{overflow:visible!important}'
            . $open . ' .mage-dropdown-dialog{position:fixed!important;inset:0!important;'
            . 'display:block!important;width:100vw!important;height:100dvh!important;'
            . 'overflow:visible!important;z-index:10020!important;transform:none!important}'
            . $panel . '{position:fixed!important;inset:0!important;display:flex!important;'
            . 'flex-direction:column!important;box-sizing:border-box!important;width:100vw!important;'
            . 'max-width:none!important;min-width:0!important;height:100dvh!important;'
            . 'max-height:100dvh!important;margin:0!important;padding:16px!important;'
            . 'overflow:auto!important;border:0!important;border-radius:0!important;'
            . 'background:var(--awa-bg-surface,Canvas)!important;box-shadow:none!important;'
            . 'z-index:10021!important;transform:none!important}'
            . $open . ' #minicart-content-wrapper{display:flex!important;flex-direction:column!important;'
            . 'width:100%!important;min-height:0!important}'
            . '}';
    }

    /**
     * Checkout shipping address modal — keeps the open modal outside document flow.
     */
    private static function checkoutOpenModalTerminalRules(): string
    {
        $root = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.checkout-index-index,.rokanthemes-onepagecheckout,.onepagecheckout-index-index)';
        $modal = $root . ' .modal-popup.new-shipping-address-modal.modal-slide._inner-scroll._show';
        $wrap = $modal . ' .modal-inner-wrap';
        $close = $modal . ' .modal-header .action-close';
        $overlay = $root . ' .modals-overlay';

        return '/* AWA checkout modal terminal v16 — exact shipping address fixed in viewport */'
            . $modal . '{position:fixed!important;inset:0!important;z-index:10010!important;'
            . 'display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:100%!important;height:100vh!important;height:100dvh!important;min-height:100vh!important;'
            . 'padding:max(16px,env(safe-area-inset-top,0px)) 16px max(16px,env(safe-area-inset-bottom,0px))!important;'
            . 'box-sizing:border-box!important;overflow:auto!important;transform:none!important;will-change:auto!important}'
            . $wrap . '{position:relative!important;inset:auto!important;top:auto!important;right:auto!important;'
            . 'bottom:auto!important;left:auto!important;width:min(720px,calc(100vw - 32px))!important;'
            . 'max-width:min(720px,calc(100vw - 32px))!important;max-height:calc(100vh - 32px)!important;'
            . 'max-height:calc(100dvh - 32px)!important;margin:auto!important;overflow:auto!important;'
            . 'transform:none!important;box-sizing:border-box!important}'
            . $close . '{position:relative!important;width:44px!important;height:44px!important;'
            . 'min-width:44px!important;min-height:44px!important;display:inline-flex!important;'
            . 'align-items:center!important;justify-content:center!important;margin:0!important;'
            . 'overflow:visible!important;flex-shrink:0!important}'
            . $overlay . '{position:fixed!important;inset:0!important;width:100%!important;'
            . 'height:100vh!important;height:100dvh!important;z-index:10000!important}'
            . '@media (max-width:767px){'
            . $modal . '{align-items:flex-start!important;'
            . 'padding:max(10px,env(safe-area-inset-top,0px)) 10px max(10px,env(safe-area-inset-bottom,0px))!important}'
            . $wrap . '{width:100%!important;max-width:100%!important;'
            . 'max-height:calc(100vh - 20px)!important;max-height:calc(100dvh - 20px)!important;'
            . 'margin-block:auto!important}}';
    }

    /**
     * Checkout agreements-modal — vence consolidated .modal-inner-wrap overflow:hidden (1º paint).
     */
    private static function checkoutAgreementsModalTerminalRules(): string
    {
        $root = self::checkoutImpeccableLockRoot();
        $modal = $root
            . ' .modal-popup.agreements-modal._inner-scroll';
        $wrap = $modal . ' .modal-inner-wrap';
        $header = $modal . ' .modal-header';
        $content = $modal . ' .modal-content';
        $close = $header . ' .action-close';
        $closeSpan = $close . '>span';

            return '/* AWA agreements-modal terminal v10 — scroll content, close 44px visível */'
                . $modal . ':not(._show){display:none!important;visibility:hidden!important;opacity:0!important;'
                . 'pointer-events:none!important;position:absolute!important;inset:auto!important;'
                . 'width:0!important;height:0!important;min-width:0!important;min-height:0!important;'
                . 'margin:0!important;overflow:hidden!important}'
                . $wrap . ','
                . $header . '{overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
            . $content . '{overflow-y:auto!important;overscroll-behavior:contain;max-height:min(70vh,520px)!important}'
            . $close . '{position:relative!important;width:44px!important;height:44px!important;'
            . 'min-width:44px!important;min-height:44px!important;padding:0!important;margin:0!important;'
            . 'overflow:visible!important;display:inline-flex!important;align-items:center!important;'
            . 'justify-content:center!important;box-sizing:border-box!important;flex-shrink:0!important}'
            . $closeSpan . '{position:absolute!important;width:1px!important;height:1px!important;'
            . 'padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;'
            . 'clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important;pointer-events:none!important}';
    }

    /**
     * Carrinho/checkout — busca distill (nested cards + autocomplete shadow-only).
     */
    private static function checkoutSearchImpeccableDistillRules(): string
    {
        $root = self::checkoutImpeccableLockRoot();
        $shell = $root . ' .page-wrapper .awa-site-header[data-awa-header-mode="default"] .awa-header-search-col';
        $ac = $root . ' .page-wrapper :is(#search_autocomplete,.search-autocomplete,.searchsuite-autocomplete,.mst-searchautocomplete__autocomplete)';

        return $ac . '{border:0!important;box-shadow:0 4px 16px rgb(15 23 42/12%)!important}'
            . $shell . ' :is(>.block-search,>.block-search>.block-content){'
            . 'background:transparent!important;border:0!important;border-radius:0!important;'
            . 'box-shadow:none!important;outline:none!important;overflow:visible!important}'
            . $shell . ' form#search_mini_form{'
            . 'background:var(--awa-bg,#fff)!important;'
            . 'border:1px solid color-mix(in srgb,var(--awa-primary,#b73337) 24%,var(--awa-border,#e5e7eb))!important;'
            . 'box-shadow:none!important;outline:none!important}'
            . $shell . ' form#search_mini_form:focus-within{'
            . 'border-color:var(--awa-primary,#b73337)!important;box-shadow:none!important;outline:none!important}';
    }

    /**
     * Home head critical — sr-only display:none + overflow allowlist (1º paint).
     */
    public static function homeImpeccableHeadCriticalCss(): string
    {
        $root = self::homeImpeccableLockRoot();
        $wrap = $root . ' .page-wrapper';
        $shell = $wrap . ' .awa-site-header';

        return self::homeHeaderRailTerminalRules()
            . self::plpImpeccableSrOnlyHideRules($wrap)
            . $shell . ' .header-content{padding-block:8px!important;box-sizing:border-box!important}'
            . $root . '{overflow-x:visible!important;overflow-y:visible!important}'
            . $wrap . '{overflow-x:visible!important;overflow-y:visible!important}'
            . $wrap . ' :is('
            . '#search_mini_form,.mst-searchautocomplete__autocomplete,'
            . '#awa-b2b-promo-bar,.awa-b2b-promo-bar,.awa-b2b-promo-bar__inner,'
            . '.columns,.column.main,#maincontent,footer.page-footer,.page_footer,'
            . '#footer,.footer-container,a.awa-whatsapp-float,'
            . '.awa-carousel-section,.top-home-content.awa-home-section'
            . '){overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
            . $shell . ' :is(#search_mini_form,button.action.search){overflow:visible!important;position:relative!important}'
            /* Home CLS: autocomplete e overlay, nao conteudo in-flow. */
            . $shell . ' form#search_mini_form .field.search .control{position:relative!important;overflow:visible!important}'
            . $shell . ' form#search_mini_form #search_autocomplete.search-autocomplete{'
            . 'position:absolute!important;top:calc(100% + 8px)!important;left:0!important;right:0!important;'
            . 'z-index:60!important;margin:0!important;min-height:0!important;height:auto!important;display:none!important}'
            . $shell . ' form#search_mini_form #search_autocomplete.search-autocomplete:not(:empty){display:block!important}'
            . $shell . ' a.action.showcart{overflow:visible!important;position:relative!important}';
    }

    /**
     * PLP terminal lock — vence postaudit (#fff hero), padding-inline:0 em columns e sr-only overflow.
     */
    public static function plpImpeccableTerminalLockRules(): string
    {
        $plp = self::plpImpeccableLockRoot();
        $wrap = $plp . ' .page-wrapper';

        return self::plpImpeccableHeaderPaddingRules($wrap)
            . self::catalogMobileHeaderClampRules(self::plpImpeccableLockRoot())
            . self::plpImpeccableSrOnlyHideRules($wrap)
            . $wrap . ' h1.awa-category-hero__title,'
            . $wrap . ' .awa-category-hero--has-image h1.awa-category-hero__title{'
            . 'color:#333333!important;text-shadow:none!important}'
            . $wrap . ' .awa-category-hero--has-image '
            . ':is(.awa-category-hero__title,.awa-category-hero__count,.awa-category-hero__eyebrow,'
            . '.awa-category-hero__content,.awa-category-hero__content *){'
            . 'color:#333!important;text-shadow:none!important}'
            . $wrap . ' .awa-category-hero--has-image .awa-category-hero__count{color:#666!important}'
            . $wrap . ' .awa-category-hero--has-image .awa-category-hero__content{'
            . 'background:#fff!important;background-color:#fff!important;'
            . 'backdrop-filter:none!important;-webkit-backdrop-filter:none!important;'
            . 'border:0!important;box-shadow:none!important;box-sizing:border-box!important}'
            . $wrap . ' .awa-category-hero--has-image .awa-category-hero__content .awa-category-hero__count{'
            . 'color:#666!important}'
            . $wrap . ' :is(.page-main>.columns,.columns.layout,.columns.layout.layout-2-col){'
            . 'padding:0!important;box-sizing:border-box!important}'
            . $wrap . ' .category-view-move{padding:0!important;box-sizing:border-box!important;overflow:visible!important;'
            . 'margin:0 0 var(--awa-plp-stack-gap,12px)!important}'
            . $wrap . '{--awa-plp-stack-gap:var(--awa-stack-tight,var(--awa-space-3,12px))}'
            . $wrap . ' .page-main > .columns.layout.layout-2-col.row,'
            . $wrap . ' .page-main > .columns.layout-2-col.row{'
            . 'column-gap:var(--awa-plp-gap-md,12px)!important;row-gap:0!important;'
            . 'gap:0 var(--awa-plp-gap-md,12px)!important}'
            . $wrap . ' .nav-breadcrumbs{margin-block:0!important;margin-inline:auto!important;'
            . 'width:100%!important;max-width:min(100%,1280px)!important;padding-block:0!important;'
            . 'padding-inline:var(--awa-shell-pad,clamp(16px,3vw,24px))!important}'
            . $wrap . ' .nav-breadcrumbs .breadcrumbs{margin:0!important;margin-block:0!important;padding-block:0!important}'
            . $wrap . ' #maincontent.page-main{padding-top:0!important}'
            . $wrap . ' .mst_categorySearch{margin:0 0 var(--awa-plp-stack-gap,12px)!important}'
            . $wrap . ' .awa-plp-b2b-gate-banner{margin:0 0 var(--awa-plp-stack-gap,12px)!important;'
            . 'padding:var(--awa-space-3,12px)!important}'
            . $wrap . ' .awa-category-hero{min-height:112px!important;max-height:140px!important;'
            . 'display:flex!important;align-items:flex-start!important;'
            . 'padding-block:8px var(--awa-space-3,12px)!important;margin:0!important;'
            . 'margin-block:0!important;margin-block-end:0!important}'
            . $wrap . ' .awa-category-hero.awa-category-hero--has-image{'
            . 'position:relative!important;min-height:180px!important;height:220px!important;'
            . 'max-height:220px!important;padding-block:16px 20px!important;align-items:flex-end!important}'
            . $wrap . ' .awa-category-hero__content{align-self:flex-start!important;padding-block-start:0!important}'
            . $wrap . ' :is(#layered-ajax-filter-block,.block.filter){padding:8px!important;box-sizing:border-box!important}'
            . $wrap . ' .awa-header-categories.menu_left_home1{padding:8px!important;box-sizing:border-box!important}'
            . self::plpSkuDensityRules($wrap)
            . $wrap . ' .awa-site-header '
            . ':is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.header-control.header-nav){'
            . 'padding:8px!important;box-sizing:border-box!important}'
            . $wrap . ' .awa-site-header '
            . ':is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar) > .container{'
            . 'padding:8px!important;box-sizing:border-box!important}'
            . $wrap . ' .wrapper.grid.products-grid .item-product .product-info{'
            . 'padding:8px!important;box-sizing:border-box!important}'
            . self::plpImpeccableToolbarTypeRules($wrap)
            . self::plpImpeccablePass2Rules();
    }

    /**
     * PLP Impeccable pass 2 — ruído estrutural 10px, overflow allowlist, Inter→Source Sans 3 no menu.
     */
    public static function plpImpeccablePass2Rules(): string
    {
        $plp = self::plpImpeccableLockRoot();
        $wrap = $plp . ' .page-wrapper';
        $shell = $wrap . ' .awa-site-header';

        $structural = ':is('
            . '#awa-plp-terminal-lock-inline,#awa-critical-inline-site,#awa-cls-nav-fix,'
            . '#awa-bugfix-terminal-inline,#awa-cookie-consent-critical,head>style,head>noscript,title'
            . '){font-size:0!important;line-height:0!important}';

        $overflow = $plp . '{overflow-x:visible!important;overflow-y:visible!important}'
            . $wrap . '{overflow-x:visible!important;overflow-y:visible!important}'
            . $wrap . ' :is('
            . '#layered-ajax-filter-block,.block.filter,.awa-category-hero,#layered-ajax-list-products,'
            . '.product-content-right,footer.page-footer,.page_footer,#footer,.footer-container,'
            . 'a.awa-whatsapp-float,.awa-header-categories.menu_left_home1,'
            . 'ul.togge-menu.list-category-dropdown,[id^="awa-vertical-menu-"],'
            . '[id^="submenu-menu-"],.navigation__submenu,.navigation__inner-list'
            . '){overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
            . $shell . ' :is(.header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar){'
            . 'overflow:visible!important}'
            . $wrap . ' .products-grid .item-product,'
            . $wrap . ' .products-grid .item-product .product-thumb,'
            . $wrap . ' .products-grid :is(.product-image-container,.product-image-wrapper){'
            . 'overflow:visible!important;contain:none!important}';

        $type = $wrap . ' .navigation.verticalmenu.side-verticalmenu{'
            . '--vm-font:"Source Sans 3",system-ui,-apple-system,sans-serif!important}'
            . $wrap . ' :is('
            . '.navigation.verticalmenu,.our_categories.title-category-dropdown,'
            . '.awa-vmenu-trigger-text,.navigation__link,.navigation__label,'
            . '.navigation__inner-link,ul.togge-menu.list-category-dropdown,'
            . '[id^="submenu-menu-"] *'
            . '){font-family:"Source Sans 3",system-ui,-apple-system,sans-serif!important}';

        return $structural . $overflow . $type;
    }

    /**
     * Home-only — regras não duplicadas no distill base (a11y, tablet, search polish).
     */
    public static function headerDistillHomeSupplementRules(): string
    {
        return self::headerA11yRules()
            . self::minicartInteractionRules()
            . self::headerStickyShellRules()
            . self::tabletHeaderCompactRules()
            . self::homeSearchApfPolishRules();
    }

    /**
     * Scripts distill — harden + a11y (substitui 2 blocos script separados).
     */
    public static function headerDistillTerminalScripts(): string
    {
        return self::headerHardenScript()
            . self::headerPolishA11yScript()
            . self::headerAlignGridContainerPadScript()
            . self::headerDistillMobileGridScript();
    }

    /**
     * Mobile grid lock via inline !important — vence bundles assíncronos pós-distill (PLP + home).
     */
    public static function headerDistillMobileGridScript(): string
    {
        return '<script id="awa-header-distill-mobile-grid-20260616c">(function(){'
            . '"use strict";'
            . 'function applyLogo(){'
            . 'if(window.innerWidth<992)return;'
            . 'var logo=document.querySelector(".awa-header-brand-cell .logo img,.col-md-2.awa-header-brand .logo img");'
            . 'if(!logo)return;'
            . 'logo.style.setProperty("width","104px","important");'
            . 'logo.style.setProperty("max-width","104px","important");'
            . 'logo.style.setProperty("min-width","0","important");'
            . 'logo.style.setProperty("height","44px","important");'
            . 'logo.style.setProperty("max-height","44px","important");'
            . 'logo.style.setProperty("aspect-ratio","auto","important");'
            . 'logo.style.setProperty("object-fit","contain","important");}'
            . 'function apply(){'
            . 'applyLogo();'
            . 'if(window.innerWidth>767)return;'
            . 'var grid=document.querySelector(".awa-main-header__inner.wp-header,[data-awa-header-row]");'
            . 'var sc=document.querySelector(".header-wrapper-sticky .awa-header-search-col");'
            . 'var form=sc&&sc.querySelector("form#search_mini_form");'
            . 'var input=sc&&sc.querySelector("input#search");'
            . 'if(grid){["padding","grid-template-columns","grid-template-areas","grid-template-rows","height","max-height"].forEach(function(p){grid.style.removeProperty(p);});'
            . 'if(!grid.getAttribute("style"))grid.removeAttribute("style");}'
            . 'if(sc){["display","grid-column","width","max-width","box-sizing"].forEach(function(p){sc.style.removeProperty(p);});'
            . 'if(!sc.getAttribute("style"))sc.removeAttribute("style");}'
            . 'if(form){["display","grid-template-columns","width","max-width","box-sizing"].forEach(function(p){form.style.removeProperty(p);});'
            . 'if(!form.getAttribute("style"))form.removeAttribute("style");}'
            . 'if(input){input.style.setProperty("font-size","16px","important");input.style.removeProperty("width");'
            . 'if(!input.getAttribute("style"))input.removeAttribute("style");}}'
            . 'document.addEventListener("DOMContentLoaded",apply,{once:true});'
            . 'window.addEventListener("load",apply,{once:true,passive:true});'
            . 'window.addEventListener("resize",apply,{passive:true});'
            . 'if(document.body&&window.MutationObserver){new MutationObserver(function(){if(document.getElementById("awa-home-cls-shell"))applyLogo();}).observe(document.body,{childList:true,subtree:true});}'
            . '})();</script>';
    }

    /**
     * Remove blocos header terminais legados antes de injetar distill.
     *
     * @param bool $stripCriticalGlobal PLP/busca: omitir critical-global (~35KB) — mobile-grid-critical + distill cobrem
     */
    public static function stripLegacyHeaderTerminalBlocks(string $html, bool $stripCriticalGlobal = false): string
    {
        foreach (self::LEGACY_HEADER_TERMINAL_IDS as $legacyId) {
            $pattern = '/<style\\s+id="' . preg_quote($legacyId, '/') . '"[^>]*>.*?<\\/style>\\s*/is';
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        foreach (self::LEGACY_HEADER_TERMINAL_SCRIPT_IDS as $scriptId) {
            $pattern = '/<script\\s+id="' . preg_quote($scriptId, '/') . '"[^>]*>.*?<\\/script>\\s*/is';
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        foreach (self::LEGACY_HEADER_DUPLICATE_IDS as $duplicateId) {
            $pattern = '/<style\\s+id="' . preg_quote($duplicateId, '/') . '"[^>]*>.*?<\\/style>\\s*/is';
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        if ($stripCriticalGlobal) {
            $pattern = '/<style\\s+id="' . preg_quote(self::HEADER_CRITICAL_GLOBAL_ID, '/') . '"[^>]*>.*?<\\/style>\\s*/is';
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        $html = preg_replace(
            '/<script\\s+id="awa-header-container-pad-zero"[^>]*>.*?<\\/script>\\s*/is',
            '',
            $html
        ) ?? $html;

        $html = self::stripHomeLightFromHtml($html);

        $html = preg_replace(
            '/<style\\s+id="awa-header-distill-terminal-[^"]+"[^>]*>.*?<\\/style>\\s*/is',
            '',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * A11y polish síncrono — aria-label busca mobile quando o template não define.
     */
    public static function headerPolishA11yScript(): string
    {
        return '<script id="awa-header-a11y-polish-20260616">'
            . '(function(){'
            . 'var btn=document.querySelector('
            . '".awa-site-header .awa-header-search-col form#search_mini_form button.action.search"'
            . ');'
            . 'if(btn&&!btn.getAttribute("aria-label")){btn.setAttribute("aria-label","Buscar");}'
            . '})();'
            . '</script>';
    }

    /**
     * Shell 1280px + alinhamento grid/busca/logo/ícones (vence super-global legacy top negativo).
     */
    public static function headerLayoutAlignRules(): string
    {
        $shell = 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header';
        $row = $shell . ' .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';
        $container = $shell . ' .header-wrapper-sticky :is(.header_main.awa-main-header-inner-wrap,.header.awa-main-header) '
            . ':is(.header-main,.header_main)>.container,'
            . $shell . ' .header-wrapper-sticky .header-main>.container';

        return '@media (min-width:992px){'
            . $container . '{'
            . 'max-width:1280px!important;width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:0!important;box-sizing:border-box!important}'
            . $shell . ' .header-wrapper-sticky .header.awa-main-header{'
            . 'padding-block:0!important;margin-block:0!important;height:auto!important;min-height:0!important;'
            . 'overflow:visible!important}'
            . $shell . ' .header-wrapper-sticky :is(.header-main,.header_main,.header_main.awa-main-header-inner-wrap){'
            . 'height:auto!important;min-height:var(--awa-header-main-row-h,72px)!important;'
            . 'padding-block:0!important;margin-block:0!important;overflow:visible!important}'
            . $row . '{max-width:1280px!important;width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:16px!important;box-sizing:border-box!important;align-items:center!important}'
            . $shell . ' :is(.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar)>.container,'
            . $shell . ' .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'max-width:1280px!important;width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:16px!important;box-sizing:border-box!important}'
            . $shell . ' :is(.awa-b2b-promo-bar__inner,.top-header .container){'
            . 'max-width:1280px!important;width:100%!important;margin-inline:auto!important;'
            . 'padding-inline:16px!important;box-sizing:border-box!important}'
            . $shell . ' .header-content:has(.awa-b2b-promo-bar){'
            . 'align-items:stretch!important;width:100%!important;max-width:min(100%,1280px)!important}'
            . $shell . ' :is(.top-header,.top-header.awa-b2b-promo-bar,.awa-b2b-promo-bar){'
            . 'width:100%!important;max-width:100%!important;align-self:stretch!important;flex:1 1 100%!important}'
            . $shell . ' .awa-header-search-col{'
            . 'display:flex!important;align-items:center!important;align-self:stretch!important;'
            . 'grid-template-columns:none!important;grid-template-areas:none!important;height:auto!important}'
            . $shell . ' .awa-header-search-col :is(.block-search,.block-content){'
            . 'display:flex!important;align-items:center!important;width:100%!important;min-height:0!important}'
            . $shell . ' .awa-header-search-col :is(.block-content,form#search_mini_form,form.minisearch){'
            . 'width:100%!important;max-width:100%!important}'
            . $shell . ' .awa-search-helper-copy{display:none!important;visibility:hidden!important;height:0!important;overflow:hidden!important}'
            . $shell . ' .awa-header-search-col form#search_mini_form{align-self:center!important;margin-block:0!important}'
            . $shell . ' .awa-header-right-col{align-items:center!important;align-self:center!important}'
            . $shell . ' .awa-header-minicart{align-self:center!important}'
            . $shell . ' :is(.awa-header-brand-cell,.col-md-2.awa-header-brand){align-self:center!important}'
            . $shell . ' :is(.awa-header-brand-cell,.col-md-2.awa-header-brand) .logo img{'
            . 'width:104px!important;max-width:104px!important;min-width:0!important;height:44px!important;max-height:44px!important;'
            . 'aspect-ratio:auto!important;object-fit:contain!important;object-position:left center!important}'
            . '}'
            . '@media (min-width:768px) and (max-width:991px){'
            . $shell . ' .header-wrapper-sticky .header.awa-main-header{'
            . 'padding-block:0!important;margin-block:0!important;height:auto!important}'
            . $shell . ' .header-wrapper-sticky :is(.header-main,.header_main){'
            . 'padding-block:0!important;margin-block:0!important;height:auto!important}'
            . $shell . ' .awa-header-search-col{'
            . 'display:block!important;grid-template-columns:none!important;grid-template-areas:none!important;width:100%!important}'
            . $shell . ' .awa-header-search-col .block-content{'
            . 'display:block!important;grid-template-columns:none!important;grid-template-areas:none!important;width:100%!important}'
            . $shell . ' .awa-header-search-col :is(form#search_mini_form,form.minisearch,.block-search,.block-content){'
            . 'width:100%!important;max-width:100%!important;min-width:0!important}'
            . $shell . ' .awa-search-helper-copy{display:none!important;visibility:hidden!important;width:0!important;height:0!important;overflow:hidden!important}'
            . $row . '{max-width:1280px!important;margin-inline:auto!important;padding-inline:16px!important}'
            . '}'
            . '@media (max-width:767px){'
            . $shell . ':not(.awa-header-condensed) .header-wrapper-sticky :is(.header-main,.header_main){'
            . 'padding:0!important;padding-block:0!important;margin:0!important;'
            . 'height:96px!important;min-height:96px!important;max-height:96px!important;'
            . 'overflow:hidden!important;box-sizing:border-box!important}'
            . $shell . ':not(.awa-header-condensed) .header-wrapper-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'align-items:center!important;align-content:center!important;contain:none!important;overflow:visible!important;'
            . 'grid-template-columns:44px minmax(0,1fr) 44px!important;grid-template-rows:44px 44px!important;'
            . 'grid-template-areas:"toggle brand cart" "search search search"!important;gap:4px 8px!important;'
            . 'padding:4px 16px 0!important;box-sizing:border-box!important}'
            . $shell . ':not(.awa-header-condensed) :is(.awa-header-mobile-toggle,.action.nav-toggle,[data-action="toggle-nav"]){'
            . 'position:relative!important;top:auto!important;right:auto!important;left:auto!important;inset:auto!important;'
            . 'align-self:center!important;justify-self:center!important;margin:0!important;transform:none!important}'
            . $shell . ':not(.awa-header-condensed) :is(.awa-header-brand-cell,.col-md-2.awa-header-brand,.awa-header-minicart){'
            . 'align-self:center!important;justify-self:center!important;position:relative!important;top:auto!important}'
            . $shell . ':not(.awa-header-condensed) .awa-header-minicart .minicart-wrapper{position:static!important;top:auto!important;right:auto!important}'
            . $shell . ':not(.awa-header-condensed) .awa-header-search-col{align-self:stretch!important;contain:none!important;'
            . 'display:block!important;grid-template-columns:none!important;width:100%!important;'
            . 'min-width:0!important;max-width:100%!important;box-sizing:border-box!important}'
            . $shell . ':not(.awa-header-condensed) .awa-header-search-col :is(.block-search,.block-content){'
            . 'display:block!important;width:100%!important;max-width:100%!important;min-width:0!important;'
            . 'padding:0!important;margin:0!important;height:44px!important;max-height:44px!important;box-sizing:border-box!important}'
            . $shell . ':not(.awa-header-condensed) .awa-header-search-col form#search_mini_form{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;'
            . 'grid-template-areas:"field submit"!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important;margin:0!important;height:44px!important;max-height:44px!important}'
            . '}';
    }

    /**
     * Regras terminais mobile para o campo de busca.
     * Usa longhands grid-column-start/end (não shorthand grid-area) para evitar
     * conflitos com regras grid-area:search de bundles assíncronos (awa-header-stack).
     * Seletor de máxima especificidade garante vitória na cascata.
     */
    public static function mobileSearchTerminalRules(): string
    {
        return '@media (max-width:767px){'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header .awa-header-search-col.top-search{'
            . 'display:block!important;grid-template-columns:none!important;grid-template-areas:none!important;'
            . 'width:100%!important;min-width:0!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header .awa-header-search-col '
            . ':is(.block-search,.block-content,form#search_mini_form,form.minisearch){'
            . 'width:100%!important;max-width:100%!important;min-width:0!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header .awa-header-search-col form#search_mini_form{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;'
            . 'grid-template-areas:"field submit"!important;width:100%!important;max-width:100%!important;'
            . 'box-sizing:border-box!important;margin:0!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header .awa-header-search-col '
            . 'form#search_mini_form .field.search{flex:1 1 auto!important;min-width:0!important;width:auto!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header .awa-header-search-col '
            . 'form#search_mini_form input#search{width:100%!important;min-width:0!important;max-width:100%!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header .awa-header-search-col .awa-search-helper-copy{'
            . 'display:none!important}'
            . 'html body#html-body#html-body#html-body .page-wrapper .awa-site-header '
            . ':is(.awa-header-brand-cell,.col-md-2.awa-header-brand){'
            . 'min-width:56px!important;width:auto!important;overflow:visible!important}'
            . '}';
    }

    /**
     * Tablet 768–991 — grid corporativo brand | search | actions (56px), não mobile 2-row.
     */
    public static function tabletHeaderCompactRules(): string
    {
        $row = 'html body#html-body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]),'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky '
            . '.header.awa-main-header .header_main.awa-main-header-inner-wrap '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row])';

        return '@media (min-width:768px) and (max-width:991px){'
            . $row . '{'
            . 'display:grid!important;grid-template-areas:"brand search actions"!important;'
            . 'grid-template-columns:clamp(112px,14vw,148px) minmax(0,1fr) minmax(220px,max-content)!important;'
            . 'grid-template-rows:56px!important;gap:0 12px!important;'
            . 'height:56px!important;min-height:56px!important;max-height:56px!important;'
            . 'padding:0 16px!important;max-width:min(100%,1280px)!important;margin-inline:auto!important;'
            . 'box-sizing:border-box!important;width:100%!important;overflow:visible!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky '
            . ':is(.awa-header-mobile-toggle,.action.nav-toggle,[data-action="toggle-nav"]){display:none!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky '
            . '.awa-header-primary-row{display:contents!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky '
            . '.awa-header-right-col{display:inline-flex!important;grid-area:actions!important;'
            . 'gap:8px!important;align-items:center!important;justify-content:flex-end!important;width:auto!important}'
            . 'html body#html-body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky '
            . '.awa-header-search-col{grid-area:search!important;grid-column:auto!important;grid-row:auto!important;'
            . 'width:100%!important;min-width:0!important;height:44px!important;max-height:44px!important;min-height:44px!important}'
            . 'html body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            . '.page-wrapper .content-top-home .awa-carousel-section>.container{'
            . 'box-sizing:border-box!important;margin-inline:auto!important;'
            . 'max-width:min(100%,1280px)!important;padding-inline:16px!important;width:100%!important}'
            . '}';
    }

    public static function styleTag(): string
    {
        return '<style id="' . self::STYLE_ID . '">'
            . self::rules()
            . self::skipLinkFocusTerminalRules()
            . self::footerCategoriesGridFillRules()
            . self::footerCategoriesDesktopLayoutRules()
            . self::pixelQaFooterAxisTerminalRules()
            . self::mobileCondensedOneRowTerminalRules()
            . self::headerGuestChromeKillCardRules()
            . '</style>';
    }

    /**
     * BUG-SHELL-MOBILE-CONDENSED-1ROW — último no cascade-lock (depois de rules()).
     * Força 1 linha + primary-row:contents no sticky mobile.
     */
    public static function mobileCondensedOneRowTerminalRules(): string
    {
        $h = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper header.awa-site-header.awa-header-condensed';

        return '/* BUG-SHELL-MOBILE-CONDENSED-1ROW terminal cascade-lock */'
            . '@media(max-width:767px){'
            . $h . ' .header-wrapper-sticky{'
            . 'height:56px!important;min-height:56px!important;max-height:56px!important;overflow:hidden!important;'
            . 'position:fixed!important;top:0!important;left:0!important;right:0!important;width:100%!important;z-index:5001!important}'
            . $h . ' .header-wrapper-sticky :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main){'
            . 'height:56px!important;min-height:56px!important;max-height:56px!important;padding:0!important;margin:0!important;overflow:hidden!important}'
            . $h . ' .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'display:grid!important;grid-template-areas:"toggle brand search cart"!important;'
            . 'grid-template-columns:44px minmax(0,1fr) 44px 44px!important;grid-template-rows:44px!important;'
            . 'gap:0 8px!important;height:56px!important;min-height:56px!important;max-height:56px!important;'
            . 'padding:6px 12px!important;box-sizing:border-box!important;align-items:center!important;overflow:visible!important}'
            /* primary-row vira contents — senão grid-area:primary cria linha fantasma sob o shell 56px. */
            . $h . ' .header-wrapper-sticky .awa-header-primary-row{'
            . 'display:contents!important;grid-area:unset!important;grid-template:none!important;'
            . 'height:auto!important;min-height:0!important;max-height:none!important;width:auto!important}'
            . $h . ' .header-wrapper-sticky :is(.awa-header-search-col,.top-search){'
            . 'grid-area:search!important;display:flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;'
            . 'min-height:44px!important;max-height:44px!important;overflow:visible!important}'
            . $h . ' .header-wrapper-sticky .awa-header-search-col form#search_mini_form{'
            . 'display:flex!important;width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;'
            . 'border:0!important;background:transparent!important;overflow:visible!important;grid-template-columns:none!important}'
            . $h . ' .header-wrapper-sticky .awa-header-search-col :is(.field.search,.field.search .control,.block-search .label,input#search){'
            . 'position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;'
            . 'overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}'
            . $h . ' .header-wrapper-sticky .awa-header-search-col .actions{display:flex!important;margin:0!important;padding:0!important}'
            . $h . ' .header-wrapper-sticky .awa-header-search-col :is(button.action.search,.action.search){'
            . 'display:inline-flex!important;align-items:center!important;justify-content:center!important;'
            . 'width:44px!important;min-width:44px!important;height:44px!important;min-height:44px!important;padding:0!important}'
            . $h . ' .header-wrapper-sticky .awa-header-mobile-toggle{grid-area:toggle!important;justify-self:start!important}'
            . $h . ' .header-wrapper-sticky .awa-header-brand-cell{grid-area:brand!important;justify-self:center!important;align-self:center!important}'
            . $h . ' .header-wrapper-sticky :is(.awa-header-minicart,.awa-header-right-col){grid-area:cart!important;justify-self:end!important}'
            . '}';
    }

    /**
     * PIXEL-QA: último override no cascade-lock — eixo só em footer.page-footer.
     * Neutraliza padding: Npx 16px / max-width calc(100%-32px) definidos acima em rules().
     */
    public static function pixelQaFooterAxisTerminalRules(): string
    {
        return '/* PIXEL-QA footer-axis-terminal fullbleed-20260731 */'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(.page_footer,footer.page-footer){'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(footer.page-footer,.page_footer,.page-footer) :is(section.awa-footer-trust-bar,.awa-footer-trust-bar){'
            . 'width:100%!important;max-width:none!important;margin-inline:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-footer-newsletter>.container,'
            . '.footer-bottom>.container,.awa-footer-trust-bar>.container,'
            . '#footer.footer-container>.container,section.awa-footer-categories-expand>.container){'
            . 'box-sizing:border-box!important;width:min(100%,1280px)!important;max-width:1280px!important;'
            . 'margin-inline:auto!important}'
            . '@media(max-width:767px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-footer-newsletter>.container,'
            . '.footer-bottom>.container,.awa-footer-trust-bar>.container,'
            . '#footer.footer-container>.container,section.awa-footer-categories-expand>.container){'
            . 'padding-inline:16px!important;padding-left:16px!important;padding-right:16px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #footer .vela-content{'
            . 'padding-inline:0!important;padding-left:0!important;padding-right:0!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-wrapper{'
            . 'flex-direction:column!important;align-items:stretch!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-info{'
            . 'flex:1 1 auto!important;max-width:none!important}}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.awa-footer-newsletter>.container,'
            . '.footer-bottom>.container,.awa-footer-trust-bar>.container,'
            . '#footer.footer-container>.container,section.awa-footer-categories-expand>.container){'
            . 'padding-inline:24px!important;padding-left:24px!important;padding-right:24px!important}}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer ul.awa-footer-categories-list a{'
            . 'border-radius:999px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-wrapper{'
            . 'display:flex!important;flex-wrap:nowrap!important;grid-template-columns:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-info{'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;'
            . 'gap:8px!important;grid-template-columns:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-icon{'
            . 'display:inline-flex!important;flex:0 0 24px!important;width:24px!important;height:24px!important;'
            . 'min-width:24px!important;min-height:24px!important;max-width:24px!important;max-height:24px!important;'
            . 'color:var(--awa-primary)!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-form-container,'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .newsletter-footer{'
            . 'border:0!important;background:transparent!important;box-shadow:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail{'
            . 'margin:0!important;max-width:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-footer-trust-copy{'
            . 'display:flex!important;flex-direction:column!important;align-items:flex-start!important;gap:4px!important}'
            . '@media(min-width:768px){'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-wrapper{'
            . 'flex-direction:row!important;align-items:center!important;justify-content:flex-start!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-info{'
            . 'flex:0 1 28rem!important;max-width:28rem!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer .awa-newsletter-form-container{'
            . 'flex:1 1 280px!important;width:auto!important;max-width:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail{'
            . 'display:block!important;width:100%!important;grid-template-columns:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail .field.newsletter{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;'
            . 'grid-template-rows:44px auto!important;align-items:start!important;'
            . 'column-gap:8px!important;row-gap:6px!important;width:100%!important;height:auto!important;'
            . 'min-height:44px!important;padding:0!important;padding-block:0!important;'
            . 'border:0!important;background:transparent!important;box-shadow:none!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail .control{'
            . 'grid-column:1!important;grid-row:1!important;min-width:0!important;height:44px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail .actions{'
            . 'grid-column:2!important;grid-row:1!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer #newsletter-validate-detail :is(.actions,button.action.subscribe){'
            . 'width:auto!important;max-width:none!important;white-space:nowrap!important;height:44px!important}}'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper footer.page-footer :is(.page_footer,#footer,.footer-container,.footer-bottom,.footer-bottom-inner),'
            . 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
            . ' .page-wrapper :is(.page_footer,.page-footer) .footer-bottom{'
            . 'max-width:100%!important;width:100%!important;margin-inline:0!important;'
            . 'padding-left:0!important;padding-right:0!important;padding-inline:0!important;'
            . 'box-sizing:border-box!important}';
    }

    /**
     * Audit #26 — skip links precisam vencer o hide terminal no fim do cascade.
     * Evidência CDP: binary-search part 297 (.awa-skip-link hide); focus show no meio
     * de rules() perdia para regras posteriores.
     */
    public static function skipLinkFocusTerminalRules(): string
    {
        return 'html body#html-body#html-body#html-body :is('
            . '.awa-skip-link,a.action.skip,a.action.skip.content,a.action.skip.nav,'
            . 'a.skip-to-main-content'
            . '):is(:focus,:focus-visible){'
            . 'position:fixed!important;top:8px!important;left:8px!important;right:auto!important;bottom:auto!important;'
            . 'width:auto!important;height:auto!important;min-width:44px!important;min-height:44px!important;'
            . 'max-width:none!important;max-height:none!important;margin:0!important;padding:10px 14px!important;'
            . 'overflow:visible!important;clip:auto!important;clip-path:none!important;contain:none!important;'
            . 'white-space:normal!important;font-size:14px!important;line-height:1.3!important;'
            . 'display:inline-flex!important;align-items:center!important;z-index:2147483646!important;'
            . 'background:#b73337!important;color:#fff!important;border-radius:8px!important;'
            . 'font-weight:600!important;text-decoration:none!important;pointer-events:auto!important;'
            . 'opacity:1!important;visibility:visible!important}';
    }

    public static function guardScriptTag(): string
    {
        $styleId = self::STYLE_ID;
        $guardId = self::GUARD_SCRIPT_ID;

        /* Snapshot do <style> existente — evita duplicar ~10KB de rules no HTML (TBT/parse). */
        /* applyMobileGrid — aplica via setProperty para garantir vitória sobre bundles assíncronos. */
        return '<script id="' . $guardId . '">(function(){'
            . '"use strict";'
            . 'var id=' . json_encode($styleId, JSON_UNESCAPED_SLASHES) . ';'
            . 'var snap="";'
            . 'function capture(){var nodes=document.querySelectorAll("style[id=\\""+id+"\\"]");var best="";'
            . 'for(var i=0;i<nodes.length;i++){if(nodes[i].textContent&&nodes[i].textContent.length>best.length){'
            . 'best=nodes[i].textContent;}}if(best){snap=best;}else{var el=document.getElementById(id);'
            . 'if(el&&el.textContent){snap=el.textContent;}}}'
            . 'function apply(){if(!snap){return;}var el=document.getElementById(id);'
            . 'var root=document.body||document.documentElement;if(!el){el=document.createElement("style");'
            . 'el.id=id;root.appendChild(el);}if(el.textContent!==snap){el.textContent=snap;}'
            . 'if(document.body&&el.parentNode!==document.body){document.body.appendChild(el);}}'
            . 'function applyMobileGrid(){'
            . 'if(window.innerWidth>767)return;'
            . 'var grid=document.querySelector(".awa-main-header__inner.wp-header");'
            . 'var sc=document.querySelector(".header-wrapper-sticky .awa-header-search-col");'
            . 'var hm=document.querySelector(".header-wrapper-sticky .header-main");'
            . 'if(grid){["grid-template-columns","grid-template-areas","grid-template-rows","gap","padding","height","min-height","max-height","box-sizing","overflow"].forEach(function(p){grid.style.removeProperty(p);});'
            . 'if(!grid.getAttribute("style"))grid.removeAttribute("style");}'
            . 'if(sc){["grid-column","grid-row","width","min-width","max-width","display","height","min-height","max-height","overflow"].forEach(function(p){sc.style.removeProperty(p);});'
            . 'if(!sc.getAttribute("style"))sc.removeAttribute("style");'
            . 'var form=sc&&sc.querySelector("form#search_mini_form");'
            . 'var input=sc&&sc.querySelector("input#search");'
            . 'if(form){["height","min-height","max-height","width","max-width","box-sizing"].forEach(function(p){form.style.removeProperty(p);});'
            . 'if(!form.getAttribute("style"))form.removeAttribute("style");}'
            . 'if(input){input.style.setProperty("font-size","16px","important");input.style.removeProperty("height");input.style.removeProperty("min-height");'
            . 'if(!input.getAttribute("style"))input.removeAttribute("style");}'
            . 'if(hm){hm.style.setProperty("overflow","visible","important");}}}'
            . 'capture();apply();'
            . 'document.addEventListener("DOMContentLoaded",function(){capture();apply();applyMobileGrid();},{once:true});'
            . 'window.addEventListener("load",function(){applyMobileGrid();},{once:true,passive:true});'
            . 'if(typeof MutationObserver!=="undefined"&&document.body){'
            . 'new MutationObserver(function(muts){'
            /* CORRECAO 07/10/2026 — medido pelo long-animation-frame: este callback acumulava
               *** 25.675ms *** em /catalogo, o maior ofensor da pagina (6x o segundo colocado).
               Causa: o observer observa TODO filho direto do body, e o documento adiciona/remove
               filhos o tempo todo (minicart, scroll-lock, banners). Em cada mutacao rodava
               `getElementById`, mesmo quando nada tinha sido removido — e o estilo so pode
               desaparecer por REMOCAO. Agora o callback sai cedo quando nao houve remocao,
               o que cobre a esmagadora maioria das mutacoes. */
            . 'var removeu=false;for(var i=0;i<muts.length;i++){if(muts[i].removedNodes&&muts[i].removedNodes.length){removeu=true;break;}}'
            . 'if(!removeu){return;}'
            . 'if(!document.getElementById(id)){apply();}})'
            . '.observe(document.body,{childList:true,subtree:false});}'
            . '})();</script>';
    }

    public static function homeSearchApfPolishRules(): string
    {
        return '/* §APF-T — busca desktop .actions stretch (polish 2026-06-12) */'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header:not(.awa-header-condensed) .awa-header-search-col '
            . ':is(form#search_mini_form,form.minisearch){'
            . 'display:flex!important;align-items:stretch!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header:not(.awa-header-condensed) .awa-header-search-col '
            . 'form#search_mini_form .actions{'
            . 'display:flex!important;align-items:stretch!important;align-self:stretch!important;'
            . 'flex:0 0 48px!important;width:48px!important;min-width:48px!important;'
            . 'height:auto!important;min-height:0!important;max-height:none!important;'
            . 'margin:0!important;padding:0!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header:not(.awa-header-condensed) .awa-header-search-col '
            . 'form#search_mini_form .action.search{'
            . 'align-self:stretch!important;width:100%!important;min-width:48px!important;'
            . 'height:100%!important;min-height:44px!important;max-height:none!important}'
            // Desktop: suprimir a lupa desenhada via ::before/::after do botão, mantendo só o SVG inline (evita lupa duplicada).
            . '@media(min-width:992px){'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header .awa-header-search-col form#search_mini_form button.action.search::before,'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header .awa-header-search-col form#search_mini_form button.action.search::after{'
            . 'content:none!important;display:none!important;border:0!important;background:none!important}'
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper '
            . '.awa-site-header .awa-header-search-col form#search_mini_form button.action.search svg{'
            . 'display:block!important;margin:0 auto!important}'
            . '}'
            . '@media(max-width:991px){html body#html-body#html-body#html-body#html-body .page-wrapper '
            . ':is(.awa-shelf--carousel,.product-item) a.b2b-login-link{'
            . 'min-height:44px!important;min-width:44px!important;display:inline-flex!important;'
            . 'align-items:center!important;justify-content:center!important}}'
            /* Promo CTA: inline no shell 32px — sem min-height 44 / pad±8 (estoura a barra). */
            . 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-b2b-promo-bar__cta{'
            . 'display:inline!important;align-items:unset!important;position:relative!important;'
            . 'min-height:0!important;height:auto!important;max-height:none!important;'
            . 'padding-block:0!important;margin-block:0!important;'
            . 'box-sizing:border-box!important}';
    }

    /**
     * Home PSI — regras pós-async que o v10 + critical-home não cobrem (promo, minicart, a11y, mobile search).
     */
    public static function homeHeaderLightLockRules(): string
    {
        return self::headerA11yRules()
            . self::minicartInteractionRules()
            . self::promoBarRules()
            . self::headerStickyShellRules()
            . self::headerVisFixTerminalRules()
            . self::headerCartDedupeRules()
            . self::headerLayoutAlignRules()
            . self::mobileSearchTerminalRules()
            . self::tabletHeaderCompactRules()
            . self::mobileHeaderCompact112Rules()
            . self::headerEssentialTerminalRules()
            . self::homeSearchApfPolishRules();
    }

    public static function headerEssentialStyleTag(): string
    {
        return '<style id="' . self::HEADER_ESSENTIAL_STYLE_ID . '">'
            . self::headerEssentialTerminalRules()
            . '</style>';
    }

    public static function audit30SurfaceStyleTag(): string
    {
        return '<style id="' . self::AUDIT30_SURFACE_STYLE_ID . '">'
            . self::mediaVisualAudit30Rules()
            . self::baixaVisualAudit30Rules()
            . self::skipLinkFocusTerminalRules()
            . self::footerCategoriesGridFillRules()
            . self::footerCategoriesDesktopLayoutRules()
            . '</style>';
    }

    public static function stripHeaderEssentialFromHtml(string $html): string
    {
        $pattern = '/<style\\s+id="' . preg_quote(self::HEADER_ESSENTIAL_STYLE_ID, '/') . '"[^>]*>.*?<\\/style>\\s*/is';

        return preg_replace($pattern, '', $html) ?? $html;
    }

    public static function stripAudit30SurfaceFromHtml(string $html): string
    {
        $pattern = '/<style\\s+id="' . preg_quote(self::AUDIT30_SURFACE_STYLE_ID, '/') . '"[^>]*>.*?<\\/style>\\s*/is';

        return preg_replace($pattern, '', $html) ?? $html;
    }

    public static function homeLightStyleTag(): string
    {
        return '<style id="' . self::HOME_LIGHT_STYLE_ID . '">' . self::homeHeaderLightLockRules() . '</style>';
    }

    public static function homeLightLinkTag(string $staticBase): string
    {
        $href = rtrim($staticBase, '/') . '/css/' . self::HOME_LIGHT_CSS_FILE . self::HOME_LIGHT_QUERY;

        return '<link rel="preload" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" as="style"/>'
            . '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" media="all" id="'
            . self::HOME_LIGHT_STYLE_ID . '"/>';
    }

    public static function htmlHasHomeLightLock(string $html): bool
    {
        if (!str_contains($html, self::HOME_LIGHT_STYLE_ID)) {
            return false;
        }

        return (bool) preg_match(
            '/<(?:style|link)[^>]+id=["\']' . preg_quote(self::HOME_LIGHT_STYLE_ID, '/') . '["\'][^>]*>/i',
            $html
        );
    }

    public static function stripHomeLightFromHtml(string $html): string
    {
        $id = preg_quote(self::HOME_LIGHT_STYLE_ID, '/');
        $html = preg_replace('/<style\\s+id="' . $id . '"[^>]*>.*?<\\/style>\\s*/is', '', $html) ?? $html;
        $html = preg_replace('/<link\\s[^>]+id=["\']' . $id . '["\'][^>]*\\/?>\\s*/is', '', $html) ?? $html;
        $html = preg_replace('/<link\\s[^>]+href=["\'][^"\']*' . preg_quote(self::HOME_LIGHT_CSS_FILE, '/') . '[^"\']*["\'][^>]*\\/?>\\s*/is', '', $html) ?? $html;

        return $html;
    }

    /**
     * Home vis-fix sem applyMobileGrid — distill mobile-grid script é SSOT.
     */
    public static function homeVisFixOnlyScriptTag(): string
    {
        $guardId = self::HOME_GUARD_SCRIPT_ID;

        return '<script id="' . $guardId . '">(function(){'
            . '"use strict";'
            . 'function applyHeaderVisFix(){'
            . 'var prompt=document.querySelector(".awa-site-header .awa-header-account-prompt");'
            . 'if(prompt&&window.innerWidth>=992){'
            . 'prompt.style.removeProperty("max-width");prompt.style.removeProperty("overflow");'
            . 'if(!prompt.getAttribute("style"))prompt.removeAttribute("style");'
            . 'prompt.style.setProperty("border","0","important");'
            . 'prompt.style.setProperty("border-radius","0","important");'
            . 'prompt.style.setProperty("background","transparent","important");'
            . 'prompt.style.setProperty("padding","0","important");'
            . 'prompt.style.setProperty("box-shadow","none","important");'
            . 'var reg=prompt.querySelector(".awa-header-account-prompt__link--register");'
            . 'if(reg){reg.style.setProperty("background","transparent","important");'
            . 'reg.style.setProperty("background-color","transparent","important");'
            . 'reg.style.setProperty("color","var(--awa-text, CanvasText)","important");'
            . 'reg.style.setProperty("border","0","important");'
            . 'reg.style.setProperty("border-radius","0","important");'
            . 'reg.style.setProperty("padding","0 2px","important");}}'
            . 'if(window.innerWidth>=768&&window.innerWidth<=991&&prompt){'
            . 'prompt.querySelectorAll(".awa-header-account-prompt__icon,.awa-header-account-prompt__text,.awa-header-account-prompt__guest")'
            . '.forEach(function(el){el.style.setProperty("display","none","important");});'
            . 'var ml=prompt.querySelector(".awa-header-account-prompt__mobile-link");'
            . 'if(ml){ml.style.setProperty("display","inline-flex","important");ml.style.setProperty("visibility","visible","important");}}'
            . 'else if(prompt){'
            . 'prompt.querySelectorAll(".awa-header-account-prompt__icon,.awa-header-account-prompt__text,.awa-header-account-prompt__guest")'
            . '.forEach(function(el){el.style.removeProperty("display");el.style.removeProperty("visibility");});'
            . 'var mlClear=prompt.querySelector(".awa-header-account-prompt__mobile-link");'
            . 'if(mlClear){mlClear.style.removeProperty("display");mlClear.style.removeProperty("visibility");'
            . 'if(!mlClear.getAttribute("style"))mlClear.removeAttribute("style");}}'
            . 'var fallback=document.querySelector(".awa-header-minicart .awa-header-cart-fallback");'
            . 'var hasShowcart=document.querySelector(".awa-header-minicart .minicart-wrapper .showcart");'
            . 'if(fallback&&hasShowcart){fallback.style.removeProperty("display");'
            . 'fallback.style.removeProperty("visibility");fallback.style.removeProperty("pointer-events");'
            . 'if(!fallback.getAttribute("style"))fallback.removeAttribute("style");}'
            . 'var mainContainer=document.querySelector(".awa-site-header .header-main>.container,.awa-site-header .header_main>.container");'
            . 'if(mainContainer&&window.innerWidth<=767){["padding","padding-inline","padding-left","padding-right"].forEach(function(p){mainContainer.style.removeProperty(p);});'
            . 'if(!mainContainer.getAttribute("style"))mainContainer.removeAttribute("style");}'
            . 'var toggle=document.querySelector(".awa-header-mobile-toggle,.action.nav-toggle,[data-action=\\"toggle-nav\\"]");'
            . 'if(toggle&&window.innerWidth<=767){["position","top","right","left","transform"].forEach(function(p){toggle.style.removeProperty(p);});'
            . 'if(!toggle.getAttribute("style"))toggle.removeAttribute("style");}}'
            . 'function boot(){applyHeaderVisFix();}'
            . 'document.addEventListener("DOMContentLoaded",boot,{once:true});'
            . 'window.addEventListener("load",boot,{once:true,passive:true});'
            . 'window.addEventListener("resize",boot,{passive:true});'
            . '})();</script>';
    }

    public static function homeGuardScriptTag(): string
    {
        $guardId = self::HOME_GUARD_SCRIPT_ID;

        return '<script id="' . $guardId . '">(function(){'
            . '"use strict";'
            . 'var distillGridId=' . json_encode(self::DISTILL_MOBILE_GRID_SCRIPT_ID, JSON_UNESCAPED_SLASHES) . ';'
            . 'function applyMobileGrid(){'
            . 'if(document.getElementById(distillGridId)){return;}'
            . 'if(window.innerWidth>767)return;'
            . 'var grid=document.querySelector(".awa-main-header__inner.wp-header");'
            . 'var sc=document.querySelector(".header-wrapper-sticky .awa-header-search-col");'
            . 'if(grid){["grid-template-columns","grid-template-areas","grid-template-rows","gap","padding","height","max-height"].forEach(function(p){grid.style.removeProperty(p);});'
            . 'if(!grid.getAttribute("style"))grid.removeAttribute("style");}'
            . 'if(sc){["grid-column","width","height","min-height","max-height","display"].forEach(function(p){sc.style.removeProperty(p);});'
            . 'if(!sc.getAttribute("style"))sc.removeAttribute("style");'
            . 'var form=sc&&sc.querySelector("form#search_mini_form");'
            . 'var input=sc&&sc.querySelector("input#search");'
            . 'if(form){["height","min-height","max-height","width","max-width","box-sizing","display","grid-template-columns"].forEach(function(p){form.style.removeProperty(p);});'
            . 'if(!form.getAttribute("style"))form.removeAttribute("style");}'
            . 'if(input){input.style.removeProperty("height");input.style.removeProperty("min-height");input.style.removeProperty("width");'
            . 'if(!input.getAttribute("style"))input.removeAttribute("style");}'
            . 'if(sc){sc.style.removeProperty("display");if(!sc.getAttribute("style"))sc.removeAttribute("style");}'
            . 'var brand=document.querySelector(".awa-site-header .awa-header-brand-cell,.awa-site-header .col-md-2.awa-header-brand");'
            . 'if(brand){brand.style.removeProperty("min-width");brand.style.removeProperty("width");'
            . 'if(!brand.getAttribute("style"))brand.removeAttribute("style");}}}'
            . 'function applyHeaderVisFix(){'
            . 'var prompt=document.querySelector(".awa-site-header .awa-header-account-prompt");'
            . 'if(prompt&&window.innerWidth>=992){'
            . 'prompt.style.removeProperty("max-width");prompt.style.removeProperty("overflow");'
            . 'if(!prompt.getAttribute("style"))prompt.removeAttribute("style");'
            . 'prompt.style.setProperty("border","0","important");'
            . 'prompt.style.setProperty("border-radius","0","important");'
            . 'prompt.style.setProperty("background","transparent","important");'
            . 'prompt.style.setProperty("padding","0","important");'
            . 'prompt.style.setProperty("box-shadow","none","important");'
            . 'var reg=prompt.querySelector(".awa-header-account-prompt__link--register");'
            . 'if(reg){reg.style.setProperty("background","transparent","important");'
            . 'reg.style.setProperty("background-color","transparent","important");'
            . 'reg.style.setProperty("color","var(--awa-text, CanvasText)","important");'
            . 'reg.style.setProperty("border","0","important");'
            . 'reg.style.setProperty("border-radius","0","important");'
            . 'reg.style.setProperty("padding","0 2px","important");}}'
            . 'if(window.innerWidth>=768&&window.innerWidth<=991&&prompt){'
            . 'prompt.querySelectorAll(".awa-header-account-prompt__icon,.awa-header-account-prompt__text,.awa-header-account-prompt__guest")'
            . '.forEach(function(el){el.style.setProperty("display","none","important");});'
            . 'var ml=prompt.querySelector(".awa-header-account-prompt__mobile-link");'
            . 'if(ml){ml.style.setProperty("display","inline-flex","important");ml.style.setProperty("visibility","visible","important");}}'
            . 'else if(prompt){'
            . 'prompt.querySelectorAll(".awa-header-account-prompt__icon,.awa-header-account-prompt__text,.awa-header-account-prompt__guest")'
            . '.forEach(function(el){el.style.removeProperty("display");el.style.removeProperty("visibility");});'
            . 'var mlClear=prompt.querySelector(".awa-header-account-prompt__mobile-link");'
            . 'if(mlClear){mlClear.style.removeProperty("display");mlClear.style.removeProperty("visibility");'
            . 'if(!mlClear.getAttribute("style"))mlClear.removeAttribute("style");}}'
            . 'var fallback=document.querySelector(".awa-header-minicart .awa-header-cart-fallback");'
            . 'var hasShowcart=document.querySelector(".awa-header-minicart .minicart-wrapper .showcart");'
            . 'if(fallback&&hasShowcart){fallback.style.removeProperty("display");'
            . 'fallback.style.removeProperty("visibility");fallback.style.removeProperty("pointer-events");'
            . 'if(!fallback.getAttribute("style"))fallback.removeAttribute("style");}'
            . 'var mainContainer=document.querySelector(".awa-site-header .header-main>.container,.awa-site-header .header_main>.container");'
            . 'if(mainContainer&&window.innerWidth<=767){["padding","padding-inline","padding-left","padding-right"].forEach(function(p){mainContainer.style.removeProperty(p);});'
            . 'if(!mainContainer.getAttribute("style"))mainContainer.removeAttribute("style");}'
            . 'var toggle=document.querySelector(".awa-header-mobile-toggle,.action.nav-toggle,[data-action=\\"toggle-nav\\"]");'
            . 'if(toggle&&window.innerWidth<=767){["position","top","right","left","transform"].forEach(function(p){toggle.style.removeProperty(p);});'
            . 'if(!toggle.getAttribute("style"))toggle.removeAttribute("style");}'
            . 'applyMobileGrid();}'
            . 'function boot(){applyHeaderVisFix();}'
            . 'document.addEventListener("DOMContentLoaded",boot,{once:true});'
            . 'window.addEventListener("load",boot,{once:true,passive:true});'
            . '})();</script>';
    }

    public static function homeLightInjection(?string $staticBase = null): string
    {
        $css = ($staticBase !== null && $staticBase !== '')
            ? self::homeLightLinkTag($staticBase)
            : self::homeLightStyleTag();

        return $css . "\n" . self::homeGuardScriptTag();
    }

    /**
     * Home: omitir cascade-lock completo (~112KB); v10 + critical-home cobrem 1º paint.
     */
    public static function injectHomeLightBeforeBodyClose(string $html): string
    {
        $html = self::stripLegacyFromHtml($html);
        $html = self::stripFooterTerminalFromHtml($html);
        $html = self::stripHomeLightFromHtml($html);
        $html = self::stripHomeImpeccableTerminalFromHtml($html);
        $html = self::stripAudit30SurfaceFromHtml($html);
        $html = preg_replace(
            '/<script\\s+id="' . preg_quote(self::HOME_GUARD_SCRIPT_ID, '/') . '"[^>]*>.*?<\\/script>\\s*/is',
            '',
            $html
        ) ?? $html;

        $tag = '';
        if (!str_contains($html, self::HOME_GUARD_SCRIPT_ID)) {
            $tag = str_contains($html, self::DISTILL_MOBILE_GRID_SCRIPT_ID)
                ? self::homeVisFixOnlyScriptTag()
                : self::homeGuardScriptTag();
        }

        if (!str_contains($html, 'id="awa-impeccable-critical-home"')) {
            $tag = self::footerOnlyStyleTag() . "\n" . $tag;
        }

        if (!str_contains($html, self::HOME_IMPECCABLE_TERMINAL_STYLE_ID)) {
            $tag = self::homeImpeccableTerminalStyleTag() . "\n" . $tag;
        }

        // Audit30 Média/Baixa — home omite cascade-lock; este bloco cobre dark header etc.
        $tag = self::audit30SurfaceStyleTag() . "\n" . $tag;

        $tag = self::externalizeGuardScript($tag, $html);

        $pos = stripos($html, '</body>');
        if ($pos === false) {
            return $html . "\n" . $tag;
        }

        return substr($html, 0, $pos) . $tag . "\n" . substr($html, $pos);
    }

    /**
     * VS5c (ciclo 2, item A): troca o script do guard por <script src> cacheavel
     * quando a base estatica versionada e conhecida (fallback = inline atual).
     */
    private static function externalizeGuardScript(string $payload, string $html): string
    {
        if (!preg_match(
            '#(?:/static/)?(version[A-Za-z0-9]+)/frontend/AWA_Custom/ayo_home5_child/pt_BR/#',
            $html,
            $m
        )) {
            return $payload;
        }

        return preg_replace(
            '/<script[^>]*\bid="' . preg_quote(self::HOME_GUARD_SCRIPT_ID, '/') . '"[^>]*>.*?<\/script>\s*/is',
            '<script src="/static/' . $m[1] . '/frontend/AWA_Custom/ayo_home5_child/pt_BR/js/'
                . self::HOME_GUARD_SCRIPT_ID . '.js?v=20261018-vs5c" id="' . self::HOME_GUARD_SCRIPT_ID . '"></script>',
            $payload,
            1
        ) ?? $payload;
    }

    public static function footerInjection(): string
    {
        return self::styleTag()
            . "\n"
            . self::guardScriptTag()
            . "\n"
            . self::catalogRootHeightScriptTag();
    }

    private static function catalogRootHeightScriptTag(): string
    {
        return '<script id="awa-catalog-root-height-lock">(function(){'
            . 'var d=document,b=d.body,doc=d.documentElement;'
            . 'if(!b||!b.matches||!b.matches(".catalog-category-view,.catalogsearch-result-index,.catalog-product-view")){return;}'
            . 'function apply(){'
            . 'if(!d.querySelector("nav.fixed-bottom")){return;}'
            . 'doc.style.setProperty("height","100%","important");'
            . 'doc.style.setProperty("min-height","100%","important");'
            . 'b.style.setProperty("height","100%","important");'
            . 'b.style.setProperty("min-height","100%","important");'
            . '}'
            . 'apply();'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply,{once:true});}'
            . 'window.addEventListener("load",apply,{once:true,passive:true});'
            . 'window.addEventListener("resize",apply,{passive:true});'
            . '})();</script>';
    }

    private static function headerNavAxisLockScriptTag(): string
    {
        /* Geometria da nav fica no CSS. Scripts duplicados são removidos em stripLegacyFromHtml. */
        return '';
    }

    public static function footerOnlyStyleTag(): string
    {
        return '<style id="' . self::FOOTER_STYLE_ID . '">' . self::footerTerminalRules() . '</style>';
    }

    /**
     * Home: terminal Impeccable — 4× #html-body vence home-density-grid (3×) e body-end bundles.
     * Injetado por último antes de </body> (inline + ordem DOM).
     */
    public static function homeImpeccableTerminalStyleTag(): string
    {
        return '<style id="' . self::HOME_IMPECCABLE_TERMINAL_STYLE_ID . '">'
            . self::homeImpeccableTerminalRules()
            . self::footerCategoriesGridFillRules()
            . self::footerCategoriesDesktopLayoutRules()
            . self::footerDesktopTouch44R1Rules()
            . self::footerCategoriesWhiteR16Rules()
            . self::footerNewsletterWhiteR17Rules()
            . self::footerCopyrightFlatR20Rules()
            . self::footerAtendimentoStoreFlatR21Rules()
            . self::categoryCarouselDesktopGutterR22Rules()
            . self::shelfViewAllMobileHideR18Rules()
            . self::shelfCarouselMobileChromeHideR19Rules()
            . self::footerImpeccablePolishR66Rules()
            . self::headerGuestChromeKillCardRules()
            . self::footerContentAxisRules()
            . '</style>';
    }

    public static function homeImpeccableTerminalRules(): string
    {
        $h = 'html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper';

        return $h . ' .content-top-home :is(.awa-shelf--carousel,.awa-carousel-section)'
            . ' :is(.content-item-product.awa-product-card,.product-thumb,span.first-thumb,.item-product.awa-carousel-card-slot,'
            . '.product-image-container,.product-image-wrapper,.owl-stage-outer){overflow:visible!important}'
            /* QA 2026-07-22 / H22b: category carousel — setas overlaid, track 100% (sem gutter 52×2). */
            . $h . ' :is(.awa-owl-progress.awa-carousel-chrome-ssr){overflow:visible!important}'
            . $h . ' .top-home-content--category-carousel .awa-category-carousel__viewport{'
            . 'margin-inline:0!important;padding:0!important;box-sizing:border-box!important;'
            . 'overflow:hidden!important;overflow-x:hidden!important;position:relative!important}'
            . $h . ' .top-home-content--category-carousel .awa-category-carousel__track,'
            . $h . ' .top-home-content--category-carousel #awa-cat-carousel.awa-category-carousel__track{'
            . 'padding:0!important;scroll-padding-inline:0!important;gap:8px!important;'
            . 'overflow-x:auto!important;overflow-y:hidden!important;scrollbar-width:none!important}'
            . '@media (min-width:768px){'
            /* r22g: product DOM — padded wrap, clipped viewport, nav left/right 4px */
            . $h . ' .top-home-content--category-carousel .awa-category-carousel{'
            . 'position:relative!important;padding-inline:48px!important;--awa-home-carousel-gutter:48px}'
            . $h . ' .top-home-content--category-carousel .awa-category-carousel__viewport{'
            . 'padding-inline:0!important;margin-inline:0!important;overflow:hidden!important;'
            . 'overflow-x:hidden!important;width:100%!important}'
            . $h . ' .top-home-content--category-carousel .awa-category-carousel__prev{'
            . 'left:0!important;right:auto!important;z-index:5!important}'
            . $h . ' .top-home-content--category-carousel .awa-category-carousel__next{'
            . 'right:0!important;left:auto!important;z-index:5!important}'
            . $h . ' .top-home-content--category-carousel .awa-category-carousel__track,'
            . $h . ' .top-home-content--category-carousel #awa-cat-carousel.awa-category-carousel__track{'
            . 'margin-inline:0!important;width:100%!important;max-width:100%!important}'
            . '}'
            . '@media (max-width:767px){'
            . $h . ' .top-home-content--category-carousel .awa-category-carousel__track,'
            . $h . ' .top-home-content--category-carousel #awa-cat-carousel.awa-category-carousel__track{'
            . 'margin-inline:0!important;width:100%!important;max-width:100%!important}'
            . '}'
            . $h . ' .top-home-content--category-carousel :is(.awa-category-carousel__item,a.awa-category-carousel__item){'
            . 'overflow:hidden!important;max-width:144px!important}'
            /* Sticky: promo e irmao do sticky — esconde via body.awa-header-is-sticky */
            . 'html body#html-body#html-body#html-body.awa-header-is-sticky:not(.onepagecheckout-index-index):not(.checkout-index-index)'
            . ' .page-wrapper .awa-site-header :is(#header.header-container,#header.header-container>.header-content,'
            . '#awa-b2b-promo-bar,.awa-b2b-promo-bar,.top-header.awa-b2b-promo-bar),'
            . 'html body#html-body#html-body#html-body:has(.header-wrapper-sticky.is-sticky):not(.onepagecheckout-index-index):not(.checkout-index-index)'
            . ' .page-wrapper .awa-site-header :is(#header.header-container,#header.header-container>.header-content,'
            . '#awa-b2b-promo-bar,.awa-b2b-promo-bar,.top-header.awa-b2b-promo-bar){'
            . 'display:none!important;height:0!important;max-height:0!important;min-height:0!important;'
            . 'overflow:hidden!important;margin:0!important;padding:0!important;border:0!important;'
            . 'visibility:hidden!important;pointer-events:none!important}'
            . $h . ' :is(.swiper.awa-hero-swiper,.swiper-wrapper,.banner_item,.banner_item_bg,'
            . '.banner-slider.banner-slider2,.wrapper_slider){overflow:visible!important}'
            . $h . ' .ayo-home5-wrapper.ayo-home5-wrapper--template-driven{overflow:visible!important}'
            . $h . ' :is(.block.block-minicart,.block-minicart.ui-dialog-content,.minicart-wrapper .mage-dropdown-dialog){overflow:visible!important}'
            . $h . ' :is(ul.togge-menu.list-category-dropdown,.level0.submenu.navigation__submenu,'
            . '.level0.submenu.navigation__inner-list,.awa-nav-bar .container,.header-control.awa-nav-bar .container){overflow:visible!important}'
            . $h . ' :is(#b2b-status-dropdown,.b2b-status-dropdown){'
            . 'border-width:0!important;box-shadow:0 2px 8px rgb(15 23 42 / 10%)!important}'
            /* Nav bar home — respiro vertical; botão Departamentos não cola no topo/fundo */
            . 'html body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .header-control.awa-nav-bar{--awa-nav-bar-h:48px!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)'
            . ' .page-wrapper .awa-site-header :is(.header-control.header-nav,.header-control.header-nav.awa-nav-bar,'
            . '.header-control.awa-nav-bar,.awa-nav-bar){'
            . 'padding:0 max(16px,env(safe-area-inset-left),env(safe-area-inset-right))!important;'
            . 'min-height:48px!important;height:48px!important;max-height:48px!important;box-sizing:border-box!important;'
            . 'overflow:visible!important}'
            . '@media(min-width:992px){'
            . $h . ' .awa-site-header[data-awa-header-mode="default"]{'
            . 'overflow:visible!important;position:relative!important;z-index:100120!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"]:has(.awa-account-dropdown__trigger[aria-expanded="true"]){'
            . 'z-index:100260!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"] :is('
            . '.header-content,.header-wrapper-sticky,.header.awa-main-header,.header-main,.header-main>.container,'
            . '.awa-main-header__inner,.awa-header-right-col,.awa-header-account-prompt,.awa-account-dropdown){'
            . 'overflow:visible!important;position:relative!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"] .awa-header-right-col{'
            . 'z-index:100270!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"] .awa-account-dropdown{'
            . 'z-index:100270!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"] .awa-account-dropdown__trigger{'
            . 'position:relative!important;z-index:100280!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"] .awa-account-dropdown__menu{'
            . 'background:var(--awa-bg,Canvas)!important;'
            . 'border:0!important;border-radius:8px!important;'
            . 'box-shadow:0 4px 12px color-mix(in srgb,CanvasText 12%,transparent)!important;'
            . 'box-sizing:border-box!important;display:none!important;inset-block-start:calc(100% - 1px)!important;'
            . 'inset-inline-end:0!important;margin:0!important;margin-block-start:0!important;'
            . 'min-width:12rem!important;max-width:min(18rem,calc(100vw - 24px))!important;'
            . 'overflow:hidden!important;padding:6px!important;position:absolute!important;'
            . 'top:calc(100% - 1px)!important;transform:none!important;visibility:hidden!important;'
            . 'z-index:100300!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"] .awa-account-dropdown__menu[aria-hidden="false"]{'
            . 'display:grid!important;opacity:1!important;pointer-events:auto!important;visibility:visible!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"]:has(.awa-account-dropdown__trigger[aria-expanded="true"]) '
            . '.header-wrapper-sticky{overflow:visible!important;z-index:100260!important}'
            . $h . ' .awa-site-header[data-awa-header-mode="default"]:has(.awa-account-dropdown__trigger[aria-expanded="true"]) '
            . ':is(.header-control.awa-nav-bar,.header-control.awa-nav-bar>.container,.header-control.awa-nav-bar .awa-nav-bar__inner){'
            . 'z-index:100120!important}'
            . '}'
            . $h . ' .header-control.awa-nav-bar .awa-nav-bar__inner{'
            . 'align-items:center!important;padding-block:0!important;'
            . 'padding-inline:var(--awa-home-shell-gutter,24px)!important;'
            . 'min-height:48px!important;height:48px!important;max-height:48px!important}'
            . $h . ' .header-control.awa-nav-bar > .container{'
            . 'align-items:center!important;padding:0!important;padding-inline:0!important;'
            . 'min-height:48px!important;height:48px!important;max-height:48px!important}'
            . $h . ' .header-control.awa-nav-bar .awa-nav-quick-links{'
            . 'align-items:center!important;height:auto!important;min-height:40px!important}'
            . $h . ' .header-control.awa-nav-bar .awa-nav-quick-links__list{align-items:center!important}'
            . $h . ' .header-control.awa-nav-bar .awa-nav-quick-links__link{'
            . 'display:inline-flex!important;align-items:center!important;'
            . 'min-height:44px!important;padding-block:0!important}'
            . $h . ' .header-control.awa-nav-bar'
            . ' :is(.our_categories.title-category-dropdown,button[data-role=awa-vertical-menu-trigger]){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'border-radius:8px!important;align-self:center!important}'
            . $h . ' .awa-header-categories.menu_left_home1{'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding:0!important;box-sizing:border-box!important;overflow:visible!important}'
            . $h . ' .awa-header-categories.menu_left_home1 :is(nav.awa-nav-categories,'
            . '.sections.nav-sections.category-dropdown,.section-items.nav-sections.category-dropdown-items,'
            . '.section-item-content.nav-sections.category-dropdown-item-content,.navigation.verticalmenu.side-verticalmenu){'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'overflow:visible!important;position:relative!important;width:100%!important}'
            . $h . ' .awa-header-categories.menu_left_home1 ul.togge-menu.list-category-dropdown{'
            . 'background:var(--awa-bg,Canvas)!important;border:1px solid var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas))!important;'
            . 'border-radius:0 0 8px 8px!important;box-shadow:0 4px 10px color-mix(in srgb,CanvasText 10%,transparent)!important;'
            . 'box-sizing:border-box!important;display:none!important;height:auto!important;left:0!important;'
            . 'max-height:min(70vh,560px)!important;opacity:0!important;overflow-x:hidden!important;overflow-y:auto!important;'
            . 'padding:8px 10px 10px!important;position:absolute!important;top:44px!important;'
            . 'visibility:hidden!important;width:min(304px,calc(100vw - 32px))!important;z-index:100150!important}'
            . $h . ' .awa-header-categories.menu_left_home1:is(:hover,:focus-within,.is-open,.active,.awa-vmf-active) ul.togge-menu.list-category-dropdown,'
            . $h . ' .awa-header-categories.menu_left_home1 ul.togge-menu.list-category-dropdown:is(.menu-open,.vmm-open,[aria-hidden="false"]){'
            . 'display:block!important;height:auto!important;max-height:min(70vh,560px)!important;opacity:1!important;'
            . 'overflow-x:hidden!important;overflow-y:auto!important;visibility:visible!important}'
            . $h . ' .minicart-wrapper .counter.qty{border-width:0!important;box-shadow:none!important}'
            . $h . ' section.awa-footer-trust-bar{padding-block:14px 16px!important}'
            . $h . ' .content-top-home>.ayo-home5-wrapper.ayo-home5-wrapper--template-driven{'
            . 'padding-inline:0!important;box-sizing:border-box!important}'
            . $h . ' .content-top-home>.top-home-content--above-fold{'
            // r44c: padding no above-fold gerava inset 1280@x=30 e LCP re-stamp ~2.9s.
            . 'padding-inline:0!important;box-sizing:border-box!important}'
            . $h . ' .content-top-home :is(.awa-hero-b2b-cta,.awa-home-pricing-notice,'
            . '.ayo-home5-wrapper--template-driven>:is(.top-home-content,.awa-home-section,.awa-carousel-section,#awa-home-niche-shelves)){'
            . 'padding-inline:max(16px,env(safe-area-inset-left),env(safe-area-inset-right))!important;box-sizing:border-box!important}'
            . $h . ' .content-top-home .ayo-home5-wrapper--template-driven>.top-home-content.awa-home-section{'
            . 'padding-inline:0!important}'
            . $h . ' .content-top-home>.top-home-content--above-fold>.banner-slider.banner-slider2{'
            . 'width:100vw!important;max-width:100vw!important;margin-left:calc(50% - 50vw)!important;margin-right:0!important;'
            . 'padding:0!important;box-sizing:border-box!important;overflow:hidden!important}'
            . $h . ' .top-home-content--above-fold .banner_item .text-banner:not(:empty){'
            . 'background:linear-gradient(to top,rgb(15 23 42 / 72%) 0%,rgb(15 23 42 / 28%) 55%,transparent 100%)!important;'
            . 'padding:clamp(16px,3vw,32px) clamp(16px,4vw,48px)!important;'
            . 'border-radius:0!important;box-sizing:border-box!important;z-index:2!important}'
            . $h . ' .top-home-content--above-fold .banner_item .text-banner :is('
            . 'h2.slide-title,.slide-title,.slide-content p,.banner-content p,p.slide-desc,.slide-desc,p'
            . '){color:rgb(248 250 252 / 92%)!important;text-shadow:none!important;background-color:transparent!important}'
            . $h . ' .top-home-content--above-fold .banner_item .text-banner :is(h2.slide-title,.slide-title){'
            . 'color:#fff!important}'
            . $h . ' .content-top-home :is(.awa-carousel-section,.top-home-content--category-carousel,.awa-home-recent-orders,.awa-grid-section,.awa-home-niche-shelves) > .container,'
            . $h . ' .awa-hero-b2b-cta__inner.container{'
            . 'margin-inline:0!important;padding-inline:0!important;width:100%!important;max-width:100%!important;box-sizing:border-box!important}'
            . $h . ' .awa-shelf--carousel{padding-inline:0!important}'
            . $h . ' .awa-shelf--carousel :is(.awa-carousel__viewport,.owl-wrapper-outer){'
            . 'margin-inline:0!important;padding-inline:0!important;width:100%!important}'
            /* Mais Vendidos desktop: setas overlay 44px — gutter no track (não no viewport). */
            . '@media(min-width:768px){'
            . $h . ' .content-top-home .awa-carousel-section--featured .awa-shelf--carousel .awa-carousel__track{'
            . 'box-sizing:content-box!important;'
            . 'padding-inline:calc(var(--awa-touch-target,44px) + 8px)!important}}'
            . $h . ' .content-top-home .awa-hero-b2b-cta{padding-block:12px!important;padding-inline:0!important}'
            . $h . ' .content-top-home .ayo-home5-wrapper--template-driven{'
            . 'gap:var(--awa-home-section-gap,12px)!important;row-gap:var(--awa-home-section-gap,12px)!important}'
            . $h . ' .content-top-home .ayo-home5-wrapper--template-driven > .top-home-content.awa-home-section{'
            . 'padding-block:0!important;padding-inline:0!important;margin-block:0!important}'
            . $h . ' .content-top-home :is(.awa-section-header,.awa-shelf__header,.awa-category-carousel__header.awa-section-header){'
            . 'margin-block-end:var(--awa-home-section-gap,12px)!important;padding-block-end:0!important;'
            . 'gap:var(--awa-home-section-gap,12px)!important}'
            . $h . ' #maincontent.page-main{min-height:0!important;height:auto!important;margin:0!important;padding:0!important}'
            . $h . ' :is(.page-footer,.page_footer){margin-block-start:var(--awa-home-section-gap,12px)!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
            . '--awa-home-shell-gutter:16px;--awa-home-pad-compact:8px;--awa-home-pad-standard:12px;'
            . '--awa-home-pad-featured:16px;--awa-home-pad-category:12px;'
            . '--awa-home-section-gap:var(--awa-stack-tight,var(--awa-space-3,12px))}'
            /* P2.1 2026-07-28: contrato tablet/desktop gutter 24 (≥768); mobile permanece 16 */
            . '@media (min-width:768px){html body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){'
            . '--awa-home-shell-gutter:24px}}'
            . 'html body#html-body#html-body#html-body#html-body#html-body .page-wrapper'
            . ' :is(.page_footer,.page-footer) .footer-container .vela-content'
            . ' :is(p.awa-footer-atendimento__label,p.awa-footer-atendimento__label--social){'
            /* FIX 2026-07-06: .vela-content deste bloco tem fundo --awa-primary (vermelho),
               não branco/claro — confirmado após corrigir corrupção em _awa-consolidated.less
               que mantinha a regra de fundo vermelho (mais específica, com #html-body) inativa.
               Texto cinza (--awa-text-secondary) não teria contraste sobre vermelho; volta ao
               branco original (76% opacidade), que já dava ~5.9:1 no fundo real. */
            . 'color:color-mix(in srgb,var(--awa-text-inverse,white) 92%,transparent)!important}'
            . self::headerStickyPositionFixRules()
            . 'html body#html-body#html-body#html-body#html-body#html-body .page-wrapper'
            . ' :is(.page_footer,.page-footer) .awa-footer-newsletter{'
            . 'padding-inline:max(16px,env(safe-area-inset-left),env(safe-area-inset-right))!important;'
            . 'box-sizing:border-box!important}'
            . 'html body#html-body#html-body#html-body#html-body#html-body .page-wrapper'
            . ' :is(.page_footer,.page-footer) .footer-bottom .footer-bottom-inner{'
            . 'padding-inline:max(16px,env(safe-area-inset-left),env(safe-area-inset-right))!important}'
            . self::homeShellCenterTerminalRules()
            . self::homeImpeccablePolishTerminalRules()
            . self::homeHeaderRailTerminalRules()
            . self::homeImpeccablePaddingTerminalRules();
    }

    /**
     * Override final dos achados Impeccable de padding.
     * r43b/CLS: NÃO reintroduzir padding-block:8px no shell — isso pintava
     * .header-main em y:53 e o CSS async (rail/terminal) zerava depois (CLS ~0.038).
     * Geometria alinhada ao awa-home-cls-geometry-guard (promo 44 + main 68 + nav 48).
     * BUG-SHELL-CLS-GEOM-ALIGN-001: sticky 116 / stack 160 ≡ SSOT geom.
     */
    public static function homeImpeccablePaddingTerminalRules(): string
    {
        $home = 'html body#html-body#html-body#html-body#html-body#html-body'
            . ':is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5)';
        $wrap = $home . ' .page-wrapper';
        $header = $wrap . ' .awa-site-header';
        $safePad = 'max(var(--awa-space-4,16px),env(safe-area-inset-left),env(safe-area-inset-right))';
        $mainH = 'var(--awa-header-main-row-h,var(--awa-header-main-h,68px))';

        return $home . '{'
            . '--awa-header-nav-h:48px!important;--awa-nav-bar-h:48px!important;'
            . '--awa-header-sticky-h:116px!important;--awa-header-stack-h:160px!important}'
            . $wrap . ' :is(#b2b-status-dropdown,.b2b-status-dropdown){'
            . 'border-width:0!important;box-shadow:0 2px 8px rgb(15 23 42 / 10%)!important}'
            . $wrap . ' :is(.awa-site-header .header-wrapper-sticky,#header .header-wrapper-sticky){'
            . 'padding-block:0!important;padding-inline:0!important;'
            . 'min-height:var(--awa-header-sticky-h,116px)!important;'
            . 'height:var(--awa-header-sticky-h,116px)!important;'
            . 'max-height:var(--awa-header-sticky-h,116px)!important;'
            . 'box-sizing:border-box!important;overflow:visible!important}'
            . $header . ' :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.header-main){'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'min-height:' . $mainH . '!important;height:' . $mainH . '!important;'
            . 'max-height:' . $mainH . '!important;box-sizing:border-box!important;overflow:visible!important}'
            . $header . ' .header-wrapper-sticky > .header.awa-main-header{'
            . 'padding:0!important;padding-block:0!important;padding-inline:0!important;'
            . 'min-height:' . $mainH . '!important;height:' . $mainH . '!important;'
            . 'max-height:' . $mainH . '!important;box-sizing:border-box!important;overflow:visible!important}'
            . $header . ' :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-block:0!important;padding-inline:var(--awa-space-4,16px)!important;'
            . 'min-height:' . $mainH . '!important;height:' . $mainH . '!important;'
            . 'max-height:' . $mainH . '!important;box-sizing:border-box!important;overflow:visible!important}'
            . $header . ' .header-wrapper-sticky .header.awa-main-header '
            . ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'padding-block:0!important;'
            . 'padding-inline:var(--awa-space-4,16px)!important;'
            . 'min-height:' . $mainH . '!important;height:' . $mainH . '!important;'
            . 'max-height:' . $mainH . '!important;box-sizing:border-box!important;overflow:visible!important}'
            . $header . ' :is(.awa-header-search-col,.top-search) :is(.block-search,.block-content){'
            . 'padding:0!important;'
            . 'min-height:var(--awa-touch-min,44px)!important;'
            . 'height:var(--awa-touch-min,44px)!important;max-height:var(--awa-touch-min,44px)!important;box-sizing:border-box!important;overflow:visible!important}'
            . $header . ' :is(.awa-header-search-col,.top-search) :is(form#search_mini_form,form.minisearch){'
            . 'height:var(--awa-touch-min,44px)!important;min-height:var(--awa-touch-min,44px)!important;'
            . 'max-height:var(--awa-touch-min,44px)!important}'
            . $header . ' :is(.header-control.header-nav,.header-control.header-nav.awa-nav-bar,'
            . '.header-control.awa-nav-bar,.awa-nav-bar){'
            . 'padding:0 ' . $safePad . '!important;'
            . 'min-height:50px!important;'
            . 'height:50px!important;max-height:50px!important;box-sizing:border-box!important;overflow:visible!important}'
            . $header . ' :is(.header-control.header-nav,.header-control.header-nav.awa-nav-bar,'
            . '.header-control.awa-nav-bar,.awa-nav-bar) > .container{'
            . 'padding-inline:0!important;min-height:50px!important;'
            . 'height:50px!important;max-height:50px!important;box-sizing:border-box!important;overflow:visible!important}'
            /* P2.1: tablet/desktop home — gutter no inner (24), zero no bar (evita double) */
            . '@media (min-width:768px){'
            . $header . ' :is(.header-control.header-nav,.header-control.header-nav.awa-nav-bar,'
            . '.header-control.awa-nav-bar,.awa-nav-bar){padding-inline:0!important}'
            . $header . ' :is(.awa-nav-bar__inner,.header-control.awa-nav-bar .awa-nav-bar__inner){'
            . 'padding-inline:var(--awa-home-shell-gutter,24px)!important;'
            . 'padding-left:var(--awa-home-shell-gutter,24px)!important;'
            . 'padding-right:var(--awa-home-shell-gutter,24px)!important}}'
            . $wrap . ' :is(.page_footer,.page-footer) .awa-footer-newsletter{'
            . 'padding-inline:' . $safePad . '!important;box-sizing:border-box!important}';
    }

    public static function stripHomeImpeccableTerminalFromHtml(string $html): string
    {
        $pattern = '/<style\\s+id="' . preg_quote(self::HOME_IMPECCABLE_TERMINAL_STYLE_ID, '/') . '"[^>]*>.*?<\\/style>\\s*/is';

        return preg_replace($pattern, '', $html) ?? $html;
    }

    public static function stripFooterTerminalFromHtml(string $html): string
    {
        $pattern = '/<style\\s+id="' . preg_quote(self::FOOTER_STYLE_ID, '/') . '"[^>]*>.*?<\\/style>\\s*/is';

        return preg_replace($pattern, '', $html) ?? $html;
    }

    /**
     * Injeta lock leve do footer antes de </body> quando o cascade-lock do header foi omitido.
     */
    public static function injectFooterOnlyBeforeBodyClose(string $html): string
    {
        if (str_contains($html, self::STYLE_ID)) {
            return $html;
        }

        $html = self::stripFooterTerminalFromHtml($html);
        $html = self::stripHeaderEssentialFromHtml($html);
        $html = self::stripAudit30SurfaceFromHtml($html);
        /* headerEssentialTerminalRules migrado para distill; Audit30 surface cobre dark/toggle/cookie. */
        $tag = self::footerOnlyStyleTag() . "\n" . self::audit30SurfaceStyleTag();
        $pos = stripos($html, '</body>');
        if ($pos === false) {
            return $html . "\n" . $tag;
        }

        return substr($html, 0, $pos) . $tag . "\n" . substr($html, $pos);
    }

    public static function htmlHasSiteHeader(string $html): bool
    {
        if (self::isAuthShellHtml($html)) {
            return false;
        }

        if (str_contains($html, 'data-awa-site-header="true"')
            || str_contains($html, "data-awa-site-header='true'")
        ) {
            return true;
        }

        return preg_match('/<(?:header|div)[^>]*class="[^"]*\bawa-site-header\b/', $html) === 1
            || preg_match('/<[^>]*class="[^"]*\bawa-b2b-promo-bar\b/', $html) === 1
            || preg_match('/<[^>]*class="[^"]*\bheader-wrapper-sticky\b/', $html) === 1;
    }

    /** Só páginas de login/cadastro — evita falso positivo em seletores CSS inline da PLP. */
    public static function isAuthShellHtml(string $html): bool
    {
        return (bool) preg_match(
            '/<body[^>]*class="[^"]*\bb2b-auth-shell\b/',
            $html
        );
    }

    /** Painel B2B logado — CSS próprio; omitir cascade-lock global (~112KB) + styles-m. */
    public static function isB2bAccountOperationalHtml(string $html): bool
    {
        return (bool) preg_match(
            '/<body[^>]*class="[^"]*\b(?:b2b-account-dashboard|awa-account-operational)\b/',
            $html
        );
    }

    /**
     * Catálogo/PDP/busca: omite cascade-lock (~221KB); guarda mobile + altura do root.
     * A geometria do header no catálogo é a SSOT unify — não reinjetar catalog-parity-v1.
     */
    public static function injectCatalogLightBeforeBodyClose(string $html): string
    {
        $html = self::stripLegacyFromHtml($html);

        $tag = '';
        if (!str_contains($html, self::HOME_GUARD_SCRIPT_ID)
            && !str_contains($html, self::GUARD_SCRIPT_ID)
            && !str_contains($html, self::DISTILL_MOBILE_GRID_SCRIPT_ID)
        ) {
            $tag .= self::homeGuardScriptTag() . "\n";
        }

        if (!str_contains($html, 'id="awa-catalog-root-height-lock"')) {
            $tag .= self::catalogRootHeightScriptTag() . "\n";
        }

        if (!str_contains($html, self::AUDIT30_SURFACE_STYLE_ID)) {
            $tag .= self::audit30SurfaceStyleTag() . "\n";
        }

        $tag = self::externalizeGuardScript($tag, $html);

        if ($tag === '') {
            return $html;
        }

        $pos = stripos($html, '</body>');
        if ($pos === false) {
            return $html . "\n" . $tag;
        }

        return substr($html, 0, $pos) . $tag . substr($html, $pos);
    }

    /**
     * Catálogo/PDP/busca: lock final de paridade do header.
     * Injeta no fim do body para vencer bundles tardios com !important.
     */
    public static function injectCatalogParityBeforeBodyClose(string $html): string
    {
        $html = preg_replace(
            '/<style\\s+id="' . preg_quote(self::CATALOG_PARITY_STYLE_ID, '/') . '"[^>]*>.*?<\\/style>\\s*/is',
            '',
            $html
        ) ?? $html;

        $tag = '<style id="' . self::CATALOG_PARITY_STYLE_ID . '">' . self::catalogParityRules() . '</style>';
        $pos = stripos($html, '</body>');
        if ($pos === false) {
            return $html . "\n" . $tag;
        }

        return substr($html, 0, $pos) . $tag . "\n" . substr($html, $pos);
    }

    /** Visible density labels must not depend on retired icon fonts. */
    public static function catalogGridDensityRules(): string
    {
        $root = 'html body#html-body:is(.catalog-category-view,.catalogsearch-result-index)'
            . ' .page-wrapper .toolbar-products .grid-mode-show-type-products';
        $rules = $root . ' a[data-grid-mode]>.awa-grid-density__value{'
            . 'display:inline!important;visibility:visible!important;opacity:1!important;'
            . 'font:700 14px/1 var(--awa-font-body,Arial,sans-serif)!important;'
            . 'text-indent:0!important;color:var(--awa-text,CanvasText)!important}'
            . $root . ' a[data-grid-mode].actived>.awa-grid-density__value{'
            . 'color:var(--awa-text-inverse,white)!important}';
        return '@layer awa-visual-priority{' . $rules . '}' . $rules;
    }

    private static function catalogParityRules(): string
    {
        $header = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header';

        return '@media (min-width:992px){'
            . $header . '{'
            . '--awa-header-main-row-h:64px!important;--awa-header-row-h:var(--awa-header-main-row-h)!important;'
            . '--awa-header-nav-h:48px!important;--awa-nav-bar-h:var(--awa-header-nav-h)!important}'
            . $header . ' #header.header-container{height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding-block:0!important;margin:0!important}'
            . $header . ' #header.header-container .header-content{height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'padding-block:0!important;margin:0 auto!important}'
            . 'html.awa-b2b-promo-dismissed' . substr($header, 4) . ' #header.header-container,'
            . 'html.awa-b2b-promo-dismissed' . substr($header, 4) . ' #header.header-container.awa-b2b-promo-shell--collapsed,'
            . 'html.awa-b2b-promo-dismissed' . substr($header, 4) . ' #header.header-container .header-content,'
            . $header . ' #header.header-container:has(#awa-b2b-promo-bar[aria-hidden="true"]),'
            . $header . ' #header.header-container:has(#awa-b2b-promo-bar[aria-hidden="true"]) .header-content{'
            . 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;'
            . 'border:0!important;overflow:hidden!important;line-height:0!important}'
            . $header . ' .header-wrapper-sticky{display:flex!important;flex-direction:column!important;'
            . 'height:auto!important;min-height:118px!important;max-height:none!important;'
            . 'padding-block:0!important;margin:0!important}'
            . $header . ' .header.awa-main-header{'
            . 'height:var(--awa-header-main-row-h,68px)!important;min-height:var(--awa-header-main-row-h,68px)!important;'
            . 'max-height:var(--awa-header-main-row-h,68px)!important;'
            . 'padding-block:0!important;width:min(100%,1280px)!important;max-width:1280px!important;'
            . 'margin:0 auto!important;padding-inline:0!important}'
            . $header . ' :is(.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container){'
            . 'height:var(--awa-header-main-row-h,68px)!important;'
            . 'min-height:var(--awa-header-main-row-h,68px)!important;'
            . 'max-height:var(--awa-header-main-row-h,68px)!important;'
            . 'width:100%!important;max-width:100%!important;padding:0!important;margin:0!important}'
            . $header . ' .header.awa-main-header :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            . 'display:grid!important;grid-template-columns:160px minmax(0,1fr) 316px!important;'
            . 'column-gap:16px!important;align-items:center!important;'
            . 'min-height:var(--awa-header-main-row-h,68px)!important;'
            . 'height:var(--awa-header-main-row-h,68px)!important;'
            . 'max-height:var(--awa-header-main-row-h,68px)!important;'
            . 'width:100%!important;max-width:1280px!important;margin-inline:auto!important;'
            . 'padding-inline:0!important;padding-block:0!important}'
            . $header . ' .awa-header-search-col{'
            . 'padding:0!important;min-height:44px!important;height:44px!important;max-height:44px!important;'
            . 'display:flex!important;align-items:center!important}'
            . $header . ' .awa-header-search-col :is(.block-search,#search_mini_form,form.minisearch){'
            . 'width:100%!important;max-width:none!important}'
            . $header . ' .awa-header-search-col .block-search{'
            . 'min-height:44px!important;height:44px!important;max-height:44px!important}'
            . $header . ' .awa-header-search-col .block-content{'
            . 'padding:0!important;padding-inline:0!important;width:100%!important;max-width:none!important}'
            . $header . ' form#search_mini_form{'
            . 'display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;'
            . 'min-height:44px!important;height:44px!important;max-height:44px!important;'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important;overflow:hidden!important}'
            . $header . ' .awa-header-search-col .block-search form#search_mini_form{'
            . 'max-width:none!important;width:100%!important;margin-inline:0!important}'
            . $header . ' #header .awa-header-search-col form#search_mini_form,'
            . $header . ' .header-wrapper-sticky .awa-header-search-col form#search_mini_form{'
            . 'max-width:none!important;width:100%!important;margin-left:0!important;margin-right:0!important;'
            . 'justify-self:stretch!important;place-self:stretch!important}'
            . $header . ' form#search_mini_form .control{'
            . 'min-height:44px!important;height:44px!important;max-height:44px!important}'
            . $header . ' :is(.awa-header-brand-cell,.awa-header-brand-cell .logo,.page-header .logo){'
            . 'width:160px!important;min-width:160px!important;max-width:160px!important}'
            . $header . ' :is(.awa-header-brand-cell .logo img,.page-header .logo img){'
            . 'width:126px!important;max-width:126px!important;max-height:44px!important;height:auto!important;object-fit:contain!important}'
            . $header . ' :is(.awa-header-right-col,.page-header .header.links,.page-header .customer-links,.page-header .header-account,.page-header .login-area,.page-header .account-wrapper){'
            . 'width:316px!important;min-width:316px!important;max-width:316px!important;'
            . 'display:inline-flex!important;align-items:center!important;justify-content:flex-end!important;gap:12px!important}'
            . $header . ' :is(.awa-header-account-prompt,.awa-header-contact-links.awa-header-account-prompt,.page-header .authorization-link,.page-header .customer-welcome,.page-header .header-account .account-link,.page-header .login-area .authorization-link,.page-header .b2b-login-box){'
            . 'max-width:266px!important;min-height:44px!important;height:44px!important;margin:0!important;padding-inline:10px!important}'
            . $header . ' :is(.awa-header-minicart,.awa-header-minicart .mini-carts,.awa-header-minicart .minicart-wrapper,.page-header .minicart-wrapper,.page-header .minicart-wrapper .action.showcart,.awa-header-minicart .action.showcart,.awa-header-minicart .showcart.header-mini-cart){'
            . 'width:44px!important;min-width:44px!important;max-width:44px!important;'
            . 'height:44px!important;min-height:44px!important;max-height:44px!important;'
            . 'margin:0!important;padding:0!important;justify-self:end!important;align-self:center!important}'
            // Nav: full-bleed (fundo 100vw). Inner = rail 1280 alinhado ao header.
            . $header . ' :is(.header-wrapper-sticky > .header-control.awa-nav-bar,.header-wrapper-sticky > .header-control.header-nav.awa-nav-bar,.header-control.awa-nav-bar,.header-control.header-nav.awa-nav-bar){'
            . 'height:var(--awa-header-nav-h,48px)!important;'
            . 'min-height:var(--awa-header-nav-h,48px)!important;'
            . 'max-height:var(--awa-header-nav-h,48px)!important;'
            . 'display:block!important;width:100%!important;max-width:none!important;'
            . 'margin-inline:0!important;margin-block:6px 0!important;padding-inline:0!important;box-sizing:border-box!important}'
            . $header . ' :is(.header-control.awa-nav-bar > .container,.header-control.header-nav.awa-nav-bar > .container){'
            . 'height:var(--awa-header-nav-h,48px)!important;'
            . 'min-height:var(--awa-header-nav-h,48px)!important;'
            . 'max-height:var(--awa-header-nav-h,48px)!important;'
            . 'width:100%!important;max-width:none!important;'
            . 'margin-inline:0!important;padding-inline:0!important;box-sizing:border-box!important}'
            . $header . ' :is(.header-control.awa-nav-bar .awa-nav-bar__inner,.header-control.header-nav.awa-nav-bar .awa-nav-bar__inner){'
            . 'height:var(--awa-header-nav-h,48px)!important;'
            . 'min-height:var(--awa-header-nav-h,48px)!important;'
            . 'max-height:var(--awa-header-nav-h,48px)!important;'
            . 'width:min(100%,1280px)!important;max-width:1280px!important;'
            . 'margin-inline:auto!important;padding-inline:24px!important;box-sizing:border-box!important}'
            // Busca: esconder label/lupa default do Magento (evita lupa duplicada à esquerda do placeholder).
            . $header . ' .awa-header-search-col :is(.block-search .label,.block-search .block-title,.nested,label.search,label[for="search"]){'
            . 'display:none!important;visibility:hidden!important;width:0!important;height:0!important;max-height:0!important;'
            . 'overflow:hidden!important;pointer-events:none!important;position:absolute!important;clip:rect(0,0,0,0)!important}'
            // Busca: input deve preencher os 44px de altura do controle (PLP herdava 36px).
            . $header . ' form#search_mini_form :is(input#search,.control input,input[type="text"]){'
            . 'min-height:44px!important;height:44px!important;max-height:44px!important;box-sizing:border-box!important}'
            // Busca: o botão precisa ser position:relative para conter seus pseudos absolutos (evita ancorar no form).
            . $header . ' form#search_mini_form :is(.actions,.actions button.action.search,button.action.search){'
            . 'position:relative!important}'
            // Busca: suprimir a lupa desenhada via ::before/::after (círculo+cabo) do botão, mantendo só o SVG inline.
            // Isso elimina a lupa duplicada à direita e a lupa deslocada à esquerda (quando o botão era position:static).
            . $header . ' form#search_mini_form button.action.search::before,'
            . $header . ' form#search_mini_form button.action.search::after,'
            . $header . ' form#search_mini_form .actions button.action.search::before,'
            . $header . ' form#search_mini_form .actions button.action.search::after{'
            . 'content:none!important;display:none!important;border:0!important;background:none!important}'
            . $header . ' form#search_mini_form button.action.search svg{'
            . 'display:block!important;margin:0 auto!important}'
            // Departamentos: suprimir o ícone-fonte (::before/::after) do tema, mantendo só o SVG inline (evita hambúrguer duplicado).
            . $header . ' :is(.our_categories.title-category-dropdown,[data-role="awa-vertical-menu-trigger"]) :is(.icon-menu,.vm-icon,.awa-vmenu-trigger-icon)::before,'
            . $header . ' :is(.our_categories.title-category-dropdown,[data-role="awa-vertical-menu-trigger"]) :is(.icon-menu,.vm-icon,.awa-vmenu-trigger-icon)::after{'
            . 'content:none!important;display:none!important;background:none!important}'
            . $header . ' :is(.our_categories.title-category-dropdown,[data-role="awa-vertical-menu-trigger"]) :is(.icon-menu,.vm-icon,.awa-vmenu-trigger-icon) svg{'
            . 'display:block!important;width:18px!important;height:18px!important}'
            // Carrinho: na PDP uma regra de .catalog-product-view forçava o path do SVG (color/stroke vermelho) — some no fundo vermelho.
            // Restaurar o ícone de contorno branco explícito (não currentColor, pois o color do path é sobrescrito p/ vermelho na PDP).
            . $header . ' :is(.awa-header-minicart,.minicart-wrapper) .action.showcart svg,'
            . $header . ' :is(.awa-header-minicart,.minicart-wrapper) .action.showcart svg :is(path,polyline,line,circle,rect,ellipse){'
            . 'color:var(--awa-text-inverse,#fff)!important;fill:none!important;stroke:var(--awa-text-inverse,#fff)!important}'
            . '}';
    }

    public static function injectBeforeBodyClose(string $html): string
    {
        $html = self::stripLegacyFromHtml($html);
        $html = preg_replace(
            '/<script\\s+id="' . preg_quote(self::GUARD_SCRIPT_ID, '/') . '"[^>]*>.*?<\\/script>\\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script\\s+id="awa-catalog-root-height-lock"[^>]*>.*?<\\/script>\\s*/is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/<script\\s+id="' . preg_quote(self::HEADER_NAV_AXIS_LOCK_SCRIPT_ID, '/') . '"[^>]*>.*?<\\/script>\\s*/is',
            '',
            $html
        ) ?? $html;

        $pos = stripos($html, '</body>');
        if ($pos === false) {
            return $html . "\n" . self::footerInjection();
        }

        return substr($html, 0, $pos) . self::footerInjection() . "\n" . substr($html, $pos);
    }

    public static function isPresentInHtml(string $html): bool
    {
        if (!str_contains($html, self::STYLE_ID)) {
            return false;
        }

        return str_contains($html, 'max-height:none!important')
            && str_contains($html, 'cubic-bezier(.22,1,.36,1)')
            && str_contains($html, 'min-height:68px!important')
            && str_contains($html, 'grid-template-areas:"toggle brand cart"')
            && str_contains($html, 'max-height:96px!important')
            && str_contains($html, '.header-control.awa-nav-bar')
            && str_contains($html, 'box-shadow:0 4px 12px rgb(15 23 42/10%)')
            && str_contains($html, 'oklch(45% .02 20)!important')
            && str_contains($html, 'background:oklch(99% .002 20)!important')
            && str_contains($html, 'subchildmenu.navigation__inner-list{padding-right:12px!important')
            && str_contains($html, 'oklch(98.5% .004 20)!important')
            && str_contains($html, 'b2b-dashboard-lazy-panel[data-lazy-loaded="loading"]')
            && str_contains($html, '--awa-header-polish-ease:cubic-bezier(.22,1,.36,1)')
            && str_contains($html, '.counter.qty .counter-number')
            && str_contains($html, 'font-weight:650!important')
            && str_contains($html, 'awa-header-primary-nav.menu_primary[data-awa-topnav-empty="1"]')
            && str_contains($html, 'awa-header-primary-nav.menu_primary:has(nav.top-menu:empty)')
            && str_contains($html, 'margin-inline:calc(50% - 50vw)')
            && str_contains($html, 'awa-header-account-prompt__line1')
            && str_contains($html, 'border-radius:10px!important')
            && str_contains($html, '--awa-header-control-h:44px')
            && str_contains($html, '.awa-vmenu-search-icon svg')
            && str_contains($html, 'data-awa-is-home="1"]')
            && str_contains($html, '.awa-search-helper-copy')
            && str_contains($html, 'grid-area:search!important;grid-column:1/-1!important')
            && str_contains($html, 'applyMobileGrid')
            && str_contains($html, ':is(.navigation__submenu,.subchildmenu){width:0!important')
            && str_contains($html, 'contain:layout!important}');
    }

    public static function stripLegacyFromHtml(string $html): string
    {
        foreach (self::LEGACY_STYLE_IDS as $legacyId) {
            $pattern = '/<style\\s+id="' . preg_quote($legacyId, '/') . '"[^>]*>.*?<\\/style>\\s*/is';
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        $pattern = '/<style\\s+id="' . preg_quote(self::STYLE_ID, '/') . '"[^>]*>.*?<\\/style>\\s*/is';
        $html = preg_replace($pattern, '', $html) ?? $html;
        $html = preg_replace(
            '/<script\\s+id="' . preg_quote(self::GUARD_SCRIPT_ID, '/') . '"[^>]*>.*?<\\/script>\\s*/is',
            '',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Remove apenas style tags de versões depreciadas (v11–v17).
     * Preserva o cascade lock ativo (STYLE_ID = v18) para manter o header estável.
     * Usar em páginas de catálogo onde stripLegacyFromHtml causaria regressão visual.
     */
    public static function stripDeprecatedOnlyFromHtml(string $html): string
    {
        foreach (self::LEGACY_STYLE_IDS as $legacyId) {
            $pattern = '/<style\\s+id="' . preg_quote($legacyId, '/') . '"[^>]*>.*?<\\/style>\\s*/is';
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        return $html;
    }
}
