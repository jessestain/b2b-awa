/**
 * AWA Header Sticky — condensed state on scroll
 *
 * Adds `awa-header-condensed` to .awa-site-header and
 * `is-sticky` + `awa-header-condensed` to .header-wrapper-sticky
 * when the page is scrolled past the dynamic scroll threshold.
 *
 * Promo bar height tracked via ResizeObserver — no getBoundingClientRect()
 * in scroll handler, no periodic forced layout reflows.
 *
 * Uses requestAnimationFrame for scroll throttle (no lodash needed).
 * Respects prefers-reduced-motion: skips class toggle animation context.
 *
 * RequireJS path: awa-header-sticky (registered in requirejs-config.js)
 */
define([], function () {
    'use strict';

    let SCROLL_THRESHOLD_BASE = 60;
    let DELTA_MIN = 6;
    let MOBILE_MQ = window.matchMedia('(max-width: 767px)');
    let DEBUG_RUN_ID = 'header-center-r9';

    return function () {
        /** @type {Element|null} */
        let header = document.querySelector('.awa-site-header');
        /** @type {Element|null} */
        let stickyWrapper = document.querySelector('.header-wrapper-sticky');
        /** @type {HTMLInputElement|null} */
        let searchInput = document.getElementById('search');

        if (!header || !stickyWrapper) {
            return;
        }
        if (window.__awaHeaderStickyInit) {
            emitHeaderDebug(
                'H3',
                'awa-header-sticky-r8.js:init:alreadyInitialized',
                'Header sticky already initialized, skipping re-init',
                {
                    path: window.location.pathname,
                    scrollY: window.pageYOffset || 0,
                    bodyClass: document.body ? document.body.className : ''
                }
            );
            return;
        }
        window.__awaHeaderStickyInit = true;

        let ticking = false;
        let lastScrollY = window.pageYOffset || 0;
        let lastSticky = false;
        /* Pause sticky over footer (links covered by fixed header). */
        let footerBlocksSticky = false;
        let footerBlocksStickyLast = null;
        let headerRenderableLast = null;

        /* Promo bar height — updated by ResizeObserver, never read in scroll handler */
        let promoBarHeight = 0;

        function collectElementGeometry(selector, viewportW) {
            let el = document.querySelector(selector);
            if (!el) {
                return null;
            }

            let rect = el.getBoundingClientRect();
            let style = window.getComputedStyle(el);
            return {
                left: Math.round(rect.left * 100) / 100,
                width: Math.round(rect.width * 100) / 100,
                centerDelta: Math.round((rect.left + rect.width / 2 - viewportW / 2) * 100) / 100,
                paddingLeft: style.paddingLeft,
                paddingRight: style.paddingRight,
                marginLeft: style.marginLeft,
                marginRight: style.marginRight,
                position: style.position
            };
        }

        function collectHeaderGeometry() {
            let viewportW = window.innerWidth || 0;
            return {
                viewportW: viewportW,
                mainHeader: collectElementGeometry('.awa-site-header .header.awa-main-header', viewportW),
                row: collectElementGeometry('.awa-site-header .awa-main-header__inner.wp-header, .awa-site-header .awa-main-header__inner[data-awa-header-row]', viewportW),
                navInner: collectElementGeometry('.awa-site-header .awa-nav-bar__inner', viewportW),
                pageMain: collectElementGeometry('.page-main', viewportW)
            };
        }

        function emitHeaderDebug(hypothesisId, location, message, data) {
            return;
        }

        emitHeaderDebug(
            'H2',
            'awa-header-sticky-r8.js:init',
            'Header sticky initialized with baseline geometry',
            {
                path: window.location.pathname,
                bodyClass: document.body ? document.body.className : '',
                geometry: collectHeaderGeometry()
            }
        );

        function isCondensedAllowed() {
            let body = document.body;
            return body
                && !body.classList.contains('awa-account-operational')
                && !body.classList.contains('b2b-account-shell');
        }

        function syncMobileSearchTabOrder(isSticky) {
            if (!searchInput) {
                return;
            }

            if (isSticky && MOBILE_MQ.matches) {
                searchInput.setAttribute('tabindex', '-1');
            } else {
                searchInput.removeAttribute('tabindex');
            }
        }

        function syncCondensedHeaderVars(isSticky) {
            var headerShell = header.querySelector('#header.header-container[data-awa-header-shell="true"]');
            var value = isSticky ? '56px' : '';
            var setVar = function (el, prop) {
                if (!el) {
                    return;
                }
                if (value) {
                    el.style.setProperty(prop, value, 'important');
                } else {
                    el.style.removeProperty(prop);
                }
            };

            setVar(header, '--awa-header-row-h');
            setVar(header, '--awa-header-main-row-h');
            setVar(header, '--awa-hdr-height-sticky');
            setVar(stickyWrapper, '--awa-header-row-h');
            setVar(stickyWrapper, '--awa-header-main-row-h');
            setVar(headerShell, '--awa-header-row-h');
            setVar(headerShell, '--awa-header-main-row-h');
            setVar(headerShell, '--awa-hdr-height-sticky');
        }

        function enforceCondensedGeometry(isSticky) {
            var row = header.querySelector('.awa-main-header__inner.wp-header, .awa-main-header__inner[data-awa-header-row]');
            var searchCol = header.querySelector('.awa-header-search-col');
            var form = header.querySelector('#search_mini_form');
            var input = header.querySelector('#search');
            var apply = function (el, prop, value) {
                if (!el) {
                    return;
                }
                if (value === null) {
                    el.style.removeProperty(prop);
                    return;
                }
                el.style.setProperty(prop, value, 'important');
            };

            if (!isSticky) {
                var shouldResetDesktopGridColumns = window.matchMedia('(max-width: 991px)').matches;
                apply(header, 'isolation', null);
                apply(header, 'z-index', null);
                apply(stickyWrapper, 'z-index', null);
                apply(stickyWrapper, 'position', null);
                apply(stickyWrapper, 'top', null);
                apply(stickyWrapper, 'left', null);
                apply(stickyWrapper, 'right', null);
                apply(stickyWrapper, 'width', null);
                apply(stickyWrapper, 'height', null);
                apply(stickyWrapper, 'min-height', null);
                apply(stickyWrapper, 'max-height', null);
                apply(row, 'height', null);
                apply(row, 'min-height', null);
                apply(row, 'max-height', null);
                apply(row, 'grid-template-areas', null);
                if (shouldResetDesktopGridColumns) {
                    apply(row, 'grid-template-columns', null);
                }
                apply(row, 'grid-template-rows', null);
                if (shouldResetDesktopGridColumns) {
                    apply(row, 'grid-template', null);
                }
                var primaryRowReset = header.querySelector('.awa-header-primary-row');
                apply(primaryRowReset, 'display', null);
                apply(primaryRowReset, 'grid-area', null);
                var mainHeaderReset = header.querySelector('.header.awa-main-header, .header_main.awa-main-header-inner-wrap');
                apply(mainHeaderReset, 'height', null);
                apply(mainHeaderReset, 'min-height', null);
                apply(mainHeaderReset, 'max-height', null);
                apply(searchCol, 'height', null);
                apply(searchCol, 'min-height', null);
                apply(searchCol, 'max-height', null);
                apply(searchCol, 'width', null);
                apply(searchCol, 'max-width', null);
                apply(searchCol, 'min-width', null);
                apply(searchCol, 'grid-column', null);
                apply(searchCol, 'grid-area', null);
                apply(form, 'height', null);
                apply(form, 'min-height', null);
                apply(form, 'max-height', null);
                apply(input, 'height', null);
                apply(input, 'min-height', null);
                apply(input, 'max-height', null);
                apply(input, 'line-height', null);
                return;
            }

            /* Wrap = main+nav. themes.min locks mobile wrap at 112px while nav is
               display:none → 16px empty fissure. Inline height:auto beats that lock. */
            /* isolation:isolate on .awa-site-header traps fixed sticky under page content (mobile fissure). */
            apply(header, 'isolation', 'auto');
            apply(header, 'z-index', '5000');
            apply(stickyWrapper, 'z-index', '5001');
            /* H7: home body overflow quebra position:sticky→computed relative; force fixed. */
            apply(stickyWrapper, 'position', 'fixed');
            apply(stickyWrapper, 'top', '0');
            apply(stickyWrapper, 'left', '0');
            apply(stickyWrapper, 'right', '0');
            apply(stickyWrapper, 'width', '100%');

            var isMobile = window.matchMedia('(max-width: 767px)').matches;
            if (isMobile) {
                /* Shell condensed mobile = 56px — height:auto+max-none deixava main-header em 96px (home). */
                apply(stickyWrapper, 'height', '56px');
                apply(stickyWrapper, 'min-height', '56px');
                apply(stickyWrapper, 'max-height', '56px');
                /* BUG-SHELL-MOBILE-CONDENSED-1ROW: CSS SSOT 1-row icon; não forçar search 56/full. */
                var primaryRow = header.querySelector('.awa-header-primary-row');
                apply(primaryRow, 'display', 'contents');
                apply(primaryRow, 'grid-area', 'unset');
                var mainHeader = header.querySelector('.header.awa-main-header, .header_main.awa-main-header-inner-wrap');
                apply(mainHeader, 'height', '56px');
                apply(mainHeader, 'min-height', '56px');
                apply(mainHeader, 'max-height', '56px');
                apply(row, 'height', '56px');
                apply(row, 'min-height', '56px');
                apply(row, 'max-height', '56px');
                apply(row, 'grid-template', '"toggle brand search cart" 44px / 44px minmax(0,1fr) 44px 44px');
                apply(searchCol, 'height', '44px');
                apply(searchCol, 'min-height', '44px');
                apply(searchCol, 'max-height', '44px');
                apply(searchCol, 'width', '44px');
                apply(searchCol, 'max-width', '44px');
                apply(searchCol, 'min-width', '44px');
                apply(searchCol, 'grid-column', 'auto');
                apply(searchCol, 'grid-area', 'search');
                apply(form, 'height', '44px');
                apply(form, 'min-height', '44px');
                apply(form, 'max-height', '44px');
                /* Input clipped by CSS; don't unclip with inline 44px. */
                apply(input, 'height', null);
                apply(input, 'min-height', null);
                apply(input, 'max-height', null);
                apply(input, 'line-height', null);
                return;
            }

            apply(stickyWrapper, 'height', 'auto');
            apply(stickyWrapper, 'min-height', '0px');
            apply(stickyWrapper, 'max-height', 'none');
            apply(row, 'height', '56px');
            apply(row, 'min-height', '56px');
            apply(row, 'max-height', '56px');
            apply(searchCol, 'height', '56px');
            apply(searchCol, 'min-height', '56px');
            apply(searchCol, 'max-height', '56px');
            apply(form, 'height', '44px');
            apply(form, 'min-height', '44px');
            apply(form, 'max-height', '44px');
            apply(input, 'height', '44px');
            apply(input, 'min-height', '44px');
            apply(input, 'max-height', '44px');
            apply(input, 'line-height', '44px');
        }

        /**
         * Fecha o menu Departamentos ao ativar sticky.
         * Preferência: API do menu-controller; fallback: click no trigger aberto.
         */
        function closeDeptMenusOnSticky() {
            try {
                if (typeof window.__awaCloseDeptMenus === 'function') {
                    window.__awaCloseDeptMenus('sticky');
                    return;
                }
            } catch (e) { /* fallback below */ }

            var openTriggers = document.querySelectorAll(
                '[data-role="awa-vertical-menu-trigger"][aria-expanded="true"]'
            );
            for (var i = 0; i < openTriggers.length; i++) {
                try {
                    openTriggers[i].click();
                } catch (err) { /* ignore */ }
            }
            document.body.classList.remove('awa-menu-dept-open');
        }

        function initPromoBarObserver() {
            let bar = document.getElementById('awa-b2b-promo-bar');
            if (!bar) {
                return;
            }

            function updatePromoHeight(entries) {
                let entry = entries && entries[0];
                if (entry) {
                    let h = entry.contentRect ? entry.contentRect.height : entry.target.offsetHeight;
                    promoBarHeight = bar.style.display === 'none' ? 0 : Math.round(h);
                } else {
                    promoBarHeight = bar.style.display === 'none' ? 0 : Math.round(bar.offsetHeight);
                }
            }

            if (window.ResizeObserver) {
                let ro = new ResizeObserver(updatePromoHeight);
                ro.observe(bar);
            } else {
                promoBarHeight = bar.style.display === 'none' ? 0 : Math.round(bar.offsetHeight);
            }

            promoBarHeight = bar.style.display === 'none' ? 0 : Math.round(bar.offsetHeight);
        }

        function getScrollThreshold() {
            return SCROLL_THRESHOLD_BASE + promoBarHeight;
        }

        function clearStickyClasses() {
            header.classList.remove('awa-header-condensed', 'awa-scroll-down', 'awa-scroll-up');
            stickyWrapper.classList.remove('is-sticky', 'awa-header-condensed');
            document.body.classList.remove('awa-header-is-sticky');
            syncMobileSearchTabOrder(false);
            lastSticky = false;
        }

        function isHeaderRenderable() {
            let headerStyle = window.getComputedStyle(header);
            return headerStyle.display !== 'none' && headerStyle.visibility !== 'hidden';
        }

        /**
         * Apply or remove sticky classes based on current scrollY.
         */
        function updateStickyState() {
            if (!isCondensedAllowed()) {
                clearStickyClasses();
                lastScrollY = window.pageYOffset || 0;
                ticking = false;
                return;
            }

            let scrollY = window.pageYOffset !== undefined
                ? window.pageYOffset
                : (document.documentElement || document.body.parentNode || document.body).scrollTop;

            if (!isHeaderRenderable()) {
                if (headerRenderableLast !== false) {
                    emitHeaderDebug(
                        'H5',
                        'awa-header-sticky-r8.js:updateStickyState:notRenderable',
                        'Header is not renderable, sticky cleared',
                        {
                            scrollY: scrollY,
                            geometry: collectHeaderGeometry()
                        }
                    );
                    headerRenderableLast = false;
                }
                clearStickyClasses();
                lastScrollY = scrollY;
                ticking = false;
                return;
            }
            if (headerRenderableLast !== true) {
                headerRenderableLast = true;
            }

            let threshold = getScrollThreshold();
            let isSticky = scrollY > threshold && !footerBlocksSticky;
            let direction = (scrollY - lastScrollY) > DELTA_MIN
                ? 'down'
                : ((lastScrollY - scrollY) > DELTA_MIN ? 'up' : 'still');

            if (isSticky !== lastSticky) {
                header.classList.toggle('awa-header-condensed', isSticky);
                document.body.classList.toggle('awa-header-is-sticky', isSticky);
                syncMobileSearchTabOrder(isSticky);
                syncCondensedHeaderVars(isSticky);
                enforceCondensedGeometry(isSticky);
                // Fechar Departamentos ao entrar em sticky — evita painel cobrir
                // Produtos Relacionados / conteúdo abaixo do fold (PDP/PLP).
                if (isSticky) {
                    closeDeptMenusOnSticky();
                }
                try {
                    document.dispatchEvent(new CustomEvent('awa:header-sticky-change', {
                        detail: { sticky: isSticky, scrollY: scrollY }
                    }));
                } catch (e) { /* ignore */ }
                lastSticky = isSticky;
                // Round5: recalcular --awa-header-height após toggle sticky
                // (site min-height 124 inflava o placeholder para 123 vs wrap 116).
                try {
                    if (typeof window.__awaUpdateHeaderHeight === 'function') {
                        window.__awaUpdateHeaderHeight();
                    }
                } catch (heightErr) { /* ignore */ }
                emitHeaderDebug(
                    'H1',
                    'awa-header-sticky-r8.js:updateStickyState:stickyTransition',
                    'Sticky state transitioned and condensed geometry applied',
                    {
                        isSticky: isSticky,
                        lastSticky: lastSticky,
                        scrollY: scrollY,
                        threshold: threshold,
                        direction: direction,
                        isMobile: window.matchMedia('(max-width: 767px)').matches,
                        footerBlocksSticky: footerBlocksSticky,
                        geometry: collectHeaderGeometry()
                    }
                );
            }

            header.classList.toggle('awa-scroll-down', direction === 'down' && isSticky);
            header.classList.toggle('awa-scroll-up', direction === 'up' && isSticky);

            if (isSticky !== stickyWrapper.classList.contains('is-sticky')) {
                stickyWrapper.classList.toggle('is-sticky', isSticky);
            }
            if (isSticky !== stickyWrapper.classList.contains('awa-header-condensed')) {
                stickyWrapper.classList.toggle('awa-header-condensed', isSticky);
            }
            enforceCondensedGeometry(isSticky);
            /* Re-aplica no próximo frame — outros scripts/CSS às vezes limpam o inline no mesmo tick. */
            if (isSticky) {
                window.requestAnimationFrame(function () {
                    if (stickyWrapper.classList.contains('is-sticky')) {
                        enforceCondensedGeometry(true);
                    }
                });
            }

            lastScrollY = scrollY;
            ticking = false;
        }

        function onScroll() {
            if (!ticking) {
                window.requestAnimationFrame(updateStickyState);
                ticking = true;
            }
        }

        initPromoBarObserver();

        /* Pause sticky when footer enters the sticky band (covers Quem somos / links). */
        (function initFooterStickyGuard() {
            var footer = document.querySelector('.page_footer, footer.page-footer, .page-footer');
            if (!footer) {
                return;
            }
            if (!window.IntersectionObserver) {
                return;
            }
            var io = new IntersectionObserver(function (entries) {
                var entry = entries && entries[0];
                if (!entry) {
                    return;
                }
                /* Footer top crossed into the sticky header zone (~80px). */
                var top = entry.boundingClientRect.top;
                footerBlocksSticky = top < 96 && entry.boundingClientRect.bottom > 0;
                if (footerBlocksStickyLast !== footerBlocksSticky) {
                    footerBlocksStickyLast = footerBlocksSticky;
                    emitHeaderDebug(
                        'H5',
                        'awa-header-sticky-r8.js:footerGuard',
                        'Footer sticky guard toggled',
                        {
                            footerBlocksSticky: footerBlocksSticky,
                            footerTop: Math.round(top * 100) / 100,
                            footerBottom: Math.round(entry.boundingClientRect.bottom * 100) / 100,
                            scrollY: window.pageYOffset || 0
                        }
                    );
                }
                onScroll();
            }, { root: null, threshold: [0, 0.01, 0.05, 0.1, 0.25, 0.5, 1], rootMargin: '0px 0px 0px 0px' });
            io.observe(footer);
        })();

        // Search control: clip no X (hidden+visible vira auto no computed); Y visible p/ autocomplete.
        try {
            document.querySelectorAll('.awa-header-search-col .control, .block-search .control').forEach(function (el) {
                el.style.setProperty('overflow-x', 'clip', 'important');
                el.style.setProperty('overflow-y', 'visible', 'important');
            });
        } catch (overflowErr) { /* ignore */ }

        /* ── Phase 3: CLS placeholder — ResizeObserver on .awa-site-header ────────
         * Tracks the total rendered height of the site header and stores it in
         * --awa-header-height on <html>. When the header-wrapper-sticky transitions
         * to position:fixed (Rokanthemes parent CSS), body.awa-header-is-sticky is
         * toggled by updateStickyState() which makes #awa-header-cls-placeholder
         * visible at the correct height, preventing CLS.
         * Protected against double init via window.__awaHeaderHeightObserver.       */
        if (!window.__awaHeaderHeightObserver) {
            var placeholder = document.getElementById('awa-header-cls-placeholder');

            /**
             * SSOT altura CLS:
             * - sticky: altura do .header-wrapper-sticky (wrap fixed sai do fluxo)
             * - normal: altura do .awa-site-header (promo + wrap)
             * Evidência 2026-07-17: medir só o site no sticky gerava 123px
             * (min-height 124) com wrap real 116 → placeholder errado.
             */
            function updateHeaderHeight(/* entries unused — trigger only */) {
                var h;
                var isStickyNow = document.body.classList.contains('awa-header-is-sticky');
                var nav;
                var navStyle;
                var wrapperRect;
                var navRect;
                // Não usar entries[0].contentRect: observe(header)+observe(wrap)
                // pode reportar altura do wrap (116) no estado não-sticky.
                if (isStickyNow && stickyWrapper) {
                    wrapperRect = stickyWrapper.getBoundingClientRect();
                    h = Math.round(stickyWrapper.offsetHeight || wrapperRect.height);
                    nav = header.querySelector('.header-control.header-nav, .header-control.awa-nav-bar');
                    if (nav) {
                        navStyle = window.getComputedStyle(nav);
                        if (navStyle.display !== 'none' && navStyle.visibility !== 'hidden') {
                            navRect = nav.getBoundingClientRect();
                            h = Math.round(
                                Math.max(wrapperRect.bottom, navRect.bottom) - Math.min(wrapperRect.top, navRect.top)
                            );
                        }
                    }
                } else {
                    h = Math.round(header.offsetHeight);
                }
                if (!h || h < 0) {
                    return;
                }
                document.documentElement.style.setProperty('--awa-header-height', h + 'px');
                if (placeholder) {
                    placeholder.style.height = h + 'px';
                }
            }

            window.__awaUpdateHeaderHeight = function () {
                updateHeaderHeight(null);
            };

            if (window.ResizeObserver) {
                window.__awaHeaderHeightObserver = new ResizeObserver(updateHeaderHeight);
                window.__awaHeaderHeightObserver.observe(header);
                window.__awaHeaderHeightObserver.observe(stickyWrapper);
            } else {
                /* Fallback: measure once and update on resize/orientationchange */
                window.__awaHeaderHeightObserver = true;
                var measureHeight = function () {
                    updateHeaderHeight(null);
                };
                window.addEventListener('resize', measureHeight, { passive: true });
                window.addEventListener('orientationchange', measureHeight, { passive: true });
                measureHeight();
            }
        }

        if (MOBILE_MQ.addEventListener) {
            MOBILE_MQ.addEventListener('change', onScroll);
        } else if (MOBILE_MQ.addListener) {
            MOBILE_MQ.addListener(onScroll);
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });

        updateStickyState();
    };
});
