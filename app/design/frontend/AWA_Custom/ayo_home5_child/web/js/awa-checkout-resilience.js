/**
 * Checkout resilience — rede, rate limit, offline e liberação do CTA após falha.
 */
define([
    'jquery',
    'uiRegistry',
    'mage/translate',
    'Magento_Checkout/js/model/full-screen-loader',
    'domReady!'
], function ($, registry, $t, fullScreenLoader) {
    'use strict';

    var BANNER_SELECTOR = '[data-awa-checkout-resilience]';
    var BANNER_ATTR = 'data-awa-checkout-resilience';
    var CHECKOUT_REST_RE = /\/rest\/[^/]+\/V1\/carts\/(?:mine|guest)/i;
    var lastBannerKey = '';
    var hideTimer = null;

    function isCheckoutPage() {
        if (!document.body) {
            return false;
        }

        return document.body.classList.contains('checkout-index-index') ||
            document.body.classList.contains('rokanthemes-onepagecheckout') ||
            document.body.classList.contains('onepagecheckout-index-index');
    }

    function isCheckoutRestUrl(url) {
        return typeof url === 'string' && CHECKOUT_REST_RE.test(url);
    }

    function isPlaceOrderRequest(url, method) {
        if (typeof url !== 'string') {
            return false;
        }

        method = (method || 'GET').toUpperCase();

        return method === 'POST' && (
            url.indexOf('set-payment-information') !== -1 ||
            url.indexOf('payment-information') !== -1 ||
            url.indexOf('place-order') !== -1
        );
    }

    /**
     * @param {number} status
     * @returns {string}
     */
    function messageForStatus(status) {
        if (!window.navigator.onLine || status === 0) {
            return $t('Sem conexão com a internet. Verifique sua rede e tente novamente.');
        }

        if (status === 429) {
            return $t('Muitas atualizações em sequência. Aguarde 5 segundos e tente de novo.');
        }

        if (status === 403) {
            return $t('Você não tem permissão para concluir esta etapa. Atualize a página ou entre novamente.');
        }

        if (status === 401) {
            return $t('Sua sessão expirou. Faça login novamente para continuar o pedido.');
        }

        if (status >= 500) {
            return $t('O servidor está temporariamente indisponível. Tente novamente em instantes.');
        }

        return $t('Não foi possível atualizar os totais. Aguarde alguns segundos ou recarregue a página.');
    }

    function findBannerHost() {
        return $('.checkout-container, .page-main .columns, .opc-wrapper').first();
    }

    function hideBanner() {
        $(BANNER_SELECTOR).remove();
        lastBannerKey = '';
    }

    /**
     * @param {string} message
     * @param {string} bannerKey
     * @returns {void}
     */
    function showBanner(message, bannerKey) {
        if (!message || lastBannerKey === bannerKey) {
            return;
        }

        hideBanner();

        var $host = findBannerHost();

        if (!$host.length) {
            return;
        }

        lastBannerKey = bannerKey;

        var $banner = $(
            '<div class="awa-checkout-resilience" ' + BANNER_ATTR + ' role="alert" aria-live="assertive" tabindex="-1">' +
                '<div class="awa-checkout-resilience__inner">' +
                    '<svg class="awa-checkout-resilience__icon" viewBox="0 0 24 24" fill="none" ' +
                        'stroke="currentColor" stroke-width="2" aria-hidden="true">' +
                        '<circle cx="12" cy="12" r="10"/>' +
                        '<path d="M12 8v5"/>' +
                        '<circle cx="12" cy="16" r="0.5" fill="currentColor" stroke="none"/>' +
                    '</svg>' +
                    '<div class="awa-checkout-resilience__content">' +
                        '<p class="awa-checkout-resilience__text"></p>' +
                        '<div class="awa-checkout-resilience__actions">' +
                            '<button type="button" class="action secondary awa-checkout-resilience__retry">' +
                                '<span>' + $t('Recarregar página do pedido') + '</span>' +
                            '</button>' +
                            '<button type="button" class="action awa-checkout-resilience__dismiss" aria-label="' +
                                $t('Fechar aviso') + '">' +
                                '<span aria-hidden="true">&times;</span>' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        $banner.find('.awa-checkout-resilience__text').text(message);
        $host.prepend($banner);

        if (typeof $banner[0].focus === 'function') {
            $banner[0].focus({ preventScroll: true });
        }

        if ($banner[0] && typeof $banner[0].scrollIntoView === 'function') {
            var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            $banner[0].scrollIntoView({
                block: 'nearest',
                behavior: prefersReducedMotion ? 'auto' : 'smooth'
            });
        }
    }

    function releasePlaceOrderLock() {
        try {
            var component = registry.get('checkout.sidebar.place-order-btn');

            if (component && typeof component.releasePlaceOrderLock === 'function') {
                component.releasePlaceOrderLock();
                return;
            }
        } catch (ignore) {
            // Componente ainda não montado.
        }

        fullScreenLoader.stopLoader();
        $('.btn-placeorder')
            .attr('aria-busy', 'false')
            .removeClass('is-processing');
        $('body').removeClass('_block-content-loading');
    }

    function scheduleHideOnSuccess() {
        window.clearTimeout(hideTimer);
        hideTimer = window.setTimeout(hideBanner, 1200);
    }

    function bindEvents() {
        $(document).on('click.awaCheckoutResilience', '.awa-checkout-resilience__retry', function () {
            window.location.reload();
        });

        $(document).on('click.awaCheckoutResilience', '.awa-checkout-resilience__dismiss', hideBanner);

        $(window).on('offline.awaCheckoutResilience', function () {
            showBanner(
                $t('Conexão perdida. Os totais podem ficar desatualizados. Reconecte antes de finalizar o pedido.'),
                'offline'
            );
        });

        $(window).on('online.awaCheckoutResilience', function () {
            showBanner(
                $t('Conexão restabelecida. A página será recarregada para atualizar os totais.'),
                'online'
            );
            window.setTimeout(function () {
                window.location.reload();
            }, 800);
        });

        $(document).on('ajaxError.awaCheckoutResilience', function (event, xhr, settings) {
            if (!isCheckoutPage() || !isCheckoutRestUrl(settings.url)) {
                return;
            }

            var status = xhr && xhr.status ? xhr.status : 0;
            var message = messageForStatus(status);
            var bannerKey = 'rest-' + status + '-' + settings.url;

            showBanner(message, bannerKey);

            if (isPlaceOrderRequest(settings.url, settings.type)) {
                releasePlaceOrderLock();
            }
        });

        $(document).on('ajaxSuccess.awaCheckoutResilience', function (event, xhr, settings) {
            if (!isCheckoutPage() || !isCheckoutRestUrl(settings.url)) {
                return;
            }

            if (xhr && xhr.status >= 200 && xhr.status < 300) {
                scheduleHideOnSuccess();
            }
        });
    }

    /**
     * @returns {void}
     */
    function bindStuckPlaceOrderWatchdog() {
        var stuckSince = 0;
        var STUCK_THRESHOLD_MS = 60000;

        window.setInterval(function () {
            var isBusy = $('.btn-placeorder[aria-busy="true"]').length > 0;

            // Shipping/totals loaders do not mean an order was submitted.
            if (!isBusy) {
                stuckSince = 0;
                return;
            }

            if (!stuckSince) {
                stuckSince = Date.now();
                return;
            }

            if (Date.now() - stuckSince < STUCK_THRESHOLD_MS) {
                return;
            }

            stuckSince = 0;
            // A slow response does not prove failure. Keep the submission lock
            // until the request lifecycle resolves; never invite a blind retry.
            showBanner(
                $t('A confirmação do pedido está demorando. Não envie novamente. Confira Meus Pedidos ou fale com nossa equipe antes de tentar outra vez.'),
                'stuck-watchdog'
            );
        }, 5000);
    }

    if (isCheckoutPage()) {
        bindEvents();
        bindStuckPlaceOrderWatchdog();

        if (!window.navigator.onLine) {
            showBanner(
                $t('Você está offline. Conecte-se à internet para concluir o pedido.'),
                'offline-boot'
            );
        }
    }

    return {
        releasePlaceOrderLock: releasePlaceOrderLock,
        showBanner: showBanner,
        hideBanner: hideBanner
    };
});
