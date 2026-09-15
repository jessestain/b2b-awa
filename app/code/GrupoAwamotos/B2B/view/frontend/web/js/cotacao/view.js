define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/confirm'
], function ($, $t, confirmModal) {
    'use strict';

    function lockFormSubmission($form)
    {
        var $submit = $form.find('[type="submit"]');

        if ($form.data('isSubmitting') === true) {
            return false;
        }

        $form.data('isSubmitting', true);
        $submit.prop('disabled', true).attr('aria-busy', 'true').addClass('is-loading');
        return true;
    }

    return function (_config, element) {
        var $root = $(element);

        $root.on('submit', '.b2b-cotacao-actions form', function (event) {
            var $form = $(this);
            var message = $form.find('[type="submit"]').data('confirm') || $t('Confirmar ação?');

            if ($form.data('isSubmitting') === true) {
                event.preventDefault();
                return;
            }

            if ($form.data('confirmAccepted') === true) {
                $form.data('confirmAccepted', false);
                lockFormSubmission($form);
                return;
            }

            event.preventDefault();
            confirmModal({
                title: $t('Confirmar'),
                content: message,
                actions: {
                    confirm: function () {
                        $form.data('confirmAccepted', true);
                        if (!lockFormSubmission($form)) {
                            return;
                        }
                        $form.get(0).submit();
                    }
                }
            });
        });
    };
});
