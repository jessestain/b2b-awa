define([
    'jquery',
    'ko',
    'underscore',
    'mage/translate',
    'Magento_Catalog/js/price-utils',
    'Mirasvit_Search/js/highlight',
    'Magento_Customer/js/customer-data',
    'Magento_Catalog/js/catalog-add-to-cart'
], function ($, ko, _, $t, priceUtils, highlight, customerData) {
    // AWA: compartilha XHR e um único timer de posicionamento entre instâncias
    var awaSharedXhr = null;
    var awaSharedQuery = null;
    var awaPositionTimer = null;
    var awaResultCache = {};
    var awaResultCacheTs = {};
    var awaResultCacheTtl = 30000;
    var awaLastAppliedQuery = null;

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
        this.$cat = $('[name=cat]', this.$input.closest('form'));
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
            this.doSearch = _.debounce(this._doSearch, this.config.delay);

            this.$input.after($('#searchAutocompletePlaceholder').html());

            this.xhr = null;

            this.$input.on("keyup", function (event) {
                this.clickHandler(event)
            }.bind(this));

            this.$input.on("focus", function (event) {
                event.stopPropagation();
                this.clickHandler(event)
            }.bind(this));

            this.$input.on("input", function () {
                this.inputHandler()
            }.bind(this));

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
                    if (awaPositionTimer) {
                        clearInterval(awaPositionTimer);
                    }
                    // 10ms gerava dezenas de timers; 100ms basta para sticky/resize
                    awaPositionTimer = setInterval(function () {
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

        syncSearchAria: function (isActive) {
            var panel = this.$placeholder()[0];
            var input = this.$input[0];
            if (panel) {
                panel.id = 'mst-searchautocomplete-results';
                panel.setAttribute('role', 'listbox');
                panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            }
            if (input) {
                input.setAttribute('aria-expanded', isActive ? 'true' : 'false');
                if (panel && panel.id) {
                    input.setAttribute('aria-controls', panel.id);
                }
            }
            return { panel: panel, input: input };
        },

        setActiveState: function (isActive) {
            if (!isActive && awaPositionTimer) {
                clearInterval(awaPositionTimer);
                awaPositionTimer = null;
            }
            if (isActive && !awaPositionTimer) {
                awaPositionTimer = setInterval(function () {
                    this.ensurePosition();
                }.bind(this), 100);
            }
            $('body').toggleClass('searchautocomplete__active', isActive);
            this.$input.toggleClass('searchautocomplete__active', isActive);
            this.$placeholder().toggleClass('_active', isActive);

            //magento minisearch
            $(this.$input[0].form).toggleClass('active', isActive);
            $(this.$input[0].labels).each(function (key, label) {
                $(label).toggleClass('active', isActive);
            });

            this.syncSearchAria(isActive);
        },

        inputHandler: function () {
            this.result = this.search();

            setTimeout(function () {
                this.setActiveState(!!this.result);
                if (this.result) {
                    this.ensurePosition();
                }
            }.bind(this), 200);

            this.ensurePosition();
        },

        $spinner: function () {
            return this.$placeholder().find(".mst-searchautocomplete__spinner");
        },

        search: function () {
            var rawQuery = String(this.$input.val() || '');
            var normalized = rawQuery.trim().toLowerCase();
            if (rawQuery.length > 0) {
                $('.actions .action.search').prop('disabled', false);
            }

            this.ensurePosition();

            this.$input.off("keydown");
            this.$input.off("blur");

            // AWA: só aborta se a query mudou — evita matar/refazer o mesmo suggest
            var inflightSame = !!(awaSharedXhr && awaSharedQuery === normalized
                && awaSharedXhr.readyState && awaSharedXhr.readyState !== 4);
            if (!inflightSame && awaSharedQuery && awaSharedQuery !== normalized) {
                if (this.xhr != null) {
                    try { this.xhr.abort(); } catch (eAbort1) {}
                    this.xhr = null;
                }
                if (awaSharedXhr != null) {
                    try { awaSharedXhr.abort(); } catch (eAbort2) {}
                    awaSharedXhr = null;
                    awaSharedQuery = null;
                }
            } else if (inflightSame) {
                this.xhr = awaSharedXhr;
            }

            if (rawQuery.length >= this.config.minSearchLength) {
                this.doSearch(rawQuery);
            } else {
                this.$placeholder().removeClass(this.additionalColumnSelector);
                awaLastAppliedQuery = null;
                return this.doPopular();
            }

            return true;
        },

        _doSearch: function (query) {
            var normalized = String(query || '').trim().toLowerCase();

            if (awaSharedXhr && awaSharedQuery === normalized
                && awaSharedXhr.readyState && awaSharedXhr.readyState !== 4) {
                this.xhr = awaSharedXhr;
                this.isVisible = true;
                this.$spinner().show();
                return;
            }

            if (awaResultCache[normalized]
                && (Date.now() - (awaResultCacheTs[normalized] || 0)) < awaResultCacheTtl) {
                this.isVisible = true;
                this.$spinner().hide();
                awaLastAppliedQuery = normalized;
                this.processApplyBinding(awaResultCache[normalized]);
                return;
            }

            // Já aplicado para o mesmo q e sem cache expirado implícito via lastApplied
            if (awaLastAppliedQuery === normalized && this.result) {
                this.isVisible = true;
                this.$spinner().hide();
                return;
            }

            this.isVisible = true;
            this.$spinner().show();


            awaSharedQuery = normalized;
            this.xhr = $.ajax({
                url:      this.config.url,
                dataType: 'json',
                type:     'GET',
                data:     {
                    q:                 query,
                    store_id:          this.config.storeId,
                    cat:               this.$cat.val(),
                    currency:          this.config.currency,
                    customer_group_id: this.config.customerGroupId
                },
                success:  function (data) {
                    awaResultCache[normalized] = data;
                    awaResultCacheTs[normalized] = Date.now();
                    awaLastAppliedQuery = normalized;
                    this.processApplyBinding(data);
                    this.$spinner().hide();
                }.bind(this),
                complete: function () {
                    if (awaSharedXhr === this.xhr) {
                        awaSharedXhr = null;
                        awaSharedQuery = null;
                    }
                }.bind(this)
            });
            awaSharedXhr = this.xhr;
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

                                this.enter(item);
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

        enter: function (item) {
            if (item.url) {
                window.location.href = item.url;
            } else {
                this.pasteToSearchString(item.query);
            }
        },

        pasteToSearchString: function (searchTerm) {
            this.$input.val(searchTerm);
            this.search();
        },

        doPopular: function () {
            this.$spinner().hide();
            if (this.config.popularSearches.length) {
                this.processApplyBinding(this._showQueries(this.config.popularSearches));

                return true;
            }

            return false;
        },

        processApplyBinding: function (data) {
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

            // Atualização subsequente — só observables (padrão Magento/KO)
            if (self.model !== null) {
                self.viewModel(data);
                finishLayout();
                return;
            }

            // 1º bind: síncrono. NÃO require Magento_Ui knockout/bootstrap
            // (esse módulo mistura/trava a busca). Isolamento via stopBinding no placeholder.
            var $placeholder = self.$placeholder();
            if ($placeholder.length && !$placeholder.attr('data-bind')) {
                $placeholder.attr('data-bind', 'stopBinding: true');
            }

            var $existing = self.$wrapper();
            if ($existing.length > 0) {
                $existing.each(function () {
                    if (ko.dataFor(this)) {
                        ko.cleanNode(this);
                    }
                });
                $existing.remove();
            }

            var wrapperHtml = $('#searchAutocompleteWrapper').html();
            if (!wrapperHtml) {
                finishLayout();
                return;
            }

            $placeholder.append(wrapperHtml);
            self.viewModel(data);

            var bindNode = self.$wrapper()[0];

            if (!bindNode) {
                finishLayout();
                return;
            }

            if (ko.dataFor(bindNode)) {
                ko.cleanNode(bindNode);
            }

            try {
                ko.applyBindings(self.model, bindNode);
            } catch (bindErr) {
                try {
                    ko.cleanNode(bindNode);
                    ko.applyBindings(self.model, bindNode);
                } catch (bindErr2) {
                    if (window.console && console.error) {
                        console.error(bindErr2);
                    }
                }
            }

            finishLayout();
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
