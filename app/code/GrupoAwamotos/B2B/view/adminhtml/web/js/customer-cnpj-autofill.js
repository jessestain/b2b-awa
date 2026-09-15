define([
    'jquery',
    'uiRegistry'
], function ($, uiRegistry) {
    'use strict';

    return function (config) {
        let lookupUrl = (config && config.lookupUrl) ? config.lookupUrl : '';
        let clearCacheUrl = (config && config.clearCacheUrl) ? config.clearCacheUrl : '';
        if (!lookupUrl) {
            return;
        }

        let contexts = [
            {
                key: 'customer_b2b',
                documentType: 'cnpj_only',
                cnpjSelectors: [
                    'input[name="customer[b2b_cnpj]"]',
                    'input[data-index="b2b_cnpj"]'
                ],
                fieldSelectors: {
                    razaoSocial: [
                        'input[name="customer[b2b_razao_social]"]',
                        'input[data-index="b2b_razao_social"]'
                    ],
                    nomeFantasia: [
                        'input[name="customer[b2b_nome_fantasia]"]',
                        'input[data-index="b2b_nome_fantasia"]'
                    ],
                    phone: [
                        'input[name="customer[b2b_phone]"]',
                        'input[data-index="b2b_phone"]'
                    ],
                    taxvat: [
                        'input[name="customer[taxvat]"]',
                        'input[data-index="taxvat"]'
                    ]
                }
        },
            {
                key: 'order_billing',
                documentType: 'cpf_or_cnpj',
                cnpjSelectors: [
                    '#order-billing_address_vat_id',
                    'input[name="order[billing_address][vat_id]"]'
                ],
                forceCountryToBr: true,
                fieldSelectors: {
                    company: ['#order-billing_address_company'],
                    phone: ['#order-billing_address_telephone'],
                    postcode: ['#order-billing_address_postcode'],
                    street0: ['#order-billing_address_street0'],
                    street1: ['#order-billing_address_street1'],
                    city: ['#order-billing_address_city'],
                    region: ['#order-billing_address_region'],
                    regionId: ['#order-billing_address_region_id'],
                    countryId: ['#order-billing_address_country_id']
                }
        },
            {
                key: 'order_shipping',
                documentType: 'cpf_or_cnpj',
                cnpjSelectors: [
                    '#order-shipping_address_vat_id',
                    'input[name="order[shipping_address][vat_id]"]'
                ],
                forceCountryToBr: true,
                fieldSelectors: {
                    company: ['#order-shipping_address_company'],
                    phone: ['#order-shipping_address_telephone'],
                    postcode: ['#order-shipping_address_postcode'],
                    street0: ['#order-shipping_address_street0'],
                    street1: ['#order-shipping_address_street1'],
                    city: ['#order-shipping_address_city'],
                    region: ['#order-shipping_address_region'],
                    regionId: ['#order-shipping_address_region_id'],
                    countryId: ['#order-shipping_address_country_id']
                }
        }
        ];

        let ufToRegionName = {
            AC: 'Acre',
            AL: 'Alagoas',
            AP: 'Amapa',
            AM: 'Amazonas',
            BA: 'Bahia',
            CE: 'Ceara',
            DF: 'Distrito Federal',
            ES: 'Espirito Santo',
            GO: 'Goias',
            MA: 'Maranhao',
            MT: 'Mato Grosso',
            MS: 'Mato Grosso do Sul',
            MG: 'Minas Gerais',
            PA: 'Para',
            PB: 'Paraiba',
            PR: 'Parana',
            PE: 'Pernambuco',
            PI: 'Piaui',
            RJ: 'Rio de Janeiro',
            RN: 'Rio Grande do Norte',
            RS: 'Rio Grande do Sul',
            RO: 'Rondonia',
            RR: 'Roraima',
            SC: 'Santa Catarina',
            SP: 'Sao Paulo',
            SE: 'Sergipe',
            TO: 'Tocantins'
        };

        function cleanDigits(value)
        {
            return (value || '').replace(/\D+/g, '');
        }

        function toUpperText(value)
        {
            return (value || '').toString().toUpperCase();
        }

        function normalizeText(value)
        {
            let text = (value || '').toString();
            if (text.normalize) {
                text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }

            return text.toUpperCase();
        }

        function formatCnpj(digits)
        {
            let value = cleanDigits(digits).slice(0, 14);
            if (value.length <= 2) {
                return value;
            }

            value = value.replace(/^(\d{2})(\d)/, '$1.$2');
            value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
            value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
            value = value.replace(/(\d{4})(\d)/, '$1-$2');

            return value;
        }

        function formatCep(cep)
        {
            let digits = cleanDigits(cep).slice(0, 8);
            if (digits.length <= 5) {
                return digits;
            }

            return digits.slice(0, 5) + '-' + digits.slice(5);
        }

        function formatPhone(phone)
        {
            return (phone || '').toString().trim();
        }

        function formatFieldInput(value, documentType)
        {
            let digits = cleanDigits(value);
            if (!digits) {
                return '';
            }

            if (documentType === 'cnpj_only') {
                return formatCnpj(digits);
            }

            if (digits.length > 11) {
                return formatCnpj(digits);
            }

            return value;
        }

        function getFieldFromSelectors(fieldSelectors, rootElement)
        {
            if (!fieldSelectors || !fieldSelectors.length) {
                return $();
            }

            var $root = rootElement ? $(rootElement) : $();
            for (let i = 0; i < fieldSelectors.length; i++) {
                let selector = fieldSelectors[i];
                var $field = $();

                if ($root.length) {
                    $field = $root.find(selector).first();
                }

                if (!$field.length) {
                    $field = $(selector).first();
                }

                if ($field.length) {
                    return $field;
                }
            }

            return $();
        }

        function collectFields(fieldSelectors)
        {
            let found = [];
            let unique = [];

            for (let i = 0; i < fieldSelectors.length; i++) {
                let selector = fieldSelectors[i];
                $(selector).each(function () {
                    if ($.inArray(this, found) === -1) {
                        found.push(this);
                        unique.push($(this));
                    }
                });
            }

            return unique;
        }

        function upsertHelperElements($cnpjField, contextKey)
        {
            var $control = $cnpjField.closest('.admin__field-control');
            if (!$control.length) {
                $control = $cnpjField.parent();
            }

            let wrapperClass = 'ga-b2b-cnpj-tools-' + contextKey;
            var $wrapper = $control.find('.' + wrapperClass);
            if ($wrapper.length) {
                return $wrapper;
            }

            $wrapper = $(
                '<div class="ga-b2b-cnpj-tools ' + wrapperClass + '"' +
                ' style="margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;"></div>'
            );

            var $button = $('<button type="button" class="action-default ga-b2b-cnpj-button"><span>Consultar CNPJ</span></button>');
            var $clearButton = $(
                '<button type="button" class="action-default ga-b2b-cnpj-clear-cache">' +
                '<span>Limpar cache CNPJ</span></button>'
            );
            var $message = $('<span class="note ga-b2b-cnpj-message" style="display:none;"></span>');

            $wrapper.append($button);
            $wrapper.append($clearButton);
            $wrapper.append($message);
            $control.append($wrapper);

            return $wrapper;
        }

        function showMessage($wrapper, type, text)
        {
            var $message = $wrapper.find('.ga-b2b-cnpj-message');
            if (!$message.length) {
                return;
            }

            if (!text) {
                $message.text('').hide();
                return;
            }

            let color = '#666';
            if (type === 'success') {
                color = '#107c10';
            } else if (type === 'error') {
                color = '#e02b27';
            } else if (type === 'loading') {
                color = '#0a6c9f';
            }

            $message.text(text).css('color', color).show();
        }

        function syncUiComponent($field, value)
        {
            let fieldName = $field.attr('name') || '';
            let dataIndex = $field.attr('data-index') || $field.data('index') || '';

            // Extract the attribute code from name like "customer[b2b_cnpj]"
            let attrCode = '';
            let nameMatch = fieldName.match(/\[([^\]]+)\]$/);
            if (nameMatch) {
                attrCode = nameMatch[1];
            }
            if (!attrCode && dataIndex) {
                attrCode = dataIndex;
            }

            if (!attrCode) {
                return;
            }

            // Try to find and update the UI Component via uiRegistry
            try {
                uiRegistry.get(function (component) {
                    if (component && component.inputName === fieldName ||
                        (component && component.index === attrCode && typeof component.value === 'function')) {
                        component.value(value);
                    }
                });
            } catch (e) {
                // Fallback: KnockoutJS not available for this field
            }
        }

        function fillField($field, value, force)
        {
            if (!$field.length || !value) {
                return;
            }

            if (!force && $field.val()) {
                return;
            }

            $field.val(value);
            syncUiComponent($field, value);

            // Trigger all relevant DOM events for KnockoutJS bindings
            $field.trigger('input').trigger('change').trigger('keyup');
            $field.addClass('ga-b2b-cnpj-autofilled');
        }

        function setCountryToBr($countryField, force)
        {
            if (!$countryField.length || !force) {
                return;
            }

            if ($countryField.val() !== 'BR') {
                $countryField.val('BR');
                syncUiComponent($countryField, 'BR');
                $countryField.trigger('input').trigger('change').trigger('keyup');
            }
        }

        function setRegionByUf(uf, $regionIdField, $regionTextField)
        {
            let ufUpper = toUpperText(uf);
            if (!ufUpper) {
                return;
            }

            let regionName = ufToRegionName[ufUpper] ? normalizeText(ufToRegionName[ufUpper]) : '';
            let selected = false;

            if ($regionIdField.length) {
                let optionToSelect = null;
                $regionIdField.find('option').each(function () {
                    let optionValue = toUpperText($(this).attr('value'));
                    let optionText = normalizeText($(this).text());
                    if (!optionValue) {
                        return;
                    }

                    if (optionText === regionName ||
                        optionText.indexOf('(' + ufUpper + ')') !== -1 ||
                        optionText.indexOf(ufUpper + ' -') === 0 ||
                        optionText.indexOf('- ' + ufUpper) !== -1 ||
                        optionText === ufUpper) {
                        optionToSelect = optionValue;
                        return false;
                    }
                });

                if (optionToSelect) {
                    $regionIdField.val(optionToSelect);
                    syncUiComponent($regionIdField, optionToSelect);
                    $regionIdField.trigger('input').trigger('change').trigger('keyup');
                    selected = true;
                }
            }

            if (!selected && $regionTextField.length) {
                $regionTextField.val(ufUpper);
                syncUiComponent($regionTextField, ufUpper);
                $regionTextField.trigger('input').trigger('change').trigger('keyup');
            }
        }

        function applyResponse(context, cnpj, response, $cnpjField)
        {
            let rootElement = $cnpjField.closest('.admin__fieldset, .admin__field, .admin__page-section, #order-billing_address, #order-shipping_address');
            let selectors = context.fieldSelectors || {};

            fillField(getFieldFromSelectors(selectors.razaoSocial, rootElement), response.razao_social || '', true);
            fillField(getFieldFromSelectors(selectors.nomeFantasia, rootElement), response.nome_fantasia || '', true);
            fillField(getFieldFromSelectors(selectors.company, rootElement), response.razao_social || '', true);
            fillField(getFieldFromSelectors(selectors.phone, rootElement), formatPhone(response.telefone || ''), true);
            fillField(getFieldFromSelectors(selectors.taxvat, rootElement), formatCnpj(cnpj), false);
            fillField(getFieldFromSelectors(selectors.postcode, rootElement), formatCep(response.cep || ''), true);
            fillField(getFieldFromSelectors(selectors.street0, rootElement), response.logradouro || '', true);

            let line2Value = '';
            if (response.numero) {
                line2Value = response.numero;
            }
            if (response.complemento) {
                line2Value = line2Value ? (line2Value + ' - ' + response.complemento) : response.complemento;
            }
            fillField(getFieldFromSelectors(selectors.street1, rootElement), line2Value, true);

            fillField(getFieldFromSelectors(selectors.city, rootElement), response.municipio || '', true);

            var $countryField = getFieldFromSelectors(selectors.countryId, rootElement);
            setCountryToBr($countryField, !!context.forceCountryToBr);

            var $regionIdField = getFieldFromSelectors(selectors.regionId, rootElement);
            var $regionTextField = getFieldFromSelectors(selectors.region, rootElement);

            if (response.uf) {
                setTimeout(function () {
                    setRegionByUf(response.uf, $regionIdField, $regionTextField);
                }, 200);
            }
        }

        function executeLookup(context, cnpj, $wrapper, $cnpjField, forceRefresh)
        {
            if ($cnpjField.data('gaB2bCnpjInFlight')) {
                return;
            }

            $cnpjField.data('gaB2bCnpjInFlight', true);
            showMessage($wrapper, 'loading', 'Consultando Receita Federal...');

            $.ajax({
                url: lookupUrl,
                method: 'POST',
                dataType: 'json',
                showLoader: false,
                data: {
                    cnpj: cnpj,
                    force_refresh: forceRefresh ? 1 : 0,
                    form_key: window.FORM_KEY || $('input[name="form_key"]').val()
                }
            }).done(function (response) {
                $cnpjField.data('gaB2bCnpjInFlight', false);

                if (!response || !response.success) {
                    showMessage(
                        $wrapper,
                        'error',
                        (response && response.message) ? response.message : 'CNPJ inválido.'
                    );
                    return;
                }

                applyResponse(context, cnpj, response, $cnpjField);

                if (response.api_unavailable) {
                    showMessage(
                        $wrapper,
                        'success',
                        response.message || 'CNPJ válido localmente. API indisponível para autopreenchimento.'
                    );
                    return;
                }

                if (response.cached) {
                    showMessage($wrapper, 'success', 'Dados preenchidos a partir do cache local.');
                    return;
                }

                if (response.source === 'cnpjws') {
                    showMessage($wrapper, 'success', 'CNPJ válido. Dados preenchidos da base pública (CNPJ.ws).');
                    return;
                }

                if (response.source === 'brasilapi') {
                    showMessage($wrapper, 'success', 'CNPJ válido. Dados preenchidos da BrasilAPI.');
                    return;
                }

                showMessage($wrapper, 'success', 'CNPJ válido. Dados da empresa preenchidos.');
            }).fail(function () {
                $cnpjField.data('gaB2bCnpjInFlight', false);
                showMessage($wrapper, 'error', 'Erro ao consultar CNPJ. Tente novamente.');
            });
        }

        function executeClearCache(cnpj, $wrapper)
        {
            if (!clearCacheUrl) {
                showMessage($wrapper, 'error', 'URL de limpeza de cache não configurada.');
                return;
            }

            showMessage($wrapper, 'loading', 'Limpando cache do CNPJ...');

            $.ajax({
                url: clearCacheUrl,
                method: 'POST',
                dataType: 'json',
                showLoader: false,
                data: {
                    cnpj: cnpj,
                    form_key: window.FORM_KEY || $('input[name="form_key"]').val()
                }
            }).done(function (response) {
                if (!response || !response.success) {
                    showMessage(
                        $wrapper,
                        'error',
                        (response && response.message) ? response.message : 'Falha ao limpar cache.'
                    );
                    return;
                }

                showMessage(
                    $wrapper,
                    'success',
                    (response && response.message) ? response.message : 'Cache do CNPJ removido.'
                );
            }).fail(function () {
                showMessage($wrapper, 'error', 'Erro ao limpar cache do CNPJ. Tente novamente.');
            });
        }

        function bindContext(context)
        {
            let foundAny = false;
            let cnpjFields = collectFields(context.cnpjSelectors || []);

            for (let i = 0; i < cnpjFields.length; i++) {
                var $cnpjField = cnpjFields[i];
                let boundKey = 'gaB2bCnpjBound_' + context.key;

                if ($cnpjField.data(boundKey)) {
                    foundAny = true;
                    continue;
                }

                foundAny = true;
                $cnpjField.data(boundKey, true);
                $cnpjField.data('gaB2bLastLookup', '');

                (function ($field, $wrapper) {
                    var $button = $wrapper.find('.ga-b2b-cnpj-button');
                    var $clearButton = $wrapper.find('.ga-b2b-cnpj-clear-cache');

                    $field.on('input.gaB2bCnpj', function () {
                        let current = $field.val();
                        let formatted = formatFieldInput(current, context.documentType);
                        if (formatted !== current) {
                            $field.val(formatted);
                        }

                        let digits = cleanDigits($field.val());
                        if (digits.length < 14) {
                            $field.data('gaB2bLastLookup', '');
                            showMessage($wrapper, 'info', '');
                            return;
                        }

                        if (digits.length !== 14) {
                            return;
                        }

                        if ($field.data('gaB2bLastLookup') === digits) {
                            return;
                        }

                        let timerKey = 'gaB2bCnpjTimer_' + context.key;
                        clearTimeout($field.data(timerKey));
                        let timer = setTimeout(function () {
                            $field.data('gaB2bLastLookup', digits);
                            executeLookup(context, digits, $wrapper, $field, false);
                        }, 600);
                        $field.data(timerKey, timer);
                    });

                    $button.on('click.gaB2bCnpj', function () {
                        let digits = cleanDigits($field.val());
                        if (digits.length !== 14) {
                            showMessage($wrapper, 'error', 'Informe um CNPJ com 14 dígitos.');
                            return;
                        }

                        $field.data('gaB2bLastLookup', digits);
                        executeLookup(context, digits, $wrapper, $field, true);
                    });

                    $clearButton.on('click.gaB2bCnpjClear', function () {
                        let digits = cleanDigits($field.val());
                        if (digits.length !== 14) {
                            showMessage($wrapper, 'error', 'Informe um CNPJ com 14 dígitos.');
                            return;
                        }

                        $field.data('gaB2bLastLookup', '');
                        executeClearCache(digits, $wrapper);
                    });
                })($cnpjField, upsertHelperElements($cnpjField, context.key));
            }

            return foundAny;
        }

        function bindAllContexts()
        {
            let found = false;
            for (let i = 0; i < contexts.length; i++) {
                if (bindContext(contexts[i])) {
                    found = true;
                }
            }

            return found;
        }

        bindAllContexts();

        if (window.MutationObserver && document.body) {
            let observer = new MutationObserver(function () {
                bindAllContexts();
            });

            observer.observe(document.body, {childList: true, subtree: true});

            setTimeout(function () {
                observer.disconnect();
            }, 120000);
        }

        let attempts = 0;
        let interval = setInterval(function () {
            attempts++;
            bindAllContexts();

            if (attempts >= 120) {
                clearInterval(interval);
            }
        }, 500);
    };
});
