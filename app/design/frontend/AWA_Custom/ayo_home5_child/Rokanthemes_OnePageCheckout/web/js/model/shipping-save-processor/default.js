define([
    'ko',
    'underscore',
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/resource-url-manager',
    'mage/storage',
    'Rokanthemes_OnePageCheckout/js/model/payment-service-default',
    'Magento_Checkout/js/model/payment/method-converter',
    'Magento_Checkout/js/model/error-processor',
    'Magento_Checkout/js/action/select-billing-address',
    'Rokanthemes_OnePageCheckout/js/model/shipping-save-processor/payload-extender',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/model/shipping-service',
    'Magento_Checkout/js/action/select-shipping-method'
], function (
    ko,
    _,
    $,
    quote,
    resourceUrlManager,
    storage,
    paymentService,
    methodConverter,
    errorProcessor,
    selectBillingAddressAction,
    payloadExtender,
    customer,
    shippingService,
    selectShippingMethodAction
) {
    'use strict';

    var pendingSaveRequest = null;
    var pendingSaveFingerprint = '';

    function getShippingSaveFingerprint(shippingMethod) {
        if (!shippingMethod) {
            return '';
        }

        return String(shippingMethod.carrier_code || '') + '::' + String(shippingMethod.method_code || '');
    }

    function isBillingAddressUsable(billingAddress) {
        var streetLine = '';

        if (!billingAddress) {
            return false;
        }

        if (!_.isUndefined(billingAddress.street) && billingAddress.street !== null) {
            streetLine = Array.isArray(billingAddress.street) ?
                String(billingAddress.street[0] || '').trim() :
                String(billingAddress.street).trim();
        }

        return Boolean(
            String(billingAddress.firstname || '').trim() &&
            String(billingAddress.lastname || '').trim() &&
            String(billingAddress.city || '').trim() &&
            String(billingAddress.postcode || '').trim() &&
            String(billingAddress.telephone || '').trim() &&
            String(billingAddress.countryId || '').trim() &&
            streetLine
        );
    }

    var ensureBillingSyncGuard = false;

    function ensureBillingFromShipping() {
        var shippingAddress = quote.shippingAddress();

        if (quote.isVirtual() || !shippingAddress) {
            return;
        }

        // Guarda de reentrancia (23/10): selectBillingAddressAction muta quote.billingAddress(),
        // re-disparando subscriptions/computed (loop infinito -> RangeError).
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

    return {
        /** @return {jQuery.Deferred} */
        saveShippingInformation: function () {
            var payload,
                billingAddress,
                shippingMethod = quote.shippingMethod();

            ensureBillingFromShipping();
            ensureShippingMethodFromRates();
            billingAddress = quote.billingAddress();
            shippingMethod = quote.shippingMethod();

            if (!shippingMethod || !shippingMethod.method_code || !shippingMethod.carrier_code) {
                return $.Deferred().reject().promise();
            }

            if (!customer.isLoggedIn()) {
                if (billingAddress) {
                    if (!_.isUndefined(billingAddress.street)) {
                        if (billingAddress.street.length === 0) {
                            delete billingAddress.street;
                        }
                    } else {
                        delete billingAddress.street;
                    }
                }
            }

            payload = {
                addressInformation: {
                    shipping_address: quote.shippingAddress(),
                    billing_address: quote.billingAddress(),
                    shipping_method_code: shippingMethod.method_code,
                    shipping_carrier_code: shippingMethod.carrier_code
                }
            };

            payloadExtender(payload);

            var fingerprint = getShippingSaveFingerprint(shippingMethod);

            if (
                pendingSaveRequest &&
                pendingSaveRequest.state &&
                pendingSaveRequest.state() === 'pending' &&
                pendingSaveFingerprint === fingerprint
            ) {
                return pendingSaveRequest;
            }

            pendingSaveFingerprint = fingerprint;
            pendingSaveRequest = storage.post(
                resourceUrlManager.getUrlForSetShippingInformation(quote),
                JSON.stringify(payload)
            ).done(function (response) {
                quote.setTotals(response.totals);
                paymentService.setPaymentMethods(methodConverter(response.payment_methods));
            }).fail(function (response) {
                errorProcessor.process(response);
            }).always(function () {
                if (pendingSaveRequest && pendingSaveRequest.state() !== 'pending') {
                    pendingSaveRequest = null;
                    pendingSaveFingerprint = '';
                }
            });

            return pendingSaveRequest;
        }
    };
});
