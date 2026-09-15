define([
    'jquery',
    'mage/url',
    'mage/validation'
], function ($, urlBuilder) {
    'use strict';

    function escapeHtml(value)
    {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    var alertIcons = {
        search: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
        check: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
        warning: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'
    };

    function translateMessage(message)
    {
        if ($.mage && typeof $.mage.__ === 'function') {
            return $.mage.__(message);
        }

        return message;
    }

    function cleanCnpjDigits(value)
    {
        return String(value || '').replace(/\D/g, '');
    }

    function isValidCnpjChecksum(value)
    {
        let cnpj = cleanCnpjDigits(value);
        let weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        let weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        let sum = 0;
        let remainder;
        let digit;
        let i;

        if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) {
            return false;
        }

        for (i = 0; i < 12; i++) {
            sum += parseInt(cnpj.charAt(i), 10) * weights1[i];
        }

        remainder = sum % 11;
        digit = remainder < 2 ? 0 : 11 - remainder;

        if (parseInt(cnpj.charAt(12), 10) !== digit) {
            return false;
        }

        sum = 0;

        for (i = 0; i < 13; i++) {
            sum += parseInt(cnpj.charAt(i), 10) * weights2[i];
        }

        remainder = sum % 11;
        digit = remainder < 2 ? 0 : 11 - remainder;

        return parseInt(cnpj.charAt(13), 10) === digit;
    }

    function registerCnpjValidator()
    {
        if (!$.validator || typeof $.validator.addMethod !== 'function') {
            return;
        }

        if ($.validator.methods['validate-cnpj']) {
            return;
        }

        $.validator.addMethod(
            'validate-cnpj',
            function (value) {
                let digits = cleanCnpjDigits(value);

                if (digits.length === 0) {
                    return true;
                }

                return isValidCnpjChecksum(digits);
            },
            translateMessage('Informe um CNPJ válido com 14 dígitos.')
        );
    }

    registerCnpjValidator();

    return function (config, element) {
        let options = config || {};
        var $form = $(element);
        var $container = $form.closest('.b2b-register-container');
        let cnpjValidateUrl = options.cnpjValidateUrl || '';
        let cepLookupUrl = options.cepLookupUrl || '';
        let loginUrl = options.loginUrl || '';
        let sendingLabel = options.sendingLabel || 'Enviando cadastro...';
        let leadStorageKey = options.leadStorageKey || 'b2b_register_lead_fired';
        let leadEventName = options.leadEventName || 'Lead';
        let passwordMinLength = parseInt(options.passwordMinLength, 10) || 8;
        let passwordMinClasses = parseInt(options.passwordMinClasses, 10) || 3;
        let stepBackLabel = options.stepBackLabel || 'Voltar';
        let stepContinueLabel = options.stepContinueLabel || 'Continuar';
        let stepFinalHint = options.stepFinalHint || 'Revise e envie';
        let totalSteps = parseInt(options.totalSteps, 10) || 4;

        let cnpjTimer = null;
        let cepLookupTimer = null;
        let lastCnpj = '';
        let cnpjValidated = false;
        let rateLimitTimer = null;
        let erpEmailFull = null;
        let erpEmailMasked = null;
        let currentProgressStep = 1;
        let benefitsViewportMedia = window.matchMedia ? window.matchMedia('(max-width: 768px)') : null;
        let lastBenefitsCompactMode = null;
        let lastSectionsCompactMode = null;
        let progressSyncScheduled = false;
        let leadTriggered = false;

        if (!$form.length) {
            return;
        }

        function applyAttributionFromQuery()
        {
            var keys = [
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_content',
                'utm_term',
                'cname',
                'campaign',
                'attendant',
                'vendedor'
            ];
            var stored = {};
            var params = new URLSearchParams(window.location.search);

            try {
                stored = JSON.parse(window.sessionStorage.getItem('awa_b2b_attr') || '{}') || {};
            } catch (error) {
                stored = {};
            }

            keys.forEach(function (name) {
                var fromUrl = String(params.get(name) || '').trim();
                if (fromUrl && !stored[name]) {
                    stored[name] = fromUrl;
                }
            });

            try {
                window.sessionStorage.setItem('awa_b2b_attr', JSON.stringify(stored));
            } catch (persistError) {
                // Ignore quota / private-mode failures.
            }

            keys.forEach(function (name) {
                var value = String(stored[name] || params.get(name) || '').trim();
                var input;
                if (!value) {
                    return;
                }
                input = $form.find('input[name="' + name + '"]');
                if (input.length && !String(input.val() || '').trim()) {
                    input.val(value);
                }
            });
        }

        applyAttributionFromQuery();

        var $progressSteps = $form.find('.b2b-register-progress .progress-step');
        var $progressBar = $form.find('.b2b-register-progress');
        var $stepSections = $form.find('.form-section[data-step]');
        var $benefitsToggle = $container.find('.b2b-benefits-toggle');
        var $benefitsPanel = $container.find('#b2b-register-benefits');
        var $passwordToggles = $form.find('[data-register-password-toggle]');
        var $stepNav = $form.find('[data-register-step-nav]');
        var $stepPrev = $stepNav.find('.b2b-register-step-prev');
        var $stepNext = $stepNav.find('.b2b-register-step-next');
        var $stepIndicator = $stepNav.find('.b2b-register-step-indicator__text');

        function $field(selector)
        {
            return $form.find(selector);
        }

        function hasLeadBeenTracked()
        {
            try {
                return window.sessionStorage.getItem(leadStorageKey) === '1';
            } catch (error) {
                return false;
            }
        }

        function markLeadTracked()
        {
            try {
                window.sessionStorage.setItem(leadStorageKey, '1');
            } catch (error) {
                // Browsers with restricted storage should not break the flow.
            }
        }

        function trackLeadStart()
        {
            if (leadTriggered || hasLeadBeenTracked()) {
                return;
            }

            let eventId = 'lead-b2b-' + Date.now();

            if (typeof window.fbq === 'function') {
                window.fbq('track', leadEventName, {
                    lead_type: 'b2b_cnpj',
                    person_type: 'pj',
                    funnel_stage: 'start',
                    register_channel: 'b2b_register_form'
                }, {eventID: eventId});
            }

            let formKey = window.FORM_KEY || '';

            if (formKey) {
                $.ajax({
                    url: urlBuilder.build('b2b/ajax/trackLead'),
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        event_id: eventId,
                        funnel_stage: 'start',
                        register_channel: 'b2b_register_form',
                        form_key: formKey
                    }
                });
            }

            leadTriggered = true;
            markLeadTracked();
        }

        function updateRegisterPasswordToggleButton($toggle, isVisible)
        {
            let isConfirm = $toggle.attr('data-target') === '#password_confirmation';
            let showLabel = isConfirm ? 'Mostrar confirmação de senha' : 'Mostrar senha';
            let hideLabel = isConfirm ? 'Ocultar confirmação de senha' : 'Ocultar senha';

            $toggle.attr('aria-pressed', isVisible ? 'true' : 'false');
            $toggle.attr('aria-label', isVisible ? hideLabel : showLabel);
            $toggle.text(isVisible ? 'Ocultar' : 'Mostrar');
        }

        function initRegisterPasswordToggles()
        {
            $passwordToggles.each(function () {
                var $toggle = $(this);
                let targetSelector = $toggle.attr('data-target');
                var $target = targetSelector ? $form.find(targetSelector) : $();

                if (!$target.length) {
                    return;
                }

                updateRegisterPasswordToggleButton($toggle, $target.attr('type') === 'text');

                $toggle.on('click', function (event) {
                    let showPassword;

                    event.preventDefault();
                    showPassword = $target.attr('type') !== 'text';
                    $target.attr('type', showPassword ? 'text' : 'password');
                    updateRegisterPasswordToggleButton($toggle, showPassword);
                    $target.trigger('focus');
                });
            });
        }

        function setHiddenState($element, isHidden)
        {
            if (!$element || !$element.length) {
                return $element;
            }

            $element.toggleClass('is-hidden', !!isHidden)
                .toggleClass('is-expanded', !isHidden)
                .attr('aria-hidden', isHidden ? 'true' : 'false');

            return $element;
        }

        function showBlock($element)
        {
            setHiddenState($element, false);
            $element.show();
            return $element;
        }

        function hideBlock($element)
        {
            $element.hide();
            setHiddenState($element, true);
            return $element;
        }

        function slideShow($element, duration)
        {
            setHiddenState($element, false);
            return $element.stop(true, true).slideDown(duration || 200);
        }

        function slideHide($element, duration)
        {
            return $element.stop(true, true).slideUp(duration || 200, function () {
                setHiddenState($(this), true);
            });
        }

        function setActiveProgressStep(stepNumber)
        {
            let activeStep = parseInt(stepNumber, 10);

            if (!activeStep || !$progressSteps.length) {
                return;
            }

            currentProgressStep = activeStep;

            $progressSteps.each(function (index) {
                let stepIndex = parseInt($(this).attr('data-step-number'), 10) || (index + 1);
                var $step = $(this);
                let isActive = stepIndex === activeStep;

                $step.toggleClass('is-active', isActive);
                if (isActive) {
                    $step.removeAttr('aria-current');
                    $step.attr('aria-current', 'step');
                } else {
                    $step.removeAttr('aria-current');
                }
            });

            updateProgressCompletionState();
            updateStepNav();
            scrollActiveProgressStepIntoView();
        }

        function getFormValidator()
        {
            if (typeof $form.valid === 'function') {
                return $form.validate();
            }

            return $form.data('validator') || null;
        }

        function attachCnpjValidationRule()
        {
            var $cnpj = $field('#cnpj');
            var validator;

            registerCnpjValidator();

            if (!$cnpj.length || typeof $cnpj.rules !== 'function') {
                return;
            }

            validator = getFormValidator();

            if (!validator) {
                return;
            }

            $cnpj.rules('add', {
                'validate-cnpj': true
            });
        }

        function getCnpjRejectionMessage()
        {
            let digits = cleanCnpjDigits($field('#cnpj').val());

            if (digits.length === 0) {
                return 'Preencha CNPJ.';
            }

            if (digits.length !== 14) {
                return 'Informe um CNPJ válido com 14 dígitos.';
            }

            if (!isValidCnpjChecksum(digits)) {
                return 'CNPJ inválido. Verifique os dígitos.';
            }

            if (!cnpjValidated) {
                return 'Aguarde a validação do CNPJ ou verifique o número informado.';
            }

            return '';
        }

        function isCnpjAccepted()
        {
            return getCnpjRejectionMessage() === '';
        }

        function markCnpjInvalid(message)
        {
            var $cnpj = $field('#cnpj');

            $cnpj.removeClass('valid').addClass('mage-error').attr('aria-invalid', 'true');
            $cnpj.closest('.field').addClass('_error');
            setCnpjStatus('error', message);
        }

        function validateStepFields(stepNumber, validationOptions)
        {
            validationOptions = validationOptions || {};
            var $section = $stepSections.filter('[data-step="' + stepNumber + '"]').first();
            var valid = true;
            var validator = getFormValidator();
            let stepIndex = parseInt(stepNumber, 10);

            if (!$section.length || !stepIndex) {
                return false;
            }

            openStepSection($section, false, true);

            $section.find('input, select, textarea').each(function () {
                var $input = $(this);
                let name = $input.attr('name') || '';
                let magentoValid = true;
                let customMessage;

                if (!$input.is(':visible') || $input.is(':disabled') || name === 'b2b_website') {
                    return;
                }

                clearInlineFieldError($input);

                if (name === 'cnpj') {
                    customMessage = getCnpjRejectionMessage();

                    if (customMessage !== '') {
                        if (!validationOptions.silent) {
                            markCnpjInvalid(customMessage);
                        }
                        valid = false;
                    }

                    return;
                }

                if (validator && typeof validator.element === 'function') {
                    magentoValid = !!validator.element(this);
                } else if ($input.prop('required') && $.trim(String($input.val() || '')) === '') {
                    magentoValid = false;
                }

                customMessage = resolveFieldValidationMessage($input);

                if (!magentoValid || customMessage !== '') {
                    if (!validationOptions.silent) {
                        ensureInlineFieldError(
                            $input,
                            customMessage !== '' ? customMessage : 'Preencha ' + getFieldLabel($input) + '.'
                        );
                    }
                    valid = false;
                }
            });

            if (stepIndex === 4) {
                let password = String($field('#password').val() || '');
                let confirm = String($field('#password_confirmation').val() || '');

                if (!isPasswordComplexEnough(password)) {
                    valid = false;
                }

                if (confirm.length < passwordMinLength || password !== confirm) {
                    valid = false;
                }
            }

            if (!valid && !validationOptions.silent) {
                refreshFieldAndSectionErrorStates();
                announceValidationErrors();
            }

            return valid;
        }

        function updateStepNav()
        {
            if (!$stepNav.length) {
                return;
            }

            $stepPrev.prop('disabled', currentProgressStep <= 1);
            $stepPrev.attr('aria-disabled', currentProgressStep <= 1 ? 'true' : 'false');

            if ($stepIndicator.length) {
                if (currentProgressStep >= totalSteps) {
                    $stepIndicator.text(stepFinalHint);
                } else {
                    $stepIndicator.text('Etapa ' + currentProgressStep + ' de ' + totalSteps);
                }
            }

            if (currentProgressStep >= totalSteps) {
                $stepNext.addClass('is-hidden').attr('aria-hidden', 'true').prop('disabled', true);
                $form.addClass('is-register-final-step');
                $form.find('.terms-section, .actions-toolbar').removeAttr('hidden');
            } else {
                $stepNext.removeClass('is-hidden').attr('aria-hidden', 'false').prop('disabled', false);
                $stepNext.find('span').text(stepContinueLabel);
                $form.removeClass('is-register-final-step');
                $form.find('.terms-section, .actions-toolbar').attr('hidden', 'hidden');
            }
        }

        function initStepNavigation()
        {
            if (!$stepNav.length) {
                return;
            }

            $stepPrev.on('click', function (event) {
                event.preventDefault();

                if (currentProgressStep <= 1) {
                    return;
                }

                goToStepSection(currentProgressStep - 1, true);
                focusFirstFieldInSection($stepSections.filter('[data-step="' + currentProgressStep + '"]').first());
            });

            $stepNext.on('click', function (event) {
                event.preventDefault();

                if (currentProgressStep >= totalSteps) {
                    return;
                }

                if (!validateStepFields(currentProgressStep)) {
                    focusFirstInvalidField();
                    return;
                }

                goToStepSection(currentProgressStep + 1, true);
                focusFirstFieldInSection($stepSections.filter('[data-step="' + currentProgressStep + '"]').first());
            });
        }

        function validateStepsBeforeTarget(targetStep)
        {
            let step = currentProgressStep;

            while (step < targetStep) {
                if (!validateStepFields(step)) {
                    goToStepSection(step, true);
                    focusFirstInvalidField();
                    return false;
                }
                step++;
            }

            return true;
        }

        function syncProgressFromFocus($target)
        {
            var $section = $target.closest('.form-section');
            let stepNumber = $section.data('step');

            if ($section.length && $section.is('[data-step]')) {
                openStepSection($section, true, true);
            }

            if (!stepNumber && $section.hasClass('form-section--terms')) {
                stepNumber = 4;
            }

            if (stepNumber) {
                setActiveProgressStep(stepNumber);
            }
        }

        function scrollActiveProgressStepIntoView()
        {
            var $activeStep;
            let supportsSmooth = !!(window.CSS && window.CSS.supports && window.CSS.supports('scroll-behavior', 'smooth'));

            if (!$progressBar.length || !isCompactBenefitsViewport()) {
                return;
            }

            $activeStep = $progressSteps.filter('.is-active').first();

            if (!$activeStep.length || !$activeStep.get(0) || typeof $activeStep.get(0).scrollIntoView !== 'function') {
                return;
            }

            try {
                $activeStep.get(0).scrollIntoView({
                    behavior: supportsSmooth ? 'smooth' : 'auto',
                    block: 'nearest',
                    inline: 'center'
                });
            } catch (error) {
                $activeStep.get(0).scrollIntoView();
            }
        }

        function isCompactBenefitsViewport()
        {
            if (window.innerWidth <= 768) {
                return true;
            }

            if (benefitsViewportMedia) {
                return !!benefitsViewportMedia.matches;
            }

            return false;
        }

        function setBenefitsExpanded(isExpanded, animate)
        {
            if (!$benefitsToggle.length || !$benefitsPanel.length) {
                return;
            }

            $benefitsToggle.attr('aria-expanded', isExpanded ? 'true' : 'false');

            if (isExpanded) {
                if (animate) {
                    slideShow($benefitsPanel, 180);
                } else {
                    showBlock($benefitsPanel);
                }
                return;
            }

            if (animate) {
                slideHide($benefitsPanel, 150);
            } else {
                hideBlock($benefitsPanel);
            }
        }

        function syncBenefitsDisclosure()
        {
            let compactMode = isCompactBenefitsViewport();

            if (!$benefitsToggle.length || !$benefitsPanel.length) {
                return;
            }

            if (!compactMode) {
                setBenefitsExpanded(true, false);
                lastBenefitsCompactMode = false;
                return;
            }

            setBenefitsExpanded(false, false);
            lastBenefitsCompactMode = true;
        }

        function getStepSectionBody($section)
        {
            return $section.children('.form-section__body').first();
        }

        function getStepSectionToggle($section)
        {
            return $section.children('.form-section__heading').find('.form-section__toggle').first();
        }

        function initStepSectionAccordionMarkup()
        {
            $stepSections.each(function (index) {
                var $section = $(this);
                var $heading = $section.children('h2, h3').first();
                var $body;
                var $toggle;
                let headingText;
                let sectionId;
                let bodyId;
                let stepNumber;

                if (!$heading.length || $heading.children('.form-section__toggle').length) {
                    return;
                }

                sectionId = $section.attr('id');
                if (!sectionId) {
                    sectionId = 'b2b-register-step-' + (index + 1);
                    $section.attr('id', sectionId);
                }

                bodyId = sectionId + '-body';
                stepNumber = String($section.data('step') || (index + 1));
                headingText = $.trim($heading.text());
                $body = $('<div/>', {
                    'class': 'form-section__body',
                    'id': bodyId,
                    'aria-hidden': 'false'
                });

                $section.children(':not(h2):not(h3)').appendTo($body);
                $section.append($body);

                $heading.addClass('form-section__heading').empty();
                $toggle = $('<button/>', {
                    type: 'button',
                    'class': 'form-section__toggle',
                    'data-section-toggle': '',
                    'data-step-number': stepNumber,
                    'aria-expanded': 'true',
                    'aria-controls': bodyId
                });

                $('<span/>', {
                    'class': 'form-section__toggle-text',
                    text: headingText
                }).appendTo($toggle);

                $('<span/>', {
                    'class': 'form-section__toggle-icon',
                    'aria-hidden': 'true'
                }).appendTo($toggle);

                $heading.append($toggle);
            });
        }

        function setStepSectionExpanded($section, isExpanded, animate)
        {
            var $body = getStepSectionBody($section);
            var $toggle = getStepSectionToggle($section);

            if (!$section || !$section.length || !$body.length || !$toggle.length) {
                return;
            }

            $section.toggleClass('is-collapsed', !isExpanded);
            $toggle.attr('aria-expanded', isExpanded ? 'true' : 'false');

            if (isExpanded) {
                if (animate) {
                    slideShow($body, 180);
                } else {
                    showBlock($body);
                }

                return;
            }

            if (animate) {
                slideHide($body, 160);
            } else {
                hideBlock($body);
            }
        }

        function openStepSection($section, animate, collapseSiblings)
        {
            let shouldCollapseSiblings = collapseSiblings;

            if (!$section || !$section.length || !$stepSections.length) {
                return;
            }

            if (typeof shouldCollapseSiblings === 'undefined') {
                shouldCollapseSiblings = true;
            }

            if (shouldCollapseSiblings) {
                $stepSections.not($section).each(function () {
                    setStepSectionExpanded($(this), false, animate);
                });
            }

            setStepSectionExpanded($section, true, animate);
        }

        function expandAllStepSections(animate)
        {
            if (!$stepSections.length) {
                return;
            }

            $stepSections.each(function () {
                setStepSectionExpanded($(this), true, animate);
            });
        }

        function syncStepSectionsDisclosure()
        {
            let compactMode = isCompactBenefitsViewport();
            var $activeSection = $stepSections.filter('[data-step="' + currentProgressStep + '"]').first();

            if (!$stepSections.length) {
                return;
            }

            if (!$activeSection.length) {
                $activeSection = $stepSections.first();
            }

            if (lastSectionsCompactMode !== compactMode || !$form.hasClass('accordion-initialized')) {
                openStepSection($activeSection, false, true);
                lastSectionsCompactMode = compactMode;
                $form.addClass('accordion-initialized');
            }
        }

        function goToStepSection(stepNumber, animate)
        {
            var $section = $stepSections.filter('[data-step="' + stepNumber + '"]').first();

            if (!$section.length) {
                return;
            }

            setActiveProgressStep(stepNumber);
            openStepSection($section, !!animate, true);
            scrollToSection($section);
        }

        function initIeIsentoToggle()
        {
            var $ieCheckbox = $field('#ie_isento');
            var $ieInput = $field('#inscricao_estadual');
            var $ieField = $form.find('.field-ie-input');

            if (!$ieCheckbox.length || !$ieInput.length) {
                return;
            }

            function syncIeIsentoState()
            {
                let isExempt = $ieCheckbox.is(':checked');

                $ieField.toggleClass('is-disabled', isExempt);
                $ieInput.prop('disabled', isExempt);

                if (isExempt) {
                    $ieInput.val('');
                }
            }

            $ieCheckbox.on('change', syncIeIsentoState);
            syncIeIsentoState();
        }

        function syncProgressFromViewport()
        {
            // Acordeão por etapa: progresso é controlado pelo stepper e foco, não pelo scroll.
        }

        function scheduleProgressViewportSync()
        {
            // noop — scroll sync desativado com acordeão em todos os breakpoints
        }

        function fieldHasValue(selector)
        {
            return $.trim(String($field(selector).val() || '')) !== '';
        }

        function getPasswordClassCount(password)
        {
            let classes = 0;
            if (/[a-z]/.test(password)) {
                classes++; }
            if (/[A-Z]/.test(password)) {
                classes++; }
            if (/[0-9]/.test(password)) {
                classes++; }
            if (/[^a-zA-Z0-9]/.test(password)) {
                classes++; }
            return classes;
        }

        function isPasswordComplexEnough(password)
        {
            return String(password || '').length >= passwordMinLength
                && getPasswordClassCount(String(password || '')) >= passwordMinClasses;
        }

        function isStepComplete(stepIndex)
        {
            if (stepIndex === 1) {
                return fieldHasValue('#cnpj') && fieldHasValue('#razao_social') && fieldHasValue('#phone') && cnpjValidated;
            }

            if (stepIndex === 2) {
                return fieldHasValue('#cep') &&
                    fieldHasValue('#logradouro') &&
                    fieldHasValue('#numero') &&
                    fieldHasValue('#bairro') &&
                    fieldHasValue('#municipio') &&
                    fieldHasValue('#uf');
            }

            if (stepIndex === 3) {
                return fieldHasValue('#firstname') && fieldHasValue('#lastname') && fieldHasValue('#email');
            }

            if (stepIndex === 4) {
                let password = String($field('#password').val() || '');
                let confirm = String($field('#password_confirmation').val() || '');
                return isPasswordComplexEnough(password)
                    && confirm.length >= passwordMinLength
                    && password === confirm;
            }

            return false;
        }

        function updateProgressCompletionState()
        {
            let priorStepsComplete = true;

            if (!$progressSteps.length) {
                return;
            }

            $progressSteps.each(function (index) {
                var $step = $(this);
                let stepIndex = parseInt($step.attr('data-step-number'), 10) || (index + 1);
                let isActive = stepIndex === currentProgressStep;
                let isComplete = isStepComplete(stepIndex);
                let isReachable = stepIndex <= currentProgressStep || priorStepsComplete;
                let isLocked = !isActive && !isReachable;

                $step.toggleClass('is-complete', isComplete && !isActive);
                $step.toggleClass('is-locked', isLocked);
                $step.prop('disabled', isLocked);
                $step.attr('aria-disabled', isLocked ? 'true' : 'false');

                priorStepsComplete = priorStepsComplete && isComplete;
            });
        }

        function refreshFieldAndSectionErrorStates()
        {
            $form.find('.field').each(function () {
                var $wrapper = $(this);
                let hasError = $wrapper.hasClass('_error') || $wrapper.find('.mage-error:visible').length > 0;

                $wrapper.find('input, select, textarea').attr('aria-invalid', hasError ? 'true' : 'false');
            });

            $form.find('.form-section').each(function () {
                var $section = $(this);
                let hasErrors = $section.find('.field._error, .mage-error:visible').length > 0;
                $section.toggleClass('has-errors', hasErrors);
            });

            if (!$form.find('.field._error input, .field._error select, .field._error textarea').filter(':visible').length) {
                announceFormStatus('');
            }
        }

        function focusFirstFieldInSection($section)
        {
            var $target;

            if (!$section || !$section.length) {
                return;
            }

            if ($section.is('[data-step]')) {
                openStepSection($section, false, true);
            }

            $target = $section.find('input, select, textarea').filter(':visible').first();

            if ($target.length) {
                window.setTimeout(function () {
                    $target.trigger('focus');
                }, 30);
            }
        }

        function scrollToSection($section)
        {
            let topOffset;
            let prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let scrollOffset = isCompactBenefitsViewport() ? 86 : 18;

            if (!$section || !$section.length) {
                return;
            }

            topOffset = Math.max(0, ($section.offset().top || 0) - scrollOffset);

            if (prefersReducedMotion) {
                window.scrollTo(0, topOffset);
                return;
            }

            $('html, body').stop(true).animate({ scrollTop: topOffset }, 240);
        }

        function announceFormStatus(message)
        {
            var $region = $('#b2b-register-form-status');
            let normalizedMessage = $.trim(String(message || ''));

            if (!$region.length) {
                return;
            }

            $region
                .text(normalizedMessage)
                .toggleClass('has-message', normalizedMessage !== '')
                .attr('aria-hidden', normalizedMessage === '' ? 'true' : 'false');
        }

        function getFieldLabel($input)
        {
            var id = $input.attr('id');
            var $label;

            if (id) {
                $label = $form.find('label[for="' + id + '"]');
                if ($label.length) {
                    return $.trim($label.text());
                }
            }

            return $input.attr('title') || $input.attr('name') || 'Campo';
        }

        function clearInlineFieldError($input)
        {
            var $field = $input.closest('.field');
            let errorId = getInlineFieldErrorId($input);
            let describedBy = String($input.attr('aria-describedby') || '')
                .split(/\s+/)
                .filter(function (token) {
                    return token && token !== errorId;
                });

            $field.removeClass('_error');
            $input.removeClass('mage-error');
            $field.find('.awa-field-error').remove();

            if (describedBy.length) {
                $input.attr('aria-describedby', describedBy.join(' '));
            } else {
                $input.removeAttr('aria-describedby');
            }
        }

        function getInlineFieldErrorId($input)
        {
            let baseId = String($input.attr('id') || $input.attr('name') || 'field')
                .replace(/[^A-Za-z0-9_-]/g, '-');

            return baseId + '-awa-error';
        }

        function linkInlineFieldError($input, $error)
        {
            let oldErrorId = String($error.attr('id') || '');
            let errorId = getInlineFieldErrorId($input);
            let describedBy = String($input.attr('aria-describedby') || '')
                .split(/\s+/)
                .filter(function (token) {
                    return token && token !== oldErrorId && token !== errorId;
                });

            describedBy.push(errorId);
            $error.attr({
                id: errorId,
                role: 'alert'
            });
            $input.attr('aria-describedby', describedBy.join(' '));
        }

        function ensureInlineFieldError($input, message)
        {
            var $field = $input.closest('.field');
            var $control = $field.find('.control').first();
            var $error = $field.find('.awa-field-error').first();
            var $magentoErrors;

            $field.addClass('_error');
            $input.removeClass('valid').addClass('mage-error').attr('aria-invalid', 'true');

            if (!$error.length) {
                $error = $field.find('.mage-error').not($input).first();
            }

            if (!$error.length) {
                $error = $('<div/>', {
                    class: 'awa-field-error mage-error'
                });
                ($control.length ? $control : $field).append($error);
            }

            $error.addClass('awa-field-error mage-error');
            linkInlineFieldError($input, $error);

            $magentoErrors = $field.find('.mage-error').not($input).not($error);
            $magentoErrors.remove();
            $error.text(message).show();
        }

        function resolveFieldValidationMessage($input)
        {
            var name = String($input.attr('name') || '');
            var value = $.trim(String($input.val() || ''));
            var digits;

            if ($input.prop('required') && value === '') {
                return 'Preencha ' + getFieldLabel($input) + '.';
            }

            if (name === 'cnpj') {
                digits = cleanCnpjDigits(value);
                if (digits.length === 0) {
                    return 'Preencha CNPJ.';
                }
                if (digits.length !== 14) {
                    return 'Informe um CNPJ válido com 14 dígitos.';
                }
                if (!isValidCnpjChecksum(digits)) {
                    return 'CNPJ inválido. Verifique os dígitos.';
                }
            }

            if (name === 'telefone' || name === 'whatsapp') {
                digits = value.replace(/\D/g, '');
                if (digits.length > 0 && (digits.length < 10 || digits.length > 11)) {
                    return 'Informe um telefone válido com DDD.';
                }
            }

            if ($input.attr('type') === 'email' && value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                return 'Informe um e-mail válido.';
            }

            return '';
        }

        function announceValidationErrors()
        {
            var $invalidFields = $form.find('.field._error input, .field._error select, .field._error textarea')
                .filter(':visible');
            var count = $invalidFields.length;
            var firstLabel;

            if (!count) {
                return;
            }

            firstLabel = getFieldLabel($invalidFields.first());

            if (count === 1) {
                announceFormStatus('Revise o campo ' + firstLabel + '.');
                return;
            }

            announceFormStatus(count + ' campos precisam de correção. Primeiro: ' + firstLabel + '.');
        }

        function focusFirstInvalidField()
        {
            var $firstInvalid = $form.find('.field._error input, .field._error select, .field._error textarea')
                .filter(':visible')
                .first();
            var $invalidSection;

            if (!$firstInvalid.length) {
                return;
            }

            $invalidSection = $firstInvalid.closest('.form-section');
            if ($invalidSection.length && $invalidSection.is('[data-step]')) {
                openStepSection($invalidSection, false, true);
            }

            syncProgressFromFocus($firstInvalid);
            scrollToSection($invalidSection);
            announceValidationErrors();
            window.setTimeout(function () {
                $firstInvalid.trigger('focus');
            }, 60);
        }

        function maskCnpj(value)
        {
            let masked = value.replace(/\D/g, '');

            if (masked.length <= 14) {
                masked = masked.replace(/^(\d{2})(\d)/, '$1.$2');
                masked = masked.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                masked = masked.replace(/\.(\d{3})(\d)/, '.$1/$2');
                masked = masked.replace(/(\d{4})(\d)/, '$1-$2');
            }

            return masked;
        }

        function maskPhone(value)
        {
            let digits = String(value).replace(/\D/g, '').substring(0, 11);

            if (!digits.length) {
                return '';
            }

            if (digits.length <= 2) {
                return '(' + digits;
            }

            if (digits.length <= 6) {
                return '(' + digits.substring(0, 2) + ') ' + digits.substring(2);
            }

            if (digits.length <= 10) {
                return '(' + digits.substring(0, 2) + ') ' + digits.substring(2, 6) + '-' + digits.substring(6);
            }

            return '(' + digits.substring(0, 2) + ') ' + digits.substring(2, 7) + '-' + digits.substring(7);
        }

        function maskCep(value)
        {
            let masked = value.replace(/\D/g, '');

            if (masked.length > 5) {
                masked = masked.substring(0, 5) + '-' + masked.substring(5, 8);
            }

            return masked;
        }

        function setCepLoading(isLoading)
        {
            var $feedback = $('#cep-feedback');

            if (!$feedback.length) {
                return;
            }

            $feedback.toggleClass('is-visible', !!isLoading);
            $feedback.text(isLoading ? 'Consultando CEP...' : '');
        }

        function lookupRegisterCep(cep)
        {
            let clean = String(cep).replace(/\D/g, '');

            if (clean.length !== 8 || !cepLookupUrl) {
                return;
            }

            setCepLoading(true);

            $.getJSON(cepLookupUrl, { cep: clean }).done(function (data) {
                if (!data || !data.success) {
                    return;
                }

                if (data.logradouro) {
                    $field('#logradouro').val(data.logradouro).addClass('autofilled');
                }

                if (data.bairro) {
                    $field('#bairro').val(data.bairro).addClass('autofilled');
                }

                if (data.localidade) {
                    $field('#municipio').val(data.localidade).addClass('autofilled');
                }

                if (data.uf) {
                    $field('#uf').val(data.uf).addClass('autofilled').trigger('change');
                }

                updateProgressCompletionState();
                window.setTimeout(function () {
                    $field('#numero').trigger('focus');
                }, 60);
            }).always(function () {
                setCepLoading(false);
            });
        }

        function normalizeClassSuffix(value)
        {
            return String(value || '')
                .toLowerCase()
                .replace(/[^a-z0-9_-]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'indefinida';
        }

        function appendCnpjRetryButton($message)
        {
            $message.append(' ').append($('<button/>', {
                type: 'button',
                class: 'cnpj-retry-btn',
                id: 'cnpj-force-refresh',
                text: 'Consultar novamente'
            }));
        }

        function cnpjSourceLabel(source, cached)
        {
            var labels = {
                api: 'Dados da Receita Federal',
                brasilapi: 'Dados da BrasilAPI',
                cnpjws: 'Cadastro público (CNPJ.ws)',
                fallback: 'Validação local do CNPJ',
                cache: 'Dados em cache'
            };
            var label = labels[source] || 'Dados cadastrais públicos';

            if (cached) {
                label += ' (cache)';
            }

            return label;
        }

        function setManualFillState(enabled)
        {
            var $alert = $field('#cnpj-manual-fill-alert');
            var $razaoField = $field('#razao_social').closest('.field');
            var $fantasiaField = $field('#nome_fantasia').closest('.field');

            $razaoField.toggleClass('is-manual-fill', !!enabled);
            $fantasiaField.toggleClass('is-manual-fill', !!enabled);

            if (!$alert.length) {
                return;
            }

            if (enabled) {
                slideShow($alert, 180);
                window.setTimeout(function () {
                    $field('#razao_social').trigger('focus');
                }, 80);
                return;
            }

            hideBlock($alert);
        }

        function setCnpjStatus(type, message, options)
        {
            var $status = $field('#cnpj-status');
            var $feedback = $field('#cnpj-feedback');
            var $message;

            options = options || {};

            $status
                .removeClass('status-loading status-success status-error')
                .addClass('status-' + type);
            $feedback.empty();

            if (type === 'loading') {
                $field('#cnpj').removeClass('valid mage-error');
                $status.html('<span class="cnpj-spinner" aria-hidden="true"></span>');
                $message = $('<span/>', {
                    class: 'feedback-loading',
                    text: 'Consultando Receita Federal...'
                });
                showBlock($feedback.append($message));
                refreshFieldAndSectionErrorStates();
                return;
            }

            if (type === 'success') {
                $field('#cnpj')
                    .removeClass('mage-error')
                    .addClass('valid')
                    .attr('aria-invalid', 'false');
                $field('#cnpj').closest('.field').removeClass('_error');
                $status.html('<span class="cnpj-check" aria-hidden="true">&#10003;</span>');
                $message = $('<span/>', {
                    class: 'feedback-success',
                    text: message || 'CNPJ validado.'
                });
                if (options.retry) {
                    appendCnpjRetryButton($message);
                }
                showBlock($feedback.append($message));
                refreshFieldAndSectionErrorStates();
                return;
            }

            if (type === 'error') {
                $field('#cnpj')
                    .removeClass('valid')
                    .addClass('mage-error')
                    .attr('aria-invalid', 'true');
                $field('#cnpj').closest('.field').addClass('_error');
                $status.html('<span class="cnpj-x" aria-hidden="true">&#10007;</span>');
                $message = $('<span/>', {
                    class: 'feedback-error',
                    text: message || 'Não foi possível validar este CNPJ.'
                });

                if (options.loginUrl) {
                    $message.append(' ').append($('<a/>', {
                        href: options.loginUrl,
                        class: 'cnpj-login-link',
                        text: 'Fazer login'
                    }));
                }

                if (options.retry) {
                    appendCnpjRetryButton($message);
                }

                showBlock($feedback.append($message));
                refreshFieldAndSectionErrorStates();
            }
        }

        function updateSubmitState()
        {
            var $submit = $form.find('.actions-toolbar .create-b2b-account');

            if ($form.data('isSubmitting')) {
                $submit.prop('disabled', true).addClass('is-loading');
                return;
            }

            if (!isCnpjAccepted()) {
                $submit.prop('disabled', true).addClass('cnpj-pending');
                return;
            }

            $submit.prop('disabled', false).removeClass('cnpj-pending');
        }

        function resetCnpjStatus()
        {
            $field('#cnpj')
                .removeClass('valid mage-error')
                .attr('aria-invalid', 'false');
            $field('#cnpj').closest('.field').removeClass('_error');
            $field('#cnpj-status')
                .removeClass('status-loading status-success status-error')
                .html('');
            hideBlock($field('#cnpj-feedback').html(''));
            slideHide($field('#cnpj-company-data'), 200);
            hideBlock($field('#erp-email-alert').empty());
            setManualFillState(false);

            erpEmailFull = null;
            erpEmailMasked = null;
            cnpjValidated = false;

            updateSubmitState();
            updateProgressCompletionState();
            refreshFieldAndSectionErrorStates();
        }

        function showErpEmailAlert()
        {
            var $alert = $field('#erp-email-alert');
            let currentEmail = ($field('#email').val() || '').trim().toLowerCase();

            if (!erpEmailMasked) {
                hideBlock($alert.empty());
                return;
            }

            if (!currentEmail) {
                $alert.html(
                    '<div class="erp-alert erp-alert-info">' +
                        '<span class="erp-alert-icon">' + alertIcons.search + '</span>' +
                        '<div class="erp-alert-content">' +
                            '<strong>Cliente encontrado no sistema!</strong><br>' +
                            'E-mail cadastrado: <strong>' + escapeHtml(erpEmailMasked) + '</strong><br>' +
                            '<small>Informe o mesmo e-mail para vincular sua conta automaticamente.</small>' +
                        '</div>' +
                    '</div>'
                );
                slideShow($alert, 300);
                return;
            }

            if (erpEmailFull && currentEmail === erpEmailFull) {
                $alert.html(
                    '<div class="erp-alert erp-alert-success">' +
                        '<span class="erp-alert-icon">' + alertIcons.check + '</span>' +
                        '<div class="erp-alert-content">' +
                            '<strong>E-mail confirmado!</strong> Sua conta será vinculada automaticamente.' +
                        '</div>' +
                    '</div>'
                );
                slideShow($alert, 200);
                return;
            }

            $alert.html(
                '<div class="erp-alert erp-alert-warning">' +
                    '<span class="erp-alert-icon">' + alertIcons.warning + '</span>' +
                    '<div class="erp-alert-content">' +
                        '<strong>Atenção:</strong> O e-mail cadastrado no sistema é <strong>' + escapeHtml(erpEmailMasked) + '</strong>.<br>' +
                        'O e-mail informado é diferente. Deseja continuar com <strong>' + escapeHtml(currentEmail) + '</strong>?<br>' +
                        '<small>Se possível, use o mesmo e-mail para vincular seu histórico de compras.</small>' +
                    '</div>' +
                '</div>'
            );
            slideShow($alert, 200);
        }

        function isValidEmail(email)
        {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function updateEmailCheckAlert()
        {
            let email = ($field('#email').val() || '').trim();
            let password = $field('#password').val() || '';
            let passwordConfirmation = $field('#password_confirmation').val() || '';
            var $alert = $field('#email-check-alert');

            if (passwordConfirmation.length >= passwordMinLength && password === passwordConfirmation && isValidEmail(email)) {
                $alert.html(
                    '<div class="email-check-alert-box">' +
                        '<span class="email-check-alert-icon">' + alertIcons.warning + '</span>' +
                        '<div class="email-check-alert-content">' +
                            '<strong>Confirme seu e-mail antes de criar a conta:</strong><br>' +
                            'A aprovação e a recuperação de senha serão enviadas para <strong>' + escapeHtml(email) + '</strong>.' +
                        '</div>' +
                    '</div>'
                );
                slideShow($alert, 180);
                return;
            }

            slideHide($alert, 120).empty();
        }

        function startRateLimitCountdown(seconds)
        {
            let remaining = seconds;
            var $cnpj = $field('#cnpj');

            setCnpjStatus('error', 'Muitas consultas. Aguarde ' + remaining + 's...');
            $cnpj.prop('disabled', true);

            if (rateLimitTimer) {
                clearInterval(rateLimitTimer);
            }

            rateLimitTimer = setInterval(function () {
                remaining -= 1;

                if (remaining <= 0) {
                    clearInterval(rateLimitTimer);
                    rateLimitTimer = null;
                    $cnpj.prop('disabled', false);
                    setCnpjStatus('error', 'Você pode consultar novamente agora.');

                    let digits = ($cnpj.val() || '').replace(/\D/g, '');

                    if (digits.length === 14) {
                        consultarCnpj(digits);
                    }

                    return;
                }

                setCnpjStatus('error', 'Muitas consultas. Aguarde ' + remaining + 's...');
            }, 1000);
        }

        function consultarCnpj(cnpj, forceRefresh)
        {
            if (!cnpjValidateUrl) {
                return;
            }

            setCnpjStatus('loading', '');
            cnpjValidated = false;
            updateSubmitState();

            $.ajax({
                url: cnpjValidateUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    cnpj: cnpj,
                    force_refresh: forceRefresh ? 1 : 0,
                    form_key: $form.find('input[name="form_key"]').val()
                }
            }).done(function (response) {
                var $companyData = $field('#cnpj-company-data');
                let errorMsg;
                let sourceLabel;
                let cepClean;
                var $badge;

                if (response.rate_limited) {
                    startRateLimitCountdown(response.retry_after || 60);
                    return;
                }

                if (response.cnpj_duplicate) {
                    setCnpjStatus(
                        'error',
                        response.message || 'Este CNPJ já possui cadastro.',
                        {loginUrl: loginUrl}
                    );
                    slideHide($companyData, 200);
                    setManualFillState(false);
                    cnpjValidated = false;
                    updateSubmitState();
                    return;
                }

                if (response.success) {
                    if (response.api_unavailable) {
                        setCnpjStatus(
                            'success',
                            response.message || 'CNPJ válido. Preencha os dados manualmente. A conta ficará pendente de aprovação.',
                            {retry: true}
                        );
                        slideHide($companyData, 200);
                        setManualFillState(true);
                        cnpjValidated = true;
                        updateSubmitState();
                        updateProgressCompletionState();
                        return;
                    }

                    setCnpjStatus('success', 'CNPJ válido, empresa ativa.');
                    setManualFillState(false);
                    cnpjValidated = true;
                    updateSubmitState();
                    updateProgressCompletionState();

                    if (response.razao_social) {
                        $field('#razao_social').val(response.razao_social).addClass('autofilled');
                    }

                    if (response.nome_fantasia) {
                        $field('#nome_fantasia').val(response.nome_fantasia).addClass('autofilled');
                    }

                    if (response.telefone) {
                        $field('#cnpj-rf-telefone').text(response.telefone);
                        showBlock($field('#cnpj-rf-telefone-row'));
                    } else {
                        $field('#cnpj-rf-telefone').text('Não informado na RF');
                        showBlock($field('#cnpj-rf-telefone-row'));
                    }

                    if (response.email) {
                        $field('#cnpj-rf-email').text(response.email);
                        showBlock($field('#cnpj-rf-email-row'));
                    } else {
                        $field('#cnpj-rf-email').text('Não informado na RF');
                        showBlock($field('#cnpj-rf-email-row'));
                    }

                    showBlock($field('#cnpj-rf-contact-note'));

                    if (response.cep) {
                        cepClean = response.cep.replace(/\D/g, '');
                        if (cepClean.length > 5) {
                            cepClean = cepClean.substring(0, 5) + '-' + cepClean.substring(5, 8);
                        }
                        $field('#cep').val(cepClean).addClass('autofilled');
                    }

                    if (response.logradouro) {
                        $field('#logradouro').val(response.logradouro).addClass('autofilled');
                    }

                    if (response.numero) {
                        $field('#numero').val(response.numero).addClass('autofilled');
                    }

                    if (response.complemento) {
                        $field('#complemento').val(response.complemento).addClass('autofilled');
                    }

                    if (response.bairro) {
                        $field('#bairro').val(response.bairro).addClass('autofilled');
                    }

                    if (response.municipio) {
                        $field('#municipio').val(response.municipio).addClass('autofilled');
                    }

                    if (response.uf) {
                        $field('#uf').val(response.uf).addClass('autofilled');
                    }

                    sourceLabel = cnpjSourceLabel(response.source, response.cached);
                    $field('#cnpj-source-badge').text(sourceLabel);

                    if (response.situacao) {
                        $field('#cnpj-situacao')
                            .text(response.situacao)
                            .attr('class', 'cnpj-situacao situacao-' + normalizeClassSuffix(response.situacao));
                    }

                    if (response.atividade_principal) {
                        $field('#cnpj-atividade').text(response.atividade_principal);
                    }

                    if (response.cnae_profile && response.cnae_profile_label && response.cnae_profile !== 'off_profile') {
                        $badge = $field('#cnae-profile-badge');
                        $badge.text(response.cnae_profile_label)
                            .removeClass('cnae-direct cnae-adjacent')
                            .addClass('cnae-' + normalizeClassSuffix(response.cnae_profile))
                            .removeClass('is-hidden')
                            .attr('aria-hidden', 'false')
                            .show();
                    } else {
                        hideBlock($field('#cnae-profile-badge'));
                    }

                    slideShow($companyData, 300);

                    if (response.cep || response.logradouro) {
                        window.setTimeout(function () {
                            goToStepSection(2, true);
                        }, 320);
                    }

                    erpEmailFull = null;
                    erpEmailMasked = null;
                    if (response.erp_found && response.erp_email) {
                        erpEmailMasked = response.erp_email;
                        erpEmailFull = response.erp_email_full || null;
                        showErpEmailAlert();
                    } else {
                        hideBlock($field('#erp-email-alert').empty());
                    }

                    return;
                }

                errorMsg = response.message || 'CNPJ inválido';
                setCnpjStatus('error', errorMsg, {
                    retry: true
                });
                slideHide($companyData, 200);
                setManualFillState(false);
                cnpjValidated = false;
                updateSubmitState();
                updateProgressCompletionState();
                refreshFieldAndSectionErrorStates();
            }).fail(function (jqXHR) {
                if (jqXHR && jqXHR.status === 429) {
                    startRateLimitCountdown(60);
                    return;
                }

                setCnpjStatus('error', 'Erro ao consultar. Verifique sua conexão e tente novamente.', {
                    retry: true
                });
                setManualFillState(false);
                cnpjValidated = false;
                updateSubmitState();
                updateProgressCompletionState();
                refreshFieldAndSectionErrorStates();
            });
        }

        $field('#cnpj').on('input', function () {
            var $input = $(this);
            let digits;

            trackLeadStart();

            $input.val(maskCnpj($input.val() || ''));
            $input.removeClass('valid');
            digits = cleanCnpjDigits($input.val());

            if (digits.length === 14 && digits !== lastCnpj) {
                lastCnpj = digits;
                clearTimeout(cnpjTimer);
                cnpjTimer = setTimeout(function () {
                    consultarCnpj(digits);
                }, 500);
                return;
            }

            if (digits.length < 14) {
                lastCnpj = '';
                clearInlineFieldError($input);
                $input.attr('aria-invalid', 'false');
                resetCnpjStatus();
            }
        });

        $field('#cnpj').on('blur', function () {
            let digits = cleanCnpjDigits($(this).val());

            if (digits.length === 0) {
                return;
            }

            if (digits.length !== 14 || !isValidCnpjChecksum(digits)) {
                markCnpjInvalid(getCnpjRejectionMessage());
                refreshFieldAndSectionErrorStates();
            }
        });

        $field('#phone').on('input', function () {
            var $input = $(this);
            trackLeadStart();
            $input.val(maskPhone($input.val() || ''));
        });

        $field('#cep').on('input', function () {
            var $input = $(this);
            let digits;

            $input.val(maskCep($input.val() || ''));
            digits = ($input.val() || '').replace(/\D/g, '');

            clearTimeout(cepLookupTimer);

            if (digits.length === 8) {
                cepLookupTimer = window.setTimeout(function () {
                    lookupRegisterCep(digits);
                }, 400);
            }
        });

        $field('#cep').on('blur', function () {
            lookupRegisterCep($(this).val() || '');
        });

        $field('#email').on('input blur', function () {
            trackLeadStart();

            if (erpEmailMasked) {
                showErpEmailAlert();
            }

            updateEmailCheckAlert();
        });

        $field('#password, #password_confirmation').on('input blur', updateEmailCheckAlert);

        $field('#razao_social, #nome_fantasia, #inscricao_estadual, #firstname, #lastname')
            .on('input blur', trackLeadStart);

        $form.on('input change blur', 'input, select, textarea', function () {
            updateProgressCompletionState();
            window.setTimeout(refreshFieldAndSectionErrorStates, 0);
        });

        $form.on('focusin', '.form-section :input, .form-section [contenteditable]', function () {
            syncProgressFromFocus($(this));
        });

        $form.on('click', '.b2b-register-progress .progress-step', function (event) {
            var $step = $(this);
            let targetSelector = $step.attr('data-step-target');
            let stepNumber = $step.attr('data-step-number');
            var $section = targetSelector ? $form.find(targetSelector) : $();
            let targetStep = parseInt(stepNumber, 10) || 0;

            event.preventDefault();

            if ($step.prop('disabled') || $step.attr('aria-disabled') === 'true') {
                return;
            }

            if (!$section.length || !targetStep) {
                return;
            }

            if (targetStep > currentProgressStep && !validateStepsBeforeTarget(targetStep)) {
                return;
            }

            setActiveProgressStep(stepNumber);
            openStepSection($section, true, true);
            scrollToSection($section);
            focusFirstFieldInSection($section);
        });

        $form.on('click', '.form-section__toggle', function (event) {
            var $toggle = $(this);
            var $section = $toggle.closest('.form-section');
            let stepNumber = parseInt($section.data('step'), 10);
            let isExpanded = $toggle.attr('aria-expanded') === 'true';

            event.preventDefault();

            if (!$section.length) {
                return;
            }

            if (stepNumber > currentProgressStep && !validateStepsBeforeTarget(stepNumber)) {
                return;
            }

            if (stepNumber) {
                setActiveProgressStep(stepNumber);
            }

            if (isExpanded) {
                setStepSectionExpanded($section, false, true);
                return;
            }

            openStepSection($section, true, true);
            scrollToSection($section);
        });

        if ($benefitsToggle.length && $benefitsPanel.length) {
            $benefitsToggle.on('click', function (event) {
                let isExpanded;

                event.preventDefault();

                if (!isCompactBenefitsViewport()) {
                    return;
                }

                isExpanded = $benefitsToggle.attr('aria-expanded') === 'true';
                setBenefitsExpanded(!isExpanded, true);
            });

            if (benefitsViewportMedia) {
                if (typeof benefitsViewportMedia.addEventListener === 'function') {
                    benefitsViewportMedia.addEventListener('change', function () {
                        syncBenefitsDisclosure();
                        syncStepSectionsDisclosure();
                        scheduleProgressViewportSync();
                    });
                } else if (typeof benefitsViewportMedia.addListener === 'function') {
                    benefitsViewportMedia.addListener(function () {
                        syncBenefitsDisclosure();
                        syncStepSectionsDisclosure();
                        scheduleProgressViewportSync();
                    });
                }
            } else {
                $(window).on('resize.b2bBenefitsDisclosure', function () {
                    syncBenefitsDisclosure();
                    syncStepSectionsDisclosure();
                    scheduleProgressViewportSync();
                });
            }
        }

        $(window).on('resize.b2bRegisterAccordion', function () {
            syncBenefitsDisclosure();
            syncStepSectionsDisclosure();
        });

        $(document)
            .off('click.b2bRegisterForceRefresh', '#cnpj-force-refresh')
            .on('click.b2bRegisterForceRefresh', '#cnpj-force-refresh', function (event) {
                let digits;

                event.preventDefault();
                digits = ($field('#cnpj').val() || '').replace(/\D/g, '');

                if (digits.length === 14) {
                    consultarCnpj(digits, true);
                }
            });

        $form.find('input').on('focus', function () {
            $(this).removeClass('autofilled');
        });

        $form.on('submit', function (event) {
            var $submitButton;

            if ($form.data('isSubmitting')) {
                event.preventDefault();
                return false;
            }

            expandAllStepSections(false);
            $form.addClass('is-register-final-step');
            $form.find('.terms-section, .actions-toolbar').removeAttr('hidden');

            if (!isCnpjAccepted()) {
                event.preventDefault();
                goToStepSection(1, false);
                markCnpjInvalid(getCnpjRejectionMessage());
                window.setTimeout(function () {
                    refreshFieldAndSectionErrorStates();
                    focusFirstInvalidField();
                }, 0);
                return false;
            }

            if (typeof $form.validation === 'function' && !$form.validation('isValid')) {
                event.preventDefault();
                window.setTimeout(function () {
                    refreshFieldAndSectionErrorStates();
                    focusFirstInvalidField();
                }, 0);
                return false;
            }

            $form.data('isSubmitting', true).addClass('is-submitting');
            $form.attr('aria-busy', 'true');
            announceFormStatus('Enviando cadastro. Aguarde.');
            $submitButton = $form.find('.actions-toolbar .create-b2b-account');
            $submitButton.addClass('is-loading').prop('disabled', true).attr('aria-busy', 'true');
            $submitButton.find('span').text(sendingLabel);

            return true;
        });

        function getPasswordStrength(password)
        {
            let classes = getPasswordClassCount(password);

            if (password.length === 0) {
                return '';
            }

            if (password.length < passwordMinLength || classes < passwordMinClasses) {
                return 'weak';
            }

            if (password.length < 10) {
                return 'medium';
            }

            return 'strong';
        }

        function initPasswordStrengthMeter()
        {
            var $meter = $field('#password-strength-meter');
            var $label = $field('#password-strength-label');
            if (!$meter.length) {
                return; }
            let strengthLabels = { weak: 'Fraca', medium: 'Média', strong: 'Forte' };
            $field('#password').on('input.strengthMeter', function () {
                let strength = getPasswordStrength(String($(this).val() || ''));
                $meter.removeClass('is-weak is-medium is-strong');
                $meter.attr('aria-valuenow', '0');
                $label.text('');
                if (strength) {
                    let strengthValues = { weak: 33, medium: 66, strong: 100 };
                    $meter.addClass('is-' + strength);
                    $meter.attr('aria-valuenow', String(strengthValues[strength]));
                    $label.text(strengthLabels[strength]);
                }
            });
        }

        initStepSectionAccordionMarkup();
        $stepSections.css('display', '');
        initRegisterPasswordToggles();
        initPasswordStrengthMeter();
        initIeIsentoToggle();
        attachCnpjValidationRule();
        initStepNavigation();
        setActiveProgressStep(1);
        syncBenefitsDisclosure();
        syncStepSectionsDisclosure();
        updateEmailCheckAlert();
        updateSubmitState();
        updateProgressCompletionState();
        refreshFieldAndSectionErrorStates();
        scheduleProgressViewportSync();

        let initialCnpjDigits = ($field('#cnpj').val() || '').replace(/\D/g, '');
        if (initialCnpjDigits.length === 14) {
            lastCnpj = initialCnpjDigits;
            consultarCnpj(initialCnpjDigits);
        }

        $form.addClass('is-ready');
    };
});
