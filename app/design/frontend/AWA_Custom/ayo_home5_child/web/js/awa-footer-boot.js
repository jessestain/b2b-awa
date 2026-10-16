(function (w, d) {
    'use strict';

    function isFooterMobile() {
        return typeof w.matchMedia === 'function' && w.matchMedia('(max-width: 767px)').matches;
    }

    function syncFooterToggleAria() {
        var mobile = isFooterMobile();
        d.querySelectorAll('button.awa-footer-section__toggle').forEach(function (btn) {
            if (!mobile && btn.getAttribute('aria-expanded') !== 'true') {
                btn.setAttribute('aria-expanded', 'true');
            }
            if (mobile && btn.getAttribute('aria-expanded') !== 'true') {
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function syncFooterCategoriesShell() {
        var mobile = isFooterMobile();

        d.querySelectorAll('[data-awa-categories-toggle]').forEach(function (btn) {
            var panelId = btn.getAttribute('aria-controls');
            var panel = panelId ? d.getElementById(panelId) : null;

            if (!panel) {
                return;
            }

            if (mobile) {
                if (btn.getAttribute('aria-expanded') !== 'true') {
                    btn.setAttribute('aria-expanded', 'false');
                    btn.classList.remove('is-expanded');
                    panel.setAttribute('aria-hidden', 'true');
                }
                return;
            }

            btn.setAttribute('aria-expanded', 'true');
            btn.classList.add('is-expanded');
            panel.removeAttribute('hidden');
            panel.removeAttribute('inert');
            panel.setAttribute('aria-hidden', 'false');
        });
    }

    syncFooterToggleAria();
    syncFooterCategoriesShell();
    if (typeof w.matchMedia === 'function') {
        var mq = w.matchMedia('(max-width: 767px)');
        var onBreakpoint = function () {
            syncFooterToggleAria();
            syncFooterCategoriesShell();
        };
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onBreakpoint);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onBreakpoint);
        }
    }

    var bootStarted = false;

    function bootFooterInteractions() {
        if (bootStarted || w.__awaFooterInteractionsHomeInit) {
            return;
        }

        var footer = d.querySelector('.page_footer, .page-footer');
        var cfgNode = d.getElementById('awa-footer-interactions-config-json');
        if (!footer || !cfgNode || !cfgNode.textContent) {
            return;
        }

        bootStarted = true;

        var run = function () {
            if (footer.getAttribute('data-awa-footer-interactions-boot') === '1') {
                return;
            }

            w.require(['awaFooterInteractions'], function (footerInteractions) {
                var config;
                try {
                    config = JSON.parse(cfgNode.textContent);
                } catch (e) {
                    return;
                }

                footer.setAttribute('data-awa-footer-interactions-boot', '1');
                footerInteractions(config, footer);
            });
        };

        if (typeof w.awaRunWhenRequire === 'function') {
            w.awaRunWhenRequire(run, { key: 'footer-interactions-critical' });
        } else if (typeof w.require === 'function' && !w.require._awaStub) {
            run();
        } else {
            var pollAttempts = 0;
            var pollTimer = w.setInterval(function () {
                pollAttempts += 1;
                if (typeof w.require === 'function' && !w.require._awaStub) {
                    w.clearInterval(pollTimer);
                    run();
                    return;
                }
                if (pollAttempts >= 80) {
                    w.clearInterval(pollTimer);
                }
            }, 250);
        }
    }

    function scheduleFooterBoot() {
        var footer = d.querySelector('.page_footer, .page-footer');
        if (!footer) {
            return;
        }

        footer.addEventListener('click', function (evt) {
            if (!evt.target || !evt.target.closest) {
                return;
            }
            if (evt.target.closest('.awa-footer-section__toggle, .awa-footer-categories-expand__toggle')) {
                bootFooterInteractions();
            }
        }, { capture: true, passive: true });

        if ('IntersectionObserver' in w) {
            var observer = new w.IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    observer.disconnect();
                    bootFooterInteractions();
                });
            }, { rootMargin: '320px 0px 0px 0px' });
            observer.observe(footer);
            return;
        }

        bootFooterInteractions();
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', scheduleFooterBoot, { once: true });
    } else {
        scheduleFooterBoot();
    }
}(window, document));
/*§boot-r16d*/


/* ============================================================================
   AWA footer toggle semantics 2026-10-12
   Em >=768px o tema deixa o toggle do rodapé INERTE por CSS (min-height:0 +
   pointer-events:none + cursor:default), mas o <button> continuava FOCÁVEL com
   aria-expanded -> controle "morto" no teclado e no leitor de tela.
   Aqui a semântica segue o comportamento: desktop = fora do fluxo de foco e sem
   semântica de botão; mobile = restaura (acordeão funcional, alvo >=44px pelo CSS).
   ============================================================================ */
(function (w, d) {
    'use strict';
    if (w.__awaFooterToggleSemantics) { return; }
    w.__awaFooterToggleSemantics = true;
    var CORTE = 768;

    function toggles() {
        return Array.prototype.slice.call(d.querySelectorAll('.awa-footer-section__toggle'));
    }
    function guardar(el) {
        if (el.hasAttribute('data-awa-desk')) { return; }
        el.setAttribute('data-awa-desk', '1');
        el.setAttribute('data-awa-prev-tab', el.hasAttribute('tabindex') ? el.getAttribute('tabindex') : '__none__');
        el.setAttribute('data-awa-prev-exp', el.hasAttribute('aria-expanded') ? el.getAttribute('aria-expanded') : '__none__');
        el.setAttribute('data-awa-prev-ctrl', el.hasAttribute('aria-controls') ? el.getAttribute('aria-controls') : '__none__');
    }
    function desktop(el) {
        guardar(el);
        el.setAttribute('tabindex', '-1');
        el.setAttribute('disabled', 'disabled');
        el.removeAttribute('aria-expanded');
        el.removeAttribute('aria-controls');
    }
    function mobile(el) {
        if (!el.hasAttribute('data-awa-desk')) { return; }
        el.removeAttribute('data-awa-desk');
        el.removeAttribute('disabled');
        var t = el.getAttribute('data-awa-prev-tab');
        if (t === null || t === '__none__') { el.removeAttribute('tabindex'); } else { el.setAttribute('tabindex', t); }
        var e = el.getAttribute('data-awa-prev-exp');
        if (e === null || e === '__none__') { el.removeAttribute('aria-expanded'); } else { el.setAttribute('aria-expanded', e); }
        var c = el.getAttribute('data-awa-prev-ctrl');
        if (c === null || c === '__none__') { el.removeAttribute('aria-controls'); } else { el.setAttribute('aria-controls', c); }
        el.removeAttribute('data-awa-prev-tab');
        el.removeAttribute('data-awa-prev-exp');
        el.removeAttribute('data-awa-prev-ctrl');
    }
    function sync() {
        var ehDesktop = w.innerWidth >= CORTE;
        toggles().forEach(function (el) { if (ehDesktop) { desktop(el); } else { mobile(el); } });
    }

    sync();
    if (w.matchMedia) {
        var mq = w.matchMedia('(min-width: ' + CORTE + 'px)');
        if (mq.addEventListener) { mq.addEventListener('change', sync); } else if (mq.addListener) { mq.addListener(sync); }
    }
    w.addEventListener('resize', sync, { passive: true });
    if ('MutationObserver' in w && d.body) {
        /* FIX 12/10/2026 (TBT): o observer no BODY inteiro (childList + subtree) chamava sync()
         * DIRETO => cada insercao/remocao em QUALQUER lugar do body disparava um sync completo
         * (innerWidth + querySelectorAll dos toggles + escritas). Perfil medido: 1.992ms numa
         * unica carga do PDP (o maior custo de TEMA depois do parse).
         * Agora o callback AGENDA um sync por frame (rAF): N mutacoes num quadro = 1 sync.
         * O comportamento observavel nao muda — o sync continua reagindo a qualquer mudanca,
         * so deixa de rodar N vezes para a mesma rajada. */
        /* FIX 12/10/2026 (TBT): o observer no BODY inteiro (childList + subtree) chamava sync()
         * DIRETO => cada insercao/remocao em QUALQUER lugar do body disparava um sync completo
         * (innerWidth + querySelectorAll dos toggles + escritas). Perfil medido: 1.992-4.586ms
         * numa unica carga do PDP.
         * 1a tentativa: debounce por rAF => coalescia RAJADAS, mas mutacoes ESPALHADAS no tempo
         * (o caso do PDP, que monta componentes ao longo de segundos) continuavam disparando um
         * sync cada. Agora e um THROTTLE: no maximo 1 sync a cada 250ms, com o ultimo sempre
         * executando (leading + trailing). O comportamento observavel nao muda. */
        var syncAgendado = false;
        var syncUltimo = 0;
        var INTERVALO_MIN = 250;
        var agendarSync = function () {
            if (syncAgendado) { return; }
            syncAgendado = true;
            var espera = Math.max(0, INTERVALO_MIN - (Date.now() - syncUltimo));
            w.setTimeout(function () {
                syncAgendado = false;
                syncUltimo = Date.now();
                sync();
            }, espera);
        };
        new w.MutationObserver(agendarSync).observe(d.body, { childList: true, subtree: true });
    }
}(window, document));