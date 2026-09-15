define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/confirm'
], function ($, $t, confirmModal) {
    'use strict';

    function submitNativeForm(formElement)
    {
        if (!formElement) {
            return;
        }

        formElement.submit();
    }

    function lockFormSubmission($form)
    {
        var $submit = $form.find('[type="submit"]');

        if ($form.data('isSubmitting') === true) {
            return false;
        }

        $form.data('isSubmitting', true);
        $submit.prop('disabled', true).addClass('is-loading').attr('aria-busy', 'true');
        return true;
    }

    return function (config, element) {
        var $root = $(element);
        var messages = config.messages || {};

        $root.on('submit', '.quote-decision-actions form', function (event) {
            var $form = $(this);
            var $submit = $form.find('[type="submit"]');
            var message = $form.data('confirm') || messages.rejectConfirm || $t('Confirmar ação?');

            if ($form.data('isSubmitting') === true) {
                event.preventDefault();
                return;
            }

            if (!$form.is('[data-confirm]')) {
                lockFormSubmission($form);
                return;
            }

            if ($form.data('confirmAccepted') === true) {
                $form.data('confirmAccepted', false);
                lockFormSubmission($form);
                return;
            }

            event.preventDefault();
            $submit.attr('aria-busy', 'false');
            confirmModal({
                title: $t('Confirmar'),
                content: message,
                actions: {
                    confirm: function () {
                        $form.data('confirmAccepted', true);
                        if (!lockFormSubmission($form)) {
                            return;
                        }
                        submitNativeForm($form.get(0));
                    }
                }
            });
        });
    };
});
