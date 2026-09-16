(function () {
    'use strict';

    let HEADER_MINICART_SHELL_SELECTOR = '.header [data-awa-header-minicart-shell="true"], .header .awa-header-minicart[data-awa-header-cart="true"]';
    let HEADER_MINICART_TRIGGER_SELECTOR = HEADER_MINICART_SHELL_SELECTOR + ' .showcart, '
        + HEADER_MINICART_SHELL_SELECTOR + ' .action.showcart';
    let HEADER_TOP_SEARCH_NESTED_CART_SELECTOR = ':scope > [data-awa-header-minicart-shell="true"], :scope > .mini-cart-wrapper, :scope > .shadowcart';

    function reportError(context, error) {
        if (typeof console !== 'undefined' && console && typeof console.warn === 'function') {
            console.warn('[AWA Header A11y]', context, error);
        }
    }

    function normalizeText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function clampInt(value, min, max, fallback) {
        let parsed = parseInt(String(value || ''), 10);
        let result = isNaN(parsed) ? fallback : parsed;
        if (typeof min === 'number') {
            result = Math.max(min, result);
        }
        if (typeof max === 'number') {
            result = Math.min(max, result);
        }
        return result;
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = {
            normalizeText: normalizeText,
            clampInt: clampInt
        };
        return;
    }

    if (window.__awaHeaderA11yPerformanceInit) {
        return;
    }
    window.__awaHeaderA11yPerformanceInit = true;

    let raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 16); };
    let supportsPassive = false;

    function pushDataLayer(eventName, payload) {
        if (!window.dataLayer || !Array.isArray(window.dataLayer)) {
            return;
        }
        let eventPayload = payload || {};
        eventPayload.event = eventName;
        try {
            window.dataLayer.push(eventPayload);
        } catch (e) {
            reportError('dataLayer push', e);
        }
    }

    function pushHeaderTelemetry(eventName, experiment, payload) {
        let basePayload = {
            experiment_name: 'header_progressive',
            experiment_variant: experiment ? experiment.variant : 'A'
        };
        let extraPayload = payload || {};
        let key;

        for (key in extraPayload) {
            if (Object.prototype.hasOwnProperty.call(extraPayload, key)) {
                basePayload[key] = extraPayload[key];
            }
        }

        pushDataLayer(eventName, basePayload);
    }

    try {
        let optionsProbe = Object.defineProperty({}, 'passive', {
            get: function () {
                supportsPassive = true;
                return true;
            }
        });
        window.addEventListener('testPassive', null, optionsProbe);
        window.removeEventListener('testPassive', null, optionsProbe);
    } catch (e) {
        reportError('passive probe', e);
    }

    function addListener(target, eventName, handler, options) {
        if (!target || !target.addEventListener) {
            return;
        }
        if (supportsPassive && typeof options !== 'undefined') {
            target.addEventListener(eventName, handler, options);
            return;
        }
        target.addEventListener(eventName, handler, !!(options && options.capture));
    }

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    }

    function ensureToggleRole(el) {
        if (!el) { return; }
        if (el.tagName && el.tagName.toLowerCase() === 'button') {
            el.removeAttribute('role');
            el.removeAttribute('tabindex');
            return;
        }
        if (!el.getAttribute('role')) {
            el.setAttribute('role', 'button');
        }
        if (!el.getAttribute('tabindex')) {
            el.setAttribute('tabindex', '0');
        }
    }

    /**
     * A11Y-001 (2026-07-04, revisão Lighthouse desktop): o widget core
     * mage/tabs.js (acionado por data-mage-init='{"tabs":...}' em
     * sections.phtml) aplica role="tablist" no container de navegação e
     * role="presentation" nos .section-item-title filhos — combinação que
     * viola aria-required-children (tablist exige filhos com role="tab").
     * Esta navegação não implementa o padrão ARIA de abas completo (sem
     * aria-selected, sem navegação por setas entre painéis), então a
     * correção correta não é adicionar role="tab" (o que exigiria replicar
     * todo o padrão de teclado), e sim remover o role="tablist" incompleto
     * aplicado pelo widget — nenhuma funcionalidade é perdida, pois a
     * expansão/recolhimento do conteúdo continua via classes CSS e os links
     * reais dentro do menu continuam navegáveis normalmente.
     */
    function fixNavTablistRole() {
        let navShell = resolveDrawerShell();
        if (navShell && navShell.getAttribute('role') === 'tablist') {
            navShell.removeAttribute('role');
        }
    }

    function resolveDrawerShell() {
        return document.querySelector('[data-awa-nav-shell="true"]')
            || document.getElementById('awa-category-navigation')
            || document.getElementById('awa-primary-navigation')
            || document.querySelector('#awa-primary-navigation.section-items')
            || document.querySelector('.section-items.nav-sections.category-dropdown-items.awa-header-primary-nav')
            || document.querySelector('.sections.nav-sections');
    }

    function syncToggleState(toggle, expanded, navShell) {
        if (!toggle) {
            return;
        }

        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.setAttribute('aria-label', expanded ? 'Fechar menu de navegação' : 'Abrir menu de navegação');

        if (navShell && navShell.id) {
            toggle.setAttribute('aria-controls', navShell.id);
        }
    }

    function setNavState() {
        let toggle = document.querySelector('[data-awa-nav-toggle="true"]');
        if (!toggle) {
            return;
        }
        ensureToggleRole(toggle);
        let navShell = resolveDrawerShell();
        let body = document.body;
        let expanded = body.classList.contains('nav-open') || body.classList.contains('nav-before-open');
        syncToggleState(toggle, expanded, navShell);
    }

    function getExperimentConfig() {
        let header = document.querySelector('[data-awa-component="site-header"]');
        if (!header) {
            return { enabled: false, rollout: 0, seed: 'home5_header_v1', bucket: 0, active: false, variant: 'A', variantCode: 'control' };
        }

        let enabled = header.getAttribute('data-awa-header-exp-enabled') === '1';
        let rollout = clampInt(header.getAttribute('data-awa-header-exp-rollout') || '0', 0, 100, 0);
        let seed = header.getAttribute('data-awa-header-exp-seed') || 'home5_header_v1';
        let bucket = clampInt(header.getAttribute('data-awa-header-exp-bucket') || '0', 0, 99, 0);
        let active = header.getAttribute('data-awa-header-exp-active') === '1';
        let variantCode = header.getAttribute('data-awa-header-exp-variant') || (active ? 'v2' : 'control');

        let variant = enabled && active ? 'B' : 'A';
        header.setAttribute('data-awa-header-exp-group', variant);
        if (variant === 'B') {
            header.classList.add('awa-header-exp-b');
        } else {
            header.classList.remove('awa-header-exp-b');
        }

        pushHeaderTelemetry('awa_header_experiment_exposure', { variant: variant }, {
            experiment_seed: seed,
            experiment_rollout: rollout,
            experiment_bucket: bucket,
            experiment_variant_code: variantCode
        });

        return {
            enabled: enabled,
            rollout: rollout,
            seed: seed,
            bucket: bucket,
            active: active,
            variant: variant,
            variantCode: variantCode
        };
    }

    function wireNavA11y(experiment) {
        if (window.__AWA_MENU_V2) {
            return;
        }

        let toggle = document.querySelector('[data-awa-nav-toggle="true"]');
        let navShell = resolveDrawerShell();
        if (toggle) {
            ensureToggleRole(toggle);
        }
        let useEnhancedDrawer = !!experiment && experiment.variant === 'B';
        let useLegacyMobileNavFallback = !useEnhancedDrawer && !!navShell && isMobileHeaderViewport();
        let overlay = null;
        let lastFocusedElement = null;
        let focusableSelector = [
            'a[href]',
            'button:not([disabled])',
            'textarea:not([disabled])',
            'input:not([disabled]):not([type="hidden"])',
            'select:not([disabled])',
            '[tabindex]:not([tabindex="-1"])'
        ].join(',');
        let drawerVisibilityProperties = [
            'display',
            'visibility',
            'opacity',
            'pointer-events',
            'position',
            'top',
            'left',
            'bottom',
            'width',
            'max-width',
            'min-width',
            'height',
            'max-height',
            'min-height',
            'overflow-y',
            'overflow-x',
            'z-index',
            'transform'
        ];
        let drawerShellVisibilityProperties = [
            'display',
            'visibility',
            'opacity',
            'pointer-events'
        ];
        let drawerShellLayoutProperties = [
            'display',
            'width',
            'max-width',
            'overflow'
        ];

        function getDrawerTargets() {
            let targets = [];

            function pushIfPresent(element) {
                if (!element || targets.indexOf(element) !== -1) {
                    return;
                }
                targets.push(element);
            }

            pushIfPresent(navShell);
            pushIfPresent(document.getElementById('awa-primary-navigation'));
            pushIfPresent(document.getElementById('awa-category-navigation'));
            pushIfPresent(document.querySelector('.section-items.nav-sections.category-dropdown-items.awa-header-primary-nav'));

            return targets;
        }

        function syncLegacyDrawerVisibility(open) {
            let shellTargets = [];

            function pushShellTarget(element) {
                if (!element || shellTargets.indexOf(element) !== -1) {
                    return;
                }
                shellTargets.push(element);
            }

            if (!isMobileHeaderViewport()) {
                return;
            }

            getDrawerTargets().forEach(function (target) {
                let shell;

                if (!target) {
                    return;
                }

                shell = target.closest('.header-control.header-nav, .header-control.awa-nav-bar');
                pushShellTarget(shell);

                if (open) {
                    target.classList.add('is-awa-mobile-open');
                    setImportantStyle(target, 'display', 'block');
                    setImportantStyle(target, 'visibility', 'visible');
                    setImportantStyle(target, 'opacity', '1');
                    setImportantStyle(target, 'pointer-events', 'auto');

                    if (
                        target.id === 'awa-primary-navigation'
                        || target.id === 'awa-category-navigation'
                        || target.classList.contains('menu_primary')
                    ) {
                        setImportantStyle(target, 'position', 'fixed');
                        setImportantStyle(target, 'top', '0');
                        setImportantStyle(target, 'left', '0');
                        setImportantStyle(target, 'bottom', '0');
                        setImportantStyle(target, 'width', 'min(86vw, 360px)');
                        setImportantStyle(target, 'max-width', '360px');
                        setImportantStyle(target, 'min-width', '280px');
                        setImportantStyle(target, 'height', '100vh');
                        setImportantStyle(target, 'max-height', '100vh');
                        setImportantStyle(target, 'min-height', '100vh');
                        setImportantStyle(target, 'overflow-y', 'auto');
                        setImportantStyle(target, 'overflow-x', 'hidden');
                        setImportantStyle(target, 'z-index', 'calc(var(--z-overlay, 500) + 20)');
                        setImportantStyle(target, 'transform', 'translateX(0)');
                    }

                    return;
                }

                target.classList.remove('is-awa-mobile-open');
                clearStyleProperties(target, drawerVisibilityProperties);
            });

            shellTargets.forEach(function (shell) {
                let shellContainer;
                let shellInner;

                if (!shell) {
                    return;
                }

                shellContainer = shell.querySelector('.container');
                shellInner = shell.querySelector('.awa-nav-bar__inner');

                if (open) {
                    setImportantStyle(shell, 'display', 'block');
                    setImportantStyle(shell, 'visibility', 'visible');
                    setImportantStyle(shell, 'opacity', '1');
                    setImportantStyle(shell, 'pointer-events', 'auto');

                    setImportantStyle(shellContainer, 'display', 'block');
                    setImportantStyle(shellContainer, 'width', '100%');
                    setImportantStyle(shellContainer, 'max-width', 'none');
                    setImportantStyle(shellContainer, 'overflow', 'visible');

                    setImportantStyle(shellInner, 'display', 'block');
                    setImportantStyle(shellInner, 'width', '100%');
                    setImportantStyle(shellInner, 'max-width', 'none');
                    setImportantStyle(shellInner, 'overflow', 'visible');
                    return;
                }

                clearStyleProperties(shell, drawerShellVisibilityProperties);
                clearStyleProperties(shellContainer, drawerShellLayoutProperties);
                clearStyleProperties(shellInner, drawerShellLayoutProperties);
            });
        }

        function isDrawerOpen() {
            return useEnhancedDrawer && document.body.classList.contains('nav-before-open');
        }

        function getFocusableItems() {
            if (!navShell) {
                return [];
            }
            return Array.prototype.slice.call(navShell.querySelectorAll(focusableSelector)).filter(function (item) {
                return item.offsetParent !== null;
            });
        }

        function ensureOverlay() {
            if (overlay) {
                return overlay;
            }
            overlay = document.querySelector('.awa-mobile-drawer-overlay');
            if (overlay) {
                return overlay;
            }

            overlay = document.createElement('button');
            overlay.type = 'button';
            overlay.className = 'awa-mobile-drawer-overlay';
            overlay.setAttribute('aria-label', 'Fechar menu');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.setAttribute('tabindex', '-1');
            document.body.appendChild(overlay);
            return overlay;
        }

        function syncDrawerState() {
            let openState = document.body.classList.contains('nav-open') || document.body.classList.contains('nav-before-open');
            syncLegacyDrawerVisibility(openState);

            if (!useEnhancedDrawer || !navShell) {
                return;
            }

            let open = isDrawerOpen();
            let drawerOverlay = ensureOverlay();

            navShell.setAttribute('aria-hidden', open ? 'false' : 'true');
            syncToggleState(toggle, open, navShell);

            if (open) {
                document.body.classList.add('awa-mobile-drawer-open');
                drawerOverlay.classList.add('is-active');
                drawerOverlay.setAttribute('aria-hidden', 'false');
                drawerOverlay.removeAttribute('tabindex');
                return;
            }

            document.body.classList.remove('awa-mobile-drawer-open');
            drawerOverlay.classList.remove('is-active');
            drawerOverlay.setAttribute('aria-hidden', 'true');
            drawerOverlay.setAttribute('tabindex', '-1');
        }

        function openDrawer() {
            if (!useEnhancedDrawer || !navShell) {
                return;
            }

            lastFocusedElement = document.activeElement;
            document.body.classList.add('nav-before-open');
            navShell.classList.add('is-awa-mobile-open');
            syncDrawerState();

            raf(function () {
                let focusables = getFocusableItems();
                if (focusables.length) {
                    focusables[0].focus();
                }
            });
        }

        function closeDrawer() {
            if (!useEnhancedDrawer || !navShell) {
                return;
            }

            document.body.classList.remove('nav-before-open');
            navShell.classList.remove('is-awa-mobile-open');
            syncDrawerState();

            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            } else {
                toggle.focus();
            }
            lastFocusedElement = null;
        }

        if (!toggle) {
            return;
        }

        if (useEnhancedDrawer && navShell) {
            navShell.setAttribute('role', 'dialog');
            navShell.setAttribute('aria-modal', 'true');
            navShell.setAttribute('aria-hidden', 'true');
            ensureOverlay();
        }

        setNavState();
        syncDrawerState();

        addListener(toggle, 'click', function (event) {
            if (useEnhancedDrawer && navShell) {
                event.preventDefault();
                if (isDrawerOpen()) {
                    closeDrawer();
                } else {
                    openDrawer();
                }
            } else if (useLegacyMobileNavFallback) {
                event.preventDefault();
                document.body.classList.toggle('nav-open');
                navShell.classList.toggle('is-awa-mobile-open', document.body.classList.contains('nav-open'));
                syncLegacyDrawerVisibility(document.body.classList.contains('nav-open'));
            }
            raf(setNavState);
            raf(syncDrawerState);

            pushHeaderTelemetry('awa_header_nav_toggle_click', experiment);
        });

        addListener(document, 'keydown', function (event) {
            if (!useEnhancedDrawer || !navShell || !isDrawerOpen()) {
                return;
            }

            if (event.key === 'Escape') {
                closeDrawer();
                raf(setNavState);
                return;
            }

            if (event.key === 'Tab') {
                let focusables = getFocusableItems();
                if (!focusables.length) {
                    event.preventDefault();
                    return;
                }

                let first = focusables[0];
                let last = focusables[focusables.length - 1];
                let active = document.activeElement;

                if (event.shiftKey && active === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && active === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        }, { capture: true });

        addListener(document, 'click', function (event) {
            if (!useEnhancedDrawer || !navShell || !document.body.classList.contains('nav-before-open')) {
                return;
            }

            if (overlay && event.target === overlay) {
                closeDrawer();
                raf(setNavState);
                return;
            }

            if (toggle.contains(event.target) || navShell.contains(event.target)) {
                return;
            }
            closeDrawer();
            raf(setNavState);
        }, { capture: true });

        if (window.MutationObserver) {
            let bodyObserver = new MutationObserver(function () {
                setNavState();
                syncDrawerState();
            });
            bodyObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
        }
    }

    function findSearchRoot() {
        return document.querySelector('[data-awa-search-root="true"]') ||
            document.querySelector('.block-search[data-awa-component="search-autocomplete"]') ||
            document.querySelector('.top-search .block-search') ||
            document.querySelector('.block-search');
    }

    function ensureSearchStatus(root, input) {
        if (!root) {
            return null;
        }

        let status = root.querySelector('[data-awa-search-status="true"]');
        let describedBy;
        let helpId = 'awa-search-help-portal';
        let help;

        if (!status) {
            status = document.createElement('span');
            status.className = 'awa-sr-only';
            status.setAttribute('data-awa-search-status', 'true');
            status.setAttribute('aria-live', 'polite');
            status.setAttribute('aria-atomic', 'true');
            status.id = 'awa-search-status';

            (root.querySelector('.block-content') || root).appendChild(status);
        } else if (!status.id) {
            status.id = 'awa-search-status';
        }

        /* Keep help copy outside header_main so DOM pickers do not surface it there. */
        help = document.getElementById(helpId);
        if (!help) {
            help = document.createElement('span');
            help.id = helpId;
            help.className = 'awa-sr-only';
            help.setAttribute('data-awa-search-help', 'true');
            help.textContent = 'Digite ao menos 2 caracteres para começar a pesquisar.';
            document.body.appendChild(help);
        }

        if (input) {
            describedBy = normalizeText(input.getAttribute('aria-describedby'))
                .split(' ')
                .filter(Boolean);

            if (describedBy.indexOf(helpId) === -1) {
                describedBy.unshift(helpId);
            }

            if (describedBy.indexOf(status.id) === -1) {
                describedBy.push(status.id);
            }

            input.setAttribute('aria-describedby', describedBy.join(' '));
            input.removeAttribute('aria-description');
        }

        return status;
    }

    function wireSearchA11y() {
        let root = findSearchRoot();
        if (!root) {
            return;
        }

        root.setAttribute('data-awa-search-root', 'true');

        let input = root.querySelector('[data-awa-search-input="true"], #search, input[name="q"]');
        let mirasvitPanel = root.querySelector('.mst-searchautocomplete__autocomplete');
        let panel = root.querySelector('[data-awa-search-panel="true"], #search_autocomplete, .searchsuite-autocomplete, .mst-searchautocomplete__autocomplete');
        let status = ensureSearchStatus(root, input);

        if (!input || !panel) {
            return;
        }

        panel.setAttribute('data-awa-search-panel', 'true');
        if (!panel.id) {
            // Assign a safe unique ID that does not collide with `#search_autocomplete`
            // which has a high-specificity `display:none !important` rule in styles-m/l.css.
            panel.id = 'awa-search-panel-a11y';
        }
        if (!input.getAttribute('aria-controls')) {
            input.setAttribute('aria-controls', panel.id);
        }
        if (!panel.getAttribute('role')) {
            panel.setAttribute('role', 'listbox');
        }
        if (!panel.getAttribute('aria-label')) {
            panel.setAttribute('aria-label', 'Sugestões de busca');
        }

        let debounceTimer;
        let busyTimer;
        let form = root.querySelector('[data-awa-search-form="true"], #search_mini_form, form.minisearch');
        let submitBtn = form ? form.querySelector('button[type="submit"], .actions .action.search') : null;

        function getActivePanel() {
            if (mirasvitPanel && (mirasvitPanel.classList.contains('_active') ||
                mirasvitPanel.querySelectorAll('.mst-searchautocomplete__item, li').length > 0)) {
                return mirasvitPanel;
            }

            return panel;
        }

        function getSuggestionCount() {
            let activePanel = getActivePanel();
            return activePanel.querySelectorAll('.mst-searchautocomplete__item, li, [role="option"]').length;
        }

        function isSearchFocused() {
            return root.contains(document.activeElement);
        }

        function syncSearchSubmitState() {
            let query = normalizeText(input.value || '');
            let shouldEnable = query.length >= 2;

            if (!submitBtn) {
                return;
            }

            submitBtn.disabled = !shouldEnable;
            submitBtn.setAttribute('aria-disabled', shouldEnable ? 'false' : 'true');
        }

        function promotePanelIfNeeded() {
            let activePanel = getActivePanel();

            if (!isSearchFocused() || getSuggestionCount() <= 0) {
                return false;
            }

            activePanel.classList.add('is-open', 'has-results');
            activePanel.removeAttribute('hidden');
            if (form) {
                form.classList.add('is-open', 'has-results');
            }
            if (input && activePanel.id) {
                input.setAttribute('aria-controls', activePanel.id);
            }
            return true;
        }

        function syncExpanded() {
            let activePanel = getActivePanel();
            // If Mirasvit has set _active, trust it as the source of truth for visibility.
            let isMirasvitActive = activePanel.classList.contains('_active');
            let hasItems = isMirasvitActive || getSuggestionCount() > 0 ||
                normalizeText(activePanel.textContent || '').length > 0;
            let compatOpen = activePanel.classList.contains('is-open') ||
                activePanel.classList.contains('has-results');
            let focused = isSearchFocused();
            let expanded = focused && (
                compatOpen ||
                (hasItems && (promotePanelIfNeeded() || isMirasvitActive))
            );
            let query = normalizeText(input.value || '');
            input.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            // ARIA visibility (aria-hidden/hidden): owned by awa-search-autocomplete-compat.js
            if (status) {
                var nextStatusText = '';
                if (expanded) {
                    nextStatusText = String(getSuggestionCount()) + ' sugestões disponíveis';
                } else if (query.length >= 2) {
                    nextStatusText = 'Nenhuma sugestão encontrada';
                }
                if (status.textContent !== nextStatusText) {
                    status.textContent = nextStatusText;
                }
            }
            root.setAttribute('aria-busy', 'false');
            root.classList.remove('is-searching');
        }

        function markSearching() {
            root.setAttribute('aria-busy', 'true');
            root.classList.add('is-searching');
            if (status) {
                if (status.textContent !== 'Buscando sugestões...') {
                    status.textContent = 'Buscando sugestões...';
                }
            }
            if (busyTimer) {
                window.clearTimeout(busyTimer);
            }
            busyTimer = window.setTimeout(function () {
                syncExpanded();
            }, 900);
        }

        addListener(input, 'input', function () {
            syncSearchSubmitState();
            markSearching();
            if (debounceTimer) {
                window.clearTimeout(debounceTimer);
            }
            debounceTimer = window.setTimeout(function () {
                raf(syncExpanded);
            }, 220);
        }, { passive: true });

        addListener(input, 'focus', function () {
            syncSearchSubmitState();
            pushDataLayer('awa_header_search_focus', {
                experiment_name: 'header_progressive'
            });
            raf(syncExpanded);
        }, { passive: true });

        if (form) {
            addListener(form, 'submit', function () {
                pushDataLayer('awa_header_search_submit', {
                    experiment_name: 'header_progressive'
                });
            });
        }

        addListener(document, 'click', function (event) {
            if (!root.contains(event.target)) {
                input.setAttribute('aria-expanded', 'false');
                if (panel.getAttribute('aria-hidden') !== 'true') {
                    panel.setAttribute('aria-hidden', 'true');
                }
                if (!panel.hasAttribute('hidden')) {
                    panel.setAttribute('hidden', '');
                }
                panel.classList.remove('is-open', 'has-results');
                if (form) {
                    form.classList.remove('is-open', 'has-results');
                }
                if (document.body) {
                    document.body.classList.remove('searchautocomplete__active');
                }
            }
        }, { capture: true });

        addListener(document, 'keyup', function (event) {
            if (event.key === 'Escape') {
                input.setAttribute('aria-expanded', 'false');
                if (panel.getAttribute('aria-hidden') !== 'true') {
                    panel.setAttribute('aria-hidden', 'true');
                }
                if (!panel.hasAttribute('hidden')) {
                    panel.setAttribute('hidden', '');
                }
                panel.classList.remove('is-open', 'has-results');
                if (form) {
                    form.classList.remove('is-open', 'has-results');
                }
                if (document.body) {
                    document.body.classList.remove('searchautocomplete__active');
                }
                root.setAttribute('aria-busy', 'false');
                root.classList.remove('is-searching');
            }
        }, { capture: true });

        if (window.MutationObserver) {
            let observer = new MutationObserver(function (mutations) {
                // BUG-a1f9f3: ignorar attribute mutations em filhos do painel
                // (ex.: Mirasvit alternando class em <li>) — só reagir a childList
                // ou atributos do próprio painel.
                let shouldSync = false;
                let i;
                for (i = 0; i < mutations.length; i += 1) {
                    if (mutations[i].type === 'childList') {
                        shouldSync = true;
                        break;
                    }
                    if (mutations[i].type === 'attributes' && mutations[i].target === panel && mutations[i].attributeName === 'class') {
                        shouldSync = true;
                        break;
                    }
                }
                if (!shouldSync) {
                    return;
                }
                raf(syncExpanded);
            });
            observer.observe(panel, {
                attributes: true,
                // BUG-a1f9f3 v7: aria-hidden/hidden/style aqui realimentavam syncExpanded
                // quando awa-search-autocomplete-compat.js e minicart-ui-v2 escreviam ARIA.
                attributeFilter: ['class'],
                childList: true,
                subtree: true
            });
        }

        syncSearchSubmitState();
        syncExpanded();
    }

    function wireHeaderClickTelemetry(experiment) {
        let root = document.querySelector('[data-awa-component="site-header"]');
        let stickyClass = 'awa-header-sticky';
        let stickyTrackedState = null;

        if (!root) {
            return;
        }

        addListener(document, 'click', function (event) {
            if (!event.target || !event.target.closest) {
                return;
            }

            let accountLink = event.target.closest('.top-account a, [data-awa-top-account="true"] a');
            if (accountLink) {
                pushHeaderTelemetry('awa_header_account_click', experiment, {
                    link_text: normalizeText(accountLink.textContent || accountLink.getAttribute('aria-label') || accountLink.getAttribute('title')),
                    link_href: accountLink.getAttribute('href') || ''
                });
                return;
            }

            let categoryLink = event.target.closest('.menu_left_home1 .navigation.verticalmenu a, .menu_left_home1 .title-category-dropdown');
            if (categoryLink) {
                pushHeaderTelemetry('awa_header_category_click', experiment, {
                    link_text: normalizeText(categoryLink.textContent || categoryLink.getAttribute('aria-label') || categoryLink.getAttribute('title')),
                    link_href: categoryLink.getAttribute('href') || ''
                });
            }
        }, { capture: true });

        function syncStickyTelemetry() {
            let isSticky = root.classList.contains(stickyClass) || document.body.classList.contains(stickyClass);

            if (stickyTrackedState === isSticky) {
                return;
            }

            stickyTrackedState = isSticky;
            pushHeaderTelemetry('awa_header_sticky_state', experiment, {
                sticky_active: isSticky ? 1 : 0
            });
        }

        syncStickyTelemetry();
        addListener(window, 'scroll', syncStickyTelemetry, { passive: true });
        addListener(window, 'resize', syncStickyTelemetry, { passive: true });

        if (window.MutationObserver) {
            new MutationObserver(syncStickyTelemetry).observe(root, {
                attributes: true,
                attributeFilter: ['class']
            });
        }
    }

    function wireDeferredBadges() {
        let badges = document.querySelector('[data-awa-deferred-badges="true"]');
        if (!badges) {
            return;
        }

        function reveal() {
            badges.setAttribute('aria-hidden', 'false');
            badges.classList.add('is-visible');
        }

        if (!('IntersectionObserver' in window)) {
            reveal();
            return;
        }

        let observer = new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    reveal();
                    obs.disconnect();
                }
            });
        }, { rootMargin: '80px 0px' });

        observer.observe(badges);
    }

    function setImportantStyle(element, property, value) {
        if (!element) {
            return;
        }

        /* Guarda de idempotência: evita escrever no atributo style quando o
         * valor já é o esperado. Sem isso, funções chamadas repetidamente por
         * um MutationObserver (ex.: normalizeDesktopHeaderVisualParity) geram
         * uma nova mutação a cada execução e realimentam o próprio observer,
         * criando um loop que nunca termina (visto no PageSpeed: TBT 11s+,
         * "Style & Layout" dominando o main-thread work). */
        if (
            element.style.getPropertyValue(property) === value
            && element.style.getPropertyPriority(property) === 'important'
        ) {
            return;
        }

        element.style.setProperty(property, value, 'important');
    }

    function clearStyleProperties(element, properties) {
        if (!element || !Array.isArray(properties)) {
            return;
        }

        properties.forEach(function (property) {
            if (!element.style.getPropertyValue(property)) {
                return;
            }
            element.style.removeProperty(property);
        });
    }

    function isHomeHeaderPage() {
        let body = document.body;

        return !!body && (
            body.classList.contains('cms-index-index')
            || body.classList.contains('cms-home')
            || body.classList.contains('cms-homepage_ayo_home5')
        );
    }

    function isMobileHeaderViewport() {
        return !!window.matchMedia && window.matchMedia('(max-width: 991px)').matches;
    }

    function isNavDrawerOpen() {
        return document.documentElement.classList.contains('nav-open')
            || document.body.classList.contains('nav-open')
            || document.body.classList.contains('nav-before-open');
    }

    let homeHeaderGuardState = null;
    let ENABLE_HOME_HEADER_COLLAPSE_GUARD = false;

    function collapseHomeSearchLayout() {
        let topSearch = document.querySelector('.header .top-search');
        let nestedCart = topSearch
            ? topSearch.querySelector(HEADER_TOP_SEARCH_NESTED_CART_SELECTOR)
            : null;
        let blockSearch = topSearch
            ? topSearch.querySelector(':scope > .block-search')
            : null;

        if (!topSearch || !isHomeHeaderPage() || !isMobileHeaderViewport()) {
            return;
        }

        setImportantStyle(topSearch, 'display', 'block');
        setImportantStyle(topSearch, 'grid-template-columns', 'minmax(0, 1fr)');
        setImportantStyle(topSearch, 'grid-template-areas', '"search"');
        setImportantStyle(topSearch, 'grid-template-rows', 'auto');
        setImportantStyle(topSearch, 'gap', '0');
        setImportantStyle(topSearch, 'min-height', '0');

        if (blockSearch) {
            setImportantStyle(blockSearch, 'grid-area', 'search');
            setImportantStyle(blockSearch, 'grid-column', '1');
            setImportantStyle(blockSearch, 'width', '100%');
            setImportantStyle(blockSearch, 'max-width', '100%');
            setImportantStyle(blockSearch, 'min-width', '0');
        }

        if (nestedCart) {
            setImportantStyle(nestedCart, 'display', 'none');
            setImportantStyle(nestedCart, 'width', '0');
            setImportantStyle(nestedCart, 'min-width', '0');
            setImportantStyle(nestedCart, 'max-width', '0');
            setImportantStyle(nestedCart, 'height', '0');
            setImportantStyle(nestedCart, 'min-height', '0');
            setImportantStyle(nestedCart, 'margin', '0');
            setImportantStyle(nestedCart, 'padding', '0');
            setImportantStyle(nestedCart, 'overflow', 'hidden');
            setImportantStyle(nestedCart, 'visibility', 'hidden');
            setImportantStyle(nestedCart, 'opacity', '0');
            setImportantStyle(nestedCart, 'pointer-events', 'none');
        }
    }

    function resetHomeHeaderCollapseGuard() {
        let topSearch = document.querySelector('.header .top-search');
        let nestedCart = topSearch
            ? topSearch.querySelector(HEADER_TOP_SEARCH_NESTED_CART_SELECTOR)
            : null;
        let blockSearch = topSearch
            ? topSearch.querySelector(':scope > .block-search')
            : null;
        let nav = document.querySelector('.header-control.header-nav-global.cms_home_1');
        let container = nav ? nav.querySelector(':scope > .container') : null;
        let row = container ? container.querySelector(':scope > .row') : null;
        let menu = nav ? nav.querySelector('.menu_left_home1') : null;
        let dropdown = menu ? menu.querySelector('.list-category-dropdown') : null;

        clearStyleProperties(topSearch, [
            'display',
            'grid-template-columns',
            'grid-template-areas',
            'grid-template-rows',
            'gap',
            'min-height'
        ]);

        clearStyleProperties(blockSearch, [
            'grid-area',
            'grid-column',
            'width',
            'max-width',
            'min-width'
        ]);

        clearStyleProperties(nestedCart, [
            'display',
            'width',
            'min-width',
            'max-width',
            'height',
            'min-height',
            'margin',
            'padding',
            'overflow',
            'visibility',
            'opacity',
            'pointer-events'
        ]);

        [nav, container, row].forEach(function (element) {
            clearStyleProperties(element, [
                'height',
                'min-height',
                'margin-top',
                'margin-bottom',
                'padding-top',
                'padding-bottom',
                'border',
                'overflow'
            ]);
        });

        clearStyleProperties(menu, [
            'display',
            'height',
            'min-height',
            'margin',
            'padding',
            'overflow',
            'visibility',
            'opacity',
            'pointer-events',
            'max-height'
        ]);

        clearStyleProperties(dropdown, [
            'max-height',
            'overflow'
        ]);

        homeHeaderGuardState = null;
    }

    function guardHomeMobileHeaderCollapse() {
        let nav;
        let container;
        let row;
        let menu;
        let dropdown;
        let nextState;

        if (!isHomeHeaderPage()) {
            homeHeaderGuardState = null;
            return;
        }

        if (!isMobileHeaderViewport()) {
            if (homeHeaderGuardState !== 'desktop') {
                resetHomeHeaderCollapseGuard();
                homeHeaderGuardState = 'desktop';
            }
            return;
        }

        nav = document.querySelector('.header-control.header-nav-global.cms_home_1');
        container = nav ? nav.querySelector(':scope > .container') : null;
        row = container ? container.querySelector(':scope > .row') : null;
        menu = nav ? nav.querySelector('.menu_left_home1') : null;
        dropdown = menu ? menu.querySelector('.list-category-dropdown') : null;

        if (!nav || !container || !row || !menu) {
            return;
        }

        nextState = isNavDrawerOpen() ? 'open' : 'closed';
        if (homeHeaderGuardState === nextState) {
            return;
        }

        if (nextState === 'closed') {
            if (homeHeaderGuardState === 'open') {
                [nav, container, row].forEach(function (element) {
                    clearStyleProperties(element, [
                        'height',
                        'min-height',
                        'margin-top',
                        'margin-bottom',
                        'padding-top',
                        'padding-bottom',
                        'border',
                        'overflow'
                    ]);
                });

                clearStyleProperties(menu, [
                    'display',
                    'height',
                    'min-height',
                    'overflow',
                    'visibility',
                    'opacity',
                    'pointer-events',
                    'margin',
                    'padding',
                    'max-height'
                ]);

                if (dropdown) {
                    clearStyleProperties(dropdown, [
                        'max-height',
                        'overflow'
                    ]);
                }
            }

            homeHeaderGuardState = 'closed';
            return;
        }

        homeHeaderGuardState = 'open';
        collapseHomeSearchLayout();

        [nav, container, row].forEach(function (element) {
            clearStyleProperties(element, [
                'height',
                'min-height',
                'margin-top',
                'margin-bottom',
                'padding-top',
                'padding-bottom',
                'border',
                'overflow'
            ]);
        });

        setImportantStyle(menu, 'display', 'block');
        setImportantStyle(menu, 'height', 'auto');
        setImportantStyle(menu, 'min-height', '0');
        setImportantStyle(menu, 'overflow', 'visible');
        setImportantStyle(menu, 'visibility', 'visible');
        setImportantStyle(menu, 'opacity', '1');
        setImportantStyle(menu, 'pointer-events', 'auto');
        clearStyleProperties(menu, [
            'margin',
            'padding',
            'max-height'
        ]);

        if (dropdown) {
            setImportantStyle(dropdown, 'max-height', 'none');
            setImportantStyle(dropdown, 'overflow', 'visible');
        }
    }

    function normalizeDesktopHeaderVisualParity() {
        let isDesktop = !!window.matchMedia && window.matchMedia('(min-width: 992px)').matches;
        let nav;
        let list;
        let trigger;
        let quickWrap;
        let quickList;
        let searchBtn;
        let searchSvg;

        if (!isDesktop) {
            return;
        }

        nav = document.querySelector('[data-role="awa-vertical-menu"]');
        list = nav ? nav.querySelector('.togge-menu.list-category-dropdown') : null;
        trigger = nav ? nav.querySelector('.title-category-dropdown.our_categories') : null;

        if (nav && list && !window.__AWA_MENU_V2) {
            let menuIsOpen = nav.classList.contains('menu-open')
                || nav.classList.contains('vmm-open')
                || list.classList.contains('menu-open')
                || list.classList.contains('vmm-open')
                || (trigger && trigger.getAttribute('aria-expanded') === 'true');

            if (menuIsOpen) {
                nav.classList.add('menu-open', 'vmm-open');
                list.classList.add('menu-open', 'vmm-open');

                setImportantStyle(list, 'display', 'grid');
                setImportantStyle(list, 'visibility', 'visible');
                setImportantStyle(list, 'opacity', '1');
                setImportantStyle(list, 'pointer-events', 'auto');
                clearStyleProperties(list, ['height', 'max-height']);

                if (trigger) {
                    trigger.classList.add('active');
                    trigger.setAttribute('aria-expanded', 'true');
                }
            } else {
                clearStyleProperties(list, [
                    'display',
                    'visibility',
                    'opacity',
                    'pointer-events',
                    'height',
                    'max-height'
                ]);

                if (trigger && trigger.getAttribute('aria-expanded') !== 'true') {
                    trigger.classList.remove('active');
                }
            }
        }

        /* Quick links: layout via CSS (LESS + cascade lock). Não injetar
         * style=!important — isso competia com o CSS, forçava overflow:hidden
         * e apertava o gap na PDP. Apenas limpa resíduos inline legados. */
        quickWrap = document.querySelector('.awa-site-header .header-control.awa-nav-bar .awa-nav-quick-links');
        quickList = quickWrap ? quickWrap.querySelector('.awa-nav-quick-links__list') : null;

        if (quickWrap) {
            clearStyleProperties(quickWrap, [
                'display',
                'align-items',
                'position',
                'left',
                'right',
                'inset-inline-start',
                'inset-inline-end',
                'width',
                'max-width',
                'min-width',
                'overflow',
                'grid-column',
                'justify-self',
                'margin',
                'justify-content'
            ]);
        }

        if (quickList) {
            clearStyleProperties(quickList, [
                'display',
                'align-items',
                'justify-content',
                'max-width',
                'min-width',
                'overflow',
                'padding-left',
                'padding-right',
                'gap'
            ]);
        }

        let accountIconLink = document.querySelector('.awa-header-account-prompt__icon');
        let accountIconSvg = accountIconLink ? accountIconLink.querySelector('svg') : null;
        if (accountIconLink) {
            setImportantStyle(accountIconLink, 'display', 'inline-flex');
            setImportantStyle(accountIconLink, 'align-items', 'center');
            setImportantStyle(accountIconLink, 'justify-content', 'center');
            setImportantStyle(accountIconLink, 'width', '44px');
            setImportantStyle(accountIconLink, 'min-width', '44px');
            setImportantStyle(accountIconLink, 'height', '44px');
            setImportantStyle(accountIconLink, 'min-height', '44px');
            setImportantStyle(accountIconLink, 'padding', '0');
            setImportantStyle(accountIconLink, 'overflow', 'hidden');
            setImportantStyle(accountIconLink, 'box-sizing', 'border-box');
            setImportantStyle(accountIconLink, 'border-radius', '6px');
        }
        if (accountIconSvg) {
            setImportantStyle(accountIconSvg, 'width', '24px');
            setImportantStyle(accountIconSvg, 'height', '24px');
        }

        searchBtn = document.querySelector('#search_mini_form .actions .action.search');
        searchSvg = searchBtn ? searchBtn.querySelector('svg') : null;

        if (searchBtn) {
            setImportantStyle(searchBtn, 'display', 'inline-flex');
            setImportantStyle(searchBtn, 'align-items', 'center');
            setImportantStyle(searchBtn, 'justify-content', 'center');
            setImportantStyle(searchBtn, 'width', '44px');
            setImportantStyle(searchBtn, 'min-width', '44px');
            setImportantStyle(searchBtn, 'height', '44px');
            setImportantStyle(searchBtn, 'min-height', '44px');
            setImportantStyle(searchBtn, 'background', 'transparent');
            setImportantStyle(searchBtn, 'background-color', 'transparent');
            setImportantStyle(searchBtn, 'color', 'var(--awa-primary)');
            setImportantStyle(searchBtn, 'border', '0');
            setImportantStyle(searchBtn, 'border-left', '0');
            setImportantStyle(searchBtn, 'border-radius', '0');
            setImportantStyle(searchBtn, 'box-shadow', 'none');
        }

        if (searchSvg) {
            setImportantStyle(searchSvg, 'stroke', 'var(--awa-primary)');
            setImportantStyle(searchSvg, 'color', 'var(--awa-primary)');
            setImportantStyle(searchSvg, 'fill', 'none');
        }

        if (window.innerWidth >= 992) {
            Array.prototype.forEach.call(
                document.querySelectorAll('.awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__line2 .awa-header-account-prompt__link, .awa-header-account-prompt[data-awa-auth-state="guest"] .awa-header-account-prompt__line2 .awa-header-account-prompt__link--register'),
                function (link) {
                    // 2026-07-30: altura auto — 30px forçava line2 alto e desalinhava
                    // "Para ver os preços" / "Entrar ou cadastre-se" vs ícone.
                    setImportantStyle(link, 'display', 'inline-flex');
                    setImportantStyle(link, 'align-items', 'center');
                    setImportantStyle(link, 'height', 'auto');
                    setImportantStyle(link, 'min-height', '0');
                    setImportantStyle(link, 'max-height', 'none');
                    setImportantStyle(link, 'font-size', '13px');
                    setImportantStyle(link, 'line-height', '1.2');
                    setImportantStyle(link, 'font-weight', '700');
                    setImportantStyle(link, 'background', 'transparent');
                    setImportantStyle(link, 'background-color', 'transparent');
                    setImportantStyle(link, 'color', 'var(--awa-primary)');
                    setImportantStyle(link, 'padding', '0 2px');
                    setImportantStyle(link, 'margin', '0');
                    setImportantStyle(link, 'border', '0');
                    setImportantStyle(link, 'box-shadow', 'none');

                }
            );
        }
    }

    let desktopHeaderParityQueued = false;
    let desktopHeaderParityObserver = null;
    let desktopHeaderParityObserverTarget = null;
    let desktopHeaderParityObserverOptions = null;

    function scheduleDesktopHeaderVisualParity() {
        if (desktopHeaderParityQueued) {
            return;
        }

        desktopHeaderParityQueued = true;
        raf(function () {
            desktopHeaderParityQueued = false;

            /* Desconecta o observer antes de escrever estilos e reconecta logo
             * depois: assim as próprias mutações causadas por este bloco nunca
             * chegam ao callback do observer (elimina o loop mutation -> write
             * -> mutation -> write que travava a thread principal). */
            if (desktopHeaderParityObserver) {
                desktopHeaderParityObserver.disconnect();
            }

            normalizeDesktopHeaderVisualParity();

            if (desktopHeaderParityObserver && desktopHeaderParityObserverTarget) {
                desktopHeaderParityObserver.observe(
                    desktopHeaderParityObserverTarget,
                    desktopHeaderParityObserverOptions
                );
            }
        });
    }

    onReady(function () {
        let experiment = getExperimentConfig();
        let isCartPage = document.body && document.body.classList.contains('checkout-cart-index');

        wireNavA11y(experiment);
        wireSearchA11y();
        wireHeaderClickTelemetry(experiment);
        wireDeferredBadges();

        /* Retries: mage/tabs.js (widget core, fora do nosso controle de timing)
         * pode aplicar role="tablist" antes OU depois deste script rodar,
         * dependendo da ordem de resolução do RequireJS. Reforça em alguns
         * instantes seguintes para cobrir ambos os casos sem MutationObserver
         * permanente (custo desnecessário para um atributo que so muda 1x). */
        fixNavTablistRole();
        window.setTimeout(fixNavTablistRole, 300);
        window.setTimeout(fixNavTablistRole, 1500);

        // Carrinho mobile: parity + observer em style/class no header entrava em loop
        // com cart-simplified-header (setProperty intercept) → aba sem resposta.
        if (!isCartPage) {
            scheduleDesktopHeaderVisualParity();

            addListener(window, 'resize', function () {
                scheduleDesktopHeaderVisualParity();
            }, { passive: true });

            window.setTimeout(scheduleDesktopHeaderVisualParity, 250);
            window.setTimeout(scheduleDesktopHeaderVisualParity, 1200);
            window.setTimeout(scheduleDesktopHeaderVisualParity, 2800);
        }

        if (ENABLE_HOME_HEADER_COLLAPSE_GUARD) {
            guardHomeMobileHeaderCollapse();

            addListener(window, 'resize', function () {
                raf(guardHomeMobileHeaderCollapse);
            }, { passive: true });

            /* Observer opcional do guard: mantido desativado por padrão para evitar
             * custo elevado na thread principal durante bootstrap mobile. */
            if (window.MutationObserver) {
                let _guardDebounce = null;
                new MutationObserver(function (mutations) {
                    let shouldRun = false;
                    let currentClassName = document.body.className || '';

                    mutations.forEach(function (mutation) {
                        let previousClassName;

                        if (shouldRun || mutation.attributeName !== 'class') {
                            return;
                        }

                        previousClassName = mutation.oldValue || '';
                        if (previousClassName === currentClassName) {
                            return;
                        }

                        if (
                            previousClassName.indexOf('nav-open') !== -1
                            || previousClassName.indexOf('nav-before-open') !== -1
                            || currentClassName.indexOf('nav-open') !== -1
                            || currentClassName.indexOf('nav-before-open') !== -1
                        ) {
                            shouldRun = true;
                        }
                    });

                    if (!shouldRun || _guardDebounce) {
                        return;
                    }

                    _guardDebounce = raf(function () {
                        _guardDebounce = null;
                        guardHomeMobileHeaderCollapse();
                    });
                }).observe(document.body, {
                    attributes: true,
                    attributeFilter: ['class'],
                    attributeOldValue: true
                });
            }
        }

        addListener(document, 'click', function (event) {
            if (!event.target || !event.target.closest) {
                return;
            }
            let showcart = event.target.closest(HEADER_MINICART_TRIGGER_SELECTOR + ', .minicart-wrapper .showcart, .minicart-wrapper .action.showcart');
            if (!showcart) {
                return;
            }
            pushHeaderTelemetry('awa_header_minicart_click', experiment);
        }, { capture: true });

        if (!isCartPage && window.MutationObserver) {
            let headerScope = document.querySelector(
                '.awa-site-header, #header.header-container, .page-header, header.page-header'
            );

            if (headerScope) {
                desktopHeaderParityObserverTarget = headerScope;
                desktopHeaderParityObserverOptions = {
                    attributes: true,
                    attributeFilter: ['class'],
                    childList: true,
                    subtree: true
                };
                desktopHeaderParityObserver = new MutationObserver(function () {
                    scheduleDesktopHeaderVisualParity();
                });
                desktopHeaderParityObserver.observe(headerScope, desktopHeaderParityObserverOptions);
            }
        }
    });
})();
