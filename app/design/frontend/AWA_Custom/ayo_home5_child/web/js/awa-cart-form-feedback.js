/**
 * Carrinho: feedback acessível ao atualizar quantidades (aria-busy + status).
 *
 * @module js/awa-cart-form-feedback
 */
define(['jquery', 'mage/translate', 'mage/validation'], function ($, $t) {
    'use strict';

    function getStatusNode() {
        return document.getElementById('awa-cart-form-status');
    }

    function announce(message) {
        var node = getStatusNode();

        if (!node) {
            return;
        }

        node.textContent = message;
    }

    function setBusy(isBusy) {
        var form = document.getElementById('form-validate');

        if (!form) {
            return;
        }

        var busyMessage = form.getAttribute('data-awa-busy-message') || 'Atualizando carrinho…';

        if (isBusy) {
            form.setAttribute('aria-busy', 'true');
            announce(busyMessage);

            return;
        }

        form.removeAttribute('aria-busy');
        announce($t('Revise a quantidade informada.'));
    }

    return function () {
        if (!document.body.classList.contains('checkout-cart-index')) {
            return;
        }

        var $form = $('#form-validate');

        if (!$form.length) {
            return;
        }

        $form.off('.awaCartFeedback');

        $form.on('submit.awaCartFeedback', function () {
            /* Magento_Checkout/js/action/update-shopping-cart valida a
               quantidade (mage/validation) e bloqueia o submit sem nunca
               disparar contentUpdated quando é inválida — aqui ficávamos
               com "Atualizando…" preso na tela mesmo sem update real.
               Checar a mesma validação evita marcar "busy" num envio que
               o próprio Magento vai rejeitar antes de qualquer AJAX. */
            if (typeof $form.validation === 'function') {
                try {
                    if ($form.validation().validation('isValid') === false) {
                        setBusy(false);

                        return;
                    }
                } catch (e) {
                    /* mage/validation indisponível — segue o fluxo padrão */
                }
            }

            setBusy(true);
        });

        $form.on('click.awaCartFeedback', '[data-cart-item-update], [data-cart-empty]', function () {
            setBusy(true);
        });

        // Generic DOM updates are not confirmation of a persisted cart update.
        // Magento renders success/errors after its native form submission.

    };
});
