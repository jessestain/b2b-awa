/**
 * Home: adia Magento_Ui/js/core/app do minicart até customer-data estar pronto.
 *
 * Dependência 'ko' adicionada para guard contra double-binding:
 * em páginas não-home, o text/x-magento-init já inicializa o minicart via
 * Magento_Ui/js/core/app; sem o guard, runAppInit chamaria appModule() novamente
 * resultando em "cannot apply bindings multiple times" (knockout.js:3620).
 */
define(['js/awa-customer-sections-gate', 'ko'], function (whenCustomerSectionsReady, ko) {
    'use strict';

    function markContentReadyWhenHydrated() {
        var shell = document.querySelector(
            '[data-awa-header-minicart-shell="true"], .awa-header-minicart[data-awa-header-cart="true"]'
        );

        if (!shell) {
            return;
        }

        function applyReady() {
            shell.setAttribute('data-awa-minicart-content-ready', '1');
            shell.classList.remove('awa-header-minicart--content-pending');
            shell.classList.add('awa-header-minicart--content-ready');
        }

        if (document.querySelector('#minicart-content-wrapper .block-title')) {
            applyReady();
            return;
        }

        var wrapper = document.getElementById('minicart-content-wrapper');
        if (wrapper && typeof MutationObserver === 'function') {
            var observer = new MutationObserver(function () {
                if (!document.querySelector('#minicart-content-wrapper .block-title')) {
                    return;
                }
                observer.disconnect();
                applyReady();
            });
            observer.observe(wrapper, { childList: true, subtree: true });
            window.setTimeout(function () {
                observer.disconnect();
            }, 10000);
            return;
        }

        var attempts = 0;
        var timer = window.setInterval(function () {
            attempts += 1;
            if (document.querySelector('#minicart-content-wrapper .block-title')) {
                window.clearInterval(timer);
                applyReady();
                return;
            }
            if (attempts >= 50) {
                window.clearInterval(timer);
            }
        }, 100);
    }

    var MINICART_TEMPLATE_IDS = [
        'Magento_Checkout/minicart/content',
        'Magento_Checkout/minicart/item/default',
        'Magento_Tax/checkout/minicart/subtotal/totals',
        'Magento_Msrp/checkout/minicart/subtotal/totals'
    ];

    function templateToTextPluginId(templateId) {
        if (!templateId || typeof templateId !== 'string') {
            return '';
        }

        if (templateId.indexOf('template/') !== -1) {
            return 'text!' + templateId.replace(/\.html$/, '') + '.html';
        }

        var slash = templateId.indexOf('/');

        if (slash === -1) {
            return '';
        }

        return 'text!' + templateId.slice(0, slash) + '/template/' + templateId.slice(slash + 1) + '.html';
    }

    function collectTemplateIds(node, bucket) {
        if (!node || typeof node !== 'object') {
            return;
        }

        if (typeof node.template === 'string') {
            bucket[node.template] = true;
        }

        if (node.config && typeof node.config.template === 'string') {
            bucket[node.config.template] = true;
        }

        if (node.children && typeof node.children === 'object') {
            Object.keys(node.children).forEach(function (key) {
                collectTemplateIds(node.children[key], bucket);
            });
        }
    }

    function preloadMinicartTemplates(jsLayout, done) {
        var bucket = Object.create(null);
        var deps;
        var i;

        MINICART_TEMPLATE_IDS.forEach(function (id) {
            bucket[id] = true;
        });

        if (jsLayout && jsLayout.components) {
            Object.keys(jsLayout.components).forEach(function (key) {
                collectTemplateIds(jsLayout.components[key], bucket);
            });
        }

        deps = Object.keys(bucket).map(templateToTextPluginId).filter(Boolean);

        if (!deps.length) {
            done();
            return;
        }

        require(deps, done, done);
    }

    function hydrateDeferredMinicartShell() {
        var trigger = document.querySelector('[data-block="minicart"] [data-awa-minicart-defer="trigger"]');
        var wrapper = document.getElementById('minicart-content-wrapper');

        if (trigger && !trigger.getAttribute('data-bind')) {
            trigger.setAttribute('data-bind', "scope: 'minicart_content'");
            trigger.removeAttribute('data-awa-minicart-defer');

            var counter = trigger.querySelector('.counter.qty');
            var totalNode = trigger.querySelector('.total-mini-cart-item');

            if (counter && totalNode && !totalNode.querySelector('[data-bind]')) {
                counter.setAttribute(
                    'data-bind',
                    "css: { empty: !!getCartParam('summary_count') == false }, blockLoader: isLoading"
                );
                totalNode.innerHTML =
                    '<!-- ko if: getCartParam(\'summary_count\') -->' +
                    '<!-- ko text: getCartParam(\'summary_count\') --><!-- /ko -->' +
                    '<!-- /ko -->' +
                    '<!-- ko if: !getCartParam(\'summary_count\') -->0<!-- /ko -->';
            }
        }

        if (wrapper && wrapper.getAttribute('data-awa-minicart-defer') === 'content') {
            wrapper.removeAttribute('data-awa-minicart-defer');
            wrapper.removeAttribute('aria-hidden');
            wrapper.setAttribute('data-bind', "scope: 'minicart_content'");
            wrapper.innerHTML = '<!-- ko template: getTemplate() --><!-- /ko -->';
        }

        // Missão estabilização 2026-10-22: o shell estático (estado "Carregando…")
        // não pode permanecer depois da hidratação — o conteúdo KO assume qualquer
        // estado real (itens ou vazio). Sem isso, um carrinho vazio real ficaria
        // com o placeholder eterno ou duplicado com o vazio do KO.
        var deferShell = document.querySelector('[data-awa-minicart-defer-shell="true"]');
        if (deferShell) {
            deferShell.setAttribute('aria-hidden', 'true');
            deferShell.setAttribute('inert', '');
            deferShell.hidden = true;
        }
    }

    function isVisible(element) {
        return !!element && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    }

    function syncDropdownState() {
        var shell = document.querySelector(
            '[data-awa-header-minicart-shell="true"], .awa-header-minicart[data-awa-header-cart="true"]'
        );
        var wrapper = document.querySelector('[data-block="minicart"], .minicart-wrapper');
        var trigger = wrapper ? wrapper.querySelector('.showcart, .action.showcart') : null;
        var panel = wrapper ? wrapper.querySelector('[data-role="dropdownDialog"], .block-minicart') : null;
        var panelStyle = panel && window.getComputedStyle ? window.getComputedStyle(panel) : null;
        var panelVisible = !!(
            panel &&
            isVisible(panel) &&
            (!panelStyle || (
                panelStyle.display !== 'none' &&
                panelStyle.visibility !== 'hidden' &&
                panelStyle.opacity !== '0'
            ))
        );
        var expanded = !!(
            panelVisible ||
            (wrapper && (
                wrapper.classList.contains('active') ||
                wrapper.classList.contains('is-open') ||
                wrapper.classList.contains('show')
            )) ||
            (panel && (
                panel.classList.contains('_active') ||
                panel.classList.contains('active') ||
                panel.classList.contains('is-open')
            ))
        );
        var ready = !!(trigger && isVisible(trigger));

        if (wrapper) {
            wrapper.setAttribute('data-awa-minicart-dropdown', '1');
        }

        if (trigger) {
            trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        if (shell) {
            shell.setAttribute('data-awa-minicart-ready', ready ? '1' : '0');
            shell.setAttribute('data-awa-minicart-expanded', expanded ? '1' : '0');
            shell.classList.toggle('awa-header-minicart--ready', ready);
            shell.classList.toggle('awa-header-minicart--expanded', expanded);
        }

        // Keep .block-minicart.empty in sync with counter (panel has no KO scope).
        // Template must not hardcode empty — that left the class stuck with items in cart.
        if (panel) {
            var counter = trigger ? trigger.querySelector('.counter.qty') : null;
            var cartEmpty = !counter || counter.classList.contains('empty');
            panel.classList.toggle('empty', cartEmpty);
        }
    }

    function scheduleDropdownStateSync() {
        [0, 80, 240, 600].forEach(function (delay) {
            window.setTimeout(syncDropdownState, delay);
        });
    }

    function bindDropdownStateSync() {
        if (window.__awaMinicartUiBootstrapStateSyncBound) {
            return;
        }

        window.__awaMinicartUiBootstrapStateSyncBound = true;
        document.addEventListener('click', scheduleDropdownStateSync, true);
        document.addEventListener('keyup', scheduleDropdownStateSync, true);
        document.addEventListener('contentUpdated', scheduleDropdownStateSync, true);
    }

    function preloadMinicartPosition() {
        if (typeof require !== 'function') {
            return;
        }

        require(['js/awa-minicart-position'], function () {
            /* warm AMD cache before first open */
        });
    }

    function centerAfterOpen(panelEl) {
        if (!panelEl || typeof require !== 'function') {
            return;
        }

        require(['js/awa-minicart-position'], function (minicartPosition) {
            if (!minicartPosition) {
                return;
            }

            // Center immediately to avoid right-edge 44px flash, then re-center as KO hydrates.
            if (typeof minicartPosition.centerOpenMinicartPanel === 'function') {
                minicartPosition.centerOpenMinicartPanel(panelEl);
            }
            if (typeof minicartPosition.scheduleCenterOpenMinicartPanel === 'function') {
                minicartPosition.scheduleCenterOpenMinicartPanel(panelEl);
            }
        });
    }

    function openDropdownWithRetry(maxAttempts) {
        var attempts = 0;

        function attemptOpen() {
            attempts += 1;

            require(['jquery', 'dropdownDialog'], function ($) {
                var $block = $('[data-block="minicart"] [data-role="dropdownDialog"]');

            function cleanOrphanDialogs() {
                var $w = $block.closest('[data-block="minicart"], .minicart-wrapper');
                $w.find('.ui-dialog').each(function () {
                    var $d = $(this);
                    if (!$d.find('.block-minicart').length && $d.height() === 0) { $d.remove(); }
                });
            }

                if ($block.length && !$block.data('mageDropdownDialog')) {
                    initDropdownDialog();
                }

                if ($block.length && $block.data('mageDropdownDialog')) {
                    $block.dropdownDialog('open');
                    scheduleDropdownStateSync();
                    centerAfterOpen($block.get(0));
                    window.__awaMinicartOpenAfterInit = false;
                    return;
                }

                if (attempts < maxAttempts) {
                    window.setTimeout(attemptOpen, 75);
                } else {
                }
            }, function () {
                if (attempts < maxAttempts) {
                    window.setTimeout(attemptOpen, 75);
                } else {
                }
            });
        }

        attemptOpen();
    }

    function initDropdownDialog() {
        require(['jquery', 'dropdownDialog'], function ($) {
            var $block = $('[data-block="minicart"] [data-role="dropdownDialog"]');
            var raw;
            var parsed;
            var config;

            if (!$block.length || $block.data('mageDropdownDialog') || $block.parent().hasClass('ui-dialog')) {
                return;
            }

            raw = $block.attr('data-mage-init');

            if (raw) {
                if (raw.indexOf('dropdownDialog') !== -1) {
                    window.setTimeout(cleanOrphanDialogs, 800);
                    window.setTimeout(cleanOrphanDialogs, 2600);
                    window.setTimeout(cleanOrphanDialogs, 5200);
                    return;
                }
                try {
                    parsed = JSON.parse(raw);
                    config = parsed.dropdownDialog || null;
                } catch (e) {
                    /* ignore malformed mage-init */
                }
            }

            if (!config && document.getElementById('awa-minicart-ui-json')) {
                config = {
                    appendTo: '[data-block=minicart]',
                    triggerTarget: '.showcart',
                    timeout: 0,
                    closeOnMouseLeave: false,
                    closeOnEscape: true,
                    triggerClass: 'is-open',
                    parentClass: 'is-open',
                    buttons: []
                };
            }

            if (config) {
                $block.dropdownDialog(config);
                var $wrapper = $block.closest('[data-block="minicart"], .minicart-wrapper');
                $wrapper.attr('data-awa-minicart-dropdown', '1');
                $wrapper.on('keydown', function (event) {
                    if (event.key !== 'Escape' && event.keyCode !== 27) { return; }
                    window.setTimeout(function () {
                        var trigger = $wrapper.find('.showcart').first();
                        var active = document.activeElement;
                        var inside = active && $wrapper[0] && $wrapper[0].contains(active);
                        if (!inside && trigger.length) { trigger.trigger('focus'); }
                    }, 60);
                });
                bindDropdownStateSync();
                scheduleDropdownStateSync();
            }
        });
    }


    // Pedido do dono 2026-10-23: botões dos cards em largura máxima. Aplicado
    // inline-important (vence o CSS do site, que usa @layer e importants em camada).
    // Duplicado aqui porque o customer-runtime não carrega na home (defer).
    function initCardsFullWidthButtons() {
        if (window.__awaCardsFullWidth) { return; }
        window.__awaCardsFullWidth = true;
        var apply = function () {
            var cards = document.querySelectorAll('.products-grid .item-product, .products-grid .product-item, .awa-shelf .item-product, .content-top-home .item-product, .item-product.awa-carousel-card-slot');
            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var btn = card.querySelector('.action.tocart, button.tocart, .btn-add-to-cart');
                if (!btn) { continue; }
                var wrappers = [card.querySelector('.product-info-cart'), card.querySelector('.actions-primary'), btn.closest('form')];
                for (var w = 0; w < wrappers.length; w++) {
                    var el = wrappers[w];
                    if (!el) { continue; }
                    el.style.setProperty('width', '100%', 'important');
                    el.style.setProperty('max-width', 'none', 'important');
                    el.style.setProperty('padding-inline', '0', 'important');
                    el.style.setProperty('margin-inline', '0', 'important');
                    el.style.setProperty('box-sizing', 'border-box', 'important');
                }
                btn.style.setProperty('width', '100%', 'important');
                btn.style.setProperty('max-width', 'none', 'important');
                btn.style.setProperty('justify-content', 'center', 'important');
                btn.style.setProperty('box-sizing', 'border-box', 'important');
            }
        };
        apply();
        var t = null;
        var mo = new MutationObserver(function () {
            if (t) { return; }
            t = window.setTimeout(function () { t = null; apply(); }, 250);
        });
        var targets = document.querySelectorAll('.products-grid, .columns, .column.main, .content-top-home');
        for (var k = 0; k < targets.length; k++) { mo.observe(targets[k], { childList: true, subtree: true }); }
        window.setTimeout(apply, 1200);
        window.setTimeout(apply, 3000);
    }
    initCardsFullWidthButtons();

    function scheduleDropdownDialogInit() {
        [0, 250, 1000, 2500].forEach(function (delay) {
            window.setTimeout(initDropdownDialog, delay);
        });
    }

    function runAppInit(appModule, payload) {
        var minicartEl = document.querySelector('[data-block="minicart"]');

        if (!minicartEl || !payload || !payload.jsLayout) {
            return false;
        }

        // Guard: already bound by Magento x-magento-init (non-home paths).
        var contentWrapper = document.getElementById('minicart-content-wrapper');

        if (contentWrapper && ko.dataFor(contentWrapper)) {
            // KO já ligado (x-magento-init em rotas não-home): apenas aposenta o shell
            // de carregamento da home e segue o fluxo normal.
            hydrateDeferredMinicartShell();
            if (payload.loaderUrl) {
                require(['Magento_Ui/js/block-loader'], function (blockLoader) {
                    blockLoader(payload.loaderUrl);
                });
            }

            scheduleDropdownDialogInit();
            markContentReadyWhenHydrated();
            window.__awaMinicartDeferSuperseded = true;

            return true;
        }

        // Hydrate is ideally done before requiring Magento_Ui/js/core/app (see launch()).
        // Keep idempotent call here for any other entry points.
        hydrateDeferredMinicartShell();

        try {
            appModule(payload.jsLayout);
        } catch (err) {
            if (window.console && console.error) {
                console.error('AWA minicart UI init failed', err);
            }
            return false;
        }

        // Safety net when KO bootstrap already ran before hydrate.
        applyMinicartKnockoutBindings();

        if (payload.loaderUrl) {
            require(['Magento_Ui/js/block-loader'], function (blockLoader) {
                blockLoader(payload.loaderUrl);
            });
        }

        if (document.getElementById('awa-customer-sections-defer-json')) {
            require(['js/awa-minicart-phase3'], function (initMinicartPhase3) {
                initMinicartPhase3({}, minicartEl);
            });
        }

        scheduleDropdownDialogInit();
        markContentReadyWhenHydrated();
        window.__awaMinicartDeferSuperseded = true;

        return true;
    }

    /**
     * Home defer: document ko.applyBindings() may attach a parent context to the
     * wrapper without rendering the scope template. Replay Magento scope.js
     * applyComponents once minicart_content exists in uiRegistry.
     */
    function applyMinicartKnockoutBindings() {
        require(['ko', 'uiRegistry', 'mage/translate'], function (koLib, registry, $t) {
            var contentEl = document.getElementById('minicart-content-wrapper');
            var triggerEl = document.querySelector('[data-block="minicart"] .showcart[data-bind]');

            function applyScopeToElement(el, component) {
                var bindingContext;
                var childContext;

                if (!el || !component) {
                    return false;
                }

                if (el.id === 'minicart-content-wrapper' && el.querySelector('.block-title')) {
                    return true;
                }

                bindingContext = koLib.contextFor(el) || koLib.contextFor(document.body);

                if (!bindingContext || typeof bindingContext.createChildContext !== 'function') {
                    return false;
                }

                childContext = bindingContext.createChildContext(component);
                koLib.utils.extend(childContext, {
                    $t: $t
                });
                koLib.utils.arrayForEach(koLib.virtualElements.childNodes(el), koLib.cleanNode);
                koLib.applyBindingsToDescendants(childContext, el);

                return !!(el.id !== 'minicart-content-wrapper' || el.querySelector('.block-title'));
            }

            if (contentEl && contentEl.querySelector('.block-title')) {
                return;
            }

            registry.get('minicart_content', function (component) {
                function tryApply() {
                    applyScopeToElement(contentEl, component);
                    applyScopeToElement(triggerEl, component);
                    return !!(contentEl && contentEl.querySelector('.block-title'));
                }

                var contentOk = tryApply();

                // Magento remote template engine can resolve async — retry briefly.
                if (!contentOk) {
                    [150, 400, 1000].forEach(function (delay) {
                        window.setTimeout(function () {
                            if (contentEl && !contentEl.querySelector('.block-title')) {
                                tryApply();
                            }
                        }, delay);
                    });
                }
            });
        });
    }

    function bootstrapMinicartUi(payload, options) {
        options = options || {};

        if (!payload || !payload.jsLayout) {
            return Promise.resolve();
        }

        var launchDelayMs = typeof options.launchDelayMs === 'number' && options.launchDelayMs >= 0
            ? options.launchDelayMs
            : 600;

        if (options.openAfterInit) {
            window.__awaMinicartOpenAfterInit = true;
        }

        preloadMinicartPosition();

        if (window.__awaMinicartUiInit) {
            if (options.openAfterInit) {
                openDropdownWithRetry(16);
            }
            return window.__awaMinicartUiReady || Promise.resolve();
        }

        if (window.__awaMinicartUiBootstrapping) {
            if (options.openAfterInit && window.__awaMinicartUiReady && typeof window.__awaMinicartUiReady.then === 'function') {
                window.__awaMinicartUiReady.then(function () {
                    openDropdownWithRetry(32);
                });
            }
            return window.__awaMinicartUiReady || Promise.resolve();
        }

        window.__awaMinicartUiBootstrapping = true;

        window.__awaMinicartUiReady = new Promise(function (resolve) {
            function launch() {
                window.setTimeout(function () {
                    // CRITICAL: inject data-bind BEFORE first Magento_Ui/js/core/app require,
                    // because knockout/bootstrap calls ko.applyBindings() as a load side-effect.
                    hydrateDeferredMinicartShell();

                    preloadMinicartTemplates(payload.jsLayout, function () {
                        require(['Magento_Ui/js/core/app'], function (appModule) {
                            var ok = runAppInit(appModule, payload);
                            window.__awaMinicartUiInit = ok;
                            window.__awaMinicartUiBootstrapping = false;
                            if (ok && window.__awaMinicartOpenAfterInit) {
                                openDropdownWithRetry(32);
                            }
                            resolve();
                        }, function () {
                            window.__awaMinicartUiBootstrapping = false;
                            resolve();
                        });
                    });
                }, launchDelayMs);
            }

            if (options.skipCustomerGate) {
                launch();
                return;
            }

            whenCustomerSectionsReady(launch);
        });

        return window.__awaMinicartUiReady;
    }

    return bootstrapMinicartUi;
});
