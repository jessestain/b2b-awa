(function(d,w){
    'use strict';
    var path = (w.location && w.location.pathname) ? w.location.pathname : '';
    var isHomeLockPage = path === '/' || path === '/index.php' || path === '/index.php/';
    if (!isHomeLockPage) {
        return;
    }
    function setImportant(el, prop, value){
        if (el && el.style) {
            el.style.setProperty(prop, value, 'important');
        }
    }
    function lockPromoRow(){
        var bar = d.getElementById('awa-b2b-promo-bar');
        var dismissed = !!(d.documentElement && d.documentElement.classList.contains('awa-b2b-promo-dismissed'));
        var b2bKnown = !!(d.querySelector('.page-wrapper .awa-site-header .b2b-status-panel'));
        var hidden = dismissed || b2bKnown || (bar && (
            bar.getAttribute('aria-hidden') === 'true'
            || bar.getAttribute('data-awa-promo-hidden') === '1'
        ));
        var shell = d.querySelector('.page-wrapper .awa-site-header #header.header-container');
        var content = d.querySelector('.page-wrapper .awa-site-header #header.header-container > .header-content');
        var inner = d.querySelector('#awa-b2b-promo-bar .awa-b2b-promo-bar__inner');
        var text = d.querySelector('#awa-b2b-promo-bar .awa-b2b-promo-bar__text');
        var cta = d.querySelector('#awa-b2b-promo-bar .awa-b2b-promo-bar__cta');
        var close = d.querySelector('#awa-b2b-promo-bar .awa-b2b-promo-close');
        var site = d.querySelector('.page-wrapper .awa-site-header');
        var rowH = hidden ? '0px' : '44px';
        var fill = 'var(--awa-primary, oklch(48% .14 20))';
        var ink = 'var(--awa-white, #fff)';
        [shell, content, bar, inner, text].forEach(function(el){
            if (!el) {
                return;
            }
            setImportant(el, 'height', rowH);
            setImportant(el, 'min-height', rowH);
            setImportant(el, 'max-height', rowH);
            setImportant(el, 'padding-top', '0');
            setImportant(el, 'padding-bottom', '0');
            setImportant(el, 'margin-top', '0');
            setImportant(el, 'margin-bottom', '0');
            setImportant(el, 'border-bottom', '0');
            setImportant(el, 'overflow', 'hidden');
            setImportant(el, 'box-sizing', 'border-box');
        });
        if (shell) {
            setImportant(shell, 'background', hidden ? 'transparent' : fill);
            setImportant(shell, 'background-color', hidden ? 'transparent' : fill);
        }
        if (content) {
            setImportant(content, 'background', 'transparent');
            setImportant(content, 'align-items', 'center');
        }
        if (bar) {
            setImportant(bar, 'background', hidden ? 'transparent' : fill);
            setImportant(bar, 'background-color', hidden ? 'transparent' : fill);
            setImportant(bar, 'color', ink);
            setImportant(bar, '-webkit-text-fill-color', ink);
            setImportant(bar, 'display', hidden ? 'none' : 'flex');
            setImportant(bar, 'align-items', 'center');
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
            setImportant(inner, 'padding', hidden ? '0' : '0 52px');
            setImportant(inner, 'padding-left', hidden ? '0' : '52px');
            setImportant(inner, 'padding-right', hidden ? '0' : '52px');
        }
        if (text) {
            setImportant(text, 'color', ink);
            setImportant(text, '-webkit-text-fill-color', ink);
            setImportant(text, 'line-height', '1.2');
            setImportant(text, 'padding', '0');
            setImportant(text, 'padding-left', '0');
            setImportant(text, 'padding-right', '0');
            setImportant(text, 'width', '100%');
            setImportant(text, 'max-width', 'none');
        }
        d.querySelectorAll('#awa-b2b-promo-bar .awa-b2b-promo-bar__inner, #awa-b2b-promo-bar .awa-b2b-promo-bar__lead, #awa-b2b-promo-bar .awa-b2b-promo-bar__lead-long, #awa-b2b-promo-bar .awa-b2b-promo-bar__lead-short, #awa-b2b-promo-bar .awa-b2b-promo-bar__tail, #awa-b2b-promo-bar .awa-b2b-promo-bar__separator, #awa-b2b-promo-bar .awa-b2b-promo-bar__cta strong').forEach(function(el){
            setImportant(el, 'color', ink);
            setImportant(el, '-webkit-text-fill-color', ink);
        });
        if (cta) {
            setImportant(cta, 'background', 'transparent');
            setImportant(cta, 'background-color', 'transparent');
            setImportant(cta, 'background-image', 'none');
            setImportant(cta, 'color', ink);
            setImportant(cta, '-webkit-text-fill-color', ink);
            setImportant(cta, 'border', '0');
            setImportant(cta, 'border-radius', '0');
            setImportant(cta, 'height', hidden ? '0px' : '44px');
            setImportant(cta, 'padding', hidden ? '0' : '0 12px');
            setImportant(cta, 'box-shadow', 'none');
            setImportant(cta, 'max-width', 'none');
        }
        if (close) {
            setImportant(close, 'height', hidden ? '0px' : '44px');
            setImportant(close, 'width', hidden ? '0px' : '44px');
            setImportant(close, 'min-height', hidden ? '0px' : '44px');
            setImportant(close, 'min-width', hidden ? '0px' : '44px');
            setImportant(close, 'color', ink);
            setImportant(close, '-webkit-text-fill-color', ink);
            setImportant(close, 'position', 'absolute');
            setImportant(close, 'top', '0');
            setImportant(close, 'right', '0');
            setImportant(close, 'inset-inline-end', '0');
            setImportant(close, 'transform', 'none');
            setImportant(close, 'border-radius', '0');
            setImportant(close, 'background', 'transparent');
            setImportant(close, 'background-color', 'transparent');
            setImportant(close, 'padding', '0');
        }
        if (site) {
            if (b2bKnown) {
                setImportant(site, 'height', 'auto');
                setImportant(site, 'min-height', '0px');
                setImportant(site, 'max-height', 'none');
                setImportant(site, '--awa-header-promo-h', '0px');
                setImportant(site, '--awa-header-stack-h', 'auto');
            } else {
                setImportant(site, 'height', hidden ? '116px' : '160px');
                setImportant(site, 'min-height', hidden ? '116px' : '160px');
                setImportant(site, '--awa-header-promo-h', hidden ? '0px' : '44px');
                setImportant(site, '--awa-header-stack-h', hidden ? '116px' : '160px');
            }
        }
    }
    function lock(){
        if (w.matchMedia && !w.matchMedia('(min-width: 992px)').matches) {
            return;
        }
        var prompt = d.querySelector('.page-wrapper .awa-site-header .awa-header-account-prompt');
        var reg = d.querySelector('.page-wrapper .awa-site-header a.awa-header-account-prompt__link--register');
        var sticky = d.querySelector('.page-wrapper .awa-site-header .header-wrapper-sticky');
        var site = d.querySelector('.page-wrapper .awa-site-header');
        var header = d.querySelector('.page-wrapper .awa-site-header .header.awa-main-header');
        var nav = d.querySelector('.page-wrapper .awa-site-header .header-control.awa-nav-bar, .page-wrapper .awa-site-header .header-control.header-nav.awa-nav-bar');
        if (prompt) {
            setImportant(prompt, 'border', '0');
            setImportant(prompt, 'border-radius', '0');
            setImportant(prompt, 'background', 'transparent');
            setImportant(prompt, 'padding', '0');
            setImportant(prompt, 'box-shadow', 'none');
        }
        if (reg) {
            setImportant(reg, 'background', 'transparent');
            setImportant(reg, 'background-color', 'transparent');
            setImportant(reg, 'color', 'var(--awa-text, CanvasText)');
            setImportant(reg, 'border', '0');
            setImportant(reg, 'border-radius', '0');
            setImportant(reg, 'padding', '0 2px');
            setImportant(reg, 'box-shadow', 'none');
        }
        var surface = 'var(--awa-bg, var(--awa-white, Canvas))';
        var searchBorder = '1px solid var(--awa-border, color-mix(in srgb, CanvasText 12%, Canvas))';
        if (site) {
            setImportant(site, 'border-bottom', '0');
            setImportant(site, 'background', surface);
            setImportant(site, 'background-color', surface);
        }
        if (sticky) {
            setImportant(sticky, 'border-bottom', '0');
            setImportant(sticky, 'background', surface);
            setImportant(sticky, 'background-color', surface);
        }
        if (header) {
            setImportant(header, 'border-bottom', '0');
            setImportant(header, 'background', surface);
            setImportant(header, 'background-color', surface);
        }
        if (nav && nav.style) {
            /* Contrato: fundo da nav vem do CSS. O shorthand background gerava
             * style="" com background-* vazios !important (evidência b7c731). */
            ['background', 'background-image', 'background-position', 'background-position-x',
             'background-position-y', 'background-size', 'background-repeat', 'background-attachment',
             'background-origin', 'background-clip', 'background-color', 'border-bottom', 'border-block-end']
                .forEach(function (prop) {
                    nav.style.removeProperty(prop);
                });
        }
        var searchForm = d.querySelector('.page-wrapper .awa-site-header form#search_mini_form');
        if (searchForm) {
            var searchFocused = searchForm.contains(d.activeElement);
            setImportant(searchForm, 'border', searchFocused
                ? '1px solid var(--awa-primary, oklch(48% .14 20))'
                : searchBorder);
            setImportant(searchForm, 'box-shadow', searchFocused
                ? '0 0 0 3px color-mix(in srgb, var(--awa-primary, oklch(48% .14 20)) 18%, transparent)'
                : 'none');
            setImportant(searchForm, 'background', surface);
            setImportant(searchForm, 'background-color', surface);
            setImportant(searchForm, 'overflow', 'visible');
            setImportant(searchForm, 'align-items', 'stretch');
            var innerH = 'var(--awa-search-height, 44px)';
            var fitId = 'awa-search-inner-fit-20260814';
            var fit = d.getElementById(fitId);
            if (!fit) {
                fit = d.createElement('style');
                fit.id = fitId;
                d.head.appendChild(fit);
            }
            fit.textContent = '@media(min-width:992px){html body'
                + '#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                + '#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body'
                + '#html-body#html-body#html-body#html-body .page-wrapper .awa-site-header form#search_mini_form '
                + ':is(input#search,input#search.input-text,.field.search,.field.search .control,.actions,button.action.search){'
                + 'height:' + innerH + '!important;max-height:' + innerH + '!important;min-height:' + innerH + '!important;'
                + 'border:0!important;box-shadow:none!important;border-radius:0!important}}';
            searchForm.querySelectorAll('.field.search, .field.search .control, .actions, input#search, button.action.search').forEach(function(el){
                setImportant(el, 'border', '0');
                setImportant(el, 'box-shadow', 'none');
                setImportant(el, 'border-radius', '0');
                setImportant(el, 'height', innerH);
                setImportant(el, 'min-height', innerH);
                setImportant(el, 'max-height', innerH);
                setImportant(el, 'align-self', 'stretch');
            });
            var searchInput = searchForm.querySelector('input#search');
            if (searchInput) {
                setImportant(searchInput, 'background', 'transparent');
                setImportant(searchInput, 'background-color', 'transparent');
                setImportant(searchInput, 'padding-top', '0');
                setImportant(searchInput, 'padding-bottom', '0');
                setImportant(searchInput, 'height', innerH);
                setImportant(searchInput, 'max-height', innerH);
                setImportant(searchInput, 'min-height', innerH);
                setImportant(searchInput, 'border', '0');
            }
        }
        var dept = d.querySelector('.page-wrapper .awa-site-header button.our_categories, .page-wrapper .awa-site-header .our_categories.title-category-dropdown');
        if (dept) {
            setImportant(dept, 'border', '0');
            setImportant(dept, 'box-shadow', 'none');
        }
        var mini = d.querySelector('.page-wrapper .awa-site-header .minicart-wrapper');
        if (mini) {
            setImportant(mini, 'border-radius', '0');
            setImportant(mini, 'background', 'transparent');
            setImportant(mini, 'box-shadow', 'none');
        }
        lockPromoRow();
    }
    var lockQueued = false;
    var lockLastRunTs = 0;
    var lockRearmTimer = null;
    function queueLock() {
        var now = Date.now();
        if (lockQueued) {
            return;
        }
        if (now - lockLastRunTs < 240) {
            if (lockRearmTimer) {
                return;
            }
            lockRearmTimer = w.setTimeout(function () {
                lockRearmTimer = null;
                queueLock();
            }, 280);
            return;
        }
        lockQueued = true;
        var run = function () {
            lockQueued = false;
            lockLastRunTs = Date.now();
            lock();
        };
        if (typeof w.requestAnimationFrame === 'function') {
            w.requestAnimationFrame(run);
            return;
        }
        w.setTimeout(run, 0);
    }
    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', queueLock, {once: true});
    } else {
        queueLock();
    }
    w.addEventListener('load', queueLock, {once: true, passive: true});
    w.setTimeout(queueLock, 400);
    w.setTimeout(queueLock, 800);
    w.setTimeout(queueLock, 1800);
    w.setTimeout(queueLock, 3200);
    w.setTimeout(queueLock, 5000);
    w.setTimeout(queueLock, 8000);
    w.__awaQueueHeaderLock = queueLock;
    d.addEventListener('click', function(ev){
        var t = ev.target;
        if (t && t.closest && t.closest('.awa-b2b-promo-close, #awa-b2b-promo-close')) {
            w.setTimeout(queueLock, 0);
            w.setTimeout(queueLock, 50);
        }
    }, true);
    d.addEventListener('focusin', function(ev){
        var t = ev.target;
        if (t && t.closest && t.closest('.page-wrapper .awa-site-header form#search_mini_form')) {
            queueLock();
        }
    }, true);
    d.addEventListener('focusout', function(ev){
        var t = ev.target;
        if (t && t.closest && t.closest('.page-wrapper .awa-site-header form#search_mini_form')) {
            w.setTimeout(queueLock, 0);
        }
    }, true);
    if (w.MutationObserver && !isHomeLockPage) {
        var moTarget = d.querySelector('.page-wrapper .awa-site-header') || d.body;
        var mo = null;
        if (moTarget) {
            mo = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i += 1) {
                    if (mutations[i].type === 'attributes' && mutations[i].attributeName === 'class') {
                        queueLock();
                        break;
                    }
                }
            });
            mo.observe(moTarget, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'aria-hidden', 'hidden']
            });
            w.setTimeout(function(){ if (mo) { mo.disconnect(); mo = null; } }, 7000);
        }
    }
}(document, window));
