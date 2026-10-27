/**
 * Carrinho: aplica alteração de quantidade após stepper/input (sem clicar em Atualizar).
 *
 * @module js/awa-cart-qty-auto-update
 */
define(['jquery'], function ($) {
    'use strict';

    var DEBOUNCE_MS = 650;
    var timer = null;

    function hasPendingQtyChanges($form) {
        var pending = false;

        $form.find('[data-role="cart-item-qty"]').each(function () {
            var input = this;
            var saved = input.getAttribute('data-item-qty');

            if (saved !== null && String(input.value) !== String(saved)) {
                pending = true;
                return false;
            }
        });

        return pending;
    }

    return function () {
        if (!document.body.classList.contains('checkout-cart-index')) {
            return;
        }

        var $form = $('#form-validate');
        var $actionContainer = $('#update_cart_action_container');

        if (!$form.length || !$actionContainer.length) {
            return;
        }

        document.body.classList.add('awa-cart-auto-qty');
        $form.addClass('awa-cart-form--auto-qty');

        function scheduleUpdate() {
            if (!hasPendingQtyChanges($form)) {
                return;
            }

            if (timer) {
                window.clearTimeout(timer);
            }

            timer = window.setTimeout(function () {
                timer = null;

                if (!hasPendingQtyChanges($form)) {
                    return;
                }

                $actionContainer.attr('name', 'update_cart_action').val('update_qty');
                $form.trigger('submit');
            }, DEBOUNCE_MS);
        }

        document.addEventListener('awa:qty-control:change', function (event) {
            if (!$form[0].contains(event.target)) {
                return;
            }

            scheduleUpdate();
        });

        $form.on('change.awaCartQtyAuto', '[data-role="cart-item-qty"]', function () {
            scheduleUpdate();
        });

        // data-item-qty comes from the server-rendered cart. contentUpdated
        // also fires during validation and must not acknowledge an unsaved edit.

    };
});
