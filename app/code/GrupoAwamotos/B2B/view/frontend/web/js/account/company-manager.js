define([
    'jquery',
    'mage/translate',
    'mage/cookies',
    'Magento_Ui/js/modal/alert',
    'Magento_Ui/js/modal/confirm'
], function ($, $t, _cookies, alertModal, confirmModal) {
    'use strict';

    function showAlert(message)
    {
        alertModal({
            title: $t('B2B'),
            content: message
        });
    }

    function getFormKey()
    {
        if (window.FORM_KEY) {
            return window.FORM_KEY;
        }

        if ($.mage && $.mage.cookies) {
            return $.mage.cookies.get('form_key') || '';
        }

        return '';
    }

    function isValidEmail(email)
    {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email || '');
    }

    function setBusy($element, isBusy)
    {
        $element.prop('disabled', isBusy).attr('aria-busy', isBusy ? 'true' : 'false');
    }

    function requestAction(url, payload, onSuccess, onFail, onAlways)
    {
        $.ajax({
            url: url,
            type: 'POST',
            dataType: 'json',
            data: payload
        }).done(function (response) {
            if (response && response.message) {
                showAlert(response.message);
            }

            if (response && response.success) {
                onSuccess();
                return;
            }

            if (typeof onFail === 'function') {
                onFail(response || {});
            }
        }).fail(function () {
            if (typeof onFail === 'function') {
                onFail({});
            }
        }).always(function () {
            if (typeof onAlways === 'function') {
                onAlways();
            }
        });
    }

    return function (config, element) {
        var $root = $(element);
        var messages = config.messages || {};
        var manageUrl = config.manageUrl || '';
        var $liveRegion = $root.find('[data-role="company-live"]').first();

        var $addForm = $root.find('#add-user-form');
        var $toggleButton = $root.find('[data-action="toggle-add-user"]');
        var $emailInput = $root.find('#new-user-email');
        var $roleInput = $root.find('#new-user-role');

        function announce(message)
        {
            if (!$liveRegion.length || !message) {
                return;
            }
            $liveRegion.text('');
            window.setTimeout(function () {
                $liveRegion.text(message);
            }, 10);
        }

        if (!manageUrl) {
            return;
        }

        $root.on('click', '[data-action="toggle-add-user"]', function (event) {
            var shouldOpen;

            event.preventDefault();
            shouldOpen = $addForm.hasClass('hidden');
            $addForm.toggleClass('hidden', !shouldOpen).prop('hidden', !shouldOpen);
            $(this).attr('aria-expanded', shouldOpen ? 'true' : 'false');
            announce(shouldOpen ? $t('Formulário de adicionar usuário aberto.') : $t('Formulário de adicionar usuário fechado.'));

            if (shouldOpen) {
                $emailInput.trigger('focus');
            }
        });

        $root.on('submit', '[data-role="company-add-user-form"]', function (event) {
            event.preventDefault();
            $(this).find('[data-action="add-user"]').trigger('click');
        });

        $root.on('click', '[data-action="add-user"]', function () {
            var $button = $(this);
            var email = $.trim($emailInput.val());
            var role = $roleInput.val() || 'buyer';
            var defaultButtonLabel = $button.data('labelDefault') || $.trim($button.text());

            if ($button.prop('disabled')) {
                return;
            }

            if (!email) {
                $emailInput.attr('aria-invalid', 'true').trigger('focus');
                showAlert(messages.emailRequired || $t('Informe o e-mail.'));
                return;
            }

            if (!isValidEmail(email)) {
                $emailInput.attr('aria-invalid', 'true').trigger('focus');
                showAlert(messages.emailInvalid || $t('Informe um e-mail válido.'));
                return;
            }

            $emailInput.attr('aria-invalid', 'false');
            announce($t('Enviando novo usuário para a empresa.'));
            $button.data('labelDefault', defaultButtonLabel).text(messages.processing || $t('Processando...'));
            setBusy($button, true);
            setBusy($emailInput, true);
            setBusy($roleInput, true);

            requestAction(
                manageUrl,
                {
                    action: 'add',
                    customer_id: 0,
                    email: email,
                    role: role,
                    form_key: getFormKey()
                },
                function () {
                    $addForm.addClass('hidden').prop('hidden', true);
                    $toggleButton.attr('aria-expanded', 'false');
                    announce($t('Usuário adicionado com sucesso. Atualizando lista.'));
                    window.location.reload();
                },
                function (response) {
                    announce((response && response.message) ? response.message : (messages.requestError || $t('Erro. Tente novamente.')));
                    if (response && response.message) {
                        return;
                    }
                    showAlert(messages.requestError || $t('Erro. Tente novamente.'));
                },
                function () {
                    setBusy($button, false);
                    setBusy($emailInput, false);
                    setBusy($roleInput, false);
                    $button.text($button.data('labelDefault') || defaultButtonLabel);
                }
            );
        });

        $root.on('change', '.js-user-role', function () {
            var $select = $(this);
            var customerId = parseInt($select.data('customer-id'), 10) || 0;
            var role = $select.val() || 'buyer';

            if (customerId <= 0) {
                return;
            }

            if ($select.prop('disabled')) {
                return;
            }

            setBusy($select, true);

            requestAction(
                manageUrl,
                {
                    action: 'update_role',
                    customer_id: customerId,
                    role: role,
                    form_key: getFormKey()
                },
                function () {
                    announce($t('Papel do usuário atualizado. Recarregando dados.'));
                    window.location.reload();
                },
                function (response) {
                    announce((response && response.message) ? response.message : (messages.requestError || $t('Erro. Tente novamente.')));
                    if (response && response.message) {
                        return;
                    }
                    showAlert(messages.requestError || $t('Erro. Tente novamente.'));
                },
                function () {
                    setBusy($select, false);
                }
            );
        });

        $root.on('click', '[data-action="remove-user"]', function () {
            var $button = $(this);
            var customerId = parseInt($button.data('customer-id'), 10) || 0;

            if (customerId <= 0) {
                return;
            }

            confirmModal({
                title: $t('Confirmar'),
                content: messages.confirmRemove || $t('Remover este usuário da empresa?'),
                actions: {
                    confirm: function () {
                        announce($t('Removendo usuário da empresa.'));
                        setBusy($button, true);
                        requestAction(
                            manageUrl,
                            {
                                action: 'remove',
                                customer_id: customerId,
                                form_key: getFormKey()
                            },
                            function () {
                                announce($t('Usuário removido com sucesso. Atualizando lista.'));
                                window.location.reload();
                            },
                            function (response) {
                                announce((response && response.message) ? response.message : (messages.requestError || $t('Erro. Tente novamente.')));
                                if (response && response.message) {
                                    return;
                                }
                                showAlert(messages.requestError || $t('Erro. Tente novamente.'));
                            },
                            function () {
                                setBusy($button, false);
                            }
                        );
                    }
                }
            });
        });
    };
});
