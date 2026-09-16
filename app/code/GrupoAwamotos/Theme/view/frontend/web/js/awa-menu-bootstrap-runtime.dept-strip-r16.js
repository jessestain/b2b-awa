(function (w, d) {
    'use strict';
    w.__fa6f22BootstrapLoaded = (w.__fa6f22BootstrapLoaded || 0) + 1;
    if (!w.__AWA_MENU_V2) {
        return;
    }

    /* Mobile hamburger preflight (1º toque antes do controller carregar) */
    if (w.matchMedia && w.matchMedia('(max-width: 767px)').matches) {
        function closeNavPreflight() {
            d.body.classList.remove('nav-open', 'awa-nav-preflight', 'awa-menu-drawer-open', 'nav-before-open');
            var toggle = d.querySelector('[data-awa-nav-toggle="true"]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
            var shell = d.querySelector('[data-awa-nav-shell="true"]')
                || d.getElementById('awa-category-navigation')
                || d.getElementById('awa-primary-navigation');
            if (shell) {
                shell.classList.remove('is-awa-mobile-open');
                ['display', 'visibility', 'opacity', 'pointer-events', 'position', 'width', 'height', 'z-index'].forEach(function (prop) {
                    shell.style.removeProperty(prop);
                });
            }
            var overlay = d.querySelector('.awa-mobile-drawer-overlay');
            if (overlay) {
                overlay.classList.remove('is-active');
            }
        }

        function onNavPreflight(ev) {
            var toggle = ev.target && ev.target.closest
                ? ev.target.closest('[data-awa-nav-toggle="true"], [data-action="toggle-nav"], .toggle-nav-footer')
                : null;
            if (!toggle || d.body.classList.contains('awa-menu-v2-ready')) {
                return;
            }
            if (!d.body.classList.contains('nav-open') && !d.body.classList.contains('awa-menu-drawer-open')) {
                d.body.classList.add('nav-open', 'awa-nav-preflight', 'awa-menu-drawer-open');
                toggle.setAttribute('aria-expanded', 'true');
                var shell = d.querySelector('[data-awa-nav-shell="true"]')
                    || d.getElementById('awa-category-navigation')
                    || d.getElementById('awa-primary-navigation');
                if (shell) {
                    shell.classList.add('is-awa-mobile-open');
                }
                var overlay = d.querySelector('.awa-mobile-drawer-overlay');
                if (overlay) {
                    overlay.classList.add('is-active');
                }
            }
        }

        d.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape' || d.body.classList.contains('awa-menu-v2-ready')) {
                return;
            }
            if (d.body.classList.contains('nav-open') || d.body.classList.contains('awa-menu-drawer-open')) {
                closeNavPreflight();
            }
        }, true);

        d.addEventListener('pointerdown', onNavPreflight, { capture: true, passive: true });
    }

    var cfg = (function () {
        try {
            var node = d.getElementById('awa-menu-bootstrap-config');
            return node ? JSON.parse(node.textContent || '{}') : {};
        } catch (e) {
            return {};
        }
    }());
    var controllerUrl = cfg.controllerUrl || '';
    var floatingCoreUrl = cfg.floatingCoreUrl || '';
    var floatingDomUrl = cfg.floatingDomUrl || '';
    var menuConfig = cfg.menuConfig || {};
    var isHome = !!cfg.isHome;
    var booted = false;
    var floatingLoading = false;
    /* Path absoluto + cache-bust: o merge requirejs às vezes não registra paths do tema. */
    /* RequireJS acrescenta .js — não duplicar no path absoluto do bootstrap config. */
    var controllerPath = String(controllerUrl || '').replace(/\.js(?:\?.*)?$/i, '');

    function stripDeptTriggerInlineTypography(trigger) {
        if (!trigger) {
            return;
        }

        ['font-size', 'font-weight', 'line-height'].forEach(function (prop) {
            trigger.style.removeProperty(prop);
        });

        var triggerText = trigger.querySelector('.awa-vmenu-trigger-text');
        if (!triggerText) {
            return;
        }

        ['font-size', 'font-weight', 'line-height'].forEach(function (prop) {
            triggerText.style.removeProperty(prop);
        });
    }

    function guardAllDeptTriggerTypography(source) {
        var guard = typeof w.__awaGuardDeptTriggerTypography === 'function'
            ? w.__awaGuardDeptTriggerTypography
            : stripDeptTriggerInlineTypography;

        d.querySelectorAll('[data-role="awa-vertical-menu-trigger"]').forEach(function (trigger) {
            guard(trigger);
        });
    }

    function scheduleDeptTriggerTypographyGuard(source) {
        guardAllDeptTriggerTypography(source);
        w.requestAnimationFrame(function () {
            guardAllDeptTriggerTypography(source + ':raf');
        });
        [0, 50, 300, 1200].forEach(function (delay) {
            w.setTimeout(function () {
                guardAllDeptTriggerTypography(source + ':t' + delay);
            }, delay);
        });
    }

    function loadScriptOnce(src, id) {
        return new Promise(function (resolve) {
            if (!src) {
                resolve();
                return;
            }
            if (id && d.getElementById(id)) {
                resolve();
                return;
            }
            var existing = d.querySelector('script[src="' + src + '"]');
            if (existing) {
                if (existing.dataset.awaLoaded === '1' || existing.getAttribute('data-awa-loaded') === '1') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', function () { resolve(); }, { once: true });
                existing.addEventListener('error', function () { resolve(); }, { once: true });
                return;
            }
            var s = d.createElement('script');
            if (id) {
                s.id = id;
            }
            s.src = src;
            s.async = true;
            s.addEventListener('load', function () {
                s.dataset.awaLoaded = '1';
                resolve();
            }, { once: true });
            s.addEventListener('error', function () { resolve(); }, { once: true });
            (d.head || d.documentElement).appendChild(s);
        });
    }

    function ensureFloatingUi(done) {
        if (w.FloatingUIDOM) {
            done();
            return;
        }
        if (floatingLoading) {
            w.setTimeout(function () { ensureFloatingUi(done); }, 50);
            return;
        }
        floatingLoading = true;
        loadScriptOnce(floatingCoreUrl, 'awa-floating-ui-core')
            .then(function () { return loadScriptOnce(floatingDomUrl, 'awa-floating-ui-dom'); })
            .then(function () {
                floatingLoading = false;
                done();
            });
    }

    function applyController() {
        ensureFloatingUi(function () {
            if (booted || typeof w.require !== 'function') {
                if (!booted && typeof w.require !== 'function') {
                    w.setTimeout(applyController, 150);
                }
                return;
            }
            booted = true;
            if (typeof w.require.config === 'function' && controllerPath) {
                w.require.config({
                    paths: {
                        'awa-menu-controller': controllerPath
                    }
                });
            }
            w.require(['awa-menu-controller', 'mage/apply/main'], function (MenuController) {
                d.querySelectorAll('[data-role="awa-vertical-menu"]').forEach(function (nav) {
                    if (nav.dataset.awaMenuControllerReady === '1') {
                        return;
                    }
                    MenuController(menuConfig, nav);
                });
                scheduleDeptTriggerTypographyGuard('post-controller');
            });
        });
    }

    function scheduleBoot() {
        if (isHome) {
            var menuEl = d.querySelector('[data-role="awa-vertical-menu"]');
            if (menuEl) {
                menuEl.addEventListener('mouseenter', applyController, { once: true, passive: true });
                menuEl.addEventListener('pointerdown', applyController, { once: true, passive: true });
            }
            // r44f: idle timeout 2500 no DCL competia com paint LCP (CPU ~2.1s em
            // awa-menu-controller; LCP render ~2.6–2.9s com img já em ~400ms).
            // Interação continua eager; boot automático só após window.load + idle.
            function deferHomeController() {
                if (w.requestIdleCallback) {
                    w.requestIdleCallback(applyController, { timeout: 8000 });
                } else {
                    w.setTimeout(applyController, 4000);
                }
            }
            if (d.readyState === 'complete') {
                deferHomeController();
            } else {
                w.addEventListener('load', deferHomeController, { once: true });
            }
            return;
        }
        applyController();
    }

    /* Departamentos preflight — 1º clique abre painel antes do controller */
    var deptTrigger = d.querySelector('[data-role="awa-vertical-menu-trigger"]');
    var deptPanel = d.querySelector('[data-role="awa-vertical-menu-panel"]');
    if (deptTrigger && deptPanel) {
        (function ensureDesktopClickFocusFallbackStyle() {
            if (d.getElementById('awa-dept-click-focus-fallback')) {
                return;
            }
            var style = d.createElement('style');
            style.id = 'awa-dept-click-focus-fallback';
            style.textContent = '@media (min-width: 992px) {'
                + '.navigation.verticalmenu.side-verticalmenu:hover > [data-role="awa-vertical-menu-panel"],'
                + '[data-role="awa-vertical-menu"]:hover > [data-role="awa-vertical-menu-panel"],'
                + '[data-role="awa-vertical-menu-trigger"]:hover ~ [data-role="awa-vertical-menu-panel"],'
                + '[data-role="awa-vertical-menu-trigger"]:active ~ [data-role="awa-vertical-menu-panel"],'
                + '[data-role="awa-vertical-menu-trigger"]:focus ~ [data-role="awa-vertical-menu-panel"],'
                + '[data-role="awa-vertical-menu-trigger"]:focus-visible ~ [data-role="awa-vertical-menu-panel"],'
                + '[data-role="awa-vertical-menu-panel"]:hover,'
                + '[data-role="awa-vertical-menu-panel"]:focus-within {'
                + 'display:flex!important;flex-direction:column!important;visibility:visible!important;opacity:1!important;pointer-events:auto!important;'
                + 'height:auto!important;min-height:120px!important;max-height:min(70vh,560px)!important;overflow-x:hidden!important;overflow-y:auto!important;}'
                + '}';
            d.head.appendChild(style);
        }());

        function isDesktopMenuViewport() {
            return !(w.matchMedia && w.matchMedia('(max-width: 991px)').matches);
        }

        function collectDeptNodePairs() {
            var result = [];
            var navNodes = d.querySelectorAll('[data-role="awa-vertical-menu"], .navigation.verticalmenu.side-verticalmenu');
            var i;
            var nav;
            var trigger;
            var panel;
            for (i = 0; i < navNodes.length; i += 1) {
                nav = navNodes[i];
                trigger = nav.querySelector('[data-role="awa-vertical-menu-trigger"], .title-category-dropdown.our_categories');
                panel = nav.querySelector('[data-role="awa-vertical-menu-panel"]');
                if (!trigger || !panel) {
                    continue;
                }
                result.push({ trigger: trigger, panel: panel, nav: nav });
            }
            return result;
        }

        function resolveDeptNodesFromIntent(target, clientX, clientY) {
            var pairs = collectDeptNodePairs();
            var intentTrigger = target && target.closest
                ? target.closest('[data-role="awa-vertical-menu-trigger"], .title-category-dropdown.our_categories')
                : null;
            var i;
            var rect;
            var stickyPair = null;
            var chosenPair = null;
            if (!pairs.length) {
                return null;
            }
            if (intentTrigger) {
                for (i = 0; i < pairs.length; i += 1) {
                    if (pairs[i].trigger === intentTrigger) {
                        chosenPair = pairs[i];
                        break;
                    }
                }
            }
            if (!chosenPair && typeof clientX === 'number' && typeof clientY === 'number') {
                for (i = 0; i < pairs.length; i += 1) {
                    rect = pairs[i].trigger.getBoundingClientRect();
                    if (clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom) {
                        chosenPair = pairs[i];
                        break;
                    }
                }
            }
            if (!chosenPair && d.body.classList.contains('awa-header-is-sticky')) {
                for (i = 0; i < pairs.length; i += 1) {
                    if (pairs[i].trigger.closest('.header-wrapper-sticky.is-sticky, .awa-header-condensed')) {
                        stickyPair = pairs[i];
                        break;
                    }
                }
                if (stickyPair) {
                    chosenPair = stickyPair;
                }
            }
            if (!chosenPair) {
                chosenPair = pairs[0];
            }
            return chosenPair;
        }

        function getCurrentDeptNodes() {
            var pairs = collectDeptNodePairs();
            var chosen = resolveDeptNodesFromIntent(d.activeElement, null, null);
            var i;
            var trigger;
            var panel;
            if (!chosen && pairs.length) {
                for (i = 0; i < pairs.length; i += 1) {
                    if (typeof pairs[i].trigger.matches === 'function' && pairs[i].trigger.matches(':hover')) {
                        chosen = pairs[i];
                        break;
                    }
                }
            }
            if (!chosen && pairs.length) {
                chosen = pairs[0];
            }
            trigger = chosen ? chosen.trigger : null;
            panel = chosen ? chosen.panel : null;
            if (!trigger || !panel) {
                return null;
            }
            return { trigger: trigger, panel: panel };
        }

        function isDeptPanelOpen(triggerEl, panelEl) {
            var nodes;
            var t;
            var p;
            if (triggerEl && panelEl) {
                t = triggerEl;
                p = panelEl;
            } else {
                nodes = getCurrentDeptNodes();
                t = nodes ? nodes.trigger : deptTrigger;
                p = nodes ? nodes.panel : deptPanel;
            }
            if (!t || !p) {
                return false;
            }
            return t.getAttribute('aria-expanded') === 'true'
                || p.getAttribute('data-awa-menu-state') === 'open'
                || p.classList.contains('vmm-open')
                || p.classList.contains('menu-open');
        }

        function forceOpenDeptPanelOn(triggerEl, panelEl) {
            var t = triggerEl || deptTrigger;
            var p = panelEl || deptPanel;
            var nav;
            var shell;
            var categoryItems;
            var categoryContent;
            var categoryRoot;
            if (!t || !p) {
                return;
            }
            d.body.classList.add('awa-menu-dept-open');
            t.setAttribute('aria-expanded', 'true');
            t.classList.add('active');
            p.setAttribute('data-awa-menu-state', 'open');
            p.setAttribute('aria-hidden', 'false');
            p.classList.add('vmm-open', 'menu-open');
            p.style.setProperty('display', 'flex', 'important');
            p.style.setProperty('flex-direction', 'column', 'important');
            p.style.setProperty('visibility', 'visible', 'important');
            p.style.setProperty('opacity', '1', 'important');
            nav = p.closest('[data-role="awa-vertical-menu"]');
            shell = nav && nav.closest('.menu_left_home1, .awa-header-categories, .sections.nav-sections');
            categoryItems = p.closest('#awa-category-navigation, .section-items.nav-sections.category-dropdown-items');
            categoryContent = p.closest('#menu\\.vertical, .section-item-content.nav-sections.category-dropdown-item-content');
            categoryRoot = p.closest('.sections.nav-sections.category-dropdown');
            if (categoryItems) {
                categoryItems.style.setProperty('overflow', 'visible', 'important');
                categoryItems.style.setProperty('overflow-y', 'visible', 'important');
                categoryItems.style.setProperty('max-height', 'none', 'important');
                categoryItems.style.setProperty('height', 'auto', 'important');
            }
            if (categoryContent) {
                categoryContent.style.setProperty('overflow', 'visible', 'important');
                categoryContent.style.setProperty('overflow-y', 'visible', 'important');
                categoryContent.style.setProperty('max-height', 'none', 'important');
                categoryContent.style.setProperty('height', 'auto', 'important');
            }
            if (categoryRoot) {
                categoryRoot.style.setProperty('overflow', 'visible', 'important');
                categoryRoot.style.setProperty('overflow-y', 'visible', 'important');
            }
            if (w.matchMedia && w.matchMedia('(min-width: 992px)').matches) {
                p.style.setProperty('height', 'auto', 'important');
                p.style.setProperty('min-height', '120px', 'important');
                p.style.setProperty('max-height', 'min(70vh, 560px)', 'important');
                p.style.setProperty('overflow-x', 'hidden', 'important');
                p.style.setProperty('overflow-y', 'auto', 'important');
                if (nav) {
                    nav.style.setProperty('height', 'auto', 'important');
                    nav.style.setProperty('max-height', 'none', 'important');
                    nav.style.setProperty('overflow', 'visible', 'important');
                    nav.style.setProperty('overflow-y', 'visible', 'important');
                }
                if (shell) {
                    shell.style.setProperty('height', 'auto', 'important');
                    shell.style.setProperty('max-height', 'none', 'important');
                    shell.style.setProperty('overflow', 'visible', 'important');
                    shell.style.setProperty('overflow-y', 'visible', 'important');
                }
                /* BUG-H2: painel absolute top:44px invade sticky (nav 40px + chrome ~6.5px). */
                alignDeptPanelBelowSticky(p, nav);
            }
        }

        function alignDeptPanelBelowSticky(panelEl, navEl) {
            var sticky = d.querySelector('.header-wrapper-sticky.is-sticky');
            var navNode = navEl || (panelEl && panelEl.closest('[data-role="awa-vertical-menu"], .navigation.verticalmenu'));
            var stickyBottom;
            var navTop;
            var neededTop;
            if (!panelEl || !sticky || !navNode) {
                return;
            }
            stickyBottom = sticky.getBoundingClientRect().bottom;
            navTop = navNode.getBoundingClientRect().top;
            neededTop = Math.ceil(stickyBottom - navTop);
            if (neededTop < 0) {
                return;
            }
            panelEl.style.setProperty('top', neededTop + 'px', 'important');
        }

        function forceOpenDeptPanel() {
            var nodes = getCurrentDeptNodes();
            if (nodes) {
                forceOpenDeptPanelOn(nodes.trigger, nodes.panel);
                return;
            }
            forceOpenDeptPanelOn(deptTrigger, deptPanel);
        }

        w.addEventListener('pointerdown', function onDeptWindowPreflight(ev) {
            var target = ev && ev.target;
            var intent = target && target.closest
                ? target.closest('[data-role="awa-vertical-menu-trigger"], .title-category-dropdown.our_categories, .title-category-dropdown .awa-vmenu-trigger-text')
                : null;
            var nodes;
            var triggerEl;
            var panelEl;
            var triggerRect;
            var byCoordinates = false;
            var menuReady;
            var wasOpen;
            if (!isDesktopMenuViewport()) {
                return;
            }
            nodes = resolveDeptNodesFromIntent(target, ev && ev.clientX, ev && ev.clientY) || getCurrentDeptNodes();
            triggerEl = nodes ? nodes.trigger : deptTrigger;
            panelEl = nodes ? nodes.panel : deptPanel;
            if (triggerEl && typeof triggerEl.getBoundingClientRect === 'function' && ev && typeof ev.clientX === 'number' && typeof ev.clientY === 'number') {
                triggerRect = triggerEl.getBoundingClientRect();
                byCoordinates = ev.clientX >= triggerRect.left
                    && ev.clientX <= triggerRect.right
                    && ev.clientY >= triggerRect.top
                    && ev.clientY <= triggerRect.bottom;
            }
            if (!intent && !byCoordinates) {
                return;
            }
            menuReady = d.body.classList.contains('awa-menu-v2-ready');
            wasOpen = isDeptPanelOpen(triggerEl, panelEl);
            if (wasOpen) {
                return;
            }
            if (!menuReady) {
                applyController();
            }
            var openImmediately = isDeptPanelOpen(triggerEl, panelEl);
            if (!openImmediately) {
                forceOpenDeptPanelOn(triggerEl, panelEl);
            }
            w.requestAnimationFrame(function () {
                var openBeforeFallback = isDeptPanelOpen();
                if (!openBeforeFallback) {
                    forceOpenDeptPanelOn(triggerEl, panelEl);
                }
            });
        }, { capture: true, passive: true });

        w.addEventListener('click', function onDeptWindowClickPreflight(ev) {
            var target = ev && ev.target;
            var intent = target && target.closest
                ? target.closest('[data-role="awa-vertical-menu-trigger"], .title-category-dropdown.our_categories, .title-category-dropdown .awa-vmenu-trigger-text')
                : null;
            var nodes;
            var triggerEl;
            var panelEl;
            var triggerRect;
            var byCoordinates = false;
            var menuReady;
            var wasOpen;
            if (!isDesktopMenuViewport()) {
                return;
            }
            nodes = resolveDeptNodesFromIntent(target, ev && ev.clientX, ev && ev.clientY) || getCurrentDeptNodes();
            triggerEl = nodes ? nodes.trigger : deptTrigger;
            panelEl = nodes ? nodes.panel : deptPanel;
            if (triggerEl && typeof triggerEl.getBoundingClientRect === 'function' && ev && typeof ev.clientX === 'number' && typeof ev.clientY === 'number') {
                triggerRect = triggerEl.getBoundingClientRect();
                byCoordinates = ev.clientX >= triggerRect.left
                    && ev.clientX <= triggerRect.right
                    && ev.clientY >= triggerRect.top
                    && ev.clientY <= triggerRect.bottom;
            }
            if (!intent && !byCoordinates) {
                return;
            }
            menuReady = d.body.classList.contains('awa-menu-v2-ready');
            wasOpen = isDeptPanelOpen(triggerEl, panelEl);
            if (wasOpen) {
                return;
            }
            if (!menuReady) {
                applyController();
            }
            var openImmediately = isDeptPanelOpen(triggerEl, panelEl);
            if (!openImmediately) {
                forceOpenDeptPanelOn(triggerEl, panelEl);
            }
            w.requestAnimationFrame(function () {
                var openBeforeFallback = isDeptPanelOpen();
                if (!openBeforeFallback) {
                    forceOpenDeptPanelOn(triggerEl, panelEl);
                }
            });
        }, { capture: true });

        (function installDeptHoverFocusWatchdog() {
            if (d.body && d.body.classList.contains("cms-index-index")) {
                return;
            }
            w.setInterval(function () {
                var menuReady;
                var nodes;
                var triggerActive;
                var navHover;
                if (!isDesktopMenuViewport()) {
                    return;
                }
                nodes = getCurrentDeptNodes();
                if (!nodes) {
                    return;
                }
                navHover = typeof nodes.panel.closest === 'function'
                    ? nodes.panel.closest('.navigation.verticalmenu.side-verticalmenu, [data-role="awa-vertical-menu"]')
                    : null;
                triggerActive = d.activeElement === nodes.trigger
                    || (typeof nodes.trigger.matches === 'function' && nodes.trigger.matches(':hover'));
                triggerActive = triggerActive
                    || (navHover && typeof navHover.matches === 'function' && navHover.matches(':hover'));
                if (!triggerActive || isDeptPanelOpen(nodes.trigger, nodes.panel)) {
                    return;
                }
                menuReady = d.body.classList.contains('awa-menu-v2-ready');
                if (!menuReady) {
                    applyController();
                }
                forceOpenDeptPanelOn(nodes.trigger, nodes.panel);
            }, 120);
        }());

        deptTrigger.addEventListener('click', function onDeptPreflight(ev) {
            var menuReady = d.body.classList.contains('awa-menu-v2-ready');
            var nodes = getCurrentDeptNodes();
            var triggerEl = nodes ? nodes.trigger : deptTrigger;
            var panelEl = nodes ? nodes.panel : deptPanel;
            var isOpen;
            if (menuReady) {
                return;
            }
            applyController();
            isOpen = isDeptPanelOpen(triggerEl, panelEl);
            if (!isOpen) {
                forceOpenDeptPanelOn(triggerEl, panelEl);
            }
        }, { capture: true });

        /* BUG-H1: fallback global precisa deste escopo — resolveDeptNodesFromIntent /
           getCurrentDeptNodes não existem no IIFE externo (ReferenceError em runtime). */
        (function installDeptResilientGlobalFallback() {
            function isDesktopViewport() {
                return !(w.matchMedia && w.matchMedia('(max-width: 991px)').matches);
            }

            function getNodes(target, clientX, clientY) {
                var chosen = resolveDeptNodesFromIntent(target, clientX, clientY) || getCurrentDeptNodes();
                if (!chosen) {
                    return null;
                }
                return { trigger: chosen.trigger, panel: chosen.panel };
            }

            function isOpen(nodes) {
                return nodes.trigger.getAttribute('aria-expanded') === 'true'
                    || nodes.panel.getAttribute('data-awa-menu-state') === 'open'
                    || nodes.panel.classList.contains('vmm-open')
                    || nodes.panel.classList.contains('menu-open');
            }

            function forceOpen(nodes) {
                forceOpenDeptPanelOn(nodes.trigger, nodes.panel);
            }

            w.addEventListener('click', function onDeptGlobalResilientClick(ev) {
                var target = ev && ev.target;
                var intent = target && target.closest
                    ? target.closest('[data-role="awa-vertical-menu-trigger"], .title-category-dropdown.our_categories, .title-category-dropdown .awa-vmenu-trigger-text')
                    : null;
                var nodes;
                if (!intent || !isDesktopViewport()) {
                    return;
                }
                nodes = getNodes(target, ev && ev.clientX, ev && ev.clientY);
                if (!nodes) {
                    return;
                }
                if (isOpen(nodes)) {
                    return;
                }
                forceOpen(nodes);
            }, { capture: true });
        }());
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', scheduleBoot, { once: true });
    } else {
        scheduleBoot();
    }
})(window, document);
