/**
 * OPC place-order: billing sync, CTA sidebar e conclusão via renderer de pagamento.
 */
define([
    'ko',
    'jquery',
    'mage/translate',
    'uiRegistry',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/select-billing-address',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Checkout/js/model/shipping-service',
    'Magento_Checkout/js/action/select-shipping-method',
    'Rokanthemes_OnePageCheckout/js/action/validate-shipping-information',
    'underscore'
], function (
    ko,
    $,
    $t,
    registry,
    quote,
    selectBillingAddressAction,
    fullScreenLoader,
    additionalValidators,
    shippingService,
    selectShippingMethodAction,
    validateShippingInformationAction,
    _
) {
    'use strict';

    var PLACE_ORDER_AJAX_RE = /payment-information|place-order|set-payment-information/i;
    var PLACE_ORDER_AJAX_TIMEOUT_MS = 90000;

    /**
     * @param {Object|null} address
     * @returns {boolean}
     */
    function isBillingAddressUsable(address) {
        var streetLine = '';

        if (!address) {
            return false;
        }

        if (!_.isUndefined(address.street) && address.street !== null) {
            streetLine = Array.isArray(address.street) ?
                String(address.street[0] || '').trim() :
                String(address.street).trim();
        }

        return Boolean(
            String(address.firstname || '').trim() &&
            String(address.lastname || '').trim() &&
            String(address.city || '').trim() &&
            String(address.postcode || '').trim() &&
            String(address.telephone || '').trim() &&
            String(address.countryId || '').trim() &&
            streetLine
        );
    }

    /**
     * @returns {void}
     */
    var ensureBillingSyncGuard = false;

    function ensureBillingFromShipping() {
        var shippingAddress = quote.shippingAddress();

        if (quote.isVirtual() || !shippingAddress) {
            return;
        }

        // Guarda de reentrancia (23/10): selectBillingAddressAction muta quote.billingAddress(),
        // que re-dispara a subscription syncAllowed. Endereco de entrega incompleto causava
        // loop infinito -> RangeError: Maximum call stack size exceeded.
        if (ensureBillingSyncGuard || !isBillingAddressUsable(shippingAddress)) {
            return;
        }

        if (!isBillingAddressUsable(quote.billingAddress())) {
            ensureBillingSyncGuard = true;
            try {
                selectBillingAddressAction(shippingAddress);
            } finally {
                ensureBillingSyncGuard = false;
            }
        }
    }

    /**
     * @returns {void}
     */
    function ensureShippingMethodFromRates() {
        var shippingMethod = quote.shippingMethod();
        var rates;
        var validRates;

        if (shippingMethod && shippingMethod.carrier_code && shippingMethod.method_code) {
            return;
        }

        rates = shippingService.getShippingRates()();
        validRates = Array.isArray(rates) ? rates.filter(function (rate) {
            return rate && rate.carrier_code && rate.method_code && !rate.error_message;
        }) : [];

        if (validRates.length) {
            selectShippingMethodAction(validRates[0]);
        }
    }

    /**
     * @returns {boolean}
     */
    function readCanPlaceOrder() {
        if (quote.isVirtual()) {
            return quote.paymentMethod() != null && isBillingAddressUsable(quote.billingAddress());
        }

        return quote.paymentMethod() != null &&
            quote.shippingMethod() != null &&
            isBillingAddressUsable(quote.billingAddress());
    }

    /**
     * @param {Function} target
     * @returns {void}
     */
    function syncPlaceOrderAllowed(target) {
        ensureBillingFromShipping();
        ensureShippingMethodFromRates();
        target(readCanPlaceOrder());
    }

    /**
     * @returns {boolean}
     */
    function canPlaceOrder() {
        ensureBillingFromShipping();
        ensureShippingMethodFromRates();

        return readCanPlaceOrder();
    }

    /**
     * @param {boolean} isBusy
     * @returns {void}
     */
    function setPlaceOrderBusyState(isBusy) {
        $('.btn-placeorder')
            .attr('aria-busy', isBusy ? 'true' : 'false')
            .toggleClass('is-processing', isBusy);
    }

    /**
     * @returns {jQuery}
     */
    function findPlaceOrderToolbar() {
        return $('.awa-place-order-toolbar, #opc-sidebar .actions-toolbar, .opc-sidebar .actions-toolbar').first();
    }

    /**
     * @param {string} message
     * @returns {void}
     */
    function showInlinePlaceOrderError(message) {
        var text = $.trim(message || '');

        if (!text) {
            $('#awa-place-order-inline-error').attr('hidden', 'hidden').empty();
            return;
        }

        var $toolbar = findPlaceOrderToolbar();
        var $region = $('#awa-place-order-inline-error');

        if (!$region.length) {
            $region = $(
                '<div id="awa-place-order-inline-error" class="awa-b2b-place-order-error message message-error error" ' +
                'role="alert" aria-live="assertive"></div>'
            );
            $toolbar.before($region);
        }

        $region
            .removeAttr('hidden')
            .html('<div>' + $('<span/>').text(text).html() + '</div>');

        if ($region[0] && typeof $region[0].scrollIntoView === 'function') {
            $region[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    /**
     * Feedback visível quando validadores adicionais bloqueiam o CTA.
     *
     * @returns {void}
     */
    function showValidatorBlockFeedback() {
        var $checkbox = $('#b2b-terms-checkbox');
        var $terms = $('.b2b-terms-container[data-awa-component="b2b-terms"]');

        if ($checkbox.length && !$checkbox.prop('checked')) {
            $terms.addClass('b2b-terms-container--error');

            if ($terms.length && typeof $terms[0].scrollIntoView === 'function') {
                $terms[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            showInlinePlaceOrderError(
                $t('Aceite os termos B2B na etapa Pagamento para concluir o pedido.')
            );

            return;
        }

        var $firstError = $('.field._error:visible, .message-error:visible, .mage-error:visible').first();

        if ($firstError.length && typeof $firstError[0].scrollIntoView === 'function') {
            $firstError[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        showInlinePlaceOrderError(
            $t('Revise os campos destacados antes de concluir o pedido.')
        );
    }

    /**
     * @returns {Object|null}
     */
    function resolveBillingAddressComponent() {
        var paymentMethod = quote.paymentMethod(),
            code = paymentMethod && paymentMethod.method,
            candidates = [
                'checkout.steps.billing-step.payment.payments-list.billing-address-form-shared'
            ],
            i;

        if (code) {
            candidates.unshift(
                'checkout.steps.billing-step.payment.payments-list.' + code + '-form'
            );
        }

        for (i = 0; i < candidates.length; i++) {
            try {
                return registry.get(candidates[i]);
            } catch (e) {
                // uiRegistry throws when component is missing
            }
        }

        return null;
    }

    /**
     * @returns {Object|null}
     */
    function resolvePaymentRenderer() {
        var code = quote.paymentMethod() && quote.paymentMethod().method;

        if (!code) {
            return null;
        }

        try {
            return registry.get('checkout.steps.billing-step.payment.payments-list.' + code);
        } catch (e) {
            return null;
        }
    }

    /**
     * @param {Object} component
     * @param {string} [message]
     * @returns {void}
     */
    function finalizePlaceOrderAttempt(component, message) {
        fullScreenLoader.stopLoader();
        setPlaceOrderBusyState(false);
        component._placeOrderContinueStarted = false;
        component.releasePlaceOrderLock();

        if (message) {
            showInlinePlaceOrderError(message);
        }
    }

    /**
     * @param {Object} component
     * @returns {void}
     */
    function bindPlaceOrderAjaxCompletion(component) {
        var completed = false;

        /**
         * @returns {void}
         */
        var finish = function () {
            if (completed) {
                return;
            }

            completed = true;
            $(document).off('ajaxComplete.awaOpcPlaceOrder');
            window.clearTimeout(component._placeOrderAjaxTimeout);
            finalizePlaceOrderAttempt(component);
        };

        $(document).on('ajaxComplete.awaOpcPlaceOrder', function (event, xhr, settings) {
            if (!settings || !settings.url || String(settings.type || '').toUpperCase() !== 'POST') {
                return;
            }

            if (PLACE_ORDER_AJAX_RE.test(settings.url)) {
                finish();
            }
        });

        component._placeOrderAjaxTimeout = window.setTimeout(function () {
            if (completed) {
                return;
            }

            // Elapsed time is not proof that the server rejected the order.
            // Keep the submission lock and listener until the request completes.
            component._placeOrderAjaxTimeout = null;
            fullScreenLoader.stopLoader();
            showInlinePlaceOrderError(
                $t('A confirmação do pedido está demorando. Não envie novamente. Confira Meus Pedidos ou fale com nossa equipe antes de tentar outra vez.')
            );
        }, PLACE_ORDER_AJAX_TIMEOUT_MS);
    }

    /**
     * @param {Object} component
     * @param {Object|null} paymentRenderer
     * @param {*} rendererResult
     * @returns {void}
     */
    function bindRendererCompletion(component, paymentRenderer, rendererResult) {
        var deferred;

        if (rendererResult && typeof rendererResult.always === 'function') {
            deferred = rendererResult;
        } else if (rendererResult === false || rendererResult == null) {
            finalizePlaceOrderAttempt(
                component,
                $t('Não foi possível iniciar o pedido. Verifique pagamento e termos, depois tente novamente.')
            );

            return;
        } else {
            bindPlaceOrderAjaxCompletion(component);

            return;
        }

        deferred.done(function () {
            component._placeOrderRedirectPending = true;
        });

        deferred.always(function () {
            if (component._placeOrderRedirectPending) {
                component._isPlacingOrder = false;
                component._placeOrderContinueStarted = false;
                component._placeOrderRedirectPending = false;

                return;
            }

            finalizePlaceOrderAttempt(component);
        });

        if (typeof deferred.fail === 'function') {
            deferred.fail(function () {
                component._placeOrderRedirectPending = false;
                showInlinePlaceOrderError(
                    $t('Não foi possível finalizar o pedido. Tente novamente ou recarregue a página.')
                );
            });
        }
    }

    return function (Component) {
        return Component.extend({
            /** @inheritdoc */
            initialize: function () {
                this._super();
                this._isPlacingOrder = false;
                this._placeOrderContinueStarted = false;
                this._placeOrderAjaxTimeout = null;

                var forceHidden = ko.observable(false);
                var previousVisible = this.isVisible;

                if (ko.isObservable(previousVisible)) {
                    previousVisible.subscribe(function (visible) {
                        forceHidden(!visible);
                    });
                    forceHidden(!previousVisible());
                }

                this.isVisible = ko.pureComputed(function () {
                    return !forceHidden();
                });

                var allowed = ko.observable(false);
                var syncAllowed = function () {
                    syncPlaceOrderAllowed(allowed);
                };

                quote.billingAddress.subscribe(syncAllowed);
                quote.paymentMethod.subscribe(syncAllowed);
                quote.shippingMethod.subscribe(syncAllowed);
                quote.shippingAddress.subscribe(syncAllowed);
                shippingService.getShippingRates().subscribe(syncAllowed);
                syncAllowed();

                this.isPlaceOrderActionAllowed = allowed;

                return this;
            },

            /**
             * @returns {void}
             */
            releasePlaceOrderLock: function () {
                var paymentRenderer = resolvePaymentRenderer();

                this._isPlacingOrder = false;
                this._placeOrderContinueStarted = false;

                if (this._placeOrderAjaxTimeout) {
                    window.clearTimeout(this._placeOrderAjaxTimeout);
                    this._placeOrderAjaxTimeout = null;
                }

                $(document).off('ajaxComplete.awaOpcPlaceOrder');
                fullScreenLoader.stopLoader();
                setPlaceOrderBusyState(false);
                syncPlaceOrderAllowed(this.isPlaceOrderActionAllowed);

                if (paymentRenderer) {
                    if (ko.isObservable(paymentRenderer.isPlaceOrderInProgress)) {
                        paymentRenderer.isPlaceOrderInProgress(false);
                    }

                    if (ko.isObservable(paymentRenderer.isPlaceOrderActionAllowed)) {
                        paymentRenderer.isPlaceOrderActionAllowed(true);
                    }
                }
            },

            /** @inheritdoc */
            placeOrder: function (data, event) {
                var self = this;
                var shippingAddressComponent;
                var canPlace;
                var validatorsOk;

                if (self._isPlacingOrder) {
                    return false;
                }

                ensureBillingFromShipping();
                ensureShippingMethodFromRates();

                canPlace = canPlaceOrder();

                if (!canPlace) {
                    return false;
                }

                validatorsOk = additionalValidators.validate();

                if (!validatorsOk) {
                    showValidatorBlockFeedback();
                    return false;
                }

                showInlinePlaceOrderError('');

                if (event) {
                    event.preventDefault();
                }

                self._isPlacingOrder = true;
                self.isPlaceOrderActionAllowed(false);
                setPlaceOrderBusyState(true);

                if (quote.isVirtual()) {
                    bindPlaceOrderAjaxCompletion(self);
                    self._super(data, event);
                    return false;
                }

                if (typeof window.shippingAddress !== 'undefined' && !$.isEmptyObject(window.shippingAddress)) {
                    bindPlaceOrderAjaxCompletion(self);
                    self._super(data, event);
                    return false;
                }

                try {
                    shippingAddressComponent = registry.get('checkout.steps.shipping-step.shippingAddress');
                } catch (ignore) {
                    finalizePlaceOrderAttempt(
                        self,
                        $t('Etapa de entrega indisponível. Recarregue a página e tente novamente.')
                    );
                    return false;
                }

                if (!shippingAddressComponent.validateShippingInformation()) {
                    finalizePlaceOrderAttempt(
                        self,
                        $t('Revise o endereço de entrega antes de concluir o pedido.')
                    );
                    return false;
                }

                self.placeOrderContinue();

                return false;
            },

            /** @inheritdoc */
            placeOrderContinue: function () {
                var self = this;

                if (self._isPlacingOrder && self._placeOrderContinueStarted) {
                    return;
                }

                self._placeOrderContinueStarted = true;
                self._isPlacingOrder = true;
                self.isPlaceOrderActionAllowed(false);

                var billingAddressComponent = resolveBillingAddressComponent();

                ensureBillingFromShipping();
                ensureShippingMethodFromRates();

                if (billingAddressComponent &&
                    typeof billingAddressComponent.isAddressSameAsShipping === 'function' &&
                    billingAddressComponent.isAddressSameAsShipping()) {
                    selectBillingAddressAction(quote.shippingAddress());
                }

                validateShippingInformationAction().done(function () {
                    var paymentRenderer = resolvePaymentRenderer();
                    var rendererResult = null;
                    var paymentCode = quote.paymentMethod() && quote.paymentMethod().method;

                    if (paymentRenderer && typeof paymentRenderer.placeOrder === 'function') {
                        rendererResult = paymentRenderer.placeOrder(null, null);
                        bindRendererCompletion(self, paymentRenderer, rendererResult);
                        return;
                    }

                    var $fallbackBtn = $('input#' + paymentCode)
                        .closest('.payment-method')
                        .find('.payment-method-content .actions-toolbar button.action.checkout')
                        .first();

                    if ($fallbackBtn.length) {
                        $fallbackBtn.trigger('click');
                        bindPlaceOrderAjaxCompletion(self);
                        return;
                    }

                    finalizePlaceOrderAttempt(
                        self,
                        $t('Forma de pagamento indisponível. Recarregue a página e tente novamente.')
                    );
                }).fail(function () {
                    finalizePlaceOrderAttempt(
                        self,
                        $t('Não foi possível validar o frete. Revise a transportadora e tente novamente.')
                    );
                });
            }
        });
    };
});
