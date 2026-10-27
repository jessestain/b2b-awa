(function (w, d) {
    'use strict';

    if (w.__awaHeroAutoplayFix) {
        return;
    }

    var tentativas = 0;
    /* VS5e (ciclo 2, item C): a visibilidade do hero NAO muda durante o boot —
       medir getBoundingClientRect a cada tentativa (8x2 swipers + eventos)
       somava ~642ms de forced reflow no trace. Medicao memoizada: 1 unica
       leitura; as tentativas seguintes so checam `el.swiper` (sem layout). */
    var candidatosCache = null;

    /* PERF-2026-10-24: timers rastreados — ao aplicar com sucesso, os timers
       pendentes (backoff + 1500ms) são cancelados; antes, a tentativa de 1500ms
       ainda disparava e fazia stop()+start() de novo no autoplay do hero. */
    var timers = [];
    var visListener = false;
    /* PERF-2026-10-24 (re-init safe): o tema pode recriar o swiper do hero
       (breakpoint). O handler de visibilidade consulta o swiper ATUAL via
       `el.swiper` a cada evento, em vez de fechar sobre a instância antiga. */
    var estado = { el: null, pausadoPorTab: false };

    var reducedQuery = w.matchMedia ? w.matchMedia('(prefers-reduced-motion: reduce)') : null;
    function prefersReducedMotion() {
        return reducedQuery ? reducedQuery.matches : false;
    }

    function agendar(fn, ms) {
        var id = w.setTimeout(fn, ms);
        timers.push(id);
        return id;
    }
    function cancelarTimers() {
        for (var i = 0; i < timers.length; i += 1) { w.clearTimeout(timers[i]); }
        timers = [];
    }

    function visivel(el) {
        if (!el) { return false; }
        var r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0;
    }

    function candidatos() {
        if (candidatosCache === null) {
            candidatosCache = [].slice.call(d.querySelectorAll('.awa-hero-swiper')).filter(visivel);
        }
        return candidatosCache;
    }

    /* Contrato de autoplay do desktop num swiper dado (idempotente/re-init safe). */
    function aplicarSwiper(sw) {
        /* O tema trava `transition: none !important` nos slides (lock de first
         * paint — deliberado). O Swiper, com `waitForTransition: true`, poe
         * `paused = true` no `slideChangeTransitionStart` e espera um
         * `transitionend` que nunca chega: o autoplay para no 2o slide.
         * Conserto: nao esperar transicao. O CSS do tema fica intacto. */
        sw.params.autoplay.waitForTransition = false;
        try {
            sw.autoplay.stop();
        } catch (e) {
            return false;
        }

        /* A11y 2026-10-24: prefers-reduced-motion -> autoplay parado de fato
           (antes, o autoplay rodava mesmo com a preferência ativa). */
        if (prefersReducedMotion()) {
            try {
                sw.params.autoplay.enabled = false;
                sw.autoplay.stop();
            } catch (e) { /* mantém o comportamento anterior em caso de falha */ }
            w.__awaHeroAutoplayFix = 'autoplay-parado-reduced-motion';
            return true;
        }

        try {
            sw.autoplay.start();
        } catch (e) {
            return false;
        }
        return true;
    }

    function aplicar() {
        var el = candidatos()[0];
        if (!el || !el.swiper) { return false; }
        estado.el = el;

        /* AWA 2026-10-27: autoplay mobile reativado por requisito de negocio.
         * Aplica o MESMO contrato do desktop (waitForTransition=false por causa do
         * lock transition:none do tema) e continua respeitando prefers-reduced-motion
         * (aplicarSwiper para o autoplay nesse caso). */
        if (el.closest('.wrapper_slider.visible-xs')) {
            var swm = el.swiper;
            if (!swm) { return true; }
            if (!swm.params || !swm.params.autoplay || swm.params.autoplay === false) {
                w.__awaHeroAutoplayFix = 'autoplay-desligado-na-config';
                return true;
            }
            if (!aplicarSwiper(swm)) { return false; }
            w.__awaHeroAutoplayFix = 'aplicado';
            return true;
        }

        var sw = el.swiper;
        if (!sw.params.autoplay) {
            w.__awaHeroAutoplayFix = 'autoplay-desligado-na-config';
            return true;
        }

        if (!aplicarSwiper(sw)) { return false; }

        /* PERF 2026-10-24: pausa o autoplay com a aba oculta e retoma ao voltar.
           Swiper 11: `autoplay.running` reflete o estado real do timer.
           Re-init safe: usa `estado.el.swiper` (instância ATUAL) a cada evento. */
        if (!visListener) {
            visListener = true;
            d.addEventListener('visibilitychange', function () {
                var cur = estado.el && estado.el.swiper ? estado.el.swiper : null;
                if (!cur || !cur.autoplay) { return; }
                if (d.hidden) {
                    if (cur.autoplay.running === true) {
                        try { cur.autoplay.stop(); estado.pausadoPorTab = true; } catch (e) { /* silencioso */ }
                    }
                } else if (estado.pausadoPorTab) {
                    estado.pausadoPorTab = false;
                    if (cur.params && cur.params.autoplay && cur.params.autoplay.enabled !== false && !prefersReducedMotion()) {
                        try {
                            /* se o swiper foi recriado, re-asserta o contrato antes de religar */
                            if (cur.params.autoplay.waitForTransition !== false) { cur.params.autoplay.waitForTransition = false; }
                            cur.autoplay.start();
                        } catch (e) { /* silencioso */ }
                    }
                }
            });
        }

        // aplicaSwiper pode ter marcado "autoplay parado por reduced-motion";
        // nesse caso preserva o flag específico (v3: não sobrescreve com 'aplicado').
        if (w.__awaHeroAutoplayFix !== 'autoplay-parado-reduced-motion') {
            w.__awaHeroAutoplayFix = 'aplicado';
        }
        return true;
    }

    if (aplicar()) { cancelarTimers(); return; }

    /* PERF VS4 (17/10/2026): o polling era `setInterval(100ms)` por ate 200
       tentativas (20s). Cada tentativa fazia getBoundingClientRect() no hero,
       gerando forced reflow repetido durante o carregamento (medido: 296-477ms
       no trace). Agora: backoff exponencial, no maximo 8 tentativas (~6s) e
       tentativas tambem nos eventos de load/DOMContentLoaded. */
    var atraso = 100;
    function tentar() {
        tentativas += 1;
        if (aplicar()) { cancelarTimers(); return; }
        if (tentativas >= 8) { return; }
        atraso = Math.min(1600, atraso * 2);
        agendar(tentar, atraso);
    }
    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', tentar, { once: true });
    } else {
        agendar(tentar, 100);
    }
    d.addEventListener('load', tentar, { once: true });
    agendar(tentar, 1500);
}(window, document));
