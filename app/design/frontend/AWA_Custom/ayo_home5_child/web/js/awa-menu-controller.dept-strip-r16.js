/**
 * AWA Menu Controller v2 — vanilla runtime (Departamentos, flyout, mobile drawer, horizontal nav).
 *
 * @module awa-menu-controller
 */
define([
    'domReady!'
], function (domReady) {
    'use strict';

    /* UMD já carregado pelo bootstrap (script defer) — evita AMD path quebrado. */
    var FloatingUIDOM = window.FloatingUIDOM || null;

    var DESKTOP_MIN = 992;
    var PORTAL_CLASS = 'awa-vmf-portal';
    var ACTIVE_CLASS = 'awa-vmf-active';
    var DOC_BOOTED = false;
    var RUNTIME_STYLE_FIX_ID = 'awa-vmenu-runtime-fixes-v2';
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    function isDesktop() {
        return window.matchMedia
            ? window.matchMedia('(min-width: ' + DESKTOP_MIN + 'px)').matches
            : window.innerWidth >= DESKTOP_MIN;
    }

    function isMobile() {
        return !isDesktop();
    }

    /* 768–991: o header restaura Departamentos como dropdown, não drawer.
     * isMobile() ainda é true (DESKTOP_MIN=992), então o click ia só para
     * openDrawer() — e o segundo clique no-op porque this.open já é true. */
    function isTabletNav() {
        return window.matchMedia
            ? window.matchMedia('(min-width: 768px) and (max-width: 991px)').matches
            : (window.innerWidth >= 768 && window.innerWidth <= 991);
    }

    function isPlpTabletAxisScope() {
        return !!document.body
            && document.body.classList.contains('catalog-category-view')
            && window.innerWidth >= 768
            && window.innerWidth <= 991;
    }

    function enforcePlpTabletAxisStability(reason) {
        var root = document.querySelector('.awa-site-header .awa-header-categories.menu_left_home1');
        var wrappers;
        var trigger;
        var rootRect;
        var triggerRect;
        var active = isPlpTabletAxisScope();
        if (!root) {
            return;
        }

        wrappers = [
            root.querySelector('nav.awa-nav-categories'),
            root.querySelector('.sections.nav-sections.category-dropdown'),
            root.querySelector('.section-items.category-dropdown-items'),
            root.querySelector('.section-item-content.category-dropdown-item-content'),
            root.querySelector('.navigation.verticalmenu.side-verticalmenu')
        ].filter(Boolean);

        if (active) {
            root.style.setProperty('padding-left', '0px', 'important');
            root.style.setProperty('padding-inline-start', '0px', 'important');
            wrappers.forEach(function (node) {
                node.style.setProperty('display', 'block', 'important');
            });
        } else {
            root.style.removeProperty('padding-left');
            root.style.removeProperty('padding-inline-start');
            wrappers.forEach(function (node) {
                node.style.removeProperty('display');
            });
        }

        trigger = root.querySelector('[data-role="awa-vertical-menu-trigger"]');
        rootRect = root.getBoundingClientRect();
        triggerRect = trigger ? trigger.getBoundingClientRect() : null;
    }

    function applyHomeMenuStyle(element, declarations) {
        if (!element) {
            return;
        }
        if (!element._awaHomeMenuPreviousStyles) {
            element._awaHomeMenuPreviousStyles = {};
        }
        Object.keys(declarations).forEach(function (property) {
            if (!Object.prototype.hasOwnProperty.call(element._awaHomeMenuPreviousStyles, property)) {
                element._awaHomeMenuPreviousStyles[property] = {
                    value: element.style.getPropertyValue(property),
                    priority: element.style.getPropertyPriority(property)
                };
            }
            element.style.setProperty(property, declarations[property], 'important');
        });
    }

    function restoreHomeMenuStyles(element) {
        if (!element || !element._awaHomeMenuPreviousStyles) {
            return;
        }
        Object.keys(element._awaHomeMenuPreviousStyles).forEach(function (property) {
            var previous = element._awaHomeMenuPreviousStyles[property];
            if (previous.value) {
                element.style.setProperty(property, previous.value, previous.priority);
            } else {
                element.style.removeProperty(property);
            }
        });
        element._awaHomeMenuPreviousStyles = null;
    }

    function syncHomeMenuComposition(open) {
        if (!document.body.matches('.cms-index-index, .cms-home, .cms-homepage_ayo_home5')) {
            return;
        }

        var fold = document.querySelector('.content-top-home > .top-home-content--above-fold');
        var banner = fold
            ? fold.querySelector(':scope > .banner-slider.banner-slider2')
            : null;
        var benefitsInner = document.querySelector('.awa-hero-b2b-cta__inner.container');
        var categorySection = document.querySelector('.top-home-content--category-carousel');
        var categoryRoot = document.querySelector('.awa-header-categories.menu_left_home1');
        var categoryNodes = categoryRoot
            ? [categoryRoot].concat(Array.prototype.slice.call(categoryRoot.querySelectorAll(
                '.awa-nav-categories, '
                + '.sections.nav-sections.category-dropdown, '
                + '.section-items.nav-sections.category-dropdown-items, '
                + '.section-item-content.nav-sections.category-dropdown-item-content, '
                + '.navigation.verticalmenu.side-verticalmenu, '
                + '[data-role="awa-vertical-menu-trigger"]'
            )))
            : [];

        /* CLS: never push above-fold when Departamentos opens (panel overlays). */
        [fold, banner, benefitsInner, categorySection]
            .concat(categoryNodes)
            .forEach(restoreHomeMenuStyles);
        document.body.classList.remove('awa-home-menu-column-active');
    }



    function rafThrottle(fn) {
        var scheduled = 0;
        return function () {
            var ctx = this;
            var args = arguments;
            if (scheduled) {
                return;
            }
            scheduled = window.requestAnimationFrame(function () {
                scheduled = 0;
                fn.apply(ctx, args);
            });
        };
    }

    function getFocusables(root) {
        if (!root) {
            return [];
        }
        return Array.prototype.slice.call(root.querySelectorAll(FOCUSABLE)).filter(function (el) {
            return el.offsetParent !== null || el === document.activeElement;
        });
    }

    function restorePortaledFocusState(root) {
        if (!root) {
            return 0;
        }

        var restored = 0;
        root.querySelectorAll('[data-awa-hidden-focus-sync="1"]').forEach(function (el) {
            var previousTabindex = el.getAttribute('data-awa-prev-tabindex');
            var previousAriaHidden = el.getAttribute('data-awa-prev-aria-hidden');

            if (previousTabindex === '') {
                el.removeAttribute('tabindex');
            } else if (previousTabindex !== null) {
                el.setAttribute('tabindex', previousTabindex);
            }

            if (previousAriaHidden === '') {
                el.removeAttribute('aria-hidden');
            } else if (previousAriaHidden !== null) {
                el.setAttribute('aria-hidden', previousAriaHidden);
            }

            if ('inert' in el) {
                el.inert = false;
            }
            el.removeAttribute('inert');
            el.removeAttribute('data-awa-hidden-focus-sync');
            el.removeAttribute('data-awa-prev-tabindex');
            el.removeAttribute('data-awa-prev-aria-hidden');
            restored += 1;
        });

        return restored;
    }

    function countDrawerLinks(root) {
        if (!root) {
            return 0;
        }
        return root.querySelectorAll(
            'a[href]:not([href="#"]), .ui-menu-item > a, .navigation__link, .level-top'
        ).length;
    }

    function resolveDrawerShell() {
        var candidates = [
            document.querySelector('[data-awa-nav-shell="true"]'),
            document.getElementById('awa-primary-navigation'),
            document.getElementById('awa-category-navigation'),
            document.querySelector('.section-items.nav-sections.category-dropdown-items.awa-header-primary-nav'),
            document.querySelector('.sections.nav-sections')
        ].filter(Boolean);

        var seen = [];
        candidates = candidates.filter(function (el) {
            if (seen.indexOf(el) !== -1) {
                return false;
            }
            seen.push(el);
            return true;
        });

        var best = null;
        var bestCount = -1;
        candidates.forEach(function (el) {
            var count = countDrawerLinks(el);
            if (count > bestCount) {
                bestCount = count;
                best = el;
            }
        });

        return best || candidates[0] || null;
    }

    function getDrawerTargets(shell) {
        shell = shell || resolveDrawerShell();
        if (!shell) {
            return [];
        }
        var targets = [shell];
        var primary = document.getElementById('awa-primary-navigation');
        var category = document.getElementById('awa-category-navigation');
        [primary, category].forEach(function (el) {
            if (!el || targets.indexOf(el) !== -1) {
                return;
            }
            if (countDrawerLinks(el) >= 3) {
                targets.push(el);
            }
        });
        return targets;
    }

    function drawerMotionEnabled() {
        return !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function setDrawerTransition(target) {
        if (!target) {
            return;
        }
        if (!drawerMotionEnabled()) {
            target.style.removeProperty('transition');
            return;
        }
        target.style.setProperty(
            'transition',
            'transform 280ms cubic-bezier(0.22, 1, 0.36, 1)',
            'important'
        );
    }

    function slideDrawerIn(target) {
        if (!target) {
            return;
        }
        setDrawerTransition(target);
        if (!drawerMotionEnabled()) {
            target.style.setProperty('transform', 'translateX(0)', 'important');
            return;
        }
        target.style.setProperty('transform', 'translateX(-105%)', 'important');
        void target.offsetWidth;
        target.style.setProperty('transform', 'translateX(0)', 'important');
    }

    function slideDrawerOut(target) {
        if (!target) {
            return;
        }
        setDrawerTransition(target);
        target.style.setProperty('transform', 'translateX(-105%)', 'important');
    }

    var DRAWER_HOST_STYLE_PROPS = [
        'display',
        'visibility',
        'opacity',
        'overflow',
        'height',
        'max-height'
    ];

    function cacheDrawerHostStyles(node) {
        if (!node || node._awaDrawerHostPrevStyles) {
            return;
        }
        var snapshot = {};
        DRAWER_HOST_STYLE_PROPS.forEach(function (prop) {
            snapshot[prop] = {
                value: node.style.getPropertyValue(prop),
                priority: node.style.getPropertyPriority(prop)
            };
        });
        node._awaDrawerHostPrevStyles = snapshot;
    }

    function restoreDrawerHostStyles(node) {
        if (!node || !node._awaDrawerHostPrevStyles) {
            return;
        }
        DRAWER_HOST_STYLE_PROPS.forEach(function (prop) {
            var prev = node._awaDrawerHostPrevStyles[prop] || { value: '', priority: '' };
            if (prev.value) {
                node.style.setProperty(prop, prev.value, prev.priority || '');
            } else {
                node.style.removeProperty(prop);
            }
        });
        node._awaDrawerHostPrevStyles = null;
    }

    function unlockDrawerHosts(root) {
        if (!root) {
            return;
        }
        var hostSel = '.header-control.header-nav.awa-nav-bar, .header-control.awa-nav-bar, '
            + '.awa-header-categories, .awa-nav-categories, .sections.nav-sections, '
            + '.section-items.nav-sections';
        var node = root.parentElement;
        while (node && node !== document.body) {
            if (node.matches && node.matches(hostSel)) {
                cacheDrawerHostStyles(node);
                node.style.setProperty('display', 'block', 'important');
                node.style.setProperty('visibility', 'visible', 'important');
                node.style.setProperty('opacity', '1', 'important');
                node.style.setProperty('overflow', 'visible', 'important');
                node.style.setProperty('height', 'auto', 'important');
                node.style.setProperty('max-height', 'none', 'important');
            }
            node = node.parentElement;
        }
    }

    function relockDrawerHosts(root) {
        if (!root) {
            return;
        }
        var hostSel = '.header-control.header-nav.awa-nav-bar, .header-control.awa-nav-bar, '
            + '.awa-header-categories, .awa-nav-categories, .sections.nav-sections, '
            + '.section-items.nav-sections';
        var node = root.parentElement;
        while (node && node !== document.body) {
            if (node.matches && node.matches(hostSel)) {
                restoreDrawerHostStyles(node);
            }
            node = node.parentElement;
        }
    }

    function syncDrawerToggleAria(isOpen) {
        document.querySelectorAll(
            '[data-awa-nav-toggle="true"], .toggle-nav-footer, [data-action="toggle-nav"]'
        ).forEach(function (btn) {
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    }

    function needsTypographyFix(node) {
        if (!node || !window.getComputedStyle) {
            return true;
        }

        var cs = window.getComputedStyle(node);
        var size = parseFloat(cs.fontSize);
        var weight = parseInt(cs.fontWeight, 10) || 400;
        /* DS vertical menu: alvo 14/600 — corrige 13–13.5/500 legado */
        return size < 14 || weight < 600;
    }

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

    function guardDeptTriggerInlineTypography(trigger) {
        if (!trigger || trigger.dataset.awaTypographyStripObserved === '1') {
            stripDeptTriggerInlineTypography(trigger);
            return;
        }

        trigger.dataset.awaTypographyStripObserved = '1';
        stripDeptTriggerInlineTypography(trigger);

        var observer = new MutationObserver(function () {
            stripDeptTriggerInlineTypography(trigger);
        });
        observer.observe(trigger, { attributes: true, attributeFilter: ['style'] });

        var triggerText = trigger.querySelector('.awa-vmenu-trigger-text');
        if (triggerText) {
            observer.observe(triggerText, { attributes: true, attributeFilter: ['style'] });
        }
    }

    window.__awaGuardDeptTriggerTypography = guardDeptTriggerInlineTypography;

    function applyMenuLinkTypography(root, enable) {
        if (!root) {
            return;
        }

        /* L1: NÃO setar color inline no <a> — isso vencia o CSS de :hover/.awa-vmf-active
         * e deixava Bauletos com label vermelho + link escuro (evidência CDP). */
        var linkProps = ['font-size', 'font-weight', 'line-height', 'color'];
        root.querySelectorAll('a.level-top, .navigation.custommenu li.level0 > a, .top-menu a.level-top').forEach(function (anchor) {
            if (enable) {
                if (!needsTypographyFix(anchor)) {
                    return;
                }
                /* DS vertical menu: 14/600 — não reverter para 13/500 legado */
                anchor.style.setProperty('font-size', '14px', 'important');
                anchor.style.setProperty('font-weight', '600', 'important');
                anchor.style.setProperty('line-height', '1.25', 'important');
                anchor.style.removeProperty('color');
            } else {
                linkProps.forEach(function (prop) {
                    anchor.style.removeProperty(prop);
                });
            }
        });

        root.querySelectorAll('.navigation__label').forEach(function (label) {
            if (enable) {
                label.style.setProperty('font-size', '14px', 'important');
                label.style.setProperty('font-weight', '600', 'important');
                label.style.setProperty('line-height', '1.25', 'important');
                /* Label sempre espelha a cor do link (hover/active/default). */
                label.style.setProperty('color', 'inherit', 'important');
            } else {
                linkProps.forEach(function (prop) {
                    label.style.removeProperty(prop);
                });
            }
        });
    }

    function ensureRuntimeMenuStyles() {
        /* Remove injector legado (cache antigo / bundle stale) que competia com v2+. */
        var legacyStyle = document.getElementById('awa-vmenu-runtime-fixes');
        if (legacyStyle && legacyStyle.parentNode) {
            legacyStyle.parentNode.removeChild(legacyStyle);
        }
        /* Evita reescrever ~4KB de CSS a cada hover/attach; troca se marker antigo. */
        var existing = document.getElementById(RUNTIME_STYLE_FIX_ID);
        if (existing && existing.getAttribute('data-awa-menu-css') === 'opt-v4') {
            return;
        }
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }
        var styleEl = document.createElement('style');
        styleEl.id = RUNTIME_STYLE_FIX_ID;
        styleEl.setAttribute('data-awa-menu-css', 'opt-v4');
        /* L1/especificidade: impeccable-refine usa html body#html-body… no nav-bar e
         * vencía color:primary do runtime (bg active aplicava; texto ficava --awa-text).
         * Martelo #html-body×5 alinha ao padrão align-grid do tema. */
        var vmenuActiveItem =
            'html body#html-body#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header '
            + '.navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown[data-awa-menu-state="open"] '
            + '> li.ui-menu-item.level0:is(:hover, .awa-vmf-active, .vmm-active, :focus-within, .ui-state-active, .ui-state-focus, .active)';
        var vmenuActiveLink = vmenuActiveItem + ' > a.level-top.navigation__link';
        styleEl.textContent = [
            '#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown > li.ui-menu-item.level0 {',
            '  box-sizing: border-box !important;',
            '  width: 100% !important;',
            '  height: var(--awa-vmenu-item-h, 48px) !important;',
            '  min-height: var(--awa-vmenu-item-h, 48px) !important;',
            '  margin: 0 !important;',
            '  padding: 0 !important;',
            '}',
            '#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown {',
            '  gap: var(--awa-space-1, 4px) !important;',
            '  padding: var(--awa-space-2, 8px) !important;',
            '  background: var(--awa-bg-surface, #fff) !important;',
            '  border: 1px solid var(--awa-border, #e5e7eb) !important;',
            '  border-radius: 0 0 var(--awa-radius-md, 8px) var(--awa-radius-md, 8px) !important;',
            '  box-shadow: 0 8px 24px color-mix(in srgb, CanvasText 10%, transparent) !important;',
            '}',
            '#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown > li.ui-menu-item.level0 > a.level-top.navigation__link,',
            'body .page-wrapper .navigation.verticalmenu .togge-menu > li.ui-menu-item.level0 > a.level-top.navigation__link {',
            '  box-sizing: border-box !important;',
            '  width: 100% !important;',
            '  height: 100% !important;',
            '  min-height: var(--awa-vmenu-item-h, 48px) !important;',
            '  padding: 0 var(--awa-space-3, 12px) !important;',
            '  border-radius: 8px !important;',
            '  line-height: 1.25 !important;',
            '  font-size: 14px !important;',
            '  font-weight: 600 !important;',
            '  display: flex !important;',
            '  align-items: center !important;',
            '  gap: var(--awa-space-3, 12px) !important;',
            '  color: var(--awa-text, var(--awa-dark, #333333)) !important;',
            '}',
            'html body#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown > li.ui-menu-item.level0 > a.level-top.navigation__link .navigation__label {',
            '  color: inherit !important;',
            '}',
            vmenuActiveItem + ' {',
            '  border-radius: 8px !important;',
            '  box-shadow: inset 3px 0 0 var(--awa-primary, #b73337) !important;',
            '}',
            vmenuActiveLink + ' {',
            '  background: color-mix(in srgb, var(--awa-primary, #b73337) 10%, var(--awa-bg-surface, #fff)) !important;',
            '  color: var(--awa-primary, #b73337) !important;',
            '  outline: 2px solid color-mix(in srgb, var(--awa-primary, #b73337) 45%, transparent) !important;',
            '  outline-offset: -2px !important;',
            '}',
            vmenuActiveLink + ' .navigation__label {',
            '  color: inherit !important;',
            '  background: transparent !important;',
            '}',
            '#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown > li.awa-vem-extra-li {',
            '  box-sizing: border-box !important;',
            '  width: 100% !important;',
            '  max-width: 100% !important;',
            '  overflow: hidden !important;',
            '}',
            '#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown > li.ui-menu-item.level0 > a.level-top.navigation__link::after,',
            'body .page-wrapper .navigation.verticalmenu .togge-menu > li.level0 > a.level-top::after,',
            'html body#html-body .page-wrapper .verticalmenu.navigation.side-verticalmenu li.level0.parent > a::after,',
            'html body#html-body .page-wrapper .verticalmenu.navigation.side-verticalmenu li.level0.navigation__item--parent > a::after,',
            'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header [data-role="awa-vertical-menu-panel"][data-awa-menu-state="open"] > li.ui-menu-item.level0:is(.parent, .navigation__item--parent) > a.level-top::after,',
            'html body#html-body:not(.checkout-index-index):not(.onepagecheckout-index-index) .page-wrapper .awa-site-header [data-role="awa-vertical-menu-panel"][data-awa-menu-state="open"] > li.ui-menu-item.level0:is(.parent, .navigation__item--parent) > a.navigation__link::after,',
            'a.level-top.navigation__link.awa-vmenu-link-runtime::after {',
            '  content: none !important;',
            '  display: none !important;',
            '  width: 0 !important;',
            '  height: 0 !important;',
            '  float: none !important;',
            '  margin: 0 !important;',
            '  position: static !important;',
            '  font-size: 0 !important;',
            '  line-height: 0 !important;',
            '  color: transparent !important;',
            '  opacity: 0 !important;',
            '  overflow: hidden !important;',
            '}',
            '#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown > li.ui-menu-item.level0 > a.level-top.navigation__link:focus {',
            '  outline: none !important;',
            '}',
            '#html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu > ul.togge-menu.list-category-dropdown > li.ui-menu-item.level0 > a.level-top.navigation__link:focus-visible,',
            'body .awa-vmf-portal .navigation__inner-item--level1.subcategory-second-level > a:focus-visible {',
            '  outline: 2px solid var(--awa-primary, var(--awa-red)) !important;',
            '  outline-offset: 1px !important;',
            '  border-radius: var(--awa-radius-sm, 8px) !important;',
            '}',
            /* --awa-text-primary está invertido (#f1f5f9) em algumas páginas; portal é fundo claro. */
            'body .awa-vmf-portal {',
            '  color: var(--awa-text, var(--awa-dark, #333333)) !important;',
            '  --awa-text-primary: var(--awa-text, var(--awa-dark, #333333));',
            '  --awa-hc-text-2: var(--awa-text, var(--awa-dark, #333333));',
            '}',
            'body .awa-vmf-portal :is(.navigation__inner-item--level1.subcategory-second-level > a, a.title-cat-mega-menu, .subchildmenu li a, .navigation__inner-item--all a) {',
            '  color: var(--awa-text, var(--awa-dark, #333333)) !important;',
            '}',
            'body .awa-vmf-portal :is(.navigation__inner-item--level1.subcategory-second-level > a, a.title-cat-mega-menu, .subchildmenu li a):hover {',
            '  color: var(--awa-primary, #b73337) !important;',
            '}',
            '@media (min-width: 992px) {',
            '  #html-body [data-role="awa-vertical-menu-panel"] > li.ui-menu-item.level0 > .submenu:not([data-awa-vmf-portaled="1"]),',
            '  #html-body [data-role="awa-vertical-menu-panel"] > li.ui-menu-item.level0 > .navigation__submenu:not([data-awa-vmf-portaled="1"]) {',
            '    display: none !important;',
            '  }',
            '  #html-body .page-wrapper .navigation.verticalmenu.side-verticalmenu .open-children-toggle.navigation__toggle,',
            '  body .page-wrapper .navigation.verticalmenu .open-children-toggle.navigation__toggle {',
            '    display: none !important;',
            '    opacity: 0 !important;',
            '    pointer-events: none !important;',
            '    width: 0 !important;',
            '    height: 0 !important;',
            '    margin: 0 !important;',
            '    padding: 0 !important;',
            '    border: 0 !important;',
            '    background: transparent !important;',
            '    box-shadow: none !important;',
            '    outline: none !important;',
            '  }',
            '}'
        ].join('\n');
        /* Final do documento: vence sheets injetados depois do <head> com mesma origem. */
        (document.documentElement || document.head).appendChild(styleEl);
    }

    function applyTopLinkRuntimeFixes(root) {
        if (!root) {
            return;
        }
        root.style.setProperty('--awa-vmenu-item-h', '48px');
        var items = root.querySelectorAll('li.ui-menu-item.level0');
        items.forEach(function (item) {
            if (item.parentElement !== root) {
                return;
            }
            item.style.setProperty('box-sizing', 'border-box', 'important');
            item.style.setProperty('width', '100%', 'important');
            item.style.setProperty('height', 'var(--awa-vmenu-item-h)', 'important');
            item.style.setProperty('min-height', 'var(--awa-vmenu-item-h)', 'important');
            item.style.setProperty('margin', '0', 'important');
            item.style.setProperty('padding', '0', 'important');
        });
        root.querySelectorAll('li.ui-menu-item.level0 > a.level-top').forEach(function (link) {
            if (link.parentElement && link.parentElement.parentElement !== root) {
                return;
            }
            link.classList.add('awa-vmenu-link-runtime');
            link.style.setProperty('box-sizing', 'border-box', 'important');
            link.style.setProperty('width', '100%', 'important');
            link.style.setProperty('height', '100%', 'important');
            link.style.setProperty('min-height', 'var(--awa-vmenu-item-h, 48px)', 'important');
            link.style.setProperty('padding', '0 12px', 'important');
            link.style.setProperty('border-radius', '8px', 'important');
            link.style.setProperty('line-height', '1.25', 'important');
            link.style.setProperty('font-size', '14px', 'important');
            link.style.setProperty('font-weight', '600', 'important');
            link.style.setProperty('display', 'flex', 'important');
            link.style.setProperty('align-items', 'center', 'important');
            link.style.setProperty('gap', '12px', 'important');
        });
        root.querySelectorAll(':scope > li.awa-vem-extra-li, li.awa-vem-extra-li').forEach(function (item) {
            if (item.parentElement !== root) {
                return;
            }
            item.style.setProperty('box-sizing', 'border-box', 'important');
            item.style.setProperty('width', '100%', 'important');
            item.style.setProperty('max-width', '100%', 'important');
            item.style.setProperty('overflow', 'hidden', 'important');
        });
        if (isDesktop()) {
            root.querySelectorAll('li.ui-menu-item.level0 > .open-children-toggle.navigation__toggle').forEach(function (btn) {
                if (btn.parentElement && btn.parentElement.parentElement !== root) {
                    return;
                }
                btn.style.setProperty('display', 'none', 'important');
                btn.style.setProperty('opacity', '0', 'important');
                btn.style.setProperty('pointer-events', 'none', 'important');
                btn.style.setProperty('width', '0', 'important');
                btn.style.setProperty('height', '0', 'important');
                btn.style.setProperty('margin', '0', 'important');
                btn.style.setProperty('padding', '0', 'important');
                btn.style.setProperty('border', '0', 'important');
                btn.style.setProperty('background', 'transparent', 'important');
                btn.style.setProperty('outline', 'none', 'important');
            });
        }
    }

    /* ── FlyoutPortal ─────────────────────────────────────────────────── */
    function FlyoutPortal(root) {
        var self = this;
        this.root = root;
        this.portals = [];
        this._onEnter = function (e) {
            var li = e.target.closest('li.level0.parent, li.level0.navigation__item--parent');
            if (li && self.root.contains(li)) {
                self.attach(li);
            }
        };
        this._onOver = function (e) {
            var li = e.target && e.target.closest
                ? e.target.closest('li.level0.parent, li.level0.navigation__item--parent')
                : null;
            if (!li || !self.root.contains(li)) {
                return;
            }
            self.attach(li);
        };
        this._onLeave = function (e) {
            var li = e.target.closest('li.level0');
            if (!li || !self.root.contains(li)) {
                return;
            }
            if (e.target !== li) {
                return;
            }
            var to = e.relatedTarget;
            if (to && li.contains(to)) {
                return;
            }
            if (to && to.closest && to.closest('.' + PORTAL_CLASS)) {
                return;
            }
            self.detach(li, 'li-leave');
        };
        this._reposition = rafThrottle(function () {
            if (!isDesktop()) {
                return;
            }
            var portals = document.querySelectorAll('.' + PORTAL_CLASS);
            if (!portals.length) {
                return;
            }
            portals.forEach(function (portal) {
                var id = portal.dataset.awVmfLiMenu;
                var li = id && self.root.querySelector('li.level0[data-menu="' + id + '"]');
                if (li) {
                    self.position(li, portal);
                }
            });
        });
    }

    FlyoutPortal.prototype.mount = function () {
        if (!this.root || this.root.dataset.awaFlyoutMounted === '1') {
            return;
        }
        this.root.dataset.awaFlyoutMounted = '1';
        this.root.addEventListener('mouseenter', this._onEnter, true);
        this.root.addEventListener('mouseover', this._onOver, true);
        this.root.addEventListener('mouseleave', this._onLeave, true);
        window.addEventListener('scroll', this._reposition, { passive: true });
        window.addEventListener('resize', this._reposition, { passive: true });
    };

    FlyoutPortal.prototype.findSubmenu = function (li) {
        return li.querySelector(':scope > .submenu, :scope > .level0.submenu, :scope > .navigation__submenu');
    };

    FlyoutPortal.prototype.applyLayoutFixes = function (portal) {
        if (!portal) {
            return;
        }

        ensureRuntimeMenuStyles();

        /* Reentrada: só re-aplica estilos de imagem (CMS lazy). */
        if (portal.dataset.awaLayoutFixed === '1') {
            portal.querySelectorAll('.navigation__inner-item--level1.imagem.img-subcategory img, .navigation__inner-item--level1.imagem img').forEach(function (image) {
                image.style.setProperty('display', 'block', 'important');
                image.style.setProperty('width', '100%', 'important');
                image.style.setProperty('height', 'auto', 'important');
                image.style.setProperty('max-height', '280px', 'important');
                image.style.setProperty('object-fit', 'contain', 'important');
                image.style.setProperty('object-position', 'top center', 'important');
            });
            return;
        }

        /* DS polish: flyout denso B2B (antes 560px gerava whitespace em categorias curtas) */
        portal.style.setProperty('width', 'min(380px, calc(100vw - 32px))', 'important');
        portal.style.setProperty('min-width', 'min(280px, calc(100vw - 32px))', 'important');
        portal.style.setProperty('max-width', '380px', 'important');
        portal.style.setProperty('padding', '16px', 'important');
        portal.style.setProperty('border-radius', '8px', 'important');
        portal.style.setProperty('overflow-x', 'hidden', 'important');

        var row = portal.querySelector('.row');
        if (row) {
            row.style.setProperty('display', 'block', 'important');
            row.style.setProperty('width', '100%', 'important');
            row.style.setProperty('max-width', '100%', 'important');
            row.style.setProperty('margin', '0', 'important');
        }

        /*
         * FIX: não aplicar grid 2-col no .submenu externo (também tem
         * .navigation__inner-list--level1). Isso esmagava o ul interno em ~186px
         * e a coluna de texto virava ~26px — links sobrepunham a imagem.
         */
        var outerSubmenu = portal.querySelector(
            ':scope > .submenu, :scope > .navigation__submenu, :scope > [id^="submenu-menu-"]'
        );
        if (outerSubmenu) {
            outerSubmenu.style.setProperty('display', 'block', 'important');
            outerSubmenu.style.setProperty('width', '100%', 'important');
            outerSubmenu.style.setProperty('max-width', '100%', 'important');
            outerSubmenu.style.setProperty('margin', '0', 'important');
            outerSubmenu.style.setProperty('padding', '0', 'important');
            outerSubmenu.style.removeProperty('grid-template-columns');
            outerSubmenu.style.setProperty('grid-template-columns', 'none', 'important');
        }

        var lists = portal.querySelectorAll(
            'ul.subchildmenu.navigation__inner-list--level1, ul.subchildmenu.mega-columns'
        );
        lists.forEach(function (list) {
            list.style.setProperty('display', 'grid', 'important');
            list.style.setProperty('grid-template-columns', 'minmax(0, 1fr) 120px', 'important');
            list.style.setProperty('column-gap', '12px', 'important');
            list.style.setProperty('row-gap', '4px', 'important');
            list.style.setProperty('padding', '0', 'important');
            list.style.setProperty('width', '100%', 'important');
            list.style.setProperty('max-width', '100%', 'important');
            list.style.setProperty('margin', '0', 'important');
            list.style.setProperty('list-style', 'none', 'important');
        });

        var textItems = portal.querySelectorAll(
            '.navigation__inner-item--level1.subcategory-title, .navigation__inner-item--level1.subcategory-second-level, .navigation__inner-item--all'
        );
        textItems.forEach(function (item) {
            item.style.setProperty('grid-column', '1', 'important');
            item.style.setProperty('display', 'block', 'important');
            item.style.setProperty('float', 'none', 'important');
            item.style.setProperty('width', '100%', 'important');
            item.style.setProperty('max-width', '100%', 'important');
            item.style.setProperty('min-width', '0', 'important');
        });

        var imageItems = portal.querySelectorAll('.navigation__inner-item--level1.imagem.img-subcategory, .navigation__inner-item--level1.imagem');
        imageItems.forEach(function (item) {
            item.style.setProperty('grid-column', '2', 'important');
            item.style.setProperty('grid-row', '1 / span 20', 'important');
            item.style.setProperty('display', 'block', 'important');
            item.style.setProperty('float', 'none', 'important');
            item.style.setProperty('width', '120px', 'important');
            item.style.setProperty('min-width', '120px', 'important');
            item.style.setProperty('max-width', '120px', 'important');
            item.style.setProperty('margin', '0', 'important');
            item.style.setProperty('padding', '0', 'important');
            item.style.setProperty('align-self', 'start', 'important');
            item.style.setProperty('justify-self', 'end', 'important');
            item.style.setProperty('max-height', '280px', 'important');
            item.style.setProperty('overflow', 'hidden', 'important');
            var image = item.querySelector('img');
            if (image) {
                image.style.setProperty('display', 'block', 'important');
                image.style.setProperty('width', '100%', 'important');
                image.style.setProperty('height', 'auto', 'important');
                image.style.setProperty('max-height', '280px', 'important');
                image.style.setProperty('object-fit', 'contain', 'important');
                image.style.setProperty('object-position', 'top center', 'important');
            }
        });
        portal.dataset.awaLayoutFixed = '1';
    };

    FlyoutPortal.prototype.attach = function (li) {
        if (!isDesktop()) {
            return;
        }
        var submenu = this.findSubmenu(li);
        if (!submenu || submenu.dataset.awVmfPortaled === '1') {
            return;
        }
        var portal = document.createElement('div');
        portal.className = PORTAL_CLASS;
        portal.dataset.awVmfLiMenu = li.getAttribute('data-menu') || '';
        portal.appendChild(submenu);
        document.body.appendChild(portal);
        restorePortaledFocusState(portal);
        this.applyLayoutFixes(portal);
        submenu.dataset.awVmfPortaled = '1';
        li.classList.add(ACTIVE_CLASS);
        li.setAttribute('data-awa-submenu-open', 'true');
        var self = this;
        if (FloatingUIDOM && typeof FloatingUIDOM.autoUpdate === 'function') {
            portal._awaCleanupAutoUpdate = FloatingUIDOM.autoUpdate(
                li,
                portal,
                function () {
                    self.position(li, portal);
                },
                {
                    ancestorScroll: true,
                    ancestorResize: true,
                    elementResize: true,
                    layoutShift: true
                }
            );
        } else {
            this.position(li, portal);
        }
        this.portals.push(portal);

        var active = document.activeElement;
        if (active && li.contains(active)) {
            var firstSubLink = portal.querySelector('a[href]');
            if (firstSubLink) {
                window.requestAnimationFrame(function () {
                    firstSubLink.focus();
                });
            }
        }

        this.bindPortalKeyboard(portal, li);

        /* Segundo passe só se houver imagem (CMS às vezes monta após o 1º paint). */
        if (portal.querySelector('.imagem.img-subcategory, .navigation__inner-item--level1.imagem')) {
            window.requestAnimationFrame(function () {
                this.applyLayoutFixes(portal);
            }.bind(this));
        }

    };

    FlyoutPortal.prototype.bindPortalKeyboard = function (portal, li) {
        var self = this;
        var handler = function (e) {
            var links = Array.prototype.slice.call(portal.querySelectorAll('a[href]')).filter(function (anchor) {
                var href = anchor.getAttribute('href');
                return href && href !== '#';
            });
            var idx = links.indexOf(document.activeElement);

            if (e.key === 'ArrowDown' && idx >= 0 && links[idx + 1]) {
                e.preventDefault();
                links[idx + 1].focus();
                return;
            }

            if (e.key === 'ArrowUp' && idx > 0 && links[idx - 1]) {
                e.preventDefault();
                links[idx - 1].focus();
                return;
            }

            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                self.detach(li, 'portal-escape');
                var parentLink = getItemLink(li);
                if (parentLink) {
                    parentLink.focus();
                }
            }
        };

        portal._awaKeyHandler = handler;
        portal.addEventListener('keydown', handler);
    };

    FlyoutPortal.prototype.unbindPortalKeyboard = function (portal) {
        if (portal && portal._awaKeyHandler) {
            portal.removeEventListener('keydown', portal._awaKeyHandler);
            portal._awaKeyHandler = null;
        }
    };

    FlyoutPortal.prototype.position = function (li, portal) {
        if (!FloatingUIDOM || typeof FloatingUIDOM.computePosition !== 'function') {
            portal.style.position = 'fixed';
            portal.style.zIndex = '99990';
            return;
        }
        var panelElement = this.root;
        FloatingUIDOM.computePosition(li, portal, {
            placement: 'right-start',
            strategy: 'fixed',
            middleware: [
                FloatingUIDOM.offset({ mainAxis: 0, crossAxis: 0 }),
                FloatingUIDOM.flip(),
                FloatingUIDOM.shift({ padding: 8 })
            ]
        }).then(function (data) {
            var panelRect = panelElement ? panelElement.getBoundingClientRect() : null;
            var panelTop = panelRect ? Math.max(8, panelRect.top) : Math.max(8, data.y);
            var availableHeight = Math.max(160, window.innerHeight - panelTop - 8);
            portal.style.setProperty(
                'max-height',
                Math.min(640, Math.floor(availableHeight)) + 'px',
                'important'
            );
            var maxTop = Math.max(8, window.innerHeight - portal.offsetHeight - 8);
            var anchoredTop = panelRect
                ? Math.min(Math.max(8, panelRect.top), maxTop)
                : Math.min(Math.max(8, data.y), maxTop);
            var maxLeft = Math.max(8, window.innerWidth - portal.offsetWidth - 8);
            var anchoredLeft = Math.min(
                Math.max(8, panelRect ? panelRect.right : data.x),
                maxLeft
            );
            Object.assign(portal.style, {
                position: data.strategy || 'fixed',
                left: anchoredLeft + 'px',
                top: anchoredTop + 'px',
                zIndex: '99990'
            });
        });
    };

    FlyoutPortal.prototype.detach = function (li, reason) {
        var menuId = li.getAttribute('data-menu') || '';
        var portal = document.querySelector(
            '.' + PORTAL_CLASS + '[data-aw-vmf-li-menu="' + menuId + '"]'
        );
        var submenu = portal
            ? portal.querySelector('.submenu, .level0.submenu, .navigation__submenu')
            : this.findSubmenu(li);

        if (portal && submenu) {
            if (typeof portal._awaCleanupAutoUpdate === 'function') {
                portal._awaCleanupAutoUpdate();
                portal._awaCleanupAutoUpdate = null;
            }
            this.unbindPortalKeyboard(portal);
            li.appendChild(submenu);
            portal.remove();
        }
        if (submenu) {
            submenu.dataset.awVmfPortaled = '';
        }
        li.classList.remove(ACTIVE_CLASS);
        li.removeAttribute('data-awa-submenu-open');
    };

    FlyoutPortal.prototype.teardown = function () {
        document.querySelectorAll('.' + PORTAL_CLASS).forEach(function (p) {
            if (typeof p._awaCleanupAutoUpdate === 'function') {
                p._awaCleanupAutoUpdate();
                p._awaCleanupAutoUpdate = null;
            }
            p.remove();
        });
    };

    FlyoutPortal.prototype.closeAll = function () {
        var self = this;
        Array.prototype.slice.call(
            this.root.querySelectorAll('li.level0[data-awa-submenu-open="true"]')
        ).forEach(function (li) {
            self.detach(li, 'close-all-open-li');
        });
        document.querySelectorAll('.' + PORTAL_CLASS).forEach(function (portal) {
            var menuId = portal.dataset.awVmfLiMenu || '';
            var li = menuId && self.root.querySelector('li.level0[data-menu="' + menuId + '"]');
            if (li) {
                self.detach(li, 'close-all-portal');
            } else {
                if (typeof portal._awaCleanupAutoUpdate === 'function') {
                    portal._awaCleanupAutoUpdate();
                    portal._awaCleanupAutoUpdate = null;
                }
                portal.remove();
            }
        });
    };

    function getLevel0Items(panel) {
        return Array.prototype.filter.call(
            panel.querySelectorAll(':scope > li.ui-menu-item.level0'),
            function (li) {
                return !li.classList.contains('expand-category-link')
                    && !li.classList.contains('awa-vmenu-empty')
                    && !li.classList.contains('vertical-bg-img')
                    && !li.classList.contains('awa-vem-extra-li')
                    && !li.classList.contains('awa-vmenu-search-li')
                    && !li.classList.contains('awa-vmenu-search-empty-li');
            }
        );
    }

    function getItemLink(li) {
        return li ? li.querySelector(':scope > a.level-top, :scope > a.navigation__link') : null;
    }

    function normalizePtTitleCase(text) {
        if (!text) {
            return text;
        }

        var lowerWords = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'para', 'com', 'por', 'a', 'o', 'as', 'os'];
        return text.split(/\s+/).map(function (word, index) {
            var lower = word.toLowerCase();
            if (index > 0 && lowerWords.indexOf(lower) !== -1) {
                return lower;
            }
            return lower.charAt(0).toUpperCase() + lower.slice(1);
        }).join(' ');
    }

    function applyMenuLabelNormalization(panel) {
        if (!panel) {
            return;
        }

        panel.querySelectorAll('.navigation__label').forEach(function (label) {
            var raw = (label.textContent || '').trim();
            if (!raw) {
                return;
            }

            var normalized = normalizePtTitleCase(raw);
            if (normalized !== raw) {
                label.textContent = normalized;
            }
        });
    }

    function isMenuPanelOpen(panel) {
        if (!panel) {
            return false;
        }

        return panel.getAttribute('data-awa-menu-state') === 'open'
            || panel.classList.contains('menu-open')
            || panel.classList.contains('vmm-open')
            || panel.getAttribute('aria-hidden') === 'false';
    }

    function applyTruncationTitles(panel) {
        var startedAt = (window.performance && typeof window.performance.now === 'function')
            ? window.performance.now()
            : Date.now();
        var links;
        var updates = [];
        var changedTitles = 0;
        var durationMs;

        if (!panel) {
            return;
        }

        if (!isMenuPanelOpen(panel)) {
            return;
        }

        links = panel.querySelectorAll('a.level-top, a.navigation__link');
        links.forEach(function (link) {
            var label = link.querySelector('.navigation__label');
            var text = label ? (label.textContent || '').trim()
                : link.getAttribute('data-awa-clean-label') || '';
            var hasBadge = !!link.querySelector('.cat-label');
            var desiredTitle = '';

            if (!text || !label) {
                return;
            }

            if (!hasBadge) {
                desiredTitle = text;
            }

            updates.push({
                link: link,
                desiredTitle: desiredTitle
            });
        });

        updates.forEach(function (update) {
            var currentTitle = update.link.getAttribute('title') || '';
            if (update.desiredTitle) {
                if (currentTitle !== update.desiredTitle) {
                    update.link.setAttribute('title', update.desiredTitle);
                    changedTitles += 1;
                }
                return;
            }

            if (currentTitle) {
                update.link.removeAttribute('title');
                changedTitles += 1;
            }
        });

        durationMs = ((window.performance && typeof window.performance.now === 'function')
            ? window.performance.now()
            : Date.now()) - startedAt;
    }

    /* ── DeptMenu ─────────────────────────────────────────────────────── */
    function DeptMenu(nav, config) {
        this.nav = nav;
        this.config = config || {};
        this.trigger = nav.querySelector('[data-role="awa-vertical-menu-trigger"]');
        this.panel = nav.querySelector('[data-role="awa-vertical-menu-panel"]');
        this.status = nav.querySelector('[data-role="awa-vertical-menu-status"]');
        this.isOpen = false;
        this.hoverTimer = null;
        this.closeTimer = null;
    }

    DeptMenu.prototype.unlockParentChain = function (open) {
        if (!this.nav) {
            return;
        }

        var node = this.nav;
        var navInner = document.querySelector('.awa-nav-bar__inner');

        while (node) {
            if (open) {
                /* Evita CLS: não forçar height/max-height/min-height no shell do header.
                   Apenas destrava overflow para flyouts no desktop. */
                node.style.setProperty('overflow', 'visible', 'important');
            } else {
                node.style.removeProperty('overflow');
            }

            if (node.classList && (
                node.classList.contains('awa-nav-bar')
                || node.classList.contains('header-nav-global')
                || (node.classList.contains('header-control') && node.classList.contains('header-nav'))
            )) {
                break;
            }
            node = node.parentElement;
        }

        if (navInner) {
            navInner.style.removeProperty('height');
            navInner.style.removeProperty('min-height');
            navInner.style.removeProperty('max-height');
        }
    };

    DeptMenu.prototype.measurePanelContentHeight = function () {
        var panelEl = this.panel;
        if (!panelEl) {
            return 160;
        }

        var total = 0;
        Array.prototype.forEach.call(panelEl.children, function (child) {
            if (window.getComputedStyle(child).display === 'none') {
                return;
            }
            total += child.getBoundingClientRect().height;
        });

        var panelStyles = window.getComputedStyle(panelEl);
        total += parseFloat(panelStyles.paddingTop) || 0;
        total += parseFloat(panelStyles.paddingBottom) || 0;
        total += parseFloat(panelStyles.borderTopWidth) || 0;
        total += parseFloat(panelStyles.borderBottomWidth) || 0;

        return Math.ceil(total);
    };

    DeptMenu.prototype.syncPanelHeight = function () {
        var panelEl = this.panel;
        if (!panelEl || !this.isOpen || !isDesktop()) {
            return;
        }

        this.unlockParentChain(true);
        panelEl.style.setProperty('display', 'flex', 'important');
        panelEl.style.setProperty('flex-direction', 'column', 'important');
        panelEl.style.setProperty('overflow-x', 'hidden', 'important');
        panelEl.style.setProperty('overflow-y', 'auto', 'important');
        panelEl.style.setProperty('z-index', '100100', 'important');
        panelEl.style.setProperty('height', 'auto', 'important');
        panelEl.style.setProperty('max-height', 'none', 'important');
        panelEl.style.setProperty('min-height', '0', 'important');
        panelEl.style.setProperty('padding-right', '12px', 'important');
        panelEl.style.setProperty('scrollbar-gutter', 'stable', 'important');

        void panelEl.offsetHeight;

        panelEl.style.setProperty('max-height', 'min(70vh, 560px)', 'important');
        panelEl.style.setProperty('height', 'auto', 'important');
        panelEl.style.setProperty('min-height', '120px', 'important');
        /* BUG-H2: sob sticky, top:44px invade chrome do header — alinhar sob stickyBottom. */
        (function alignPanelBelowSticky() {
            var sticky = document.querySelector('.header-wrapper-sticky.is-sticky');
            var navNode = panelEl.closest('[data-role="awa-vertical-menu"], .navigation.verticalmenu');
            var stickyBottom;
            var navTop;
            var neededTop;
            if (!sticky || !navNode) {
                return;
            }
            stickyBottom = sticky.getBoundingClientRect().bottom;
            navTop = navNode.getBoundingClientRect().top;
            neededTop = Math.ceil(stickyBottom - navTop);
            if (neededTop >= 0) {
                panelEl.style.setProperty('top', neededTop + 'px', 'important');
            }
        }());
        /* Tipografia/links já aplicados no syncAria(open) — evita N× querySelectorAll no resize. */
        if (panelEl.dataset.awaTypographyReady !== '1') {
            applyMenuLinkTypography(panelEl, true);
            applyTopLinkRuntimeFixes(panelEl);
            panelEl.dataset.awaTypographyReady = '1';
        }
    };

    DeptMenu.prototype.syncSearchRow = function (open) {
        if (!this.panel) {
            return;
        }

        var searchRow = this.panel.querySelector('[data-role="awa-vmenu-search-row"]');
        if (!searchRow) {
            return;
        }

        if (open && isDesktop()) {
            searchRow.style.setProperty('display', 'block', 'important');
        } else if (!open) {
            searchRow.style.removeProperty('display');
        }
    };

    DeptMenu.prototype.schedulePanelHeight = function () {
        var self = this;
        var attempt = 0;
        function run() {
            if (!self.isOpen || !self.panel) {
                return;
            }
            self.syncPanelHeight();
            attempt += 1;
            /* 2 passes bastam (layout + fontes); 5× era custo morto de debug. */
            if (attempt < 2) {
                window.setTimeout(run, 32);
            }
        }
        window.requestAnimationFrame(run);
    };

    DeptMenu.prototype.syncAria = function (open) {
        if (this.trigger) {
            this.trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            /* H-B/D: syncAria must own aria-label too — nav-runtime alone left
               "Fechar departamentos" after close, causing expanded/status desync. */
            this.trigger.setAttribute(
                'aria-label',
                open ? 'Fechar departamentos' : 'Abrir departamentos'
            );
            this.trigger.classList.toggle('active', open);
        }
        if (this.nav) {
            this.nav.classList.toggle(ACTIVE_CLASS, open);
            this.nav.setAttribute('data-awa-menu-open', open ? 'true' : 'false');
        }
        if (this.panel) {
            var nextPanelAriaHidden = open ? 'false' : 'true';
            if (this.panel.getAttribute('aria-hidden') !== nextPanelAriaHidden) {
                this.panel.setAttribute('aria-hidden', nextPanelAriaHidden);
            }
            /* A11Y 2026-08-05: ~76 focáveis no menu fechado com aria-hidden — inert fecha o foco. */
            if (open) {
                this.panel.removeAttribute('inert');
            } else {
                this.panel.setAttribute('inert', '');
            }
            this.panel.setAttribute('data-awa-menu-state', open ? 'open' : 'closed');
            this.panel.classList.toggle('vmm-open', open);
            this.panel.classList.toggle('menu-open', open);
            if (open) {
                this.panel.style.setProperty('display', 'flex', 'important');
                this.panel.style.setProperty('flex-direction', 'column', 'important');
                this.panel.style.setProperty('visibility', 'visible', 'important');
                this.panel.style.setProperty('opacity', '1', 'important');
                this.panel.style.setProperty('padding-right', '12px', 'important');
                this.panel.style.setProperty('scrollbar-gutter', 'stable', 'important');
                ensureRuntimeMenuStyles();
                this.syncSearchRow(true);
                applyMenuLinkTypography(this.panel, true);
                applyMenuLabelNormalization(this.panel);
                applyTruncationTitles(this.panel);
                applyTopLinkRuntimeFixes(this.panel);
                this.panel.dataset.awaTypographyReady = '1';
                if (isDesktop()) {
                    this.schedulePanelHeight();
                }
            } else {
                this.syncSearchRow(false);
                applyMenuLinkTypography(this.panel, false);
                delete this.panel.dataset.awaTypographyReady;
                ['display', 'flex-direction', 'visibility', 'opacity', 'height', 'min-height', 'max-height', 'overflow', 'overflow-x', 'overflow-y'].forEach(function (prop) {
                    this.panel.style.removeProperty(prop);
                }, this);
            }
        }
        if (this.nav && isDesktop()) {
            this.unlockParentChain(open);
        }
        if (this.status) {
            this.status.textContent = open
                ? 'Menu de departamentos aberto.'
                : 'Menu de departamentos fechado. Pressione Enter para abrir.';
        }
        syncHomeMenuComposition(open);
        document.body.classList.toggle('awa-menu-dept-open', open);
        enforcePlpTabletAxisStability(open ? 'dept-sync-open' : 'dept-sync-close');
    };

    DeptMenu.prototype.open = function () {
        if (this.isOpen) {
            return;
        }
        clearTimeout(this.closeTimer);
        this.isOpen = true;
        this._openScrollY = window.scrollY || window.pageYOffset || 0;
        this.syncAria(true);
    };

    DeptMenu.prototype.isDomOpen = function () {
        if (!this.trigger && !this.panel) {
            return false;
        }
        if (this.trigger && this.trigger.getAttribute('aria-expanded') === 'true') {
            return true;
        }
        if (!this.panel) {
            return false;
        }
        return this.panel.getAttribute('data-awa-menu-state') === 'open'
            || this.panel.classList.contains('menu-open')
            || this.panel.classList.contains('vmm-open')
            || this.panel.getAttribute('aria-hidden') === 'false';
    };

    DeptMenu.prototype.close = function () {
        /* H22: fechar mesmo com isOpen dessincronizado do DOM (painel aberto, flag false). */
        if (!this.isOpen && !this.isDomOpen()) {
            return;
        }
        clearTimeout(this.hoverTimer);
        clearTimeout(this.closeTimer);
        this.isOpen = false;
        this._openScrollY = null;
        this.syncAria(false);
    };

    DeptMenu.prototype.closeSoon = function (delay) {
        var self = this;
        clearTimeout(this.closeTimer);
        this.closeTimer = window.setTimeout(function () {
            if (document.querySelector('.' + PORTAL_CLASS + ':hover')) {
                self.closeSoon(delay);
                return;
            }
            self.close();
        }, typeof delay === 'number' ? delay : 180);
    };

    DeptMenu.prototype.toggle = function () {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    };

    DeptMenu.prototype.bind = function (flyout) {
        var self = this;
        this.trigger = this.nav.querySelector('[data-role="awa-vertical-menu-trigger"]');
        this.panel = this.nav.querySelector('[data-role="awa-vertical-menu-panel"]');
        if (!this.trigger || !this.panel) {
            return;
        }

        guardDeptTriggerInlineTypography(this.trigger);
        window.requestAnimationFrame(function () {
            guardDeptTriggerInlineTypography(self.trigger);
        });

        if (
            document.body.classList.contains('awa-menu-dept-open')
            || this.panel.getAttribute('data-awa-menu-state') === 'open'
            || this.trigger.getAttribute('aria-expanded') === 'true'
        ) {
            this.isOpen = true;
            this.syncAria(true);
        }

        this.trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (isTabletNav()) {
                self.isOpen = !!(self.isOpen
                    || self.trigger.getAttribute('aria-expanded') === 'true'
                    || self.panel.getAttribute('data-awa-menu-state') === 'open'
                    || self.panel.classList.contains('menu-open')
                    || self.panel.classList.contains('vmm-open')
                    || document.body.classList.contains('awa-menu-dept-open')
                    || document.body.classList.contains('awa-menu-drawer-open'));
                if (self.isOpen) {
                    self.close();
                    if (mobileDrawer && mobileDrawer.open && typeof mobileDrawer.closeDrawer === 'function') {
                        mobileDrawer.closeDrawer();
                    }
                } else {
                    self.open();
                }
                return;
            }
            if (isMobile()) {
                mobileDrawer.openDrawer();
                return;
            }
            var wasOpen = self.isOpen
                || self.trigger.getAttribute('aria-expanded') === 'true'
                || self.panel.getAttribute('data-awa-menu-state') === 'open'
                || self.panel.classList.contains('menu-open')
                || self.panel.classList.contains('vmm-open');

            self.toggle();

            // If first click remains closed due race/late init, force deterministic open.
            if (!wasOpen) {
                window.requestAnimationFrame(function () {
                    var openAfterToggle = self.trigger.getAttribute('aria-expanded') === 'true'
                        || self.panel.getAttribute('data-awa-menu-state') === 'open'
                        || self.panel.classList.contains('menu-open')
                        || self.panel.classList.contains('vmm-open');

                    if (!openAfterToggle) {
                        self.open();
                    }
                });
            }
        });

        this.trigger.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'ArrowDown') {
                return;
            }
            e.preventDefault();
            if (isTabletNav()) {
                self.open();
                window.requestAnimationFrame(function () {
                    var first = self.panel.querySelector('[data-role="awa-vmenu-search"], a.level-top, a.navigation__link');
                    if (first) {
                        first.focus();
                    }
                });
                return;
            }
            if (isMobile()) {
                mobileDrawer.openDrawer();
                return;
            }
            self.open();
            window.requestAnimationFrame(function () {
                var first = self.panel.querySelector('[data-role="awa-vmenu-search"], a.level-top, a.navigation__link');
                if (first) {
                    first.focus();
                }
            });
        });

        this.nav.addEventListener('mouseenter', function () {
            if (!isDesktop()) {
                return;
            }
            clearTimeout(self.closeTimer);
            clearTimeout(self.hoverTimer);
            self.hoverTimer = window.setTimeout(function () { self.open(); }, self.config.hoverDelay || 100);
        });
        this.nav.addEventListener('mouseleave', function (e) {
            if (!isDesktop()) {
                return;
            }
            var to = e.relatedTarget;
            if (to && (self.nav.contains(to) || (to.closest && to.closest('.' + PORTAL_CLASS)))) {
                return;
            }
            clearTimeout(self.hoverTimer);
            self.closeSoon(self.config.closeDelay || 180);
        });
        document.addEventListener('mouseleave', function (e) {
            if (!isDesktop() || !self.isOpen) {
                return;
            }
            var from = e.target;
            var to = e.relatedTarget;
            if (to || !from || !from.closest || (!from.closest('.' + PORTAL_CLASS) && !from.closest('[data-role="awa-vertical-menu"]'))) {
                return;
            }
            self.closeSoon(self.config.closeDelay || 180);
        }, true);

        document.addEventListener('click', function (e) {
            var target = e && e.target ? e.target : null;
            var targetElement = target && target.nodeType === 3 ? target.parentElement : target;
            var navSelector = '[data-role="awa-vertical-menu"], .navigation.verticalmenu.side-verticalmenu';
            var currentNav = targetElement && targetElement.closest
                ? targetElement.closest(navSelector)
                : null;
            if (!currentNav) {
                currentNav = document.querySelector(navSelector);
            }
            var insideCurrentNav = !!(currentNav && targetElement && currentNav.contains(targetElement));
            var insideSelfNav = !!(targetElement && self.nav && self.nav.contains(targetElement));
            var insideKnownMenu = !!(
                targetElement && targetElement.closest && targetElement.closest(
                    '[data-role="awa-vertical-menu-trigger"], [data-role="awa-vertical-menu-panel"], .title-category-dropdown.our_categories, .navigation.verticalmenu.side-verticalmenu'
                )
            );
            if (
                !self.isOpen
                || insideSelfNav
                || insideCurrentNav
                || insideKnownMenu
            ) {
                return;
            }
            self.close();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !self.isOpen) {
                return;
            }
            if (flyout && document.querySelector('.' + PORTAL_CLASS)) {
                flyout.closeAll();
                return;
            }
            self.close();
            self.trigger.focus();
        });

        this.bindPanelKeyboard(flyout);
        this.bindPanelSearch();
        this.bindLimitShow();
        this.syncSearchRow(this.isOpen);
        if (this.isOpen) {
            this.schedulePanelHeight();
        }
        applyMenuLabelNormalization(this.panel);
        applyTruncationTitles(this.panel);
        if (this.panel && window.ResizeObserver) {
            var ro = new ResizeObserver(function () {
                applyTruncationTitles(self.panel);
            });
            ro.observe(this.panel);
        }
        window.addEventListener('resize', function () {
            applyTruncationTitles(self.panel);
            syncHomeMenuComposition(self.isOpen);
        }, { passive: true });

        /* H22: menu sticky aberto cobre Lançamentos (~141k px²). Fechar no scroll
           da página (não no scroll interno do painel). Threshold evita micro-jitter. */
        if (!this._scrollCloseBound) {
            this._scrollCloseBound = true;
            var SCROLL_CLOSE_PX = 40;
            window.addEventListener('scroll', function (evt) {
                if (!isDesktop()) {
                    return;
                }
                var domOpen = self.isDomOpen();
                if (!self.isOpen && !domOpen) {
                    return;
                }
                if (!self.isOpen && domOpen) {
                    self.isOpen = true;
                    if (typeof self._openScrollY !== 'number') {
                        self._openScrollY = window.scrollY || window.pageYOffset || 0;
                    }
                }
                var target = evt && evt.target;
                if (target && target !== document && target !== document.documentElement
                    && target !== document.body && self.panel && self.panel.contains(target)) {
                    return;
                }
                var y = window.scrollY || window.pageYOffset || 0;
                var base = typeof self._openScrollY === 'number' ? self._openScrollY : y;
                var delta = Math.abs(y - base);
                if (delta < SCROLL_CLOSE_PX) {
                    return;
                }
                if (flyout && typeof flyout.closeAll === 'function') {
                    flyout.closeAll();
                }
                self.close();
            }, { passive: true, capture: true });
        }

        this.panel.querySelectorAll('.open-children-toggle').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var li = btn.closest('li');
                if (!li) {
                    return;
                }
                var sub = li.querySelector(':scope > .subchildmenu, :scope > .submenu');
                if (!sub) {
                    return;
                }
                var opened = sub.classList.toggle('opened');
                btn.setAttribute('aria-expanded', opened ? 'true' : 'false');
                sub.style.display = opened ? '' : 'none';
            });
        });

    };

    DeptMenu.prototype.bindLimitShow = function () {
        var self = this;
        if (!this.panel) {
            return;
        }

        var limit = parseInt(this.panel.getAttribute('data-limit-show') || '0', 10);
        var expandLi = this.panel.querySelector(':scope > li.expand-category-link');
        var expandBtn = expandLi ? expandLi.querySelector('.vm-toggle-categories') : null;
        var items = getLevel0Items(this.panel);

        if (!expandBtn || limit <= 0 || items.length <= limit) {
            if (expandLi) {
                expandLi.style.display = 'none';
            }
            return;
        }

        expandLi.style.display = '';
        items.forEach(function (li, index) {
            if (index >= limit) {
                li.classList.add('orther-link');
                li.classList.remove('is-expanded');
                li.style.setProperty('display', 'none', 'important');
            } else {
                li.style.removeProperty('display');
            }
        });

        var showText = expandBtn.getAttribute('data-show-text') || expandBtn.textContent.trim();
        var hideText = expandBtn.getAttribute('data-hide-text') || showText;
        var showAria = expandBtn.getAttribute('data-show-aria') || showText;
        var hideAria = expandBtn.getAttribute('data-hide-aria') || hideText;
        var labelSpan = expandBtn.querySelector('span');

        if (labelSpan && showText) {
            labelSpan.textContent = showText;
        }

        if (self.isOpen) {
            self.schedulePanelHeight();
        }

        expandBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var expanding = !expandBtn.classList.contains('expanding');
            expandBtn.classList.toggle('expanding', expanding);
            expandLi.classList.toggle('expanding', expanding);
            expandBtn.setAttribute('aria-expanded', expanding ? 'true' : 'false');

            if (labelSpan) {
                labelSpan.textContent = expanding ? hideText : showText;
            }
            expandBtn.setAttribute('aria-label', expanding ? hideAria : showAria);

            items.forEach(function (li, index) {
                if (index < limit) {
                    return;
                }
                li.classList.toggle('is-expanded', expanding);
                if (expanding) {
                    li.style.removeProperty('display');
                } else {
                    li.style.setProperty('display', 'none', 'important');
                }
            });
            self.schedulePanelHeight();
        });
    };

    DeptMenu.prototype.bindPanelKeyboard = function (flyout) {
        var self = this;
        if (!this.panel) {
            return;
        }

        this.panel.addEventListener('keydown', function (e) {
            if (!isDesktop() || !self.isOpen) {
                return;
            }

            var items = getLevel0Items(self.panel);
            if (!items.length) {
                return;
            }

            var currentLi = e.target.closest('li.ui-menu-item.level0');
            var idx = currentLi ? items.indexOf(currentLi) : -1;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var nextIdx = idx < 0 ? 0 : Math.min(idx + 1, items.length - 1);
                var nextLink = getItemLink(items[nextIdx]);
                if (nextLink) {
                    nextLink.focus();
                }
                return;
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prevIdx = idx <= 0 ? 0 : idx - 1;
                var prevLink = getItemLink(items[prevIdx]);
                if (prevLink) {
                    prevLink.focus();
                }
                return;
            }

            if ((e.key === 'ArrowRight' || e.key === 'Enter' || e.key === ' ') && currentLi) {
                if (currentLi.classList.contains('parent') || currentLi.classList.contains('navigation__item--parent')) {
                    e.preventDefault();
                    currentLi.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true, cancelable: true }));
                    if (flyout) {
                        flyout.attach(currentLi);
                    }
                }
                return;
            }

            if ((e.key === 'ArrowLeft' || e.key === 'Escape') && flyout && document.querySelector('.' + PORTAL_CLASS)) {
                e.preventDefault();
                e.stopPropagation();
                flyout.closeAll();
                if (currentLi) {
                    var link = getItemLink(currentLi);
                    if (link) {
                        link.focus();
                    }
                }
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !isDesktop() || !document.querySelector('.' + PORTAL_CLASS)) {
                return;
            }
            if (self.panel && self.panel.contains(document.activeElement)) {
                return;
            }
            if (flyout) {
                flyout.closeAll();
            }
        }, true);
    };

    DeptMenu.prototype.bindPanelSearch = function () {
        var self = this;
        var input = this.panel && this.panel.querySelector('[data-role="awa-vmenu-search"]');
        if (!input) {
            return;
        }

        var searchRow = this.panel.querySelector('[data-role="awa-vmenu-search-row"]');
        var searchTimer = null;

        function normalize(text) {
            return (text || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
        }

        function getFilterableItems() {
            return Array.prototype.filter.call(
                self.panel.querySelectorAll(':scope > li.ui-menu-item.level0'),
                function (li) {
                    return !li.classList.contains('awa-vmenu-search-li')
                        && !li.classList.contains('awa-vmenu-search-empty-li');
                }
            );
        }

        function ensureSearchEmptyRow() {
            var existing = self.panel.querySelector('[data-role="awa-vmenu-search-empty"]');
            if (existing) {
                return existing.closest('li');
            }
            if (!searchRow) {
                return null;
            }
            var li = document.createElement('li');
            li.className = 'awa-vmenu-search-empty-li';
            li.setAttribute('data-role', 'awa-vmenu-search-empty');
            li.setAttribute('role', 'none');
            li.innerHTML = '<div class="awa-vmenu-search-empty" aria-live="polite">' +
                '<span class="awa-vmenu-search-empty-icon" aria-hidden="true">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" focusable="false" aria-hidden="true">' +
                '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg></span>' +
                '<p class="awa-vmenu-search-empty-text"></p></div>';
            searchRow.insertAdjacentElement('afterend', li);
            return li;
        }

        function filterItems(query) {
            var rawQuery = (query || '').trim();
            var q = normalize(rawQuery);
            var visibleCount = 0;

            getFilterableItems().forEach(function (li) {
                var labelEl = li.querySelector('.navigation__label')
                    || li.querySelector('a.level-top, a.navigation__link');
                var label = normalize(labelEl ? labelEl.textContent : '');
                var match = !q || label.indexOf(q) !== -1;
                li.style.display = match ? '' : 'none';
                if (match) {
                    visibleCount += 1;
                }
            });

            self.panel.querySelectorAll(':scope > .awa-vmenu__divider, :scope > .awa-vmenu__section-label').forEach(function (el) {
                if (!q) {
                    el.style.display = '';
                    return;
                }
                var nextVisible = Array.prototype.find.call(
                    getFilterableItems(),
                    function (item) { return item.style.display !== 'none'; }
                );
                el.style.display = nextVisible ? '' : 'none';
            });

            var expandLink = self.panel.querySelector(':scope > li.expand-category-link');
            if (expandLink) {
                expandLink.style.display = q ? 'none' : '';
            }

            var emptyLi = self.panel.querySelector('[data-role="awa-vmenu-search-empty"]');
            emptyLi = emptyLi ? emptyLi.closest('li') : null;
            if (q && visibleCount === 0) {
                emptyLi = ensureSearchEmptyRow();
                if (emptyLi) {
                    var textEl = emptyLi.querySelector('.awa-vmenu-search-empty-text');
                    if (textEl) {
                        textEl.innerHTML = 'Nenhuma categoria para <span class="awa-vmenu-search-empty-query"></span>';
                        var queryEl = textEl.querySelector('.awa-vmenu-search-empty-query');
                        if (queryEl) {
                            queryEl.textContent = rawQuery;
                        }
                    }
                    emptyLi.classList.add('is-visible');
                    emptyLi.style.display = '';
                }
            } else if (emptyLi) {
                emptyLi.classList.remove('is-visible');
                emptyLi.style.display = 'none';
            }

            if (self.status) {
                if (q) {
                    self.status.textContent = visibleCount > 0
                        ? visibleCount + ' categoria' + (visibleCount !== 1 ? 's' : '') + ' encontrada' + (visibleCount !== 1 ? 's' : '')
                        : 'Nenhuma categoria para "' + rawQuery + '"';
                } else if (self.isOpen) {
                    self.status.textContent = 'Menu de departamentos aberto.';
                }
            }

            if (self.isOpen) {
                self.schedulePanelHeight();
            }
        }

        input.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var value = input.value;
            searchTimer = window.setTimeout(function () {
                filterItems(value);
            }, 120);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && input.value) {
                input.value = '';
                filterItems('');
                e.stopPropagation();
            }
        });
    };

    /* ── MobileDrawer ───────────────────────────────────────────────── */
    var mobileDrawer = {
        toggle: null,
        shell: null,
        overlay: null,
        lastFocus: null,
        trapHandler: null,
        open: false,
        closing: false,

        ensureOverlay: function () {
            if (this.overlay) {
                return this.overlay;
            }
            this.overlay = document.querySelector('.awa-mobile-drawer-overlay');
            if (this.overlay) {
                return this.overlay;
            }
            this.overlay = document.createElement('button');
            this.overlay.type = 'button';
            this.overlay.className = 'awa-mobile-drawer-overlay';
            this.overlay.setAttribute('aria-label', 'Fechar menu');
            this.overlay.setAttribute('aria-hidden', 'true');
            this.overlay.setAttribute('tabindex', '-1');
            document.body.appendChild(this.overlay);
            return this.overlay;
        },

        ensureDrawerHeader: function () {
            var shell = this.shell || resolveDrawerShell();
            if (!shell) {
                return null;
            }
            var header = shell.querySelector('.awa-menu-drawer-header');
            if (header) {
                return header;
            }
            header = document.createElement('div');
            header.className = 'awa-menu-drawer-header';
            header.setAttribute('data-awa-menu-drawer-header', 'true');

            var title = document.createElement('p');
            title.className = 'awa-menu-drawer-header__title';
            title.textContent = 'Departamentos';

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'awa-nav-close awa-mobile-drawer-close';
            closeBtn.setAttribute('aria-label', 'Fechar menu');
            closeBtn.innerHTML = '<span aria-hidden="true">&times;</span>';

            header.appendChild(title);
            header.appendChild(closeBtn);
            shell.insertBefore(header, shell.firstChild);

            var self = this;
            closeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                self.closeDrawer();
            });

            return header;
        },

        syncShell: function (isOpen) {
            var shell = this.shell || resolveDrawerShell();
            if (!shell && isOpen) {
                this.shell = resolveDrawerShell();
                shell = this.shell;
            }
            if (!shell) {
                return;
            }

            var targets = getDrawerTargets(shell);
            var plpTabletAxisScope = isPlpTabletAxisScope();
            targets.forEach(function (target) {
                target.classList.toggle('is-awa-mobile-open', isOpen);
                if (isOpen) {
                    unlockDrawerHosts(target);
                    target.style.setProperty('position', 'fixed', 'important');
                    target.style.setProperty('top', '0', 'important');
                    target.style.setProperty('left', '0', 'important');
                    target.style.setProperty('width', 'min(86vw, 360px)', 'important');
                    target.style.setProperty('height', '100dvh', 'important');
                    target.style.setProperty('max-height', '100dvh', 'important');
                    target.style.setProperty('min-height', '0', 'important');
                    target.style.setProperty('z-index', '1300', 'important');
                    target.style.setProperty('display', 'block', 'important');
                    target.style.setProperty('visibility', 'visible', 'important');
                    target.style.setProperty('opacity', '1', 'important');
                    target.style.setProperty('pointer-events', 'auto', 'important');
                    target.style.setProperty('overflow-y', 'auto', 'important');
                    target.style.setProperty('overflow-x', 'hidden', 'important');
                    if (plpTabletAxisScope) {
                        target.style.setProperty('padding', '16px 0 calc(24px + env(safe-area-inset-bottom))', 'important');
                    } else {
                        target.style.setProperty('padding', '16px 12px calc(24px + env(safe-area-inset-bottom))', 'important');
                    }
                    target.style.setProperty('box-sizing', 'border-box', 'important');
                    target.style.setProperty('background', 'var(--awa-surface, #fff)', 'important');
                    target.style.setProperty('box-shadow', '8px 0 28px rgb(15 23 42 / 15%)', 'important');
                    slideDrawerIn(target);
                } else {
                    applyMenuLinkTypography(target, false);
                    relockDrawerHosts(target);
                    ['display', 'visibility', 'opacity', 'pointer-events', 'position', 'width', 'height', 'max-height', 'min-height', 'z-index', 'transform', 'top', 'left', 'overflow-y', 'overflow-x', 'padding', 'box-sizing', 'background', 'box-shadow', 'transition'].forEach(function (p) {
                        target.style.removeProperty(p);
                    });
                }
            });

            syncDrawerToggleAria(isOpen);

            if (isOpen && isMobile()) {
                this.shell = shell;
                this.ensureDrawerHeader();
                targets.forEach(function (target) {
                    applyMenuLinkTypography(target, true);
                });
                var searchRow = shell.querySelector('[data-role="awa-vmenu-search-row"]');
                if (searchRow) {
                    searchRow.style.setProperty('display', 'block', 'important');
                }
                var panel = shell.querySelector('[data-role="awa-vertical-menu-panel"]');
                if (panel) {
                    if (panel.getAttribute('aria-hidden') !== 'false') {
                        panel.setAttribute('aria-hidden', 'false');
                    }
                    panel.setAttribute('data-awa-menu-state', 'open');
                    panel.classList.add('menu-open', 'vmm-open');
                    panel.style.setProperty('display', 'block', 'important');
                    panel.style.setProperty('visibility', 'visible', 'important');
                    panel.style.setProperty('opacity', '1', 'important');
                    panel.style.setProperty('position', 'static', 'important');
                    panel.style.setProperty('width', '100%', 'important');
                    panel.style.setProperty('height', 'auto', 'important');
                    panel.style.setProperty('max-height', 'none', 'important');
                    panel.style.setProperty('overflow', 'visible', 'important');
                }
                applyMenuLabelNormalization(panel || shell);
                applyTruncationTitles(panel || shell);
            } else {
                var closedPanel = shell.querySelector('[data-role="awa-vertical-menu-panel"]');
                if (closedPanel) {
                    if (closedPanel.getAttribute('aria-hidden') !== 'true') {
                        closedPanel.setAttribute('aria-hidden', 'true');
                    }
                    closedPanel.setAttribute('data-awa-menu-state', 'closed');
                    closedPanel.classList.remove('menu-open', 'vmm-open');
                    ['display', 'visibility', 'opacity', 'position', 'width', 'height', 'max-height', 'overflow'].forEach(function (p) {
                        closedPanel.style.removeProperty(p);
                    });
                }
            }
            enforcePlpTabletAxisStability(isOpen ? 'drawer-sync-open' : 'drawer-sync-close');
        },

        openDrawer: function () {
            if (!isMobile() || this.open) {
                return;
            }
            var self = this;
            this.lastFocus = document.activeElement;
            this.open = true;
            document.body.classList.add('nav-open', 'awa-menu-drawer-open');
            document.body.classList.remove('awa-nav-preflight');
            this.syncShell(true);
            syncDrawerToggleAria(true);
            if (this.toggle) {
                this.toggle.setAttribute('aria-expanded', 'true');
            }
            var ov = this.ensureOverlay();
            ov.classList.add('is-active');
            ov.setAttribute('aria-hidden', 'false');
            var focusDelay = drawerMotionEnabled() ? 300 : 0;
            window.setTimeout(function () {
                var shell = self.shell || resolveDrawerShell();
                var closeBtn = shell && shell.querySelector('.awa-mobile-drawer-close');
                if (closeBtn) {
                    closeBtn.focus();
                    return;
                }
                var focusables = getFocusables(shell);
                if (focusables.length) {
                    focusables[0].focus();
                }
            }, focusDelay);
            if (this.trapHandler) {
                document.addEventListener('keydown', this.trapHandler);
            }
        },

        closeDrawer: function () {
            if (!this.open || this.closing) {
                return;
            }
            var self = this;
            if (this.trapHandler) {
                document.removeEventListener('keydown', this.trapHandler);
            }
            this.closing = true;
            var targets = getDrawerTargets(this.shell);
            var duration = drawerMotionEnabled() ? 280 : 0;

            if (this.overlay) {
                this.overlay.classList.remove('is-active');
                this.overlay.setAttribute('aria-hidden', 'true');
            }
            targets.forEach(slideDrawerOut);

            window.setTimeout(function () {
                self.closing = false;
                self.open = false;
                document.body.classList.remove('nav-open', 'awa-menu-drawer-open', 'awa-mobile-drawer-open', 'nav-before-open');
                self.syncShell(false);
                syncDrawerToggleAria(false);
                if (self.toggle) {
                    self.toggle.setAttribute('aria-expanded', 'false');
                }
                if (self.lastFocus && typeof self.lastFocus.focus === 'function') {
                    self.lastFocus.focus();
                } else if (self.toggle) {
                    self.toggle.focus();
                }
                self.lastFocus = null;
            }, duration);
        },

        bind: function () {
            var self = this;
            this.toggle = document.querySelector('[data-awa-nav-toggle="true"]');
            this.shell = resolveDrawerShell();
            if (!this.toggle) {
                return;
            }

            this.trapHandler = function (e) {
                if (e.key !== 'Tab' || !self.open) {
                    return;
                }
                var focusables = getFocusables(self.shell);
                if (!focusables.length) {
                    return;
                }
                var first = focusables[0];
                var last = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            };

            if (
                isMobile()
                && (
                    document.body.classList.contains('nav-open')
                    || document.body.classList.contains('awa-nav-preflight')
                )
                && !this.open
            ) {
                this.open = true;
                document.body.classList.add('awa-menu-drawer-open');
                document.body.classList.remove('awa-nav-preflight');
                this.syncShell(true);
                if (this.toggle) {
                    this.toggle.setAttribute('aria-expanded', 'true');
                }
            }

            this.toggle.addEventListener('click', function (e) {
                if (!isMobile()) {
                    return;
                }
                e.preventDefault();
                if (self.open) {
                    self.closeDrawer();
                } else {
                    self.openDrawer();
                }
            });

            document.querySelectorAll('.toggle-nav-footer, [data-action="toggle-nav"]').forEach(function (btn) {
                if (btn === self.toggle || btn.getAttribute('data-awa-nav-toggle') === 'true') {
                    return;
                }
                btn.addEventListener('click', function (e) {
                    if (!isMobile()) {
                        return;
                    }
                    e.preventDefault();
                    if (self.open) {
                        self.closeDrawer();
                    } else {
                        self.openDrawer();
                    }
                });
            });

            document.querySelectorAll('.awa-nav-close, .vmm-mobile-close').forEach(function (btn) {
                if (btn.dataset.awaDrawerCloseBound === '1') {
                    return;
                }
                btn.dataset.awaDrawerCloseBound = '1';
                btn.addEventListener('click', function (e) {
                    if (!isMobile() || !self.open) {
                        return;
                    }
                    e.preventDefault();
                    self.closeDrawer();
                });
            });

            this.ensureOverlay().addEventListener('click', function () {
                self.closeDrawer();
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && self.open) {
                    self.closeDrawer();
                }
            });
        }
    };

    /* ── HorizontalNav ──────────────────────────────────────────────── */
    function HorizontalNav() {
        this.root = document.querySelector('.navigation.custommenu.main-nav');
    }

    HorizontalNav.prototype.bind = function () {
        if (!this.root) {
            return;
        }
        this.root.setAttribute('role', 'navigation');
        this.root.querySelectorAll('a.level-top').forEach(function (link) {
            if (!link.getAttribute('tabindex')) {
                link.setAttribute('tabindex', '0');
            }
        });
        this.root.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') {
                return;
            }
            var links = Array.prototype.slice.call(this.querySelectorAll('a.level-top, .top-menu > li > a'));
            var idx = links.indexOf(document.activeElement);
            if (idx < 0) {
                return;
            }
            e.preventDefault();
            var next = e.key === 'ArrowRight' ? idx + 1 : idx - 1;
            if (links[next]) {
                links[next].focus();
            }
        }.bind(this.root));
    };

    /* Instâncias DeptMenu — fechamento global (sticky header / escape) */
    var DEPT_MENU_INSTANCES = [];

    function closeAllDeptMenus(reason) {
        var closed = 0;
        for (var i = 0; i < DEPT_MENU_INSTANCES.length; i++) {
            var instance = DEPT_MENU_INSTANCES[i];
            if (instance && (instance.isOpen || (typeof instance.isDomOpen === 'function' && instance.isDomOpen()))) {
                try {
                    if (instance._flyout && typeof instance._flyout.closeAll === 'function') {
                        instance._flyout.closeAll();
                    }
                    instance.close();
                    closed += 1;
                } catch (err) { /* ignore */ }
            }
        }
        /* Fallback DOM: garante fechamento mesmo se a lista de instâncias estiver vazia/stale. */
        if (closed === 0) {
            document.querySelectorAll('[data-role="awa-vertical-menu-trigger"][aria-expanded="true"]').forEach(function (trigger) {
                try {
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.setAttribute('aria-label', 'Abrir departamentos');
                    trigger.classList.remove('active');
                    closed += 1;
                } catch (err) { /* ignore */ }
            });
            document.querySelectorAll('[data-role="awa-vertical-menu-panel"]').forEach(function (panel) {
                try {
                    panel.setAttribute('data-awa-menu-state', 'closed');
                    panel.setAttribute('aria-hidden', 'true');
                    panel.classList.remove('menu-open', 'vmm-open');
                    ['display', 'flex-direction', 'visibility', 'opacity', 'height', 'min-height', 'max-height', 'overflow', 'overflow-x', 'overflow-y'].forEach(function (prop) {
                        panel.style.removeProperty(prop);
                    });
                } catch (err) { /* ignore */ }
            });
            /* H-C: fallback previously skipped status — keep SR text in sync. */
            document.querySelectorAll('[data-role="awa-vertical-menu-status"]').forEach(function (statusEl) {
                try {
                    statusEl.textContent = 'Menu de departamentos fechado. Pressione Enter para abrir.';
                } catch (err) { /* ignore */ }
            });
        }
        document.body.classList.remove('awa-menu-dept-open');
        return closed;
    }

    window.__awaCloseDeptMenus = closeAllDeptMenus;

    if (!window.__awaDeptStickyCloseBound) {
        window.__awaDeptStickyCloseBound = true;
        document.addEventListener('awa:header-sticky-change', function (evt) {
            if (evt && evt.detail && evt.detail.sticky) {
                closeAllDeptMenus('sticky-event');
            }
        });
    }

    /* H22: fechamento global no scroll da página (complementa sticky-event).
       Com header sticky, qualquer scroll fecha — evita painel cobrindo Lançamentos. */
    if (!window.__awaDeptScrollCloseBound) {
        window.__awaDeptScrollCloseBound = true;
        var __awaDeptScrollCloseY = null;
        var __awaDeptScrollCloseTicking = false;
        window.addEventListener('scroll', function () {
            if (!isDesktop()) {
                return;
            }
            var anyOpen = !!document.querySelector(
                '[data-role="awa-vertical-menu-trigger"][aria-expanded="true"],'
                + '[data-role="awa-vertical-menu-panel"][data-awa-menu-state="open"]'
            );
            if (!anyOpen) {
                __awaDeptScrollCloseY = null;
                return;
            }
            var isSticky = !!document.querySelector('.header-wrapper-sticky.is-sticky')
                || document.body.classList.contains('awa-header-is-sticky');
            var y = window.scrollY || window.pageYOffset || 0;
            if (!isSticky) {
                if (__awaDeptScrollCloseY === null) {
                    __awaDeptScrollCloseY = y;
                    return;
                }
                if (Math.abs(y - __awaDeptScrollCloseY) < 40) {
                    return;
                }
            }
            if (__awaDeptScrollCloseTicking) {
                return;
            }
            __awaDeptScrollCloseTicking = true;
            window.requestAnimationFrame(function () {
                __awaDeptScrollCloseTicking = false;
                __awaDeptScrollCloseY = null;
                closeAllDeptMenus(isSticky ? 'page-scroll-sticky' : 'page-scroll');
            });
        }, {passive: true});
    }

    /* ── Widget entry (per vertical menu nav) ───────────────────────── */
    return function (config, element) {
        if (!window.__AWA_MENU_V2) {
            return;
        }

        var nav = element && element.nodeType === 1
            ? element
            : document.querySelector('[data-role="awa-vertical-menu"]');
        if (!nav) {
            return;
        }
        var dept = new DeptMenu(nav, config || {});
        var flyout = new FlyoutPortal(nav.querySelector('[data-role="awa-vertical-menu-panel"]') || nav);
        dept._flyout = flyout;
        DEPT_MENU_INSTANCES.push(dept);
        try {
            dept.bind(flyout);
            flyout.mount();
        } catch (bindErr) {
        }

        if (!DOC_BOOTED) {
            DOC_BOOTED = true;
            ensureRuntimeMenuStyles();
            mobileDrawer.bind();
            (new HorizontalNav()).bind();
            window.addEventListener('resize', function () {
                enforcePlpTabletAxisStability('window-resize');
            }, { passive: true });
            enforcePlpTabletAxisStability('boot');
            document.body.classList.add('awa-menu-v2-ready');
        }

        nav.dataset.awaMenuControllerReady = '1';
    };
});
