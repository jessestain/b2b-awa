/**
 * B2B Densidade — Coordenação de painéis flutuantes do header (14/10/2026).
 *
 * REGRA: abrir qualquer painel flutuante fecha automaticamente todos os outros.
 * Nunca dois abertos ao mesmo tempo (bug regredido: conta + minicarrinho
 * abertos simultaneamente, sobrepostos em 326x334px).
 *
 * Painéis coordenados:
 *   - Conta B2B (.b2b-status-dropdown / .awa-account-dropdown__menu)
 *   - Minicarrinho (.showcart -> #minicart-content-wrapper)
 *   - Menu Departamentos (button.our_categories -> [data-role=awa-vertical-menu-panel])
 *   - Sugestões de busca (.awa-sku-suggest)
 *
 * Sem dependências (vanilla), roda em todas as páginas.
 */
(function () {
    'use strict';

    function init() {
        window.__awaPanelExclusive = true;
        var doc = document;

        var find = function (sel) { return doc.querySelector(sel); };

        var isOpen = function (el) {
            if (!el) { return false; }
            if (el.hasAttribute('hidden') && el.hidden) { return false; }
            if (el.getAttribute('aria-hidden') === 'true') { return false; }
            if (el.getAttribute('data-awa-menu-state') === 'closed') { return false; }
            var cls = String(el.className || '');
            if (cls.indexOf('is-open') !== -1 || cls.indexOf('active') !== -1 || cls.indexOf('_active') !== -1) { return true; }
            var r = el.getBoundingClientRect();
            return r.width > 0 && r.height > 0;
        };

        var closeAccount = function () {
            var trigger = find('.b2b-status-trigger');
            var panel = find('.b2b-status-panel');
            var dropdown = find('.b2b-status-dropdown');
            var simpleTrigger = find('.awa-account-dropdown__trigger');
            var simpleMenu = find('.awa-account-dropdown__menu');
            if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
            if (dropdown) { dropdown.setAttribute('aria-hidden', 'true'); }
            if (panel) { panel.classList.remove('is-open'); }
            if (simpleTrigger) { simpleTrigger.setAttribute('aria-expanded', 'false'); }
            if (simpleMenu) { simpleMenu.setAttribute('aria-hidden', 'true'); }
        };

        var closeMinicart = function () {
            var trigger = doc.querySelector('a.showcart, .action.showcart');
            var wrapper = doc.querySelector('.minicart-wrapper, [data-block="minicart"]');
            var content = doc.querySelector('#minicart-content-wrapper, .block-minicart');
            // 1) dispara o outside-close NATIVO do dropdownDialog (fecha se aberto;
            //    inofensivo se fechado). Necessário porque alguns handlers de
            //    gatilho (ex.: conta) interrompem a propagação do clique original,
            //    impedindo o outside-close de recebê-lo.
            //
            // FIX 2026-10-24: o body.click() era disparado SEMPRE, e o controlador
            // do menu (anchor-r23) tratava esse clique sintético como "clique fora"
            // — fechava o menu de Departamentos no mesmo instante em que o toggle
            // do gatilho o reabria (2º clique nunca fechava o menu). Agora o
            // outside-close nativo só é disparado quando o minicarrinho está de
            // fato aberto; nos demais casos o fallback DOM (2) já basta.
            // FIX 2026-10-24 r2: SEM leitura de layout (getBoundingClientRect) —
            // o trace do DevTools mostrou 478 ms de reflow forçado carregados
            // nessa leitura durante o clique do menu. As classes is-open/active
            // do wrapper são o contrato do minicart-ui-bootstrap.
            var minicartOpen = !!(wrapper && (
                wrapper.classList.contains('is-open') || wrapper.classList.contains('active')
            ));
            if (minicartOpen) {
                try { doc.body.click(); } catch (e) { /* noop */ }
            }
            // 2) fallback DOM (classes/display) caso o widget não esteja pronto.
            if (trigger) { trigger.classList.remove('is-open'); }
            if (wrapper) { wrapper.classList.remove('is-open', 'active'); }
            if (content) { content.style.display = 'none'; }
        };

        var closeMenu = function () {
            var panel = find('[data-role="awa-vertical-menu-panel"]');
            var btn = find('button.our_categories, .title-category-dropdown');
            if (panel) {
                panel.setAttribute('aria-hidden', 'true');
                panel.setAttribute('data-awa-menu-state', 'closed');
                try { panel.setAttribute('inert', ''); } catch (e) { /* noop */ }
                panel.classList.remove('menu-open', 'is-open', 'open');
            }
            if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        };

        var closeSearch = function () {
            var panel = find('.awa-sku-suggest');
            if (panel) {
                panel.hidden = true;
                panel.setAttribute('aria-hidden', 'true');
                panel.innerHTML = '';
            }
        };

        var closeOthers = function (except) {
            if (except !== 'account') { closeAccount(); }
            if (except !== 'minicart') { closeMinicart(); }
            if (except !== 'menu') { closeMenu(); }
            if (except !== 'search') { closeSearch(); }
        };

        // Intercepta o clique nos gatilhos ANTES dos handlers nativos (capture).
        // Assim o painel clicado permanece aberto e os demais fecham.
        doc.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) { return; }
            if (t.closest('.b2b-status-trigger, .awa-account-dropdown__trigger')) {
                closeOthers('account');
            } else if (t.closest('a.showcart, .action.showcart')) {
                closeOthers('minicart');
            } else if (t.closest('button.our_categories, .title-category-dropdown, [data-role="awa-vertical-menu-toggle"]')) {
                closeOthers('menu');
            }
        }, true);

        // Focar a busca fecha os demais painéis.
        doc.addEventListener('focusin', function (e) {
            var t = e.target;
            if (t && t.matches && t.matches('input#search, input[name="q"], .block-search input')) {
                closeOthers('search');
                // FIX 2026-10-24: closeMenu() remove só as classes do painel (e
                // deixa vmm-open, que o nav-runtime trata como aberto). O close
                // completo é do controlador anchor-r23 via outside-close — o
                // body.click() sintético aciona esse caminho (mesmo padrão do
                // closeMinicart) e garante syncAria(false) + display:none.
                try { doc.body.click(); } catch (e2) { /* noop */ }
            }
        });

        // NOTA: sem listener global de "click fora fecha tudo" — cada painel já
        // tem seu próprio outside-close nativo (conta: document click do
        // header-status-panel; minicart: dropdownDialog; menu: verticalmenu;
        // busca: blur). Um listener global disputava o timing desses handlers
        // e causava o menu fechar/reabrir de forma não determinística.

        // Escape fecha tudo.
        doc.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeOthers('none'); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/* AWA-VS3c (17/10/2026) — correções globais de a11y/SEO:
   B9a: nome acessível do diálogo do minicart (role=dialog sem aria-label);
   B9b: aria-label do carrossel de categorias não pode divergir do texto visível
        (carrossel definia apenas "1 de 11");
   B10: links de produto do chat de IA nascem sem href (Lighthouse crawlable-anchors). */
(function () {
  if (window.__awaA11yFix) { return; }
  window.__awaA11yFix = true;
  function text(el) { return (el && el.textContent ? el.textContent : '').replace(/\s+/g, ' ').trim(); }
  function fixDialogs() {
    var dialogs = document.querySelectorAll('.ui-dialog[role="dialog"]');
    for (var i = 0; i < dialogs.length; i++) {
      var d = dialogs[i];
      if (d.getAttribute('aria-label') || d.getAttribute('aria-labelledby')) { continue; }
      var label = '';
      if (d.closest && d.closest('#awa-minicart-panel')) { label = 'Meu Carrinho'; }
      var t = d.querySelector('.ui-dialog-title, .modal-title, .minicart-title, .block-minicart .title, [id$="-title"]');
      if (!label && t) { label = text(t); }
      if (!label) {
        var cart = document.querySelector('#awa-minicart-panel .minicart-title, #minicart-content-wrapper .minicart-title, .block-minicart .title, .block-minicart .block-title');
        label = cart ? text(cart) : '';
      }
      label = (label || '').replace(/\s*(Fechar|Close|×)\s*$/i, '').trim();
      d.setAttribute('aria-label', label || 'Meu Carrinho');
    }
  }
  function fixCategoryAria() {
    var itens = document.querySelectorAll('.awa-category-carousel__item[aria-label], #awa-cat-carousel .awa-category-carousel__item');
    for (var i = 0; i < itens.length; i++) {
      var a = itens[i];
      var label = (a.getAttribute('aria-label') || '').trim();
      var vis = text(a);
      if (!vis) { continue; }
      if (!label) { a.setAttribute('aria-label', vis); continue; }
      if (/^\d+\s+de\s+\d+$/.test(label)) {
        a.setAttribute('aria-label', vis + ' (' + label + ')');
      } else if (label.indexOf(vis) === -1) {
        a.setAttribute('aria-label', vis + ' — ' + label);
      }
    }
  }
  function fixChatHref() {
    var links = document.querySelectorAll('.awa-ai-chat__product:not([href])');
    for (var i = 0; i < links.length; i++) { links[i].setAttribute('href', '/'); }
  }
  function all() {
    try { fixDialogs(); } catch (e) {}
    try { fixCategoryAria(); } catch (e) {}
    try { fixChatHref(); } catch (e) {}
  }
  function boot() {
    all();
    if (window.MutationObserver) {
      try {
        new MutationObserver(function () { all(); }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-label', 'href', 'role', 'class'] });
      } catch (e) {}
    }
    var n = 0;
    var t = window.setInterval(function () { all(); if (++n > 20) { window.clearInterval(t); } }, 1000);
    window.addEventListener('load', all, { once: true });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot, { once: true }); } else { boot(); }
})();
