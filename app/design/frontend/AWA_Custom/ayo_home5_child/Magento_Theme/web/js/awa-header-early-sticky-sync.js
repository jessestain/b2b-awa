/** AWA early sticky sync — 1o paint antes de awa-header-sticky.js */
(function (w, d) {
    'use strict';
    if (w.__awaHeaderEarlyStickySync) { return; }
    w.__awaHeaderEarlyStickySync = true;

    var _header = null, _wrapper = null, _promoBar = null, _searchInput = null;
    var _rafPending = false;
    var _headerOk = false;
    var _mobileMq = w.matchMedia('(max-width: 767px)');

    function initRefs() {
        _header   = _header   || d.querySelector('.awa-site-header');
        _wrapper  = _wrapper  || d.querySelector('.header-wrapper-sticky');
        _promoBar = _promoBar || d.getElementById('awa-b2b-promo-bar');
        _searchInput = _searchInput || d.getElementById('search');
    }

    function isCondensedAllowed() {
        var body = d.body;
        return body
            && !body.classList.contains('awa-account-operational')
            && !body.classList.contains('b2b-account-shell');
    }

    function syncMobileSearchTabOrder(sticky) {
        if (!_searchInput) { return; }
        if (sticky && _mobileMq.matches) {
            _searchInput.setAttribute('tabindex', '-1');
        } else {
            _searchInput.removeAttribute('tabindex');
        }
    }

    /* PERF-2026-10-26 (INP/reflow): getBoundingClientRect por frame de scroll era o
       maior forced reflow deste script (applySync ~90ms no trace). A altura do promo
       bar so muda em resize/load ou quando o display inline muda — cache validado
       por esses eventos (leitura de style.display NAO dispara layout). */
    var _thrCache = null, _thrDisp = null;
    function invalidateThreshold() { _thrCache = null; }
    function getThreshold() {
        var bar = _promoBar;
        var disp = bar ? bar.style.display : null;
        if (_thrCache === null || disp !== _thrDisp) {
            var rect = bar && disp !== 'none' ? bar.getBoundingClientRect() : null;
            _thrCache = 60 + (rect && rect.height > 0 ? Math.round(rect.height) : 0);
            _thrDisp = disp;
        }
        return _thrCache;
    }

    function setVar(el, prop, value) {
        if (!el) { return; }
        if (value) {
            el.style.setProperty(prop, value, 'important');
        } else {
            el.style.removeProperty(prop);
        }
    }

    function apply(el, prop, value) {
        if (!el) { return; }
        if (value === null) {
            el.style.removeProperty(prop);
            return;
        }
        el.style.setProperty(prop, value, 'important');
    }

    /**
     * Enquanto awa-header-sticky (RequireJS) nao carrega, o early sync e o owner.
     * Precisa aplicar tokens/geometria 56px — senao a home fica em 68/72px.
     */
    function syncCondensedGeometry(isSticky) {
        var shell = _header && _header.querySelector('#header.header-container[data-awa-header-shell="true"]');
        var row = _header && _header.querySelector('.awa-main-header__inner.wp-header, .awa-main-header__inner[data-awa-header-row]');
        var searchCol = _header && _header.querySelector('.awa-header-search-col');
        var form = _header && _header.querySelector('#search_mini_form');
        var input = _header && _header.querySelector('#search');
        var value = isSticky ? '56px' : '';

        setVar(_header, '--awa-header-row-h', value);
        setVar(_header, '--awa-header-main-row-h', value);
        setVar(_header, '--awa-hdr-height-sticky', value);
        setVar(_wrapper, '--awa-header-row-h', value);
        setVar(_wrapper, '--awa-header-main-row-h', value);
        setVar(shell, '--awa-header-row-h', value);
        setVar(shell, '--awa-header-main-row-h', value);
        setVar(shell, '--awa-hdr-height-sticky', value);

        /* Wrap contém main+nav: NÃO forçar 56px no wrap (corta/estoura a nav).
           Lock só na row/search; wrap fica height:auto com stack ~104px. */
        if (!isSticky) {
            apply(row, 'height', null);
            apply(row, 'min-height', null);
            apply(row, 'max-height', null);
            apply(row, 'grid-template-areas', null);
            apply(row, 'grid-template-columns', null);
            apply(row, 'grid-template-rows', null);
            apply(row, 'grid-template', null);
            var primaryRowReset = _header && _header.querySelector('.awa-header-primary-row');
            apply(primaryRowReset, 'display', null);
            apply(primaryRowReset, 'grid-area', null);
            apply(searchCol, 'height', null);
            apply(searchCol, 'min-height', null);
            apply(searchCol, 'max-height', null);
            apply(searchCol, 'width', null);
            apply(searchCol, 'max-width', null);
            apply(searchCol, 'min-width', null);
            apply(searchCol, 'grid-column', null);
            apply(searchCol, 'grid-area', null);
            apply(_wrapper, 'height', null);
            apply(_wrapper, 'min-height', null);
            apply(_wrapper, 'max-height', null);
            apply(_wrapper, 'padding-block-start', null);
            apply(_wrapper, 'position', null);
            apply(_wrapper, 'top', null);
            apply(_wrapper, 'left', null);
            apply(_wrapper, 'right', null);
            apply(_wrapper, 'width', null);
            apply(_wrapper, 'z-index', null);
            var mainHeaderReset = _header && _header.querySelector('.header.awa-main-header, .header_main.awa-main-header-inner-wrap');
            apply(mainHeaderReset, 'height', null);
            apply(mainHeaderReset, 'min-height', null);
            apply(mainHeaderReset, 'max-height', null);
            apply(form, 'height', null);
            apply(form, 'min-height', null);
            apply(form, 'max-height', null);
            apply(input, 'height', null);
            apply(input, 'min-height', null);
            apply(input, 'max-height', null);
            apply(input, 'line-height', null);
            setVar(_header, '--awa-header-scroll-offset', '');
            setVar(document.documentElement, '--awa-header-scroll-offset', '');
            return;
        }

        apply(_wrapper, 'height', 'auto');
        apply(_wrapper, 'min-height', '0px');
        apply(_wrapper, 'max-height', 'none');
        apply(_wrapper, 'padding-block-start', '0px');
        /* H7: home overflow ancestor — pin wrap while early sync owns sticky. */
        apply(_wrapper, 'position', 'fixed');
        apply(_wrapper, 'top', '0');
        apply(_wrapper, 'left', '0');
        apply(_wrapper, 'right', '0');
        apply(_wrapper, 'width', '100%');
        apply(_wrapper, 'z-index', '5001');

        var isMobile = window.matchMedia('(max-width: 767px)').matches;
        if (isMobile) {
            apply(_wrapper, 'height', '56px');
            apply(_wrapper, 'min-height', '56px');
            apply(_wrapper, 'max-height', '56px');
            var primaryRow = _header && _header.querySelector('.awa-header-primary-row');
            apply(primaryRow, 'display', 'contents');
            apply(primaryRow, 'grid-area', 'unset');
            var mainHeader = _header && _header.querySelector('.header.awa-main-header, .header_main.awa-main-header-inner-wrap');
            apply(mainHeader, 'height', '56px');
            apply(mainHeader, 'min-height', '56px');
            apply(mainHeader, 'max-height', '56px');
            apply(row, 'height', '56px');
            apply(row, 'min-height', '56px');
            apply(row, 'max-height', '56px');
            apply(row, 'grid-template', '"toggle brand search cart" 44px / 44px minmax(0,1fr) 44px 44px');
            apply(searchCol, 'height', '44px');
            apply(searchCol, 'min-height', '44px');
            apply(searchCol, 'max-height', '44px');
            apply(searchCol, 'width', '44px');
            apply(searchCol, 'max-width', '44px');
            apply(searchCol, 'min-width', '44px');
            apply(searchCol, 'grid-column', 'auto');
            apply(searchCol, 'grid-area', 'search');
            apply(form, 'height', '44px');
            apply(form, 'min-height', '44px');
            apply(form, 'max-height', '44px');
            apply(input, 'height', null);
            apply(input, 'min-height', null);
            apply(input, 'max-height', null);
            apply(input, 'line-height', null);
            setVar(_header, '--awa-header-scroll-offset', '56px');
            setVar(document.documentElement, '--awa-header-scroll-offset', '56px');
            return;
        }

        apply(row, 'height', '56px');
        apply(row, 'min-height', '56px');
        apply(row, 'max-height', '56px');
        apply(searchCol, 'height', '56px');
        apply(searchCol, 'min-height', '56px');
        apply(searchCol, 'max-height', '56px');
        apply(form, 'height', '44px');
        apply(form, 'min-height', '44px');
        apply(form, 'max-height', '44px');
        apply(input, 'height', '44px');
        apply(input, 'min-height', '44px');
        apply(input, 'max-height', '44px');
        apply(input, 'line-height', '44px');
        setVar(_header, '--awa-header-scroll-offset', '104px');
        setVar(document.documentElement, '--awa-header-scroll-offset', '104px');
    }

    function kickStickyOwner() {
        if (w.__awaHeaderStickyInit || w.__awaStickyRequireKick) {
            return;
        }
        if (typeof w.require !== 'function') {
            return;
        }
        w.__awaStickyRequireKick = true;
        try {
            w.require(['awa-header-sticky'], function (initStickyHeader) {
                if (typeof initStickyHeader === 'function') {
                    initStickyHeader();
                }
            }, function () {
                w.__awaStickyRequireKick = false;
            });
        } catch (e) {
            w.__awaStickyRequireKick = false;
        }
    }

    function applySync() {
        _rafPending = false;
        /* Handoff: awa-header-sticky.js owns state (incl. footer guard).
           Sem isso o early sync re-aplica is-sticky a cada scroll e anula o pause no rodape. */
        if (w.__awaHeaderStickyInit) {
            return;
        }
        initRefs();
        if (!_header || !_wrapper) { return; }

        if (!isCondensedAllowed()) {
            _header.classList.remove('awa-header-condensed', 'awa-scroll-down');
            _wrapper.classList.remove('is-sticky', 'awa-header-condensed');
            if (d.body) { d.body.classList.remove('awa-header-is-sticky'); }
            syncCondensedGeometry(false);
            syncMobileSearchTabOrder(false);
            return;
        }

        if (!_headerOk) {
            var hr = _header.getBoundingClientRect();
            var wr = _wrapper.getBoundingClientRect();
            if (hr.height <= 24 || wr.height <= 24) {
                _header.classList.remove('awa-header-condensed', 'awa-scroll-down');
                _wrapper.classList.remove('is-sticky', 'awa-header-condensed');
                if (d.body) { d.body.classList.remove('awa-header-is-sticky'); }
                syncCondensedGeometry(false);
                syncMobileSearchTabOrder(false);
                return;
            }
            _headerOk = true;
        }

        var y = w.pageYOffset || 0;
        var sticky = y > getThreshold();

        _header.classList.toggle('awa-header-condensed', sticky);
        _wrapper.classList.toggle('is-sticky', sticky);
        _wrapper.classList.toggle('awa-header-condensed', sticky);
        if (d.body) { d.body.classList.toggle('awa-header-is-sticky', sticky); }
        if (_promoBar) { _promoBar.classList.toggle('awa-promo-bar--scrolled-away', sticky); }
        syncCondensedGeometry(sticky);
        syncMobileSearchTabOrder(sticky);

        if (sticky) {
            kickStickyOwner();
        }

    }

    function onScroll() {
        if (!_rafPending) {
            _rafPending = true;
            w.requestAnimationFrame(applySync);
        }
    }

    if (_mobileMq.addEventListener) {
        _mobileMq.addEventListener('change', onScroll);
    } else if (_mobileMq.addListener) {
        _mobileMq.addListener(onScroll);
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', applySync, { once: true });
    } else {
        applySync();
    }
    w.addEventListener('scroll', onScroll, { passive: true });
    /* PERF: resize pode mudar a altura do promo bar (threshold) e o estado sticky. */
    w.addEventListener('resize', function () {
        invalidateThreshold();
        onScroll();
    }, { passive: true });
    w.addEventListener('load', invalidateThreshold, { once: true, passive: true });
}(window, document));
