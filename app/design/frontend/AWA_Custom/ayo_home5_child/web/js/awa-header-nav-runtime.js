define([], function () {
    'use strict';

    let HEADER_MINICART_COUNTER_SELECTOR = '[data-awa-header-minicart-shell="true"] .counter.qty, .awa-header-minicart[data-awa-header-cart="true"] .counter.qty';
    const DESKTOP_MIN = 992;

    return function initHeaderNavRuntime() {
        if (window.__awaHeaderNavRuntimeInit) {
            return;
        }

        window.__awaHeaderNavRuntimeInit = true;

        /* ── Pre-initialize cart badge from localStorage to avoid flash ── */
        (function () {
            try {
                let emptyMarker = document.querySelector('[data-awa-cart-empty="1"]');

                if (emptyMarker) {
                    let serverCount = parseInt(emptyMarker.getAttribute('data-awa-server-items-count') || '0', 10);

                    if (!serverCount) {
                        let staleBadge = document.querySelector('.awa-header-cart-link .awa-cart-link-badge');

                        if (staleBadge) {
                            staleBadge.textContent = '';
                            staleBadge.style.display = 'none';
                            staleBadge.classList.add('awa-badge-hidden');
                        }

                        return;
                    }
                }

                let cache = JSON.parse(localStorage.getItem('mage-cache-storage') || '{}');
                let count = Number((cache.cart || {}).summary_count || 0);
                if (count > 0) {
                    let badge = document.querySelector('.awa-header-cart-link .awa-cart-link-badge');
                    if (badge) {
                        badge.textContent = count > 99 ? '99+' : String(count);
                        badge.style.cssText = 'display:inline-flex;align-items:center;justify-content:center';
                    }
                }
            } catch (e) {
                // localStorage not available
            }
        }());

        /* ── resolveDrawerShell/syncNavAria ficam no escopo de initHeaderNavRuntime (nao dentro da IIFE
           abaixo) porque tambem sao usadas mais adiante por resetMobileDrawerStateForDesktop() e
           bindMenuViewportBoundary() (fix: eram funcoes locais da IIFE, gerando
           "syncNavAria is not defined" ao cruzar o breakpoint desktop/mobile). ── */
        function resolveDrawerShell() {
            return document.querySelector('[data-awa-nav-shell="true"]') ||
                document.getElementById('awa-category-navigation') ||
                document.querySelector('#awa-primary-navigation.section-items');
        }

        function syncNavAria() {
            let isOpen = document.body.classList.contains('nav-open')
                || document.body.classList.contains('nav-before-open')
                || document.body.classList.contains('awa-mobile-drawer-open');
            let toggle = document.querySelector('.awa-header-mobile-toggle[data-awa-nav-toggle="true"]');

            if (toggle) {
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }

            let drawerShell = resolveDrawerShell();
            if (drawerShell) {
                drawerShell.classList.toggle('is-awa-mobile-open', isOpen);
            }
        }

        /* ── aria-expanded sync for hamburger (body.nav-open toggled by Magento menu.js) ── */
        (function () {
            if (window.MutationObserver) {
                let navAriaQueued = false;

                new MutationObserver(function () {
                    if (navAriaQueued) {
                        return;
                    }
                    navAriaQueued = true;
                    window.requestAnimationFrame(function () {
                        navAriaQueued = false;
                        syncNavAria();
                    });
                }).observe(document.body, {
                    attributes: true,
                    attributeFilter: ['class']
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', syncNavAria, { once: true });
            } else {
                syncNavAria();
            }
        }());

        /* ── aria sync para menu vertical: estado consistente entre trigger e painel ── */
        (function () {
            function resolveVerticalMenu() {
                return {
                    trigger: document.querySelector('[data-role="awa-vertical-menu-trigger"]'),
                    panel: document.querySelector('[data-role="awa-vertical-menu-panel"]'),
                    status: document.querySelector('[data-role="awa-vertical-menu-status"]')
                };
            }

            function isMenuOpen(menuState) {
                if (!menuState) {
                    return false;
                }

                return menuState.classList.contains('menu-open')
                    || menuState.classList.contains('vmm-open')
                    || menuState.classList.contains('open')
                    || menuState.getAttribute('data-awa-menu-state') === 'open';
            }

            function syncVerticalMenuA11y() {
                let menu = resolveVerticalMenu();

                if (!menu.trigger || !menu.panel) {
                    return;
                }

                let isOpen = isMenuOpen(menu.panel);

                menu.trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                menu.trigger.setAttribute('aria-label', isOpen ? 'Fechar departamentos' : 'Abrir departamentos');

                menu.panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');

                if (menu.status) {
                    menu.status.textContent = isOpen ? 'Menu de departamentos aberto.' : 'Menu de departamentos fechado. Pressione Enter para abrir.';
                }
            }

            if (window.MutationObserver) {
                let observed = false;

                new MutationObserver(function () {
                    if (observed) {
                        return;
                    }

                    observed = true;
                    if (window.requestAnimationFrame) {
                        window.requestAnimationFrame(function () {
                            observed = false;
                            syncVerticalMenuA11y();
                        });
                    } else {
                        observed = false;
                        syncVerticalMenuA11y();
                    }
                }).observe(document.body, {
                    attributes: true,
                    subtree: true,
                    attributeFilter: ['class', 'style', 'data-awa-menu-state']
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', syncVerticalMenuA11y, { once: true });
            } else {
                syncVerticalMenuA11y();
            }
        }());

        function syncBadge() {
            let badge = document.querySelector('.awa-header-cart-link .awa-cart-link-badge');
            if (!badge) {
                return;
            }

            let counter = document.querySelector(HEADER_MINICART_COUNTER_SELECTOR);
            if (!counter) {
                badge.style.display = 'none';
                return;
            }

            if (counter.classList.contains('empty')) {
                badge.style.display = 'none';
                return;
            }

            let total = counter.querySelector('.total-mini-cart-item');
            let value = total ? (parseInt((total.textContent || '').replace(/\D/g, ''), 10) || 0) : 0;
            if (value > 0) {
                badge.textContent = value > 99 ? '99+' : String(value);
                badge.style.display = 'inline-flex';
                badge.style.alignItems = 'center';
                badge.style.justifyContent = 'center';
            } else {
                badge.style.display = 'none';
            }
        }

        function bootBadgeSync() {
            if (document.body && document.body.classList.contains('checkout-cart-index')) {
                return;
            }

            syncBadge();

            let counter = document.querySelector(HEADER_MINICART_COUNTER_SELECTOR);
            if (counter && window.MutationObserver) {
                new MutationObserver(syncBadge).observe(counter, {
                    childList: true,
                    subtree: true,
                    characterData: true,
                    attributes: true,
                    attributeFilter: ['class']
                });
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bootBadgeSync, { once: true });
        } else {
            bootBadgeSync();
        }

        /* ── Fix: botão de busca fica disabled quando input já está pré-preenchido no carregamento
           Causa: form-mini.js do Magento inicia com submitBtn.disabled=true e só re-habilita via
           evento 'input', que não dispara quando o valor vem do URL (ex.: /catalogsearch/result/?q=zz).
           Fix: disparar o evento 'input' após o RequireJS inicializar o widget. ── */
        function isDesktopViewport() {
            return window.matchMedia
                ? window.matchMedia('(min-width: ' + DESKTOP_MIN + 'px)').matches
                : window.innerWidth >= DESKTOP_MIN;
        }

        function resetMobileDrawerStateForDesktop() {
            var body = document.body;
            if (!body) {
                return;
            }

            var toggle = document.querySelector('.awa-header-mobile-toggle[data-awa-nav-toggle="true"]');
            var shell = resolveDrawerShell();
            var sections = document.querySelectorAll(
                '.sections.nav-sections, .section-items.nav-sections, #awa-category-navigation, #awa-primary-navigation'
            );

            body.classList.remove('nav-open');
            body.classList.remove('nav-before-open');
            body.classList.remove('awa-mobile-drawer-open');
            body.style.removeProperty('overflow');
            body.style.removeProperty('overflow-x');

            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }

            if (shell) {
                shell.classList.remove('is-awa-mobile-open');
                shell.style.removeProperty('transform');
                shell.style.removeProperty('transition');
                shell.style.removeProperty('display');
                shell.style.removeProperty('opacity');
                shell.style.removeProperty('visibility');
                shell.style.removeProperty('height');
                shell.style.removeProperty('max-height');
            }

            sections.forEach(function (section) {
                section.classList.remove('is-awa-mobile-open');
                section.classList.remove('open');
                section.classList.remove('active');
                section.style.removeProperty('transform');
                section.style.removeProperty('display');
                section.style.removeProperty('visibility');
                section.style.removeProperty('opacity');
                section.style.removeProperty('height');
                section.style.removeProperty('max-height');
            });
        }

        function clearNavAxisInlineLock(el) {
            if (!el || !el.style) {
                return;
            }
            ['width', 'max-width', 'margin-inline', 'margin-left', 'margin-right',
                'padding-inline', 'padding-left', 'padding-right', 'box-sizing']
                .forEach(function (prop) {
                    el.style.removeProperty(prop);
                });
        }

        function applyDesktopNavAxisLock() {
            var body = document.body;
            if (!body) {
                return;
            }

            /* Contrato: barra full-bleed; inner = rail 1280. Sem style="" de geometria. */
            document.querySelectorAll(
                '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky > .header-control.awa-nav-bar, ' +
                '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-wrapper-sticky > .header-control.header-nav.awa-nav-bar, ' +
                '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.awa-nav-bar, ' +
                '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.header-nav.awa-nav-bar, ' +
                '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.awa-nav-bar > .container, ' +
                '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.header-nav.awa-nav-bar > .container, ' +
                '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.awa-nav-bar .awa-nav-bar__inner, ' +
                '.page-wrapper .awa-site-header[data-awa-header-mode="default"] .header-control.header-nav.awa-nav-bar .awa-nav-bar__inner'
            ).forEach(clearNavAxisInlineLock);
        }

        function bindMenuViewportBoundary() {
            var lastDesktop = isDesktopViewport();

            function handleChange() {
                var isDesktop = isDesktopViewport();
                if (isDesktop === lastDesktop) {
                    return;
                }

                if (isDesktop) {
                    resetMobileDrawerStateForDesktop();
                    applyDesktopNavAxisLock();
                } else {
                    syncNavAria();
                }

                lastDesktop = isDesktop;
            }

            if (window.matchMedia) {
                var mq = window.matchMedia('(min-width: ' + DESKTOP_MIN + 'px)');
                if (mq.addEventListener) {
                    mq.addEventListener('change', function () {
                        if (window.requestAnimationFrame) {
                            window.requestAnimationFrame(handleChange);
                        } else {
                            handleChange();
                        }
                    });
                } else if (mq.addListener) {
                    mq.addListener(function () {
                        if (window.requestAnimationFrame) {
                            window.requestAnimationFrame(handleChange);
                        } else {
                            handleChange();
                        }
                    });
                }
            } else {
                window.addEventListener('resize', function () {
                    if (window.requestAnimationFrame) {
                        window.requestAnimationFrame(handleChange);
                    } else {
                        handleChange();
                    }
                }, { passive: true });
            }
        }

        function resolveSearchControls() {
            let searchInput = document.getElementById('search');
            let form = searchInput ? searchInput.closest('form') : null;
            let submitBtn = form ? form.querySelector('button[type="submit"]') : null;

            return {
                searchInput: searchInput,
                submitBtn: submitBtn
            };
        }

        function syncSearchSubmitBtnState() {
            let controls = resolveSearchControls();
            let hasValue;

            if (!controls.searchInput || !controls.submitBtn) {
                return;
            }

            hasValue = String(controls.searchInput.value || '').trim().length >= 2;
            controls.submitBtn.disabled = !hasValue;
            controls.submitBtn.setAttribute('aria-disabled', hasValue ? 'false' : 'true');
        }

        function bindSearchSubmitBtnGuard() {
            if (window.__awaSearchSubmitBtnGuardBound) {
                return;
            }

            let controls = resolveSearchControls();
            if (!controls.searchInput || !controls.submitBtn) {
                return;
            }

            window.__awaSearchSubmitBtnGuardBound = true;

            controls.searchInput.addEventListener('input', syncSearchSubmitBtnState, { passive: true });
            controls.searchInput.addEventListener('change', syncSearchSubmitBtnState, { passive: true });
            controls.searchInput.addEventListener('keyup', syncSearchSubmitBtnState, { passive: true });

            syncSearchSubmitBtnState();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindSearchSubmitBtnGuard, { once: true });
        } else {
            bindSearchSubmitBtnGuard();
        }

        document.addEventListener('focusin', function (event) {
            if (event.target && event.target.id === 'search') {
                bindSearchSubmitBtnGuard();
                syncSearchSubmitBtnState();
            }
        }, true);

        bindMenuViewportBoundary();
        applyDesktopNavAxisLock();

    };
});
