/**
 * AWA Motos — Header / busca / minicart UI (v2 performance-safe)
 * Evita MutationObserver em attributes/style (loop com setProperty).
 */
(function () {
    'use strict';

    var runtime = window.__awaHeaderMinicartRuntime = window.__awaHeaderMinicartRuntime || {};
    if (!runtime.cosmeticOwner) {
        runtime.cosmeticOwner = 'v2';
        runtime.cosmeticOwnerSource = 'awa-header-minicart-ui-v2.js';
        runtime.cosmeticOwnerClaimedAt = Date.now();
    } else if (runtime.cosmeticOwner !== 'v2') {
        return;
    }

    if (window.__awaRound2HeaderMinicartUiInit) {
        return;
    }
    window.__awaRound2HeaderMinicartUiInit = true;

    var HEADER_ROW_SEL = '.header .header_main .awa-main-header__inner[data-awa-header-row], .header .header_main .wp-header[data-awa-header-row], .header .header-main .awa-main-header__inner[data-awa-header-row], .header .header-main .wp-header[data-awa-header-row]';
    var layoutFingerprint = '';
    var searchOptionSeq = 0;
    var searchCompatLoading = false;
    var searchCompatReady = false;
    var resizeScheduled = false;
    var scrollScheduled = false;
    var searchSyncScheduled = false;
    var searchSyncLastAt = 0;
    var layoutSyncScheduled = false;
    var searchSyncRunning = false;
    var layoutSyncRunning = false;
    var stickyScrollScheduled = false;
    var overlaySyncScheduled = false;
    var overlaySyncRunning = false;
    var overlaySyncLastAt = 0;

    function bodyEl() {
        return document.body;
    }

    function isMenuV2DeptOpen() {
        if (!window.__AWA_MENU_V2) {
            return false;
        }
        if (document.body && document.body.classList.contains('awa-menu-dept-open')) {
            return true;
        }
        var panel = document.querySelector('[data-role="awa-vertical-menu-panel"]');
        return !!(panel && panel.getAttribute('data-awa-menu-state') === 'open');
    }

    function stickyWrapper() {
        return document.querySelector('.header-wrapper-sticky');
    }

    function searchBlock() {
        return document.querySelector('.header .top-search .block-search, .awa-site-header .block-search');
    }

    function hasModernHeader() {
        return !!document.querySelector('.awa-site-header');
    }

    function isVisible(el) {
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
        inlineDisplay = el.style ? el.style.display : '';
        inlineVisibility = el.style ? el.style.visibility : '';
        if (inlineDisplay === 'none' || inlineVisibility === 'hidden') {
            return false;
        }
        return true;
    }

    function isHomePage() {
        var b = bodyEl();
        return !!b && (b.classList.contains('cms-index-index') || b.classList.contains('cms-home') || b.classList.contains('cms-homepage_ayo_home5'));
    }

    function isCategoryPage() {
        var b = bodyEl();
        return !!b && (b.classList.contains('catalog-category-view') || b.classList.contains('catalogsearch-result-index'));
    }

    function isAuthShell() {
        var body = bodyEl();
        return !!body && (
            body.classList.contains('b2b-auth-shell') ||
            body.classList.contains('customer-account') ||
            body.classList.contains('customer-account-login') ||
            body.classList.contains('customer-account-create')
        );
    }

    function setStyleImportant(el, prop, value) {
        if (!el || el.getAttribute('data-awa-js-layout') === 'css') {
            return;
        }
        if (el.style.getPropertyValue(prop) === value && el.style.getPropertyPriority(prop) === 'important') {
            return;
        }
        el.style.setProperty(prop, value, 'important');
    }

    function isValidMinicartQtyChange(origin, changed) {
        return String(origin) !== String(changed) &&
            String(changed).length > 0 &&
            Number(changed) == changed && // eslint-disable-line eqeqeq
            Number(changed) > 0;
    }

    function syncMinicartUpdateButtonForInput(input) {
        var row;
        var button;
        var baseQty;
        var changedQty;
        var shouldShowUpdate;

        if (!input || !input.classList || !input.classList.contains('cart-item-qty')) {
            return;
        }

        row = input.closest('.details-qty');
        button = row ? row.querySelector('.update-cart-item') : null;

        if (!button) {
            return;
        }
        baseQty = input.getAttribute('data-item-qty');
        changedQty = input.value;
        shouldShowUpdate = isValidMinicartQtyChange(baseQty, changedQty);

        if (shouldShowUpdate) {
            button.style.removeProperty('display');
            button.style.removeProperty('visibility');
            button.style.removeProperty('inline-size');
            button.style.removeProperty('block-size');
            button.style.removeProperty('min-inline-size');
            button.style.removeProperty('min-block-size');
            button.style.removeProperty('overflow');
            button.style.removeProperty('margin');
            button.style.removeProperty('padding');
            button.style.removeProperty('border');
            button.style.removeProperty('pointer-events');
        } else {
            button.style.setProperty('display', 'none', 'important');
        }

    }

    function syncMinicartUpdateButtons(root) {
        var scope = root && root.querySelectorAll ? root : document;

        scope.querySelectorAll('.block-minicart .cart-item-qty').forEach(syncMinicartUpdateButtonForInput);
    }

    function onMinicartQtyEvent(evt) {
        var input;

        if (!evt.target) {
            return;
        }

        if (evt.type === 'awa:qty-control:change') {
            input = evt.target.querySelector ? evt.target.querySelector('.cart-item-qty') : null;
        } else {
            input = evt.target.closest ? evt.target.closest('.block-minicart .cart-item-qty') : null;
        }

        syncMinicartUpdateButtonForInput(input);
    }

    function onMinicartOpenIntent(evt) {
        if (!evt.target || !evt.target.closest) {
            return;
        }

        if (evt.target.closest('[data-block="minicart"] .showcart, .minicart-wrapper .showcart')) {
            syncMinicartUpdateButtons(document);
        }
    }

    function bindMinicartOpenSync() {
        var bind = function ($) {
            $(document)
                .off('dropdowndialogopen.awaMinicartUpdateSync')
                .on('dropdowndialogopen.awaMinicartUpdateSync', function () {
                    syncMinicartUpdateButtons(document);
                });
        };

        if (window.jQuery) {
            bind(window.jQuery);
            return;
        }

        if (window.require) {
            window.require(['jquery'], bind);
        }
    }

    function initMinicartUpdateButtonObserver() {
        var root = document.querySelector('[data-block="minicart"]');
        var observer;

        if (!root || !window.MutationObserver || root.getAttribute('data-awa-update-button-observed') === '1') {
            return;
        }

        root.setAttribute('data-awa-update-button-observed', '1');
        observer = new MutationObserver(function (mutations) {
            var shouldSync = mutations.some(function (mutation) {
                return mutation.type === 'childList' && (mutation.addedNodes.length || mutation.removedNodes.length);
            });

            if (shouldSync) {
                syncMinicartUpdateButtons(root);
            }
        });

        observer.observe(root, {
            childList: true,
            subtree: true
        });
    }

    function setOverlayStyleImportant(el, prop, value) {
        if (!el) {
            return;
        }
        if (el.style.getPropertyValue(prop) === value && el.style.getPropertyPriority(prop) === 'important') {
            return;
        }
        el.style.setProperty(prop, value, 'important');
    }

    function schedule(fn, flagName, holder) {
        if (holder[flagName]) {
            return;
        }
        holder[flagName] = true;
        window.requestAnimationFrame(function () {
            holder[flagName] = false;
            fn();
        });
    }

    var syncFlags = { search: false, layout: false, scroll: false, resize: false };

    function scheduleSearchSync() {
        var elapsed;
        if (isMinicartOverlayActive()) {
            return;
        }
        elapsed = Date.now() - searchSyncLastAt;
        if (elapsed < 120 && syncFlags.search) {
            return;
        }
        schedule(function searchSyncJob() {
            if (searchSyncRunning) {
                return;
            }
            searchSyncRunning = true;
            searchSyncLastAt = Date.now();
            try {
                ensureSearchCompat();
                syncSearchPanelState();
            } finally {
                searchSyncRunning = false;
            }
        }, 'search', syncFlags);
    }

    function scheduleLayoutSync() {
        schedule(function layoutSyncJob() {
            if (layoutSyncRunning) {
                return;
            }
            layoutSyncRunning = true;
            try {
                syncLayout();
            } finally {
                layoutSyncRunning = false;
            }
        }, 'layout', syncFlags);
    }

    function getSearchContext() {
        var block = searchBlock();
        var form = block ? block.querySelector('#search_mini_form, form.form.minisearch') : null;
        if (!block || !form) {
            return null;
        }
        return {
            scope: block,
            form: form,
            input: form.querySelector('#search, #search-input-autocomplate, input[name="q"]'),
            actions: form.querySelector('.actions'),
            button: form.querySelector('.action.search, button[type="submit"]'),
            panel: block.querySelector('.mst-searchautocomplete__autocomplete, #search_autocomplete'),
            resultsRoot: block.querySelector('.mst-searchautocomplete__autocomplete, .searchsuite-autocomplete, #search_autocomplete')
        };
    }

    function ensureSearchCompat() {
        var req = typeof window.require === 'function' ? window.require : (typeof window.requirejs === 'function' ? window.requirejs : null);
        var ctx = getSearchContext();
        var root = ctx ? ctx.scope : document;
        var hasAutocomplete = document.getElementById('searchAutocompletePlaceholder') ||
            (root && root.querySelector('.mst-searchautocomplete__autocomplete'));

        if (hasAutocomplete) {
            searchCompatReady = true;
            return;
        }
        if (!req || !ctx || !ctx.form || searchCompatReady || searchCompatLoading) {
            return;
        }
        searchCompatLoading = true;
        req(['js/awa-search-autocomplete-compat'], function (initCompat) {
            var fresh = getSearchContext();
            searchCompatLoading = false;
            searchCompatReady = true;
            if (fresh && fresh.form && typeof initCompat === 'function') {
                initCompat({}, fresh.form);
                fresh.form.setAttribute('data-awa-search-compat-bound', 'true');
                scheduleSearchSync();
            }
        }, function () {
            searchCompatLoading = false;
        });
    }

    function getSearchOptions(ctx) {
        var root = ctx ? (ctx.resultsRoot || ctx.panel) : null;
        var nodes;
        var i;
        var out = [];
        var item;
        var action;
        var dup;

        if (!root) {
            return out;
        }
        nodes = root.querySelectorAll('.suggest ul li, .product ul li, [role="option"], li');
        for (i = 0; i < nodes.length; i += 1) {
            item = nodes[i];
            if (!item) {
                continue;
            }
            if (!item.matches || !item.matches('[role="option"], li')) {
                item = item.closest('[role="option"], li');
            }
            if (!item || item.hasAttribute('hidden') || item.getAttribute('aria-hidden') === 'true') {
                continue;
            }
            dup = false;
            out.forEach(function (row) {
                if (row.option === item) {
                    dup = true;
                }
            });
            if (dup) {
                continue;
            }
            item.setAttribute('role', 'option');
            if (!item.id) {
                searchOptionSeq += 1;
                item.id = 'awa-search-option-' + searchOptionSeq;
            }
            action = item.matches && item.matches('a[href], button, [tabindex]') ?
                item : item.querySelector('a[href], button, [tabindex]');
            out.push({ option: item, action: action || item });
        }
        return out;
    }

    function clearSearchActive(ctx, options) {
        var i;
        if (!ctx) {
            return;
        }
        (options || []).forEach(function (row) {
            row.option.classList.remove('selected', 'awa-option-active');
            row.option.setAttribute('aria-selected', 'false');
        });
        if (ctx.input) {
            ctx.input.removeAttribute('aria-activedescendant');
        }
        ctx.form.setAttribute('data-awa-active-index', '-1');
    }

    function setSearchActive(ctx, options, index, announce) {
        var row;
        var region;
        var label;
        var i;

        if (!ctx || !options.length) {
            clearSearchActive(ctx, options);
            return;
        }
        if (index >= options.length) {
            index = 0;
        }
        if (index < 0) {
            index = options.length - 1;
        }
        clearSearchActive(ctx, options);
        row = options[index];
        row.option.classList.add('selected', 'awa-option-active');
        row.option.setAttribute('aria-selected', 'true');
        ctx.form.setAttribute('data-awa-active-index', String(index));
        if (ctx.input) {
            ctx.input.setAttribute('aria-activedescendant', row.option.id);
        }
        if (row.option.scrollIntoView) {
            row.option.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
        if (announce) {
            region = ensureSearchLiveRegion(ctx);
            if (region) {
                label = (row.option.textContent || '').replace(/\s+/g, ' ').trim();
                region.textContent = 'Sugestão ' + (index + 1) + ' de ' + options.length + ': ' + label;
            }
        }
    }

    function ensureSearchLiveRegion(ctx) {
        var region;
        var ids;
        if (!ctx || !ctx.scope) {
            return null;
        }
        region = ctx.scope.querySelector('#awa-search-live-region');
        if (!region) {
            region = document.createElement('div');
            region.id = 'awa-search-live-region';
            region.className = 'awa-sr-only';
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'true');
            region.setAttribute('data-awa-search-live', 'true');
            region.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;';
            ctx.scope.appendChild(region);
        }
        if (ctx.input) {
            ids = (ctx.input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
            if (ids.indexOf(region.id) === -1) {
                ids.push(region.id);
                ctx.input.setAttribute('aria-describedby', ids.join(' '));
            }
        }
        return region;
    }

    function setLiveRegionText(region, text) {
        if (!region) {
            return;
        }
        var nextText = text || '';
        if (region.textContent !== nextText) {
            region.textContent = nextText;
        }
    }

    function isIgnorableA11yLiveMutation(mutation) {
        var target = mutation && mutation.target ? mutation.target : null;
        if (!target || mutation.type !== 'childList') {
            return false;
        }
        if (target.id === 'awa-search-live-region') {
            return true;
        }
        if (target.getAttribute && target.getAttribute('data-awa-search-status') === 'true') {
            return true;
        }
        return !!(target.classList && target.classList.contains('awa-sr-only'));
    }

    var OVERLAY_MUTATION_TARGET_SEL =
        '.minicart-wrapper, .block-minicart, ' +
        '.mst-searchautocomplete__autocomplete, .searchsuite-autocomplete, #search_autocomplete, ' +
        '.awa-header-categories, [data-role="awa-vertical-menu-panel"], .awa-header-minicart';

    var minicartPanelLayoutKey = '';

    function isMinicartOverlayActive() {
        return !!(document.body && document.body.classList.contains('awa-minicart-overlay-active'));
    }

    function isRelevantHeaderOverlayMutation(mutation) {
        var target = mutation && mutation.target ? mutation.target : null;

        if (isIgnorableA11yLiveMutation(mutation)) {
            return false;
        }
        if (!target || !target.closest) {
            return false;
        }
        // BUG-a1f9f3 v11: com minicart aberto, compat da busca ainda toggla
        // has-results/is-open → observer → closeSearchOverlay → loop (~46 sync/s).
        if (isMinicartOverlayActive() && target.closest(
            '.block-search, .mst-searchautocomplete__autocomplete, .searchsuite-autocomplete, #search_autocomplete'
        )) {
            return false;
        }
        // BUG-a1f9f3 v6: attributeFilter=class no header inteiro (subtree) reagia a
        // is-empty/is-ready no form de busca e a toggles sticky no .awa-site-header,
        // realimentando scheduleOverlaySync() em ~10 sync/s no dashboard B2B.
        if (mutation.type === 'attributes') {
            if (mutation.attributeName !== 'class' && mutation.attributeName !== 'aria-expanded') {
                return false;
            }
            if (target.matches && target.matches('.awa-site-header')) {
                return false;
            }
            return !!target.closest(OVERLAY_MUTATION_TARGET_SEL);
        }
        if (mutation.type === 'childList') {
            return !!target.closest(OVERLAY_MUTATION_TARGET_SEL);
        }
        return false;
    }

    function syncSearchPanelState() {
        var ctx = getSearchContext();
        var panelOpen;
        var options;
        var hasResults;
        var text;
        var activeIndex;
        var region;
        var query;

        if (!ctx) {
            return;
        }
        if (isMinicartOverlayActive()) {
            return;
        }

        ctx.form.classList.add('is-ready');
        ensureSearchLiveRegion(ctx);
        query = ctx.input ? (ctx.input.value || '') : '';
        if (query !== (ctx.form.getAttribute('data-awa-last-query') || '')) {
            ctx.form.setAttribute('data-awa-last-query', query);
            ctx.form.removeAttribute('data-awa-panel-closed');
            if (ctx.panel) {
                ctx.panel.style.removeProperty('display');
            }
        }

        /* INP: não varrer todos os <li> + getComputedStyle a cada tecla.
           Contagem leve via querySelector; getSearchOptions só se painel aberto. */
        panelOpen = !!(ctx.panel && (
            ctx.panel.classList.contains('_active') ||
            ctx.panel.classList.contains('is-open') ||
            ctx.panel.getAttribute('aria-hidden') === 'false' ||
            (ctx.form.contains(document.activeElement) && ctx.panel.children.length > 0)
        ));

        if (ctx.resultsRoot || ctx.panel) {
            hasResults = !!(
                (ctx.panel && ctx.panel.querySelector('li, .mst-searchautocomplete__item')) ||
                (ctx.resultsRoot && ctx.resultsRoot.querySelector('li'))
            );
            text = '';
        } else {
            hasResults = false;
            text = '';
        }

        options = panelOpen && hasResults ? getSearchOptions(ctx) : [];

        if (ctx.form.getAttribute('data-awa-panel-closed') === 'true') {
            panelOpen = false;
            if (ctx.panel) {
                setStyleImportant(ctx.panel, 'display', 'none');
            }
        }

        ctx.form.classList.toggle('is-open', panelOpen);
        ctx.form.classList.toggle('has-results', hasResults);
        ctx.form.classList.toggle('is-empty', !hasResults);
        if (ctx.input) {
            ctx.input.setAttribute('aria-expanded', panelOpen ? 'true' : 'false');
            ctx.input.setAttribute('aria-haspopup', 'listbox');
        }
        if (ctx.panel) {
            // ARIA (aria-hidden/hidden) do painel: awa-header-a11y-performance +
            // awa-search-autocomplete-compat. Escrever aqui gerava ping-pong H14.
            ctx.panel.classList.toggle('is-open', panelOpen);
            ctx.panel.classList.toggle('has-results', hasResults);
            if (panelOpen) {
                ctx.panel.removeAttribute('hidden');
                ctx.panel.style.removeProperty('display');
                ctx.panel.style.removeProperty('visibility');
                ctx.panel.style.removeProperty('opacity');
                if (document.body) {
                    document.body.classList.add('searchautocomplete__active');
                }
            } else if (document.body) {
                // BUG-a1f9f3: remover sempre que o painel fecha — antes só removia
                // quando !hasResults, deixando a classe presa e mantendo listObs
                // chamando closeVerticalMenuOverlay() em loop (~11/s no dashboard).
                document.body.classList.remove('searchautocomplete__active');
            }
        }

        activeIndex = parseInt(ctx.form.getAttribute('data-awa-active-index') || '-1', 10);
        if (panelOpen && hasResults) {
            if (isNaN(activeIndex) || activeIndex < 0 || activeIndex >= options.length) {
                clearSearchActive(ctx, options);
            }
        } else {
            clearSearchActive(ctx, options);
        }

        region = ensureSearchLiveRegion(ctx);
        if (region) {
            if (panelOpen && hasResults) {
                setLiveRegionText(region, options.length + ' sugestões disponíveis. Use seta para baixo e cima para navegar.');
            } else if (panelOpen && !hasResults && query.length >= 2) {
                setLiveRegionText(region, 'Nenhuma sugestão encontrada.');
            } else {
                setLiveRegionText(region, '');
            }
        }
    }

    function fixSearchFormAction() {
        var ctx = getSearchContext();
        var action;
        var url;
        var fixed;
        if (!ctx || !ctx.form || typeof window.URL !== 'function') {
            return;
        }
        action = ctx.form.getAttribute('action') || ctx.form.action || '';
        try {
            url = new window.URL(action, window.location.origin);
        } catch (e) {
            return;
        }
        if (/\/catalogsearch\/result\/?/i.test(url.pathname) && url.origin !== window.location.origin) {
            fixed = window.location.origin + url.pathname;
            if (ctx.form.getAttribute('action') !== fixed) {
                ctx.form.setAttribute('action', fixed);
            }
        }
    }

    function syncStickyCondensed() {
        var wrap = stickyWrapper();
        var body = bodyEl();
        var condensed;
        if (!body || !wrap || (!wrap.classList.contains('enabled-header-sticky') && !wrap.classList.contains('enable-sticky'))) {
            return;
        }
        condensed = window.scrollY >= 48;
        wrap.classList.toggle('awa-header-condensed', condensed);
        body.classList.toggle('awa-header-condensed', condensed);

    }

    function syncPlpToolbarOffset() {
        var html = document.documentElement;
        var body = bodyEl();
        var toolbar;
        var mobile;
        var selectors;
        var nodes;
        var maxBottom;
        var offset;
        var i;
        var j;
        var el;
        var style;
        var rect;

        if (!html || !body) {
            return;
        }
        mobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
        toolbar = document.querySelector('.shop-tab-select .toolbar.toolbar-products');
        if (!body || !isCategoryPage() || !mobile || !toolbar) {
            html.style.removeProperty('--awa-plp-toolbar-top-offset');
            return;
        }
        selectors = [
            '.header-wrapper-sticky',
            '.page-wrapper .header-wrapper-sticky',
            '.page-wrapper .top-header',
            '.page-wrapper .header-control',
            '.page-wrapper .header .header_main',
            '.page-wrapper .header .header-main'
        ];
        maxBottom = 0;
        selectors.forEach(function (sel) {
            nodes = document.querySelectorAll(sel);
            for (i = 0; i < nodes.length; i += 1) {
                el = nodes[i];
                if (!isVisible(el)) {
                    continue;
                }
                style = window.getComputedStyle ? window.getComputedStyle(el) : null;
                if (!style || (style.position !== 'fixed' && style.position !== 'sticky')) {
                    continue;
                }
                rect = el.getBoundingClientRect();
                if (!rect || rect.height < 20 || rect.bottom <= 0) {
                    continue;
                }
                if (style.position === 'sticky' && rect.top > 2) {
                    continue;
                }
                if (rect.bottom > maxBottom) {
                    maxBottom = rect.bottom;
                }
            }
        });
        offset = Math.round(Math.min(220, Math.max(6, maxBottom + 8)));
        html.style.setProperty('--awa-plp-toolbar-top-offset', offset + 'px');
    }

    /**
     * Define --awa-header-desktop-bottom para posicionar corretamente o
     * dropdown do minicart no desktop (evita gap quando promo bar está fechada).
     */
    function syncHeaderDesktopBottom() {
        var html = document.documentElement;
        var sticky = document.querySelector('.header-wrapper-sticky');
        var promo = document.querySelector('.awa-b2b-promo-bar');
        var navBar = document.querySelector('.header-control.awa-nav-bar');
        var bottom = 0;
        var rect;
        var promoH;
        var navH;
        var mainH;

        if (!html) {
            return;
        }

        // Desktop only (≥992px)
        if (window.matchMedia && window.matchMedia('(max-width: 991px)').matches) {
            html.style.removeProperty('--awa-header-desktop-bottom');
            return;
        }

        // Calcula a altura total do header: promo + main + nav
        if (sticky) {
            rect = sticky.getBoundingClientRect();
            if (rect && rect.height > 20) {
                bottom = rect.bottom;
            }
        }

        // Fallback se sticky não estiver visível ainda
        if (bottom <= 0) {
            promoH = 0;
            navH = 0;
            mainH = 68;
            if (promo && isVisible(promo)) {
                promoH = promo.getBoundingClientRect().height || 40;
            }
            if (navBar && isVisible(navBar)) {
                navH = navBar.getBoundingClientRect().height || 52;
            }
            bottom = promoH + mainH + navH;
        }

        html.style.setProperty('--awa-header-desktop-bottom', Math.round(bottom) + 'px');
    }

    function classifyTopLinks() {
        var links = document.querySelectorAll('.top-account ul.header.links > li > a');
        var i;
        var anchor;
        var li;
        var href;
        var text;
        links.forEach(function (anchorEl) {
            anchor = anchorEl;
            li = anchor ? anchor.closest('li') : null;
            if (!anchor || !li) {
                return;
            }
            href = (anchor.getAttribute('href') || '').toLowerCase();
            text = (anchor.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            li.classList.add('awa-top-link-item');
            anchor.classList.add('awa-top-link-anchor');
            li.classList.remove('awa-top-link-item--compare', 'awa-top-link-item--b2b-register', 'awa-top-link-item--login', 'awa-top-link-item--logout', 'awa-top-link-item--account');
            if (li.classList.contains('compare') || /comparar/.test(text) || /product_compare/.test(href)) {
                li.classList.add('awa-top-link-item--compare');
            } else if (/cadastro b2b|cadastre-se|cadastrar-se|cadastro/.test(text) || /\/b2b\/register|\/customer\/account\/create|\/register/.test(href)) {
                li.classList.add('awa-top-link-item--b2b-register');
            } else if (/entrar|acessar|login/.test(text) || /\/login/.test(href)) {
                li.classList.add('awa-top-link-item--login');
            } else if (/sair|logout/.test(text) || /\/logout/.test(href)) {
                li.classList.add('awa-top-link-item--logout');
            } else if (/minha conta/.test(text) || /customer\/account/.test(href)) {
                li.classList.add('awa-top-link-item--account');
            }
            if (!anchor.getAttribute('title')) {
                anchor.setAttribute('title', (anchor.textContent || '').replace(/\s+/g, ' ').trim());
            }
        });
    }

    function syncTopLinkCounters() {
        document.querySelectorAll('.top-account ul.header.links > li').forEach(function (li) {
            var counter = li.querySelector('.counter.qty');
            var raw;
            var qty;
            var hasValue;
            if (!counter) {
                li.classList.remove('awa-top-link-counter-zero', 'awa-top-link-counter-has-value');
                return;
            }
            raw = (counter.textContent || '').replace(/[^\d]/g, '');
            qty = raw ? Number(raw) : 0;
            hasValue = Number.isFinite(qty) && qty > 0;
            li.classList.toggle('awa-top-link-counter-has-value', hasValue);
            li.classList.toggle('awa-top-link-counter-zero', !hasValue);
            counter.setAttribute('aria-hidden', hasValue ? 'false' : 'true');
        });
    }

    function syncMinicartA11y() {
        var ctx = getSearchContext();
        var btn = ctx ? ctx.button : null;
        var input = ctx ? ctx.input : null;
        var panel = ctx ? ctx.panel : null;
        var showcart = document.querySelector('.header .top-search .minicart-wrapper .action.showcart, .awa-site-header .minicart-wrapper .action.showcart');
        var wrap = document.querySelector('.header .top-search .minicart-wrapper, .awa-site-header .minicart-wrapper');
        var restricted = !!(bodyEl() && bodyEl().classList.contains('b2b-restricted-mode'));
        var restrictedLabel = 'Abrir carrinho - resumo comercial protegido';
        var defaultLabel = 'Abrir carrinho';

        if (btn && !btn.getAttribute('aria-label')) {
            btn.setAttribute('aria-label', btn.getAttribute('title') || 'Buscar');
        }
        if (btn && !btn.getAttribute('title')) {
            btn.setAttribute('title', btn.getAttribute('aria-label') || 'Buscar');
        }
        if (input && !input.getAttribute('aria-label') && !input.getAttribute('aria-labelledby')) {
            input.setAttribute('aria-label', 'Buscar produtos');
        }
        if (input && panel && !input.getAttribute('aria-controls')) {
            input.setAttribute('aria-controls', panel.id || 'search_autocomplete');
        }
        if (wrap) {
            wrap.classList.toggle('awa-b2b-minicart-restricted', restricted);
        }
        if (showcart) {
            showcart.setAttribute('title', restricted ? restrictedLabel : defaultLabel);
            showcart.setAttribute('aria-label', restricted ? restrictedLabel : defaultLabel);
            showcart.setAttribute('data-b2b-cart-state', restricted ? 'restricted' : 'default');
        }
    }

    function closeVerticalMenuOverlay() {
        var deptOpen = isMenuV2DeptOpen();
        if (deptOpen) {
            return;
        }
        // BUG-a1f9f3: com menu v2, awa-menu-controller.js é dono do painel
        // ul.togge-menu — manipular aria-hidden/classe aqui brigava com syncAria().
        if (!window.__AWA_MENU_V2) {
            document.querySelectorAll('.awa-header-categories.menu_left_home1').forEach(function (nav) {
                var title = nav.querySelector('[data-role="awa-vertical-menu-trigger"], .title-category-dropdown');
                var list = nav.querySelector('ul.togge-menu.list-category-dropdown');
                nav.classList.remove('menu-open', 'vmm-open', 'active', 'open');
                if (title) {
                    title.classList.remove('active', 'open');
                    title.setAttribute('aria-expanded', 'false');
                }
                if (list) {
                    list.classList.remove('menu-open', 'vmm-open', 'vmm-animate-in', 'open', 'active');
                    if (list.getAttribute('aria-hidden') !== 'true') {
                        list.setAttribute('aria-hidden', 'true');
                    }
                    setOverlayStyleImportant(list, 'display', 'none');
                    list.style.removeProperty('visibility');
                    list.style.removeProperty('opacity');
                    list.style.removeProperty('pointer-events');
                }
            });
        }
        document.querySelectorAll('body > .awa-vmf-portal, body > .level0.submenu, body > .navigation__submenu').forEach(function (panel) {
            panel.classList.remove('menu-open', 'vmm-open', 'vmm-animate-in', 'open', 'active');
            if (panel.getAttribute('aria-hidden') !== 'true') {
                panel.setAttribute('aria-hidden', 'true');
            }
            setOverlayStyleImportant(panel, 'display', 'none');
            setOverlayStyleImportant(panel, 'visibility', 'hidden');
            setOverlayStyleImportant(panel, 'opacity', '0');
            setOverlayStyleImportant(panel, 'pointer-events', 'none');
        });
    }

    function closeSearchOverlay() {
        var ctx = getSearchContext();
        var panels = document.querySelectorAll('#search_autocomplete, .mst-searchautocomplete__autocomplete, .searchsuite-autocomplete');
        var needsClose = isSearchOverlayOpen();
        var i;
        var panel;

        if (!needsClose) {
            for (i = 0; i < panels.length; i += 1) {
                panel = panels[i];
                if (panel.classList.contains('is-open') || panel.classList.contains('has-results') ||
                    panel.classList.contains('_active') || panel.classList.contains('active')) {
                    needsClose = true;
                    break;
                }
            }
        }
        if (!needsClose && !(ctx && ctx.form && ctx.form.classList.contains('is-open'))) {
            return;
        }
        if (ctx && ctx.form) {
            ctx.form.setAttribute('data-awa-panel-closed', 'true');
            ctx.form.classList.remove('is-open', 'has-results');
        }
        if (ctx && ctx.input) {
            ctx.input.setAttribute('aria-expanded', 'false');
        }
        panels.forEach(function (panelEl) {
            panelEl.classList.remove('is-open', 'has-results', '_active', 'active');
            if (panelEl.getAttribute('aria-hidden') !== 'true') {
                panelEl.setAttribute('aria-hidden', 'true');
            }
            panelEl.style.setProperty('display', 'none', 'important');
            panelEl.style.setProperty('visibility', 'hidden', 'important');
            panelEl.style.setProperty('opacity', '0', 'important');
            panelEl.style.setProperty('pointer-events', 'none', 'important');
        });
        if (document.body) {
            document.body.classList.remove('searchautocomplete__active');
        }
    }

    function closeMinicartOverlay() {
        var wraps = document.querySelectorAll('.awa-site-header .minicart-wrapper.active, .awa-site-header .minicart-wrapper.show, .awa-site-header .minicart-wrapper.is-open');
        var bodyHasOverlay = !!(document.body && document.body.classList.contains('awa-minicart-overlay-active'));

        if (!wraps.length && !bodyHasOverlay) {
            return;
        }

        wraps.forEach(function (wrap) {
            var panel = wrap.querySelector('.block-minicart');
            var close = wrap.querySelector('.block-minicart .action.close, .block-minicart .close');
            var showcart = wrap.querySelector('.showcart, .action.showcart');
            if (close && typeof close.click === 'function') {
                close.click();
            }
            wrap.classList.remove('active', 'show', 'is-open');
            if (showcart) {
                showcart.classList.remove('is-open', 'active');
                showcart.setAttribute('aria-expanded', 'false');
            }
            if (panel) {
                panel.classList.remove('_active', 'active', 'is-open');
                // BUG-a1f9f3 v10: display:none !important inline impedia reabrir o dropdown
                // (inline vence o CSS terminal display:flex !important).
                panel.style.removeProperty('display');
                panel.style.removeProperty('visibility');
                panel.style.removeProperty('pointer-events');
                panel.style.removeProperty('opacity');
            }
        });
        if (document.body) {
            document.body.classList.remove('awa-minicart-overlay-active');
        }
    }

    function closeVerticalMenuOverlayFrames(framesLeft) {
        closeVerticalMenuOverlay();
        if (framesLeft <= 0) {
            return;
        }
        window.requestAnimationFrame(function () {
            closeVerticalMenuOverlayFrames(framesLeft - 1);
        });
    }

    function isOverlayVisible(el) {
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
        inlineDisplay = el.style ? el.style.getPropertyValue('display') : '';
        inlineVisibility = el.style ? el.style.getPropertyValue('visibility') : '';
        if (inlineDisplay === 'none' || inlineVisibility === 'hidden') {
            return false;
        }
        return true;
    }

    function getOpenMinicartPanel() {
        var wraps = document.querySelectorAll(
            '.awa-site-header .minicart-wrapper.active, ' +
            '.awa-site-header .minicart-wrapper.show, ' +
            '.awa-site-header .minicart-wrapper.is-open'
        );
        var i;
        var panel;

        for (i = 0; i < wraps.length; i += 1) {
            panel = wraps[i].querySelector('.block-minicart');
            if (panel && isOverlayVisible(panel)) {
                return panel;
            }
        }

        return null;
    }

    function isSearchOverlayOpen() {
        var panel = getOpenSearchPanel();
        var form;
        var ctx;

        if (!panel || !isOverlayVisible(panel)) {
            return false;
        }
        if (document.body && document.body.classList.contains('searchautocomplete__active')) {
            return true;
        }
        ctx = getSearchContext();
        form = ctx && ctx.form ? ctx.form : document.querySelector('#search_mini_form, form.minisearch');
        return !!(form && form.classList.contains('is-open') && form.contains(document.activeElement));
    }

    function isVerticalMenuListOpen(list) {
        if (!list) {
            return false;
        }
        // BUG-a1f9f3: NÃO usar isOverlayVisible(list) — o <ul> do menu vertical
        // sempre tem dimensões no header e isso fazia o observer tratá-lo como
        // permanentemente aberto, gerando ~11 sync/s em idle no dashboard.
        if (list.getAttribute('data-awa-menu-state') === 'open') {
            return true;
        }
        return list.classList.contains('menu-open') || list.classList.contains('vmm-open');
    }

    function cleanupStaleOverlayState() {
        if (!document.body) {
            return;
        }
        if (!isSearchOverlayOpen()) {
            document.body.classList.remove('searchautocomplete__active');
        }
        if (!isOverlayVisible(getOpenMinicartPanel())) {
            document.body.classList.remove('awa-minicart-overlay-active');
        }
    }

    function getOpenSearchPanel() {
        return document.querySelector('#search_autocomplete.is-open, #search_autocomplete[aria-hidden="false"], .mst-searchautocomplete__autocomplete._active, .mst-searchautocomplete__autocomplete.is-open, .searchsuite-autocomplete.is-open');
    }

    function getOpenVerticalMenu() {
        if (isMenuV2DeptOpen()) {
            return document.querySelector('[data-role="awa-vertical-menu-panel"]');
        }
        return document.querySelector(
            '.awa-header-categories.menu_left_home1 ul.togge-menu.menu-open, ' +
            '.awa-header-categories.menu_left_home1 ul.togge-menu.vmm-open, ' +
            '[data-role="awa-vertical-menu-panel"][data-awa-menu-state="open"], ' +
            'body > .awa-vmf-portal, body > .level0.submenu, body > .navigation__submenu'
        );
    }

    function positionOpenMinicartPanel() {
        var panel = getOpenMinicartPanel();
        var wrap = panel ? panel.closest('.minicart-wrapper') : null;
        var header = document.querySelector('.awa-site-header');
        var isDesktop = !!(window.matchMedia && window.matchMedia('(min-width: 992px)').matches);
        var nextLayoutKey;
        var prompt = document.querySelector('.awa-header-account-prompt');
        var promptRect = prompt ? prompt.getBoundingClientRect() : null;
        var panelRect = panel ? panel.getBoundingClientRect() : null;
        var overlapPx = (promptRect && panelRect)
            ? Math.max(0, Math.round(promptRect.bottom - panelRect.top))
            : -1;

        // AWA Fix: skip fixed positioning in auth shells to avoid conflict with absolute positioning
        if (typeof isAuthShell === 'function' && isAuthShell()) {
            return;
        }

        if (!panel || !isDesktop) {
            return;
        }
        if (!wrap || !(wrap.classList.contains('active') || wrap.classList.contains('show') || wrap.classList.contains('is-open'))) {
            return;
        }

        nextLayoutKey = [
            Math.round(window.innerWidth),
            Math.round(header ? header.getBoundingClientRect().bottom : 0)
        ].join('|');
        if (nextLayoutKey === minicartPanelLayoutKey) {
            return;
        }
        minicartPanelLayoutKey = nextLayoutKey;

        require(['js/awa-minicart-position'], function (minicartPosition) {
            if (minicartPosition && typeof minicartPosition.centerOpenMinicartPanel === 'function') {
                minicartPosition.centerOpenMinicartPanel(panel);
            }
        });
    }

    function syncHeaderOverlayExclusivity() {
        var mini = getOpenMinicartPanel();
        var search = getOpenSearchPanel();
        var menu = getOpenVerticalMenu();

        if (overlaySyncRunning) {
            return;
        }
        overlaySyncRunning = true;
        try {
            if (isOverlayVisible(mini)) {
                if (document.body) {
                    document.body.classList.add('awa-minicart-overlay-active');
                }
                if (!document.body.getAttribute('data-awa-search-closed-for-minicart')) {
                    closeSearchOverlay();
                    document.body.setAttribute('data-awa-search-closed-for-minicart', '1');
                }
                closeVerticalMenuOverlayFrames(4);
                positionOpenMinicartPanel();
                return;
            }
            if (document.body) {
                document.body.classList.remove('awa-minicart-overlay-active');
                document.body.removeAttribute('data-awa-search-closed-for-minicart');
                minicartPanelLayoutKey = '';
            }
            if (isSearchOverlayOpen()) {
                closeMinicartOverlay();
                if (!isMenuV2DeptOpen()) {
                    closeVerticalMenuOverlay();
                }
                return;
            }
            if (isOverlayVisible(menu)) {
                closeSearchOverlay();
                closeMinicartOverlay();
            }
        } finally {
            overlaySyncRunning = false;
        }
    }

    function suppressVerticalMenuWhenMinicartActive(evt) {
        var target = evt && evt.target ? evt.target : null;
        if (!target || !document.body || !document.body.classList.contains('awa-minicart-overlay-active')) {
            return;
        }
        if (!target.closest('.awa-header-categories.menu_left_home1, [data-role="awa-vertical-menu-trigger"], .title-category-dropdown')) {
            return;
        }
        evt.preventDefault();
        evt.stopPropagation();
        evt.stopImmediatePropagation();
        closeVerticalMenuOverlay();
    }

    function scheduleOverlaySync() {
        var elapsed;
        if (overlaySyncScheduled) {
            return;
        }
        elapsed = Date.now() - overlaySyncLastAt;
        overlaySyncScheduled = true;

        function run() {
            overlaySyncScheduled = false;
            overlaySyncLastAt = Date.now();
            syncHeaderOverlayExclusivity();
        }

        if (elapsed < 100) {
            window.setTimeout(run, 100 - elapsed);
            return;
        }

        window.requestAnimationFrame(run);
    }

    function buildLayoutFingerprint() {
        var ctx = getSearchContext();
        var mobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
        var desktop = window.matchMedia && window.matchMedia('(min-width: 992px)').matches;
        var body = bodyEl();
        return [
            ctx && ctx.form ? 1 : 0,
            ctx && ctx.input ? 1 : 0,
            ctx && ctx.actions ? 1 : 0,
            ctx && ctx.button ? 1 : 0,
            mobile ? 1 : 0,
            desktop ? 1 : 0,
            isHomePage() ? 1 : 0,
            isCategoryPage() ? 1 : 0,
            body && body.classList.contains('awa-plp-filters-collapsed') ? 1 : 0,
            document.querySelectorAll(HEADER_ROW_SEL).length,
            document.querySelectorAll('.top-account ul.header.links > a').length,
            hasModernHeader() ? 1 : 0
        ].join('|');
    }

    function applyLegacyMobileGrid() {
        /* Layout mobile moderno vem do CSS (awa-ui-ux-pro-max-header); não sobrescrever com JS. */
        if (hasModernHeader()) {
            return;
        }
        var mobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
        var rows = document.querySelectorAll(HEADER_ROW_SEL);
        var t;
        var row;
        var brandCol;
        var searchCol;
        var block;
        var cartWrap;
        var minicart;
        var showcart;
        var logo;
        var logoLink;
        var logoImg;

        if (isHomePage() || !mobile || !rows.length) {
            return;
        }

        for (t = 0; t < rows.length; t += 1) {
            row = rows[t];
            row.setAttribute('data-awa-js-layout', 'js');
            brandCol = row.querySelector(':scope > [class*="col-"]:first-child');
            searchCol = row.querySelector(':scope > .top-search');
            block = searchCol ? searchCol.querySelector(':scope > .block-search') : null;
            cartWrap = searchCol ? searchCol.querySelector(':scope > .mini-cart-wrapper') : null;
            minicart = cartWrap ? cartWrap.querySelector(':scope > .mini-carts, .minicart-wrapper') : null;
            showcart = cartWrap ? cartWrap.querySelector('.showcart, .action.showcart') : null;
            logo = (brandCol ? brandCol.querySelector('.logo') : null) || row.querySelector('.logo');
            logoLink = logo ? logo.querySelector('a') : null;
            logoImg = logo ? logo.querySelector('img') : null;

            setStyleImportant(row, 'display', 'grid');
            setStyleImportant(row, 'grid-template-columns', 'clamp(82px, 24vw, 108px) minmax(0, 1fr)');
            setStyleImportant(row, 'align-items', 'center');
            setStyleImportant(row, 'gap', '8px');
            setStyleImportant(row, 'width', '100%');
            setStyleImportant(row, 'max-width', '100%');
            setStyleImportant(row, 'min-width', '0');

            if (searchCol) {
                /* H-search-input-width (2026-08-02): cart vive em .awa-header-right-col (row brand),
                   não em .awa-header-search-col. Reservar 44px aqui cria coluna fantasma e
                   deixa #search em ~235px. Só use 1fr+44px se o cartWrap for filho real. */
                setStyleImportant(searchCol, 'display', 'grid');
                if (cartWrap) {
                    setStyleImportant(searchCol, 'grid-template-columns', 'minmax(0, 1fr) 44px');
                    setStyleImportant(searchCol, 'grid-template-areas', '"search cart"');
                    setStyleImportant(searchCol, 'gap', '8px');
                } else {
                    setStyleImportant(searchCol, 'grid-template-columns', 'minmax(0, 1fr)');
                    setStyleImportant(searchCol, 'grid-template-areas', '"search"');
                    setStyleImportant(searchCol, 'gap', '0');
                }
                setStyleImportant(searchCol, 'grid-template-rows', '44px');
                setStyleImportant(searchCol, 'align-items', 'center');
                setStyleImportant(searchCol, 'width', '100%');
                setStyleImportant(searchCol, 'min-width', '0');
                setStyleImportant(searchCol, 'min-height', '44px');
                setStyleImportant(searchCol, 'margin', '0');
                setStyleImportant(searchCol, 'position', 'relative');
            }
            if (block) {
                setStyleImportant(block, 'grid-area', 'search');
                setStyleImportant(block, 'width', '100%');
                setStyleImportant(block, 'min-width', '0');
            }
            if (cartWrap) {
                setStyleImportant(cartWrap, 'display', 'block');
                setStyleImportant(cartWrap, 'position', 'static');
                setStyleImportant(cartWrap, 'grid-area', 'cart');
                setStyleImportant(cartWrap, 'width', '44px');
                setStyleImportant(cartWrap, 'min-width', '44px');
                setStyleImportant(cartWrap, 'max-width', '44px');
                setStyleImportant(cartWrap, 'min-height', '44px');
            }
            if (minicart) {
                setStyleImportant(minicart, 'position', 'static');
                setStyleImportant(minicart, 'width', '44px');
                setStyleImportant(minicart, 'height', '44px');
                setStyleImportant(minicart, 'display', 'flex');
                setStyleImportant(minicart, 'align-items', 'center');
                setStyleImportant(minicart, 'justify-content', 'center');
            }
            if (showcart) {
                setStyleImportant(showcart, 'position', 'static');
                setStyleImportant(showcart, 'width', '44px');
                setStyleImportant(showcart, 'height', '44px');
                setStyleImportant(showcart, 'display', 'inline-flex');
                setStyleImportant(showcart, 'align-items', 'center');
                setStyleImportant(showcart, 'justify-content', 'center');
            }
            if (logo) {
                setStyleImportant(logo, 'display', 'flex');
                setStyleImportant(logo, 'width', 'clamp(82px, 24vw, 108px)');
                setStyleImportant(logo, 'max-width', '108px');
                setStyleImportant(logo, 'overflow', 'visible');
            }
            if (logoImg) {
                setStyleImportant(logoImg, 'display', 'block');
                setStyleImportant(logoImg, 'width', '100%');
                setStyleImportant(logoImg, 'max-height', '56px');
            }
        }
    }

    function hideLegacyNavToggleOnHome() {
        var toggle = document.querySelector('.header-control .action.nav-toggle, .header-control .nav-toggle');
        if (!toggle) {
            return;
        }
        if (isHomePage()) {
            toggle.style.removeProperty('display');
            toggle.style.removeProperty('visibility');
            toggle.style.removeProperty('pointer-events');
            toggle.removeAttribute('aria-hidden');
            if (toggle.getAttribute('tabindex') === '-1') {
                toggle.removeAttribute('tabindex');
            }
            return;
        }
        setStyleImportant(toggle, 'display', 'none');
        setStyleImportant(toggle, 'visibility', 'hidden');
        setStyleImportant(toggle, 'pointer-events', 'none');
        toggle.setAttribute('aria-hidden', 'true');
        toggle.setAttribute('tabindex', '-1');
    }

    function syncLayout() {
        var fp = buildLayoutFingerprint();
        if (fp === layoutFingerprint) {
            return;
        }
        layoutFingerprint = fp;
        fixSearchFormAction();
        syncPlpToolbarOffset();
        syncHeaderDesktopBottom();
        applyLegacyMobileGrid();
        hideLegacyNavToggleOnHome();
    }

    function runHeaderPass() {
        cleanupStaleOverlayState();
        syncStickyCondensed();
        fixSearchFormAction();
        syncPlpToolbarOffset();
        syncHeaderDesktopBottom();
        syncLayout();
        ensureSearchCompat();
        syncMinicartA11y();
        classifyTopLinks();
        syncTopLinkCounters();
        scheduleSearchSync();
    }

    function onDelegatedInteraction(evt) {
        var target = evt && evt.target ? evt.target : null;
        if (!target) {
            return;
        }
        if (target.closest('.toolbar .modes .modes-label[data-awa-filter-toggle="true"]')) {
            scheduleLayoutSync();
            return;
        }
        if (target.closest('[data-awa-search-root="true"],[data-awa-search-form="true"],[data-awa-search-input="true"],[data-awa-search-panel="true"],.header .top-search,.awa-site-header .block-search')) {
            closeVerticalMenuOverlay();
            closeMinicartOverlay();
            scheduleOverlaySync();
            if (evt.type === 'click') {
                scheduleLayoutSync();
            } else if (evt.type !== 'input') {
                /* input: Mirasvit + compat já sincronizam; sync aqui = INP ruim */
                scheduleSearchSync();
            }
            return;
        }
        if (target.closest('.awa-header-categories.menu_left_home1, [data-role="awa-vertical-menu-trigger"], .title-category-dropdown')) {
            closeSearchOverlay();
            closeMinicartOverlay();
            scheduleOverlaySync();
            scheduleLayoutSync();
            return;
        }
        if (target.closest('.header .mini-cart-wrapper,.header .mini-carts,.minicart-wrapper,.top-account ul.header.links,.awa-header-minicart')) {
            if (target.closest('.showcart,.action.showcart') && evt.type === 'click') {
                if (document.body) {
                    document.body.classList.add('awa-minicart-overlay-active');
                }
                closeSearchOverlay();
                closeVerticalMenuOverlayFrames(4);
                window.requestAnimationFrame(positionOpenMinicartPanel);
                scheduleOverlaySync();
            }
            if (target.closest('.block-minicart .action.close, .block-minicart .close')) {
                window.requestAnimationFrame(function () {
                    if (!getOpenMinicartPanel() && document.body) {
                        document.body.classList.remove('awa-minicart-overlay-active');
                    }
                });
            }
            scheduleLayoutSync();
        }
    }

    function onSearchKeydown(evt) {
        var ctx = getSearchContext();
        var key = evt.key;
        var options;
        var index;
        var row;
        if (!ctx || !ctx.input || evt.target !== ctx.input) {
            return;
        }
        options = getSearchOptions(ctx);
        index = parseInt(ctx.form.getAttribute('data-awa-active-index') || '-1', 10);
        if (key === 'Escape') {
            evt.preventDefault();
            clearSearchActive(ctx, options);
            ctx.form.setAttribute('data-awa-panel-closed', 'true');
            if (ctx.panel) {
                setStyleImportant(ctx.panel, 'display', 'none');
            }
            scheduleSearchSync();
            return;
        }
        if (!options.length) {
            return;
        }
        if (key === 'ArrowDown' || key === 'Down') {
            evt.preventDefault();
            setSearchActive(ctx, options, (isNaN(index) ? -1 : index) + 1, true);
            return;
        }
        if (key === 'ArrowUp' || key === 'Up') {
            evt.preventDefault();
            setSearchActive(ctx, options, (isNaN(index) ? 0 : index) - 1, true);
            return;
        }
        if (key === 'Enter' && index >= 0) {
            row = options[index];
            if (row && row.action && row.action.click) {
                evt.preventDefault();
                row.action.click();
            }
        }
    }

    function onSearchHover(evt) {
        var ctx = getSearchContext();
        var options;
        var i;
        var row;
        if (!ctx || !ctx.panel || !ctx.panel.contains(evt.target)) {
            return;
        }
        row = evt.target.closest('[role="option"], li');
        if (!row) {
            return;
        }
        options = getSearchOptions(ctx);
        for (i = 0; i < options.length; i += 1) {
            if (options[i].option === row) {
                setSearchActive(ctx, options, i, false);
                break;
            }
        }
    }

    function initObservers() {
        var sticky = stickyWrapper();
        var search = searchBlock();
        if (sticky && window.MutationObserver) {
            new MutationObserver(function () {
                schedule(function () {
                    syncStickyCondensed();
                    syncPlpToolbarOffset();
                    syncHeaderDesktopBottom();
                }, 'scroll', syncFlags);
            }).observe(sticky, { attributes: true, attributeFilter: ['class'] });
        }
        if (search && window.MutationObserver) {
            new MutationObserver(function (mutations) {
                var i;
                for (i = 0; i < mutations.length; i += 1) {
                    if (mutations[i].type === 'childList' && !isIgnorableA11yLiveMutation(mutations[i])) {
                        scheduleSearchSync();
                        return;
                    }
                }
            }).observe(search, { childList: true, subtree: true });
        }
        if (hasModernHeader() && window.MutationObserver && !window.__awaHeaderOverlayExclusivityObserver) {
            window.__awaHeaderOverlayExclusivityObserver = true;
            new MutationObserver(function (mutations) {
                var i;
                for (i = 0; i < mutations.length; i += 1) {
                    if (!isRelevantHeaderOverlayMutation(mutations[i])) {
                        continue;
                    }
                    if (mutations[i].type === 'attributes' || mutations[i].type === 'childList') {
                        scheduleOverlaySync();
                        return;
                    }
                }
            }).observe(document.querySelector('.awa-site-header'), {
                attributes: true,
                // BUG-a1f9f3: aria-hidden removido — mudanças ARIA em overlays
                // (menu/busca) realimentavam scheduleOverlaySync() em loop.
                attributeFilter: ['class'],
                childList: true,
                subtree: true
            });
            // Menu v2 (awa-menu-controller.js) gerencia seu próprio ARIA/estado;
            // o listObs legado brigava com syncAria() e gerava loop contínuo.
            if (!window.__AWA_MENU_V2) {
            document.querySelectorAll('.awa-header-categories.menu_left_home1 ul.togge-menu.list-category-dropdown').forEach(function (list) {
                new MutationObserver(function () {
                    if (!document.body) {
                        return;
                    }
                    // BUG-a1f9f3: não fechar menu já fechado — evita loop quando
                    // searchautocomplete__active ficava presa no body.
                    if (!isVerticalMenuListOpen(list)) {
                        return;
                    }
                    if (
                        !isMenuV2DeptOpen() &&
                        (
                            document.body.classList.contains('awa-minicart-overlay-active') ||
                            document.body.classList.contains('searchautocomplete__active') ||
                            isOverlayVisible(getOpenMinicartPanel()) ||
                            isOverlayVisible(getOpenSearchPanel())
                        )
                    ) {
                        window.requestAnimationFrame(function () {
                            closeVerticalMenuOverlay();
                        });
                    }
                }).observe(list, {
                    attributes: true,
                    attributeFilter: ['class']
                });
            });
            }
        }
    }

    function init() {
        runHeaderPass();
        window.addEventListener('scroll', function () {
            schedule(function () {
                syncStickyCondensed();
                syncPlpToolbarOffset();
                syncHeaderDesktopBottom();
            }, 'scroll', syncFlags);
        }, { passive: true });
        window.addEventListener('resize', function () {
            layoutFingerprint = '';
            scheduleLayoutSync();
            schedule(function () {
                runHeaderPass();
            }, 'resize', syncFlags);
        }, { passive: true });
        document.addEventListener('keydown', onSearchKeydown, true);
        document.addEventListener('mouseover', onSearchHover, true);
        document.addEventListener('focusin', onDelegatedInteraction, true);
        document.addEventListener('keyup', onDelegatedInteraction, true);
        document.addEventListener('mouseover', suppressVerticalMenuWhenMinicartActive, true);
        document.addEventListener('click', onDelegatedInteraction, true);
        document.addEventListener('input', onDelegatedInteraction, true);
        document.addEventListener('input', onMinicartQtyEvent, true);
        document.addEventListener('change', onMinicartQtyEvent, true);
        document.addEventListener('keyup', onMinicartQtyEvent, true);
        document.addEventListener('awa:qty-control:change', onMinicartQtyEvent, true);
        document.addEventListener('click', onMinicartOpenIntent, true);
        document.addEventListener('contentUpdated', function (evt) {
            syncMinicartUpdateButtons(evt.target);
        }, true);
        initMinicartUpdateButtonObserver();
        syncMinicartUpdateButtons(document);
        bindMinicartOpenSync();
        initObservers();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());

/* Auth / account shells — minicart visível sem polling agressivo */
(function () {
    'use strict';

    // AWA Fix: cada IIFE é um closure independente — isAuthShell() precisa
    // existir localmente aqui também (não é compartilhada com a IIFE acima).
    function isAuthShell() {
        var body = document.body;
        return !!body && (
            body.classList.contains('b2b-auth-shell') ||
            body.classList.contains('customer-account') ||
            body.classList.contains('customer-account-login') ||
            body.classList.contains('customer-account-create')
        );
    }

    function ensureShowcartVisible() {
        if (!isAuthShell()) {
            return;
        }
        document.querySelectorAll('.awa-site-header .minicart-wrapper .showcart, .awa-site-header .minicart-wrapper .action.showcart, .b2b-auth-shell .awa-site-header .minicart-wrapper .showcart').forEach(function (btn) {
            if (btn.getAttribute('data-awa-showcart-fixed') === '1') {
                return;
            }
            btn.setAttribute('data-awa-showcart-fixed', '1');
            btn.style.setProperty('opacity', '1', 'important');
            btn.style.setProperty('visibility', 'visible', 'important');
            btn.style.setProperty('display', 'inline-flex', 'important');
            btn.style.setProperty('pointer-events', 'auto', 'important');
        });
    }

    function fixOpenMinicartPanel() {
        var panel = document.querySelector('.minicart-wrapper .block-minicart._active, .minicart-wrapper.active .block-minicart, .minicart-wrapper.is-open .block-minicart');
        if (!panel) {
            return;
        }
        require(['js/awa-minicart-position'], function (minicartPosition) {
            if (minicartPosition && typeof minicartPosition.centerOpenMinicartPanel === 'function') {
                minicartPosition.centerOpenMinicartPanel(panel);
            }
        });
    }

    function onCartInteraction() {
        window.requestAnimationFrame(function () {
            ensureShowcartVisible();
            fixOpenMinicartPanel();
        });
    }

    function initAuthShell() {
        if (!isAuthShell()) {
            return;
        }
        ensureShowcartVisible();
        document.addEventListener('click', function (evt) {
            if (evt.target && evt.target.closest('.minicart-wrapper,.showcart,.action.showcart,.awa-header-minicart')) {
                onCartInteraction();
            }
        }, true);
        window.addEventListener('resize', ensureShowcartVisible, { passive: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAuthShell, { once: true });
    } else {
        initAuthShell();
    }
}());
