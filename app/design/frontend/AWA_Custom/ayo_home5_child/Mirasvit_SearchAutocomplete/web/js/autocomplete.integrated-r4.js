define([
    'jquery',
    'js/awa-search-state-r4',
    'ko',
    'underscore',
    'mage/translate',
    'Magento_Catalog/js/price-utils',
    'Mirasvit_Search/js/highlight',
    'Magento_Customer/js/customer-data',
    'Magento_Catalog/js/catalog-add-to-cart'
], function ($, SearchState, ko, _, $t, priceUtils, highlight, customerData) {
    // AWA: compartilha XHR e um único timer de posicionamento entre instâncias
    var awaResultCache = {};
    var awaResultCacheTs = {};
    var awaResultCacheTtl = 30000;

    function awaSoftTitleCase(str) {
        if (!str || typeof str !== 'string') {
            return str;
        }
        var letters = str.replace(/[^A-Za-zÀ-ÿ]/g, '');
        if (!letters.length) {
            return str;
        }
        var upper = (letters.match(/[A-ZÀ-Ý]/g) || []).length;
        if (upper / letters.length < 0.72) {
            return str;
        }
        return str.toLowerCase().replace(/(^|[\s\/\-_.])(\S)/g, function (m, sep, ch) {
            return sep + ch.toUpperCase();
        });
    }

    var Autocomplete = function (input) {
        this.$input = $(input);
        this.searchState = SearchState.forForm(this.$input[0].form);
        this.$cat = $('[name=cat]', this.$input.closest('form'));
        this.positionTimer = null;
        this.isVisible = false;
        this.isShowAll = true;
        this.loading = false;
        this.config = [];
        this.result = false
    };

    Autocomplete.prototype = {
        placeholderSelector:      '.mst-searchautocomplete__autocomplete',
        wrapperSelector:          '.mst-searchautocomplete__wrapper',
        additionalColumnSelector: 'mst-2-cols',
        model:                    null,

        init: function (config) {
            this.config = _.defaults(config, this.defaults);
            window.priceFormat = this.config.priceFormat;
            this.searchState.configure({enhanced: true, nativeExpected: true, minLength: this.config.minSearchLength});
            if (this.$input.data('awaNativeStateBound')) { return; }
            if (!this.$input[0].form.querySelector('.mst-searchautocomplete__autocomplete')) {
                this.$input.after($('#searchAutocompletePlaceholder').html());
            }
            if (!SearchState.hosts(this.$input[0].form).native) { return; }
            SearchState.prepareForm(this.$input[0].form);
            this.$input.data('awaNativeStateBound', this);
            this.searchState.subscribe(function (state, reason) {
                if (reason === 'begin' || !state.open) { this.disposeSearchView(); }
                this.paintPanelOpen(state.open && state.owner === 'native');
            }.bind(this));

            this.xhr = null;

            this.$input.on("keyup", function (event) {
                this.clickHandler(event)
            }.bind(this));

            this.$input.on("focus", function (event) {
                event.stopPropagation();
                this.search(true);
            }.bind(this));

            this.$input.on("input compositionend", function (event) {
                if (event.type === 'compositionend') {
                    this.searchState.endComposition(this.$input.val(), SearchState.context(this.$input[0].form));
                } else if (event.originalEvent && event.originalEvent.isComposing) {
                    this.searchState.beginComposition(); return;
                }
                if (!this.searchState.snapshot().composing) { this.inputHandler(); }
            }.bind(this));

            this.$input.on('compositionstart', function () { this.searchState.beginComposition(); }.bind(this));
            this.$cat.on('change.awaNative', function () { this.search(); }.bind(this));
            SearchState.bindFocus(this.$input[0].form);

            $(document).on("click", function (event) {
                event.stopPropagation();
                this.clickHandler(event);
            }.bind(this));

            ko.bindingHandlers.highlight = {
                init: function (element, valueAccessor, allBindings, viewModel, bindingContext) {
                    var skipCase = element.classList && element.classList.contains('sku');
                    var before = String(element.textContent || '');
                    var display = skipCase ? before : awaSoftTitleCase(before);
                    if (display !== before) {
                        element.textContent = display;
                        ['title', 'aria-label'].forEach(function (attr) {
                            var cur = element.getAttribute(attr);
                            if (cur) {
                                element.setAttribute(attr, awaSoftTitleCase(cur));
                            }
                        });
                    }
                    highlight(element, bindingContext.$parents[2].result().query, 'mst-searchautocomplete__highlight');
                }
            };

            ko.bindingHandlers.processStockStatus = {
                init: function (element, valueAccessor, allBindings, viewModel, bindingContext) {
                    var value = $(element).text();
                    if (value == 2) {
                        value = $t('In stock');
                        $(element).addClass('inStock');
                    } else {
                        value = $t('Out Of Stock');
                        $(element).addClass('outOfStock');
                    }

                    $(element).text(value);
                }
            };
        },

        clickHandler: function (event) {
            if (!event || event.type == 'focus') {
                if (event.target.value.length >= this.config.minSearchLength) {
                    this.setActiveState(true);
                }
                this.ensurePosition();
                if (this.result) {
                    this.setActiveState(true);
                    if (this.positionTimer) {
                        clearInterval(this.positionTimer);
                    }
                    // 10ms gerava dezenas de timers; 100ms basta para sticky/resize
                    this.positionTimer = setInterval(function () {
                        this.ensurePosition();
                    }.bind(this), 100);
                } else {
                    this.result = this.search();
                    if (this.result) {
                        this.setActiveState(true);
                        this.ensurePosition();
                    }
                }
            } else {
                //if (event.keyCode === 13) { // restore that code if enter doens't work
                //    $(event.target).closest('form').submit();
                //    return true;
                //}

                if ($(event.target)[0] == $('label[data-role=minisearch-label]')[0]) {
                    if ($('body').hasClass('searchautocomplete__active')) {
                        this.setActiveState(false);
                        return false;
                    } else {
                        this.setActiveState(true);
                        return true;
                    }
                }

                if ($(event.target)[0] != this.$input[0] && !$(event.target).closest(this.$placeholder()).length) {
                    this.setActiveState(false);
                    return false;
                }

                if ($(event.target).hasClass('mst-searchautocomplete__close')) {
                    this.setActiveState(false);
                    return false;
                }

            }
        },

        syncSearchAria: function () {
            SearchState.syncAria(this.$input[0].form);
            return {panel: this.$placeholder()[0], input: this.$input[0]};
        },

        disposeSearchView: function () {
            this.renderTicket = null;
            var pending = this.pendingBinding;
            this.pendingBinding = null;
            if (pending && pending.root) { ko.removeNode(pending.root); }
            this.$placeholder().find('.awa-search-binding-root').each(function () { ko.removeNode(this); });
            this.$wrapper().each(function () { ko.removeNode(this); });
            this.model = null;
            this.result = false;
            this.$placeholder().removeAttr('data-awa-query data-awa-epoch');
            SearchState.syncAria(this.$input[0].form);
        },

        paintPanelOpen: function (open) {
            this.$placeholder().toggleClass('_active', open)
                .attr('aria-hidden', open ? 'false' : 'true');
            this.$input.toggleClass('searchautocomplete__active', open);
            this.syncSearchAria(open);
            if (!open && this.positionTimer) { clearInterval(this.positionTimer); this.positionTimer = null; }
            if (open) { this.ensurePosition(); }
        },

        setActiveState: function (isActive) {
            if (!isActive) { this.searchState.close('native-close'); return; }
            var state = this.searchState.snapshot();
            this.paintPanelOpen(state.open && state.owner === 'native');
        },

        inputHandler: function () {
            this.result = this.search();
        },

        $spinner: function () {
            return this.$placeholder().find(".mst-searchautocomplete__spinner");
        },

        search: function (allowPopular) {
            if (this.searchState.snapshot().composing) { return false; }
            var query = String(this.$input.val() || '');
            if (query.length) { $(this.$input[0].form).find('.actions .action.search').prop('disabled', false); }
            var popular = (allowPopular === true || query.trim().length > 0) && query.trim().length < this.config.minSearchLength
                && this.config.popularSearches && this.config.popularSearches.length;
            var state = this.searchState.begin(query, SearchState.context(this.$input[0].form), popular ? 'popular' : 'query');
            if (!state.open) { return false; }
            if (state.mode === 'popular') { return this.doPopular(); }
            SearchState.syncAria(this.$input[0].form);
            this.searchState.schedule('native-query', function () { this._doSearch(query); }.bind(this), this.config.delay);
            return true;
        },

        acceptsResponse: function (ticket) {
            var form = this.$input[0].form;
            return this.searchState.current(ticket)
                && SearchState.normalize(this.$input.val()) === ticket.query
                && SearchState.context(form) === ticket.context
                && form.contains(document.activeElement);
        },

        applyCurrentResponse: function (data, ticket) {
            if (!this.acceptsResponse(ticket)) { return; }
            if (!this.searchState.replaceNativeView(ticket)) { return; }
            this.disposeSearchView();
            var binding = {ticket: ticket, root: null, wrapper: null, complete: false, applied: false};
            this.pendingBinding = binding;
            var publish = function () {
                if (this.pendingBinding !== binding || !this.acceptsResponse(ticket)) { return; }
                if (!binding.applied || !binding.complete) { return; }
                var host = SearchState.hosts(this.$input[0].form).native;
                if (!host || !binding.root || !host.contains(binding.root)
                    || !binding.wrapper || ko.dataFor(binding.wrapper) !== this.model) { return; }
                host.setAttribute('data-awa-query', ticket.query);
                host.setAttribute('data-awa-epoch', String(ticket.epoch));
                if (this.searchState.commit(ticket)) {
                    this.renderTicket = {query: ticket.query, epoch: ticket.epoch, context: ticket.context,
                        host: host, root: binding.root, model: this.model};
                    $(this.$input[0].form).trigger('awaSearchNativeReady', [ticket]);
                }
                this.searchState.finish(ticket);
                SearchState.syncAria(this.$input[0].form);
            }.bind(this);
            binding.queue = function () {
                if (ko.tasks && ko.tasks.schedule && ko.tasks.cancel) {
                    this.searchState.scheduleTask('native-paint', publish, ko.tasks.schedule, ko.tasks.cancel);
                } else { this.searchState.schedule('native-paint', publish, 0); }
            }.bind(this);
            try {
                if (!this.processApplyBinding(data, binding)) { throw new Error('Search template unavailable'); }
                binding.applied = true;
                binding.queue();
            } catch (_bindError) {
                if (this.pendingBinding === binding) { this.disposeSearchView(); }
                else if (binding.root && binding.root.parentNode) { ko.removeNode(binding.root); }
                this.searchState.fail(ticket, 'paint');
                this.searchState.finish(ticket);
            }
        },

        _doSearch: function (query) {
            var state = this.searchState.snapshot();
            var normalized = SearchState.normalize(query);
            if (!state.open || state.query !== normalized
                || SearchState.normalize(this.$input.val()) !== normalized) { return; }
            var ticket = this.searchState.reserve('native');
            if (!ticket) { return; }
            var cacheKey = JSON.stringify([normalized, this.config.storeId, this.$cat.val(),
                this.config.currency, this.config.customerGroupId]);
            if (awaResultCache[cacheKey]
                && Date.now() - (awaResultCacheTs[cacheKey] || 0) < awaResultCacheTtl) {
                this.applyCurrentResponse(awaResultCache[cacheKey], ticket);
                return;
            }
            var request = $.ajax({
                url: this.config.url, dataType: 'json', type: 'GET',
                data: {q: query, store_id: this.config.storeId, cat: this.$cat.val(),
                    currency: this.config.currency, customer_group_id: this.config.customerGroupId},
                success: function (data) {
                    if (!this.acceptsResponse(ticket)) { return; }
                    awaResultCache[cacheKey] = data;
                    awaResultCacheTs[cacheKey] = Date.now();
                    this.applyCurrentResponse(data, ticket);
                }.bind(this),
                error: function (_xhr, status) {
                    if (status !== 'abort') { this.searchState.fail(ticket, 'transport'); }
                    this.searchState.finish(ticket);
                }.bind(this),
                complete: function () {
                    // Do not finish a successful token before its guarded KO-paint callback.
                    if (this.xhr === request) { this.xhr = null; }
                }.bind(this)
            });
            this.xhr = request;
            this.searchState.track(ticket, function () { request.abort(); });
        },

        viewModel: function (data) {
            if (this.model === null) {
                this.model = {
                    result:  ko.observable({}),
                    config:  this.config,
                    loading: ko.observable(false),

                    onMouseOver: function (item, event) {
                        $(event.currentTarget).addClass('_active');
                    }.bind(this),

                    onMouseOut: function (item, event) {
                        $(event.currentTarget).removeClass('_active');
                    }.bind(this),

                    afterRender: function (el) {
                        $(el).catalogAddToCart({});
                    }.bind(this),

                    onClick: function (item, event) {
                        if (!this.canUseRenderedResult(event.currentTarget)) { event.preventDefault(); return false; }
                        if (event.button === 0) { // left click
                            if ($(event.target).closest('.tocart').length || $(event.target).closest('.mst__add_to_cart').length) {
                                event.preventDefault();
                                return this.processAddToCart(event);
                            }

                            if ($(event.currentTarget).hasClass('popular')
                                || $(event.target).closest('a.awa-search-chip').length) {
                                return true;
                            }

                            event.preventDefault();

                            if (event.target.nodeName === 'A'
                                || event.target.nodeName === 'IMG'
                                || event.target.nodeName === 'LI'
                                || event.target.nodeName === 'SPAN'
                                || event.target.nodeName === 'DIV') {

                                this.enter(item, event.currentTarget);
                            }
                        }
                    }.bind(this),

                    onSubmit: function (item, event) {
                    }.bind(this),

                    bindPrice: function (item, event) {
                        return true;
                    }.bind(this)
                };
            }

            this.model.loading(this.loading);
            this.model.result(data);
            this.model.result().isShowAll = this.isShowAll;

            let form_key = '';
            try {
                form_key = document.cookie.match('(^|;) ?form_key=([^;]*)(;|$)')[2];
            } catch (error) {
                form_key = document.cookie.match('(^|;) ?form_key=([^;]*)(;|$)');
            }

            this.model.form_key = form_key;

            return this.model;
        },

        canUseRenderedResult: function (target) {
            var identity = this.renderTicket, form = this.$input[0].form;
            return !!identity && this.searchState.snapshot().owner === 'native'
                && this.searchState.currentQuery(identity.query, identity.epoch)
                && SearchState.normalize(this.$input.val()) === identity.query
                && SearchState.context(form) === identity.context
                && identity.host === SearchState.hosts(form).native
                && identity.model === this.model && identity.host.contains(identity.root)
                && (!target || identity.root.contains(target));
        },

        enter: function (item, target) {
            if (!this.canUseRenderedResult(target)) { return; }
            if (item.url) { window.location.href = item.url; }
            else { this.pasteToSearchString(item.query); }
        },

        pasteToSearchString: function (searchTerm) {
            this.$input.val(searchTerm);
            this.search();
        },

        doPopular: function () {
            this.$spinner().hide();
            if (!this.config.popularSearches || !this.config.popularSearches.length) { return false; }
            var ticket = this.searchState.reserve('native');
            if (!ticket) { return false; }
            this.applyCurrentResponse(this._showQueries(this.config.popularSearches), ticket);
            return true;
        },

        processApplyBinding: function (data, binding) {
            // AWA: fast_mode (direct) não passa por DI — normaliza CTA/nomes aqui
            if (data && Array.isArray(data.indexes)) {
                var productTotal = 0;
                data.indexes.forEach(function (index) {
                    if (!index) {
                        return;
                    }
                    if (index.identifier === 'magento_catalog_product'
                        || index.identifier === 'catalogsearch_fulltext') {
                        productTotal = parseInt(index.totalItems, 10) || 0;
                    }
                    if (Array.isArray(index.items)) {
                        index.items.forEach(function (item) {
                            if (item && typeof item.name === 'string') {
                                item.name = item.name.replace(/\s+/g, ' ').trim();
                            }
                        });
                    }
                });
                if (productTotal > 0) {
                    data.textAll = 'Ver todos os ' + productTotal + ' resultados →';
                }
            }

            var self = this;

            var finishLayout = function () {
                if (self.config.layout === '2columns' && data && Array.isArray(data.indexes) && data.indexes.length > 1) {
                    var result = {};
                    data.indexes.forEach(function (index) {
                        if (index && index.items && index.items.length > 0) {
                            result[index.identifier] = index.items.length;
                        }
                    });

                    if (Object.keys(result).length > 1 && typeof result.magento_catalog_product != 'undefined') {
                        self.$placeholder().addClass(self.additionalColumnSelector);
                    } else {
                        self.$placeholder().removeClass(self.additionalColumnSelector);
                    }
                }

                self.ensurePosition();
                self.bindPopularChipHrefs();
            };

            var $placeholder = self.$placeholder();
            if (!$placeholder.length || !binding || !self.acceptsResponse(binding.ticket)) { return false; }
            if (!$placeholder.attr('data-bind')) { $placeholder.attr('data-bind', 'stopBinding: true'); }
            var wrapperHtml = $('#searchAutocompleteWrapper').html();
            if (!wrapperHtml) { return false; }
            var root = document.createElement('div');
            root.className = 'awa-search-binding-root';
            root.setAttribute('data-bind', 'descendantsComplete: awaSearchDescendantsReady');
            root.innerHTML = wrapperHtml;
            binding.root = root;
            binding.wrapper = root.querySelector(self.wrapperSelector);
            if (!binding.wrapper) { return false; }
            self.viewModel(data);
            self.model.awaSearchDescendantsReady = function () {
                if (self.pendingBinding !== binding || !self.acceptsResponse(binding.ticket)) { return; }
                binding.complete = true;
                binding.queue();
            };
            $placeholder[0].appendChild(root);
            ko.applyBindings(self.model, root);
            finishLayout();
            return true;
        },

        $placeholder: function () {
            return $(this.$input.next(this.placeholderSelector));
        },

        $wrapper: function () {
            return $(this.$input.next(this.placeholderSelector).find(this.wrapperSelector));
        },

        _showQueries: function (data) {
            let self = this;
            let queries = data;
            let items = [];
            let item;
            let result, index;
            var formAction = (self.$input.closest('form').attr('action') || '/catalogsearch/result/')
                .replace(/\/+$/, '');

            _.each(queries, function (query) {
                item = {};
                item.query = query;
                item.url = formAction + '/?q=' + encodeURIComponent(query);
                item.enter = function () {
                    self.query = query;
                };

                items.push(item);
            }, this);

            result = {
                totalItems: items.length,
                noResults:  items.length === 0,
                query:      this.$input.val(),
                indexes:    []
            };

            index = {
                totalItems:   items.length,
                isShowTotals: false,
                items:        items,
                identifier:   'popular',
                title:        this.config.popularTitle
            };

            result.indexes.push(index);

            return result;
        },

        processAddToCart: function (event) {
            let linkToCart = $(event.target).parent('.mst__add_to_cart').attr('_href');
            if (this.config.isAjaxCartButton) {
                this.xhr = $.ajax({
                    url:      linkToCart,
                    dataType: 'json',
                    type:     'GET',
                    success:  function (data) {
                        let message = '<div class="to_cart_message ' + (data.success ? 'success' : 'error') + '">' + data.message + '</div>';
                        this.reloadCart();
                        $(event.target).closest('.to-cart').parent().prepend(message);
                        setTimeout(function () {
                            $(event.target).closest('.to-cart').parent().find('.to_cart_message').remove();
                        }, 5000);
                    }.bind(this)
                });
            } else {
                $(event.target).closest('.mst__add_to_cart').attr('href', linkToCart).trigger('click');
            }

            return false;
        },

        reloadCart: function () {
            var form = $('form#form-validate');
            $.ajax({
                url:     form.attr('action'),
                data:    form.serialize(),
                success: function (res) {
                    var parsedResponse = $.parseHTML(res);
                    var result = $(parsedResponse).find("#form-validate");
                    var sections = ['cart'];
                    $("#form-validate").replaceWith(result);
                    customerData.reload(sections, true);

                }
            });
        },

        bindPopularChipHrefs: function () {
            var self = this;
            var formAction = (self.$input.closest('form').attr('action') || '/catalogsearch/result/')
                .replace(/\/+$/, '');

            self.$placeholder().find('.mst-searchautocomplete__item.popular a.awa-search-chip').each(function () {
                var ctx = ko.dataFor(this);
                var query = String((ctx && ctx.query) || this.textContent || '').trim();
                var url = (ctx && ctx.url) || (formAction + '/?q=' + encodeURIComponent(query));

                if (ctx && !ctx.url && query) {
                    ctx.url = url;
                }

                if (url) {
                    this.setAttribute('href', url);
                }

                if (query) {
                    this.setAttribute('title', query);
                }
            });
        },

        ensurePosition: function () {
            var $input = this.$input;
            var el = this.$placeholder()[0];
            if (!el) {
                return;
            }
            var position = $input.position();
            var marginLeft = parseInt($input.css('marginLeft'), 10) || 0;
            var marginTop = parseInt($input.css('marginTop'), 10) || 0;
            var gap = 8;
            var topPx = $input.outerHeight() + gap + position.top + marginTop;
            var leftPx = position.left + marginLeft;
            // top/left with !important: super-global usa top: calc(100% + 12px) !important
            // no form (offsetParent), o que na PLP cola o painel (gap 6 vs 8).
            el.style.setProperty('top', topPx + 'px', 'important');
            el.style.setProperty('left', leftPx + 'px', 'important');
            // width fica no CSS (100% + border-box). Pixel width + content-box
            // estourava 18px além do input.
            el.style.removeProperty('width');
            if (el.classList.contains('_active')) {
                this.syncSearchAria(true);
            }
        }
    };

    return Autocomplete;
});
