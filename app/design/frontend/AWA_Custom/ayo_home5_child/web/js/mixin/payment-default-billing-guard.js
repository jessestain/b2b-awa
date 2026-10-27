/**
 * Garante billing utilizável antes de place order (quote pode ter billing vazio/nulo).
 */
define([
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/select-billing-address',
    'underscore'
], function (quote, selectBillingAddressAction, _) {
    'use strict';

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

    return function (Component) {
        return Component.extend({
            /** @inheritdoc */
            initialize: function () {
                this._super();

                quote.billingAddress.subscribe(function () {
                    ensureBillingFromShipping();
                });

                quote.shippingAddress.subscribe(function () {
                    ensureBillingFromShipping();
                });

                ensureBillingFromShipping();

                return this;
            },

            /** @inheritdoc */
            isPlaceOrderActionAllowed: function () {
                ensureBillingFromShipping();

                return this._super() && isBillingAddressUsable(quote.billingAddress());
            }
        });
    };
});
