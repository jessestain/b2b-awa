/**
 * Checkout Simplified Header — v5 (setProperty interception)
 *
 * Problem: awa-bundle-site.js continuously re-applies inline styles
 * (display: grid !important) on .wp-header and .top-search on mobile.
 * MutationObserver-based approaches lose the race because awa-bundle-site
 * re-applies faster than any debounced callback can enforce.
 *
 * Solution: Intercept style.setProperty() and setAttribute('style')
 * on the target elements. When awa-bundle-site.js tries to set
 * display: grid, our interceptor silently redirects to display: flex
 * (for wp-header) or display: none (for top-search). This is
 * synchronous — no race condition possible.
 *
 * Only active on checkout pages (body.rokanthemes-onepagecheckout)
 * and only on mobile (≤991px).
 */
define([], function () {
    'use strict';

    /** @type {Set<string>} Properties to block entirely on wp-header */
    let BLOCKED_PROPS = [
        'grid-template-columns', 'grid-template-rows',
        'grid-template-areas', 'grid-template'
    ];

    /**
     * Install a setProperty interceptor on an element's style object.
     *
     * @param {HTMLElement} el - Target element
     * @param {Object} overrides - Map of property name → {value, priority}
     * @param {string[]} blocked - Property names to silently ignore
     * @returns {Function} The original setProperty (bound)
     */
    function interceptSetProperty(el, overrides, blocked) {
        let orig = el.style.setProperty.bind(el.style);

        el.style.setProperty = function (prop, value, priority) {
            if (overrides[prop]) {
                orig(prop, overrides[prop].value, overrides[prop].priority);
                return;
            }

            if (blocked.indexOf(prop) !== -1) {
                return;
            }

            orig(prop, value, priority);
        };

        return orig;
    }

    /**
     * Intercept setAttribute('style', ...) so bulk style reassignment from
     * awa-bundle-site.js is redirected through our setProperty interceptor
     * (which already overrides display:grid and blocks grid-template-*).
     *
     * Instead of silently discarding the entire style string, we parse each
     * CSS declaration and forward it via the already-intercepted setProperty —
     * this preserves non-conflicting properties set by third-party components.
     *
     * @param {HTMLElement} el - Target element
     */
    function blockSetAttribute(el) {
        let orig = el.setAttribute.bind(el);

        el.setAttribute = function (name, value) {
            if (name !== 'style') {
                orig(name, value);
                return;
            }

            // Route each declaration through the setProperty interceptor so
            // override + block rules are enforced without dropping the call.
            let declarations = (value || '').split(';');

            for (let i = 0; i < declarations.length; i++) {
                let decl = declarations[i].trim();

                if (!decl) {
                    continue;
                }

                let colonIdx = decl.indexOf(':');

                if (colonIdx < 0) {
                    continue;
                }

                let prop = decl.slice(0, colonIdx).trim();
                let rawVal = decl.slice(colonIdx + 1).trim();
                let priority = '';

                if (rawVal.endsWith('!important')) {
                    priority = 'important';
                    rawVal = rawVal.slice(0, -10).trim();
                }

                el.style.setProperty(prop, rawVal, priority);
            }
        };
    }

    /**
     * Apply initial desired styles using the original setProperty.
     *
     * @param {Function} origSet - Original setProperty (bound)
     * @param {Object} styles - Map of property name → {value, priority}
     */
    function applyInitial(origSet, styles) {
        let props = Object.keys(styles);

        for (let i = 0; i < props.length; i++) {
            origSet(props[i], styles[props[i]].value, styles[props[i]].priority);
        }
    }

    return function () {
        if (!document.body.classList.contains('rokanthemes-onepagecheckout')) {
            return;
        }

        // GUARDA (23/10): o re-render das secoes customer-data re-insere temporariamente
        // promo/top-header/support no header do checkout (flash de ~99-197px e CLS).
        // O estado final do checkout nao tem esses blocos — remove imediatamente
        // qualquer re-insercao (desktop e mobile).
        var REMOVE_ON_CHECKOUT = [
            '#awa-b2b-promo-bar', '.awa-b2b-promo-bar', '.top-header', '.wp-header',
            '.awa-checkout-header-support', '.hotline_header'
        ];

        function pruneReinserted(node) {
            for (var k = 0; k < REMOVE_ON_CHECKOUT.length; k++) {
                var sel = REMOVE_ON_CHECKOUT[k];
                if (node.matches && node.matches(sel)) {
                    if (node.parentNode) { node.parentNode.removeChild(node); }
                    return;
                }
            }
            var inside = node.querySelectorAll(REMOVE_ON_CHECKOUT.join(','));
            for (var x = 0; x < inside.length; x++) {
                if (inside[x].parentNode) { inside[x].parentNode.removeChild(inside[x]); }
            }
        }

        var reinsertObserver = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var added = mutations[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    if (added[j].nodeType === 1) {
                        pruneReinserted(added[j]);
                    }
                }
            }
        });

        reinsertObserver.observe(document.body, { childList: true, subtree: true });

        // Only intercept on mobile (≤991px) — desktop CSS handles it fine
        if (window.innerWidth > 991) {
            return;
        }

        let wpH = document.querySelector('.wp-header');
        let topS = document.querySelector('.wp-header > .top-search');

        if (!wpH) {
            return;
        }

        // --- wp-header: force flex layout, block grid ---
        let wpOverrides = {
            'display': {value: 'flex', priority: 'important'},
            'gap': {value: '0', priority: 'important'}
        };

        let wpStyles = {
            'display': {value: 'flex', priority: 'important'},
            'align-items': {value: 'center', priority: 'important'},
            'justify-content': {value: 'center', priority: 'important'},
            'padding': {value: '8px 12px', priority: 'important'},
            'gap': {value: '0', priority: 'important'},
            'min-height': {value: 'auto', priority: 'important'},
            'height': {value: 'auto', priority: 'important'}
        };

        // Clear existing inline styles before intercepting
        wpH.removeAttribute('style');
        let origWpSet = interceptSetProperty(wpH, wpOverrides, BLOCKED_PROPS);

        blockSetAttribute(wpH);
        applyInitial(origWpSet, wpStyles);

        // --- top-search: force hidden ---
        if (topS) {
            let tsOverrides = {
                'display': {value: 'none', priority: 'important'},
                'min-height': {value: '0', priority: 'important'},
                'height': {value: '0', priority: 'important'}
            };

            let tsStyles = {
                'display': {value: 'none', priority: 'important'},
                'height': {value: '0', priority: 'important'},
                'overflow': {value: 'hidden', priority: 'important'},
                'min-height': {value: '0', priority: 'important'}
            };

            topS.removeAttribute('style');
            let origTsSet = interceptSetProperty(topS, tsOverrides, BLOCKED_PROPS);

            blockSetAttribute(topS);
            applyInitial(origTsSet, tsStyles);
        }
    };
});

/* v6 guarda re-insercao 20261023-r2 */
