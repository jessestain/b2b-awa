/**
 * Carrinho mobile: barra fixa com total + CTA (substitui bottom nav removido no checkout mode).
 *
 * @module js/awa-cart-mobile-bar
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    var BAR_CLASS = 'awa-cart-mobile-bar';
    var MOBILE_MQ = '(max-width: 767px)';
    var CHECKOUT_SELECTOR = '.cart-summary .checkout-methods-items .action.primary.checkout';
    var TOTAL_SELECTOR = '.cart-summary .cart-totals .grand.totals .amount .price, ' +
        '.cart-summary .cart-totals .grand.totals .amount strong';
    var META_SELECTOR = '#awa-cart-page-meta .awa-cart-page-meta__stat';

    function getCheckoutButton() {
        return document.querySelector(CHECKOUT_SELECTOR);
    }

    function readTotalLabel() {
        var node = document.querySelector(TOTAL_SELECTOR);

        if (!node) {
            return '';
        }

        return String(node.textContent || '').trim();
    }

    function readCartMetaLabel() {
        var stats = document.querySelectorAll(META_SELECTOR);
        var parts = [];
        var i;

        for (i = 0; i < stats.length; i++) {
            parts.push(String(stats[i].textContent || '').trim());
        }

        return parts.filter(Boolean).join(' · ');
    }

    function readContinueUrl() {
        var link = document.querySelector('.cart.main.actions .action.continue');

        if (!link) {
            return '';
        }

        return link.getAttribute('href') || link.href || '';
    }

    function syncContinueLink(bar) {
        var continueNode = bar.querySelector('.' + BAR_CLASS + '__continue');
        var url = readContinueUrl();

        if (!continueNode) {
            return;
        }

        if (!url) {
            continueNode.hidden = true;
            continueNode.setAttribute('aria-hidden', 'true');

            return;
        }

        continueNode.hidden = false;
        continueNode.setAttribute('aria-hidden', 'false');
        continueNode.href = url;
    }

    function syncBarState(bar) {
        var checkout = getCheckoutButton();
        var amountNode = bar.querySelector('.' + BAR_CLASS + '__amount');
        var countNode = bar.querySelector('.' + BAR_CLASS + '__count');
        var cta = bar.querySelector('.' + BAR_CLASS + '__checkout');
        var total = readTotalLabel();
        var metaLabel = readCartMetaLabel();
        var disabled = checkout
            && (checkout.hasAttribute('disabled') || checkout.getAttribute('aria-disabled') === 'true');

        if (amountNode && total) {
            amountNode.textContent = total;
        }

        if (countNode) {
            if (metaLabel) {
                countNode.textContent = metaLabel;
                countNode.hidden = false;
            } else {
                countNode.textContent = '';
                countNode.hidden = true;
            }
        }

        if (!cta || !checkout) {
            return;
        }

        cta.href = checkout.getAttribute('href') || checkout.href || '#';
        cta.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        cta.classList.toggle('disabled', disabled);

        if (disabled) {
            cta.setAttribute('tabindex', '-1');
            var describedBy = checkout.getAttribute('aria-describedby');

            if (describedBy) {
                cta.setAttribute('aria-describedby', describedBy);
            }
        } else {
            cta.removeAttribute('tabindex');
            cta.removeAttribute('aria-describedby');
        }
    }


    function syncSummaryClearance(bar) {
        var summary = document.querySelector('.cart-summary');
        var mq = window.matchMedia(MOBILE_MQ);
        var clearance;
        var top;
        var maxH;
        var barRect;

        if (!summary) {
            return;
        }

        if (!mq.matches || !bar || bar.hidden || !document.body.classList.contains('awa-cart-mobile-bar-active')) {
            if (window.matchMedia('(min-width: 992px)').matches) {
                /* Desktop: não apagar max-height:none do unclamp (AQ). */
                return;
            }
            summary.style.removeProperty('max-height');
            document.documentElement.style.removeProperty('--awa-cart-summary-max-h');
            document.documentElement.style.removeProperty('--awa-cart-mobile-bar-clearance');

            return;
        }

        barRect = bar.getBoundingClientRect();
        clearance = Math.max(60, Math.round(barRect.height)) + 16;
        document.documentElement.style.setProperty('--awa-cart-mobile-bar-clearance', clearance + 'px');
        top = summary.getBoundingClientRect().top;
        maxH = Math.floor(barRect.top - Math.max(top, 0) - 12);

        if (maxH < 160) {
            maxH = 160;
        }

        document.documentElement.style.setProperty('--awa-cart-summary-max-h', maxH + 'px');
        summary.style.maxHeight = maxH + 'px';
        summary.style.overflowY = 'auto';
}

    function setBarVisible(bar, visible) {
        if (!bar) {
            return;
        }

        bar.hidden = !visible;
        bar.setAttribute('aria-hidden', visible ? 'false' : 'true');
        document.body.classList.toggle('awa-cart-mobile-bar-active', visible);
        syncSummaryClearance(bar);
    }

    function bindCheckoutClick(bar) {
        var cta = bar.querySelector('.' + BAR_CLASS + '__checkout');

        if (!cta) {
            return;
        }

        cta.addEventListener('click', function (event) {
            var checkout = getCheckoutButton();

            if (!checkout) {
                return;
            }

            if (checkout.hasAttribute('disabled') || checkout.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
                checkout.focus();

                return;
            }

            if (checkout.tagName === 'BUTTON') {
                event.preventDefault();
                checkout.click();
            }
        });
    }

    function watchSummaryVisibility(bar) {
        var checkout = getCheckoutButton();

        if (!checkout || typeof IntersectionObserver === 'undefined') {
            return;
        }

        var mq = window.matchMedia(MOBILE_MQ);

        var observer = new IntersectionObserver(function (entries) {
            if (!mq.matches) {
                setBarVisible(bar, false);

                return;
            }

            var entry = entries[0];
            var summaryCtaVisible = entry && entry.isIntersecting && entry.intersectionRatio > 0.35;

            setBarVisible(bar, !summaryCtaVisible);
        }, {
            root: null,
            threshold: [0, 0.35, 0.6]
        });

        observer.observe(checkout);

        mq.addEventListener('change', function () {
            if (!mq.matches) {
                setBarVisible(bar, false);
            } else {
                syncBarState(bar);
                setBarVisible(bar, true);
            }
        });
    }

    function buildBar() {
        var bar = document.createElement('div');

        bar.className = BAR_CLASS;
        bar.setAttribute('role', 'region');
        bar.setAttribute('aria-label', 'Resumo rápido do pedido');
        bar.hidden = true;
        bar.setAttribute('aria-hidden', 'true');
        bar.innerHTML =
            '<div class="' + BAR_CLASS + '__inner">'
            + '<a class="' + BAR_CLASS + '__continue action continue" href="#" hidden>'
            + '<span class="' + BAR_CLASS + '__continue-label">' + $t('Continuar comprando') + '</span>'
            + '</a>'
            + '<div class="' + BAR_CLASS + '__meta">'
            + '<span class="' + BAR_CLASS + '__count" hidden></span>'
            + '<span class="' + BAR_CLASS + '__label">' + $t('Total do pedido') + '</span>'
            + '<span class="' + BAR_CLASS + '__amount" aria-live="polite"></span>'
            + '</div>'
            + '<a class="' + BAR_CLASS + '__checkout action primary" href="#">'
            + $t('Finalizar compra')
            + '</a>'
            + '</div>';

        document.body.appendChild(bar);

        return bar;
    }

    return function () {
        if (!document.body.classList.contains('checkout-cart-index') || !document.getElementById('form-validate')) {
            return;
        }

        /* e86806: init pode rodar N vezes (require/contentUpdated) → N barras.
           CDP cart: 3× .awa-cart-mobile-bar no mesmo rect. Reusa a existente. */
        var bar = document.querySelector('body > .' + BAR_CLASS);
        var isNew = !bar;

        if (isNew) {
            bar = buildBar();
            bindCheckoutClick(bar);
            watchSummaryVisibility(bar);
            window.addEventListener('resize', function () {
                syncSummaryClearance(bar);
            }, {passive: true});
            window.addEventListener('scroll', function () {
                syncSummaryClearance(bar);
            }, {passive: true});
        }

        syncContinueLink(bar);
        syncBarState(bar);
        syncSummaryClearance(bar);

        var mq = window.matchMedia(MOBILE_MQ);

        if (mq.matches && typeof IntersectionObserver === 'undefined') {
            setBarVisible(bar, true);
        }

        $(document).off('contentUpdated.awaCartMobileBar').on('contentUpdated.awaCartMobileBar', function () {
            window.setTimeout(function () {
                syncContinueLink(bar);
                syncBarState(bar);
            }, 80);
        });

        if (isNew) {
            var checkoutList = document.querySelector('.checkout-methods-items');

            if (checkoutList && typeof MutationObserver !== 'undefined') {
                var mo = new MutationObserver(function () {
                    syncBarState(bar);
                });

                mo.observe(checkoutList, {
                    attributes: true,
                    subtree: true,
                    attributeFilter: ['disabled', 'aria-disabled', 'class', 'href']
                });
            }
        }
    };
});
