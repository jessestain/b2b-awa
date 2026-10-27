/* Private section proof controls presentation only; backend authorization is unchanged. */
define([
    'GrupoAwamotos_AiAssistant/js/ai-assistant',
    'Magento_Customer/js/customer-data',
    'ko',
    'mage/url'
], function (Assistant, customerData, ko, urlBuilder) {
    'use strict';

    function parseContext(body) {
        var value = body && body.b2b_panel && body.b2b_panel.ai_context;
        if (!value || typeof value.is_logged_in !== 'boolean'
            || typeof value.is_approved !== 'boolean'
            || typeof value.identity_key !== 'string'
            || (value.is_approved && !value.is_logged_in)
            || (value.is_logged_in ? !/^[a-f0-9]{64}$/.test(value.identity_key)
                : value.identity_key !== 'guest')) {
            throw new Error('Invalid private context');
        }
        return {
            key: value.identity_key,
            logged: value.is_logged_in,
            approved: value.is_approved
        };
    }

    function sameContext(left, right) {
        return left && right && left.key === right.key
            && left.logged === right.logged && left.approved === right.approved;
    }

    return Assistant.extend({
        initialize: function () {
            this._privateDestroyed = false;
            this._privateGeneration = 0;
            this._conversationEpoch = 0;
            this._verifiedContext = null;
            this._privateWaiters = [];
            this._privateSubscriptions = [];
            this.privateContextReady = ko.observable(false);
            this.privateContextError = ko.observable(false);
            this._privateFlags(null);
            this._super();

            var self = this;
            ['customer', 'b2b_panel'].forEach(function (name) {
                self._privateSubscriptions.push(customerData.get(name).subscribe(function (section) {
                    self._privateHint(name, section);
                    self._requestPrivateVerification();
                }));
            });
            this._privateStorageListener = function (event) {
                if (event.key !== 'mage-cache-storage'
                    && event.key !== 'mage-cache-storage-section-invalidation'
                    && event.key !== null) {
                    return;
                }
                if (event.key === 'mage-cache-storage') {
                    try {
                        var data = JSON.parse(event.newValue || '{}');
                        if (data.customer) { self._privateHint('customer', data.customer); }
                        if (data.b2b_panel) { self._privateHint('b2b_panel', data.b2b_panel); }
                        if (event.newValue === null) { self._clearPrivateConversation(); }
                    } catch (error) { self._clearPrivateConversation(); }
                } else if (event.key === null) {
                    self._clearPrivateConversation();
                }
                self._requestPrivateVerification();
            };
            this._privateFocusListener = function () { self._requestPrivateVerification(); };
            window.addEventListener('storage', this._privateStorageListener);
            window.addEventListener('focus', this._privateFocusListener);
            /* PLANO B2B F2.3 (lazy init): NÃO verificar sessão no load — para o
               visitante sem sessão, uma falha transiente aqui deixava o painel em
               estado de erro ("Não foi possível verificar sua sessão…") antes de
               qualquer interação. A verificação ocorre sob demanda: em toggleChat()
               (abertura), na exibição do coach (guided-coach.js) e em mudanças de
               customer-data (login/logout/storage/focus). */
            return this;
        },

        _privateFlags: function (context) {
            this.isLoggedIn = !!(context && context.logged);
            this.isB2B = !!(context && context.approved);
            this.channel = this.isB2B ? 'b2b' : 'storefront';
        },

        _privateHint: function (name, section) {
            // Snapshots may invalidate memory, but can never grant an identity or approval.
            if ((name === 'customer' && section && !section.firstname && !section.fullname)
                || (name === 'b2b_panel' && section && section.ai_context
                    && this._verifiedContext
                    && !sameContext(this._verifiedContext, {
                        key: section.ai_context.identity_key,
                        logged: section.ai_context.is_logged_in,
                        approved: section.ai_context.is_approved
                    }))) {
                this._clearPrivateConversation();
            }
        },

        _clearPrivateConversation: function () {
            this._conversationEpoch += 1;
            this._privateRestoreFocus = false;
            this._privateOpeningFocus = false;
            this._privateOpeningOrigin = null;
            this._privateWaiters = [];
            this._history = [];
            this.inputText('');
            this.errorMsg('');
            this.isLoading(false);
            this.messages(this.welcomeMessage ? [{
                role: 'assistant', content: this.welcomeMessage, products: [], confirmation: null
            }] : []);
            this.guideState({
                active: false, step: 'part', part: '', brand: '', model: '', year: '', skipped_part: false
            });
            this.guideChips([{
                id: 'start', caption: 'Me guia passo a passo', value: '', action: 'start_guide', primary: true
            }]);
            this.guideProgress(0);
            this.guideTotal(3);
            if (typeof this._clearAutoDismiss === 'function') { this._clearAutoDismiss(); }
            ['coachVisible', 'coachUnread'].forEach(function (name) {
                if (typeof this[name] === 'function') { this[name](false); }
            }, this);
            ['coachMessage', 'coachScenario'].forEach(function (name) {
                if (typeof this[name] === 'function') { this[name](''); }
            }, this);
        },

        _requestPrivateVerification: function () {
            if (this._privateDestroyed) { return; }
            if (document.activeElement && document.activeElement.id === 'awa-ai-chat-input') {
                this._privateRestoreFocus = true;
            }
            this.privateContextReady(false);
            this.privateContextError(false);
            this._privateFlags(null);
            this._privateGeneration += 1;
            if (this._privateController) { this._privateController.abort(); }
            clearTimeout(this._privateTimeout);
            clearTimeout(this._privateDebounce);
            var self = this;
            var generation = this._privateGeneration;
            // Coalesce section publications from the same customer-data update.
            this._privateDebounce = setTimeout(function () {
                self._fetchPrivateContext(generation);
            }, 30);
        },

        _fetchPrivateContext: function (generation) {
            if (this._privateDestroyed || generation !== this._privateGeneration) { return; }
            var self = this;
            var controller = new AbortController();
            this._privateController = controller;
            var settled = false;
            function fail() {
                if (settled || self._privateDestroyed || generation !== self._privateGeneration) { return; }
                settled = true;
                clearTimeout(self._privateTimeout);
                self._verifiedContext = null;
                self._privateFlags(null);
                self._clearPrivateConversation();
                self.privateContextReady(false);
                self.privateContextError(true);
            }
            this._privateTimeout = setTimeout(function () { fail(); controller.abort(); }, 8000);
            try {
                window.fetch(urlBuilder.build('customer/section/load/') + '?sections=b2b_panel', {
                    method: 'GET', credentials: 'same-origin', cache: 'no-store',
                    headers: {'Accept': 'application/json'}, signal: controller.signal
                }).then(function (response) {
                    if (!response.ok) { throw new Error('Private context unavailable'); }
                    return response.json();
                }).then(function (body) {
                    if (settled || self._privateDestroyed || generation !== self._privateGeneration) { return; }
                    var next = parseContext(body);
                    settled = true;
                    clearTimeout(self._privateTimeout);
                    if (!sameContext(self._verifiedContext, next)) {
                        var initialOpening = !self._verifiedContext && self._privateOpeningFocus;
                        var openingOrigin = self._privateOpeningOrigin;
                        self._clearPrivateConversation();
                        if (initialOpening) {
                            self._privateOpeningFocus = true;
                            self._privateOpeningOrigin = openingOrigin;
                            self._privateRestoreFocus = true;
                        }
                    }
                    self._verifiedContext = next;
                    self._privateFlags(next);
                    self.privateContextError(false);
                    self.privateContextReady(true);
                    var waiting = self._privateWaiters;
                    self._privateWaiters = [];
                    waiting.forEach(function (item) {
                        if (!self._privateDestroyed && item.epoch === self._conversationEpoch
                            && self.privateContextReady()) { item.action(); }
                    });
                    self._restorePrivateInputFocus();
                }).catch(fail);
            } catch (error) { fail(); }
        },

        _restorePrivateInputFocus: function () {
            if (!this._privateRestoreFocus || !this.isOpen()) { return; }
            var self = this;
            var epoch = this._conversationEpoch;
            requestAnimationFrame(function () {
                if (!self._privateRestoreFocus || self._privateDestroyed || epoch !== self._conversationEpoch
                    || !self.privateContextReady() || !self.isOpen()) { return; }
                var active = document.activeElement;
                var input = document.getElementById('awa-ai-chat-input');
                if (active && active !== document.body && active !== input
                    && !(self._privateOpeningFocus && active === self._privateOpeningOrigin)) {
                    self._privateRestoreFocus = false;
                    return;
                }
                if (input && !input.disabled && document.hasFocus()) {
                    self._privateRestoreFocus = false;
                    self._privateOpeningFocus = false;
                    input.focus({preventScroll: true});
                }
            });
        },

        sendMessage: function (data, event) {
            if (!this.privateContextReady() || this._privateDestroyed) {
                if (event && event.preventDefault) { event.preventDefault(); }
                return false;
            }
            return this._super(data, event);
        },

        confirmWrite: function (message) {
            if (!this.privateContextReady() || this._privateDestroyed) { return; }
            return this._super(message);
        },

        _postJson: function (payload, onDone) {
            if (!this.privateContextReady() || this._privateDestroyed) { return; }
            var epoch = this._conversationEpoch;
            var self = this;
            var parentPost = this._super.bind(this);
            // Prove the same current session before transmitting history or a confirmation token.
            this._privateWaiters.push({epoch: epoch, action: function () {
                parentPost(payload, function (error, response) {
                    if (self._privateDestroyed || epoch !== self._conversationEpoch) { return; }
                    // A response must also wait for fresh proof before re-entering the visible UI.
                    self._privateWaiters.push({epoch: epoch, action: function () { onDone(error, response); }});
                    self._requestPrivateVerification();
                });
            }});
            this._requestPrivateVerification();
        },

        toggleChat: function () {
            if (!this.isOpen()) {
                this._requestPrivateVerification();
                this._privateRestoreFocus = true;
                this._privateOpeningFocus = true;
                this._privateOpeningOrigin = document.activeElement;
            } else {
                this._privateRestoreFocus = false;
                this._privateOpeningFocus = false;
                this._privateOpeningOrigin = null;
            }
            return this._super();
        },

        destroy: function () {
            this._privateDestroyed = true;
            this._privateGeneration += 1;
            if (this._privateController) { this._privateController.abort(); }
            clearTimeout(this._privateTimeout);
            clearTimeout(this._privateDebounce);
            this.privateContextReady(false);
            this._privateFlags(null);
            this._clearPrivateConversation();
            this._privateSubscriptions.forEach(function (subscription) { subscription.dispose(); });
            window.removeEventListener('storage', this._privateStorageListener);
            window.removeEventListener('focus', this._privateFocusListener);
            return this._super();
        }
    });
});
