/**
 * Pedido mínimo B2B no resumo do checkout (Magento + OPC).
 * Usa checkoutConfig.b2bMinOrder + customer-data cart.
 *
 * @module GrupoAwamotos_B2B/js/checkout/min-order-sidebar
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Customer/js/customer-data',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/totals'
], function ($, $t, customerData, quote, totalsModel) {
    'use strict';

    var ROOT_SELECTOR = '.awa-b2b-min-order-progress[data-awa-component="checkout-sidebar-min-order"]';
    var cartSection = customerData.get('cart');
    var scheduledSync = false;
    var syncDebounceTimer = null;
    var observerDebounceTimer = null;
    var SYNC_DEBOUNCE_MS = 100;

    function scheduleSync()
    {
        if (scheduledSync) {
            return;
        }

        scheduledSync = true;

        (window.requestAnimationFrame || window.setTimeout)(function () {
            scheduledSync = false;
            sync();
        });
    }

    function scheduleSyncDebounced()
    {
        window.clearTimeout(syncDebounceTimer);
        syncDebounceTimer = window.setTimeout(function () {
            syncDebounceTimer = null;
            scheduleSync();
        }, SYNC_DEBOUNCE_MS);
    }

    /**
     * @param {jQuery} $root
     * @param {number} percent
     * @returns {void}
     */
    function applyFillProgress($root, percent)
    {
        var scale = Math.max(0, Math.min(1, (parseFloat(percent) || 0) / 100));

        $root.find('[data-role="fill"]').each(function () {
            this.style.setProperty('--awa-min-order-scale', String(scale));
        });
    }

    function normalizeLocale(locale)
    {
        var normalized = String(locale || '').replace('_', '-').trim();

        return normalized || 'pt-BR';
    }

    function formatCurrencyLocalized(amount, currencyCode, locale)
    {
        var value = Math.max(0, parseFloat(amount) || 0);
        var safeCurrency = String(currencyCode || '').trim() || 'BRL';
        var safeLocale = normalizeLocale(locale);

        if (window.Intl && window.Intl.NumberFormat) {
            try {
                return new window.Intl.NumberFormat(safeLocale, {
                    style: 'currency',
                    currency: safeCurrency,
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(value);
            } catch (e) {
                // fallback abaixo mantém UX estável.
            }
        }

        return 'R$ ' + value.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getConfig()
    {
        var cfg = window.checkoutConfig || {};

        return cfg.b2bMinOrder || null;
    }

    function buildHtml()
    {
        return '<section class="awa-b2b-min-order-progress awa-b2b-min-order-progress--checkout" ' +
            'role="status" aria-live="polite" aria-label="' + $t('Progresso do pedido mínimo B2B') + '" ' +
            'data-awa-component="checkout-sidebar-min-order" hidden aria-hidden="true">' +
            '<div class="awa-b2b-min-order-progress__header">' +
            '<span class="awa-b2b-min-order-progress__label">' + $t('Pedido mínimo B2B') + '</span>' +
            '<span class="awa-b2b-min-order-progress__percent" data-role="percent">0%</span>' +
            '</div>' +
            '<div class="awa-b2b-min-order-progress__track" aria-hidden="true">' +
            '<span class="awa-b2b-min-order-progress__fill" data-role="fill" style="--awa-min-order-scale:0"></span>' +
            '</div>' +
            '<p class="awa-b2b-min-order-progress__message" data-role="message"></p>' +
            '</section>';
    }

    function findTarget()
    {
        var $summary = $('#opc-sidebar .opc-block-summary, .opc-sidebar .opc-block-summary').first();

        if ($summary.length) {
            return $summary;
        }

        return $('.opc-sidebar, #opc-sidebar').first();
    }

    function render($root, minAmount, subtotal, locale, currencyCode)
    {
        var remaining = Math.max(0, minAmount - subtotal);
        var percent = minAmount > 0 ? Math.min(100, Math.round((subtotal / minAmount) * 100)) : 100;

        if (subtotal <= 0 || remaining <= 0.009) {
            $root.attr('hidden', 'hidden').attr('aria-hidden', 'true');
            return;
        }

        var message = $t('Faltam %1 para atingir o pedido mínimo de %2.')
            .replace('%1', formatCurrencyLocalized(remaining, currencyCode, locale))
            .replace('%2', formatCurrencyLocalized(minAmount, currencyCode, locale));

        $root.removeAttr('hidden').removeAttr('aria-hidden');
        $root.toggleClass('awa-b2b-min-order-progress--near', percent >= 80);
        $root.find('[data-role="percent"]').text(percent + '%');
        applyFillProgress($root, percent);
        $root.find('[data-role="message"]').text(message);
    }

    function ensureRoot()
    {
        var $target = findTarget();

        if (!$target.length) {
            return $();
        }

        // Garante instância única: o alvo muda quando o resumo (.opc-block-summary)
        // renderiza após o sidebar. Procuramos o root globalmente e removemos duplicatas
        // em vez de inserir um novo a cada troca de alvo.
        var $existing = $(ROOT_SELECTOR);

        if ($existing.length > 1) {
            $existing.slice(1).remove();
            $existing = $(ROOT_SELECTOR);
        }

        var $title = $target.find('.title').first();

        if ($existing.length) {
            if ($title.length) {
                if (!$title.next(ROOT_SELECTOR).length) {
                    $title.after($existing.detach());
                }
            } else if (!$target.children(ROOT_SELECTOR).length) {
                $target.prepend($existing.detach());
            }

            return $(ROOT_SELECTOR);
        }

        if ($title.length) {
            $title.after(buildHtml());
        } else {
            $target.prepend(buildHtml());
        }

        return $(ROOT_SELECTOR);
    }

    function getCheckoutSubtotal()
    {
        var segment = totalsModel.getSegment('subtotal');

        if (segment && segment.value !== undefined && segment.value !== null) {
            var fromSegment = parseFloat(segment.value);

            if (!isNaN(fromSegment) && fromSegment > 0) {
                return fromSegment;
            }
        }

        var quoteTotals = quote.totals();

        if (quoteTotals && quoteTotals.subtotal !== undefined && quoteTotals.subtotal !== null) {
            var fromQuote = parseFloat(quoteTotals.subtotal);

            if (!isNaN(fromQuote) && fromQuote > 0) {
                return fromQuote;
            }
        }

        var cart = cartSection();

        if (cart && cart.subtotalAmount !== undefined && cart.subtotalAmount !== null) {
            return parseFloat(cart.subtotalAmount) || 0;
        }

        return 0;
    }

    function sync()
    {
        var config = getConfig();

        if (!config || !config.enabled || !(parseFloat(config.minAmount) > 0)) {
            $(ROOT_SELECTOR).attr('hidden', 'hidden');
            return;
        }

        var minAmount = parseFloat(config.minAmount) || 0;
        var locale = normalizeLocale(config.locale);
        var currencyCode = String(config.currencyCode || '').trim() || 'BRL';
        var $root = ensureRoot();

        if (!$root.length) {
            return;
        }

        render($root, minAmount, getCheckoutSubtotal(), locale, currencyCode);
    }

    function isCheckoutPage()
    {
        if (!document.body) {
            return false;
        }

        return document.body.classList.contains('checkout-index-index') ||
            document.body.classList.contains('rokanthemes-onepagecheckout') ||
            document.body.classList.contains('onepagecheckout-index-index');
    }

    return function () {
        if (!isCheckoutPage()) {
            return;
        }

        sync();

        if (!window.__awaB2bMinOrderSidebarCartSubscribed) {
            window.__awaB2bMinOrderSidebarCartSubscribed = true;
            cartSection.subscribe(scheduleSyncDebounced);
        }

        if (!window.__awaB2bMinOrderSidebarTotalsSubscribed) {
            window.__awaB2bMinOrderSidebarTotalsSubscribed = true;
            quote.totals.subscribe(scheduleSyncDebounced);
        }

        if (window.MutationObserver && !window.__awaB2bMinOrderSidebarObserver) {
            var target = document.querySelector('#opc-sidebar .opc-block-summary, .opc-sidebar .opc-block-summary') ||
                document.querySelector('#opc-sidebar, .opc-sidebar');

            if (target) {
                window.__awaB2bMinOrderSidebarObserver = new window.MutationObserver(function () {
                    window.clearTimeout(observerDebounceTimer);
                    observerDebounceTimer = window.setTimeout(function () {
                        observerDebounceTimer = null;
                        scheduleSyncDebounced();
                    }, SYNC_DEBOUNCE_MS);
                });
                window.__awaB2bMinOrderSidebarObserver.observe(target, {
                    childList: true,
                    subtree: false
                });
            }
        }
    };
});
