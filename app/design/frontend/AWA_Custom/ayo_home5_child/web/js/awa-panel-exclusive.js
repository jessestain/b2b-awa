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
            try { doc.body.click(); } catch (e) { /* noop */ }
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
