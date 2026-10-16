/**
 * AWA — CSS Interaction Gate
 *
 * Aplica links CSS com data-awa-gate="1" (media="print" → "all")
 * e injeta fila #awa-css-gate-queue de forma progressiva.
 *
 * Home: cosmético somente por intenção real para preservar TBT/LCP.
 * Interação acelera a fila; clique em área vazia não dispara o gate.
 * Outras rotas: fallback pós-load curto.
 */
(function () {
    'use strict';

    var CSS_GATE_ATTR = 'data-awa-gate';
    var applied = false;
    var homePostGateFixPending = false;
    var hasMeaningfulGateInteraction = false;
    var GATE_EVENTS = ['pointerdown', 'keydown', 'touchstart'];
    var QUEUE_WATCHDOG_MS = 30000;
    var MOBILE_FALLBACK_DELAY_MS = 1200;
    var DESKTOP_FALLBACK_DELAY_MS = 1200;
    var HOME_INTERACTION_FALLBACK_MS = 1000; /* was 20000; idle/rAF hard-cap 1s (2026-09-02 round2) */
    var HOME_STRUCTURAL_FALLBACK_MS = 1200; /* SSOT home-gate: align-grid estrutural cedo; pesados no idle/rAF 1s */
    var HOME_STYLES_M_DELAY_MS = 3200; /* styles-m pesado — após LCP, antes do gate completo */
    var HOME_LCP_GATE_FALLBACK_MS = 12000; /* era 2800 — alinhava styles-l e estourava TBT no desktop lab */
    var QUEUE_STAGGER_MS = 50;
    var QUEUE_HEAVY_GAP_MS = 120; /* opt17: gap entre bundles LOAD_LAST (evita parse paralelo) */
    var QUEUE_STYLES_M_FRAGMENT = 'styles-m.css';
    var HOME_AUTO_FALLBACK_ENABLED = true;
    /* awa-home-standardize-terminal-wins: aposentado (LESS _awa-visual-consolidation). Não injetar. */
    var ALIGN_GRID_TERMINAL_FRAGMENT = 'awa-align-grid-terminal-2026-06-11';
    var AWA_CSS_GATE_DEBUG_VERSION = '20260711-header-geometry-v4';
    var QUEUE_HEADER_FIRST_FRAGMENTS = [
        'awa-third-party-bundle',
        /* awa-carousel-bundle + awa-shelf-carousel: async imediato na home — vitrine é conteúdo primário */
        /* header-stack + header-refine-terminal: migrados para styles-l (_extend.less 43.01/43.02) */
        /* impeccable-refine: stripped on cms_index_index (overflow-x:clip vs SEÇÃO 53) */
    ];
    /* Home: pesados por último (parse sequencial; LCP já coberto por critical/inline). */
    var QUEUE_HOME_LOAD_LAST_FRAGMENTS = [
        'awa-align-grid-terminal-2026-06-11'
    ];

    function injectHomeFooterOverflowGuard() {
        var style;

        style = document.getElementById('awa-post-audit-footer-overflow-fix');
        if (!style) {
            style = document.createElement('style');
            style.id = 'awa-post-audit-footer-overflow-fix';
            style.textContent = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is(.page_footer,.page-footer) .footer-bottom{width:100%!important;max-width:100%!important;margin-inline:0!important;box-sizing:border-box!important}';
            (document.head || document.documentElement).appendChild(style);
        }

        if (document.getElementById('awa-footer-categories-desktop-lock') || !window.matchMedia('(min-width: 768px)').matches) {
            return;
        }

        style = document.createElement('style');
        style.id = 'awa-footer-categories-desktop-lock';
        style.textContent = '@media (min-width:768px){html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) section.awa-footer-categories-expand .awa-footer-categories-expand__inner{display:grid!important;grid-template-columns:minmax(96px,max-content) minmax(0,1fr)!important;align-items:center!important;column-gap:clamp(14px,2vw,28px)!important;row-gap:10px!important;height:auto!important;min-height:0!important}html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) section.awa-footer-categories-expand .awa-footer-categories-expand__toggle{display:none!important;visibility:hidden!important;pointer-events:none!important}html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) section.awa-footer-categories-expand .awa-footer-categories-expand__heading{grid-column:1!important;grid-row:1!important}html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) section.awa-footer-categories-expand .awa-footer-categories-expand__panel{grid-column:2!important;grid-row:1!important;display:block!important;max-height:none!important;height:auto!important;visibility:visible!important}html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) ul.awa-footer-categories-list{display:flex!important;flex-wrap:wrap!important;grid-template-columns:none!important;gap:4px 16px!important;width:100%!important;height:auto!important}html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) ul.awa-footer-categories-list a{border-radius:999px!important}}';
        (document.head || document.documentElement).appendChild(style);
    }

    function isMobileViewport() {
        return window.matchMedia('(max-width: 767px)').matches;
    }

    function isMeaningfulIntent(event) {
        var target;

        if (!event) {
            return false;
        }

        if (event.type === 'keydown') {
            return event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar';
        }

        target = event.target;
        if (target && target.classList && (
            target.classList.contains('awa-nav-bar')
            || target.classList.contains('header-nav-global')
        )) {
            return true;
        }
        if (target && target.closest && target.closest(
            '[data-awa-header-nav="true"], .header-control.header-nav.header-nav-global, .header-control.awa-nav-bar'
        )) {
            return true;
        }

        return !!(target && target.closest && target.closest(
            'a, button, input, select, textarea, label, summary, [role="button"], [role="link"], ' +
            '.minicart-wrapper, .awa-header-account-prompt, #search_mini_form, .awa-hero-swiper__nav, ' +
            '.swiper-pagination-bullet, .awa-category-carousel__item, .product-item, .item-product, ' +
            '.awa-nav-bar, [data-awa-header-nav="true"], [data-role="awa-vertical-menu"], ' +
            '[data-role="awa-vertical-menu-trigger"], [data-role="awa-vertical-menu-panel"], ' +
            '.navigation.verticalmenu.side-verticalmenu'
        ));
    }

    function isHomePage() {
        return !!(document.body && (
            document.body.classList.contains('cms-index-index') ||
            document.body.classList.contains('cms-home') ||
            document.body.classList.contains('cms-homepage_ayo_home5')
        ));
    }

    function onGateInteraction(event) {
        if (isHomePage() && !isMeaningfulIntent(event)) {
            return;
        }

        hasMeaningfulGateInteraction = true;

        if (applied) {
            if (homePostGateFixPending) {
                injectPostGateHeaderFix();
                injectHeaderVtexFinalLock();
                window.setTimeout(function () {
                    injectHeaderVtexFinalLock();
                }, 800);
                window.setTimeout(function () {
                    injectHeaderVtexFinalLock();
                }, 1800);
                injectHomeCarouselCardTerminal();
                injectHeaderMobileGridTerminal();
                homePostGateFixPending = false;
            }
            return;
        }

        applyGatedCSS('interaction');
    }

    function isHeavyQueueFragment(url) {
        var j;
        var frag;

        if (!url) {
            return false;
        }

        for (j = 0; j < QUEUE_HOME_LOAD_LAST_FRAGMENTS.length; j += 1) {
            frag = QUEUE_HOME_LOAD_LAST_FRAGMENTS[j];
            if (url.indexOf(frag) !== -1) {
                return true;
            }
        }

        return url.indexOf(QUEUE_STYLES_M_FRAGMENT) !== -1;
    }

    /**
     * Evita 2º fetch quando early-inject (ou outro link) já trouxe o mesmo CSS
     * com query/buster diferente do item da fila.
     */
    function isStylesheetAlreadyPresent(url) {
        var links;
        var i;
        var href;
        var file;
        var m;

        if (!url) {
            return true;
        }

        if (document.querySelector('link[href="' + url + '"]')) {
            return true;
        }

        if (
            url.indexOf(ALIGN_GRID_TERMINAL_FRAGMENT) !== -1
            && document.querySelector(
                'link[data-awa-align-grid-terminal="1"], link[href*="' + ALIGN_GRID_TERMINAL_FRAGMENT + '"]'
            )
        ) {
            return true;
        }

        m = url.match(/\/([^\/?]+\.css)/);
        file = m ? m[1] : '';
        if (!file) {
            return false;
        }

        links = document.querySelectorAll('link[rel="stylesheet"], link[rel="preload"][as="style"]');
        for (i = 0; i < links.length; i += 1) {
            href = links[i].href || '';
            if (href.indexOf('/' + file) !== -1) {
                return true;
            }
        }

        return false;
    }

    function injectQueuedStylesheets() {
        return new Promise(function (resolve) {
            var node = document.getElementById('awa-css-gate-queue');
            var urls;
            var i;
            var link;
            var pending = 0;
            var urlsToLoad = [];
            var resolved = false;
            var watchdogId;

            function finishQueue() {
                if (resolved) {
                    return;
                }
                resolved = true;
                if (watchdogId) {
                    window.clearTimeout(watchdogId);
                }
                resolve();
            }

            if (!node || !node.textContent) {
                finishQueue();
                return;
            }

            try {
                urls = JSON.parse(node.textContent);
            } catch (e) {
                finishQueue();
                return;
            }

            if (!Array.isArray(urls)) {
                finishQueue();
                return;
            }

            urls.sort(function (a, b) {
                var aPri = 0;
                var bPri = 0;
                var aLast = 0;
                var bLast = 0;
                var j;
                var frag;

                for (j = 0; j < QUEUE_HEADER_FIRST_FRAGMENTS.length; j += 1) {
                    frag = QUEUE_HEADER_FIRST_FRAGMENTS[j];
                    if (a.indexOf(frag) !== -1) {
                        aPri = j + 1;
                    }
                    if (b.indexOf(frag) !== -1) {
                        bPri = j + 1;
                    }
                }

                if (aPri && !bPri) {
                    return -1;
                }
                if (!aPri && bPri) {
                    return 1;
                }
                if (aPri && bPri) {
                    return aPri - bPri;
                }

                for (j = 0; j < QUEUE_HOME_LOAD_LAST_FRAGMENTS.length; j += 1) {
                    frag = QUEUE_HOME_LOAD_LAST_FRAGMENTS[j];
                    if (a.indexOf(frag) !== -1) {
                        aLast = j + 1;
                    }
                    if (b.indexOf(frag) !== -1) {
                        bLast = j + 1;
                    }
                }

                if (aLast && !bLast) {
                    return 1;
                }
                if (!aLast && bLast) {
                    return -1;
                }
                if (aLast && bLast) {
                    return aLast - bLast;
                }

                return 0;
            });

            for (i = 0; i < urls.length; i += 1) {
                if (!urls[i] || isStylesheetAlreadyPresent(urls[i])) {
                    continue;
                }
                if (isHomePage() && urls[i].indexOf('awa-commerce-impeccable-refine') !== -1) {
                    continue;
                }
                urlsToLoad.push(urls[i]);
            }

            /* Home: styles-m (~5MB) por último — reduz TBT/Speed Index no lab sem atrasar LCP */
            if (document.body && document.body.classList.contains('cms-index-index')) {
                var stylesMUrls = [];
                var otherUrls = [];
                for (i = 0; i < urlsToLoad.length; i += 1) {
                    if (urlsToLoad[i].indexOf(QUEUE_STYLES_M_FRAGMENT) !== -1) {
                        stylesMUrls.push(urlsToLoad[i]);
                    } else {
                        otherUrls.push(urlsToLoad[i]);
                    }
                }
                urlsToLoad = otherUrls.concat(stylesMUrls);
            }

            if (urlsToLoad.length === 0) {
                finishQueue();
                return;
            }

            pending = urlsToLoad.length;
            watchdogId = window.setTimeout(finishQueue, QUEUE_WATCHDOG_MS);

            function onQueueSheetDone() {
                pending -= 1;
                if (pending <= 0) {
                    finishQueue();
                }
            }

            function appendQueuedSheet(url, done) {
                link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = url;
                link.fetchPriority = 'low';
                link.onload = done;
                link.onerror = done;
                document.head.appendChild(link);
            }

            var isHomeGate = document.body && document.body.classList.contains('cms-index-index');
            var lightUrls = [];
            var heavyUrls = [];

            for (i = 0; i < urlsToLoad.length; i += 1) {
                if (isHeavyQueueFragment(urlsToLoad[i])) {
                    heavyUrls.push(urlsToLoad[i]);
                } else {
                    lightUrls.push(urlsToLoad[i]);
                }
            }

            for (i = 0; i < lightUrls.length; i += 1) {
                window.setTimeout(
                    appendQueuedSheet.bind(null, lightUrls[i], onQueueSheetDone),
                    i * QUEUE_STAGGER_MS
                );
            }

            /* opt17: bundles pesados em série — evita travamento do main thread */
            (function loadHeavySequential(index) {
                var url;
                var stylesMDelay;
                var run;

                if (index >= heavyUrls.length) {
                    return;
                }

                url = heavyUrls[index];
                stylesMDelay = (isHomeGate && url.indexOf(QUEUE_STYLES_M_FRAGMENT) !== -1)
                    ? HOME_STYLES_M_DELAY_MS
                    : 0;

                run = function () {
                    appendQueuedSheet(url, function () {
                        window.setTimeout(function () {
                            loadHeavySequential(index + 1);
                        }, QUEUE_HEAVY_GAP_MS);
                        onQueueSheetDone();
                    });
                };

                if (stylesMDelay > 0) {
                    window.setTimeout(run, stylesMDelay);
                } else {
                    run();
                }
            }(0));
        });
    }

    function normalizeTerminalHref(href) {
        return href || '';
    }

    /** Header refine terminal — migrado para styles-l via _extend.less (import 43.02). */
    function injectHeaderRefineTerminal() {
        return Promise.resolve();
    }

    /** Impeccable refine — reinjeta após fila gate (vence polish-type / layout-bundle). */
    function injectImpeccableRefineTerminal() {
        return new Promise(function (resolve) {
            var probe = document.querySelector('link[data-awa-impeccable-terminal="refine"]');
            var href;
            var link;

            if (document.querySelector('link[data-awa-impeccable-refine-terminal="1"]')) {
                resolve();
                return;
            }

            href = '';
            if (probe && probe.href) {
                href = probe.href;
            } else {
                probe = document.querySelector('link[href*="awa-commerce-impeccable-refine"]');
                if (probe && probe.href) {
                    href = probe.href;
                }
            }

            if (!href) {
                resolve();
                return;
            }

            link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.media = 'all';
            link.setAttribute('data-awa-impeccable-refine-terminal', '1');
            link.onload = resolve;
            link.onerror = resolve;
            document.body.appendChild(link);
        });
    }

    function resolveAlignGridTerminalHref() {
        var probe = document.querySelector('link[href*="' + ALIGN_GRID_TERMINAL_FRAGMENT + '"]');
        var node;
        var urls;
        var i;

        if (probe && probe.href) {
            return probe.href;
        }

        node = document.getElementById('awa-css-gate-queue');
        if (node && node.textContent) {
            try {
                urls = JSON.parse(node.textContent);
                for (i = 0; i < urls.length; i += 1) {
                    if (urls[i].indexOf(ALIGN_GRID_TERMINAL_FRAGMENT) !== -1) {
                        return urls[i];
                    }
                }
            } catch (e) { /* noop */ }
        }

        probe = document.querySelector('link[href*="/static/"][href*="/css/"]');
        if (probe && probe.href) {
            return probe.href.replace(/\/css\/[^/?]+(\?[^/?]*)?$/, '/css/' + ALIGN_GRID_TERMINAL_FRAGMENT + '.min.css?v=20260618-postaudit2');
        }

        return '';
    }

    function injectAlignGridTerminal() {
        return new Promise(function (resolve) {
            var href;
            var link;

            if (document.querySelector(
                'link[data-awa-align-grid-terminal="1"],' +
                'link[href*="' + ALIGN_GRID_TERMINAL_FRAGMENT + '"]'
            )) {
                resolve();
                return;
            }

            href = resolveAlignGridTerminalHref();
            if (!href) {
                resolve();
                return;
            }

            link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.media = 'all';
            link.setAttribute('data-awa-align-grid-terminal', '1');
            link.onload = resolve;
            link.onerror = resolve;
            document.body.appendChild(link);
        });
    }

    /** Impeccable audit — no fim do body (vence fila reinjetada no head). */
    function injectImpeccableAuditTerminal() {
        return new Promise(function (resolve) {
            var href;
            var link;
            var probe = document.querySelector(
                'link[data-awa-impeccable-terminal="1"],' +
                'link[data-awa-impeccable-terminal="final"]'
            );

            if (probe) {
                resolve();
                return;
            }

            href = '';
            probe = document.querySelector('link[href*="awa-impeccable-audit-2026-05-28"]');
            if (probe && probe.href) {
                href = probe.href;
            } else {
                probe = document.querySelector('link[href*="awa-header-refine-terminal"]');
                if (probe && probe.href) {
                    href = probe.href.replace(/[^/]+$/, 'awa-impeccable-audit-2026-05-28.css');
                }
            }

            if (!href) {
                resolve();
                return;
            }

            link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.media = 'all';
            link.setAttribute('data-awa-impeccable-terminal', '1');
            link.onload = resolve;
            link.onerror = resolve;
            document.body.appendChild(link);
        });
    }

    /** Post-gate final-wins: CSS injetado no fim do body vence links do gate no head. */
    function getHomeStabilityCss() {
        return 'body#html-body.cms-index-index:not(.nav-open) .navigation.verticalmenu.side-verticalmenu>ul.togge-menu.list-category-dropdown:not(.vmm-open):not(.menu-open),body#html-body.cms-home:not(.nav-open) .navigation.verticalmenu.side-verticalmenu>ul.togge-menu.list-category-dropdown:not(.vmm-open):not(.menu-open),body#html-body.cms-homepage_ayo_home5:not(.nav-open) .navigation.verticalmenu.side-verticalmenu>ul.togge-menu.list-category-dropdown:not(.vmm-open):not(.menu-open){display:none!important;visibility:hidden!important;height:0!important;max-height:0!important;min-height:0!important;overflow:visible!important;margin:0!important;padding:0!important;border:0!important;pointer-events:none!important}body#html-body.cms-index-index .content-top-home a.awa-section-header__link,body#html-body.cms-index-index .content-top-home a.awa-shelf__view-all,body#html-body.cms-index-index .content-top-home a.awa-category-carousel__cta-link,body#html-body.cms-home .content-top-home a.awa-section-header__link,body#html-body.cms-home .content-top-home a.awa-shelf__view-all,body#html-body.cms-home .content-top-home a.awa-category-carousel__cta-link,body#html-body.cms-homepage_ayo_home5 .content-top-home a.awa-section-header__link,body#html-body.cms-homepage_ayo_home5 .content-top-home a.awa-shelf__view-all,body#html-body.cms-homepage_ayo_home5 .content-top-home a.awa-category-carousel__cta-link{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;min-height:44px!important;padding:8px 12px!important;border-radius:999px!important;box-sizing:border-box!important;text-decoration:none!important;white-space:nowrap!important;color:var(--awa-primary,#b73337)!important}body#html-body.cms-index-index .content-top-home .awa-shelf--carousel :is(img.product-image-photo[loading="lazy"],.product-thumb img[loading="lazy"]),body#html-body.cms-home .content-top-home .awa-shelf--carousel :is(img.product-image-photo[loading="lazy"],.product-thumb img[loading="lazy"]),body#html-body.cms-homepage_ayo_home5 .content-top-home .awa-shelf--carousel :is(img.product-image-photo[loading="lazy"],.product-thumb img[loading="lazy"]){opacity:1!important;visibility:visible!important}';
    }

    /** opt21: shell leve anti-CLS — aplica antes da fila CSS pesada; post-gate cosmético continua adiável. */
    function getHomeClsShellCss() {
        var home = 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper';

        return home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded){display:flex!important;flex-flow:row nowrap!important;overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important;width:100%!important;min-height:300px!important;max-height:300px!important;margin:0!important;padding:0!important;list-style:none!important}'
            + home + ' .awa-shelf--carousel .awa-carousel__viewport>:is(ul.owl.awa-carousel__track,.awa-carousel__track){max-block-size:none!important;overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}'
            + home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item{flex:0 0 50%!important;max-width:50%!important;box-sizing:border-box!important;list-style:none!important}'
            + home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+3){display:none!important}'
            + home + ' .content-top-home .product-thumb,' + home + ' .content-top-home .product-image-container{aspect-ratio:1/1!important;min-height:120px!important;display:block!important;width:100%!important;contain:layout!important}'
            + home + ' .content-top-home .product-thumb img,' + home + ' .item-product .product-image-photo{min-height:120px!important;width:100%!important;height:auto!important;aspect-ratio:1/1!important;object-fit:contain!important;opacity:1!important;visibility:visible!important}'
            + home + ' .awa-shelf--carousel :is(img.product-image-photo[loading="lazy"],.product-thumb img[loading="lazy"]){opacity:1!important;visibility:visible!important}'
            + home + ' .awa-category-carousel__icon svg{display:block!important;width:64px!important;height:64px!important;max-width:64px!important;max-height:64px!important}'
            + 'html body#html-body .awa-whatsapp-float{width:56px!important;height:56px!important;min-width:56px!important;min-height:56px!important;bottom:84px!important;z-index:10002!important}'
            + 'html body#html-body nav.fixed-bottom.hidden-sm{min-height:56px!important}'
            + '.awa-footer-pay-logos{display:flex!important;flex-wrap:wrap!important;gap:8px!important;align-items:center!important;min-height:34px!important}'
            + '.awa-pay-logo{min-width:40px!important;min-height:28px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;box-sizing:border-box!important;padding:3px 6px!important}'
            + '.awa-pay-logo img{display:block!important;object-fit:contain!important}'
            + '.awa-pay-logo img[width=\"46\"][height=\"28\"]{width:46px!important;height:28px!important;max-width:46px!important;max-height:28px!important}'
            + '.awa-pay-logo img[width=\"36\"][height=\"24\"]{width:36px!important;height:24px!important;max-width:36px!important;max-height:24px!important}'
            + 'html body#html-body .page-wrapper :not(.awa-site-header) .logo img{display:block!important;width:161px!important;height:auto!important;aspect-ratio:161/92!important;max-width:min(161px,36vw)!important;max-height:56px!important;object-fit:contain!important}'
            + '@media(min-width:992px){html body#html-body .awa-site-header .logo img{display:block!important;width:104px!important;height:44px!important;max-width:104px!important;max-height:44px!important;min-width:0!important;aspect-ratio:auto!important;object-fit:contain!important}}'
            + '@media(max-width:991px){html body#html-body .awa-site-header .logo img{display:block!important;width:auto!important;height:44px!important;max-width:min(120px,36vw)!important;max-height:44px!important;min-width:0!important;aspect-ratio:auto!important;object-fit:contain!important}}'
            + home + ' .awa-hero-b2b-cta ul.awa-hero-benefits{display:grid!important;gap:12px!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;list-style:none!important;margin:0!important;padding:0!important}'
            + '.b2b-login-to-see-price{display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;min-height:80px!important;background:var(--awa-bg-soft)!important;border:1px dashed var(--awa-border)!important;border-radius:8px!important;padding:12px!important;margin:8px 0!important}'
            + '.newsletter-section .block.newsletter .form.subscribe{display:flex!important;align-items:flex-start!important;gap:12px!important}'
            + '.newsletter-section .block.newsletter .form.subscribe .field.newsletter{flex:1!important;margin:0!important}'
            + '.newsletter-section .block.newsletter .form.subscribe input{height:44px!important;border-radius:8px!important;padding-inline:16px!important;border:1.5px solid var(--awa-border)!important}'
            + '.newsletter-section .block.newsletter .form.subscribe .action.subscribe{height:44px!important;padding:0 24px!important;border-radius:8px!important;background:var(--awa-primary)!important;color:#fff!important;font-weight:700!important}'
            + home + ' .awa-hero-benefits__item{display:flex!important;align-items:center!important;gap:8px!important;padding:12px!important;box-sizing:border-box!important}'
            + home + ' .awa-hero-benefits__icon{display:inline-grid!important;flex:0 0 32px!important;width:32px!important;height:32px!important;min-width:32px!important;min-height:32px!important;place-items:center!important}'
            + home + ' .awa-hero-benefits__icon svg{display:block!important;width:24px!important;height:24px!important;max-width:24px!important;max-height:24px!important}'
            + 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-bottom{min-height:120px!important;contain:layout style!important}'
            + 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-pay-sec{min-height:56px!important}'
            + '@media(min-width:768px){' + home + ' .awa-hero-b2b-cta ul.awa-hero-benefits{gap:16px!important;grid-template-columns:repeat(4,minmax(0,1fr))!important}' + home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item{flex:0 0 33.333%!important;max-width:33.333%!important}' + home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+3){display:flex!important}' + home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+4){display:none!important}}'
            + '@media(max-width:479px){' + home + ' .awa-hero-b2b-cta ul.awa-hero-benefits{grid-template-columns:1fr!important;gap:8px!important}}'
            + '@media(min-width:1024px){' + home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item{flex:0 0 25%!important;max-width:25%!important}' + home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+4){display:flex!important}' + home + ' .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+5){display:none!important}}'
            + '@media(max-width:991px){' + home + ' .awa-category-carousel__icon svg{width:52px!important;height:52px!important;max-width:52px!important;max-height:52px!important}}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is(#maincontent.page-main.container,#maincontent.page-main,main.page-main#maincontent){width:100%!important;max-width:none!important;margin-inline:0!important;padding-inline:0!important;box-sizing:border-box!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home{display:flex!important;flex-direction:column!important;width:100%!important;max-width:none!important;padding-inline:0!important;box-sizing:border-box!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home>.top-home-content--above-fold,html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home>.top-home-content--above-fold>.banner-slider.banner-slider2{width:100%!important;max-width:none!important;margin-inline:0!important;padding-inline:0!important;box-sizing:border-box!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home>:is(.ayo-home5-wrapper,.ayo-home5-wrapper--template-driven){width:min(100%,var(--awa-home-shell-max,1280px))!important;max-width:min(100%,var(--awa-home-shell-max,1280px))!important;margin-inline:auto!important;padding-inline:var(--awa-home-shell-gutter,24px)!important;box-sizing:border-box!important}';
    }

    function injectHomeClsShell() {
        var style;

        if (!isHomePage() || document.getElementById('awa-home-cls-shell')) {
            return;
        }

        style = document.createElement('style');
        style.id = 'awa-home-cls-shell';
        style.textContent = getHomeClsShellCss();
        document.body.appendChild(style);
    }

    function injectHomeStabilityFix(id) {
        var style;
        var isHome;

        isHome = document.body.classList.contains('cms-index-index') ||
            document.body.classList.contains('cms-home') ||
            document.body.classList.contains('cms-homepage_ayo_home5');

        if (!isHome) {
            return;
        }

        style = document.getElementById(id);
        if (!style) {
            style = document.createElement('style');
            style.id = id;
            document.body.appendChild(style);
        }

        style.textContent = getHomeStabilityCss();
    }

    function hasHeaderCascadeLock() {
        return !!(
            document.getElementById('awa-header-impeccable-cascade-lock-v18')
            || document.getElementById('awa-header-impeccable-cascade-lock-v17')
            || document.getElementById('awa-header-impeccable-cascade-lock-v16')
            || document.getElementById('awa-header-impeccable-cascade-lock-v15')
            || document.getElementById('awa-header-impeccable-cascade-lock-v14')
        );
    }

    function injectPostGateHeaderFix() {
        var style;
        var isHome;
        var css;

        if (hasHeaderCascadeLock()) {
            return;
        }

        isHome = isHomePage();

        css = '@media (min-width:992px){html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-header-categories .awa-nav-categories,html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-header-categories .sections.nav-sections.category-dropdown,html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-header-categories .section-items.nav-sections.category-dropdown-items,html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-header-categories .section-item-content.nav-sections.category-dropdown-item-content,html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-header-categories .navigation.verticalmenu.side-verticalmenu{background:transparent!important;height:48px!important;max-height:48px!important;min-height:0!important;overflow:visible!important;border:0!important;box-shadow:none!important;padding:0!important;margin:0!important}html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-header-categories button.our_categories.title-category-dropdown[data-role="awa-vertical-menu-trigger"],html body#html-body .page-wrapper .header-control.awa-nav-bar .menu_left_home1 button.our_categories.title-category-dropdown[data-role="awa-vertical-menu-trigger"]{border-radius:0!important;border:0!important;box-shadow:none!important;background:var(--awa-primary,#b73337)!important;color:#fff!important}html body#html-body .page-wrapper .header-control.awa-nav-bar .title-category-dropdown .vm-icon,html body#html-body .page-wrapper .header-control.awa-nav-bar .title-category-dropdown .icon-menu{background:transparent!important;border-radius:0!important;box-shadow:none!important}html body#html-body .page-wrapper .awa-site-header .awa-header-account-prompt .awa-header-account-prompt__line2{display:inline-flex!important;flex-wrap:nowrap!important;align-items:center!important;gap:4px!important;white-space:nowrap!important;min-height:0!important;height:auto!important}html body#html-body .page-wrapper .awa-site-header a.awa-header-account-prompt__link.awa-header-account-prompt__link--register{color:var(--awa-text,CanvasText)!important;background:transparent!important;background-color:transparent!important;border:none!important;border-radius:0!important;padding:0 2px!important;font-size:max(12px,0.75rem)!important;font-weight:700!important;white-space:nowrap!important;display:inline-flex!important;align-items:center!important;line-height:1.2!important;min-height:0!important;height:auto!important;box-shadow:none!important}html body#html-body .page-wrapper .awa-site-header .awa-header-account-prompt .awa-header-account-prompt__line1{font-size:max(12px,0.75rem)!important}html body#html-body .page-wrapper .awa-site-header form#search_mini_form.minisearch{height:44px!important;min-height:44px!important;max-height:44px!important;border:1px solid var(--awa-border,#e5e5e5)!important;border-radius:8px!important;box-shadow:none!important;overflow:hidden!important}html body#html-body .page-wrapper .awa-site-header a.action.showcart.header-mini-cart,html body#html-body .page-wrapper .awa-site-header .minicart-wrapper .action.showcart{overflow:visible!important;position:relative!important}}';

        css += '@media (prefers-reduced-motion:no-preference){#html-body .page-wrapper .header-wrapper-sticky,#html-body .page-wrapper .header-wrapper-sticky.is-sticky,#html-body .page-wrapper .awa-site-header .header-wrapper-sticky{transition:box-shadow .2s ease,opacity .2s ease,transform .2s ease!important}#html-body .page-wrapper .logo,#html-body .page-wrapper .awa-site-header .logo,#html-body .page-wrapper .logo img{transition:opacity .2s ease,transform .2s ease!important}}@media (prefers-reduced-motion:reduce){#html-body .page-wrapper .header-wrapper-sticky,#html-body .page-wrapper .logo,#html-body .page-wrapper .logo img{transition:none!important}}#html-body .page-wrapper .awa-header-account-prompt__guest .awa-header-account-prompt__line1,#html-body .page-wrapper .awa-benefit-desc{font-size:max(12px,.8125rem)!important;line-height:1.45!important}#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-copy strong{color:#333!important}#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-copy span{color:#666!important}#html-body .page-wrapper .awa-footer-business-contact__action,#html-body .page-wrapper .awa-footer-business-contact__action--primary{background:#fff!important;border:1px solid #e5e7eb!important;color:#111827!important}#html-body .page-wrapper .awa-footer-business-contact__action-copy strong,#html-body .page-wrapper .awa-footer-business-contact__action-copy small{color:#111827!important}html body#html-body .page-wrapper .page_footer .awa-footer-atendimento p.awa-footer-atendimento__label,html body#html-body .page-wrapper .page_footer .awa-footer-atendimento p.awa-footer-atendimento__label--social,html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__label,html body#html-body .page-wrapper .page_footer p.awa-footer-atendimento__label--social{color:oklch(45% .02 20)!important}html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store{background:oklch(99% .002 20)!important;border:1px solid oklch(92% .01 20)!important;padding:12px!important;border-radius:8px!important}html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store p.awa-footer-atendimento__store-name,html body#html-body .page-wrapper .page_footer .awa-footer-atendimento .awa-footer-atendimento__store p.awa-footer-atendimento__store-address{color:oklch(22% .01 20)!important}#html-body .page-wrapper .page_footer h3.awa-newsletter-title{color:#fff!important}html body#html-body .page-wrapper :is(.navigation.verticalmenu div[id^="submenu-menu-"],.navigation.verticalmenu .submenu.navigation__submenu){border:0!important;box-shadow:0 4px 12px rgb(15 23 42/10%)!important}html body#html-body .page-wrapper :is(#search_autocomplete,.mst-searchautocomplete__autocomplete){border:0!important;box-shadow:0 4px 16px rgb(15 23 42/12%)!important;overflow:visible!important}html body#html-body .page-wrapper :is(#awa-b2b-promo-bar,.awa-b2b-promo-bar) .awa-b2b-promo-close{color:#fff!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-section-header__eyebrow{display:none!important;visibility:hidden!important;height:0!important;width:0!important;overflow:hidden!important;margin:0!important;padding:0!important;border:0!important}html body#html-body :is(nav.fixed-bottom.hidden-sm,nav.fixed-bottom,.fixed-bottom,.awa-mobile-bottom-nav){border:0!important;border-top:0!important;box-shadow:0 -2px 8px rgb(15 23 42/8%)!important}html body#html-body .page-wrapper :is(#header .header-content,.header-container .header-content,.header-content){border:0!important;box-shadow:none!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-category-carousel__item .awa-category-carousel__icon{transition:transform .24s cubic-bezier(.22,1,.36,1)!important}html body#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-item .awa-footer-trust-copy strong{color:#333!important}html body#html-body .page-wrapper .awa-footer-trust-bar .awa-footer-trust-item .awa-footer-trust-copy span{color:#666!important}html body#html-body.b2b-register-index .page-wrapper #b2b-register-shell{background:oklch(98.5% .004 20)!important;border:0!important;box-shadow:none!important;padding:clamp(20px,4vw,34px)!important;border-radius:16px!important}html body#html-body.b2b-register-index .page-wrapper #b2b-register-shell :is(.b2b-register-container,.b2b-register-page){border:0!important;box-shadow:none!important;background:transparent!important;padding:0!important}html body#html-body.b2b-register-index .page-wrapper #b2b-register-shell .b2b-register-progress{border:0!important;background:transparent!important;box-shadow:none!important}html body#html-body.b2b-register-index .page-wrapper #b2b-register-shell .form-section{border:0!important;background:transparent!important;box-shadow:none!important}html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper{overflow-x:clip!important;overflow-y:visible!important}html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper .awa-skip-link:not(:focus):not(:focus-visible){left:0!important;width:1px!important;height:1px!important;clip-path:inset(50%)!important;overflow:hidden!important;margin:-1px!important}html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper :is(#awa-search-label,#awa-search-panel-a11y,.mst-searchautocomplete__autocomplete){overflow:visible!important}html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper li.ui-menu-item.navigation__item--parent{overflow:visible!important}html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper :is(.level0.submenu.navigation__submenu,.subchildmenu.navigation__inner-list){overflow:visible!important;padding-right:12px!important}html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper .navigation.custommenu.main-nav>li.ui-menu-item.navigation__item>a{padding-block:12px!important}html body#html-body:is(.b2b-auth-shell,.b2b-register-index) .page-wrapper form#search_mini_form.minisearch .actions{padding-inline-start:8px!important}html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns{display:grid!important;grid-template-columns:1fr!important;gap:8px!important;align-items:start!important}@media (min-width:768px){html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns{grid-template-columns:200px minmax(0,1fr)!important;gap:16px!important}html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns>.column.main{grid-column:2!important}}@media (min-width:1024px){html body#html-body.b2b-account-dashboard .page-wrapper .page-main>.columns{grid-template-columns:240px minmax(0,1fr)!important;gap:24px!important}}html body#html-body.b2b-account-dashboard .page-wrapper .summary-card{box-shadow:none!important}html body#html-body.b2b-account-dashboard .page-wrapper .b2b-dashboard-lazy-panel[data-lazy-loaded="error"],html body#html-body.b2b-account-dashboard .page-wrapper .b2b-dashboard-lazy-panel.b2b-dashboard-lazy-panel--error{border:0!important;background:transparent!important;padding:8px 0!important}';

        if (isHome) {
            css += 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded){display:flex!important;flex-flow:row nowrap!important;overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important;width:100%!important;min-height:300px!important;max-height:300px!important;margin:0!important;padding:0!important;list-style:none!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-shelf--carousel .awa-carousel__viewport>:is(ul.owl.awa-carousel__track,.awa-carousel__track){max-block-size:none!important;overflow:visible!important;overflow-x:visible!important;overflow-y:visible!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item{flex:0 0 50%!important;max-width:50%!important;box-sizing:border-box!important;list-style:none!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+3){display:none!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-nav-bar .velaFooterLinks,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .velaFooterLinks:not(.page-footer .velaFooterLinks),html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .header-control .velaFooterLinks{display:none!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .awa-account-dropdown__trigger:not([aria-expanded="true"])+.awa-account-dropdown__menu{display:none!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .item-product .product-image-photo,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .item-product .product-thumb img{min-height:120px!important;object-fit:contain!important;opacity:1!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-shelf--carousel :is(img.product-image-photo[loading="lazy"],.product-thumb img[loading="lazy"]){opacity:1!important;visibility:visible!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-shelf--carousel.awa-carousel-pending .awa-carousel__viewport{animation-iteration-count:3!important}@media(min-width:768px){html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item{flex:0 0 33.333%!important;max-width:33.333%!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+3){display:flex!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+4){display:none!important}}@media(min-width:1024px){html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item{flex:0 0 25%!important;max-width:25%!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+4){display:flex!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-carousel-section ul.owl.awa-carousel__track:not(.owl-carousel):not(.owl-loaded)>li.item:nth-child(n+5){display:none!important}}';
            css += getHomeStabilityCss();
            css += '@media(max-width:767px){html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-shelf--carousel>.awa-owl-nav,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-shelf--carousel .awa-carousel>.awa-owl-nav{display:flex!important;position:relative!important;transform:none!important;min-height:44px!important;height:auto!important;justify-content:flex-end!important;margin-block-start:8px!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-shelf--carousel .awa-owl-nav__btn,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-shelf--carousel .awa-owl-nav__btn.swiper-button-prev,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-shelf--carousel .awa-owl-nav__btn.swiper-button-next{position:relative!important;inset:auto!important;top:auto!important;left:auto!important;right:auto!important;inline-size:44px!important;block-size:44px!important;min-inline-size:44px!important;min-block-size:44px!important;transform:none!important;margin:0!important}}';
            css += '@media(max-width:767px){html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is(.awa-owl-nav__btn,.awa-carousel__arrow,.awa-carousel__toggle){inline-size:44px!important;block-size:44px!important;min-inline-size:44px!important;min-block-size:44px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}}';
            css += '@media (min-width:992px){html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .menu_left_home1 .verticalmenu.side-verticalmenu>ul.togge-menu.list-category-dropdown:is(.menu-open,.vmm-open,[aria-hidden="false"],[data-awa-menu-state="open"]){overflow:visible!important;overflow-x:visible!important;contain:layout style!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .menu_left_home1 .verticalmenu.side-verticalmenu>ul.togge-menu.list-category-dropdown:is(.menu-open,.vmm-open)>li.ui-menu-item.level0{overflow:visible!important}}';
        }

        /* Cascade-lock v12 no body: nav/mobile only — promo + impeccable surfaces live in PHP cascade-lock */
        if (!hasHeaderCascadeLock()) {
            css += '@media(max-width:767px){html body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky,html body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) #header .header-wrapper-sticky{height:96px!important;min-height:96px!important;max-height:96px!important;overflow:hidden!important;padding-block:0!important}html body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){display:grid!important;grid-template-areas:"toggle brand cart" "search search search"!important;grid-template-columns:44px minmax(0,1fr) 44px!important;grid-template-rows:44px 44px!important;gap:8px!important;padding:0 16px!important;max-height:96px!important;height:96px!important;overflow:hidden!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky :is(.awa-header-brand-cell,.col-md-2.awa-header-brand){grid-area:brand!important;align-self:center!important;max-height:44px!important}}@media (min-width:768px) and (max-width:991px){html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){display:grid!important;grid-template-areas:\"brand search actions\"!important;grid-template-columns:clamp(112px,14vw,148px) minmax(0,1fr) minmax(220px,max-content)!important;grid-template-rows:56px!important;gap:0 12px!important;padding:0 16px!important;max-height:56px!important;height:56px!important;min-height:56px!important;max-width:min(100%,1280px)!important;margin-inline:auto!important;overflow:visible!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky :is(.awa-header-mobile-toggle,.action.nav-toggle,[data-action="toggle-nav"]){display:none!important;visibility:hidden!important;pointer-events:none!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky .awa-header-primary-row{display:contents!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky .awa-header-right-col{display:inline-flex!important;grid-area:actions!important;gap:8px!important;align-items:center!important;justify-content:flex-end!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky .awa-header-right-col>:not(.awa-header-minicart):not(.awa-header-account-prompt){display:none!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky .awa-header-brand-cell{grid-area:brand!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky .awa-header-search-col{grid-area:search!important;grid-column:auto!important;min-width:0!important}}@media (min-width:992px){html body#html-body .page-wrapper .awa-site-header .awa-main-header__inner.wp-header,html body#html-body .page-wrapper .awa-site-header .awa-main-header__inner[data-awa-header-row]{display:grid!important;grid-template-columns:clamp(128px,12vw,168px) minmax(0,1fr) minmax(280px,max-content)!important;grid-template-areas:\"brand search actions\"!important;align-items:center!important;gap:0 12px!important;overflow:visible!important}html body#html-body .page-wrapper .awa-site-header .awa-main-header__inner.wp-header,html body#html-body .page-wrapper .awa-site-header .awa-main-header__inner[data-awa-header-row],html body#html-body .page-wrapper .awa-site-header .header.awa-main-header{min-height:64px!important;height:64px!important;max-height:64px!important;padding-block:0!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .awa-main-header__inner.wp-header,html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .awa-main-header__inner[data-awa-header-row]{display:grid!important;grid-template-columns:clamp(128px,12vw,168px) minmax(0,1fr) minmax(280px,max-content)!important;grid-template-areas:\"brand search actions\"!important;column-gap:12px!important}html body#html-body .page-wrapper .awa-site-header .awa-header-primary-row{display:contents!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .awa-header-brand-cell{grid-area:brand!important;grid-column:auto!important;max-width:168px!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .awa-header-search-col{grid-area:search!important;grid-column:auto!important;min-width:0!important;max-width:none!important}html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-site-header .awa-header-right-col{grid-area:actions!important;grid-column:auto!important;width:auto!important;max-width:none!important;justify-self:end!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky :is(.awa-header-brand-cell,.col-md-2.awa-header-brand){align-self:center!important;height:auto!important;min-height:0!important;max-height:56px!important}html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky,html body#html-body .page-wrapper .awa-site-header .header-wrapper-sticky.is-sticky,html body#html-body .page-wrapper #header .header-wrapper-sticky{height:auto!important;min-height:64px!important;max-height:64px!important;overflow:visible!important}html body#html-body .page-wrapper .header-control.header-nav.awa-nav-bar,html body#html-body .page-wrapper .header-control.awa-nav-bar,html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-nav-bar__inner,html body#html-body .page-wrapper .header-control.awa-nav-bar > .container{min-height:40px!important;max-height:40px!important;height:40px!important;box-sizing:border-box!important}html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar,html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar > .container,html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar .awa-nav-bar__inner{min-height:40px!important;max-height:40px!important;height:40px!important;box-sizing:border-box!important;padding-block:0!important;overflow:visible!important}html body#html-body .page-wrapper .header-control.awa-nav-bar .awa-nav-bar__inner,html body#html-body .page-wrapper .header-control.awa-nav-bar > .container > .row{display:flex!important;align-items:center!important;min-height:40px!important;height:40px!important}}';
        }

        style = document.getElementById('awa-header-post-gate-fix');
        if (!style) {
            style = document.createElement('style');
            style.id = 'awa-header-post-gate-fix';
            document.body.appendChild(style);
        }

        css += '@media (max-width:767px){html body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) .header-wrapper-sticky{height:auto!important;min-height:96px!important;max-height:none!important;overflow:visible!important;contain:none!important;padding-block:0!important}html body#html-body .page-wrapper .header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){overflow:visible!important}html body#html-body .page-wrapper .awa-site-header :is(.header.awa-main-header,.header_main.awa-main-header-inner-wrap,.awa-header-search-col,.awa-header-minicart,.minicart-wrapper){overflow:visible!important}}@media (min-width:768px) and (max-width:991px){html body#html-body .page-wrapper :is(.awa-site-header .header-wrapper-sticky,#header .header-wrapper-sticky){height:auto!important;min-height:56px!important;max-height:none!important;overflow:visible!important;contain:none!important;padding-block:0!important}}@media (min-width:992px){html body#html-body .page-wrapper :is(.awa-site-header .header-wrapper-sticky,#header .header-wrapper-sticky){height:auto!important;min-height:64px!important;max-height:64px!important;overflow:visible!important;contain:none!important;padding-block:0!important}}';

        /* §SEARCH-SSOT post-gate — última camada inline (vence density/S11/polish-type legado) */
        css += '@media (min-width:768px){html body#html-body:not(#__awa-no-match):not(.onepagecheckout-index-index):not(.checkout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) .awa-header-search-col :is(form#search_mini_form,form.minisearch,form.search-content){display:flex!important;align-items:stretch!important;height:44px!important;min-height:44px!important;max-height:44px!important;border:1px solid var(--awa-border,#e5e5e5)!important;border-radius:8px!important;overflow:hidden!important;box-shadow:none!important}html body#html-body:not(#__awa-no-match):not(.onepagecheckout-index-index):not(.checkout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) .awa-header-search-col :is(input#search,.input-text){height:44px!important;min-height:44px!important;max-height:44px!important;line-height:44px!important;font-size:14px!important;border:0!important;border-radius:0!important;box-shadow:none!important;background:transparent!important}html body#html-body:not(#__awa-no-match):not(.onepagecheckout-index-index):not(.checkout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) .awa-header-search-col :is(button.action.search,button.awa-search-btn,form#search_mini_form button.action.search,.actions .action.search,.block-search .action.search){width:44px!important;min-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;background-color:transparent!important;color:var(--awa-primary,#b73337)!important;box-shadow:none!important}html body#html-body:not(#__awa-no-match):not(.onepagecheckout-index-index):not(.checkout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) .awa-header-search-col :is(button.action.search,.actions .action.search) svg{stroke:var(--awa-primary,#b73337)!important;fill:none!important}}@media (max-width:767px){html body#html-body:not(#__awa-no-match):not(.onepagecheckout-index-index):not(.checkout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) .awa-header-search-col :is(form#search_mini_form,form.minisearch,form.search-content){display:grid!important;grid-template-columns:minmax(0,1fr) 44px!important;grid-template-areas:"field submit"!important;height:44px!important;min-height:44px!important;max-height:44px!important;border:1px solid var(--awa-border,#e5e5e5)!important;border-radius:8px!important;overflow:hidden!important}html body#html-body:not(#__awa-no-match):not(.onepagecheckout-index-index):not(.checkout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) .awa-header-search-col :is(input#search,.input-text){height:44px!important;min-height:44px!important;max-height:44px!important;line-height:44px!important;font-size:16px!important}html body#html-body:not(#__awa-no-match):not(.onepagecheckout-index-index):not(.checkout-index-index) .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) .awa-header-search-col :is(button.action.search,button.awa-search-btn,form#search_mini_form button.action.search,.actions .action.search,.block-search .action.search){width:44px!important;min-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important}}';

        /* §H41b — carousel card: uma caixa mensurável (vence density/impeccable pós-align-grid) */
        css += 'html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is(.awa-carousel-section,.content-top-home .awa-shelf--carousel) .item-product.awa-carousel-card-slot>.content-item-product.awa-product-card{display:contents!important;background:transparent!important;border:0!important;box-shadow:none!important;padding:0!important;margin:0!important;min-height:0!important}';

        /* §IMPECCABLE v10.47 — hero contrast/inset + footer labels (terminal pós-gate) */
        css += 'html body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer):not(.awa-footer--dark) .footer-container :is(p.awa-footer-atendimento__label,p.awa-footer-atendimento__label--social){color:#666!important}html body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5){--awa-home-shell-gutter:16px;--awa-home-pad-compact:8px;--awa-home-pad-standard:12px;--awa-home-pad-featured:16px;--awa-home-section-gap:12px}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .awa-shelf--carousel{padding-inline:0!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home :is(.awa-carousel-section,.top-home-content,.awa-home-recent-orders,.awa-grid-section,.awa-home-niche-shelves,.awa-hero-b2b-cta) > .container,html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .ayo-home5-wrapper--template-driven .container{padding-inline:0!important;margin-inline:0!important;width:100%!important;max-width:100%!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper :is(.page_footer,.page-footer) .awa-footer-newsletter{padding-inline:0!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .ayo-home5-wrapper--template-driven>.top-home-content.awa-home-section{padding-inline:0!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .top-home-content--category-carousel .awa-category-carousel__viewport{padding:0!important;margin-inline:0!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .top-home-content--category-carousel :is(.awa-category-carousel__track,#awa-cat-carousel.awa-category-carousel__track){padding:0!important;margin-inline:0!important;scroll-padding-inline:0!important;gap:8px!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .content-top-home .ayo-home5-wrapper--template-driven>.top-home-content.awa-home-section{padding-block:8px!important;margin-block:0!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .top-home-content--above-fold .banner-slider.banner-slider2{padding:clamp(8px,1.2vw,14px)!important;box-sizing:border-box!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .banner_item .text-banner:not(:empty){padding:clamp(16px,3vw,32px) clamp(16px,4vw,48px)!important;background:linear-gradient(to top,rgb(15 23 42 / 72%) 0%,rgb(15 23 42 / 28%) 55%,transparent 100%)!important;z-index:2!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .banner_item .text-banner :is(h2,.slide-title){color:#fff!important}html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .banner_item .text-banner :is(p,.slide-desc){color:rgb(248 250 252 / 92%)!important;background-color:transparent!important}';

        style.textContent = css;
    }

    /** §H41b — uma caixa por card; roda sempre (cascade-lock v14+ bloqueia post-gate header). */
    function injectHomeCarouselCardTerminal() {
        var style;
        var css;

        if (!isHomePage()) {
            return;
        }

        css = 'html body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + '.page-wrapper :is(.awa-carousel-section,.content-top-home .awa-shelf--carousel) '
            + '.item-product.awa-carousel-card-slot>.content-item-product.awa-product-card{'
            + 'display:contents!important;background:transparent!important;border:0!important;'
            + 'box-shadow:none!important;padding:0!important;margin:0!important;min-height:0!important}';

        style = document.getElementById('awa-home-carousel-card-terminal');
        if (!style) {
            style = document.createElement('style');
            style.id = 'awa-home-carousel-card-terminal';
            document.body.appendChild(style);
        }

        style.textContent = css;
    }

    /** §HPOL5-MOBILE — grid mobile do header; roda sempre (cascade-lock v14+ bloqueia post-gate parcial). */
    function injectHeaderMobileGridTerminal() {
        var style;
        var css;

        css = '@layer awa-visual-priority{@media(max-width:767px){'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            + ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            + 'display:grid!important;grid-template-columns:minmax(0,1fr)!important;'
            + 'grid-template-rows:44px 44px auto!important;grid-template-areas:"primary" "search" "actions"!important;'
            + 'gap:4px 8px!important;height:auto!important;min-height:0!important;max-height:none!important;'
            + 'padding:4px 12px 0!important;overflow:visible!important;box-sizing:border-box!important}'
            /* Autopilot 2026-07-03: a camada PHP awa-fixes transforma o header mobile em primary/search/actions;
               este lock mantém o primary-row como grid interno para evitar logo/menu/carrinho deslocados. */
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"]:not(.awa-header-condensed) '
            + '.awa-header-primary-row[data-awa-header-primary-row="true"]{display:grid!important;grid-area:primary!important;'
            + 'grid-template-columns:44px minmax(0,1fr) 44px!important;grid-template-areas:"toggle brand cart"!important;'
            + 'align-items:center!important;column-gap:8px!important;width:100%!important;min-width:0!important;'
            + 'min-height:44px!important;max-height:44px!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"].awa-header-condensed '
            + '.awa-header-primary-row[data-awa-header-primary-row="true"]{display:contents!important;grid-area:unset!important;'
            + 'grid-template:none!important;height:auto!important;min-height:0!important;max-height:none!important;width:auto!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"].awa-header-condensed '
            + '.header-wrapper-sticky{height:56px!important;min-height:56px!important;max-height:56px!important;overflow:hidden!important;'
            + 'position:fixed!important;top:0!important;left:0!important;right:0!important;width:100%!important;z-index:5001!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"].awa-header-condensed '
            + '.header-wrapper-sticky :is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){'
            + 'display:grid!important;grid-template:"toggle brand search cart" 44px/44px minmax(0,1fr) 44px 44px!important;'
            + 'height:56px!important;min-height:56px!important;max-height:56px!important;gap:0 8px!important;'
            + 'padding:6px 12px!important;box-sizing:border-box!important;align-items:center!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"].awa-header-condensed '
            + '.header-wrapper-sticky :is(.awa-header-search-col,.top-search){grid-area:search!important;display:flex!important;'
            + 'width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"] '
            + '.awa-header-primary-row .awa-header-mobile-toggle{grid-area:toggle!important;justify-self:start!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"] '
            + '.awa-header-primary-row > .col-md-2,'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"] '
            + '.awa-header-brand-cell[data-awa-header-brand="true"]{grid-area:brand!important;justify-self:center!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"] '
            + '.awa-header-primary-row .awa-header-cart-link{grid-area:cart!important;justify-self:end!important}'
            /* BUGFIX-B2B-PANEL-MOBILE-2026-07-08: esta regra (layer alta prioridade,
               vence !important normal via reversao de ordem de camadas) zerava o
               right-col inteiro no mobile — incluindo o painel B2B injetado por JS
               dentro dele (b2b-panel-hydrate.js/header-status-panel.js). Resultado:
               trigger com display:none e o "modal" do painel B2B nunca renderizava
               no mobile (confirmado via CDP getMatchedStylesForNode). Exceção via
               :has() para manter o comportamento legado quando o painel B2B não
               está presente. */
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"] '
            + '.awa-header-right-col[data-awa-header-right="true"]:not(:has(.b2b-status-panel)){display:none!important;visibility:hidden!important;'
            + 'width:0!important;min-width:0!important;max-width:0!important;height:0!important;min-height:0!important;'
            + 'max-height:0!important;overflow:hidden!important;pointer-events:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            + '.page-wrapper header.awa-site-header[data-awa-header-mode="default"] '
            + '.awa-header-right-col[data-awa-header-right="true"]:has(.b2b-status-panel){display:flex!important;visibility:visible!important;'
            + 'width:auto!important;min-width:0!important;max-width:min(220px,54vw)!important;height:44px!important;min-height:44px!important;'
            + 'max-height:44px!important;overflow:visible!important;pointer-events:auto!important;grid-area:actions!important;'
            + 'justify-self:end!important;align-self:center!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header:not(.awa-header-condensed) '
            + ':is(.header-wrapper-sticky,.header.awa-main-header){height:auto!important;min-height:0!important;'
            + 'max-height:none!important;padding-block:0!important;overflow:visible!important}'
            + 'html body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode="default"]{'
            + '--awa-header-main-row-h:auto!important}}}'
            /* AUDIT30-2026-07-16: unlayered (vence @layer) — painel B2B precisa da 3ª track >44px.
             * CDP: grid-template shorthand 44px na 3ª track + right-col overflow → overlap logo×trigger.
             * Reaplica shorthand + longhands FORA do @layer para ganhar da cascata layered. */
            + '@media(max-width:767px){'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            + '.page-wrapper header.awa-site-header[data-awa-header-mode="default"] '
            + '.header-wrapper-sticky:has(.b2b-status-panel) '
            + ':is(.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row="brand-search"]){'
            + 'grid-template:"toggle brand cart" auto "search search search" auto / 44px minmax(0,1fr) minmax(96px,auto)!important;'
            + 'grid-template-columns:44px minmax(0,1fr) minmax(96px,auto)!important;'
            + 'grid-template-rows:auto auto!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            + '.page-wrapper header.awa-site-header[data-awa-header-mode="default"] '
            + '.header-wrapper-sticky:has(.b2b-status-panel) '
            + '.awa-header-primary-row[data-awa-header-primary-row="true"]{'
            + 'grid-template-columns:44px minmax(0,1fr) minmax(96px,auto)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body '
            + '.page-wrapper header.awa-site-header[data-awa-header-mode="default"] '
            + '.awa-header-right-col:has(.b2b-status-panel){'
            + 'min-width:96px!important;max-width:min(140px,38vw)!important;width:auto!important;'
            + 'overflow:hidden!important;justify-self:end!important}'
            + '}';

        style = document.getElementById('awa-header-mobile-grid-terminal');
        if (!style) {
            style = document.createElement('style');
            style.id = 'awa-header-mobile-grid-terminal';
            document.body.appendChild(style);
        }

        style.textContent = css;
    }

    function injectPostAuditVisualTerminal() {
        var style;
        var css;

        if (!isHomePage()) {
            return;
        }

        css = ''
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar{background:var(--awa-primary,oklch(48% .14 20))!important;background-color:var(--awa-primary,oklch(48% .14 20))!important;color:var(--awa-text-inverse,oklch(99% .002 20))!important;border:0!important;border-bottom:0!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__lead-short,.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__separator,.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta-short,.awa-b2b-promo-bar__cta-long,.awa-b2b-promo-bar__cta strong){color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar .awa-b2b-promo-close{background:transparent!important;color:var(--awa-text-inverse,oklch(99% .002 20))!important}'
            /* FIX-HOME-PROMO-GHOST-MOBILE-2026-07-05: colapso equivalente ao bloco
               desktop abaixo, porém FORA do @media(min-width:992px) — confirmado via
               CDP getMatchedStylesForNode em viewport 360px que o computed height da
               promo bar ficava travado em 36/44px (várias regras !important de altura
               fixa no bundle legado vencem a regra mobile de baixa especificidade em
               awa-header-mobile-grid-critical.css). Mesma especificidade de 7 IDs usada
               no fix desktop, sem media query, cobre todos os breakpoints. */
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar.awa-promo-bar--scrolled-away,'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5).awa-header-is-sticky .awa-site-header #awa-b2b-promo-bar,'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header.awa-header-condensed #awa-b2b-promo-bar{'
            + 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;border:0!important;overflow:hidden!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar.awa-promo-bar--scrolled-away :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout,.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__cta),'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5).awa-header-is-sticky .awa-site-header #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout,.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__cta),'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header.awa-header-condensed #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__inner,.awa-b2b-promo-bar__layout,.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__cta){'
            + 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;border:0!important;overflow:hidden!important}'
            + '@media(min-width:992px){'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header{background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;border-bottom:0!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #header.header-container{height:44px!important;min-height:44px!important;max-height:44px!important;overflow:hidden!important;background:var(--awa-primary,oklch(48% .14 20))!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #header.header-container .header-content{height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;align-items:center!important;overflow:hidden!important;background:transparent!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar{position:relative!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;border:0!important;background:var(--awa-primary,oklch(48% .14 20))!important;background-color:var(--awa-primary,oklch(48% .14 20))!important;color:var(--awa-text-inverse,oklch(99% .002 20))!important;line-height:1.2!important;box-sizing:border-box!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar .awa-b2b-promo-bar__inner{position:static!important;width:min(100%,1280px)!important;max-width:1280px!important;margin:0 auto!important;padding:0 52px 0 24px!important;height:44px!important;min-height:44px!important;max-height:44px!important;align-items:center!important;justify-content:center!important;box-sizing:border-box!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar :is(.awa-b2b-promo-bar__text,.awa-b2b-promo-bar__lead,.awa-b2b-promo-bar__cta,.awa-b2b-promo-bar__cta strong){color:var(--awa-text-inverse,oklch(99% .002 20))!important;line-height:1.2!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar .awa-b2b-promo-bar__cta{font-weight:700!important;color:var(--awa-text-inverse,oklch(99% .002 20))!important;background:transparent!important;background-color:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0 12px!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar .awa-b2b-promo-close{position:absolute!important;top:0!important;right:0!important;inset-block-start:0!important;inset-inline-end:0!important;width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;border:0!important;border-radius:0!important;background:transparent!important;color:var(--awa-text-inverse,oklch(99% .002 20))!important;font-size:16px!important;font-weight:600!important;line-height:1!important;transform:none!important}'
            /* FIX-HOME-PROMO-GHOST-2026-07-05: o lock de altura da promo acima vence as
               regras de colapso sticky (.awa-promo-bar--scrolled-away / .awa-header-condensed),
               deixando uma faixa invisível no fluxo do header. Este bloco, com a MESMA
               especificidade de 7 IDs + classe de estado e declarado DEPOIS no mesmo
               stylesheet, colapsa promo bar e seu contêiner quando rolado. */
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header #awa-b2b-promo-bar.awa-promo-bar--scrolled-away,'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5).awa-header-is-sticky .awa-site-header #awa-b2b-promo-bar,'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header.awa-header-condensed #awa-b2b-promo-bar{'
            + 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;border:0!important;overflow:hidden!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5).awa-header-is-sticky .awa-site-header #header.header-container,'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5).awa-header-is-sticky .awa-site-header #header.header-container .header-content,'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header.awa-header-condensed #header.header-container,'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header.awa-header-condensed #header.header-container .header-content{'
            + 'height:0!important;min-height:0!important;max-height:0!important;padding:0!important;margin:0!important;border:0!important;overflow:hidden!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-wrapper-sticky:not(.is-sticky){display:block!important;height:auto!important;min-height:0!important;max-height:none!important;padding:0!important;margin:0!important;background:transparent!important;box-shadow:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-wrapper-sticky.is-sticky{box-sizing:border-box!important;padding-block-start:4px!important;padding-inline:max(16px,calc((100% - min(100%,1280px))/2))!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header.awa-main-header{height:74px!important;min-height:74px!important;max-height:74px!important;padding:0!important;padding-block:0!important;padding-inline:0!important;margin:0!important;background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;border-bottom:0!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header :is(.header_main.awa-main-header-inner-wrap,.header-main,.header-main>.container,.awa-main-header__inner.wp-header,.awa-main-header__inner[data-awa-header-row]){height:74px!important;min-height:74px!important;max-height:74px!important;padding-block:0!important;box-sizing:border-box!important;overflow:visible!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header_main.awa-main-header-inner-wrap{border-block:0!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-main>.container{display:block!important;max-width:1280px!important;margin-inline:auto!important;padding-inline:16px!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-main-header__inner.wp-header{display:grid!important;grid-template-columns:minmax(132px,176px) minmax(420px,1fr) minmax(236px,300px)!important;grid-template-areas:\"brand search actions\"!important;align-items:center!important;column-gap:24px!important;padding-block:9px!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-primary-row{display:contents!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-brand-cell{grid-area:brand!important;align-self:center!important;height:56px!important;min-height:0!important;max-height:56px!important;display:flex!important;align-items:center!important;justify-content:flex-start!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-brand-cell .logo,html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-brand-cell .logo a{display:flex!important;align-items:center!important;height:56px!important;min-height:0!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-brand-cell .logo img{width:104px!important;height:44px!important;max-width:104px!important;max-height:44px!important;object-fit:contain!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-search-col{grid-area:search!important;align-self:center!important;height:56px!important;display:flex!important;align-items:center!important;min-width:0!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-search-col :is(.block-search,#search_mini_form,form.minisearch){width:100%!important;max-width:760px!important;margin-inline:auto!important;height:44px!important;min-height:44px!important;max-height:44px!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header form#search_mini_form.minisearch{background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;border:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;border-radius:var(--awa-radius-md,8px)!important;box-shadow:none!important;overflow:hidden!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header form#search_mini_form.minisearch :is(input,#search){background:transparent!important;box-shadow:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-right-col{grid-area:actions!important;justify-self:end!important;align-self:center!important;height:56px!important;min-height:0!important;max-height:56px!important;display:flex!important;align-items:center!important;gap:10px!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-account-prompt{height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;align-items:center!important;gap:8px!important;border-radius:0!important;background:transparent!important;border:0!important;box-shadow:none!important;width:max-content!important;min-width:0!important;max-width:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-account-prompt__icon{width:20px!important;min-width:20px!important;padding:0!important;color:var(--awa-text-muted,var(--awa-text-secondary,CanvasText))!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-account-prompt__guest{gap:2px!important;line-height:1.1!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-account-prompt__line1{font-size:11px!important;line-height:1.05!important;font-weight:600!important;color:var(--awa-text-muted,var(--awa-text-secondary,CanvasText))!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-account-prompt__line2{font-size:13px!important;line-height:1.1!important;font-weight:600!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-account-prompt__link--login{font-size:13px!important;font-weight:700!important;padding:2px 4px!important;color:var(--awa-text,CanvasText)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-account-prompt__separator{font-size:10px!important;font-weight:500!important;padding-inline:1px!important;color:var(--awa-text-muted,var(--awa-text-secondary,CanvasText))!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-account-prompt__link--register{font-size:12px!important;font-weight:700!important;padding:0 2px!important;min-height:0!important;height:auto!important;border-radius:0!important;background:transparent!important;background-color:transparent!important;color:var(--awa-text,CanvasText)!important;border:0!important;display:inline-flex!important;align-items:center!important;justify-content:flex-start!important;box-shadow:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .minicart-wrapper:not(.active):not(.show):not(.is-open),'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-minicart:not(:has(.minicart-wrapper.active)):not(:has(.minicart-wrapper.show)):not(:has(.minicart-wrapper.is-open)),'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .minicart-wrapper:not(.active):not(.show):not(.is-open) .action.showcart{height:44px!important;min-height:44px!important;max-height:44px!important;width:44px!important;min-width:44px!important;max-width:44px!important;align-self:center!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header :is(.awa-header-minicart .action.showcart,.awa-minicart-trigger){border-radius:8px!important;box-shadow:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-control.header-nav.awa-nav-bar{height:48px!important;min-height:48px!important;max-height:48px!important;padding:0!important;margin:0!important;width:100%!important;max-width:none!important;background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;border-block-start:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;border-block-end:0!important;border-inline:0!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-control.awa-nav-bar>.container{height:48px!important;min-height:48px!important;max-height:48px!important;width:100%!important;max-width:none!important;margin-inline:0!important;padding:0!important;box-sizing:border-box!important;background:transparent!important}html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-control.awa-nav-bar .awa-nav-bar__inner{height:48px!important;min-height:48px!important;max-height:48px!important;width:min(100%,1280px)!important;max-width:1280px!important;margin-inline:auto!important;padding-inline:24px!important;box-sizing:border-box!important;background:transparent!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-control.awa-nav-bar .awa-nav-bar__inner{display:flex!important;justify-content:flex-start!important;align-items:center!important;gap:16px!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-header-categories.menu_left_home1{grid-column:1!important;width:206px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;align-self:center!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-control.awa-nav-bar button.our_categories.title-category-dropdown[data-role="awa-vertical-menu-trigger"],html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .header-control.awa-nav-bar .our_categories.title-category-dropdown{height:44px!important;min-height:44px!important;max-height:44px!important;border-radius:8px!important;box-shadow:none!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-nav-quick-links{flex:0 0 auto!important;margin:0!important;margin-inline-start:0!important;justify-content:flex-start!important;justify-self:start!important;grid-column:auto!important;height:44px!important;min-height:44px!important;max-height:44px!important;align-items:center!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-nav-quick-links__list{height:44px!important;min-height:44px!important;max-height:44px!important;align-items:center!important;gap:24px!important;padding:0 var(--awa-home-shell-gutter,16px)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-site-header .awa-nav-quick-links__link{font-size:13px!important;font-weight:600!important;color:var(--awa-text,CanvasText)!important}'
            + '}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + '.page-wrapper .top-home-content--above-fold .wrapper_slider.visible-xs .banner_item_bg :is(picture,img){'
            + 'display:block!important;width:100%!important;height:auto!important;min-height:0!important;'
            + 'max-height:min(58vh,430px)!important;aspect-ratio:auto!important;object-fit:contain!important;'
            + 'object-position:center center!important;background:var(--awa-bg,Canvas)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer){'
            + 'background:var(--awa-bg,Canvas)!important;color:var(--awa-text,CanvasText)!important;min-height:0!important;height:auto!important;padding-block:0!important;overflow-x:clip!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            + ':is(.footer.content,.footer-top,.footer-middle,.footer-content,.velaBlock,.velaContent){'
            + 'background:transparent!important;background-color:transparent!important;color:var(--awa-text,CanvasText)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            + ':is(input,textarea,select,.control input){background:var(--awa-bg,Canvas)!important;color:var(--awa-text,CanvasText)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            + ':is(h2,h3,h4,.footer-title,.awa-footer-title){color:var(--awa-text,CanvasText)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) '
            + ':is(a,p,li,span,.footer.links a,.footer-content a){color:var(--awa-text,CanvasText)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) .footer-bottom{'
            + 'background:#fff!important;background-color:#fff!important;color:var(--awa-text,CanvasText)!important;border-radius:0!important;'
            + 'box-sizing:border-box!important;margin-inline:0!important;width:100%!important;max-width:none!important;padding-block:clamp(16px,2.4vw,28px)!important;padding-inline:0!important;'
            + 'border:0!important;box-shadow:none!important;overflow:visible!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + '.page-wrapper .top-home-content--category-carousel :is(.awa-section-header,.awa-category-carousel__header){'
            + 'display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:center!important;gap:12px!important}'
            + '@media(max-width:767px){'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__text{display:flex!important;align-items:center!important;justify-content:center!important;height:36px!important;margin:0 auto!important;max-width:calc(100% - 88px)!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;line-height:1.2!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__cta{display:inline-flex!important;align-items:center!important;justify-content:center!important;position:static!important;min-width:0!important;max-width:100%!important;height:36px!important;min-height:0!important;margin:0!important;padding:0!important;line-height:1.2!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] :is(.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__separator,.awa-b2b-promo-bar__cta-long){display:none!important}html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__lead{display:inline-flex!important;align-items:center!important;min-width:0!important;max-width:none!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;visibility:visible!important}html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__lead-short{display:block!important;max-width:100%!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;visibility:visible!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__cta-short{display:inline!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + '.awa-site-header :is(.awa-header-minicart,.minicart-wrapper,.minicart-wrapper .action.showcart){'
            + 'width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;'
            + 'min-height:44px!important;max-height:44px!important;display:inline-flex!important;align-items:center!important;'
            + 'justify-content:center!important;box-sizing:border-box!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + '.page-wrapper :is(.awa-owl-nav__btn,.awa-carousel__arrow,.awa-carousel__toggle){'
            + 'inline-size:44px!important;block-size:44px!important;min-inline-size:44px!important;min-block-size:44px!important;'
            + 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;'
            + 'display:inline-flex!important;align-items:center!important;justify-content:center!important;box-sizing:border-box!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + '.page-wrapper .top-home-content--category-carousel :is(.awa-section-header,.awa-category-carousel__header){grid-template-columns:1fr!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + '.page-wrapper .top-home-content--category-carousel :is(.awa-section-header__link,.awa-category-carousel__cta-link,.awa-shelf__view-all){'
            + 'justify-self:stretch!important;width:100%!important}}';

        style = document.getElementById('awa-post-audit-visual-terminal');
        if (!style) {
            style = document.createElement('style');
            style.id = 'awa-post-audit-visual-terminal';
            document.body.appendChild(style);
        }

        style.textContent = css;
    }

    function getHeaderVtexFinalLockCss() {
        return ".js-mobile-menu-compact, #nav .owl-item, .header-content, .nav-sections, .awa-site-header .vm-icon{will-change:auto!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .header-wrapper-sticky{position:sticky!important;top:0!important;z-index:1000!important;contain:layout!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .header-wrapper-sticky,html body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] #header{height:156px!important;min-height:156px!important;max-height:156px!important;background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;backdrop-filter:blur(2px)!important;border-bottom:0!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-main-header__inner.wp-header,.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-main-header__inner[data-awa-header-row]{display:grid!important;grid-template-columns:minmax(120px,176px) minmax(0,1fr) minmax(260px,max-content)!important;grid-template-areas:\"brand search actions\"!important;align-items:center!important;gap:12px!important;max-width:1280px!important;margin:0 auto!important;padding:0 16px!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-brand-cell{grid-area:brand!important;height:56px!important;max-height:56px!important;display:flex!important;align-items:center!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-search-col{grid-area:search!important;height:64px!important;min-height:64px!important;max-height:64px!important;display:flex!important;align-items:center!important;min-width:0!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-right-col{grid-area:actions!important;height:56px!important;max-height:56px!important;display:flex!important;align-items:center!important;justify-content:flex-end!important;gap:10px!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-brand-cell .logo img{width:104px!important;height:44px!important;max-width:104px!important;max-height:44px!important;object-fit:contain!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-search-col :is(form#search_mini_form,form.minisearch,form.search-content){display:flex!important;align-items:stretch!important;height:44px!important;min-height:44px!important;max-height:44px!important;border:1px solid var(--awa-border,#e5e5e5)!important;border-radius:8px!important;overflow:hidden!important;background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;box-shadow:none!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-search-col :is(input#search,.input-text){height:44px!important;min-height:44px!important;max-height:44px!important;line-height:44px!important;border:0!important;border-radius:0!important;background:transparent!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-search-col :is(button.action.search,button.awa-search-btn,form#search_mini_form button.action.search,.actions .action.search,.block-search .action.search){width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;min-height:44px!important;max-height:44px!important;padding:0!important;border:0!important;border-radius:0!important;color:var(--awa-primary,#b73337)!important;background:transparent!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-right-col .action.showcart,.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-right-col .minicart-wrapper{width:44px!important;min-width:44px!important;max-width:44px!important;height:44px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;box-sizing:border-box!important;border-radius:8px!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-account-prompt{height:44px!important;min-height:44px!important;max-height:44px!important;display:flex!important;align-items:center!important;padding:0!important;gap:8px!important;background:transparent!important;border:0!important;border-radius:0!important;box-shadow:none!important;width:max-content!important;min-width:0!important;max-width:none!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .header-control.awa-nav-bar{height:48px!important;min-height:48px!important;max-height:48px!important;padding:0 16px!important;margin:0!important;display:grid!important;grid-template-columns:206px minmax(0,1fr) auto!important;gap:24px!important;align-items:center!important;background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;border-block-start:1px solid var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important;border-block-end:0!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .header-control.awa-nav-bar .awa-nav-bar__inner{height:46px!important;min-height:46px!important;max-height:46px!important;max-width:1280px!important;margin:0 auto!important;display:grid!important;grid-template-columns:206px minmax(0,1fr) auto!important;align-items:center!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-categories.menu_left_home1{grid-column:1!important;height:44px!important;min-height:44px!important;max-height:44px!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-header-categories{width:206px!important;height:44px!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .awa-nav-bar__logo{display:none!important}.page-wrapper .awa-site-header[data-awa-header-mode=\"default\"] .header-control.awa-nav-bar button.our_categories.title-category-dropdown{height:44px!important;min-height:44px!important;max-height:44px!important;border-radius:8px!important;padding:0 12px!important;box-shadow:none!important;background:var(--awa-bg-surface,var(--awa-bg,Canvas))!important;border-color:var(--awa-border-subtle,var(--awa-border,color-mix(in srgb,CanvasText 10%,Canvas)))!important}";
    }

    function injectHeaderVtexFinalLock() {
        var style;
        style = document.getElementById("awa-header-impeccable-cascade-lock-v18");

        if (!style) {
            style = document.createElement("style");
            style.id = "awa-header-impeccable-cascade-lock-v18";
            document.body.appendChild(style);
        }

        style.textContent = getHeaderVtexFinalLockCss();
        if (document.body.classList) {
            document.body.classList.add("awa-header-lock-armed");
        }
    }

    function applyGatedCSS(triggerType) {
        var links;
        var i;
        var isHomeFallbackRun;
        var queueNode;
        var queueLength = 0;

        if (applied) {
            return;
        }

        if (!triggerType) {
            triggerType = 'immediate';
        }

        isHomeFallbackRun = isHomePage()
            && triggerType !== 'interaction'
            && triggerType !== 'immediate';

        if (triggerType === 'interaction' || triggerType === 'immediate') {
            hasMeaningfulGateInteraction = true;
        }

        applied = true;

        injectHomeStabilityFix('awa-home-stability-gate-fix');
        injectHomeClsShell();

        if (document.documentElement) {
            document.documentElement.classList.add('awa-css-gate-applied');
            document.documentElement.classList.remove('awa-css-gate-pending');
        }

        links = document.querySelectorAll('link[' + CSS_GATE_ATTR + ']');

        for (i = 0; i < links.length; i += 1) {
            links[i].media = 'all';
        }

        if (isHomePage()) {
            queueNode = document.getElementById('awa-css-gate-queue');
            if (queueNode && queueNode.textContent) {
                try {
                    queueLength = JSON.parse(queueNode.textContent).length;
                } catch (e) { /* noop */ }
            }
            injectQueuedStylesheets().then(function () {
                try {
                    document.dispatchEvent(new CustomEvent('awa:css-gate-applied', {bubbles: true}));
                } catch (e) { /* noop */ }
            });
            for (i = 0; i < GATE_EVENTS.length; i += 1) {
                window.removeEventListener(GATE_EVENTS[i], onGateInteraction, true);
            }
            return;
        }

        injectQueuedStylesheets().then(function () {
            return injectHeaderRefineTerminal();
        }).then(function () {
            return injectImpeccableAuditTerminal();
        }).then(function () {
            return injectImpeccableRefineTerminal();
        }).then(function () {
            return injectAlignGridTerminal();
        }).then(function () {
            if (isHomeFallbackRun && !hasMeaningfulGateInteraction) {
                homePostGateFixPending = true;
            } else {
                injectPostGateHeaderFix();
                injectHeaderVtexFinalLock();
                window.setTimeout(function () {
                    injectHeaderVtexFinalLock();
                }, 800);
                window.setTimeout(function () {
                    injectHeaderVtexFinalLock();
                }, 1800);
                homePostGateFixPending = false;
            }
            injectHomeCarouselCardTerminal();
            injectHeaderMobileGridTerminal();
            injectPostAuditVisualTerminal();
            try {
                document.dispatchEvent(new CustomEvent('awa:css-gate-applied', { bubbles: true }));
            } catch (e) { /* noop */ }
        });

        for (i = 0; i < GATE_EVENTS.length; i += 1) {
            window.removeEventListener(GATE_EVENTS[i], onGateInteraction, true);
        }
    }

    var i;

    window.__awaApplyGatedCSS = applyGatedCSS;

    if (document.documentElement && isHomePage()) {
        document.documentElement.classList.add('awa-css-gate-pending');
    }

    injectHomeFooterOverflowGuard();
for (i = 0; i < GATE_EVENTS.length; i += 1) {
        window.addEventListener(GATE_EVENTS[i], onGateInteraction, {
            capture: true,
            passive: true
        });
    }

    function scheduleFallbackGate() {
        var run = function () {
            if (!applied) {
                applyGatedCSS('fallback');
            }
        };

        if (isHomePage()) {
            if (!HOME_AUTO_FALLBACK_ENABLED) {
                return;
            }

            // Home refine fallback: idle/rAF with 1s cap (was 20s setTimeout).
            // Structural align-grid still at HOME_STRUCTURAL_FALLBACK_MS.
            window.setTimeout(function () {
                if (applied) {
                    return;
                }
                injectAlignGridTerminal();
            }, HOME_STRUCTURAL_FALLBACK_MS);
            var idleRun = function () {
                if (applied) {
                    return;
                }
                if (window.requestAnimationFrame) {
                    window.requestAnimationFrame(function () {
                        run();
                    });
                } else {
                    run();
                }
            };
            if (window.requestIdleCallback) {
                window.requestIdleCallback(function () {
                    idleRun();
                }, { timeout: HOME_INTERACTION_FALLBACK_MS });
            } else {
                window.setTimeout(idleRun, HOME_INTERACTION_FALLBACK_MS);
            }
            return;
        }

        if (isMobileViewport()) {
            window.setTimeout(run, MOBILE_FALLBACK_DELAY_MS);
            return;
        }

        window.setTimeout(run, DESKTOP_FALLBACK_DELAY_MS);
    }

    // defer/async pode executar antes OU depois de window.load.
    // Cobrir os dois lados + watchdog para nunca ficar preso em pending.
    if (document.readyState === 'complete') {
        scheduleFallbackGate();
    } else {
        window.addEventListener('load', function () {
            scheduleFallbackGate();
        }, { once: true });
        if (document.readyState === 'complete') {
            scheduleFallbackGate();
        }
    }

    // Home: NÃO usar watchdog de 1.2s — o script costuma carregar tarde (pós-load),
    // então 1.2s após o parse ≈ 10s de navegação e reintroduz align-grid cedo (GTmetrix).
    // Fora da home o fallback já cobre via MOBILE/DESKTOP_FALLBACK_DELAY_MS.
    if (!isHomePage()) {
        window.setTimeout(function () {
            if (!applied) {
                applyGatedCSS('fallback-watchdog');
            }
        }, 1200);
    }

    if (window.__awaCssGateApplyImmediately) {
        window.setTimeout(function () {
            applyGatedCSS('immediate');
        }, 0);
    }
}());

/**
 * Busca — botão limpar (sync; awa-ux-enhancements deferido na home via AMD bootstrap).
 */
(function () {
    'use strict';

    function setSearchClearVisible(clearBtn, visible) {
        clearBtn.hidden = !visible;
        clearBtn.style.display = visible ? 'inline-flex' : 'none';
        clearBtn.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function initSearchClear() {
        // AWA 2026-07-22: busca só com lupa — sem botão limpar (X).
        return;
        var searchInput = document.querySelector(
            '#search, .header-search input[type="text"], .block-search input.input-text'
        );
        if (!searchInput || document.getElementById('awa-search-clear')) {
            return;
        }

        var clearBtn = document.createElement('button');
        clearBtn.id = 'awa-search-clear';
        clearBtn.type = 'button';
        clearBtn.className = 'awa-search-clear-btn';
        clearBtn.setAttribute('aria-label', 'Limpar busca');
        clearBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

        var control = searchInput.closest(
            '.control[data-awa-search-control], .field.search .control, .control'
        ) || searchInput.parentElement;

        if (control) {
            if (window.getComputedStyle(control).position === 'static') {
                control.style.position = 'relative';
            }
            control.appendChild(clearBtn);
        }

        setSearchClearVisible(clearBtn, false);
        searchInput.classList.add('awa-search-input--clearable');

        searchInput.addEventListener('input', function () {
            setSearchClearVisible(clearBtn, !!this.value);
        });
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            setSearchClearVisible(clearBtn, false);
            searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            searchInput.focus();
        });
    }

    function bootSearchClear() {
        initSearchClear();
    }

    if (document.readyState !== 'loading') {
        bootSearchClear();
    } else {
        document.addEventListener('DOMContentLoaded', bootSearchClear, { once: true });
    }

    window.setTimeout(bootSearchClear, 500);
    window.setTimeout(bootSearchClear, 2000);
}());

/**
 * Header mobile — faixa B2B compacta terminal para páginas fora da home.
 */
(function () {
    'use strict';

    function injectHeaderMobilePromoClean() {
        var style;
        var css;

        if (!document.querySelector('.awa-site-header .awa-b2b-promo-bar__text')) {
            return;
        }

        style = document.getElementById('awa-header-mobile-promo-clean-terminal');
        if (!style) {
            style = document.createElement('style');
            style.id = 'awa-header-mobile-promo-clean-terminal';
            document.head.appendChild(style);
        }

        css = '@media(max-width:767px){'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__text{display:flex!important;align-items:center!important;justify-content:center!important;height:36px!important;margin:0 auto!important;max-width:calc(100% - 88px)!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;line-height:1.2!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__cta{display:inline-flex!important;align-items:center!important;justify-content:center!important;position:static!important;min-width:0!important;max-width:100%!important;height:36px!important;min-height:0!important;margin:0!important;padding:0!important;line-height:1.2!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] :is(.awa-b2b-promo-bar__tail,.awa-b2b-promo-bar__lead-long,.awa-b2b-promo-bar__separator,.awa-b2b-promo-bar__cta-long){display:none!important}html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__lead{display:inline-flex!important;align-items:center!important;min-width:0!important;max-width:none!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;visibility:visible!important}html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__lead-short{display:block!important;max-width:100%!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;visibility:visible!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header[data-awa-header-mode=default] .awa-b2b-promo-bar__cta-short{display:inline!important}'
            + '}';

        style.textContent = css;
    }

    if (document.readyState !== 'loading') {
        injectHeaderMobilePromoClean();
    } else {
        document.addEventListener('DOMContentLoaded', injectHeaderMobilePromoClean, { once: true });
    }

    window.setTimeout(injectHeaderMobilePromoClean, 600);
    window.setTimeout(injectHeaderMobilePromoClean, 2500);
}());

/**
 * Patch 2026-07-02 — Contraste do footer vermelho (links/textos ilegíveis).
 *
 * Causa raiz: o footer (.page_footer/.page-footer) tem fundo vermelho fixo
 * (var(--awa-primary)), mas várias folhas de estilo legadas (assumindo um
 * footer claro/branco) e injeções anteriores deste mesmo gate competem entre
 * si definindo `color` para .velaFooterLinks a / .awa-newsletter-desc /
 * .awa-footer-atendimento p — algumas com tom escuro (#666/#475569), outras
 * já tentando um tom claro, mas perdendo o empate de especificidade por
 * posição no DOM. Como esta função é a última <style> anexada por este
 * arquivo (executa por último, na mesma ordem de carregamento), ela vence
 * qualquer empate de especificidade (#html-body x7) contra as demais.
 * Não removemos as regras antigas — apenas garantimos a cor final correta.
 */
(function () {
    'use strict';

    function injectFooterContrastTerminal() {
        var style = document.getElementById('awa-footer-contrast-terminal');
        var css;

        function isFooterDark() {
            var footer = document.querySelector('.page_footer, .page-footer');
            var color;
            var match;
            var lightness;
            var r;
            var g;
            var b;

            if (!footer) {
                return false;
            }

            color = window.getComputedStyle(footer).backgroundColor || '';
            match = color.match(/oklch\(\s*([0-9.]+)(%)?/i);
            if (match) {
                lightness = parseFloat(match[1]);
                if (match[2]) {
                    lightness /= 100;
                }

                return lightness < 0.62;
            }

            match = color.match(/rgba?\(\s*([0-9.]+)[,\s]+([0-9.]+)[,\s]+([0-9.]+)/i);
            if (match) {
                r = parseFloat(match[1]) / 255;
                g = parseFloat(match[2]) / 255;
                b = parseFloat(match[3]) / 255;

                return (0.2126 * r + 0.7152 * g + 0.0722 * b) < 0.5;
            }

            return footer.classList.contains('awa-footer--dark');
        }

        if (!isFooterDark()) {
            if (style) {
                style.textContent = '';
            }

            return;
        }

        if (!style) {
            style = document.createElement('style');
            style.id = 'awa-footer-contrast-terminal';
            document.head.appendChild(style);
        }

        /* A11Y-001 (2026-07-04, revisão Lighthouse desktop): rgba(255,255,255,.82)
         * sobre o vermelho efetivo do footer (~rgb(178,60,52)) mede ~4.46:1 —
         * abaixo do minimo AA de 4.5:1 para texto normal (falha por margem
         * mínima). .9 de opacidade eleva para ~5.0:1, com folga de segurança. */
        css = 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            + ':is(.page_footer,.page-footer) :is(.velaFooterLinks a,.awa-newsletter-desc,.awa-footer-atendimento p,.awa-footer-atendimento a[href^="tel"],.awa-footer-atendimento a[href^="mailto"]):not(.awa-footer-atendimento__store *){'
            + 'color:rgba(255,255,255,.9)!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            + ':is(.page_footer,.page-footer) .awa-footer-section__toggle{'
            + 'color:#fff!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            + ':is(.page_footer,.page-footer) :is(.awa-footer-atendimento a[href^="tel"],.awa-footer-atendimento a[href^="mailto"]):hover{'
            + 'color:#fff!important;text-decoration:underline!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            + ':is(.page_footer,.page-footer) .velaFooterLinks a:hover,'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            + ':is(.page_footer,.page-footer) .velaFooterLinks a:focus-visible{'
            + 'color:#fff!important;text-decoration:underline!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            + ':is(.page_footer,.page-footer) :is(.awa-footer-atendimento__store,.awa-footer-atendimento__store p){'
            + 'color:#1a1a1a!important}'
            /* A11Y-001 (2026-07-04): .awa-footer-pay-sec__label/.awa-footer-muted-label e
             * .awa-footer-copyright__legal/__disclaimer tem a cor pretendida (#333/#666,
             * escura, sobre fundo claro da faixa de pagamento/copyright) sobrescrita por
             * tokens legados de _awa-consolidated.less (--awa-cons-c76/--awa-vfix-c1)
             * que resolvem para um tom quase branco — texto praticamente invisivel
             * (contraste ~1.05:1, WCAG exige 4.5:1). Mesmo mecanismo de reforco final
             * ja usado acima para o restante do footer. */
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            + ':is(.page_footer,.page-footer) .footer-bottom :is(.awa-footer-muted-label,.awa-footer-pay-sec__label){'
            + 'color:#333333!important}'
            + 'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body .page-wrapper '
            + ':is(.page_footer,.page-footer) .footer-bottom :is(.awa-footer-copyright__legal,.awa-footer-copyright__disclaimer){'
            + 'color:#666666!important}';

        style.textContent = css;

        /*
         * Reforço via estilo inline (!important): o footer deste tema acumula
         * dezenas de folhas concorrentes com cadeias #html-body de especificidade
         * variável e ordem de carga imprevisível (algumas assíncronas via
         * document.body.appendChild em Promises). Confirmado empiricamente (via
         * bisseção de document.styleSheets) que mesmo #html-body x8 nem sempre
         * vence em todos os elementos do footer (ex.: botões-acordeão mobile).
         * Estilo inline com prioridade "important" tem precedência garantida
         * sobre QUALQUER regra de folha de estilo externa, eliminando de vez
         * a disputa de cascade para este conjunto pontual de elementos.
         */
        function forceInlineContrast() {
            var footer = document.querySelector('.page_footer, .page-footer');
            if (!footer) {
                return;
            }
            /* A11Y-001: .awa-footer-section__toggle NAO entra nesta lista "muted"
             * (rgba(255,255,255,.82) ~3.9:1 de contraste, falha WCAG). Esse elemento
             * ja tem sua propria regra dedicada (#fff, ~5.4:1) na <style> acima; incluir
             * aqui sobrescrevia essa regra com um inline style de opacidade reduzida. */
            var lightEls = footer.querySelectorAll(
                '.velaFooterLinks a, .awa-newsletter-desc, ' +
                '.awa-footer-atendimento a[href^="tel"], .awa-footer-atendimento a[href^="mailto"]'
            );
            for (var i = 0; i < lightEls.length; i++) {
                if (lightEls[i].closest('.awa-footer-atendimento__store')) {
                    continue;
                }
                /* A11Y-001: .9 (nao .82) — ver justificativa de contraste na
                 * <style> injetada acima (mesmo par de cores, mesma regra). */
                lightEls[i].style.setProperty('color', 'rgba(255,255,255,.9)', 'important');
            }
            var darkEls = footer.querySelectorAll('.awa-footer-atendimento__store, .awa-footer-atendimento__store p');
            for (var j = 0; j < darkEls.length; j++) {
                darkEls[j].style.setProperty('color', '#1a1a1a', 'important');
            }
            /* A11Y-001 (2026-07-04, revisão pós-Lighthouse): a regra dedicada
             * ".awa-footer-section__toggle{color:#fff!important}" da <style>
             * acima nao vence no DOM real (auditado: cor efetiva permanece
             * #393130 sobre fundo efetivo #b23c34 — contraste 2.16, falha
             * WCAG). Reforço via inline style, que tem precedência garantida. */
            var toggleEls = footer.querySelectorAll('.awa-footer-section__toggle');
            for (var k = 0; k < toggleEls.length; k++) {
                toggleEls[k].style.setProperty('color', '#ffffff', 'important');
            }
            /* A11Y-001 (2026-07-04): .awa-footer-devby__label acumula a classe
             * .awa-footer-muted-label mas vive fora de .footer-bottom (esta em
             * .awa-footer-devby, secao irma), entao a regra CSS acima que exige
             * ".footer-bottom .awa-footer-muted-label" nao o alcança — cor
             * efetiva ficava quase branca sobre fundo quase branco (contraste
             * 1.02:1). Fix direto e independente de hierarquia via inline. */
            var devbyLabel = footer.querySelector('.awa-footer-devby__label');
            if (devbyLabel) {
                devbyLabel.style.setProperty('color', '#333333', 'important');
            }
        }

        forceInlineContrast();
        window.setTimeout(forceInlineContrast, 800);
        window.setTimeout(forceInlineContrast, 3200);
    }

    if (document.readyState !== 'loading') {
        injectFooterContrastTerminal();
    } else {
        document.addEventListener('DOMContentLoaded', injectFooterContrastTerminal, { once: true });
    }

    window.setTimeout(injectFooterContrastTerminal, 700);
    window.setTimeout(injectFooterContrastTerminal, 3000);
}());

/*
 * Fix terminal — ícone do carrinho (showcart) invisível (2026-07-15).
 *
 * Runtime CDP (home): `.action.showcart.header-mini-cart` tem background
 * vermelho sólido (oklch primary). Forçar stroke/color = --awa-primary
 * deixa o SVG vermelho-sobre-vermelho (caixa vermelha sem ícone).
 *
 * Regra: fundo sólido → ícone branco (--awa-text-inverse);
 * fundo transparente → ícone primary.
 */
(function () {
    'use strict';

    var TRIGGER_SELECTOR = '.awa-site-header .awa-header-minicart .minicart-wrapper .action.showcart, ' +
        '.awa-site-header .awa-header-cart-fallback';
    var ICON_SELECTOR = '.awa-minicart-icon, .awa-minicart-icon *, .awa-header-cart-fallback__icon, .awa-header-cart-fallback__icon *';

    function isTransparentBg(bg) {
        if (!bg || bg === 'transparent') {
            return true;
        }
        var m = /^rgba?\(([\d\s.,]+)\)$/i.exec(bg);
        if (!m) {
            return false;
        }
        var parts = m[1].split(',').map(function (part) {
            return parseFloat(part.trim());
        });
        return parts.length >= 4 && parts[3] === 0;
    }

    function forceCartIconContrast() {
        var triggers = document.querySelectorAll(TRIGGER_SELECTOR);
        var i;
        var trigger;
        var solid;
        var color;
        var icons;
        var j;
        var icon;

        for (i = 0; i < triggers.length; i++) {
            trigger = triggers[i];
            /* Minicart sem fundo/borda: ícone primary alinhado aos demais ícones do header. */
            trigger.style.removeProperty('background');
            trigger.style.setProperty('background-color', 'transparent', 'important');
            trigger.style.setProperty('border', '0', 'important');
            trigger.style.setProperty('box-shadow', 'none', 'important');
            trigger.style.setProperty('color', 'var(--awa-primary, #b73337)', 'important');
            trigger.style.setProperty('border-radius', '0', 'important');
            solid = false;
            color = 'var(--awa-primary, #b73337)';
            icons = trigger.querySelectorAll(ICON_SELECTOR);

            for (j = 0; j < icons.length; j++) {
                icon = icons[j];
                icon.style.setProperty('color', color, 'important');
                icon.style.setProperty('stroke', color, 'important');
                if (icon.tagName === 'SVG' || (icon.classList && icon.classList.contains('awa-minicart-icon'))) {
                    icon.style.setProperty('width', '24px', 'important');
                    icon.style.setProperty('height', '24px', 'important');
                }
                if (icon.tagName === 'circle') {
                    icon.style.setProperty('fill', color, 'important');
                }
            }

        }

    }

    if (document.readyState !== 'loading') {
        forceCartIconContrast();
    } else {
        document.addEventListener('DOMContentLoaded', forceCartIconContrast, { once: true });
    }

    window.setTimeout(forceCartIconContrast, 700);
    window.setTimeout(forceCartIconContrast, 1500);
    window.setTimeout(forceCartIconContrast, 3000);
    window.setTimeout(forceCartIconContrast, 6000);

    /* Minicart é re-renderizado por Knockout ao atualizar contagem/itens — reaplica. */
    if (window.jQuery) {
        window.jQuery(document).on('ajaxComplete cartUpdate', forceCartIconContrast);
    }
}());

/**
 * A11Y-001 (2026-07-04 / 2026-08-05) — Contraste: botão
 * "Deptos." da nav inferior mobile.
 *
 * Causa raiz #1: `.awa-home-niche-shelves__tab.is-active` usa
 * `color: var(--awa-text-inverse, CanvasText)`, mas `--awa-text-inverse` está
 * "envenenado" em várias folhas legadas (_awa-consolidated.less e outras)
 * por tokens como --awa-cons-c78/--awa-sg-c61 que resolvem para preto/quase-
 * preto em vez de branco — texto preto sobre fundo vermelho (--awa-primary),
 * contraste ~1.4:1. Mesmo padrão já documentado acima no fix do ícone do
 * carrinho.
 *
 * Causa raiz #2: `button.toggle-nav-footer` é o único item da nav inferior
 * mobile com fundo próprio colorido (vermelho, quando ativo/padrão), mas
 * herda `color: var(--awa-text-secondary, #666666)` da regra genérica dos
 * irmãos `<a>` (que têm fundo transparente) — resultando em slate-600
 * (rgb(71,85,105)) sobre vermelho, contraste ~2.1:1.
 *
 * Reforço inline "important": mesmo mecanismo comprovado acima, pois ambos
 * os seletores perdem a disputa de cascade contra folhas assíncronas/tokens
 * quebrados fora do nosso controle direto nesta função.
 */
(function () {
    'use strict';

    /* A11Y-001 (2026-07-04, revisão pós-Lighthouse): regra <style> síncrona,
     * injetada assim que este script executa (sem esperar DOMContentLoaded).
     * O reforço 100% via JS abaixo (setProperty inline) só alcança o botão
     * depois de DOMContentLoaded + até 3s de timeouts — nesse intervalo (ou
     * caso ".is-active" seja atribuída por outro script assíncrono, ex.
     * awa-home-shelf-bootstrap, antes do primeiro forceHomeTabAndFooterToggleContrast
     * rodar) o Lighthouse/axe pode auditar o botão ainda com a cor "envenenada"
     * herdada de --awa-text-inverse. Uma regra CSS de verdade não tem essa
     * corrida: aplica no primeiro paint e continua valendo para qualquer
     * elemento que ganhe a classe depois, sem depender de nenhum timer.
     */
    /* 2026-08-05: abas niche removidas — só strip residual, sem reinjetar. */
    (function stripStaleHomeTabContrastStyle() {
        var stale = document.getElementById('awa-home-tab-contrast-style');
        if (stale && stale.parentNode) {
            stale.parentNode.removeChild(stale);
        }
    }());

    function forceHomeTabAndFooterToggleContrast() {
        /* BUGFIX (2026-07-13): a versão anterior só ADICIONAVA a cor inline na
         * aba ativa e nunca a removia da aba que deixa de ser ativa. Como
         * inline `!important` vence qualquer regra de folha de estilo
         * (inclusive a `<style>` injetada acima), a aba que perde ".is-active"
         * ficava com `color:#fff` preso sobre o fundo claro do estado inativo
         * — texto branco sobre branco, invisível. Ao trocar de aba (clique ou
         * seta do teclado), é preciso reforçar a ativa E limpar as demais. */
        document.querySelectorAll('nav.fixed-bottom button.toggle-nav-footer').forEach(function (btn) {
            var isExpanded = btn.getAttribute('aria-expanded') === 'true';
            /* r37c: inactive = slate; expanded = brand. Also flatten soft-chip chrome. */
            var deptColor = isExpanded ? '#b73337' : '#64748b';
            btn.style.setProperty('color', deptColor, 'important');
            btn.style.setProperty('background', 'transparent', 'important');
            btn.style.setProperty('background-color', 'transparent', 'important');
            btn.style.setProperty('box-shadow', 'none', 'important');
            btn.style.setProperty('outline', 'none', 'important');
            if (!isExpanded && document.activeElement === btn) {
                try { btn.blur(); } catch (eBlur) {}
            }
            var icon = btn.querySelector('.icon');
            if (icon) {
                icon.style.setProperty('color', deptColor, 'important');
            }
        });
    }

    function scheduleDeptosContrast() {
        forceHomeTabAndFooterToggleContrast();
        window.setTimeout(forceHomeTabAndFooterToggleContrast, 30);
        window.setTimeout(forceHomeTabAndFooterToggleContrast, 120);
        window.setTimeout(forceHomeTabAndFooterToggleContrast, 350);
    }

    if (document.readyState !== 'loading') {
        forceHomeTabAndFooterToggleContrast();
    } else {
        document.addEventListener('DOMContentLoaded', forceHomeTabAndFooterToggleContrast, { once: true });
    }

    window.setTimeout(forceHomeTabAndFooterToggleContrast, 700);
    window.setTimeout(forceHomeTabAndFooterToggleContrast, 1500);
    window.setTimeout(forceHomeTabAndFooterToggleContrast, 3000);

    document.addEventListener('click', function (evt) {
        if (evt.target && evt.target.closest &&
            evt.target.closest('.toggle-nav-footer, .awa-custom-drawer-overlay, .awa-mobile-overlay, .awa-nav-close, .vmm-mobile-close')) {
            scheduleDeptosContrast();
        }
    }, { capture: true, passive: true });

    document.addEventListener('keydown', function (evt) {
        if (evt.key === 'Escape') {
            scheduleDeptosContrast();
        }
    }, { capture: true, passive: true });

    /* INP guard: debounce por requestAnimationFrame — observers de mutacao nao podem
       bloquear o paint do clique (ex.: abrir o menu dispara scan em todo o DOM). */
    var awaRafThrottle = function (fn) {
        var queued = false;
        return function () {
            if (queued) { return; }
            queued = true;
            window.requestAnimationFrame(function () { queued = false; fn(); });
        };
    };

    /* r37c: Escape/overlay close aria-expanded without a toggle click — observe. */
    /* Sync when menu-controller toggles body.nav-open without a toggle click. */
    (function observeBodyDrawerClass() {
        try {
            var last = document.body.className;
            new MutationObserver(function () {
                var now = document.body.className;
                if (now === last) { return; }
                var prevOpen = /\b(nav-open|awa-menu-drawer-open|awa-mobile-drawer-open)\b/.test(last);
                var nextOpen = /\b(nav-open|awa-menu-drawer-open|awa-mobile-drawer-open)\b/.test(now);
                last = now;
                if (prevOpen !== nextOpen) {
                    scheduleDeptosContrast();
                }
            }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
        } catch (e) {}
    }());

    (function observeDeptosExpanded() {
        function bind(btn) {
            if (!btn || btn.getAttribute('data-awa-r37c-obs') === '1') {
                return;
            }
            btn.setAttribute('data-awa-r37c-obs', '1');
            try {
                var mo = new MutationObserver(awaRafThrottle(function () {
                    forceHomeTabAndFooterToggleContrast();
                }));
                mo.observe(btn, { attributes: true, attributeFilter: ['aria-expanded', 'class'] });
            } catch (e) {}
        }
        function scan() {
            document.querySelectorAll('nav.fixed-bottom button.toggle-nav-footer').forEach(bind);
        }
        scan();
        window.setTimeout(scan, 800);
        window.setTimeout(scan, 2000);
        if (typeof MutationObserver !== 'undefined') {
            try {
                new MutationObserver(awaRafThrottle(scan)).observe(document.documentElement, { childList: true, subtree: true });
            } catch (e2) {}
        }
    }());

}());

/*
 * VISUAL-20260707: footer home/PLP e quickview PLP.
 *
 * O footer recebe regras concorrentes em inline head, bundles assíncronos e
 * este próprio gate. Algumas rotas ainda chegavam no fim do carregamento com o
 * container externo vermelho, embora os filhos já estivessem claros. Esta
 * camada aplica a decisão visual diretamente nos containers-alvo, depois das
 * folhas tardias, sem tocar no conteúdo nem nas rotas.
 */
(function () {
    'use strict';

    function isTargetPage() {
        return !!(document.querySelector('footer.page-footer, .page_footer'));
    }

    function setImportant(el, prop, value) {
        if (el) {
            el.style.setProperty(prop, value, 'important');
        }
    }

    /**
     * H10A: setProperty('background', …) expande longhands vazios
     * (background-image: !important) no style attr. Usar só color + image:none.
     */
    function clearEmptyBackgroundLonghands(el) {
        if (!el || !el.style) {
            return;
        }
        // Nunca incluir 'background': removeProperty('background') zera todos os longhands no Chrome.
        var props = [
            'background-image',
            'background-position',
            'background-position-x',
            'background-position-y',
            'background-size',
            'background-repeat',
            'background-attachment',
            'background-origin',
            'background-clip'
        ];
        props.forEach(function (prop) {
            var val = (el.style.getPropertyValue(prop) || '').trim();
            if (val === '') {
                el.style.removeProperty(prop);
            }
        });
    }

    function applyFooterShellSurface(el, colorValue) {
        if (!el) {
            return;
        }
        el.style.removeProperty('background');
        clearEmptyBackgroundLonghands(el);
        setImportant(el, 'background-color', colorValue);
        setImportant(el, 'background-image', 'none');
    }

    function applyFooterShellFix() {
        if (!isTargetPage()) {
            return;
        }

        var isMobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
        /* Desktop/tablet: content-driven height (locks 566/339/111 congelavam densidade).
         * Mobile: mantém floors leves só onde accordion/CLS ainda colapsa. */
        var containerMinHeight = isMobile ? '0' : '0';
        var newsletterMinHeight = isMobile ? '0' : '0';
        var atendimentoMinHeight = isMobile ? '0' : '0';

        // Evita style="" inflado no shell do footer; essas regras ficam no CSS.
        document.querySelectorAll('footer.page-footer, .page-footer').forEach(function (footer) {
            [
                'background',
                'background-color',
                'background-image',
                'color',
                'height',
                'min-height',
                'max-height',
                'padding-block',
                'padding-inline',
                'padding-left',
                'padding-right',
                'padding',
                'overflow',
                'overflow-x',
                'overflow-y',
                'max-width',
                'width',
                'margin-top',
                'margin-bottom',
                'margin-left',
                'margin-right',
                'margin-inline'
            ].forEach(function (prop) { footer.style.removeProperty(prop); });
            footer.style.setProperty('max-width', 'none', 'important');
            footer.style.setProperty('width', '100%', 'important');
            footer.style.setProperty('margin-left', '0', 'important');
            footer.style.setProperty('margin-right', '0', 'important');
            footer.style.setProperty('margin-inline', '0', 'important');
            footer.style.setProperty('padding-left', '0', 'important');
            footer.style.setProperty('padding-right', '0', 'important');
            footer.style.setProperty('padding-inline', '0', 'important');
            footer.style.setProperty('box-sizing', 'border-box', 'important');
        });
        document.querySelectorAll('footer.page-footer .page_footer').forEach(function (shell) {
            setImportant(shell, 'max-width', 'none');
            setImportant(shell, 'width', '100%');
            setImportant(shell, 'margin-left', '0');
            setImportant(shell, 'margin-right', '0');
            setImportant(shell, 'margin-inline', '0');
            setImportant(shell, 'padding-left', '0');
            setImportant(shell, 'padding-right', '0');
            setImportant(shell, 'padding-inline', '0');
            setImportant(shell, 'box-sizing', 'border-box');
        });

        document.querySelectorAll(
            '.page_footer #footer, .page-footer #footer, ' +
            '.page_footer .footer-container, .page-footer .footer-container'
        ).forEach(function (el) {
            applyFooterShellSurface(el, 'transparent');
            setImportant(el, 'color', 'var(--awa-text,CanvasText)');
            setImportant(el, 'min-height', containerMinHeight);
            setImportant(el, 'height', 'auto');
        });

        document.querySelectorAll('.page_footer .awa-footer-newsletter, .page-footer .awa-footer-newsletter').forEach(function (el) {
            setImportant(el, 'min-height', newsletterMinHeight);
            setImportant(el, 'height', 'auto');
        });

        document.querySelectorAll('.page_footer .awa-footer-atendimento, .page-footer .awa-footer-atendimento').forEach(function (el) {
            setImportant(el, 'min-height', atendimentoMinHeight);
            setImportant(el, 'height', 'auto');
        });

        /* r70 + audit 4.2: touch ≥44 from 768px; dense compaction only ≥992. */
        var isDesktopDense = window.matchMedia && window.matchMedia('(min-width: 992px)').matches;
        var isTouchDesktop = window.matchMedia && window.matchMedia('(min-width: 768px)').matches;
        var denseEls = document.querySelectorAll('.page_footer .velaFooterLinks a, .page_footer .awa-footer-atendimento__actions a, .page_footer .awa-footer-atendimento__phone a, .page_footer .awa-footer-atendimento__email a, .page_footer .awa-footer-copyright__disclaimer, .page_footer .awa-footer-atendimento__store-badge, .page_footer .awa-footer-atendimento__store-address');
        var denseProps = ['margin-top','padding-top','padding-bottom','padding-block','min-height','line-height','font-size','max-height','overflow','display','white-space','text-overflow'];
        if (!isTouchDesktop) {
            denseEls.forEach(function (el) { denseProps.forEach(function (prop) { el.style.removeProperty(prop); }); });
            document.querySelectorAll(
                '.page_footer .footer-container > .container, ' +
                '.page_footer .awa-footer-newsletter > .container, ' +
                '.page_footer .footer-bottom > .container, ' +
                '.page_footer .awa-footer-trust-bar > .container'
            ).forEach(function (el) {
                [
                    'max-width', 'width', 'margin-left', 'margin-right', 'margin-inline',
                    'padding-left', 'padding-right', 'padding-inline',
                    'padding-top', 'padding-bottom', 'padding-block'
                ].forEach(function (prop) { el.style.removeProperty(prop); });
            });
        } else {
            document.querySelectorAll(
                '.page_footer .velaFooterLinks a, .page_footer .awa-footer-atendimento__actions a, ' +
                '.page_footer .awa-footer-atendimento__phone a, .page_footer .awa-footer-atendimento__email a, ' +
                '.page_footer .awa-footer-pro__social-link, .page_footer ul.awa-footer-categories-list > li > a'
            ).forEach(function (link) {
setImportant(link, 'display', 'inline-flex');
                setImportant(link, 'align-items', 'center');
                setImportant(link, 'padding-top', '10px');
                setImportant(link, 'padding-bottom', '10px');
                setImportant(link, 'padding-block', '10px');
                setImportant(link, 'min-height', '44px');
                setImportant(link, 'height', 'auto');
                setImportant(link, 'line-height', '1.25');
            });
            document.querySelectorAll('.page_footer .awa-footer-pro__social-link').forEach(function (el) {
                setImportant(el, 'width', '44px');
                setImportant(el, 'min-width', '44px');
                setImportant(el, 'justify-content', 'center');
                setImportant(el, 'box-sizing', 'border-box');
            });
            if (isDesktopDense) {
                document.querySelectorAll('.page_footer .awa-footer-copyright__disclaimer').forEach(function (el) {
                    setImportant(el, 'font-size', '10px');
                    setImportant(el, 'line-height', '1.4');
                    setImportant(el, 'max-height', 'none');
                    setImportant(el, 'overflow', 'visible');
                    setImportant(el, 'white-space', 'normal');
                    setImportant(el, 'max-width', 'min(72ch, 100%)');
                });
                document.querySelectorAll('.page_footer .awa-footer-atendimento__store-badge').forEach(function (el) {
                    setImportant(el, 'display', 'none');
                });
                document.querySelectorAll('.page_footer .awa-footer-atendimento__store-address').forEach(function (el) {
                    setImportant(el, 'white-space', 'nowrap');
                    setImportant(el, 'overflow', 'hidden');
                    setImportant(el, 'text-overflow', 'ellipsis');
                });
                document.querySelectorAll(
                    '.page_footer .footer-container > .container, ' +
                    '.page_footer .awa-footer-newsletter > .container, ' +
                    '.page_footer .footer-bottom > .container, ' +
                    '.page_footer .awa-footer-trust-bar > .container'
                ).forEach(function (el) {
                    /* Mesmo eixo do header: 1280 centrado. max-width:none esticava o miolo. */
                    setImportant(el, 'max-width', '1280px');
                    setImportant(el, 'width', 'min(100%, 1280px)');
                    setImportant(el, 'margin-left', 'auto');
                    setImportant(el, 'margin-right', 'auto');
                    setImportant(el, 'margin-inline', 'auto');
                    setImportant(el, 'padding-left', '24px');
                    setImportant(el, 'padding-right', '24px');
                    setImportant(el, 'padding-inline', '24px');
                    setImportant(el, 'box-sizing', 'border-box');
                });
                document.querySelectorAll('.page_footer .footer-container > .container').forEach(function (el) {
                    setImportant(el, 'padding-top', '16px');
                    setImportant(el, 'padding-bottom', '16px');
                    setImportant(el, 'padding-block', '16px');
                });
                document.querySelectorAll('.page_footer .awa-footer-newsletter').forEach(function (el) {
                    setImportant(el, 'padding-top', '14px');
                    setImportant(el, 'padding-bottom', '14px');
                    setImportant(el, 'padding-block', '14px');
                    setImportant(el, 'padding-left', '0');
                    setImportant(el, 'padding-right', '0');
                    setImportant(el, 'padding-inline', '0');
                    setImportant(el, 'margin-bottom', '0');
                });
            } else {
                document.querySelectorAll(
                    '.page_footer .footer-container > .container, ' +
                    '.page_footer .awa-footer-newsletter > .container, ' +
                    '.page_footer .footer-bottom > .container, ' +
                    '.page_footer .awa-footer-trust-bar > .container'
                ).forEach(function (el) {
                    [
                        'max-width', 'width', 'margin-left', 'margin-right', 'margin-inline',
                        'padding-left', 'padding-right', 'padding-inline',
                        'padding-top', 'padding-bottom', 'padding-block'
                    ].forEach(function (prop) { el.style.removeProperty(prop); });
                });
            }
        }
        document.querySelectorAll('.page_footer ul.awa-footer-categories-list a, .page-footer ul.awa-footer-categories-list a').forEach(function (el) {
            setImportant(el, 'border-radius', '999px');
        });

        document.querySelectorAll(
            '.page_footer .awa-newsletter-wrapper, .page-footer .awa-newsletter-wrapper'
        ).forEach(function (el) {
            if (isMobile) {
                setImportant(el, 'display', 'flex');
                setImportant(el, 'flex-direction', 'column');
                setImportant(el, 'align-items', 'stretch');
            } else {
                setImportant(el, 'display', 'flex');
                setImportant(el, 'flex-direction', 'row');
                setImportant(el, 'flex-wrap', 'nowrap');
                setImportant(el, 'align-items', 'center');
                setImportant(el, 'justify-content', 'flex-start');
                setImportant(el, 'grid-template-columns', 'none');
            }
        });

        document.querySelectorAll(
            '.page_footer .awa-newsletter-icon, .page-footer .awa-newsletter-icon'
        ).forEach(function (el) {
            setImportant(el, 'display', 'inline-flex');
            setImportant(el, 'flex', '0 0 24px');
            setImportant(el, 'width', '24px');
            setImportant(el, 'height', '24px');
            setImportant(el, 'min-width', '24px');
            setImportant(el, 'min-height', '24px');
            setImportant(el, 'max-width', '24px');
            setImportant(el, 'max-height', '24px');
            setImportant(el, 'margin', '0');
            setImportant(el, 'padding', '0');
            setImportant(el, 'color', 'var(--awa-primary)');
        });

        document.querySelectorAll(
            '.page_footer .awa-newsletter-form-container, .page-footer .awa-newsletter-form-container'
        ).forEach(function (el) {
            if (isMobile) {
                setImportant(el, 'flex', '1 1 auto');
                setImportant(el, 'width', '100%');
                setImportant(el, 'max-width', 'none');
            } else {
                setImportant(el, 'flex', '1 1 280px');
                setImportant(el, 'width', 'auto');
                setImportant(el, 'max-width', 'none');
            }
            setImportant(el, 'border', '0');
            setImportant(el, 'background', 'transparent');
        });

        document.querySelectorAll(
            '.page_footer #newsletter-validate-detail, ' +
            '.page-footer #newsletter-validate-detail'
        ).forEach(function (el) {
            setImportant(el, 'display', 'block');
            setImportant(el, 'width', '100%');
            setImportant(el, 'max-width', 'none');
            setImportant(el, 'margin', '0');
            setImportant(el, 'grid-template-columns', 'none');
        });

        document.querySelectorAll(
            '.page_footer .awa-newsletter-form-container .field.newsletter, ' +
            '.page-footer .awa-newsletter-form-container .field.newsletter'
        ).forEach(function (el) {
            if (isMobile) {
                setImportant(el, 'display', 'grid');
                setImportant(el, 'grid-template-columns', 'minmax(0,1fr)');
                setImportant(el, 'grid-template-rows', 'none');
                setImportant(el, 'gap', '8px');
                setImportant(el, 'padding', '0');
            } else {
                setImportant(el, 'display', 'grid');
                setImportant(el, 'grid-template-columns', 'minmax(0,1fr) auto');
                setImportant(el, 'grid-template-rows', '44px auto');
                setImportant(el, 'align-items', 'start');
                setImportant(el, 'column-gap', '8px');
                setImportant(el, 'row-gap', '6px');
                setImportant(el, 'flex-wrap', 'nowrap');
                setImportant(el, 'width', '100%');
                setImportant(el, 'height', 'auto');
                setImportant(el, 'min-height', '44px');
                setImportant(el, 'padding', '0');
                setImportant(el, 'padding-block', '0');
                setImportant(el, 'border', '0');
                setImportant(el, 'background', 'transparent');
                setImportant(el, 'box-shadow', 'none');
            }
        });

        document.querySelectorAll(
            '.page_footer .awa-newsletter-form-container .field.newsletter .control, ' +
            '.page_footer .awa-newsletter-form-container .field.newsletter .actions, ' +
            '.page-footer .awa-newsletter-form-container .field.newsletter .control, ' +
            '.page-footer .awa-newsletter-form-container .field.newsletter .actions'
        ).forEach(function (el) {
            if (isMobile) {
                setImportant(el, 'grid-column', '1');
                setImportant(el, 'grid-row', 'auto');
                setImportant(el, 'width', '100%');
                setImportant(el, 'max-width', '100%');
            } else if (el.classList && el.classList.contains('control')) {
                setImportant(el, 'grid-column', '1');
                setImportant(el, 'grid-row', '1');
                setImportant(el, 'min-width', '0');
                setImportant(el, 'width', 'auto');
                setImportant(el, 'max-width', 'none');
                setImportant(el, 'height', '44px');
                setImportant(el, 'margin', '0');
                setImportant(el, 'padding', '0');
            } else {
                setImportant(el, 'grid-column', '2');
                setImportant(el, 'grid-row', '1');
                setImportant(el, 'width', 'auto');
                setImportant(el, 'max-width', 'none');
                setImportant(el, 'height', '44px');
                setImportant(el, 'margin', '0');
                setImportant(el, 'padding', '0');
            }
        });

        document.querySelectorAll(
            '.page_footer .awa-newsletter-form-container .field.newsletter .action.subscribe, ' +
            '.page-footer .awa-newsletter-form-container .field.newsletter .action.subscribe'
        ).forEach(function (el) {
            if (isMobile) {
                setImportant(el, 'width', '100%');
                setImportant(el, 'max-width', '100%');
                setImportant(el, 'min-width', '0');
                setImportant(el, 'justify-content', 'center');
            } else {
                setImportant(el, 'width', 'auto');
                setImportant(el, 'max-width', 'none');
                setImportant(el, 'white-space', 'nowrap');
            }
        });

        document.querySelectorAll(
            '.page_footer #newsletter-validate-detail button.action.subscribe, ' +
            '.page-footer #newsletter-validate-detail button.action.subscribe'
        ).forEach(function (el) {
            if (isMobile) {
                setImportant(el, 'width', '100%');
                setImportant(el, 'max-width', '100%');
                setImportant(el, 'min-width', '0');
                setImportant(el, 'display', 'inline-flex');
                setImportant(el, 'justify-content', 'center');
            } else {
                setImportant(el, 'width', 'auto');
                setImportant(el, 'max-width', 'none');
                setImportant(el, 'white-space', 'nowrap');
            }
        });

        document.querySelectorAll('.page_footer .awa-footer-devby, .page-footer .awa-footer-devby').forEach(function (el) {
            setImportant(el, 'min-height', '32px');
            setImportant(el, 'height', 'auto');
        });

        document.querySelectorAll('.page_footer .footer-bottom, .page-footer .footer-bottom').forEach(function (el) {
            setImportant(el, 'box-sizing', 'border-box');
            /* cat-white-r10: literal #fff — body --awa-bg is soft oklch */
            applyFooterShellSurface(el, '#fff');
            setImportant(el, 'border', '0');
            setImportant(el, 'border-radius', '0');
            setImportant(el, 'color', 'var(--awa-text,CanvasText)');
/* PIXEL-QA: preencher shell do page-footer (sem calc-32 recentralizado). */
            setImportant(el, 'margin-inline', '0');
            setImportant(el, 'max-width', '100%');
            setImportant(el, 'width', '100%');
            /* visible: hidden cortava copyright/disclaimer no mobile (H91) */
            setImportant(el, 'overflow', 'visible');
            setImportant(el, 'box-shadow', 'none');
        });
    }

    function applyCatalogQuickviewFix() {
        if (!document.body || !document.body.matches('.catalog-category-view,.catalogsearch-result-index')) {
            return;
        }

        document.querySelectorAll('.products-grid .product-thumb').forEach(function (el) {
            setImportant(el, 'position', 'relative');
            setImportant(el, 'overflow', 'hidden');
        });

        document.querySelectorAll('.products-grid .quickview-link').forEach(function (el) {
            setImportant(el, 'box-sizing', 'border-box');
            setImportant(el, 'inline-size', '40px');
            setImportant(el, 'block-size', '40px');
            setImportant(el, 'max-width', '40px');
            setImportant(el, 'min-width', '0');
            setImportant(el, 'right', '0');
            setImportant(el, 'inset-inline-end', '0');
        });
    }

    function applyHeaderNavAxisFix() {
        if (!document.body || !window.matchMedia || !window.matchMedia('(min-width: 992px)').matches) {
            return;
        }

        /* Contrato: nav full-bleed; inner rail 1280 no CSS. Não travar geometria via style="". */
        document.querySelectorAll(
            '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky > .header-control.awa-nav-bar, ' +
            '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky > .header-control.header-nav.awa-nav-bar, ' +
            '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.awa-nav-bar, ' +
            '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.header-nav.awa-nav-bar, ' +
            '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.awa-nav-bar > .container, ' +
            '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.header-nav.awa-nav-bar > .container, ' +
            '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.awa-nav-bar .awa-nav-bar__inner, ' +
            '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.header-nav.awa-nav-bar .awa-nav-bar__inner'
        ).forEach(function (el) {
            if (!el || !el.style) {
                return;
            }
            ['width', 'max-width', 'margin-inline', 'margin-left', 'margin-right',
                'padding-inline', 'padding-left', 'padding-right', 'box-sizing']
                .forEach(function (prop) {
                    el.style.removeProperty(prop);
                });
        });
    }

    function applyVisualBugfixTerminal() {
        applyFooterShellFix();
        applyCatalogQuickviewFix();
        applyHeaderNavAxisFix();
    }

    if (document.readyState !== 'loading') {
        applyVisualBugfixTerminal();
    } else {
        document.addEventListener('DOMContentLoaded', applyVisualBugfixTerminal, { once: true });
    }

    document.addEventListener('awa:css-gate-applied', applyVisualBugfixTerminal, { passive: true });
    window.addEventListener('load', applyVisualBugfixTerminal, { once: true, passive: true });
    window.setTimeout(applyVisualBugfixTerminal, 800);
    window.setTimeout(applyVisualBugfixTerminal, 2400);
    window.setTimeout(applyVisualBugfixTerminal, 5000);
}());
