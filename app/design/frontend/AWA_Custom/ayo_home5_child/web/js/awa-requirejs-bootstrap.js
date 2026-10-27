/**
 * Bootstrap AMD da storefront AWA (extraído do requirejs-config.js).
 */
define([
    'jquery',
    'Magento_PageCache/js/form-key-provider',
    'domReady!'
], function ($, initFormKeyProvider) {
    'use strict';

    if (typeof initFormKeyProvider === 'function') {
        initFormKeyProvider({
            isPaginationCacheEnabled: 0
        });
    }

    $.noConflict();

    // Pedido do dono 2026-10-23: "melhore menu vertical". O painel de Departamentos
    // abria em fluxo (alinhado ao fundo do container de 44px), com o início cortado
    // acima da viewport, e um botão "Ver mais categorias" sobrepondo itens.
    // Este enhancer posiciona o painel logo abaixo da barra, limita a altura à
    // viewport com rolagem interna, esconde o overlay redundante e atualiza aria.
    //
    // FIX 2026-10-24 (incidente: clique em "Departamentos" travava a página em
    // loop infinito de mutações): o MutationObserver chamava sync() de forma
    // síncrona/reentrante e applyPosition() reescrevia TODAS as propriedades a
    // cada chamada — cada escrita gerava nova mutação e o ciclo nunca assentava
    // (feedback com o controlador anchor-r23, que escreve os mesmos elementos).
    // Correção:
    //  1) setProp() escreve somente quando o valor/prioridade atuais diferem
    //     (última passada não gera mutação → o ciclo termina);
    //  2) sync() coalescido via requestAnimationFrame (máx. 1 por frame);
    //  3) aria-expanded fica a cargo do controlador do menu (anchor-r23) —
    //     o enhancer não escreve mais esse atributo (era um dos lados do loop).
    function initVerticalMenuEnhance() {
        if (window.__awaVMenuEnhanced) { return; }
        var trigger = document.querySelector('.awa-nav-bar button.title-category-dropdown, .awa-header-categories button.title-category-dropdown, button.title-category-dropdown');
        var content = document.querySelector('.section-item-content.nav-sections.category-dropdown-item-content');
        if (!trigger || !content) { return; }
        window.__awaVMenuEnhanced = true;

        var bar = trigger.closest('.awa-nav-bar__inner, .awa-nav-bar') || trigger.parentElement;
        var navBar = trigger.closest('.awa-nav-bar') || bar;
        var lastTopPx = null;
        var lastLeftPx = null;

        function panelEl() { return document.querySelector('.section-item-content.nav-sections.category-dropdown-item-content'); }

        /* FIX 2026-10-24 r4: cache de geometria. O painel é position:fixed — a
           posição só muda com scroll real/resize/sticky. Se já está posicionado
           e nada mudou, applyPosition NÃO faz leitura de layout (o trace mostrou
           ~168ms de recalc forçado por open só por reler getBoundingClientRect
           sobre o layout recém-escrito). */
        var geometryDirty = true;
        function markGeometryDirty() { geometryDirty = true; }

        function isOpen() {
            /* FIX 2026-10-24: estado vem do controlador (aria/classes), sem leitura
               de layout — o fallback antigo (rect().height > 0) via o container
               "visível" mesmo com o menu fechado e o applyPosition reabria o painel
               (display:block) logo após o fechar. */
            var trig = document.querySelector('button.title-category-dropdown') || trigger;
            if (trig && trig.getAttribute('aria-expanded') === 'true') { return true; }
            var c = panelEl();
            if (!c) { return false; }
            var ul = c.querySelector('ul.togge-menu, ul.list-category-dropdown');
            return !!(ul && (ul.classList.contains('menu-open') || ul.classList.contains('vmm-open') || ul.getAttribute('data-awa-menu-state') === 'open'));
        }

        /* FIX 2026-10-24: escreve só quando o valor atual difere do desejado. */
        function setProp(el, prop, value) {
            if (!el) { return; }
            if (el.style.getPropertyValue(prop) === value && el.style.getPropertyPriority(prop) === 'important') {
                return;
            }
            el.style.setProperty(prop, value, 'important');
        }

        function applyPosition() {
            var c = panelEl();
            if (!c) { return; }
            // FIX r4: geometria ainda válida → nenhuma leitura de layout.
            if (!geometryDirty) {
                var ulCached = c.querySelector('ul.togge-menu, ul.list-category-dropdown');
                if (ulCached && ulCached.style.getPropertyValue('position') === 'fixed') {
                    return;
                }
            }
            var trig = document.querySelector('button.title-category-dropdown') || trigger;
            var tb = trig ? trig.getBoundingClientRect() : null;
            var navBar2 = trig ? (trig.closest('.awa-nav-bar') || trig.closest('.awa-nav-bar__inner')) : null;
            var nb = navBar2 ? navBar2.getBoundingClientRect() : null;
            var topPx = nb ? Math.round(nb.bottom + 2) : (tb ? Math.round(tb.bottom + 2) : 120);
            var leftPx = tb ? Math.round(tb.left) : 12;
            // FIX 2026-10-24 r3: histerese de ±2px — durante o open, o layout
            // assenta em 1px para cá/lá (scroll-anchoring/sticky) e cada passada
            // reescrevia as ~20 propriedades (medido: 17 passadas/open). Agora
            // reposiciona só quando o deslocamento REAL mudou.
            if (lastTopPx !== null && Math.abs(topPx - lastTopPx) <= 2 && Math.abs(leftPx - lastLeftPx) <= 2) {
                var ulPrev = c.querySelector('ul.togge-menu, ul.list-category-dropdown');
                if (ulPrev && ulPrev.style.getPropertyValue('position') === 'fixed') {
                    return;
                }
            }
            lastTopPx = topPx;
            lastLeftPx = leftPx;
            // wrappers sem caixa (o painel real e o UL) + painel FIXO abaixo da barra
            setProp(c, 'display', 'contents');
            var sn = c.querySelector('.section-items');
            if (sn) { setProp(sn, 'display', 'contents'); }
            var ul = c.querySelector('ul.togge-menu, ul.list-category-dropdown');
            if (ul) {
                setProp(ul, 'position', 'fixed');
                setProp(ul, 'top', topPx + 'px');
                setProp(ul, 'left', leftPx + 'px');
                setProp(ul, 'width', 'min(460px, calc(100vw - 24px))');
                setProp(ul, 'max-height', 'calc(100vh - ' + topPx + 'px - 12px)');
                setProp(ul, 'overflow-y', 'auto');
                setProp(ul, 'overflow-x', 'hidden');
                setProp(ul, 'z-index', '1500');
                setProp(ul, 'background', '#fff');
                setProp(ul, 'border', '1px solid #e8e8e8');
                setProp(ul, 'border-top', '0');
                setProp(ul, 'border-radius', '0 0 12px 12px');
                setProp(ul, 'box-shadow', '0 16px 40px rgb(15 23 42 / 18%)');
                setProp(ul, 'margin', '0');
                setProp(ul, 'padding', '0');
                setProp(ul, 'list-style', 'none');
                setProp(ul, 'display', 'block');
                setProp(ul, 'height', 'auto');
                setProp(ul, 'transform', 'none');
            }
            geometryDirty = false;
            // FIX 2026-10-24: aria-expanded é responsabilidade do controlador
            // anchor-r23 (syncAria); escrever aqui alimentava o loop de mutações.
        }

        /* FIX 2026-10-24: sync coalescido — no máximo 1 execução por frame;
           quando fechado, não faz nada (o fechamento é do controlador). */
        var syncPending = false;
        function sync() {
            if (syncPending) { return; }
            syncPending = true;
            var run = function () {
                syncPending = false;
                if (isOpen()) { applyPosition(); }
            };
            if (window.requestAnimationFrame) { window.requestAnimationFrame(run); }
            else { window.setTimeout(run, 16); }
        }

        trigger.addEventListener('click', function () { window.setTimeout(sync, 400); });
        document.addEventListener('click', function (event) {
            if (!event.target.closest('.awa-header-categories, .awa-nav-categories, .awa-nav-bar')) { window.setTimeout(sync, 60); }
        }, true);
        window.addEventListener('resize', function () { markGeometryDirty(); sync(); });
        /* FIX 2026-10-24 r2: o painel é position:fixed — o scroll da página NÃO
           muda a posição do gatilho; só re-sincroniza quando o estado do header
           sticky muda (altura da barra). Antes, cada evento de scroll (inclusive
           os ajustes de scroll-anchoring durante o open) re-executava applyPosition
           com leituras de getBoundingClientRect sobre layout sujo — reflow por frame. */
        var lastStickyState = null;
        window.addEventListener('scroll', function () {
            markGeometryDirty();
            var sticky = document.querySelector('.header-wrapper-sticky');
            var now = !!(sticky && sticky.classList.contains('is-sticky'));
            if (lastStickyState === now) { return; }
            lastStickyState = now;
            sync();
        }, { passive: true });
        var moTimer = null;
        var mo = new MutationObserver(function () {
            /* FIX r4: 1ª vez posiciona JÁ (senão o painel pinta deslocado); depois,
               debounce de 200ms — a re-medição acontece após a tempestade de
               mutações do open, fora da janela crítica do clique. */
            var ulNow = panelEl() ? panelEl().querySelector('ul.togge-menu, ul.list-category-dropdown') : null;
            if (ulNow && ulNow.style.getPropertyValue('position') === 'fixed') {
                if (moTimer) { window.clearTimeout(moTimer); }
                moTimer = window.setTimeout(sync, 200);
            } else {
                sync();
            }
        });
        var scope = document.querySelector('.awa-header-categories, .awa-nav-categories');
        if (scope) { mo.observe(scope, { attributes: true, subtree: true, attributeFilter: ['class', 'data-awa-menu-state', 'style'] }); }
        sync();
        window.setTimeout(sync, 1200);
    }
    (function scheduleVMenuEnhance() {
        var tries = [0, 400, 1200, 2500, 5000];
        for (var i = 0; i < tries.length; i++) {
            window.setTimeout(initVerticalMenuEnhance, tries[i]);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initVerticalMenuEnhance);
        }
    })();



    // Pedido do dono 2026-10-23: botoes dos cards em largura maxima (inline
    // important vence o CSS em @layer). Este bootstrap AMD roda em todas as paginas.
    function initCardsFullWidthButtons() {
        if (window.__awaCardsFullWidth) { return; }
        window.__awaCardsFullWidth = true;
        var apply = function () {
            var cards = document.querySelectorAll('.item-product, .product-item');
            for (var i = 0; i < cards.length; i++) {
                var card = cards[i];
                var btn = card.querySelector('.action.tocart, button.tocart, .btn-add-to-cart');
                if (!btn) { continue; }
                var wrappers = [card.querySelector('.product-info-cart'), card.querySelector('.actions-primary'), btn.closest('form')];
                for (var w = 0; w < wrappers.length; w++) {
                    var el = wrappers[w];
                    if (!el) { continue; }
                    el.style.setProperty('width', '100%', 'important');
                    el.style.setProperty('max-width', 'none', 'important');
                    el.style.setProperty('padding-inline', '0', 'important');
                    el.style.setProperty('margin-inline', '0', 'important');
                    el.style.setProperty('box-sizing', 'border-box', 'important');
                }
                btn.style.setProperty('width', '100%', 'important');
                btn.style.setProperty('max-width', 'none', 'important');
                btn.style.setProperty('justify-content', 'center', 'important');
                btn.style.setProperty('box-sizing', 'border-box', 'important');
            }
        };
        apply();
        var t = null;
        var mo = new MutationObserver(function () {
            if (t) { return; }
            t = window.setTimeout(function () { t = null; apply(); }, 250);
        });
        var targets = document.querySelectorAll('.products-grid, .columns, .column.main, .content-top-home, .page-wrapper');
        for (var k = 0; k < targets.length; k++) { mo.observe(targets[k], { childList: true, subtree: true }); }
        window.setTimeout(apply, 800);
        window.setTimeout(apply, 2500);
    }
    initCardsFullWidthButtons();



    if (document.body && document.body.classList.contains('catalog-product-view')) {
        require(['awa-b2b-pdp-price-reload']);
    }

    function isCheckoutFlowPage() {
        if (!document.body) {
            return false;
        }

        return document.body.classList.contains('checkout-cart-index') ||
            document.body.classList.contains('checkout-index-index') ||
            document.body.classList.contains('rokanthemes-onepagecheckout') ||
            document.body.classList.contains('onepagecheckout-index-index');
    }

    function loadIdleModules() {
        var modules = ['awa-footer-returns-hotfix'];

        if (!isCheckoutFlowPage()) {
            modules.push('awa-link-a11y-hotfix');
        }

        require(modules);
    }

    var isHome = document.body && document.body.classList.contains('cms-index-index');

    if (!isHome) {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(loadIdleModules, { timeout: 3500 });
        } else {
            window.setTimeout(loadIdleModules, 2500);
        }
    }
});
