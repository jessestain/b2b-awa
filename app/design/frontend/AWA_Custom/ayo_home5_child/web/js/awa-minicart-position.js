/**
 * AWA Motos — shared minicart viewport anchoring (right / under cart icon).
 * Used by defer-init, header-minicart-ui-v2, and home bootstrap open paths.
 */
define([], function () {
    'use strict';

    /**
     * Cart page intentionally disables the flyout (awa-cart-stack).
     *
     * @returns {boolean}
     */
    function isCartPageFlyoutDisabled() {
        var body = document.body;

        return !!(body && body.classList.contains('checkout-cart-index'));
    }

    /**
     * @param {HTMLElement|null} panel
     * @returns {HTMLElement|null}
     */
    function resolvePanel(panel) {
        if (!panel) {
            return null;
        }

        if (panel.classList && panel.classList.contains('block-minicart')) {
            return panel;
        }

        return (panel.querySelector ? panel.querySelector('.block-minicart') : null) || panel;
    }

    /**
     * Bottom of the cart trigger in viewport coords.
     * Falls back to the visible header only when the trigger is unavailable.
     *
     * @param {DOMRect|null} triggerRect
     * @returns {Object}
     */
    function resolveHeaderBottom(triggerRect) {
        var selectors = [
            '.header-wrapper-sticky.is-sticky',
            '.header-wrapper-sticky',
            '.awa-site-header',
            '.awa-main-header',
            '.header-main'
        ];
        var best = 0;
        var i;
        var el;
        var rect;
        var source = 'fallback';

        if (triggerRect && triggerRect.width > 0 && triggerRect.height > 0) {
            return {
                bottom: triggerRect.bottom,
                source: '.action.showcart'
            };
        }

        for (i = 0; i < selectors.length; i += 1) {
            el = document.querySelector(selectors[i]);
            if (!el) {
                continue;
            }
            rect = el.getBoundingClientRect();
            // Prefer bars that still intersect the upper viewport.
            if (rect.height > 0 && rect.top < 160 && rect.bottom > best) {
                best = rect.bottom;
                source = selectors[i];
            }
        }

        return {
            bottom: best > 0 ? best : 64,
            source: best > 0 ? source : 'fallback-64'
        };
    }

    /**
     * Anchor an open minicart panel under/near the cart trigger (desktop right).
     * On narrow viewports, keep a full-width inset panel.
     *
     * @param {HTMLElement|null} panel
     * @param {Object} [options]
     * @param {boolean} [options.forceVisible=true]
     * @returns {Object|null} metrics for debugging
     */
    function centerOpenMinicartPanel(panel, options) {
        var opts = options || {};
        var forceVisible = opts.forceVisible !== false;
        var target = resolvePanel(panel);
        var wrapper;
        var dialog;
        var headerInfo;
        var headerBottom;
        var positioningRoot;
        var positioningRect;
        var panelWidth;
        var left;
        var right;
        var top;
        var rect;
        var metrics;
        var trigger;
        var triggerRect;
        var gutter = 16;
        var isNarrow;

        if (!target || !target.style) {
            return null;
        }

        if (isCartPageFlyoutDisabled()) {
            return { skipped: true, reason: 'checkout-cart-index' };
        }

        if (!document.querySelector('[data-block="minicart"].is-open, .minicart-wrapper.is-open')) {
            return { skipped: true, reason: 'minicart-closed' };
        }

        wrapper = target.closest('[data-block="minicart"], .minicart-wrapper');
        dialog = target.closest('.ui-dialog, .mage-dropdown-dialog');
        trigger = document.querySelector(
            '[data-block="minicart"] .action.showcart, .minicart-wrapper .action.showcart, a.action.showcart'
        );

        if (wrapper) {
            wrapper.setAttribute('data-awa-minicart-dropdown', '1');
        }

        if (dialog) {
            dialog.style.setProperty('display', 'block', 'important');
            dialog.style.setProperty('visibility', 'visible', 'important');
            dialog.style.setProperty('opacity', '1', 'important');
            dialog.style.setProperty('position', 'static', 'important');
            dialog.style.setProperty('width', 'auto', 'important');
            dialog.style.setProperty('height', 'auto', 'important');
            dialog.style.setProperty('overflow', 'visible', 'important');
        }

        target.style.setProperty('position', 'fixed', 'important');
        positioningRoot = target.offsetParent;
        positioningRect = positioningRoot
            ? positioningRoot.getBoundingClientRect()
            : {
                top: 0,
                right: document.documentElement.clientWidth,
                width: document.documentElement.clientWidth
            };

        triggerRect = trigger ? trigger.getBoundingClientRect() : null;
        headerInfo = resolveHeaderBottom(triggerRect);
        headerBottom = headerInfo.bottom;
        isNarrow = window.innerWidth < 768;
        panelWidth = isNarrow
            ? Math.max(0, window.innerWidth - gutter * 2)
            : Math.min(380, Math.max(280, window.innerWidth - gutter * 2));
        top = Math.max(0, headerBottom + 8 - positioningRect.top);

        if (isNarrow) {
            left = gutter;
            right = gutter;
        } else if (triggerRect && triggerRect.width > 0) {
            // Fixed descendants use the sticky wrapper as containing block
            // because it has backdrop-filter; anchor in that coordinate space.
            right = Math.max(gutter, positioningRect.right - triggerRect.right);
            if (right + panelWidth > positioningRect.width - gutter) {
                right = Math.max(gutter, positioningRect.width - gutter - panelWidth);
            }
            left = null;
        } else {
            right = gutter;
            left = null;
        }

        if (forceVisible) {
            target.style.setProperty('display', 'flex', 'important');
            target.style.setProperty('visibility', 'visible', 'important');
            target.style.setProperty('opacity', '1', 'important');
            target.style.setProperty('pointer-events', 'auto', 'important');
            target.setAttribute('aria-hidden', 'false');
        }

        target.style.setProperty('top', top + 'px', 'important');
        target.style.setProperty('transform', 'none', 'important');
        target.style.setProperty('width', panelWidth + 'px', 'important');
        target.style.setProperty('min-width', isNarrow ? '0' : '280px', 'important');
        target.style.setProperty('max-width', 'min(380px, calc(100vw - 32px))', 'important');
        target.style.setProperty('z-index', 'var(--awa-z-minicart, 1300)', 'important');
        target.style.setProperty('overflow-y', 'auto', 'important');
        // Sticky + backdrop-filter creates a fixed containing block (~header height).
        // height:auto against that CB collapses the drawer (~220px). Detect CB, not only body class.
        (function applyViewportDock() {
            var stickyEl = document.querySelector('.header-wrapper-sticky');
            var stickyCs = stickyEl ? window.getComputedStyle(stickyEl) : null;
            var stickyBf = stickyCs
                ? (stickyCs.backdropFilter || stickyCs.webkitBackdropFilter || '')
                : '';
            var stickyCreatesCb = !!(stickyBf && stickyBf !== 'none');
            var offsetParent = target.offsetParent;
            var opIsSticky = !!(offsetParent && offsetParent.classList && (
                offsetParent.classList.contains('header-wrapper-sticky') ||
                (typeof offsetParent.closest === 'function' &&
                    offsetParent.closest('.header-wrapper-sticky'))
            ));
            var body = document.body;
            var isAccountShell = !!(body && (
                body.classList.contains('account') ||
                body.classList.contains('b2b-account-dashboard') ||
                body.classList.contains('b2b-account-shell') ||
                body.classList.contains('awa-account-operational') ||
                Array.prototype.some.call(body.classList, function (cls) {
                    return String(cls).indexOf('b2b-') === 0;
                })
            ));
            var needsDock = stickyCreatesCb || opIsSticky || isAccountShell;
            var dockHeight;

            if (needsDock) {
                // Sticky backdrop-filter cria containing block; desligar remove o CB.
                if (stickyEl && stickyEl.style) {
                    stickyEl.style.setProperty('backdrop-filter', 'none', 'important');
                    stickyEl.style.setProperty('-webkit-backdrop-filter', 'none', 'important');
                    stickyEl.setAttribute('data-awa-mc-bf', 'off');
                }
                dockHeight = Math.max(240, Math.round(window.innerHeight - headerBottom));
                target.style.setProperty('bottom', 'auto', 'important');
                target.style.setProperty('height', dockHeight + 'px', 'important');
                target.style.setProperty('max-height', dockHeight + 'px', 'important');
                target.style.setProperty('min-height', '0', 'important');
                return;
            }

            target.style.setProperty(
                'max-height',
                'calc(100vh - ' + Math.max(0, Math.round(headerBottom + 24)) + 'px)',
                'important'
            );
            target.style.setProperty('height', 'auto', 'important');
        }());

        if (isNarrow) {
            target.style.setProperty('left', left + 'px', 'important');
            target.style.setProperty('right', right + 'px', 'important');
            target.style.setProperty('width', 'auto', 'important');
        } else {
            target.style.setProperty('right', right + 'px', 'important');
            target.style.setProperty('left', 'auto', 'important');
        }

        rect = target.getBoundingClientRect();
        metrics = {
            skipped: false,
            vw: window.innerWidth,
            left: Math.round(rect.left),
            right: right,
            top: top,
            headerBottom: Math.round(headerBottom),
            headerSource: headerInfo.source,
            positioningRootTop: Math.round(positioningRect.top),
            positioningRootRight: Math.round(positioningRect.right),
            panelWidth: panelWidth,
            rectLeft: Math.round(rect.left),
            rectTop: Math.round(rect.top),
            rectWidth: Math.round(rect.width),
            rectHeight: Math.round(rect.height),
            triggerRight: triggerRect ? Math.round(triggerRect.right) : null,
            edgeDelta: triggerRect
                ? Math.round(triggerRect.right - (rect.left + rect.width))
                : null,
            computedDisplay: window.getComputedStyle(target).display
        };

        return metrics;
    }

    /**
     * @param {HTMLElement|null} panel
     * @param {Object} [options]
     */
    function scheduleCenterOpenMinicartPanel(panel, options) {
        var startedAt;
        var rafId = 0;

        if (isCartPageFlyoutDisabled()) {
            return;
        }

        // Light reposition: Magento may nudge once; KO hydration needs a few late ticks.
        // Avoid 50ms interval + 2.5s rAF (main-thread cost on sticky header).
        startedAt = Date.now();
        function rafTick() {
            centerOpenMinicartPanel(panel, options);
            if (Date.now() - startedAt < 400) {
                rafId = window.requestAnimationFrame(rafTick);
            }
        }
        if (typeof window.requestAnimationFrame === 'function') {
            rafId = window.requestAnimationFrame(rafTick);
        }

        [0, 32, 120, 320, 800].forEach(function (delay) {
            window.setTimeout(function () {
                centerOpenMinicartPanel(panel, options);
            }, delay);
        });

        window.setTimeout(function () {
            if (rafId && typeof window.cancelAnimationFrame === 'function') {
                window.cancelAnimationFrame(rafId);
            }
        }, 900);
    }

    /**
     * Center whatever minicart panel is currently in the DOM.
     *
     * @param {Object} [options]
     */
    function centerCurrentMinicart(options) {
        var panel = document.querySelector(
            '[data-block="minicart"].is-open .block-minicart, .minicart-wrapper.is-open .block-minicart'
        );

        return scheduleCenterOpenMinicartPanel(panel, options);
    }

    return {
        isCartPageFlyoutDisabled: isCartPageFlyoutDisabled,
        centerOpenMinicartPanel: centerOpenMinicartPanel,
        scheduleCenterOpenMinicartPanel: scheduleCenterOpenMinicartPanel,
        centerCurrentMinicart: centerCurrentMinicart
    };
});
