/**
 * Carrinho AWA — runtime único (1 mage-init, 1 árvore RequireJS).
 *
 * @module js/awa-cart-runtime
 */
define([
    'jquery',
    'js/awa-cart-summary-polish',
    'js/awa-cart-table-a11y',
    'js/awa-cart-min-order-live',
    'js/awa-cart-form-feedback',
    'js/awa-cart-qty-auto-update',
    'js/awa-cart-mobile-bar',
    'js/awa-cart-page-meta-sync',
    'Magento_Customer/js/customer-data'
], function ($, summaryPolish, tableA11y, minOrderLive, formFeedback, qtyAutoUpdate, mobileBar, pageMetaSync, customerData) {
    'use strict';

    /**
     * Luma/theme.js legado chama mage/sticky em .cart-summary (top inline / _sticky).
     * No carrinho usamos só CSS position:sticky — destruir widget e limpar inline.
     */
    function neutralizeLegacyCartSticky() {
        var $summary = $('.cart-summary, #cart-summary');

        if (!$summary.length) {
            return;
        }

        $summary.each(function () {
            var el = this;
            var $el = $(el);
            var stickyInstance;

            try {
                stickyInstance = $el.data('mageSticky') || $el.data('mage-sticky') || $el.data('sticky');

                if (stickyInstance && typeof stickyInstance.destroy === 'function') {
                    stickyInstance.destroy();
                } else if ($.fn.sticky) {
                    $el.sticky('destroy');
                }
            } catch (e) {
                /* ignore — fallback CSS abaixo */
            }

            $el.removeClass('_sticky');
            ['top', 'position', 'width', 'left', 'right', 'bottom', 'z-index', 'margin-top'].forEach(function (prop) {
                el.style.removeProperty(prop);
            });

            if (!(el.getAttribute('style') || '').trim()) {
                el.removeAttribute('style');
            }
        });

        /* Sempre reaplicar: se só inview=true, o clamp 789px volta no desktop. */
        applyCartSummaryFooterStop(
            !!(document.body && document.body.classList.contains('awa-cart-footer-inview'))
        );
    }

    /**
     * Inline !important — única forma de vencer sticky CSS + mage/sticky residual.
     *
     * @param {boolean} active
     */
    function applyCartSummaryFooterStop(active) {
        var nodes = document.querySelectorAll('#cart-summary, .cart-container .cart-summary');
        var i;
        var el;

        for (i = 0; i < nodes.length; i++) {
            el = nodes[i];

            if (active) {
                /* max-height + overflow:visible (align-grid) deixa #block-shipping
                   pintar fora do box e cobrir o footer — soltar o clamp. */
                el.style.setProperty('position', 'static', 'important');
                el.style.setProperty('top', 'auto', 'important');
                el.style.setProperty('bottom', 'auto', 'important');
                el.style.setProperty('left', 'auto', 'important');
                el.style.setProperty('max-height', 'none', 'important');
                el.style.setProperty('height', 'auto', 'important');
                el.style.setProperty('overflow', 'visible', 'important');
                el.style.setProperty('overflow-y', 'visible', 'important');
                el.style.setProperty('z-index', 'auto', 'important');
                el.classList.remove('_sticky');
            } else if (window.matchMedia('(min-width: 992px)').matches) {
                /* AQ: max-height viewport + overflow auto (H29/inline) cortava
                   #block-shipping e cupom. CDP 1286: 777 vs scrollH 1126.
                   Soltar o clamp: o grid cresce e o footer desce. */
                el.style.removeProperty('position');
                el.style.removeProperty('top');
                el.style.removeProperty('bottom');
                el.style.removeProperty('left');
                el.style.removeProperty('z-index');
                el.style.setProperty('max-height', 'none', 'important');
                el.style.setProperty('height', 'auto', 'important');
                el.style.setProperty('overflow', 'visible', 'important');
                el.style.setProperty('overflow-x', 'visible', 'important');
                el.style.setProperty('overflow-y', 'visible', 'important');
            } else {
                el.style.removeProperty('position');
                el.style.removeProperty('top');
                el.style.removeProperty('bottom');
                el.style.removeProperty('left');
                el.style.removeProperty('max-height');
                el.style.removeProperty('height');
                el.style.removeProperty('z-index');
                el.style.setProperty('overflow-x', 'hidden', 'important');
                el.style.setProperty('overflow-y', 'auto', 'important');
                el.style.setProperty('overflow', 'hidden auto', 'important');
            }
        }
    }

    /**
     * Sticky CSS para ao aproximar o footer (evita sobrepor trust bar / colunas).
     */
    function bootCartFooterStickyGuard() {
        if (window.__awaCartFooterStickyGuard) {
            return;
        }

        window.__awaCartFooterStickyGuard = 1;

        var footer = document.querySelector('footer.page-footer, .page_footer, .page-footer');

        if (!footer || !window.IntersectionObserver || !document.body) {
            return;
        }

        function onFooterProximity(isNear) {
            document.body.classList.toggle('awa-cart-footer-inview', isNear);
            neutralizeLegacyCartSticky();
            applyCartSummaryFooterStop(isNear);
        }

        /* rootMargin pequeno: 70% deixava inview=true já no topo da página. */
        new IntersectionObserver(function (entries) {
            var entry = entries && entries[0];

            if (!entry) {
                return;
            }

            onFooterProximity(entry.isIntersecting);
        }, {
            root: null,
            rootMargin: '0px 0px 96px 0px',
            threshold: 0
        }).observe(footer);

        /* Fallback scroll: para sticky quando o aside atingiria o footer. */
        var ticking = false;

        function checkFooterByScroll() {
            ticking = false;
            var fr = footer.getBoundingClientRect();
            var summary = document.querySelector('#cart-summary, .cart-container .cart-summary');
            var sr = summary ? summary.getBoundingClientRect() : null;
            var near = fr.top < (window.innerHeight - 24);

            if (sr) {
                near = near || fr.top < (sr.bottom + 12);
            }

            onFooterProximity(near);
        }

        window.addEventListener('scroll', function () {
            if (ticking) {
                return;
            }

            ticking = true;
            window.requestAnimationFrame(checkFooterByScroll);
        }, {passive: true});

        checkFooterByScroll();
    }

    /**
     * mage/sticky pode inicializar depois do runtime (theme.js async) — re-neutraliza.
     */
    function watchLegacyCartSticky() {
        var attempts = 0;
        var maxAttempts = 24;
        var timer;

        function tick() {
            neutralizeLegacyCartSticky();
            attempts += 1;

            if (attempts >= maxAttempts) {
                if (timer) {
                    window.clearInterval(timer);
                }
            }
        }

        tick();
        timer = window.setInterval(tick, 400);
        $(document).on('contentUpdated.awaCartStickyKill', function () {
            neutralizeLegacyCartSticky();
        });
    }

    function clearStaleCartBadges() {
        document.querySelectorAll('.awa-header-cart-link .awa-cart-link-badge').forEach(function (badge) {
            badge.textContent = '';
            badge.style.display = 'none';
            badge.classList.add('awa-badge-hidden');
            badge.setAttribute('aria-hidden', 'true');
        });

        document.querySelectorAll(
            '[data-awa-header-minicart-shell="true"] .counter.qty, .awa-header-minicart[data-awa-header-cart="true"] .counter.qty'
        ).forEach(function (counter) {
            counter.classList.add('empty');
            counter.querySelectorAll('.counter-number, .total-mini-cart-item').forEach(function (node) {
                node.textContent = '0';
            });
        });
    }

    /**
     * Carrinho vazio no servidor mas customer-data/localStorage ainda com itens (badge stale).
     */
    function syncEmptyCartCustomerData() {
        if (window.__awaCartEmptySyncDone) {
            return;
        }

        var emptyNode = document.querySelector('[data-awa-cart-empty="1"]');

        if (!emptyNode) {
            return;
        }

        window.__awaCartEmptySyncDone = true;

        var serverCount = parseInt(emptyNode.getAttribute('data-awa-server-items-count') || '0', 10);

        if (isNaN(serverCount)) {
            serverCount = 0;
        }

        var cartSection = customerData.get('cart')();
        var localCount = parseInt(cartSection.summary_count || 0, 10);

        if (serverCount === 0 && localCount > 0) {
            clearStaleCartBadges();
        }

        if (localCount === serverCount) {
            return;
        }

        customerData.invalidate(['cart']);
        customerData.reload(['cart'], true).done(function () {
            if (serverCount === 0) {
                clearStaleCartBadges();
            }
        });
    }

    function bootAll() {
        if (!document.body || !document.body.classList.contains('checkout-cart-index')) {
            return;
        }

        neutralizeLegacyCartSticky();
        bootCartFooterStickyGuard();
        watchLegacyCartSticky();
        summaryPolish();

        if (document.querySelector('[data-awa-cart-empty="1"]')) {
            syncEmptyCartCustomerData();
            return;
        }

        if (document.getElementById('form-validate')) {
            tableA11y();
            minOrderLive();
            formFeedback();
            qtyAutoUpdate();
            mobileBar();
            pageMetaSync();
        }
    }

    /* Boot ao carregar o módulo — não depender só do mage-init. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootAll, {once: true});
    } else {
        bootAll();
    }

    return bootAll;
});
