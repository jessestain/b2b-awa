define(['jquery', 'Magento_Ui/js/modal/alert', 'mage/translate'], function ($, alert, $t) {
    'use strict';
    return function (Widget) {
        $.widget('mage.updateShoppingCart', Widget, {
            _awaNotice: function (message, busy) {
                this.element.attr('aria-busy', busy ? 'true' : 'false');
                this.element.data('awaQtyPhase', busy ? 'validating' : 'idle');
                $('#awa-cart-form-status').text(message);
            },
            onSubmit: function (event) {
                if (this._awaCommitting) { event.preventDefault(); return false; }
                if (this.element.find(this.options.updateCartActionContainer).val() === 'empty_cart') {
                    event.preventDefault();
                    if (this._awaValidation) { this._awaValidation = null; $(document.body).trigger('processStop'); }
                    this.submitForm();
                    return false;
                }
                return this._super(event);
            },
            validateItems: function (url) {
                var self = this;
                if (this._awaCommitting || this._awaValidation) { return; }
                var snapshot = this.element.serialize();
                var token = {};
                this._awaValidation = token;
                this._awaNotice($t('Validando quantidades…'), true);
                $(document.body).trigger('processStart');
                function finish() {
                    if (self._awaValidation !== token) { return false; }
                    self._awaValidation = null;
                    $(document.body).trigger('processStop');
                    return true;
                }
                $.ajax({url: url, data: snapshot, type: 'post', dataType: 'json', timeout: 15000})
                    .done(function (response) {
                        if (!finish()) { return; }
                        // A response validates only the values sent, never later edits.
                        if (self.element.serialize() !== snapshot) {
                            if (self.isValid()) { self.validateItems(url); }
                            else { self._awaNotice($t('Revise a quantidade informada.'), false); }
                            return;
                        }
                        if (!response || response.success !== true) {
                            self._awaNotice($t('Quantidade não aceita. Revise os valores e atualize o carrinho.'), false);
                            return;
                        }
                        self.element.data('awaQtyPhase', 'submitting');
                        self.element.attr('aria-busy', 'true');
                        $('#awa-cart-form-status').text($t('Atualizando carrinho…'));
                        // Original Magento submitForm owns the native form submission.
                        self.submitForm();
                    })
                    .fail(function () {
                        if (!finish()) { return; }
                        // No fallback POST and no automatic retry after a lost response.
                        self._awaNotice($t('Não foi possível validar. Seus valores foram preservados; tente atualizar o carrinho novamente.'), false);
                    });
            },
            submitForm: function () {
                if (this._awaCommitting) { return; }
                this._awaCommitting = true;
                this.element.data('awaQtyPhase', 'submitting');
                $(document.body).trigger('processStart');
                // Keep the guarded submit handler installed for repeated clicks.
                window.HTMLFormElement.prototype.submit.call(this.element[0]);
            }
        });
        return $.mage.updateShoppingCart;
    };
});
