/**
 * AWA Guided Purchase Coach — modular proactive tip on the AI FAB.
 * Zero network; sessionStorage + localStorage gates; CustomEvent hooks for analytics.
 * v2: 7-day suppress, modal detection, B2B scenario, audio opt-in.
 */
define([
    'ko'
], function (ko) {
    'use strict';

    var STORAGE = {
        firstVisitSeen: 'awa_guide_first_visit_seen',
        guestSeen:  'awa_guide_guest_seen',
        customerSeen: 'awa_guide_customer_seen',
        authSeen:   'awa_guide_auth_seen',
        b2bSeen:    'awa_guide_b2b_seen',
        wasGuest:   'awa_guide_was_guest',
        soundOn:    'awa_guide_sound',
        suppressUntil: 'awa_guide_suppress_until',
        registerClosed: 'awa_guide_register_closed_v2'
    };

    var REGISTER_STEP_MESSAGES = {
        '1': 'Digite o CNPJ da empresa. Depois confira os dados encontrados.',
        '2': 'Digite o CEP e confira número e complemento.',
        '3': 'Informe o contato da pessoa que acessará a AWA Motos. Evite usar o e-mail do contador.',
        '4': 'Crie sua senha. Por segurança, ela não pode ser visualizada pelo assistente.'
    };

    var CHECKOUT_BODY = [
        'checkout-index-index',
        'onepagecheckout-index-index',
        'checkout-cart-index',
        'rokanthemes-onepagecheckout'
    ];

    function storageGet(key) {
        try {
            return sessionStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            sessionStorage.setItem(key, value);
        } catch (e) {
            /* private mode / blocked storage */
        }
    }

    function lsGet(key) {
        try { return localStorage.getItem(key); } catch (e) { return null; }
    }

    function lsSet(key, value) {
        try { localStorage.setItem(key, value); } catch (e) { /* ignore */ }
    }

    function isSuppressedGlobally(suppressDays) {
        var until = parseInt(lsGet(STORAGE.suppressUntil) || '0', 10);
        return until && Date.now() < until;
    }

    function setSuppressed(suppressDays) {
        var days = suppressDays || 7;
        lsSet(STORAGE.suppressUntil, String(Date.now() + days * 86400 * 1000));
    }

    function isModalOpen() {
        /* Detecta modais comuns do Magento e do tema */
        var selectors = [
            '.modal-popup.modal-slide._show',
            '.modals-wrapper .modal-popup[aria-hidden="false"]',
            '.modal-overlay',
            '.fancybox-is-open',
            '[data-role="modal"].modal-slide._show',
        ];
        for (var i = 0; i < selectors.length; i++) {
            if (document.querySelector(selectors[i])) {
                return true;
            }
        }
        return false;
    }

    /* Web Audio chime — somente após consentimento explícito */
    function playChime() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.setValueAtTime(1100, ctx.currentTime + 0.08);
            gain.gain.setValueAtTime(0.15, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.35);
        } catch (e) { /* ignore if AudioContext blocked */ }
    }

    function isCheckoutPage() {
        var body = document.body;
        if (!body || !body.classList) {
            return false;
        }
        return CHECKOUT_BODY.some(function (cls) {
            return body.classList.contains(cls);
        });
    }

    function isRegisterPage() {
        var body = document.body;
        if (body && body.classList && body.classList.contains('b2b-register-index')) {
            return true;
        }
        return !!document.getElementById('b2b-register-form');
    }

    function isCookieBannerVisible() {
        var banner = document.getElementById('awa-cookie-banner');
        var style;

        if (!banner || !banner.classList.contains('awa-cookie-banner--visible')) {
            return false;
        }

        style = window.getComputedStyle ? window.getComputedStyle(banner) : null;
        return !style || (
            style.display !== 'none' &&
            style.visibility !== 'hidden' &&
            style.opacity !== '0'
        );
    }

    function currentRegisterStep() {
        var active = document.querySelector('.progress-step.is-active');
        if (!active) {
            return '1';
        }
        return String(active.getAttribute('data-step-number') || '1');
    }

    function emit(action, detail) {
        try {
            document.dispatchEvent(new CustomEvent('awa:guide:action', {
                detail: Object.assign({ action: action }, detail || {})
            }));
        } catch (e) {
            /* older webviews */
        }
    }

    function scheduleIdle(fn, delayMs) {
        var cancelled = false;
        var timerId = null;
        var idleId = null;

        function run() {
            if (cancelled) {
                return;
            }
            if (typeof window.requestIdleCallback === 'function') {
                idleId = window.requestIdleCallback(function () {
                    if (!cancelled) {
                        fn();
                    }
                }, { timeout: 1500 });
            } else {
                fn();
            }
        }

        timerId = window.setTimeout(run, delayMs);

        return function cancel() {
            cancelled = true;
            if (timerId) {
                window.clearTimeout(timerId);
            }
            if (idleId && typeof window.cancelIdleCallback === 'function') {
                window.cancelIdleCallback(idleId);
            }
        };
    }

    function focusSearch() {
        var input = document.getElementById('search')
            || document.querySelector('input#search, .block-search input[type="text"], .awa-professional-search input');
        var label = document.querySelector(
            '.block-search .label, .block-search .action.search, [data-action="toggle-search"]'
        );

        if (label && window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
            try {
                label.click();
            } catch (e) { /* ignore */ }
        }

        if (input) {
            requestAnimationFrame(function () {
                input.focus();
                if (typeof input.select === 'function') {
                    input.select();
                }
            });
            return true;
        }
        return false;
    }

    /**
     * Mix coach observables + methods onto the chat uiComponent instance.
     *
     * @param {Object} component
     * @param {Object} coachConfig
     * @returns {Object}
     */
    function attach(component, coachConfig) {
        var cfg = coachConfig || {};
        var urls = cfg.urls || {};
        var cancelSchedule = null;
        var autoDismissTimer = null;

        component.coachVisible = ko.observable(false);
        component.coachScenario = ko.observable('');
        component.coachMessage = ko.observable('');
        component.coachShowHowTo = ko.observable(false);
        component.coachHowToBuyUrl = ko.observable(cfg.howToBuyUrl || '');
        component.coachPulse = ko.observable(false);
        /* B3: marcador de não lido. Fica true só quando o coach some sozinho
         * (auto-dismiss) — mensagem proativa que o cliente não chegou a ver.
         * Fechar no X ou agir são decisões conscientes e NÃO marcam. */
        component.coachUnread = ko.observable(false);
        component.soundEnabled = ko.observable(lsGet(STORAGE.soundOn) === '1');
        component._coachCfg = cfg;
        component._coachUrls = urls;

        /* PureComputeds: KO css/visible with `scenario() === 'x'` evaluated once at bind. */
        component.coachCss = ko.pureComputed(function () {
            return {
                'awa-ai-coach--visible': !!component.coachVisible(),
                'awa-ai-coach--howto': !!component.coachShowHowTo()
            };
        });
        component.isGuestCoach = ko.pureComputed(function () {
            var scenario = component.coachScenario();
            return scenario === 'first_visit' || scenario === 'guest';
        });
        component.isAuthCoach = ko.pureComputed(function () {
            var scenario = component.coachScenario();
            return scenario === 'customer' || scenario === 'auth';
        });
        component.fabPulseCss = ko.pureComputed(function () {
            return {
                'awa-ai-chat__toggle--pulse': !!(component.coachPulse() && component.coachVisible())
            };
        });

        /* Sound toggle — only after user interaction */
        component.toggleSound = function () {
            var next = !component.soundEnabled();
            component.soundEnabled(next);
            lsSet(STORAGE.soundOn, next ? '1' : '0');
            if (next) {
                playChime();
            }
            emit('sound_toggle', { enabled: next });
        };

        component._markScenarioSeen = function (scenario) {
            if (scenario === 'first_visit') {
                lsSet(STORAGE.firstVisitSeen, '1');
                storageSet(STORAGE.guestSeen, '1');
            } else if (scenario === 'guest') {
                storageSet(STORAGE.guestSeen, '1');
            } else if (scenario === 'customer' || scenario === 'auth') {
                storageSet(STORAGE.customerSeen, '1');
                storageSet(STORAGE.authSeen, '1');
            } else if (scenario === 'b2b') {
                storageSet(STORAGE.b2bSeen, '1');
            }
        };

        component._clearAutoDismiss = function () {
            if (autoDismissTimer) {
                window.clearTimeout(autoDismissTimer);
                autoDismissTimer = null;
            }
        };

        component.dismissCoach = function (reason) {
            var scenario = component.coachScenario();
            component._clearAutoDismiss();
            component.coachVisible(false);
            component.coachPulse(false);
            component.coachShowHowTo(false);
            if (scenario) {
                component._markScenarioSeen(scenario);
            }
            /* B3: só o desaparecimento automático deixa pendência. */
            component.coachUnread(reason === 'auto');
            /* Suppress globally for N days on explicit user dismissal, except cadastro B2B (sessão). */
            if (reason === 'close' || reason === 'dismiss') {
                if (isRegisterPage()) {
                    storageSet(STORAGE.registerClosed, '1');
                } else {
                    setSuppressed(parseInt(cfg.suppressDays, 10) || 7);
                }
            }
            emit('dismiss', { scenario: scenario, reason: reason || 'close' });
        };

        component._showCoach = function (scenario, message) {
            /* Evita sobrepor a escolha de privacidade na primeira visita. */
            if (isCookieBannerVisible()) {
                return;
            }

            /* PLANO B2B F2.3 (lazy init): o coach só existe com contexto privado
               válido (binding visible: privateContextReady no widget). Com a
               verificação de sessão sob demanda, dispara-a aqui antes de mostrar —
               preserva a abordagem proativa sem chamada de sessão no load. */
            if (typeof component._requestPrivateVerification === 'function') {
                component._requestPrivateVerification();
            }

            component.coachScenario(scenario);
            component.coachMessage(message);
            component.coachShowHowTo(false);
            component.coachVisible(true);
            component.coachPulse(true);
            /* Card aberto = mensagem à vista; nada pendente. */
            component.coachUnread(false);
            emit('show', { scenario: scenario });
            /* Chime somente se usuário optou pelo som */
            if (component.soundEnabled()) {
                playChime();
            }

            var dismissMs = parseInt(cfg.autoDismissMs, 10) || 20000;
            component._clearAutoDismiss();
            autoDismissTimer = window.setTimeout(function () {
                if (component.coachVisible()) {
                    component.dismissCoach('auto');
                }
            }, dismissMs);
        };

        component.coachAction = function (action) {
            var scenario = component.coachScenario();
            emit(action, { scenario: scenario });

            switch (action) {
            case 'open_guide':
                if (typeof component.startSlotGuide === 'function') {
                    component.startSlotGuide();
                }
                break;
            case 'search':
                component.dismissCoach('search');
                focusSearch();
                break;
            case 'how_to_buy':
                component.coachShowHowTo(true);
                component._clearAutoDismiss();
                break;
            case 'login':
                component._markScenarioSeen(scenario);
                if (urls.login) {
                    window.location.href = urls.login;
                }
                break;
            case 'register':
                component._markScenarioSeen(scenario);
                if (urls.register) {
                    window.location.href = urls.register;
                }
                break;
            case 'orders':
                component._markScenarioSeen(scenario);
                if (urls.orders) {
                    window.location.href = urls.orders;
                }
                break;
            case 'reorder':
                component._markScenarioSeen(scenario);
                if (urls.reorder) {
                    window.location.href = urls.reorder;
                }
                break;
            case 'open_chat':
                component.dismissCoach('open_chat');
                if (!component.isOpen()) {
                    component.toggleChat();
                }
                component.messages.push({
                    role: 'assistant',
                    content: scenario === 'b2b'
                        ? 'Olá! Posso te ajudar com cotações, crédito, recompra ou qualquer dúvida sobre o painel B2B.'
                        : (scenario === 'customer' || scenario === 'auth')
                        ? 'Perfeito, eu te ajudo agora. Você pode pedir peça por moto, SKU ou número do pedido.'
                        : 'Claro! Me diga a moto ou o código da peça e eu te ajudo a encontrar rápido.',
                    products: []
                });
                break;
            case 'how_to_buy_link':
                component._markScenarioSeen(scenario);
                if (cfg.howToBuyUrl) {
                    window.location.href = cfg.howToBuyUrl;
                }
                break;
            case 'help_center':
                component._markScenarioSeen(scenario);
                if (urls.helpCenter) {
                    window.location.href = urls.helpCenter;
                }
                break;
            default:
                break;
            }
        };

        /**
         * Resolve o cenário mais adequado: b2b | first_visit | guest | customer.
         * Prioridade: b2b > first_visit > guest > customer.
         * Não exibe se houver supressão global ou modal aberto.
         */
        component._pickScenario = function () {
            if (isRegisterPage()) {
                if (storageGet(STORAGE.registerClosed) === '1') {
                    return null;
                }
                var step = currentRegisterStep();
                return {
                    scenario: 'register_step_' + step,
                    message: REGISTER_STEP_MESSAGES[step] || REGISTER_STEP_MESSAGES['1']
                };
            }

            if (isSuppressedGlobally(parseInt(cfg.suppressDays, 10) || 7)) {
                return null;
            }
            if (isModalOpen()) {
                return null;
            }

            /* B2B: cliente logado e aprovado */
            if (component.isB2B) {
                if (storageGet(STORAGE.b2bSeen) === '1') {
                    return null;
                }
                return {
                    scenario: 'b2b',
                    message: cfg.b2bMessage
                        || 'Novo aqui? Veja como usar cotações, crédito e gestão da carteira B2B.',
                };
            }

            if (!component.isLoggedIn) {
                storageSet(STORAGE.wasGuest, '1');

                if (lsGet(STORAGE.firstVisitSeen) !== '1') {
                    return {
                        scenario: 'first_visit',
                        message: cfg.firstVisitMessage
                            || 'Bem-vindo a AWA Motos! Quer ajuda para encontrar a peça certa no primeiro acesso?',
                    };
                }

                if (storageGet(STORAGE.guestSeen) === '1') {
                    return null;
                }
                return {
                    scenario: 'guest',
                    message: cfg.guestMessage
                        || 'Precisa de ajuda para encontrar a peça certa? Eu te guio em poucos cliques.',
                };
            }

            if (storageGet(STORAGE.customerSeen) === '1' || storageGet(STORAGE.authSeen) === '1') {
                storageSet(STORAGE.wasGuest, '0');
                return null;
            }

            storageSet(STORAGE.wasGuest, '0');
            return {
                scenario: 'customer',
                message: cfg.customerMessage || cfg.authMessage
                    || 'Precisa de ajuda para encontrar uma peça ou acompanhar seu pedido?',
            };
        };

        component.startGuidedCoach = function () {
            if (!component.isLoggedIn) {
                storageSet(STORAGE.wasGuest, '1');
            }

            if (isCheckoutPage()) {
                return;
            }
            if (!cfg.enabled && !isRegisterPage()) {
                return;
            }

            /* Não exibir em login Magento (o cadastro B2B tem coach próprio). */
            var bodyClasses = document.body && document.body.className || '';
            if (!isRegisterPage()
                && /customer-account-login|customer-account-create|customer-account-forgotpassword/.test(bodyClasses)
            ) {
                return;
            }

            var delay = isRegisterPage() ? 600 : (parseInt(cfg.delayMs, 10) || 6000);

            cancelSchedule = scheduleIdle(function () {
                if (component.isOpen && component.isOpen()) {
                    return;
                }
                var pick = component._pickScenario();
                if (pick) {
                    component._showCoach(pick.scenario, pick.message);
                }
            }, delay);

            if (isRegisterPage()) {
                var progress = document.querySelector('.b2b-register-progress');
                if (progress && typeof MutationObserver === 'function') {
                    var lastStep = currentRegisterStep();
                    var observer = new MutationObserver(function () {
                        if (storageGet(STORAGE.registerClosed) === '1') {
                            return;
                        }
                        var nextStep = currentRegisterStep();
                        if (nextStep === lastStep) {
                            return;
                        }
                        lastStep = nextStep;
                        var pick = component._pickScenario();
                        if (pick) {
                            component._showCoach(pick.scenario, pick.message);
                            emit('register_step_help', { step: nextStep });
                        }
                    });
                    observer.observe(progress, { attributes: true, subtree: true, attributeFilter: ['class', 'aria-current'] });
                }
            }

            component._coachOpenSub = component.isOpen.subscribe(function (open) {
                if (open && cancelSchedule) {
                    cancelSchedule();
                    cancelSchedule = null;
                }
                if (open && component.coachVisible()) {
                    component.dismissCoach('chat_open');
                }
            });
        };

        component.startGuidedCoach();

        return component;
    }

    return {
        attach: attach,
        STORAGE: STORAGE,
        isCheckoutPage: isCheckoutPage
    };
});
