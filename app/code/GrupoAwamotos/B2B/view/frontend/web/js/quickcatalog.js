/**
 * Catálogo de Compra Rápida B2B — "Comprar produtos" (/b2b/quickcatalog/).
 *
 * Contrato (CONTRACT.md §8):
 * - Digitar quantidade, buscar, rolar ou trocar categoria NUNCA adiciona.
 * - Só cliques explícitos em "Adicionar"/"Adicionar selecionados" chamam o servidor.
 * - Guarda de duplo clique + request_id por lote (idempotência server-side).
 * - Resposta incerta → reconcilia com o carrinho real antes de tentar de novo;
 *   nunca repete POST automaticamente após resposta incerta.
 * - Painel do carrinho só reflete dados confirmados pelo servidor.
 * - Selecionado ≠ adicionado (badges distintos; qty zera após confirmação).
 * - Seleção preservada por conta em sessionStorage; limpa no logout.
 *
 * Fase 2 (CONTRACT.md §12):
 * - Perspectivas "Todos os produtos | Favoritos | Comprar novamente"
 *   (role=tablist/aria-selected, teclado APG com setas Left/Right/Home/End).
 * - Coração de favorito por linha (aria-pressed, POST /favorite com form_key
 *   + product_id, guard de duplo clique, feedback em aria-live).
 * - "Comprar novamente" usa GET /buyagain (page/limit), linha com "Comprado
 *   em <data>" e ordenação oculta (recência é server-side).
 *
 * Vanilla ES5+, sem dependências, sem MutationObserver.
 */
(function () {
    'use strict';

    var STORAGE_PREFIX = 'awa_qc_sel_';
    var ADD_TIMEOUT_MS = 25000;
    var SEARCH_DEBOUNCE_MS = 300;

    /* ============================================================ Core (puro) */

    var Core = {
        formatMessage: function (template, value) {
            if (typeof template !== 'string') {
                return '';
            }
            return template.replace('%1', String(value === undefined || value === null ? '' : value));
        },

        normalizeQty: function (value, max) {
            var qty = parseInt(value, 10);
            if (isNaN(qty) || qty < 1) {
                qty = 1;
            }
            if (max && qty > max) {
                qty = max;
            }
            return qty;
        },

        parseSelection: function (raw) {
            var selection = {};
            if (typeof raw !== 'string' || raw === '') {
                return selection;
            }
            try {
                var parsed = JSON.parse(raw);
                if (!parsed || typeof parsed !== 'object') {
                    return selection;
                }
                Object.keys(parsed).forEach(function (sku) {
                    var qty = parseInt(parsed[sku], 10);
                    if (!isNaN(qty) && qty > 0) {
                        selection[sku] = qty;
                    }
                });
            } catch (e) {
                return {};
            }
            return selection;
        },

        serializeSelection: function (selection) {
            var out = {};
            Object.keys(selection || {}).forEach(function (sku) {
                var qty = parseInt(selection[sku], 10);
                if (!isNaN(qty) && qty > 0) {
                    out[sku] = qty;
                }
            });
            return JSON.stringify(out);
        },

        buildBatchItems: function (selection) {
            var items = [];
            Object.keys(selection || {}).forEach(function (sku) {
                var qty = parseInt(selection[sku], 10);
                if (!isNaN(qty) && qty > 0) {
                    items.push({ sku: sku, qty: qty });
                }
            });
            return items;
        },

        /**
         * Decide se o lote "incerto" entrou no carrinho comparando o snapshot
         * anterior com o atual. landed=true quando TODOS os itens esperados
         * aparecem com qty >= (anterior + pedido).
         */
        reconcile: function (before, after, expectedItems) {
            var beforeMap = {};
            var afterMap = {};
            var items = expectedItems || [];
            var landed = [];
            var missing = [];

            (before && before.items || []).forEach(function (item) {
                beforeMap[String(item.sku).toLowerCase()] = parseFloat(item.qty) || 0;
            });
            (after && after.items || []).forEach(function (item) {
                afterMap[String(item.sku).toLowerCase()] = parseFloat(item.qty) || 0;
            });

            items.forEach(function (item) {
                var key = String(item.sku).toLowerCase();
                var required = (beforeMap[key] || 0) + (parseInt(item.qty, 10) || 0);
                if ((afterMap[key] || 0) + 0.0001 >= required) {
                    landed.push(item.sku);
                } else {
                    missing.push(item.sku);
                }
            });

            return {
                landed: missing.length === 0 && items.length > 0,
                partial: landed.length > 0 && missing.length > 0,
                none: landed.length === 0,
                landedSkus: landed,
                missingSkus: missing
            };
        },

        createRequestId: function () {
            var cryptoObj = typeof window !== 'undefined' ? window.crypto : null;
            if (cryptoObj && typeof cryptoObj.randomUUID === 'function') {
                return cryptoObj.randomUUID();
            }
            if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
                var bytes = new Uint8Array(16);
                cryptoObj.getRandomValues(bytes);
                var hex = '';
                for (var i = 0; i < bytes.length; i++) {
                    hex += ('0' + bytes[i].toString(16)).slice(-2);
                }
                return 'qc-' + hex;
            }
            return 'qc-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
        },

        /**
         * Formata "YYYY-MM-DD HH:MM:SS" (UTC, como o servidor envia) em
         * "dd/mm/aaaa". Entrada ausente/inválida (inclusive datas inexistentes
         * como 2026-02-30) vira ''. Determinística: sem conversão de fuso.
         */
        formatDate: function (value) {
            if (typeof value !== 'string' || value === '') {
                return '';
            }
            var m = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
            if (!m) {
                return '';
            }
            var year = parseInt(m[1], 10);
            var month = parseInt(m[2], 10);
            var day = parseInt(m[3], 10);
            var hour = parseInt(m[4], 10);
            var minute = parseInt(m[5], 10);
            var second = m[6] ? parseInt(m[6], 10) : 0;
            if (month < 1 || month > 12 || day < 1 || day > 31 || hour > 23 || minute > 59 || second > 59) {
                return '';
            }
            var date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
            if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
                return '';
            }
            return ('0' + day).slice(-2) + '/' + ('0' + month).slice(-2) + '/' + year;
        },

        /**
         * Params da busca por perspectiva. view=favorites acrescenta o filtro
         * server-side; buyagain NÃO usa este builder (usa buildBuyAgainParams).
         */
        buildSearchParams: function (state) {
            var s = state || {};
            var params = new URLSearchParams();
            params.set('page', String(s.page || 1));
            params.set('limit', String(s.pageSize || 24));
            params.set('sort', s.sort || 'relevance');
            if (s.q) {
                params.set('q', String(s.q));
            }
            if (Number(s.cat) > 0) {
                params.set('cat', String(s.cat));
            }
            if (s.view === 'favorites') {
                params.set('view', 'favorites');
            }
            return params;
        },

        /**
         * Params do endpoint buyagain: apenas page/limit (a ordenação é por
         * recência, server-side; o sort do catálogo não se aplica).
         */
        buildBuyAgainParams: function (state) {
            var s = state || {};
            var params = new URLSearchParams();
            params.set('page', String(s.page || 1));
            params.set('limit', String(s.pageSize || 24));
            return params;
        },

        /**
         * Cópia pura de um item com o flag favorite alterado (testável).
         */
        toggleFavoriteState: function (item, favorited) {
            var out = {};
            for (var key in item) {
                if (Object.prototype.hasOwnProperty.call(item, key)) {
                    out[key] = item[key];
                }
            }
            out.favorite = !!favorited;
            return out;
        }
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = Core;
    }

    /* =============================================================== Browser */

    if (typeof document === 'undefined' || typeof window === 'undefined') {
        return;
    }

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    onReady(function () {
        var root = document.getElementById('b2b-quickcatalog');
        var configNode = document.getElementById('qc-config');
        if (!root || !configNode) {
            return;
        }

        var config;
        try {
            config = JSON.parse(configNode.textContent || '{}');
        } catch (e) {
            return;
        }

        if (!config.gate || !config.gate.allowed) {
            return;
        }

        var demoMode = /[?&]qcdebug=demo/.test(window.location.search);
        var debugMode = /[?&]qcdebug=1/.test(window.location.search);
        var msg = config.messages || {};
        var MAX_QTY = parseInt(config.maxQty, 10) || 9999;

        var state = {
            q: '',
            cat: 0,
            sort: 'relevance',
            view: 'catalog',
            page: 1,
            pageSize: parseInt(config.pageSize, 10) || 24,
            total: 0,
            totalPages: 0,
            items: [],
            itemsBySku: {},
            selection: {},
            addedSkus: {},
            unit: {},
            qtyBySku: {},
            cart: config.initialCart || null,
            searchController: null,
            searchSeq: 0,
            inFlight: false,
            addUnconfirmed: false,
            lastRequestId: '',
            storageKey: STORAGE_PREFIX + (config.accountKey || 'anon')
        };

        // Guarda de duplo clique por SKU para o toggle de favorito.
        var favInFlight = {};

        var el = {
            list: document.getElementById('qc-list'),
            skeleton: document.getElementById('qc-list-skeleton'),
            empty: document.getElementById('qc-empty'),
            status: document.getElementById('qc-results-status'),
            searchForm: document.getElementById('qc-search-form'),
            searchInput: document.getElementById('qc-search-input'),
            sortSelect: document.getElementById('qc-sort-select'),
            addSelected: document.getElementById('qc-add-selected'),
            addSelectedCount: document.querySelector('#qc-add-selected [data-role="count"]'),
            addSelectedLabel: document.querySelector('#qc-add-selected [data-role="label"]'),
            feedback: document.getElementById('qc-batch-feedback'),
            feedbackSummary: document.querySelector('#qc-batch-feedback [data-role="summary"]'),
            feedbackErrors: document.querySelector('#qc-batch-feedback [data-role="errors"]'),
            pagination: document.getElementById('qc-pagination'),
            pageInfo: document.getElementById('qc-page-info'),
            prev: document.getElementById('qc-prev-page'),
            next: document.getElementById('qc-next-page'),
            sidebar: document.getElementById('qc-sidebar'),
            sidebarToggle: document.getElementById('qc-sidebar-toggle'),
            cartPanel: document.getElementById('qc-cart-panel'),
            cartEmpty: document.getElementById('qc-cart-empty'),
            cartBody: document.getElementById('qc-cart-body'),
            cartDistinct: document.querySelector('#qc-cart-panel [data-role="cart-distinct"]'),
            cartUnits: document.querySelector('#qc-cart-panel [data-role="cart-units"]'),
            cartSubtotal: document.querySelector('#qc-cart-panel [data-role="cart-subtotal"]'),
            bottomUnits: document.querySelector('[data-role="bottombar-units"]'),
            bottomSubtotal: document.querySelector('[data-role="bottombar-subtotal"]'),
            tabs: document.getElementById('qc-tabs'),
            tabButtons: [],
            main: document.getElementById('qc-main'),
            sortWrap: document.querySelector('.qc-sort'),
            hint: document.getElementById('qc-select-hint'),
            favFeedback: document.getElementById('qc-fav-feedback')
        };
        if (el.tabs) {
            el.tabButtons = Array.prototype.slice.call(el.tabs.querySelectorAll('[role="tab"]'));
        }

        /* ---------------------------------------------------------- storage -- */

        function loadSelection() {
            try {
                state.selection = Core.parseSelection(window.sessionStorage.getItem(state.storageKey));
            } catch (e) {
                state.selection = {};
            }
            state.addedSkus = {};
        }

        function saveSelection() {
            try {
                if (Object.keys(state.selection).length === 0) {
                    window.sessionStorage.removeItem(state.storageKey);
                } else {
                    window.sessionStorage.setItem(state.storageKey, Core.serializeSelection(state.selection));
                }
            } catch (e) {
                /* sessão indisponível: seleção vale só em memória */
            }
        }

        function clearAccountState() {
            try {
                window.sessionStorage.removeItem(state.storageKey);
            } catch (e) {
                /* ignore */
            }
            state.selection = {};
            state.addedSkus = {};
        }

        function bindLogoutCleanup() {
            document.addEventListener('click', function (event) {
                var link = event.target && event.target.closest
                    ? event.target.closest('a[href*="logout"], a[href*="account/logout"]')
                    : null;
                if (link) {
                    clearAccountState();
                }
            }, true);

            document.addEventListener('customer_logout', clearAccountState);
        }

        /* ----------------------------------------------------------- helpers -- */

        function escapeHtml(value) {
            return String(value === undefined || value === null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function t(key, fallback) {
            return msg[key] || fallback || '';
        }

        function url(path) {
            return (config.endpoints && config.endpoints[path]) || path;
        }

        function isAbortError(error) {
            return error && (error.name === 'AbortError' || error.code === 20);
        }

        // Texto padrão do hint do toolbar (server-side); buyagain troca pelo
        // buyAgainHint do config e volta ao padrão nas demais perspectivas.
        var hintDefaultText = el.hint ? el.hint.textContent.trim() : '';

        /**
         * Sincroniza os nomes acessíveis das abas com o config (tabAllProducts,
         * tabFavorites, tabBuyAgain, tabsLabel). Sem config → mantém o texto
         * renderizado pelo servidor (fallback). Não re-renderiza rótulos.
         */
        function syncTabLabels() {
            var map = { catalog: 'tabAllProducts', favorites: 'tabFavorites', buyagain: 'tabBuyAgain' };
            el.tabButtons.forEach(function (btn) {
                var view = btn.getAttribute('data-qc-view');
                var key = map[view];
                btn.setAttribute('aria-label', key ? t(key, btn.textContent.trim()) : btn.textContent.trim());
            });
            if (el.tabs && msg.tabsLabel) {
                el.tabs.setAttribute('aria-label', msg.tabsLabel);
            }
        }

        /* ---------------------------------------------------------- cart UI -- */

        function renderCart(cart) {
            if (!cart) {
                return;
            }

            state.cart = cart;
            var count = parseInt(cart.items_count, 10) || 0;
            var units = parseFloat(cart.total_units) || 0;
            var distinct = parseInt(cart.distinct_skus, 10) || 0;

            if (el.cartEmpty) {
                el.cartEmpty.hidden = count > 0;
            }
            if (el.cartBody) {
                el.cartBody.hidden = count === 0;
            }
            if (el.cartDistinct) {
                el.cartDistinct.textContent = String(distinct);
            }
            if (el.cartUnits) {
                el.cartUnits.textContent = String(units);
            }
            if (el.cartSubtotal) {
                el.cartSubtotal.textContent = cart.subtotal || '';
            }
            if (el.bottomUnits) {
                el.bottomUnits.textContent = String(units);
            }
            if (el.bottomSubtotal) {
                el.bottomSubtotal.textContent = cart.subtotal || '';
            }

            // Mantém a quantidade confirmada por SKU para reconciliação.
            state.qtyBySku = {};
            (cart.items || []).forEach(function (item) {
                state.qtyBySku[String(item.sku).toLowerCase()] = parseFloat(item.qty) || 0;
            });
        }

        function cartSnapshotForReconcile() {
            return {
                items: (state.cart && state.cart.items) || []
            };
        }

        /**
         * Re-sincroniza o painel com o carrinho REAL do servidor.
         * Usado após falha/parcial de add: a resposta do lote pode carregar um snapshot do
         * quote em memória (ex.: item adicionado e depois rejeitado pela validação de preço).
         * Nunca exibimos esse estado como definitivo — lemos o carrinho persistido.
         */
        function syncCartFromServer() {
            return fetchJson(url('cart'), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (res) {
                var data = res.data || {};
                if (data.success && data.cart) {
                    renderCart(data.cart);
                    return data.cart;
                }
                return null;
            }).catch(function () {
                return null;
            });
        }

        /* ------------------------------------------------------- render list -- */

        function rowHtml(item) {
            var sku = String(item.sku);
            var qtyValue = state.selection[sku] || 0;
            var isSelected = qtyValue > 0;
            var isAdded = !!state.addedSkus[sku];
            var addable = item.addable !== false;
            var image = item.image
                ? '<img src="' + escapeHtml(item.image) + '" alt="' + escapeHtml(item.name) + '" loading="lazy" width="64" height="64">'
                : '<span class="qc-row__media-placeholder" aria-hidden="true"></span>';

            var meta = [
                '<span class="qc-row__sku">' + escapeHtml(sku) + '</span>'
            ];
            if (item.application) {
                meta.push('<span class="qc-row__application">' + escapeHtml(item.application) + '</span>');
            }

            var badges = [];
            if (item.exclusive) {
                badges.push('<span class="qc-badge qc-badge--exclusive">' + escapeHtml(t('exclusive', 'Exclusivo')) + '</span>');
            }
            if (isSelected) {
                badges.push('<span class="qc-badge qc-badge--selected">' + escapeHtml(t('selectedBadge', 'Selecionado')) + '</span>');
            }
            if (isAdded) {
                badges.push('<span class="qc-badge qc-badge--added">' + escapeHtml(t('addedBadge', 'Adicionado')) + '</span>');
            }

            var availabilityClass = item.availability && item.availability.saleable ? '' : ' is-out';
            var availabilityLabel = item.availability ? item.availability.label : '';

            var addButton;
            if (item.requires_config) {
                addButton = '<a class="action secondary qc-row__add" href="' + escapeHtml(item.url || '#') + '">'
                    + escapeHtml(t('configureButton', 'Configurar')) + '</a>'
                    + '<p class="qc-row__add-note">' + escapeHtml(t('requiredConfig', 'Requer configuração na página do produto')) + '</p>';
            } else {
                addButton = '<button type="button" class="action primary qc-row__add" data-qc-add="' + escapeHtml(sku) + '"'
                    + (addable ? '' : ' disabled aria-disabled="true"')
                    + '>' + escapeHtml(t('addButton', 'Adicionar')) + '</button>';

                if (!addable) {
                    addButton += '<p class="qc-row__add-note">' + escapeHtml(t('notAddable', 'Indisponível para compra')) + '</p>';
                }
            }

            var favLabel = (item.favorite
                ? t('favoriteRemove', 'Remover dos favoritos')
                : t('favoriteAdd', 'Adicionar aos favoritos')) + ' ' + (item.name || '');
            var favButton = '<button type="button" class="qc-row__fav' + (item.favorite ? ' is-fav' : '') + '"'
                + ' data-qc-fav="' + escapeHtml(sku) + '"'
                + ' aria-pressed="' + (item.favorite ? 'true' : 'false') + '"'
                + ' aria-label="' + escapeHtml(favLabel) + '">'
                + '<svg class="qc-row__fav-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">'
                + '<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>'
                + '</svg>'
                + '</button>';

            var lastPurchasedHtml = '';
            if (state.view === 'buyagain') {
                var lastDate = Core.formatDate(item.last_purchased);
                if (lastDate) {
                    lastPurchasedHtml = '<p class="qc-row__last-purchased">'
                        + escapeHtml(Core.formatMessage(t('lastPurchased', 'Comprado em %1'), lastDate))
                        + '</p>';
                }
            }

            return '<li class="qc-row' + (isSelected ? ' is-selected' : '') + (isAdded ? ' is-added' : '') + '" data-sku="' + escapeHtml(sku) + '">'
                + '<div class="qc-row__media">' + image + '</div>'
                + '<div class="qc-row__info">'
                + '<div class="qc-row__head">'
                + '<h3 class="qc-row__name"><a href="' + escapeHtml(item.url || '#') + '">' + escapeHtml(item.name) + '</a></h3>'
                + favButton
                + '</div>'
                + '<div class="qc-row__meta">' + meta.join('')
                + (item.unit ? '<span class="qc-row__unit">' + escapeHtml(item.unit) + '</span>' : '')
                + '</div>'
                + (badges.length ? '<div class="qc-row__badges">' + badges.join('') + '</div>' : '')
                + '<div class="qc-row__price">' + (item.price && item.price.html ? item.price.html : escapeHtml(t('priceUnavailable', 'Preço indisponível'))) + '</div>'
                + '<div class="qc-row__availability' + availabilityClass + '">' + escapeHtml(availabilityLabel) + '</div>'
                + lastPurchasedHtml
                + '</div>'
                + '<div class="qc-row__actions">'
                + '<div class="qc-qty">'
                + '<label class="qc-qty__label" for="qc-qty-' + escapeHtml(sku) + '">' + escapeHtml(t('qtyShort', 'Qtd.')) + '</label>'
                + '<div class="qc-qty__stepper awa-qty-stepper--shared" data-awa-qty-root="true">'
                + '<button type="button" class="awa-qty-btn" data-awa-role="qty-trigger" data-awa-direction="decrement"'
                + (addable ? '' : ' disabled')
                + ' aria-label="' + escapeHtml(t('qtyDecrease', 'Diminuir quantidade')) + '">−</button>'
                + '<input class="qc-qty__input" id="qc-qty-' + escapeHtml(sku) + '" type="number" inputmode="numeric"'
                + ' min="1" max="' + MAX_QTY + '" step="1" value="' + (qtyValue > 0 ? qtyValue : 1) + '"'
                + ' data-qc-qty="' + escapeHtml(sku) + '" data-awa-role="qty-input" aria-label="' + escapeHtml(t('qtyLabel', 'Quantidade de %1').replace('%1', item.name)) + '"'
                + (addable ? '' : ' disabled')
                + '>'
                + '<button type="button" class="awa-qty-btn" data-awa-role="qty-trigger" data-awa-direction="increment"'
                + (addable ? '' : ' disabled')
                + ' aria-label="' + escapeHtml(t('qtyIncrease', 'Aumentar quantidade')) + '">+</button>'
                + '</div>'
                + '</div>'
                + addButton
                + '</div>'
                + '</li>';
        }

        function initSteppers() {
            // Missão estabilização 2026-10-22: stepper compartilhado (padrão do carrinho).
            if (!el.list || typeof window.require !== 'function') {
                return;
            }

            window.require(['js/awa-qty-control'], function (qtyControl) {
                var roots = el.list.querySelectorAll('[data-awa-qty-root]');
                for (var i = 0; i < roots.length; i += 1) {
                    qtyControl({}, roots[i]);
                }
            });
        }

        function renderList() {
            if (!el.list) {
                return;
            }

            el.list.innerHTML = state.items.map(rowHtml).join('');
            setBusy(state.inFlight);
            el.list.setAttribute('aria-busy', 'false');
            initSteppers();

            // O tema aplica `display:none` inline em elementos vazios no DOMContentLoaded
            // (cleanEmptyTags em awa-inline-js-extract.phtml). A lista estava vazia nesse
            // instante (linhas chegam depois do fetch); ao popular, limpamos esse inline style.
            if (el.list.style && el.list.style.length) {
                el.list.style.removeProperty('display');
            }

            if (el.skeleton) {
                el.skeleton.hidden = true;
                el.skeleton.setAttribute('hidden', 'hidden');
            }

            if (el.empty) {
                el.empty.hidden = state.items.length > 0;
                if (state.items.length === 0) {
                    renderEmptyContent();
                }
            }

            updateSelectionUi();
            renderPagination();
        }

        function updateSelectionUi() {
            var selected = Core.buildBatchItems(state.selection);
            var count = selected.length;

            if (el.addSelected) {
                el.addSelected.disabled = state.inFlight || state.addUnconfirmed || count === 0;
            }
            if (el.addSelectedCount) {
                el.addSelectedCount.textContent = count > 0 ? String(count) : '';
            }

            if (el.list) {
                Array.prototype.forEach.call(el.list.querySelectorAll('[data-sku]'), function (row) {
                    var sku = row.getAttribute('data-sku');
                    var qty = state.selection[sku] || 0;
                    row.classList.toggle('is-selected', qty > 0);
                    var badges = row.querySelector('.qc-row__badges');
                    var hasSelected = row.querySelector('.qc-badge--selected');
                    if (qty > 0 && !hasSelected) {
                        if (!badges) {
                            badges = document.createElement('div');
                            badges.className = 'qc-row__badges';
                            row.querySelector('.qc-row__info').appendChild(badges);
                        }
                        var badge = document.createElement('span');
                        badge.className = 'qc-badge qc-badge--selected';
                        badge.textContent = t('selectedBadge', 'Selecionado');
                        badges.appendChild(badge);
                    } else if (qty === 0 && hasSelected) {
                        hasSelected.parentNode.removeChild(hasSelected);
                    }
                });
            }
        }

        function renderPagination() {
            if (!el.pagination) {
                return;
            }

            var totalPages = state.totalPages;
            el.pagination.hidden = totalPages <= 1;

            if (el.prev) {
                el.prev.disabled = state.page <= 1;
            }
            if (el.next) {
                el.next.disabled = state.page >= totalPages;
            }
            if (el.pageInfo) {
                el.pageInfo.textContent = totalPages > 0
                    ? Core.formatMessage(t('pageOf', 'Página %1 de %2'), state.page).replace('%2', String(totalPages))
                    : '';
            }
        }

        function showStatus(text) {
            if (el.status) {
                el.status.textContent = text || '';
            }
        }

        /* ----------------------------------------------------------- search -- */

        function buildSearchUrl() {
            return url('search') + '?' + Core.buildSearchParams(state).toString();
        }

        function buildBuyAgainUrl() {
            return url('buyagain') + '?' + Core.buildBuyAgainParams(state).toString();
        }

        function showSkeleton() {
            if (el.skeleton) {
                el.skeleton.hidden = false;
                el.skeleton.removeAttribute('hidden');
            }
            if (el.list) {
                el.list.setAttribute('aria-busy', 'true');
            }
            if (el.empty) {
                el.empty.hidden = true;
            }
        }

        function fetchJson(requestUrl, options) {
            var opts = options || {};
            return fetch(requestUrl, {
                method: opts.method || 'GET',
                credentials: 'same-origin',
                headers: opts.headers || {},
                body: opts.body || null,
                signal: opts.signal || undefined
            }).then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, status: response.status, data: data };
                });
            });
        }

        function handleGateFailure(data) {
            if (!data || data.success) {
                return false;
            }
            if (data.reason === 'login_required') {
                window.location.href = config.urls && config.urls.login ? config.urls.login : '/b2b/account/login';
                return true;
            }
            if (data.reason) {
                showStatus(t('addError', 'Não foi possível carregar: %1').replace('%1', data.message || data.reason));
                return true;
            }
            return false;
        }

        /* --------------------------------------------------- perspectivas -- */

        var VIEWS = ['catalog', 'favorites', 'buyagain'];

        function updateTabsUi() {
            el.tabButtons.forEach(function (btn) {
                var active = btn.getAttribute('data-qc-view') === state.view;
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
                btn.classList.toggle('is-active', active);
                btn.tabIndex = active ? 0 : -1;
            });
            if (el.main) {
                var activeTab = document.getElementById('qc-tab-' + state.view);
                el.main.setAttribute('aria-labelledby', activeTab ? activeTab.id : '');
            }
        }

        function updateViewUi() {
            if (el.sortWrap) {
                el.sortWrap.hidden = state.view === 'buyagain';
            }
            if (el.hint) {
                el.hint.textContent = state.view === 'buyagain'
                    ? t('buyAgainHint', hintDefaultText)
                    : hintDefaultText;
            }
        }

        function setView(view, focusTab) {
            if (VIEWS.indexOf(view) === -1) {
                return;
            }
            state.view = view;
            state.page = 1;
            updateTabsUi();
            updateViewUi();
            var tab = document.getElementById('qc-tab-' + view);
            if (focusTab && tab) {
                tab.focus();
            }
            refresh();
        }

        function renderEmptyContent() {
            if (!el.empty) {
                return;
            }
            var showCta = state.view === 'favorites' || state.view === 'buyagain';
            var text;
            if (state.view === 'favorites') {
                text = t('favoritesEmpty', 'Você ainda não tem favoritos.');
            } else if (state.view === 'buyagain') {
                text = t('buyAgainEmpty', 'Você ainda não tem compras para repetir.');
            } else {
                text = t('empty', 'Nenhum produto encontrado para esta busca.');
            }
            el.empty.innerHTML = escapeHtml(text) + (showCta
                ? '<a class="qc-empty__cta" href="#qc-search-input" data-qc-empty-cta>'
                    + escapeHtml(t('emptyCta', 'Ver todos os produtos')) + '</a>'
                : '');
            // O tema pode ter aplicado display:none inline em elementos vazios
            // no DOMContentLoaded (cleanEmptyTags); limpa ao popular.
            if (el.empty.style && el.empty.style.display === 'none') {
                el.empty.style.removeProperty('display');
            }
        }

        function resetCatalogFilters() {
            state.q = '';
            state.cat = 0;
            if (el.searchInput) {
                el.searchInput.value = '';
            }
            Array.prototype.forEach.call(document.querySelectorAll('.qc-cats__link.is-active'), function (node) {
                node.classList.remove('is-active');
            });
            var all = document.querySelector('.qc-cats__link[data-qc-cat="0"]');
            if (all) {
                all.classList.add('is-active');
            }
        }

        /* --------------------------------------------------------- favorito -- */

        function showFavFeedback(text, isError) {
            if (!el.favFeedback) {
                return;
            }
            el.favFeedback.textContent = text || '';
            el.favFeedback.classList.toggle('is-error', !!isError);
            if (isError) {
                el.favFeedback.setAttribute('role', 'alert');
            } else {
                el.favFeedback.removeAttribute('role');
            }
            // Mesmo cuidado do renderList: o tema esconde elementos vazios
            // inline no DOMContentLoaded.
            if (el.favFeedback.style && el.favFeedback.style.display === 'none') {
                el.favFeedback.style.removeProperty('display');
            }
            el.favFeedback.hidden = false;
        }

        function applyFavoriteState(sku, favorited) {
            var toggled = null;
            state.items = state.items.map(function (item) {
                if (String(item.sku) === String(sku)) {
                    toggled = Core.toggleFavoriteState(item, favorited);
                    return toggled;
                }
                return item;
            });
            if (toggled) {
                state.itemsBySku[String(sku)] = toggled;
            }
        }

        function updateFavButtonUi(sku, favorited) {
            var button = el.list ? el.list.querySelector('[data-qc-fav="' + cssEscape(String(sku)) + '"]') : null;
            if (!button) {
                return;
            }
            var item = state.itemsBySku[String(sku)] || {};
            button.setAttribute('aria-pressed', favorited ? 'true' : 'false');
            button.classList.toggle('is-fav', !!favorited);
            button.setAttribute('aria-label', (favorited
                ? t('favoriteRemove', 'Remover dos favoritos')
                : t('favoriteAdd', 'Adicionar aos favoritos')) + ' ' + (item.name || ''));
        }

        function toggleFavorite(sku) {
            var key = String(sku);
            var item = state.itemsBySku[key];
            var button = el.list ? el.list.querySelector('[data-qc-fav="' + cssEscape(key) + '"]') : null;
            if (!item || item.id === undefined || item.id === null || favInFlight[key]) {
                return Promise.resolve(false);
            }

            favInFlight[key] = true;
            if (button) {
                button.disabled = true;
            }

            var body = new URLSearchParams();
            body.set('form_key', config.formKey);
            body.set('product_id', String(item.id));

            return fetchJson(url('favorite'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (res) {
                var data = res.data || {};
                if (!data.success) {
                    if (data.reason === 'login_required') {
                        handleGateFailure(data);
                    } else {
                        showFavFeedback(t('favoriteError', 'Não foi possível atualizar os favoritos.'), true);
                    }
                    return false;
                }
                var favorited = data.favorite === true;
                applyFavoriteState(key, favorited);
                showFavFeedback(favorited
                    ? t('favoriteAdded', 'Adicionado aos favoritos.')
                    : t('favoriteRemoved', 'Removido dos favoritos.'), false);
                if (state.view === 'favorites') {
                    // Na perspectiva de favoritos o item sai da lista: re-lê a
                    // perspectiva do servidor (total/paginação/estado vazio).
                    refresh();
                } else {
                    updateFavButtonUi(key, favorited);
                }
                return true;
            }).catch(function () {
                showFavFeedback(t('favoriteError', 'Não foi possível atualizar os favoritos.'), true);
                return false;
            }).then(function (ok) {
                favInFlight[key] = false;
                var current = el.list ? el.list.querySelector('[data-qc-fav="' + cssEscape(key) + '"]') : null;
                if (current) {
                    current.disabled = false;
                }
                return ok;
            });
        }

        function applyResults(data) {
            var applied = data.applied || {};
            if (applied.view === 'catalog' || applied.view === 'favorites' || applied.view === 'buyagain') {
                state.view = applied.view;
                updateTabsUi();
                updateViewUi();
            }
            state.items = data.items || [];
            state.itemsBySku = {};
            state.items.forEach(function (item) {
                state.itemsBySku[String(item.sku)] = item;
            });
            state.total = parseInt(data.pagination && data.pagination.total, 10) || 0;
            state.totalPages = Math.ceil(state.total / state.pageSize);
            renderList();
            showStatus(Core.formatMessage(t('resultsFound', '%1 produto(s) encontrado(s)'), state.total));
        }

        function refresh() {
            if (state.searchController) {
                try {
                    state.searchController.abort();
                } catch (e) {
                    /* ignore */
                }
            }
            state.searchController = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var seq = ++state.searchSeq;

            showSkeleton();
            showStatus(t('loading', 'Carregando produtos…'));

            var requestUrl = state.view === 'buyagain' ? buildBuyAgainUrl() : buildSearchUrl();

            fetchJson(requestUrl, {
                signal: state.searchController ? state.searchController.signal : undefined
            }).then(function (res) {
                if (seq !== state.searchSeq) {
                    return;
                }
                var data = res.data || {};
                if (!data.success) {
                    if (!handleGateFailure(data)) {
                        showStatus(res.status >= 500
                            ? t('networkError', 'Falha de conexão.')
                            : (data.message || t('empty', 'Nenhum produto encontrado.')));
                    }
                    state.items = [];
                    state.total = 0;
                    state.totalPages = 0;
                    renderList();
                    showStatus(data.message || '');
                    return;
                }

                applyResults(data);
            }).catch(function (error) {
                if (isAbortError(error) || seq !== state.searchSeq) {
                    return;
                }
                if (el.skeleton) {
                    el.skeleton.hidden = true;
                    el.skeleton.setAttribute('hidden', 'hidden');
                }
                if (el.list) {
                    el.list.setAttribute('aria-busy', 'false');
                }
                showStatus(t('networkError', 'Falha de conexão. Tente novamente.'));
            });
        }

        /* ---------------------------------------------------------- batch add -- */

        function setBusy(busy) {
            state.inFlight = busy;
            var buttons = el.list ? el.list.querySelectorAll('[data-qc-add]') : [];
            Array.prototype.forEach.call(buttons, function (button) {
                button.disabled = busy || state.addUnconfirmed || button.getAttribute('aria-disabled') === 'true';
            });
            if (el.addSelected) {
                el.addSelected.disabled = busy || state.addUnconfirmed || Object.keys(state.selection).length === 0;
            }
            if (el.addSelectedLabel) {
                el.addSelectedLabel.textContent = busy
                    ? t('loading', 'Carregando…')
                    : t('addSelected', 'Adicionar selecionados');
            }
        }

        function showFeedback(summaryText, errors, isError) {
            if (!el.feedback) {
                return;
            }
            el.feedback.hidden = false;
            el.feedback.classList.toggle('has-errors', !!isError);

            if (el.feedbackSummary) {
                el.feedbackSummary.textContent = summaryText || '';
            }
            if (el.feedbackErrors) {
                if (errors && errors.length) {
                    el.feedbackErrors.hidden = false;
                    el.feedbackErrors.innerHTML = errors.map(function (error) {
                        return '<li>' + escapeHtml(error.sku ? error.sku + ': ' : '') + escapeHtml(error.message || '') + '</li>';
                    }).join('');
                } else {
                    el.feedbackErrors.hidden = true;
                    el.feedbackErrors.innerHTML = '';
                }
            }
        }

        function submitBatch(items) {
            if (state.inFlight || state.addUnconfirmed || !items || items.length === 0) {
                return Promise.resolve();
            }

            setBusy(true);
            var requestId = Core.createRequestId();
            state.lastRequestId = requestId;
            var before = cartSnapshotForReconcile();

            var body = new URLSearchParams();
            body.set('form_key', config.formKey);
            body.set('request_id', requestId);
            body.set('items', JSON.stringify(items));

            var timeoutId = null;
            var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            if (controller) {
                timeoutId = window.setTimeout(function () {
                    try { controller.abort(); } catch (e) { /* ignore */ }
                }, ADD_TIMEOUT_MS);
            }

            return fetchJson(url('add'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
                signal: controller ? controller.signal : undefined
            }).then(function (res) {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
                var data = res.data || {};
                if (!data.success && data.reason && data.reason !== 'cannot_add') {
                    handleGateFailure(data);
                }

                if (data.cart) {
                    renderCart(data.cart);
                }

                var added = data.added || [];
                var errors = (data.errors || []).slice();
                added.forEach(function (item) {
                    markAdded(item.sku);
                });
                finishBatch(added, errors, data);

                // Falha ou parcial: a resposta pode trazer snapshot otimista do quote em memória.
                // Re-lê o carrinho persistido antes de encerrar o lote (P14).
                if (!data.success || errors.length > 0) {
                    return syncCartFromServer().then(function () {
                        return data;
                    });
                }
                return data;
            }).catch(function (error) {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
                if (isAbortError(error)) {
                    return reconcileUncertain(items, before, requestId);
                }
                return reconcileUncertain(items, before, requestId);
            }).then(function (result) {
                setBusy(false);
                return result;
            });
        }

        /**
         * A lost response is not evidence that the POST failed. Read only;
         * further additions require reviewing the existing cart page first.
         * This page-local guard is not server-side idempotency.
         */
        function reconcileUncertain(items, before, requestId) {
            state.addUnconfirmed = true;
            var message = t('addUnconfirmed', 'Não foi possível confirmar a adição. Abra Revisar carrinho e confira as quantidades antes de adicionar novamente.');
            showFeedback(message, [], true);
            var recoveryTimer;
            var timeout = new Promise(function (resolve, reject) {
                recoveryTimer = window.setTimeout(function () {
                    reject(new Error('Cart recovery timed out'));
                }, 15000);
            });
            return Promise.race([fetchJson(url('cart'), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }), timeout]).then(function (res) {
                clearTimeout(recoveryTimer);
                var data = res.data || {};
                if (data.success && data.cart) {
                    renderCart(data.cart);
                }
                showFeedback(message, [], true);
                return { unconfirmed: true };
            }).catch(function () {
                clearTimeout(recoveryTimer);
                showFeedback(message, [], true);
                return { unconfirmed: true };
            });
        }

        function finishBatch(added, errors, data) {
            if (added.length > 0) {
                var summary = Core.formatMessage(t('addSuccess', '%1 item(ns) adicionado(s) ao carrinho.'), added.length);
                showFeedback(summary, errors, errors.length > 0);
            } else if (errors.length > 0) {
                showFeedback(t('addPartial', 'Alguns itens não puderam ser adicionados.'), errors, true);
            } else if (data && data.replayed) {
                showFeedback(t('reconciled', 'Carrinho conferido.'), [], false);
            }
        }

        function markAdded(sku) {
            state.addedSkus[sku] = true;
            delete state.selection[sku];
            saveSelection();

            var row = el.list ? el.list.querySelector('[data-sku="' + cssEscape(sku) + '"]') : null;
            if (row) {
                row.classList.add('is-added');
                row.classList.remove('is-selected');
                var oldSelected = row.querySelector('.qc-badge--selected');
                if (oldSelected) {
                    oldSelected.parentNode.removeChild(oldSelected);
                }
                if (!row.querySelector('.qc-badge--added')) {
                    var badges = row.querySelector('.qc-row__badges');
                    if (!badges) {
                        badges = document.createElement('div');
                        badges.className = 'qc-row__badges';
                        row.querySelector('.qc-row__info').appendChild(badges);
                    }
                    var badge = document.createElement('span');
                    badge.className = 'qc-badge qc-badge--added';
                    badge.textContent = t('addedBadge', 'Adicionado');
                    badges.appendChild(badge);
                }
                var qtyInput = row.querySelector('[data-qc-qty]');
                if (qtyInput) {
                    qtyInput.value = '1';
                }
            }
            updateSelectionUi();
        }

        function cssEscape(value) {
            if (window.CSS && typeof window.CSS.escape === 'function') {
                return window.CSS.escape(value);
            }
            return String(value).replace(/["\\]/g, '\\$&');
        }

        /* ------------------------------------------------------------- events -- */

        function bindEvents() {
            if (el.searchForm) {
                el.searchForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    state.q = el.searchInput ? el.searchInput.value.trim() : '';
                    state.page = 1;
                    refresh();
                });
            }

            if (el.searchInput) {
                var debounceId = null;
                el.searchInput.addEventListener('input', function () {
                    if (debounceId) {
                        clearTimeout(debounceId);
                    }
                    debounceId = window.setTimeout(function () {
                        state.q = el.searchInput.value.trim();
                        state.page = 1;
                        refresh();
                    }, SEARCH_DEBOUNCE_MS);
                });
            }

            if (el.sortSelect) {
                el.sortSelect.addEventListener('change', function () {
                    state.sort = el.sortSelect.value;
                    state.page = 1;
                    refresh();
                });
            }

            if (el.tabs) {
                el.tabs.addEventListener('click', function (event) {
                    var tab = event.target && event.target.closest
                        ? event.target.closest('[data-qc-view]')
                        : null;
                    if (tab && tab.getAttribute('role') === 'tab') {
                        setView(tab.getAttribute('data-qc-view'));
                    }
                });

                // APG tabs: Left/Right movem foco E seleção (ativação automática);
                // Home/End vão para a primeira/última aba.
                el.tabs.addEventListener('keydown', function (event) {
                    var key = event.key;
                    var idx = VIEWS.indexOf(state.view);
                    var next = null;
                    if (key === 'ArrowRight') {
                        next = VIEWS[(idx + 1) % VIEWS.length];
                    } else if (key === 'ArrowLeft') {
                        next = VIEWS[(idx - 1 + VIEWS.length) % VIEWS.length];
                    } else if (key === 'Home') {
                        next = VIEWS[0];
                    } else if (key === 'End') {
                        next = VIEWS[VIEWS.length - 1];
                    }
                    if (next) {
                        event.preventDefault();
                        setView(next, true);
                    }
                });
            }

            if (el.prev) {
                el.prev.addEventListener('click', function () {
                    if (state.page > 1) {
                        state.page -= 1;
                        refresh();
                        scrollToTop();
                    }
                });
            }

            if (el.next) {
                el.next.addEventListener('click', function () {
                    if (state.page < state.totalPages) {
                        state.page += 1;
                        refresh();
                        scrollToTop();
                    }
                });
            }

            // Quantidade digitada: atualiza SÓ a seleção (nunca adiciona).
            if (el.list) {
                el.list.addEventListener('input', function (event) {
                    var input = event.target;
                    if (!input || !input.getAttribute) {
                        return;
                    }
                    var sku = input.getAttribute('data-qc-qty');
                    if (!sku) {
                        return;
                    }
                    var qty = Core.normalizeQty(input.value, MAX_QTY);
                    if (qty > 0) {
                        state.selection[sku] = qty;
                    } else {
                        delete state.selection[sku];
                    }
                    saveSelection();
                    updateSelectionUi();
                });

                el.list.addEventListener('change', function (event) {
                    var input = event.target;
                    if (input && input.getAttribute && input.getAttribute('data-qc-qty')) {
                        input.value = String(Core.normalizeQty(input.value, MAX_QTY));
                    }
                });

                // Adicionar por linha.
                el.list.addEventListener('click', function (event) {
                    // Favorito por linha (guard de duplo clique no toggleFavorite).
                    var fav = event.target && event.target.closest
                        ? event.target.closest('[data-qc-fav]')
                        : null;
                    if (fav) {
                        event.preventDefault();
                        if (!fav.disabled) {
                            toggleFavorite(fav.getAttribute('data-qc-fav'));
                        }
                        return;
                    }

                    var button = event.target && event.target.closest
                        ? event.target.closest('[data-qc-add]')
                        : null;
                    if (!button || button.disabled) {
                        return;
                    }
                    event.preventDefault();
                    var sku = button.getAttribute('data-qc-add');
                    var input = el.list.querySelector('[data-qc-qty="' + cssEscape(sku) + '"]');
                    var qty = Core.normalizeQty(input ? input.value : 1, MAX_QTY);
                    if (input) {
                        input.value = String(qty);
                    }
                    submitBatch([{ sku: sku, qty: qty }]);
                });
            }

            if (el.addSelected) {
                el.addSelected.addEventListener('click', function () {
                    var items = Core.buildBatchItems(state.selection);
                    if (items.length > 0) {
                        submitBatch(items);
                    }
                });
            }

            // Categorias: filtro e colapso (sem adicionar nada) + CTA do estado vazio.
            document.addEventListener('click', function (event) {
                var cta = event.target && event.target.closest ? event.target.closest('[data-qc-empty-cta]') : null;
                if (cta) {
                    event.preventDefault();
                    resetCatalogFilters();
                    setView('catalog');
                    if (el.searchInput) {
                        el.searchInput.focus();
                    }
                    return;
                }

                var toggle = event.target && event.target.closest ? event.target.closest('[data-qc-cat-toggle]') : null;
                if (toggle) {
                    var id = toggle.getAttribute('data-qc-cat-toggle');
                    var children = document.getElementById('qc-cat-children-' + id);
                    var expanded = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    if (children) {
                        children.hidden = expanded;
                    }
                    return;
                }

                var link = event.target && event.target.closest ? event.target.closest('[data-qc-cat]') : null;
                if (link) {
                    event.preventDefault();
                    Array.prototype.forEach.call(document.querySelectorAll('.qc-cats__link.is-active'), function (node) {
                        node.classList.remove('is-active');
                    });
                    link.classList.add('is-active');
                    state.cat = parseInt(link.getAttribute('data-qc-cat'), 10) || 0;
                    state.page = 1;
                    refresh();
                }
            });

            if (el.sidebarToggle) {
                el.sidebarToggle.addEventListener('click', function () {
                    var isOpen = el.sidebar.classList.toggle('is-open');
                    el.sidebarToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
            }
        }

        function scrollToTop() {
            try {
                var target = document.getElementById('qc-main');
                if (target && target.scrollIntoView) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            } catch (e) {
                /* ignore */
            }
        }

        /* --------------------------------------------------------------- demo -- */

        var demoFavoriteIds = {};
        var demoInstalled = false;

        function demoPrice(value) {
            return '<div class="price-box price-final_price"><span class="normal-price"><span class="price">R$ ' + value + '</span></span></div>';
        }

        function demoPad(n) {
            return (n < 10 ? '0' : '') + n;
        }

        function demoDateUtc(dayOffset, hour, minute) {
            var d = new Date(Date.UTC(2026, 9, 18 - dayOffset, hour, minute, 0));
            return d.getUTCFullYear() + '-' + demoPad(d.getUTCMonth() + 1) + '-' + demoPad(d.getUTCDate())
                + ' ' + demoPad(d.getUTCHours()) + ':' + demoPad(d.getUTCMinutes()) + ':00';
        }

        function demoCatalogItems() {
            return [
                {
                    id: 9001, sku: 'DEMO-001', name: 'Produto de demonstração 1', url: '#',
                    image: null, application: 'Moto demo 150', unit: 'Peça',
                    price: { html: demoPrice('99,90') },
                    availability: { saleable: true, in_stock: true, label: 'Disponível' },
                    requires_config: false, addable: true, exclusive: false
                },
                {
                    id: 9002, sku: 'DEMO-002', name: 'Produto de demonstração 2', url: '#',
                    image: null, application: null, unit: 'Par',
                    price: { html: demoPrice('149,90') },
                    availability: { saleable: true, in_stock: true, label: 'Disponível' },
                    requires_config: false, addable: true, exclusive: true
                },
                {
                    id: 9003, sku: 'DEMO-003', name: 'Produto indisponível (demo)', url: '#',
                    image: null, application: null, unit: null,
                    price: { html: '' },
                    availability: { saleable: false, in_stock: false, label: 'Sem estoque' },
                    requires_config: false, addable: false, exclusive: false
                }
            ];
        }

        function demoBuyAgainItems() {
            var items = [];
            for (var i = 1; i <= 26; i++) {
                var unavailable = i % 7 === 0;
                items.push({
                    id: 8000 + i,
                    sku: 'BA-' + ('00' + i).slice(-3),
                    name: 'Peça comprada anteriormente ' + i,
                    url: '#',
                    image: null,
                    application: i % 2 === 0 ? 'Aplicação demo ' + i : null,
                    unit: i % 3 === 0 ? null : 'Peça',
                    price: { html: demoPrice((10 + i) + ',90') },
                    availability: unavailable
                        ? { saleable: false, in_stock: false, label: 'Sem estoque' }
                        : { saleable: true, in_stock: true, label: 'Disponível' },
                    requires_config: false,
                    addable: !unavailable,
                    exclusive: false,
                    last_purchased: demoDateUtc(i - 1, 14, 30)
                });
            }
            return items;
        }

        function demoWithFavorite(items) {
            return items.map(function (item) {
                return Core.toggleFavoriteState(item, !!demoFavoriteIds[item.id]);
            });
        }

        function demoAnswerSearch(target) {
            var params = new URLSearchParams(target.split('?')[1] || '');
            var q = (params.get('q') || '').toLowerCase();
            var view = params.get('view') || 'catalog';
            var page = parseInt(params.get('page') || '1', 10) || 1;
            var limit = parseInt(params.get('limit') || '24', 10) || 24;
            var items = demoCatalogItems();
            if (view === 'favorites') {
                items = items.filter(function (item) {
                    return !!demoFavoriteIds[item.id];
                });
            }
            if (q) {
                items = items.filter(function (item) {
                    return item.name.toLowerCase().indexOf(q) !== -1 || item.sku.toLowerCase().indexOf(q) !== -1;
                });
            }
            var total = items.length;
            return {
                success: true,
                items: demoWithFavorite(items.slice((page - 1) * limit, page * limit)),
                pagination: { page: page, page_size: limit, total: total },
                facets: { categories: [] },
                applied: { q: params.get('q') || '', cat: null, sort: params.get('sort') || 'relevance', view: view },
                account_key: 'proto-account'
            };
        }

        function demoAnswerBuyAgain(target) {
            var params = new URLSearchParams(target.split('?')[1] || '');
            var page = parseInt(params.get('page') || '1', 10) || 1;
            var limit = parseInt(params.get('limit') || '24', 10) || 24;
            var items = demoBuyAgainItems();
            return {
                success: true,
                items: demoWithFavorite(items.slice((page - 1) * limit, page * limit)),
                pagination: { page: page, page_size: limit, total: items.length },
                facets: { categories: [] },
                applied: { q: '', cat: null, sort: 'relevance', view: 'buyagain' },
                account_key: 'proto-account'
            };
        }

        function demoJson(payload, status) {
            return Promise.resolve(new Response(JSON.stringify(payload), {
                status: status || 200,
                headers: { 'Content-Type': 'application/json' }
            }));
        }

        function demoFindProduct(id) {
            var all = demoCatalogItems().concat(demoBuyAgainItems());
            for (var i = 0; i < all.length; i++) {
                if (all[i].id === id) {
                    return all[i];
                }
            }
            return null;
        }

        /**
         * Modo demo (?qcdebug=demo): substitui window.fetch UMA vez e responde
         * a TODOS os endpoints do catálogo com dados sintéticos (search,
         * buyagain, favorite, add, cart). Nada real é consultado.
         */
        function installDemoFetch() {
            if (demoInstalled) {
                return;
            }
            demoInstalled = true;
            demoFavoriteIds[9002] = true; // DEMO-002 começa favoritado.

            var demoCart = {
                items_count: 0, total_units: 0, distinct_skus: 0,
                items: [], subtotal: 'R$ 0,00', subtotal_value: 0, shipping_unknown: true
            };
            var originalFetch = window.fetch;
            window.fetch = function (requestUrl, options) {
                var target = String(requestUrl);

                if (target.indexOf('/b2b/quickcatalog/search') !== -1) {
                    return demoJson(demoAnswerSearch(target));
                }
                if (target.indexOf('/b2b/quickcatalog/buyagain') !== -1) {
                    return demoJson(demoAnswerBuyAgain(target));
                }
                if (target.indexOf('/b2b/quickcatalog/favorite') !== -1) {
                    var favParams = new URLSearchParams((options && options.body) || '');
                    var productId = parseInt(favParams.get('product_id') || '0', 10);
                    var product = demoFindProduct(productId);
                    if (!product) {
                        return demoJson({ success: false, reason: 'not_available', message: 'Produto não disponível.' });
                    }
                    var wasFavorite = !!demoFavoriteIds[productId];
                    var nowFavorite = !wasFavorite;
                    if (nowFavorite) {
                        demoFavoriteIds[productId] = true;
                    } else {
                        delete demoFavoriteIds[productId];
                    }
                    return demoJson({ success: true, product_id: productId, favorite: nowFavorite });
                }
                if (target.indexOf('/b2b/quickcatalog/cart') !== -1) {
                    return demoJson({ success: true, cart: demoCart });
                }
                if (target.indexOf('/b2b/quickcatalog/add') !== -1) {
                    var added = [];
                    var errors = [];
                    try {
                        var params = new URLSearchParams((options && options.body) || '');
                        var items = JSON.parse(params.get('items') || '[]');
                        items.forEach(function (item) {
                            if (item.sku === 'DEMO-003') {
                                errors.push({ sku: item.sku, message: 'Indisponível (demo)' });
                            } else {
                                added.push({ sku: item.sku, qty: item.qty, name: item.sku, cart_qty_sku: item.qty });
                            }
                        });
                    } catch (e) {
                        errors.push({ sku: '', message: 'demo' });
                    }
                    var bySku = {};
                    (demoCart.items || []).forEach(function (item) {
                        bySku[item.sku] = item.qty;
                    });
                    added.forEach(function (item) {
                        bySku[item.sku] = (bySku[item.sku] || 0) + item.qty;
                    });
                    var demoCartItems = Object.keys(bySku).map(function (sku) {
                        return { sku: sku, qty: bySku[sku], name: sku };
                    });
                    demoCart = {
                        items_count: demoCartItems.length,
                        total_units: demoCartItems.reduce(function (sum, item) { return sum + item.qty; }, 0),
                        distinct_skus: demoCartItems.length,
                        items: demoCartItems,
                        subtotal: 'R$ 0,00',
                        subtotal_value: 0,
                        shipping_unknown: true
                    };
                    return demoJson({
                        success: added.length > 0, replayed: false, added: added, errors: errors,
                        processed: added.length + errors.length,
                        summary: { added: added.length, failed: errors.length },
                        cart: demoCart
                    });
                }
                return originalFetch.apply(window, arguments);
            };
        }

        /* ---------------------------------------------------------------- boot -- */

        loadSelection();
        bindLogoutCleanup();
        bindEvents();
        syncTabLabels();
        renderCart(state.cart);
        updateTabsUi();
        updateViewUi();
        if (demoMode) {
            installDemoFetch();
        }
        refresh();

        if (debugMode) {
            window.__awaQc = {
                Core: Core,
                state: state,
                refresh: refresh,
                setView: setView,
                submitBatch: submitBatch,
                toggleFavorite: toggleFavorite,
                renderCart: renderCart
            };
        }
    });
})();
