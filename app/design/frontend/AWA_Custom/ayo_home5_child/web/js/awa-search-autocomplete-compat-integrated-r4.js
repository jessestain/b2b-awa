define([
	'jquery',
	'js/awa-search-state-r4'
], function ($, SearchState) {
	'use strict';

	let DEFAULT_OPTIONS = {
		inputSelector: '#search-input-autocomplate, #search, input[name="q"]',
		panelSelector: '#search_autocomplete, .mst-searchautocomplete__autocomplete',
		mirasvitPanelSelector: '.mst-searchautocomplete__autocomplete',
		resultsRootSelector: '.searchsuite-autocomplete, .mst-searchautocomplete__autocomplete',
		fallbackEndpoint: '',
		searchResultUrl: '/catalogsearch/result/',
		minQueryLength: 2,
		fallbackDelay: 260,
		nativePaintWait: 1500,
		fallbackTimeout: 8000,
		fallbackSuggestLimit: 6,
		fallbackProductLimit: 6
	};
	let AUTO_BOOT_KEY = '__awaSearchCompatAutoBoot';
	let AUTO_OBSERVER_KEY = '__awaSearchCompatAutoObserver';
	let SEARCH_FORM_SELECTOR = 'form.form.minisearch, #search_mini_form';

	function escapeHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function toText(value) {
		return $('<div/>').html(value || '').text();
	}

	/**
	 * Hide wholesale prices for guests/pending.
	 * Do not rely only on body.b2b-restricted-mode — login-to-cart skips home
	 * (no .product-item-actions early), so that class is often missing while
	 * Instant autocomplete still returns price JSON.
	 */
	function isB2bRestrictedMode() {
		var body = document.body;
		var authEl;
		var state;
		var customer;

		if (body && (
			body.classList.contains('b2b-restricted-mode')
			|| body.classList.contains('b2b-guest-mode')
			|| body.classList.contains('b2b-pending-mode')
		)) {
			return true;
		}

		authEl = document.querySelector('[data-awa-auth-state]');
		if (authEl) {
			state = authEl.getAttribute('data-awa-auth-state') || '';
			if (state === 'guest' || state === 'pending') {
				return true;
			}
			if (state === 'approved' || state === 'logged-in' || state === 'customer') {
				return false;
			}
		}

		if (document.querySelector('.b2b-login-to-see-price, [data-awa-b2b-price-gate="1"]')) {
			return true;
		}

		try {
			customer = JSON.parse(window.localStorage.getItem('mage-cache-storage') || '{}').customer || {};
			if (customer.firstname || customer.fullname || customer.email || customer.id || customer.entity_id) {
				return false;
			}
		} catch (e) {
			/* fail-closed below */
		}

		// B2B storefront: fail-closed — hide prices when auth is unknown.
		return true;
	}

    function findScoped($form, selector) {
        return $form && $form.length ? $form.find(selector).first() : $();
    }

	function visible(el) {
		var inlineDisplay;
		var inlineVisibility;
		if (!el) {
			return false;
		}
		if (el.hasAttribute && el.hasAttribute('hidden')) {
			return false;
		}
		if (el.getAttribute && el.getAttribute('aria-hidden') === 'true') {
			return false;
		}
		if (el.classList && (el.classList.contains('_active') || el.classList.contains('is-open') || el.classList.contains('menu-open'))) {
			return true;
		}
		inlineDisplay = el.style ? el.style.display : '';
		inlineVisibility = el.style ? el.style.visibility : '';
		if (inlineDisplay === 'none' || inlineVisibility === 'hidden') {
			return false;
		}
		return true;
	}


	function panelHasContent($panel) {
		if (!$panel || !$panel.length) {
			return false;
		}

		return $panel.find([
			'li',
			'.mst-searchautocomplete__item',
			'.awa-fallback-item',
			'.qs-option-info',
			'.suggest a',
			'.product a',
			'.no-result'
		].join(',')).length > 0;
	}

	function isSearchFocused($form, $input) {
		if ($form.is(':focus-within')) {
			return true;
		}

		if (!$input || !$input.length) {
			return false;
		}

		return $input.get(0) === document.activeElement;
	}

	function setPanelAriaHidden($panel, value) {
		if (!$panel || !$panel.length) {
			return;
		}

		if ($panel.attr('aria-hidden') !== value) {
			$panel.attr('aria-hidden', value);
		}
	}

	/**
	 * Scrim full-page ao focar a busca — isola o campo (a11y + UX Magento).
	 * Classe: body.awa-search-focus-active (independente do painel Mirasvit).
	 */
	function ensureSearchFocusBackdrop() {
		var el = document.getElementById('awa-search-focus-backdrop');
		// Substitui button legado (min-height 44px colapsava o scrim)
		if (el && el.tagName !== 'DIV') {
			el.parentNode && el.parentNode.removeChild(el);
			el = null;
		}
		if (el) {
			return el;
		}
		// div (não button): evita min-height:44px global de botões do header
		el = document.createElement('div');
		el.id = 'awa-search-focus-backdrop';
		el.className = 'awa-search-focus-backdrop';
		el.setAttribute('role', 'button');
		el.setAttribute('aria-label', 'Fechar busca');
		el.setAttribute('tabindex', '-1');
		el.setAttribute('hidden', 'hidden');
		(document.body || document.documentElement).appendChild(el);
		el.addEventListener('click', function () {
			var input = document.activeElement;
            closeAllMirasvitAutocompletePanels('backdrop');
            setSearchFocusActive(false);
			if (input) {
				input.blur();
			}
			if (document.body) {
				document.body.classList.remove('searchautocomplete__active');
			}
		});
		return el;
	}


	/* H-search-popular-width (2026-08-02): painel absolute em .control (~235px)
	   enquanto .awa-header-search-col tem ~343px. Fixa geometria via CSS vars. */
	function syncSearchPanelGeometry(form) {
		form = form || document.querySelector('form[data-awa-search-integrated="r4"].is-open');
        var col = form && (form.closest('.awa-header-search-col, .top-search') || form);
        var input = form && form.querySelector('#search, input[name="q"]');
		var root = document.documentElement;
		if (!col || !input || !root) {
			return;
		}
		var cr = col.getBoundingClientRect();
		var ir = input.getBoundingClientRect();
		var left = Math.max(0, Math.round(cr.left));
		var width = Math.max(200, Math.round(cr.width));
		var top = Math.round(ir.bottom + 8);
		root.style.setProperty('--awa-search-panel-left', left + 'px');
		root.style.setProperty('--awa-search-panel-width', width + 'px');
		root.style.setProperty('--awa-search-panel-top', top + 'px');
	}

	function clearSearchPanelGeometry() {
		var root = document.documentElement;
		if (!root) {
			return;
		}
		root.style.removeProperty('--awa-search-panel-left');
		root.style.removeProperty('--awa-search-panel-width');
		root.style.removeProperty('--awa-search-panel-top');
	}

	function setSearchFocusActive(active, form) {
		var body = document.body;
		var backdrop;
		if (!body) {
			return;
		}
		backdrop = ensureSearchFocusBackdrop();
		body.classList.toggle('awa-search-focus-active', !!active);
		backdrop.setAttribute('aria-hidden', active ? 'false' : 'true');
		backdrop.classList.toggle('is-active', !!active);
		if (active) {
			backdrop.removeAttribute('hidden');
			syncSearchPanelGeometry(form);
		} else {
			backdrop.setAttribute('hidden', 'hidden');
			clearSearchPanelGeometry();
		}
	}

    function bindSearchFocusOverlay() {
        ensureSearchFocusBackdrop();
        if (window.__awaSearchPanelGeomBound) { return; }
        window.__awaSearchPanelGeomBound = 1;
        function updateOwnedGeometry() {
            var form = document.querySelector('form[data-awa-search-integrated="r4"].is-open');
            if (form) { syncSearchPanelGeometry(form); }
        }
        window.addEventListener('resize', updateOwnedGeometry, {passive: true});
        window.addEventListener('scroll', updateOwnedGeometry, {passive: true});
    }

	function setInputAriaExpanded($input, value) {
		if (!$input || !$input.length) {
			return;
		}

		if ($input.attr('aria-expanded') !== value) {
			$input.attr('aria-expanded', value);
		}
	}

	function promotePanelOpen($form, $panel, $input) {
		if (!panelHasContent($panel) || !isSearchFocused($form, $input)) {
			return false;
		}

		$form.addClass('is-open').removeClass('is-empty');
		$panel.addClass('is-open active has-results');
		setPanelAriaHidden($panel, 'false');
		$panel.removeAttr('hidden');

		if ($input.length) {
			setInputAriaExpanded($input, 'true');
		}

		if (document.body) {
			document.body.classList.add('searchautocomplete__active');
			setSearchFocusActive(true);
		}

		return true;
	}


    function closeAllMirasvitAutocompletePanels(reason) {
        $(SEARCH_FORM_SELECTOR).each(function () { SearchState.forForm(this).close(reason || 'close'); });
        return true;
    }

    function closeSearchForm(form, reason) {
        var input = form.querySelector('#search, input[name="q"]');
        if (input && document.activeElement !== input) { input.focus({preventScroll: true}); }
        SearchState.forForm(form).close(reason || 'close');
    }

    function bindGlobalEscapeClose() {
        if (window.__awaSearchEscMstBound) { return; }
        window.__awaSearchEscMstBound = true;
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || event.isComposing || event.keyCode === 229) { return; }
            var form = event.target.closest && event.target.closest(SEARCH_FORM_SELECTOR);
            if (!form) { form = document.querySelector('form[data-awa-search-integrated="r4"].is-open'); }
            if (!form) { return; }
            closeSearchForm(form, 'escape');
            event.preventDefault();
        }, true);
    }

	function demotePanelClosed($form, $panel, $input) {
		$form.removeClass('is-open has-results').addClass('is-empty');
		/* 9A S4: Mirasvit uses ._active (not .active). CSS §36.4b hides :not(._active). */
		$panel.removeClass('is-open active _active has-results');
		setPanelAriaHidden($panel, 'true');

		if ($input.length) {
			setInputAriaExpanded($input, 'false');
		}

		if (document.body) {
			document.body.classList.remove('searchautocomplete__active');
			if (!$form.is(':focus-within')) {
				setSearchFocusActive(false);
			}
		}
	}

	function applyTitles($root) {
		$root.find('a[href]').each(function () {
			var $a = $(this);
			let text = $.trim($a.text());

			if (!text) {
				return;
			}

			if (!$a.attr('title')) {
				$a.attr('title', text);
			}

			if (!$a.attr('aria-label')) {
				$a.attr('aria-label', text);
			}
		});
	}

    function getFallbackPanel($form, _options) {
        return $(SearchState.hosts($form[0]).fallback || []);
    }

    function ownerPanel($form) {
        var machine = SearchState.forForm($form[0]);
        var state = machine.snapshot();
        return $(SearchState.hosts($form[0])[state.owner] || []);
    }

    function syncState($form, options) {
        var machine = SearchState.forForm($form[0]), state = machine.snapshot();
        var hosts = SearchState.hosts($form[0]), $input = findScoped($form, options.inputSelector);
        var visibleOwner = false;
        ['native', 'fallback'].forEach(function (kind) {
            var host = hosts[kind];
            if (!host) { return; }
            var open = state.open && state.owner === kind
                && host.getAttribute('data-awa-query') === state.query
                && host.getAttribute('data-awa-epoch') === String(state.epoch);
            $(host).toggleClass('is-open', open).toggleClass('_active', open)
                .toggleClass('active', open);
            setPanelAriaHidden($(host), open ? 'false' : 'true');
            if (host.hidden !== !open) { host.hidden = !open; }
            if (open) { visibleOwner = true; }
        });
        SearchState.syncAria($form[0]);
        if (!visibleOwner || state.selection < 0) { $input.removeAttr('aria-activedescendant'); }
        $form.toggleClass('is-open', visibleOwner).toggleClass('has-results', visibleOwner)
            .toggleClass('is-empty', !visibleOwner);
        var activeForm = visibleOwner ? $form[0] : document.querySelector('form[data-awa-search-integrated="r4"].is-open');
        if (document.body) { document.body.classList.toggle('searchautocomplete__active', !!activeForm); }
        setSearchFocusActive(!!activeForm, activeForm);
        if (visibleOwner) { applyTitles(ownerPanel($form)); }
    }

	function getMirasvitPanel($form, options) {
		return findScoped($form, options.mirasvitPanelSelector || '.mst-searchautocomplete__autocomplete');
	}

	function isMirasvitPanelOpen($form, options) {
		var $mirasvit = getMirasvitPanel($form, options);
		var el;

		if (!$mirasvit.length) {
			return false;
		}

		el = $mirasvit.get(0);
		if ($mirasvit.hasClass('_active') || $mirasvit.hasClass('is-open')) {
			return visible(el) || $mirasvit.find('.mst-searchautocomplete__item, li').length > 0;
		}

		return visible(el) && $mirasvit.find('.mst-searchautocomplete__item, li').length > 0;
	}

	function resolveActivePanel($form, options) {
		var $mirasvit = getMirasvitPanel($form, options);

		if (isMirasvitPanelOpen($form, options)) {
			return $mirasvit;
		}

		return findScoped($form, options.panelSelector);
	}

	/**
	 * AWA: com Mirasvit ativo, NÃO chamar /search/ajax/suggest (Magento).
	 * Evita XHR duplicado, 500s e contenção no header.
	 */
	function isMirasvitOwned($form, options) {
		var $input = findScoped($form, options.inputSelector || '#search');

		if ($input.length && $input.data('awaMirasvitAutocompleteInit')) {
			return true;
		}

		if (document.querySelector('.mst-searchautocomplete__autocomplete')) {
			return true;
		}

		return false;
	}

	function hasNativeResults($form, options) {
		var $resultsRoot = findScoped($form, options.resultsRootSelector);
		var $panel = getFallbackPanel($form, options);
		var $nativeItems = $();

		if (isMirasvitPanelOpen($form, options)) {
			return true;
		}

		if ($resultsRoot.length) {
			$nativeItems = $nativeItems.add($resultsRoot.find('li').not('.awa-fallback-item'));
		}

		if ($panel.length) {
			$nativeItems = $nativeItems.add($panel.find('li').not('.awa-fallback-item'));
		}

		return $nativeItems.length > 0;
	}

	function extractSuggestItems(suggestRaw) {
		let suggest = [];

		$.each(suggestRaw || [], function (_, item) {
			let label = '';
			let url = '';

			if (typeof item === 'string') {
				label = item;
			} else if (item && typeof item === 'object') {
				label = item.query_text || item.label || item.value || item.name || item.title || '';
				url = item.url || '';
			}

			label = $.trim(toText(label));
			url = $.trim(url);

			if (label) {
				suggest.push({
					label: label,
					url: url
				});
			}
		});

		return suggest;
	}

	function extractProductItems(productRaw) {
		let products = [];
		let hidePrice = isB2bRestrictedMode();

		$.each(productRaw || [], function (_, item) {
			if (!item || typeof item !== 'object') {
				return;
			}

			products.push({
				name: $.trim(toText(item.name || item.title || '')),
				url: $.trim(item.url || '#'),
				image: $.trim(item.image || item.imageUrl || ''),
				priceText: hidePrice ? '' : $.trim(toText(item.price || item.priceText || '')),
				sku: $.trim(toText(item.sku || '')),
				fitment: $.trim(toText(item.fitment || ''))
			});
		});

		return products;
	}

	function normalizeFallbackPayload(payload) {
		let suggest = [];
		let products = [];

		if ($.isArray(payload)) {
			suggest = suggest.concat(extractSuggestItems(payload));
		}

		if (payload && $.isArray(payload.result)) {
			$.each(payload.result, function (_, chunk) {
				if (!chunk || typeof chunk !== 'object') {
					return;
				}

				if (chunk.code === 'suggest') {
					suggest = suggest.concat(extractSuggestItems(chunk.data));
					return;
				}

				if (chunk.code === 'product') {
					products = products.concat(extractProductItems(chunk.data));
				}
			});
		}

		if (payload && $.isArray(payload.indexes)) {
			$.each(payload.indexes, function (_, index) {
				if (!index || typeof index !== 'object') {
					return;
				}

				if (index.identifier === 'magento_search_query') {
					suggest = suggest.concat(extractSuggestItems(index.items));
					return;
				}

				if (index.identifier === 'magento_catalog_product') {
					products = products.concat(extractProductItems(index.items));
				}
			});
		}

		return {
			suggest: suggest,
			products: products
		};
	}

	function buildFallbackMarkup(normalized, options, query) {
		let html = '';
		let i;
		let suggestion;
		let suggestionHref;
		let product;
		let searchResultUrl = (options.searchResultUrl || '/catalogsearch/result/').replace(/\/+$/, '');

		if (normalized.suggest.length) {
			html += '<div class="suggest">';
			html += '<ul role="listbox">';

			for (i = 0; i < normalized.suggest.length && i < options.fallbackSuggestLimit; i += 1) {
				suggestion = normalized.suggest[i];
				suggestionHref = suggestion.url || (searchResultUrl + '/?q=' + encodeURIComponent(suggestion.label));

				html += '<li class="awa-fallback-item" role="option">';
				html += '<a href="' + escapeHtml(suggestionHref) + '">' + escapeHtml(suggestion.label) + '</a>';
				html += '</li>';
			}

			html += '</ul>';
			html += '</div>';
		}

		if (normalized.products.length) {
			html += '<div class="product">';
			html += '<ul role="listbox">';

			for (i = 0; i < normalized.products.length && i < options.fallbackProductLimit; i += 1) {
				product = normalized.products[i];
				html += '<li class="awa-fallback-item" role="option">';

				if (product.image) {
					html += '<div class="qs-option-image">';
					html += '<a href="' + escapeHtml(product.url || '#') + '">';
					html += '<img src="' + escapeHtml(product.image) + '" alt="' + escapeHtml(product.name || query) + '" loading="lazy" />';
					html += '</a>';
					html += '</div>';
				}

				html += '<div class="qs-option-info">';
				html += '<div class="qs-option-title"><a href="' + escapeHtml(product.url || '#') + '">' + escapeHtml(product.name || query) + '</a></div>';

				if (product.sku) {
					html += '<div class="awa-ac-product-sku">SKU: <span>' + escapeHtml(product.sku) + '</span></div>';
				}

				if (product.fitment) {
					html += '<div class="awa-ac-product-fitment" title="Compatibilidade" aria-label="Compatibilidade do produto">' + escapeHtml(product.fitment) + '</div>';
				}

				if (product.priceText) {
					html += '<div class="qs-option-price">' + escapeHtml(product.priceText) + '</div>';
				}

				html += '</div>';
				html += '</li>';
			}

			html += '</ul>';
			html += '</div>';
		}

		if (!html) {
			html = '<div class="no-result">Nenhum resultado encontrado.</div>';
		}

		return html;
	}

    function clearFallback($form, options) {
        var $panel = getFallbackPanel($form, options);
        $panel.empty().removeAttr('data-awa-fallback-rendered data-awa-query data-awa-epoch')
            .removeClass('is-open active _active has-results').attr('aria-hidden', 'true').attr('hidden', 'hidden');
    }

    function renderFallback($form, options, payload, query, state, ticket) {
        var machine = state.machine, current = machine.snapshot();
        if (!machine.current(ticket) || SearchState.normalize(query) !== SearchState.normalize(findScoped($form, options.inputSelector).val())) { return; }
        var panel = SearchState.hosts($form[0]).fallback;
        if (!panel || !isSearchFocused($form, findScoped($form, options.inputSelector))) { return; }
        var html = buildFallbackMarkup(normalizeFallbackPayload(payload), options, query);
        // No stale network/cache response may write to the live host.
        if (!machine.current(ticket)) { return; }
        panel.innerHTML = html;
        panel.setAttribute('data-awa-fallback-rendered', 'true');
        panel.setAttribute('data-awa-query', current.query);
        panel.setAttribute('data-awa-epoch', String(current.epoch));
        machine.commit(ticket);
        syncState($form, options);
    }

	function resolveFallbackEndpoint($form, options) {
		let explicit = options.fallbackEndpoint || '';
		let attrEndpoint = $form.attr('data-awa-search-endpoint') || '';

		if (explicit) {
			return explicit;
		}

		if (attrEndpoint) {
			return attrEndpoint;
		}

		// [AWA][SRCH-007] Core Magento /search/ajax/suggest returns [] on this store.
		// Mirasvit Instant (/searchautocomplete/ajax/suggest/) is the real source.
		return '/searchautocomplete/ajax/suggest/';
	}

	function buildCacheKey(query, categoryValue) {
		return query + '::' + (categoryValue || '');
	}

    function handleSearchKeydown($form, options, machine, event) {
            if (event.isComposing || (event.originalEvent && event.originalEvent.isComposing) || event.keyCode === 229) { return; }
            var $panel = ownerPanel($form), state = machine.snapshot();
            var $items = $panel.find('.mst-searchautocomplete__item, .awa-fallback-item').filter(function () {
                return $(this).find('a[href]').not('.tocart,.mst__add_to_cart').length > 0;
            });
            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && $items.length && state.open) {
                event.preventDefault();
                var index = machine.select(event.key === 'ArrowDown' ? 1 : -1, $items.length);
                $items.removeClass('_active').attr('aria-selected', 'false');
                var item = $items.get(index); item.id = state.id + '-option-' + index;
                $(item).addClass('_active').attr('aria-selected', 'true');
                $(event.currentTarget).attr('aria-activedescendant', item.id);
                item.scrollIntoView({block: 'nearest'});
            } else if (event.key === 'Enter' && state.selection >= 0 && $items.get(state.selection)) {
                var link = $($items.get(state.selection)).find('a[href]').not('.tocart,.mst__add_to_cart').get(0);
                if (link) { event.preventDefault(); link.click(); }
            }
    }

    function runFallbackRequest($form, options, state, query) {
        var machine = state.machine;
        if (!machine.canFallback(options.nativePaintWait)
            || SearchState.normalize(query) !== SearchState.normalize(findScoped($form, options.inputSelector).val())) { return; }
        var ticket = machine.reserve('fallback');
        if (!ticket) { return; }
        var category = SearchState.context($form[0]);
        var cacheKey = buildCacheKey(ticket.query, category);
        if (Object.prototype.hasOwnProperty.call(state.cache, cacheKey)) {
            renderFallback($form, options, state.cache[cacheKey], query, state, ticket);
            machine.finish(ticket); return;
        }
        var params = {q: query, store_id: options.storeId || (window.checkout && window.checkout.storeId) || 1};
        if (category) { params.cat = category; }
        var request = $.ajax({url: resolveFallbackEndpoint($form, options), method: 'GET', dataType: 'json',
            data: params, cache: false, timeout: options.fallbackTimeout})
            .done(function (response) {
                if (!machine.current(ticket) || ticket.context !== SearchState.context($form[0])
                    || ticket.query !== SearchState.normalize(findScoped($form, options.inputSelector).val())
                    || !isSearchFocused($form, findScoped($form, options.inputSelector))) { return; }
                state.cache[cacheKey] = response;
                renderFallback($form, options, response, query, state, ticket);
            }).fail(function (_xhr, status) {
                if (status === 'abort' || !machine.current(ticket)) { return; }
                machine.fail(ticket, 'transport');
                clearFallback($form, options); syncState($form, options);
            }).always(function () {
                machine.finish(ticket);
                if (state.xhr === request) { state.xhr = null; }
            });
        state.xhr = request;
        machine.track(ticket, function () { request.abort(); });
    }

    function scheduleFallbackRequest($form, options, state, query) {
        var machine = state.machine;
        var previous = machine.snapshot();
        if (previous.composing || previous.mode === 'popular' && previous.open
            && previous.query === SearchState.normalize(query) && previous.context === SearchState.context($form[0])) { return; }
        machine.begin(query, SearchState.context($form[0]));
        var current = machine.snapshot();
        if (!current.open || current.owner === 'native' || current.fallbackFailed) { return; }
        machine.schedule('fallback', function () {
            runFallbackRequest($form, options, state, query);
        }, machine.fallbackDelay(options.nativePaintWait, options.fallbackDelay));
    }

	function initCompat(config, element) {
		let options = $.extend({}, DEFAULT_OPTIONS, config || {});
		var $form = $(element);
		let observer;
		let bodyObserver;
		let panelNode;
		let scheduled = false;
		let lastFlushAt = 0;
		let scopeNode;
		if (!$form.length || $form.data('awaSearchCompatInit')) {
			return;
		}
        var machine = SearchState.forForm($form[0]);
        SearchState.prepareForm($form[0]);
        SearchState.bindFocus($form[0]);
        machine.configure({enhanced: true, nativeExpected: !!document.getElementById('awa-mirasvit-ac-bootstrap-config')});
        var fallbackState = {xhr: null, cache: {}, machine: machine};



		function flushSync() {
			scheduled = false;
			lastFlushAt = Date.now();
			attachPanelObserver();
			syncState($form, options);
		}

		function scheduleSync() {
			var elapsed;
			if (scheduled) {
				return;
			}

			elapsed = Date.now() - lastFlushAt;
			scheduled = true;

			if (elapsed < 90) {
				window.setTimeout(flushSync, 90 - elapsed);
				return;
			}

			if (typeof window.requestAnimationFrame === 'function') {
				window.requestAnimationFrame(flushSync);
				return;
			}

			window.setTimeout(flushSync, 0);
		}

		function attachPanelObserver() {
			let nextPanelNode;

			if (!observer) {
				return false;
			}

			nextPanelNode = SearchState.hosts($form[0]).native || SearchState.hosts($form[0]).fallback;
			if (!nextPanelNode) {
				return false;
			}

			if (panelNode === nextPanelNode) {
				return true;
			}

			observer.disconnect();
			panelNode = nextPanelNode;
			observer.observe(panelNode, {
				subtree: true,
				childList: true,
				attributes: true,
				attributeFilter: ['class', 'aria-hidden']
			});

			return true;
		}

		$form.attr({
			'data-awa-component': $form.attr('data-awa-component') || 'search-autocomplete',
			'data-awa-initialized': 'true'
		}).addClass('is-ready');

		scheduleSync();

        machine.subscribe(function (state, reason) {
            if (reason === 'begin' || !state.open || state.owner === 'native') { clearFallback($form, options); }
            if ((reason === 'failure' || reason === 'native-render') && state.native !== 'ready' && !state.fallbackFailed && state.owner !== 'fallback') {
                scheduleFallbackRequest($form, options, fallbackState, findScoped($form, options.inputSelector).val());
            }
            syncState($form, options);
        });
        $form.on('awaSearchNativeReady.awaSearchCompat', function () { clearFallback($form, options); syncState($form, options); });
        $form.on('focusin.awaSearchCompat input.awaSearchCompat compositionend.awaSearchCompat', options.inputSelector, function (event) {
            if (event.type === 'compositionend') { machine.endComposition($(this).val(), SearchState.context($form[0])); }
            else if (event.originalEvent && event.originalEvent.isComposing) { machine.beginComposition(); return; }
            if (machine.snapshot().composing) { return; }
            scheduleFallbackRequest($form, options, fallbackState, $(this).val());
        });
        $form.on('compositionstart.awaSearchCompat', options.inputSelector, function () { machine.beginComposition(); });
        $form.on('keydown.awaSearchCompat', options.inputSelector, function (event) {
            handleSearchKeydown($form, options, machine, event);
        });
        $form.on('change.awaSearchCompat', '[name="cat"]', function () {
            scheduleFallbackRequest($form, options, fallbackState, findScoped($form, options.inputSelector).val());
        });
        $form.on('click.awaSearchCompat', '[data-awa-search-owner] a[href]', function (event) {
            var host = this.closest('[data-awa-search-owner]'), state = machine.snapshot();
            if (!host || !state.open || host.getAttribute('data-awa-search-owner') !== state.owner
                || host.getAttribute('data-awa-query') !== state.query
                || host.getAttribute('data-awa-epoch') !== String(state.epoch)) { event.preventDefault(); }
        });

		if (typeof window.MutationObserver === 'function') {
			observer = new window.MutationObserver(function () {
				scheduleSync();
			});

			attachPanelObserver();
			scopeNode = $form.closest('.block-search, .header .search, .top-search').get(0) || document.body;

			if (!panelNode && scopeNode) {
				bodyObserver = new window.MutationObserver(function () {
					attachPanelObserver();
					scheduleSync();
				});

				bodyObserver.observe(scopeNode, {
					subtree: true,
					childList: true
				});
			}

			$form.data('awaSearchCompatObserver', observer);
			if (bodyObserver) {
				$form.data('awaSearchCompatBodyObserver', bodyObserver);
			}
		}

		$form.data('awaSearchCompatInit', 1);
		$form.attr('data-awa-search-compat-init', '1');
		bindGlobalEscapeClose();
        bindSearchFocusOverlay($form, options);
	}

	function bootAll(config) {
		let options = $.extend({}, DEFAULT_OPTIONS, config || {});

		$(SEARCH_FORM_SELECTOR).each(function () {
			initCompat(options, this);
		});
	}

	function shouldObserveMutation(mutations) {
		let i;
		let j;
		let mutation;
		let addedNodes;

		for (i = 0; i < mutations.length; i += 1) {
			mutation = mutations[i];
			if (!mutation || !mutation.addedNodes || !mutation.addedNodes.length) {
				continue;
			}

			addedNodes = mutation.addedNodes;
			for (j = 0; j < addedNodes.length; j += 1) {
				if (!addedNodes[j] || addedNodes[j].nodeType !== 1) {
					continue;
				}

				if ($(addedNodes[j]).is(SEARCH_FORM_SELECTOR) || $(addedNodes[j]).find(SEARCH_FORM_SELECTOR).length) {
					return true;
				}
			}
		}

		return false;
	}

	function autoBoot() {
		if (window[AUTO_BOOT_KEY]) {
			return;
		}

		window[AUTO_BOOT_KEY] = true;

		if (document.readyState === 'loading') {
			$(function () {
				bootAll({});
			});
		} else {
			bootAll({});
		}

		$(document).on('contentUpdated.awaSearchCompatAuto', function (event) {
			if (!event || !event.target || $(event.target).is(SEARCH_FORM_SELECTOR) || $(event.target).find(SEARCH_FORM_SELECTOR).length) {
				bootAll({});
			}
		});

		let headerScope = document.querySelector(
			'.awa-site-header, #header.header-container, .page-header, header.page-header'
		);

		if (window.MutationObserver && headerScope && !window[AUTO_OBSERVER_KEY]) {
			window[AUTO_OBSERVER_KEY] = new window.MutationObserver(function (mutations) {
				if (!shouldObserveMutation(mutations)) {
					return;
				}

				bootAll({});

				let $forms = $(SEARCH_FORM_SELECTOR);
				if ($forms.length && $forms.filter('[data-awa-search-compat-init="1"]').length >= $forms.length) {
					window[AUTO_OBSERVER_KEY].disconnect();
					window[AUTO_OBSERVER_KEY] = null;
				}
			});

			window[AUTO_OBSERVER_KEY].observe(headerScope, {
				childList: true,
				subtree: true
			});
		}
	}

	autoBoot();

	return function (config, element) {
		initCompat(config, element);
	};
});
