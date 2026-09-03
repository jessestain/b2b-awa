(function () {
    'use strict';

    var runtime = window.__awaHeaderMinicartRuntime = window.__awaHeaderMinicartRuntime || {};
    var runtimeOwner = 'defer';

    function isDeferOwnerActive() {
        return runtime.functionalOwner === runtimeOwner;
    }

    function shouldBypassOnHome() {
        var body = document.body;
        var path = window.location && typeof window.location.pathname === 'string'
            ? window.location.pathname
            : '';

        if (path === '' || path === '/') {
            return true;
        }

        if (document.getElementById('awa-home-bootstrap-merged')) {
            return true;
        }

        if (!body) {
            return false;
        }

        return body.classList.contains('cms-index-index')
            || body.classList.contains('cms-home')
            || body.classList.contains('cms-homepage_ayo_home5');
    }

    // Home uses awa-minicart-ui-bootstrap-home.js; this legacy defer guard can
    // create observer/RAF churn there and must stay disabled.
    if (shouldBypassOnHome()) {
        window.__awaMinicartDeferInit = true;
        window.__awaMinicartDeferSuperseded = true;
        return;
    }

    if (runtime.functionalOwner && runtime.functionalOwner !== runtimeOwner) {
        return;
    }

    runtime.functionalOwner = runtimeOwner;
    runtime.functionalOwnerSource = runtime.functionalOwnerSource || 'awa-minicart-defer-init';
    runtime.functionalOwnerClaimedAt = runtime.functionalOwnerClaimedAt || Date.now();

    if (window.__awaMinicartDeferInit) {
        return;
    }

    /**
     * Home com customer-data defer: awa-minicart-ui-bootstrap-home.js inicializa KO.
     * Mesmo assim precisamos do trigger guard para evitar navegação no 1º clique.
     */
    function isHomeDeferBootstrapActive() {
        var body = document.body;

        return !!(
            document.getElementById('awa-customer-sections-defer-json') &&
            body &&
            (body.classList.contains('cms-index-index') ||
                body.classList.contains('cms-home') ||
                body.classList.contains('cms-homepage_ayo_home5'))
        );
    }

    function isCheckoutCartPage() {
        var body = document.body;

        return !!(body && body.classList.contains('checkout-cart-index'));
    }

    /**
     * Cart page already renders the full cart + summary — keep header minicart closed.
     */
    function closeMinicartOnCartPage() {
        var parts;
        var wrappers;
        var panels;
        var index;

        if (!isCheckoutCartPage()) {
            return;
        }

        parts = getMinicartParts();
        if (parts.dropdown) {
            closeDropdown(parts.trigger, parts.dropdown);
            applyManualDropdownState(parts.trigger, parts.dropdown, false);
        }

        wrappers = document.querySelectorAll('.minicart-wrapper');
        for (index = 0; index < wrappers.length; index++) {
            wrappers[index].classList.remove('active', 'is-open', 'show');
        }

        panels = document.querySelectorAll('.block-minicart');
        for (index = 0; index < panels.length; index++) {
            panels[index].classList.remove('_active');
            panels[index].setAttribute('aria-hidden', 'true');
        }

        if (parts.trigger) {
            setAttributeIfChanged(parts.trigger, 'aria-expanded', 'false');
            parts.trigger.classList.remove('is-open', 'active');
        }

        syncShellState(parts.shell, false);
    }

    var guardsOnlyMode = false;

    if (window.__awaMinicartDeferInit) {
        return;
    }

    if (
        window.__awaMinicartUiInit ||
        window.__awaMinicartUiBootstrapping ||
        window.__awaMinicartDeferSuperseded
    ) {
        guardsOnlyMode = true;
    } else if (
        isHomeDeferBootstrapActive() &&
        (window.__awaMinicartUiInit || window.__awaMinicartUiBootstrapping)
    ) {
        guardsOnlyMode = true;
    } else if (isHomeDeferBootstrapActive()) {
        guardsOnlyMode = true;
    } else {
        window.__awaMinicartDeferInit = true;
    }

    let HEADER_MINICART_SHELL_SELECTOR = '[data-awa-header-minicart-shell="true"], .awa-header-minicart[data-awa-header-cart="true"], .awa-header-minicart';
    let HEADER_MINICART_FALLBACK_SELECTOR = '[data-awa-header-minicart-fallback="true"], .awa-header-cart-fallback';
    let HEADER_MINICART_CONTENT_SELECTOR = '[data-awa-header-minicart-content="true"], .mini-carts';
    let MINICART_TRIGGER_SELECTORS = [
        '.minicart-wrapper .showcart',
        '.minicart-wrapper .action.showcart',
        '.showcart.header-mini-cart'
    ];
    let MINICART_TRIGGER_SELECTOR = MINICART_TRIGGER_SELECTORS.join(', ');
    let MINICART_DROPDOWN_SELECTOR = '.minicart-wrapper [data-role="dropdownDialog"]';
    const MINICART_TRIGGER_GUARD_FLAG = '__awaMinicartTriggerHandled';
    const MINICART_UPDATE_MIN_INTERVAL_MS = 120;
    let minicartUpdateQueued = false;
    let minicartUpdateLastRunTs = 0;
    let minicartDebugScheduleCount = 0;
    let minicartDebugRunCount = 0;
    let minicartDebugSlowRuns = 0;

    function getHeaderMinicartShell() {
        return document.querySelector(HEADER_MINICART_SHELL_SELECTOR);
    }

    function getHeaderMinicartRoot() {
        let shell = getHeaderMinicartShell();

        if (!shell) {
            return document;
        }

        return shell.querySelector(HEADER_MINICART_CONTENT_SELECTOR) || shell;
    }

    function queryRuntimeTrigger(root) {
        return (root || document).querySelector(MINICART_TRIGGER_SELECTOR);
    }

    function queryDropdown(root) {
        return (root || document).querySelector(MINICART_DROPDOWN_SELECTOR);
    }

    function isRealRequireReady() {
        return typeof window.require === 'function' && !window.require._awaStub;
    }

    function whenRequireReady(fn, key) {
        if (typeof fn !== 'function') {
            return;
        }

        if (isRealRequireReady()) {
            fn();
            return;
        }

        if (typeof window.awaRunWhenRequire === 'function') {
            window.awaRunWhenRequire(fn, { key: key || 'awa-minicart' });
            return;
        }

        window.addEventListener('awa-bootstrap-ready', fn, { once: true });
    }

    function getMinicartParts() {
        let shell = getHeaderMinicartShell();
        let root = getHeaderMinicartRoot();

        return {
            shell: shell,
            root: root,
            trigger: queryRuntimeTrigger(root) || queryRuntimeTrigger(shell) || queryRuntimeTrigger(document),
            dropdown: queryDropdown(root) || queryDropdown(shell) || queryDropdown(document)
        };
    }

    function getMinicartPartsForShell(shell) {
        if (!shell) {
            return getMinicartParts();
        }

        let root = shell.querySelector(HEADER_MINICART_CONTENT_SELECTOR) || shell;

        return {
            shell: shell,
            root: root,
            trigger: queryRuntimeTrigger(root) || queryRuntimeTrigger(shell),
            dropdown: queryDropdown(root) || queryDropdown(shell)
        };
    }

    function isVisible(element) {
        return !!(element && (element.offsetWidth || element.offsetHeight || element.getClientRects().length));
    }

    function getFallbackForShell(shell) {
        return shell ? shell.querySelector(HEADER_MINICART_FALLBACK_SELECTOR) : null;
    }

    function setAttributeIfChanged(element, name, value) {
        if (element && element.getAttribute(name) !== value) {
            element.setAttribute(name, value);
        }
    }

    function toggleClassIfChanged(element, className, enabled) {
        if (element && element.classList.contains(className) !== enabled) {
            element.classList.toggle(className, enabled);
        }
    }

    // Only write the inline style (with !important) when the value actually
    // changes — otherwise every write mutates the `style` attribute and can
    // re-trigger a MutationObserver watching `style` (see observeMinicartState).
    function setStyleIfChanged(element, prop, value) {
        if (element && element.style.getPropertyValue(prop) !== value) {
            element.style.setProperty(prop, value, 'important');
        }
    }

    function queueUpdateExpandedState(delay) {
        if (!isDeferOwnerActive()) {
            return;
        }

        minicartDebugScheduleCount += 1;
        if (minicartDebugScheduleCount === 1 || minicartDebugScheduleCount % 200 === 0) {
            // #region agent log
            fetch('/awa-debug-50aadf.php',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'50aadf'},body:JSON.stringify({sessionId:'50aadf',runId:'post-fix-minicart-throttle',hypothesisId:'H9',location:'awa-minicart-defer-init.js:queueUpdateExpandedState',message:'queue schedule sample',data:{scheduleCount:minicartDebugScheduleCount,delay:delay||0,queued:minicartUpdateQueued},timestamp:Date.now()})}).catch(()=>{});
            // #endregion
        }

        if (typeof delay === 'number' && delay > 0) {
            window.setTimeout(function () {
                queueUpdateExpandedState();
            }, delay);
            return;
        }

        if (minicartUpdateQueued) {
            return;
        }

        let now = Date.now();
        let wait = MINICART_UPDATE_MIN_INTERVAL_MS - (now - minicartUpdateLastRunTs);
        minicartUpdateQueued = true;

        if (wait > 0) {
            window.setTimeout(function () {
                window.requestAnimationFrame(function () {
                    minicartUpdateQueued = false;
                    minicartUpdateLastRunTs = Date.now();
                    minicartDebugRunCount += 1;
                    if (minicartDebugRunCount === 1 || minicartDebugRunCount % 100 === 0) {
                        // #region agent log
                        fetch('/awa-debug-50aadf.php',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'50aadf'},body:JSON.stringify({sessionId:'50aadf',runId:'post-fix-minicart-throttle',hypothesisId:'H9',location:'awa-minicart-defer-init.js:queueUpdateExpandedState:raf-waited',message:'queue run sample',data:{runCount:minicartDebugRunCount,wait:wait},timestamp:Date.now()})}).catch(()=>{});
                        // #endregion
                    }
                    updateExpandedState();
                });
            }, wait);
            return;
        }

        window.requestAnimationFrame(function () {
            minicartUpdateQueued = false;
            minicartUpdateLastRunTs = Date.now();
            minicartDebugRunCount += 1;
            if (minicartDebugRunCount === 1 || minicartDebugRunCount % 100 === 0) {
                // #region agent log
                fetch('/awa-debug-50aadf.php',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'50aadf'},body:JSON.stringify({sessionId:'50aadf',runId:'post-fix-minicart-throttle',hypothesisId:'H9',location:'awa-minicart-defer-init.js:queueUpdateExpandedState:raf-now',message:'queue run sample',data:{runCount:minicartDebugRunCount,wait:0},timestamp:Date.now()})}).catch(()=>{});
                // #endregion
            }
            updateExpandedState();
        });
    }

    /**
     * Center via shared module. Skips checkout-cart-index (awa-cart-stack disables flyout).
     *
     * @param {HTMLElement|null} panel
     */
    function scheduleCenteredMinicartPanel(panel) {
        if (document.body && document.body.classList.contains('checkout-cart-index')) {
            return;
        }

        require(['js/awa-minicart-position'], function (minicartPosition) {
            if (minicartPosition && typeof minicartPosition.scheduleCenterOpenMinicartPanel === 'function') {
                minicartPosition.scheduleCenterOpenMinicartPanel(panel);
            }
        });
    }

    function isDropdownExpanded(dropdown) {
        let wrapper;
        let style;
        let isActuallyVisible;

        if (!dropdown) {
            return false;
        }

        wrapper = dropdown.closest('[data-block="minicart"], .minicart-wrapper');
        style = window.getComputedStyle ? window.getComputedStyle(dropdown) : null;
        isActuallyVisible = !!(
            style &&
            style.display !== 'none' &&
            style.visibility !== 'hidden' &&
            style.opacity !== '0' &&
            isVisible(dropdown)
        );

        return !!(
            isActuallyVisible ||
            (dropdown.getAttribute('aria-hidden') === 'false' && isActuallyVisible) ||
            ((dropdown.classList.contains('active') || dropdown.classList.contains('is-open')) && isActuallyVisible) ||
            (wrapper && (
                wrapper.classList.contains('active') ||
                wrapper.classList.contains('is-open') ||
                wrapper.classList.contains('show')
            ) && isActuallyVisible)
        );
    }

    function syncFallbackAccessibility(shell, hasRuntimeTrigger) {
        let fallback = getFallbackForShell(shell);

        if (!shell || !fallback) {
            return;
        }

        setAttributeIfChanged(shell, 'data-awa-minicart-ready', hasRuntimeTrigger ? '1' : '0');
        toggleClassIfChanged(shell, 'awa-header-minicart--ready', hasRuntimeTrigger);
        toggleClassIfChanged(fallback, 'awa-header-cart-fallback--with-trigger', hasRuntimeTrigger);

        if (hasRuntimeTrigger) {
            setAttributeIfChanged(fallback, 'aria-hidden', 'true');
            setAttributeIfChanged(fallback, 'tabindex', '-1');
            setAttributeIfChanged(fallback, 'data-awa-minicart-fallback-state', 'conflict');
            return;
        }

        fallback.removeAttribute('aria-hidden');
        fallback.removeAttribute('tabindex');
        fallback.removeAttribute('data-awa-minicart-fallback-state');
    }

    function syncShellState(shell, expanded) {
        if (!shell) {
            return;
        }

        setAttributeIfChanged(shell, 'data-awa-minicart-expanded', expanded ? '1' : '0');
        toggleClassIfChanged(shell, 'awa-header-minicart--expanded', expanded);
    }

    function resolveMinicartPanel(dropdown, wrapper) {
        let panel;

        if (!dropdown) {
            return null;
        }

        if (dropdown.classList && dropdown.classList.contains('block-minicart')) {
            return dropdown;
        }

        panel = wrapper ? wrapper.querySelector('.block-minicart') : null;

        return panel || dropdown;
    }

    function syncBodyScrollLock(expanded) {
        let body = document.body;
        let html = document.documentElement;

        if (!body) {
            return;
        }

        body.classList.toggle('awa-minicart-open', expanded);
        body.classList.toggle('awa-minicart-overlay-active', expanded);

        if (html) {
            html.classList.toggle('awa-minicart-open', expanded);
        }
    }

    function syncFloatingCtas(hidden) {
        document.querySelectorAll('#awa-back-to-top, .awa-whatsapp-float, [class*="whatsapp-float"]').forEach(function (node) {
            if (!node || !node.style) {
                return;
            }

            if (hidden) {
                node.style.setProperty('opacity', '0', 'important');
                node.style.setProperty('visibility', 'hidden', 'important');
                node.style.setProperty('pointer-events', 'none', 'important');
                node.style.setProperty('transform', 'translateY(8px)', 'important');
                return;
            }

            node.style.removeProperty('opacity');
            node.style.removeProperty('visibility');
            node.style.removeProperty('pointer-events');
            node.style.removeProperty('transform');
        });
    }

    function applyManualDropdownState(trigger, dropdown, expanded) {
        let wrapper = dropdown ? dropdown.closest('[data-block="minicart"], .minicart-wrapper') : null;
        let shell = getHeaderMinicartShell();
        let panel = resolveMinicartPanel(dropdown, wrapper);
        let targets = [];
        let index;

        if (!dropdown) {
            return;
        }

        if (wrapper) {
            wrapper.setAttribute('data-awa-minicart-dropdown', '1');
            wrapper.classList.toggle('active', expanded);
            wrapper.classList.toggle('is-open', expanded);
            wrapper.classList.toggle('show', expanded);
        }

        if (panel && targets.indexOf(panel) === -1) {
            targets.push(panel);
        }

        if (dropdown && targets.indexOf(dropdown) === -1) {
            targets.push(dropdown);
        }

        // Cart page: awa-cart-stack disables the flyout — do not force display:flex.
        if (document.body && document.body.classList.contains('checkout-cart-index')) {
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
            syncShellState(shell, false);
            syncBodyScrollLock(false);
            return;
        }

        for (index = 0; index < targets.length; index += 1) {
            toggleClassIfChanged(targets[index], 'active', expanded);
            toggleClassIfChanged(targets[index], 'is-open', expanded);
            toggleClassIfChanged(targets[index], '_active', expanded);
            setAttributeIfChanged(targets[index], 'aria-hidden', expanded ? 'false' : 'true');
            setStyleIfChanged(targets[index], 'display', expanded ? 'flex' : 'none');
            setStyleIfChanged(targets[index], 'visibility', expanded ? 'visible' : 'hidden');
            setStyleIfChanged(targets[index], 'opacity', expanded ? '1' : '0');
            setStyleIfChanged(targets[index], 'pointer-events', expanded ? 'auto' : 'none');
        }

        if (trigger) {
            trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            // Magento dropdownDialog usa triggerClass:"is-open" — manter em sync com aria-expanded
            trigger.classList.toggle('is-open', expanded);
            if (!expanded) {
                trigger.classList.remove('active');
            }
        }

        if (expanded && panel) {
            scheduleCenteredMinicartPanel(panel);
        }

        syncShellState(shell, expanded);
        syncBodyScrollLock(expanded);
        syncFloatingCtas(expanded);
    }

    function closeDropdown(trigger, dropdown) {
        var $dropdown;

        if (!dropdown) {
            return;
        }

        // Apply the lock transition immediately to avoid brief CTA overlap while dialog closes.
        syncBodyScrollLock(false);

        if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.dropdownDialog === 'function') {
                $dropdown = window.jQuery(dropdown);
                if ($dropdown.data('mageDropdownDialog')) {
                    $dropdown.dropdownDialog('close');
                    applyManualDropdownState(trigger, dropdown, false);
                    queueUpdateExpandedState();
                    queueUpdateExpandedState(240);
                    return;
            }
        }

        applyManualDropdownState(trigger, dropdown, false);
        queueUpdateExpandedState();
    }

    function openDropdown(trigger, dropdown) {
        var $dropdown;

        if (!dropdown) {
            return;
        }

        // Apply the lock transition immediately to avoid brief CTA overlap while dialog opens.
        syncBodyScrollLock(true);
        syncFloatingCtas(true);

        function openNow() {
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.dropdownDialog === 'function') {
                $dropdown = window.jQuery(dropdown);
                if ($dropdown.length && $dropdown.data('mageDropdownDialog')) {
                    $dropdown.dropdownDialog('open');
                    applyManualDropdownState(trigger, dropdown, true);
                    queueUpdateExpandedState();
                    queueUpdateExpandedState(240);
                    return;
                }
            }

            applyManualDropdownState(trigger, dropdown, true);
            queueUpdateExpandedState();
        }

        if (!isHomeDeferBootstrapActive() && !window.__awaMinicartUiInit && !window.ko) {
            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.dropdownDialog === 'function') {
                $dropdown = window.jQuery(dropdown);
                if ($dropdown.length && $dropdown.data('mageDropdownDialog')) {
                    openNow();
                    ensureCatalogMinicartUi(function () {
                        initDropdown();
                        queueUpdateExpandedState();
                    });
                    return;
                }
            }

            ensureCatalogMinicartUi(openNow);
            return;
        }

        openNow();
    }

    function buildScopedTriggerSelector() {
        return HEADER_MINICART_SHELL_SELECTOR.split(',').map(function (scopeSelector) {
            let scope = scopeSelector.trim();

            return MINICART_TRIGGER_SELECTORS.map(function (triggerSelector) {
                return scope + ' ' + triggerSelector;
            }).join(', ');
        }).join(', ');
    }

    function ensureCatalogMinicartUi(onReady) {
        var scripts;
        var i;
        var parsed;
        var jsLayout;
        var loaderUrl;

        if (isHomeDeferBootstrapActive() || window.__awaMinicartUiInit || window.ko) {
            if (typeof onReady === 'function') {
                onReady();
            }
            return;
        }

        if (window.__awaMinicartCatalogUiBootstrapping) {
            if (typeof onReady === 'function') {
                window.__awaMinicartUiReady = window.__awaMinicartUiReady || Promise.resolve();
                window.__awaMinicartUiReady.then(onReady);
            }
            return;
        }

        if (typeof window.require !== 'function') {
            if (typeof onReady === 'function') {
                onReady();
            }
            return;
        }

        scripts = document.querySelectorAll('script[type="text/x-magento-init"]');
        jsLayout = null;

        for (i = 0; i < scripts.length; i++) {
            try {
                parsed = JSON.parse(scripts[i].textContent || '');
            } catch (e) {
                continue;
            }

            if (parsed["[data-block='minicart']"] && parsed["[data-block='minicart']"]["Magento_Ui/js/core/app"]) {
                jsLayout = parsed["[data-block='minicart']"]["Magento_Ui/js/core/app"];
                break;
            }
        }

        if (!jsLayout) {
            if (typeof onReady === 'function') {
                onReady();
            }
            return;
        }

        loaderUrl = (window.require && window.require.toUrl)
            ? window.require.toUrl('images/loader-1.gif')
            : '';

        window.__awaMinicartCatalogUiBootstrapping = true;

        window.require(['js/awa-minicart-ui-bootstrap'], function (bootstrapMinicartUi) {
            bootstrapMinicartUi({ jsLayout: jsLayout, loaderUrl: loaderUrl }, {}).then(function () {
                window.__awaMinicartCatalogUiBootstrapping = false;
                initDropdown();
                queueUpdateExpandedState();
                if (typeof onReady === 'function') {
                    onReady();
                }
            });
        }, function () {
            window.__awaMinicartCatalogUiBootstrapping = false;
            if (typeof onReady === 'function') {
                onReady();
            }
        });
    }

    function initDropdown(onReady) {
        let parts = getMinicartParts();

        function runDropdownInit() {
            if (!isRealRequireReady()) {
                if (typeof onReady === 'function') {
                    onReady(null, parts.trigger || null, parts.dropdown || null);
                }
                return;
            }

            window.require(['jquery', 'dropdownDialog'], function ($) {
            let latestParts = getMinicartParts();
            let trigger = latestParts.trigger;
            let dropdown = latestParts.dropdown;
            var $dropdown = dropdown ? $(dropdown) : null;

            if ($dropdown && $dropdown.length) {
                var existingWidget = $dropdown.data('mageDropdownDialog');
                var existingTimeout = existingWidget && existingWidget.options
                    ? parseInt(String(existingWidget.options.timeout || 0), 10)
                    : 0;

                if (existingWidget && existingTimeout > 0) {
                    $dropdown.dropdownDialog('destroy');
                    existingWidget = null;
                }

                if (!existingWidget) {
                    $dropdown.dropdownDialog({
                        appendTo: '[data-block=minicart]',
                        triggerTarget: buildScopedTriggerSelector(),
                        timeout: 0,
                        closeOnMouseLeave: false,
                        closeOnEscape: true,
                        triggerClass: 'is-open',
                        parentClass: 'is-open',
                        buttons: []
                    });
                }
            }

            if (!window.__awaMinicartDropdownSyncBound) {
                window.__awaMinicartDropdownSyncBound = true;

                $(document).on('click keyup', MINICART_TRIGGER_SELECTOR + ', .block-minicart', function () {
                    queueUpdateExpandedState();
                    queueUpdateExpandedState(240);
                });
            }

            queueUpdateExpandedState();

            if (typeof onReady === 'function') {
                onReady($, trigger || null, dropdown || null);
            }
        }, function () {
            if (typeof onReady === 'function') {
                onReady(null, parts.trigger || null, parts.dropdown || null);
            }
            queueUpdateExpandedState();
        });
        }

        whenRequireReady(runDropdownInit, 'awa-minicart-initDropdown');
    }

    function toggleDropdown(trigger, dropdown, fallbackUrl) {
        var $dropdown;

        if (window.jQuery && dropdown) {
            $dropdown = window.jQuery(dropdown);
            if ($dropdown.length && $dropdown.data('mageDropdownDialog')) {
                if (isDropdownExpanded(dropdown)) {
                    $dropdown.dropdownDialog('close');
                } else {
                    openDropdown(trigger, dropdown);
                }

                queueUpdateExpandedState();
                queueUpdateExpandedState(240);
                return;
            }
        }

        initDropdown(function ($, latestTrigger, latestDropdown) {
            let resolvedTrigger = latestTrigger || trigger;
            let resolvedDropdown = latestDropdown || dropdown;
            var $dropdown = resolvedDropdown && $ ? $(resolvedDropdown) : null;

            if ($dropdown && $dropdown.length && $dropdown.data('mageDropdownDialog')) {
                if (isDropdownExpanded(resolvedDropdown)) {
                    $dropdown.dropdownDialog('close');
                } else {
                    openDropdown(resolvedTrigger, resolvedDropdown);
                }

                queueUpdateExpandedState();
                queueUpdateExpandedState(240);
                return;
            }

            if (resolvedDropdown) {
                if (isDropdownExpanded(resolvedDropdown)) {
                    applyManualDropdownState(resolvedTrigger, resolvedDropdown, false);
                } else {
                    if (!isHomeDeferBootstrapActive() && !window.__awaMinicartUiInit && !window.ko) {
                        if ($dropdown && $dropdown.length && $dropdown.data('mageDropdownDialog')) {
                            applyManualDropdownState(resolvedTrigger, resolvedDropdown, true);
                            queueUpdateExpandedState();
                            ensureCatalogMinicartUi(function () {
                                initDropdown();
                                queueUpdateExpandedState();
                            });
                            return;
                        }

                        ensureCatalogMinicartUi(function () {
                            applyManualDropdownState(resolvedTrigger, resolvedDropdown, true);
                            queueUpdateExpandedState();
                        });
                        return;
                    }
                    applyManualDropdownState(resolvedTrigger, resolvedDropdown, true);
                }

                queueUpdateExpandedState();
                return;
            }

            if (fallbackUrl) {
                window.location.assign(fallbackUrl);
            }
        });
    }

    function ensureHomeMinicartBootstrap() {
        var node;
        var payload;

        if (!isHomeDeferBootstrapActive() || window.__awaMinicartUiInit || window.__awaMinicartUiBootstrapping) {
            return;
        }

        if (typeof window.require !== 'function') {
            return;
        }

        node = document.getElementById('awa-minicart-ui-json');
        if (!node || !node.textContent) {
            return;
        }

        try {
            payload = JSON.parse(node.textContent);
        } catch (e) {
            return;
        }

        window.require(['js/awa-minicart-ui-bootstrap'], function (bootstrapMinicartUi) {
            function start() {
                bootstrapMinicartUi(payload, { skipCustomerGate: true });
            }

            if (window.__awaCustomerSectionsReady && typeof window.__awaCustomerSectionsReady.then === 'function') {
                window.__awaCustomerSectionsReady.then(start);
                return;
            }

            start();
        });
    }

    function bindCheckoutFallback() {
        if (window.__awaMinicartCheckoutFallbackBound) {
            return;
        }

        window.__awaMinicartCheckoutFallbackBound = true;

            document.addEventListener('click', function (event) {
                if (!isDeferOwnerActive()) {
                    return;
                }

                let button = event.target && event.target.closest
                    ? event.target.closest('#top-cart-btn-checkout')
                    : null;
            let minicart = window.jQuery
                ? window.jQuery('[data-block="minicart"]')
                : null;
            let dropdown = window.jQuery
                ? window.jQuery('[data-role="dropdownDialog"]')
                : null;
            let checkoutUrl = window.checkout && typeof window.checkout.checkoutUrl === 'string'
                ? window.checkout.checkoutUrl
                : '';

            if (!button || !checkoutUrl || (minicart && minicart.data('mageSidebar'))) {
                return;
            }

            event.preventDefault();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
            event.stopPropagation();

            if (dropdown && dropdown.length && dropdown.data('mageDropdownDialog')) {
                dropdown.dropdownDialog('close');
            }

            window.location.assign(checkoutUrl);
        }, true);
    }

    function bindTriggerGuard() {
        function stopMinicartEvent(event) {
            if (!event) {
                return;
            }

            if (typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            event.stopPropagation();
        }

        function handleTriggerGuardClick(event) {
            if (!isDeferOwnerActive()) {
                return;
            }

            if (event && event[MINICART_TRIGGER_GUARD_FLAG]) {
                return;
            }

            let target = event.target;
            let trigger;
            let shell;
            let parts;
            let href;
            let fallback;
            let $dropdown;
            let clickedFallback = null;

            if (!target || !target.closest) {
                return;
            }

            trigger = target.closest(MINICART_TRIGGER_SELECTOR);
            if (!trigger) {
                if (target.closest('.block-minicart')) {
                    return;
                }

                shell = target.closest(HEADER_MINICART_SHELL_SELECTOR);
                if (!shell) {
                    return;
                }

                trigger = queryRuntimeTrigger(shell) || queryRuntimeTrigger(document);
                if (!trigger) {
                    return;
                }
            } else {
                shell = trigger.closest(HEADER_MINICART_SHELL_SELECTOR) || getHeaderMinicartShell();
            }

            parts = getMinicartPartsForShell(shell);
            if (!trigger.closest('.awa-header-minicart, [data-awa-header-minicart-shell="true"]')) {
                return;
            }

            if (isCheckoutCartPage()) {
                event[MINICART_TRIGGER_GUARD_FLAG] = true;
                stopMinicartEvent(event);
                closeMinicartOnCartPage();
                return;
            }

            fallback = parts.shell ? parts.shell.querySelector(HEADER_MINICART_FALLBACK_SELECTOR) : null;
            clickedFallback = target.closest(HEADER_MINICART_FALLBACK_SELECTOR);

            if (clickedFallback) {
                event[MINICART_TRIGGER_GUARD_FLAG] = true;

                if (parts.trigger && parts.trigger.isConnected) {
                    stopMinicartEvent(event);
                    syncFallbackAccessibility(parts.shell, true);
                    href = parts.trigger.getAttribute('href') || '';
                    toggleDropdown(parts.trigger, parts.dropdown, href);
                    return;
                }

                if (fallback && fallback.href) {
                    stopMinicartEvent(event);
                    window.location.assign(fallback.href);
                }

                return;
            }

            event[MINICART_TRIGGER_GUARD_FLAG] = true;

            if (window.__awaMinicartUiInit && parts.dropdown && window.jQuery) {
                $dropdown = window.jQuery(parts.dropdown);
                if ($dropdown.length && $dropdown.data('mageDropdownDialog')) {
                    href = trigger.getAttribute('href') || '';
                    stopMinicartEvent(event);
                    toggleDropdown(parts.trigger || trigger, parts.dropdown, href);
                    return;
                }
            }

            if (!parts.dropdown) {
                stopMinicartEvent(event);
                initDropdown();
                return;
            }

            href = trigger.getAttribute('href') || '';

            stopMinicartEvent(event);

            if (isHomeDeferBootstrapActive()) {
                ensureHomeMinicartBootstrap();

                if (parts.dropdown) {
                    if (isDropdownExpanded(parts.dropdown)) {
                        closeDropdown(parts.trigger || trigger, parts.dropdown);
                    } else {
                        applyManualDropdownState(parts.trigger || trigger, parts.dropdown, true);
                        queueUpdateExpandedState();
                        queueUpdateExpandedState(240);
                    }

                    return;
                }
            }

            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.dropdownDialog === 'function') {
                let $dropdown = window.jQuery(parts.dropdown);
                if ($dropdown.length && $dropdown.data('mageDropdownDialog')) {
                    toggleDropdown(parts.trigger || trigger, parts.dropdown, href);
                    return;
                }
            }

            toggleDropdown(parts.trigger || trigger, parts.dropdown, href);
        }

        if (window.__awaMinicartTriggerGuardBound) {
            return;
        }

        window.__awaMinicartTriggerGuardBound = true;

        document.addEventListener('click', handleTriggerGuardClick, true);
    }

    function bindShellFallbackNavigation() {
        if (window.__awaMinicartShellFallbackBound) {
            return;
        }

        window.__awaMinicartShellFallbackBound = true;

        document.addEventListener('click', function (event) {
            if (!isDeferOwnerActive()) {
                return;
            }

            let target = event.target;
            let shell;
            let fallback;

            if (!target || !target.closest) {
                return;
            }

            shell = target.closest(HEADER_MINICART_SHELL_SELECTOR);
            if (!shell || target.closest('a[href], button, [role="button"]')) {
                return;
            }

            if (queryRuntimeTrigger(shell)) {
                return;
            }

            fallback = shell.querySelector(HEADER_MINICART_FALLBACK_SELECTOR);
            if (!fallback || !fallback.href) {
                return;
            }

            event.preventDefault();
            window.location.assign(fallback.href);
        }, true);
    }

    function bindEscapeClose() {
        if (window.__awaMinicartEscapeCloseBound) {
            return;
        }

        window.__awaMinicartEscapeCloseBound = true;

        document.addEventListener('keydown', function (event) {
            if (!isDeferOwnerActive()) {
                return;
            }

            let parts;

            if (event.key !== 'Escape') {
                return;
            }

            parts = getMinicartParts();
            if (!parts.dropdown || !isDropdownExpanded(parts.dropdown)) {
                return;
            }

            closeDropdown(parts.trigger, parts.dropdown);
        }, true);
    }

    function updateExpandedState() {
        if (!isDeferOwnerActive()) {
            return;
        }
        if (window.__awaMinicartStateSyncing) {
            return;
        }
        window.__awaMinicartStateSyncing = true;
        try {
        let updateStartedAt = Date.now();

        let parts = getMinicartParts();
        let wrapper = parts.dropdown ? parts.dropdown.closest('[data-block="minicart"], .minicart-wrapper') : null;
        let panel = resolveMinicartPanel(parts.dropdown, wrapper);
        let panelStyle = panel && window.getComputedStyle ? window.getComputedStyle(panel) : null;
        let panelVisible = !!(
            panel &&
            panelStyle &&
            panelStyle.display !== 'none' &&
            panelStyle.visibility !== 'hidden' &&
            panelStyle.opacity !== '0' &&
            isVisible(panel)
        );
        /*
         * Do not gate readiness by computed visibility:
         * several fallback styles hide .showcart while data-awa-minicart-ready="0",
         * which can create a circular lock (trigger exists but never becomes "ready").
         */
        let hasRuntimeTrigger = !!parts.trigger;
        let expanded = hasRuntimeTrigger && isDropdownExpanded(parts.dropdown);

        syncFallbackAccessibility(parts.shell, hasRuntimeTrigger);

        if (parts.trigger) {
            setAttributeIfChanged(parts.trigger, 'aria-expanded', expanded ? 'true' : 'false');
            parts.trigger.classList.toggle('is-open', expanded);
            if (!expanded) {
                parts.trigger.classList.remove('active');
            }
        }

        syncShellState(parts.shell, expanded);
        syncBodyScrollLock(expanded);
        syncFloatingCtas(expanded || panelVisible);

        let updateDuration = Date.now() - updateStartedAt;
        if (updateDuration >= 40 && minicartDebugSlowRuns < 6) {
            minicartDebugSlowRuns += 1;
            // #region agent log
            fetch('/awa-debug-50aadf.php',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'50aadf'},body:JSON.stringify({sessionId:'50aadf',runId:'post-fix-minicart-throttle',hypothesisId:'H11',location:'awa-minicart-defer-init.js:updateExpandedState',message:'slow updateExpandedState',data:{durationMs:updateDuration,expanded:expanded,panelVisible:panelVisible,hasRuntimeTrigger:hasRuntimeTrigger},timestamp:Date.now()})}).catch(()=>{});
            // #endregion
        }
        } finally {
            window.__awaMinicartStateSyncing = false;
        }

    }

    function scheduleBootstrapPasses() {
        if (window.__awaMinicartBootstrapScheduled) {
            return;
        }

        window.__awaMinicartBootstrapScheduled = true;

        [0, 250, 900, 1800, 3200].forEach(function (delay) {
            window.setTimeout(function () {
                if (!isDeferOwnerActive()) {
                    return;
                }
                queueUpdateExpandedState();
                if (!isHomeDeferBootstrapActive() || window.__awaMinicartUiInit) {
                    initDropdown();
                }
            }, delay);
        });
    }

    function bindInteractionSync() {
        if (window.__awaMinicartInteractionSyncBound) {
            return;
        }

        window.__awaMinicartInteractionSyncBound = true;

        document.addEventListener('click', function (event) {
            if (!isDeferOwnerActive()) {
                return;
            }

            let target = event.target;
            let parts = getMinicartParts();
            let interactiveSelector = MINICART_TRIGGER_SELECTOR + ', .block-minicart, ' + HEADER_MINICART_SHELL_SELECTOR;

            if (!target || !target.closest) {
                return;
            }

            if (!target.closest(interactiveSelector)) {
                if (parts.dropdown && isDropdownExpanded(parts.dropdown)) {
                    closeDropdown(parts.trigger, parts.dropdown);
                }
                return;
            }

            queueUpdateExpandedState();
            queueUpdateExpandedState(240);
            queueUpdateExpandedState(520);
        }, true);

        document.addEventListener('contentUpdated', function () {
            if (!isDeferOwnerActive()) {
                return;
            }
            queueUpdateExpandedState();
        }, true);

        if (isHomeDeferBootstrapActive() && window.__awaMinicartUiReady && typeof window.__awaMinicartUiReady.then === 'function') {
            window.__awaMinicartUiReady.then(function () {
                queueUpdateExpandedState();
                initDropdown();
            });
        }
    }

    function observeMinicartState() {
        if (window.__awaMinicartStateObserverBound || !window.MutationObserver) {
            return;
        }

        let parts = getMinicartParts();
        /* 51.52: never fall back to document.body — updateExpandedState toggles
         * body.awa-minicart-open and would re-enter this observer forever. */
        let target = parts.shell || document.querySelector('[data-block="minicart"]') ||
            document.querySelector('.minicart-wrapper');

        if (!target) {
            return;
        }

        window.__awaMinicartStateObserverBound = true;

        new MutationObserver(function () {
            if (!isDeferOwnerActive()) {
                return;
            }
            if (window.__awaMinicartStateSyncing) {
                return;
            }
            queueUpdateExpandedState();
        }).observe(target, {
            attributes: true,
            childList: true,
            subtree: true,
            attributeFilter: ['class', 'aria-hidden']
        });
    }

    function bindContinueClose() {
        if (window.__awaMinicartContinueCloseBound) {
            return;
        }

        window.__awaMinicartContinueCloseBound = true;

        document.addEventListener('click', function (event) {
            if (!isDeferOwnerActive()) {
                return;
            }

            let target = event.target;
            let button;
            let parts;

            if (!target || !target.closest) {
                return;
            }

            button = target.closest('[data-awa-minicart-close="true"]');
            if (!button) {
                return;
            }

            event.preventDefault();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
            event.stopPropagation();

            parts = getMinicartParts();
            closeDropdown(parts.trigger, parts.dropdown);
        }, true);
    }

    function boot() {
        bindCheckoutFallback();
        closeMinicartOnCartPage();

        if (isCheckoutCartPage()) {
            if (window.__awaMinicartCartPageBooted) {
                return;
            }
            window.__awaMinicartCartPageBooted = true;
            // #region agent log
            fetch('/awa-debug-50aadf.php',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'50aadf'},body:JSON.stringify({sessionId:'50aadf',runId:'post-fix-minicart-throttle',hypothesisId:'H10',location:'awa-minicart-defer-init.js:boot',message:'cart page short-circuit active',data:{pathname:(window.location&&window.location.pathname)||'',guardsOnlyMode:guardsOnlyMode},timestamp:Date.now()})}).catch(()=>{});
            // #endregion
            bindTriggerGuard();
            bindShellFallbackNavigation();
            bindEscapeClose();
            bindContinueClose();
            return;
        }

        bindTriggerGuard();
        bindShellFallbackNavigation();
        bindEscapeClose();
        bindContinueClose();

        if (guardsOnlyMode) {
            window.__awaMinicartDeferInit = true;
            bindInteractionSync();
            observeMinicartState();
            queueUpdateExpandedState();
            scheduleBootstrapPasses();
            if (!isHomeDeferBootstrapActive() || window.__awaMinicartUiInit) {
                initDropdown();
            }
            if (isHomeDeferBootstrapActive()) {
                // Home: the full Knockout minicart UI is intentionally booted by
                // awa-minicart-ui-bootstrap-home.js on cart intent or late fallback.
                if (window.__awaMinicartUiReady && typeof window.__awaMinicartUiReady.then === 'function') {
                    window.__awaMinicartUiReady.then(function () {
                        initDropdown();
                        queueUpdateExpandedState();
                    });
                }
            }
            return;
        }

        if (window.__awaMinicartDeferBooted) {
            scheduleBootstrapPasses();
            return;
        }

        window.__awaMinicartDeferBooted = true;

        bindInteractionSync();
        observeMinicartState();
        queueUpdateExpandedState();
        scheduleBootstrapPasses();

        if (!isHomeDeferBootstrapActive()) {
            initDropdown();
            [0, 600, 1800].forEach(function (delay) {
                window.setTimeout(function () {
                    ensureCatalogMinicartUi(function () {
                        initDropdown();
                        queueUpdateExpandedState();
                    });
                }, delay);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.addEventListener('load', boot, { once: true });
})();
