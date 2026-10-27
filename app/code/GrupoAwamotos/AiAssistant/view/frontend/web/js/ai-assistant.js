/**
 * AWA AI Assistant — Knockout/UIComponent
 * Magento 2 storefront chat widget (RequireJS + Knockout + uiComponent)
 */
define([
    'ko',
    'uiComponent',
    'jquery',
    'mage/cookies',
    'GrupoAwamotos_AiAssistant/js/guided-coach'
], function (ko, Component, $, mageCookies, guidedCoach) {
    'use strict';

    return Component.extend({

        defaults: {
            config: {}
        },

        initialize: function () {
            this._super();

            // Magento UI merges x-magento-init "config" onto the component instance.
            // this.config is often {} — never overwrite merged props from it alone.
            var cfg = this.config || {};
            this.endpoint       = this.endpoint       || cfg.endpoint       || '';
            this.title          = this.title          || cfg.title          || 'Assistente AWA';
            this.welcomeMessage = this.welcomeMessage || cfg.welcomeMessage || '';
            this.channel        = this.channel        || cfg.channel        || 'storefront';
            this.isLoggedIn     = typeof this.isLoggedIn === 'boolean'
                ? this.isLoggedIn
                : !!(cfg.isLoggedIn);
            this.isB2B          = typeof this.isB2B === 'boolean'
                ? this.isB2B
                : !!(cfg.isB2B);

            this.handoffUrl     = this.handoffUrl     || cfg.handoffUrl     || '';
            this.registerUrl    = this.registerUrl    || cfg.registerUrl    || '';
            this.guideStart     = this.guideStart     || cfg.guideStart     || {};
            this.guideIdle      = this.guideIdle      || cfg.guideIdle      || {};

            this.isOpen    = ko.observable(false);
            this.inputText = ko.observable('');
            this.isLoading = ko.observable(false);
            this.errorMsg  = ko.observable('');
            this.messages  = ko.observableArray([]);
            this.guideState = ko.observable({
                active: false,
                step: 'part',
                part: '',
                brand: '',
                model: '',
                year: '',
                skipped_part: false
            });
            this.guideChips = ko.observableArray(this._idleChipsForPage(
                (this.guideIdle && this.guideIdle.chips) || (cfg.guideIdle && cfg.guideIdle.chips) || []
            ));
            if (!this.guideChips().length) {
                this.guideChips.push({
                    id: 'start',
                    caption: 'Me guia passo a passo',
                    value: '',
                    action: 'start_guide',
                    primary: true
                });
            }
            this.guideProgress = ko.observable(0);
            this.guideTotal = ko.observable(3);

            var selfGuide = this;
            this.guideActive = ko.pureComputed(function () {
                var state = selfGuide.guideState();
                return !!(state && state.active);
            });
            this.inputPlaceholder = ko.pureComputed(function () {
                if (!selfGuide.guideActive()) {
                    return 'Digite sua pergunta…';
                }
                var step = (selfGuide.guideState() || {}).step;
                if (step === 'bike') {
                    return 'Ex.: Titan 150, Fazer 250…';
                }
                if (step === 'year') {
                    return 'Ex.: 2018';
                }
                return 'Ex.: retrovisor, bauleto…';
            });
            this.guideProgressLabel = ko.pureComputed(function () {
                if (!selfGuide.guideActive()) {
                    return '';
                }
                return 'Passo ' + selfGuide.guideProgress() + ' de ' + selfGuide.guideTotal();
            });
            this.hasGuideChips = ko.pureComputed(function () {
                return selfGuide.guideChips().length > 0;
            });

            // LLM conversation history (role/content pairs, no UI state).
            this._history = [];

            if (this._isHelpCenterPage()) {
                this.welcomeMessage = 'Olá! Na Central de Ajuda posso orientar sobre prazos, rastreio, trocas, B2B e também buscar uma peça. Escolha uma opção ou digite sua dúvida.';
            }

            if (this.welcomeMessage) {
                this.messages.push({
                    role: 'assistant',
                    content: this.welcomeMessage,
                    products: [],
                    confirmation: null
                });
            }

            var self = this;
            this.messages.subscribe(function () {
                self._scrollToBottom();
            });

            var coachCfg = this.guidedCoach || cfg.guidedCoach || {};
            guidedCoach.attach(this, coachCfg);
            this._bindGlobalA11yHandlers();
            this._bindHelpCenterAsk();

            // Knockout foreach chama click com `this` = item. Preserva o componente.
            this.selectGuideChip = this.selectGuideChip.bind(this);
            this.startSlotGuide = this.startSlotGuide.bind(this);
            this._bindGuideChipNativeClicks();

            return this;
        },

        toggleChat: function () {
            var willOpen = !this.isOpen();
            if (willOpen) {
                this._awaReturnFocus = document.activeElement;
            }
            this.isOpen(willOpen);

            if (willOpen) {
                /* B3: abrir a conversa consome a pendência do coach. */
                if (typeof this.coachUnread === 'function') {
                    this.coachUnread(false);
                }
                this._scrollToBottom();
                var el = document.getElementById('awa-ai-chat-input');
                if (el) {
                    requestAnimationFrame(function () { el.focus(); });
                }
                return;
            }

            var toggle = this._awaReturnFocus;
            if (!toggle || !toggle.isConnected || typeof toggle.focus !== 'function') {
                toggle = document.querySelector('#awa-ai-chat-root .awa-ai-chat__toggle');
            }
            this._awaReturnFocus = null;
            if (toggle) {
                requestAnimationFrame(function () { toggle.focus({preventScroll: true}); });
            }
        },

        _bindGlobalA11yHandlers: function () {
            var self = this;
            if (this._onDocumentKeydown) {
                return;
            }

            this._onDocumentKeydown = function (event) {
                if (!event || event.key !== 'Escape') {
                    return;
                }

                if (!self.isOpen()) {
                    return;
                }

                var root = document.getElementById('awa-ai-chat-root');
                if (!root || !root.contains(document.activeElement)) {
                    return;
                }

                self.toggleChat();
            };

            document.addEventListener('keydown', this._onDocumentKeydown);
        },

        /**
         * Central de Ajuda dispara `awa:helpcenter:ask` com detail.query.
         */
        _bindHelpCenterAsk: function () {
            var self = this;
            if (this._onHelpCenterAsk) {
                return;
            }

            this._onHelpCenterAsk = function (event) {
                var query = event && event.detail ? String(event.detail.query || '').trim() : '';
                window.__awaAiAskPending = '';
                if (!query) {
                    if (!self.isOpen()) {
                        self.toggleChat();
                    }
                    return;
                }
                self.openWithQuery(query);
            };

            this._onHelpCenterOpen = function () {
                if (!self.isOpen()) {
                    self.toggleChat();
                }
            };

            document.addEventListener('awa:helpcenter:ask', this._onHelpCenterAsk);
            document.addEventListener('awa:helpcenter:open', this._onHelpCenterOpen);

            if (window.__awaAiAskPending) {
                var pending = String(window.__awaAiAskPending);
                window.__awaAiAskPending = '';
                this.openWithQuery(pending);
            }
        },

        /**
         * Knockout registra o click via jQuery; cliques nativos no chip não disparam
         * esse handler. Delegação em captura na window cobre o clique real.
         */
        _bindGuideChipNativeClicks: function () {
            var self = this;

            if (window.__awaAiGuideChipClickBound) {
                return;
            }
            window.__awaAiGuideChipClickBound = true;

            window.addEventListener('click', function (event) {
                var root = document.getElementById('awa-ai-chat-root');
                var target = event.target;
                var btn = target && target.closest ? target.closest('.awa-ai-chat__chip') : null;

                if (!root || !btn || !root.contains(btn) || btn.disabled) {
                    return;
                }

                var ctx = null;
                try {
                    ctx = ko.contextFor(btn);
                } catch (err) { /* ignore */ }
                var chip = ctx && ctx.$data ? ctx.$data : null;
                if (chip) {
                    self.selectGuideChip(chip);
                }
            }, true);
        },

        /**
         * Abre o painel e envia a pergunta (mesmo fluxo do input).
         * @param {string} text
         */
        openWithQuery: function (text) {
            var query = String(text || '').trim();
            if (!query) {
                return;
            }

            if (this.isLoading()) {
                this.inputText(query);
                return;
            }

            if (typeof this.coachVisible === 'function' && this.coachVisible()
                && typeof this.dismissCoach === 'function') {
                this.dismissCoach('helpcenter_ask');
            }

            if (!this.isOpen()) {
                this.toggleChat();
            }

            this.inputText(query);
            this.sendMessage();
        },

        handleKeydown: function (data, event) {
            if (event && (event.key === 'Enter' || event.keyCode === 13) && !event.shiftKey) {
                event.preventDefault();
                this.sendMessage(data, event);
                return false;
            }
            return true;
        },

        _formKey: function () {
            var fromInput = $('input[name="form_key"]').first().val() || '';
            var fromCookie = '';
            if ($.mage && $.mage.cookies && typeof $.mage.cookies.get === 'function') {
                fromCookie = $.mage.cookies.get('form_key') || '';
            } else if (mageCookies && typeof mageCookies.get === 'function') {
                fromCookie = mageCookies.get('form_key') || '';
            }
            return fromInput || fromCookie || '';
        },

        _clearConfirmation: function (msg) {
            var list = this.messages();
            var idx = list.indexOf(msg);
            if (idx === -1) {
                return;
            }
            this.messages.splice(idx, 1, {
                role: msg.role,
                content: msg.content,
                products: msg.products || [],
                confirmation: null
            });
        },

        _postJson: function (payload, onDone) {
            var key = this._formKey();
            var url = this.endpoint || '';
            if (key) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'form_key=' + encodeURIComponent(key);
            }

            $.ajax({
                url: url,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json',
                timeout: 60000,
                headers: {
                    'X-Magento-Form-Key': key
                },
                success: function (response) {
                    onDone(null, response);
                },
                error: function (xhr) {
                    var msg = 'Não foi possível conectar ao assistente. Tente novamente.';
                    if (xhr && xhr.status === 403) {
                        msg = 'Sessão expirada. Recarregue a página e tente de novo.';
                    } else if (xhr && xhr.status === 429) {
                        /* Rate limit do Nginx (/aiassistant/). Sem este ramo a
                         * mensagem genérica "Tente novamente" incentivaria o
                         * reenvio imediato, agravando a rajada. */
                        msg = (xhr.responseJSON && xhr.responseJSON.error) ||
                            'Muitas mensagens em pouco tempo. Aguarde alguns segundos e tente de novo.';
                    }
                    onDone(msg, xhr && xhr.responseJSON ? xhr.responseJSON : null);
                }
            });
        },

        sendMessage: function (data, event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            var text = (this.inputText() || '').trim();
            if (!text) {
                var el = document.getElementById('awa-ai-chat-input');
                text = el && el.value ? String(el.value).trim() : '';
                if (text) {
                    this.inputText(text);
                }
            }
            if (!text || this.isLoading()) {
                return false;
            }

            var apiText = this._ensureHelpPrefix(text);
            var displayText = this._displayHelpQuery(text);

            this.errorMsg('');
            this.messages.push({ role: 'user', content: displayText, products: [], confirmation: null });
            this.inputText('');
            this.isLoading(true);
            this._scrollToBottom();

            var self = this;
            this._postJson({
                message: apiText,
                channel: this.channel,
                history: this._history,
                guide: this._guidePayload()
            }, function (err, response) {
                self.isLoading(false);
                if (err) {
                    self.errorMsg(err);
                    self.messages.push({ role: 'assistant', content: err, products: [], confirmation: null });
                    self._scrollToBottom();
                    return;
                }
                self._applyResponse(response);
            });

            return false;
        },

        confirmWrite: function (msg) {
            var token = msg && msg.confirmation ? msg.confirmation.token : '';
            if (!token || this.isLoading()) {
                return;
            }
            this._clearConfirmation(msg);
            this.isLoading(true);
            this._scrollToBottom();

            var self = this;
            this._postJson({
                confirm_token: token,
                history: this._history
            }, function (err, response) {
                self.isLoading(false);
                if (err) {
                    self.errorMsg(err);
                    self.messages.push({ role: 'assistant', content: err, products: [], confirmation: null });
                    self._scrollToBottom();
                    return;
                }
                self._applyResponse(response);
            });
        },

        cancelWrite: function (msg) {
            this._clearConfirmation(msg);
            this.messages.push({
                role: 'assistant',
                content: 'Ação cancelada. Nada foi alterado.',
                products: [],
                confirmation: null
            });
            this._scrollToBottom();
        },

        _applyResponse: function (response) {
            if (response && response.history) {
                this._history = response.history;
            }
            if (response && response.reply) {
                this.messages.push({
                    role: 'assistant',
                    content: response.reply,
                    products: this._normalizeProducts(response.products),
                    confirmation: response.confirmation || null
                });
            } else if (response && response.error) {
                this.errorMsg(response.error);
                this.messages.push({
                    role: 'assistant',
                    content: response.error,
                    products: [],
                    confirmation: null
                });
            }
            if (response && response.guide) {
                this._applyGuide(response.guide);
            }
            this._scrollToBottom();
        },

        /**
         * Keep only product cards with usable name + url.
         * @param {*} raw
         * @returns {Array}
         */
        _normalizeProducts: function (raw) {
            if (!Array.isArray(raw)) {
                return [];
            }
            return raw.filter(function (p) {
                return p && typeof p === 'object' && !!(p.name || p.sku) && !!p.url;
            });
        },

        formatMessage: function (content) {
            if (!content) { return ''; }

            var safe = content
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');

            safe = safe.replace(
                /(https?:\/\/[^\s<>"']+)/g,
                '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
            );

            return safe.replace(/\n/g, '<br>');
        },

        _helpQueryPrefix: /^Central de Ajuda\s+[—–-]\s*/i,

        _displayHelpQuery: function (text) {
            return String(text || '').replace(this._helpQueryPrefix, '').trim();
        },

        _ensureHelpPrefix: function (text) {
            var t = String(text || '').trim();
            if (!t) {
                return t;
            }
            if (this._isHelpCenterPage() && !this._helpQueryPrefix.test(t) && t.toLowerCase().indexOf('central de ajuda') === -1) {
                return 'Central de Ajuda — ' + t;
            }
            return t;
        },

        _normalizeChips: function (raw) {
            if (!Array.isArray(raw)) {
                return [];
            }
            var chips = raw.map(function (chip) {
                if (!chip || typeof chip !== 'object') {
                    return { id: '', caption: '', value: '', action: 'send', primary: false };
                }
                return {
                    id: String(chip.id || ''),
                    caption: String(chip.caption || chip.label || ''),
                    value: String(chip.value || ''),
                    action: String(chip.action || 'send'),
                    primary: !!chip.primary
                };
            }).filter(function (chip) {
                return chip.caption !== '' || chip.action === 'start_guide';
            });
            if (chips.length === 1 && chips[0].action === 'start_guide') {
                chips[0].primary = true;
            }
            return chips;
        },

        _isHelpCenterPage: function () {
            try {
                var path = window.location.pathname || '';
                return document.body.classList.contains('helpcenter-index-index')
                    || path === '/ajuda'
                    || path.indexOf('/ajuda/') === 0;
            } catch (err) {
                return false;
            }
        },

        _idleChipsForPage: function (fallback) {
            if (this._isHelpCenterPage()) {
                return this._normalizeChips([
                    {
                        id: 'hc-prazos',
                        caption: 'Prazos de entrega',
                        value: 'Central de Ajuda — Quais são os prazos de entrega?',
                        action: 'send',
                        primary: true
                    },
                    {
                        id: 'hc-rastrear',
                        caption: 'Rastrear pedido',
                        value: 'Central de Ajuda — Como rastrear meu pedido',
                        action: 'send'
                    },
                    {
                        id: 'hc-troca',
                        caption: 'Trocas e devoluções',
                        value: 'Central de Ajuda — Política de trocas e devoluções',
                        action: 'send'
                    },
                    {
                        id: 'start',
                        caption: 'Buscar uma peça',
                        value: '',
                        action: 'start_guide'
                    }
                ]);
            }
            return this._normalizeChips(fallback || []);
        },

        _guidePayload: function () {
            var state = this.guideState() || {};
            return {
                active: !!state.active,
                step: state.step || 'part',
                part: state.part || '',
                brand: state.brand || '',
                model: state.model || '',
                year: state.year || '',
                skipped_part: !!state.skipped_part
            };
        },

        _applyGuide: function (guide) {
            if (!guide || typeof guide !== 'object') {
                return;
            }
            this.guideState({
                active: !!guide.active,
                step: guide.step || 'part',
                part: guide.part || '',
                brand: guide.brand || '',
                model: guide.model || '',
                year: guide.year || '',
                skipped_part: !!guide.skipped_part
            });
            this.guideProgress(parseInt(guide.progress, 10) || 0);
            this.guideTotal(parseInt(guide.total, 10) || 3);
            if (!guide.active) {
                this.guideChips(this._idleChipsForPage(guide.chips));
            } else {
                this.guideChips(this._normalizeChips(guide.chips));
            }
        },

        startSlotGuide: function () {
            if (typeof this.coachVisible === 'function' && this.coachVisible()
                && typeof this.dismissCoach === 'function') {
                this.dismissCoach('open_guide');
            }
            var start = this.guideStart || {};
            this.guideState({
                active: true,
                step: 'part',
                part: '',
                brand: '',
                model: '',
                year: '',
                skipped_part: false
            });
            this.guideProgress(start.progress || 1);
            this.guideTotal(start.total || 3);
            this.guideChips(this._normalizeChips(start.chips));
            if (!this.isOpen()) {
                this.toggleChat();
            }
            if (this.messages().length <= 1) {
                this.messages.removeAll();
            }
            this.messages.push({
                role: 'assistant',
                content: start.reply || 'Vou te guiar em 3 passos. Qual peça você precisa?',
                products: [],
                confirmation: null
            });
            this._scrollToBottom();
        },

        selectGuideChip: function (chip) {
            if (this._chipHandling) {
                return;
            }
            if (!chip || typeof this.isLoading !== 'function' || this.isLoading()) {
                return;
            }
            var action = String(chip.action || 'send');
            if (action === 'start_guide' && this.guideActive()) {
                return;
            }
            this._chipHandling = true;
            try {
                if (action === 'start_guide') {
                    this.startSlotGuide();
                    return;
                }
                if (action === 'handoff' && this.handoffUrl) {
                    window.open(this.handoffUrl, '_blank', 'noopener');
                    return;
                }
                if (action === 'register' && this.registerUrl) {
                    window.location.href = this.registerUrl;
                    return;
                }
                var text = String(chip.value || chip.caption || chip.label || '').trim();
                if (!text) {
                    return;
                }
                this.inputText(text);
                this.sendMessage();
            } finally {
                this._chipHandling = false;
            }
        },

        _scrollToBottom: function () {
            var run = function () {
                var el = document.querySelector('.awa-ai-chat__messages');
                if (el) {
                    el.scrollTop = el.scrollHeight;
                }
            };
            requestAnimationFrame(function () {
                requestAnimationFrame(run);
            });
            setTimeout(run, 60);
            setTimeout(run, 280);
        }
    });
});
