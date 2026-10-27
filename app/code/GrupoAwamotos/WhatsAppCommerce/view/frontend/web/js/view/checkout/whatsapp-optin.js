define([
    'uiComponent',
    'ko',
    'Magento_Customer/js/model/customer',
    'jquery',
    'mage/url'
], function (Component, ko, customer, $, urlBuilder) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'GrupoAwamotos_WhatsAppCommerce/checkout/whatsapp-optin'
        },

        isChecked: ko.observable(false),
        isLoggedIn: customer.isLoggedIn,
        isSaving: ko.observable(false),

        initialize: function () {
            this._super();

            var attributes = (customer.customerData || {}).custom_attributes || {},
                optin = attributes.whatsapp_optin;
            if (Array.isArray(attributes)) {
                optin = attributes.find(function (attribute) {
                    return attribute.attribute_code === 'whatsapp_optin';
                });
            }
            // Hydrate before subscribing: reading consent must never write it.
            this.isChecked = ko.observable(!!optin && Number(optin.value) === 1);
            this.isSaving = ko.observable(false);

            this.isChecked.subscribe(function (newValue) {
                this.saveOptin(newValue ? 1 : 0);
            }.bind(this));

            return this;
        },

        saveOptin: function (value) {
            if (this.isSaving()) {
                return;
            }

            this.isSaving(true);

            $.ajax({
                url: urlBuilder.build('whatsappcommerce/checkout/saveoptin'),
                type: 'POST',
                dataType: 'json',
                data: {
                    optin: value,
                    form_key: $.cookie('form_key')
                },
                complete: function () {
                    this.isSaving(false);
                }.bind(this)
            });
        }
    });
});
