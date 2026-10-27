(function(w,d){
    'use strict';
    var desktopMq = w.matchMedia ? w.matchMedia('(min-width: 992px)') : null;
    var navLockPages = '.cms-index-index,.cms-home,.cms-homepage_ayo_home5,.catalog-category-view,.catalogsearch-result-index,.catalog-product-view,.checkout-cart-index,.b2b-account-dashboard,.b2b-account-shell';
    var navSelector = '.page-wrapper .awa-site-header .header-wrapper-sticky > .header-control.awa-nav-bar,'
        + '.page-wrapper .awa-site-header .header-wrapper-sticky > .header-control.header-nav.awa-nav-bar,'
        + '.page-wrapper .awa-site-header .header-control.awa-nav-bar,'
        + '.page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar';
    var containerSelector = '.page-wrapper .awa-site-header .header-control.awa-nav-bar > .container,'
        + '.page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar > .container';
    var innerSelector = '.page-wrapper .awa-site-header .header-control.awa-nav-bar .awa-nav-bar__inner,'
        + '.page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar .awa-nav-bar__inner';
    var navAxisCleared = false;
    var accountLockLastKey = '';

    function setImportant(el, prop, value){
        if (el && el.style) {
            el.style.setProperty(prop, value, 'important');
        }
    }

    function clearInlineLock(el){
        if (!el || !el.style) {
            return;
        }
        ['width','max-width','margin-inline','margin-left','margin-right','padding-inline','padding-left','padding-right','box-sizing']
            .forEach(function(prop){
                el.style.removeProperty(prop);
            });
    }

    function applyNavAxisInlineLock(){
        /* Contrato: .awa-nav-bar full-bleed; .container passthrough; .awa-nav-bar__inner = 100% + padding do trilho 1280.
         * Não travar geometria via style="" — o CSS já define o eixo. Só limpa locks legados uma vez. */
        if (navAxisCleared || !d.body || !d.body.matches(navLockPages)) {
            return;
        }
        d.querySelectorAll(navSelector).forEach(clearInlineLock);
        d.querySelectorAll(containerSelector).forEach(clearInlineLock);
        d.querySelectorAll(innerSelector).forEach(clearInlineLock);
        navAxisCleared = true;
    }

    function applyAccountChromeInlineLock(){
        if (!desktopMq || !desktopMq.matches) {
            return;
        }
        var bar = d.getElementById('awa-b2b-promo-bar');
        var dismissed = !!(d.documentElement && d.documentElement.classList.contains('awa-b2b-promo-dismissed'));
        var b2bKnown = !!(d.querySelector('.page-wrapper .awa-site-header .b2b-status-panel'));
        var hidden = dismissed || b2bKnown || (bar && (
            bar.getAttribute('aria-hidden') === 'true'
            || bar.getAttribute('data-awa-promo-hidden') === '1'
        ));
        var accountKey = (hidden ? 'h' : 'v')
            + '|'
            + (b2bKnown ? 'b2b' : 'std')
            + '|'
            + w.innerWidth;
        if (accountKey === accountLockLastKey) {
            return;
        }
        accountLockLastKey = accountKey;
        var prompt = d.querySelector('.page-wrapper .awa-site-header .awa-header-account-prompt');
        var icon = d.querySelector('.page-wrapper .awa-site-header .awa-header-account-prompt__icon');
        var reg = d.querySelector('.page-wrapper .awa-site-header a.awa-header-account-prompt__link--register');
        var sticky = d.querySelector('.page-wrapper .awa-site-header .header-wrapper-sticky');
        var site = d.querySelector('.page-wrapper .awa-site-header');
        var header = d.querySelector('.page-wrapper .awa-site-header .header.awa-main-header');
        if (prompt) {
            setImportant(prompt, 'border', '0');
            setImportant(prompt, 'border-radius', '0');
            setImportant(prompt, 'outline', '0');
            setImportant(prompt, 'background', 'transparent');
            setImportant(prompt, 'background-image', 'none');
            setImportant(prompt, 'box-shadow', 'none');
            setImportant(prompt, 'padding', '0');
        }
        if (icon) {
            setImportant(icon, 'background', 'transparent');
            setImportant(icon, 'border', '0');
            setImportant(icon, 'border-radius', '0');
            setImportant(icon, 'box-shadow', 'none');
        }
        if (reg) {
            setImportant(reg, 'background', 'transparent');
            setImportant(reg, 'background-color', 'transparent');
            setImportant(reg, 'background-image', 'none');
            setImportant(reg, 'color', 'var(--awa-text, CanvasText)');
            setImportant(reg, 'border', '0');
            setImportant(reg, 'border-radius', '0');
            setImportant(reg, 'padding', '0 2px');
            setImportant(reg, 'box-shadow', 'none');
            setImportant(reg, 'min-height', '0');
            setImportant(reg, 'height', 'auto');
        }
        if (site) {
            setImportant(site, 'border-bottom', '0');
            setImportant(site, 'box-shadow', 'none');
        }
        if (sticky) {
            setImportant(sticky, 'border-bottom', '0');
            setImportant(sticky, 'box-shadow', 'none');
        }
        if (header) {
            setImportant(header, 'border-bottom', '0');
            setImportant(header, 'box-shadow', 'none');
        }
        var shell = d.querySelector('.page-wrapper .awa-site-header #header.header-container');
        var content = d.querySelector('.page-wrapper .awa-site-header #header.header-container > .header-content');
        var inner = d.querySelector('#awa-b2b-promo-bar .awa-b2b-promo-bar__inner');
        var text = d.querySelector('#awa-b2b-promo-bar .awa-b2b-promo-bar__text');
        var close = d.querySelector('#awa-b2b-promo-bar .awa-b2b-promo-close');
        var rowH = hidden ? '0px' : '44px';
        var fill = 'var(--awa-primary, oklch(48% .14 20))';
        [shell, content, bar, inner, text].forEach(function(el){
            if (!el) {
                return;
            }
            setImportant(el, 'height', rowH);
            setImportant(el, 'min-height', rowH);
            setImportant(el, 'max-height', rowH);
            setImportant(el, 'overflow', 'hidden');
            setImportant(el, 'box-sizing', 'border-box');
        });
        if (shell) {
            setImportant(shell, 'background', hidden ? 'transparent' : fill);
        }
        if (content) {
            setImportant(content, 'background', 'transparent');
        }
        if (bar) {
            setImportant(bar, 'background', hidden ? 'transparent' : fill);
            setImportant(bar, 'color', 'var(--awa-white, #fff)');
            setImportant(bar, '-webkit-text-fill-color', 'var(--awa-white, #fff)');
            setImportant(bar, 'display', hidden ? 'none' : 'flex');
            setImportant(bar, 'padding', '0');
            setImportant(bar, 'padding-left', '0');
            setImportant(bar, 'padding-right', '0');
            setImportant(bar, 'width', '100%');
            setImportant(bar, 'max-width', 'none');
        }
        if (inner) {
            setImportant(inner, 'width', hidden ? '0px' : 'min(100%, 1280px)');
            setImportant(inner, 'max-width', hidden ? '0px' : '1280px');
            setImportant(inner, 'margin-left', 'auto');
            setImportant(inner, 'margin-right', 'auto');
            setImportant(inner, 'padding', hidden ? '0' : '0 52px 0 24px');
            setImportant(inner, 'padding-left', hidden ? '0' : '24px');
            setImportant(inner, 'padding-right', hidden ? '0' : '52px');
        }
        if (text) {
            setImportant(text, 'padding', '0');
            setImportant(text, 'width', '100%');
            setImportant(text, 'max-width', 'none');
            setImportant(text, 'color', 'var(--awa-white, #fff)');
            setImportant(text, '-webkit-text-fill-color', 'var(--awa-white, #fff)');
        }
        d.querySelectorAll('#awa-b2b-promo-bar .awa-b2b-promo-bar__inner, #awa-b2b-promo-bar .awa-b2b-promo-bar__lead, #awa-b2b-promo-bar .awa-b2b-promo-bar__lead-long').forEach(function(el){
            setImportant(el, 'color', 'var(--awa-white, #fff)');
            setImportant(el, '-webkit-text-fill-color', 'var(--awa-white, #fff)');
        });
        var cta = d.querySelector('#awa-b2b-promo-bar .awa-b2b-promo-bar__cta');
        if (cta) {
            setImportant(cta, 'background', 'transparent');
            setImportant(cta, 'background-color', 'transparent');
            setImportant(cta, 'background-image', 'none');
            setImportant(cta, 'color', 'var(--awa-white, #fff)');
            setImportant(cta, '-webkit-text-fill-color', 'var(--awa-white, #fff)');
            setImportant(cta, 'border', '0');
            setImportant(cta, 'border-radius', '0');
            setImportant(cta, 'padding', hidden ? '0' : '0 12px');
            setImportant(cta, 'max-width', 'none');
        }
        if (close) {
            setImportant(close, 'height', hidden ? '0px' : '44px');
            setImportant(close, 'width', hidden ? '0px' : '44px');
            setImportant(close, 'position', 'absolute');
            setImportant(close, 'top', '0');
            setImportant(close, 'right', '0');
            setImportant(close, 'inset-inline-end', '0');
            setImportant(close, 'transform', 'none');
            setImportant(close, 'border-radius', '0');
            setImportant(close, 'background', 'transparent');
            setImportant(close, 'color', 'var(--awa-white, #fff)');
            setImportant(close, '-webkit-text-fill-color', 'var(--awa-white, #fff)');
        }
        if (site) {
            if (b2bKnown) {
                setImportant(site, 'height', 'auto');
                setImportant(site, 'min-height', '0px');
                setImportant(site, 'max-height', 'none');
            } else {
                setImportant(site, 'height', hidden ? '116px' : '160px');
                setImportant(site, 'min-height', hidden ? '116px' : '160px');
            }
        }
    }


    /* secao53-home-doc-scroll: do not leave overflow:hidden on html/body
       after chat/minicart/nav close. Skip assignment unless a dialog is open. */
    function isHomeDoc(){
        return !!(d.body && (d.body.classList.contains('cms-index-index')
            || d.body.classList.contains('cms-home')
            || d.body.classList.contains('cms-homepage_ayo_home5')));
    }
    function homeDialogOpen(){
        var b = d.body;
        if (!b) { return false; }
        if (b.classList.contains('nav-open')
            || b.classList.contains('nav-before-open')
            || b.classList.contains('_has-modal')
            || b.classList.contains('awa-mobile-drawer-open')
            || b.classList.contains('awa-modal-open')
            || b.classList.contains('nl-popup-body-open')
            || b.classList.contains('awa-minicart-overlay-active')) {
            return true;
        }
        return !!(d.querySelector('.modal-popup._show, .modal-slide._show, .minicart-wrapper.active'));
    }
    function restoreHomeDocScroll(){
        if (!isHomeDoc() || homeDialogOpen()) { return; }
        [d.documentElement, d.body].forEach(function (el) {
            if (!el || !el.style) { return; }
            var ov = (el.style.getPropertyValue('overflow') || el.style.overflow || '').toLowerCase();
            var ovy = (el.style.getPropertyValue('overflow-y') || el.style.overflowY || '').toLowerCase();
            if (ov === 'hidden' || ov === 'clip' || ovy === 'hidden' || ovy === 'clip') {
                el.style.removeProperty('overflow');
                el.style.removeProperty('overflow-y');
            }
            var h = (el.style.getPropertyValue('height') || el.style.height || '').toLowerCase();
            if (h === '100vh' || h === '100%') {
                el.style.removeProperty('height');
                el.style.removeProperty('max-height');
            }
        });
        var wrap = d.querySelector('.page-wrapper');
        if (wrap && wrap.style) {
            var wov = (wrap.style.getPropertyValue('overflow') || '').toLowerCase();
            var wovy = (wrap.style.getPropertyValue('overflow-y') || '').toLowerCase();
            if (wov === 'hidden' || wov === 'clip' || wovy === 'hidden' || wovy === 'clip') {
                wrap.style.removeProperty('overflow');
                wrap.style.removeProperty('overflow-y');
            }
        }
    }
    function patchHomeOverflowSetter(el){
        if (!el || !el.style || el.getAttribute('data-awa-ov-guard') === '1') { return; }
        el.setAttribute('data-awa-ov-guard', '1');
        try {
            var st = el.style;
            Object.defineProperty(st, 'overflow', {
                configurable: true,
                get: function () { return st.getPropertyValue('overflow'); },
                set: function (v) {
                    var val = String(v == null ? '' : v).toLowerCase();
                    if ((val === 'hidden' || val === 'clip') && isHomeDoc() && !homeDialogOpen()) {
                        return;
                    }
                    st.setProperty('overflow', v);
                }
            });
        } catch (e) { /* ignore */ }
    }
    function bootHomeDocScroll(){
        if (!isHomeDoc()) { return; }
        d.documentElement.classList.add('awa-home-doc-scroll');
        /* freeze-fix-r11: do not monkey-patch CSSStyleDeclaration.overflow
           and do not observe html/body attrs. Both re-enter style recalc
           on scroll (Translate class mutations + layout reading overflow). */
        restoreHomeDocScroll();
    }

    var reveal=function(){
        if(!d.body){return;}
        d.body.classList.add('awa-overflow-polish-ready');
        bootHomeDocScroll();
        /* PD7b: não injetar min-height:44px inline nos títulos — vencia toda a cascata CSS
         * e quebrava o line-clamp de 2 linhas. Touch target permanece no CTA (.awa-card-view-product). */
        if(d.getElementById('awa-touch-target-post-lock')){
            d.getElementById('awa-touch-target-post-lock').remove();
        }
        d.querySelectorAll('.content-top-home .product-item-link').forEach(function(link){
            if(link.style.getPropertyValue('min-height')==='44px'){
                link.style.removeProperty('min-height');
            }
            if(link.style.getPropertyValue('max-height')==='none'){
                link.style.removeProperty('max-height');
            }
        });
        applyNavAxisInlineLock();
        applyAccountChromeInlineLock();
    };
    /* DOMContentLoaded: HTML parseado, JS ainda não rodou — overflow:visible não causa CLS
       (nenhum elemento muda de posição no layout). Mais rápido que load+2500ms, garante
       que ferramentas de qualidade (Impeccable) detectem o estado final correto. */
    if(d.readyState==='loading'){
        d.addEventListener('DOMContentLoaded',reveal,{once:true});
    } else {
        reveal();
    }
    w.addEventListener('load', applyAccountChromeInlineLock, {once:true, passive:true});
    if (desktopMq) {
        if (desktopMq.addEventListener) {
            desktopMq.addEventListener('change', applyAccountChromeInlineLock);
        } else if (desktopMq.addListener) {
            desktopMq.addListener(applyAccountChromeInlineLock);
        }
    }
    w.setTimeout(applyAccountChromeInlineLock, 800);
    w.setTimeout(applyAccountChromeInlineLock, 1800);
})(window,document);
