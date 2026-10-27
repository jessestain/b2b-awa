(function (d, w) {
    /* Visual Autopilot 2026-07-02: fix do hero slider deslocado -5px em desktop/tablet.
       O full-bleed do hero usa width:100vw + margin-left:calc(50% - 50vw)
       (awa-home-standardize-terminal-wins-2026-06-09.css). `100vw` inclui a faixa da
       scrollbar vertical, mas o `left`/`margin` percentual do truque resolve contra o
       clientWidth do <html> (que a exclui) — a diferença desloca o slider em metade da
       largura da scrollbar (~5px, medido ao vivo). Expomos a diferença real como custom
       property para a regra de full-bleed compensar via calc(); fallback 0px preserva o
       comportamento antigo se JS falhar. Não pode ir em awa-head-preload.phtml: o plugin
       GrupoAwamotos\Theme\Plugin\Response\OptimizeHeadStylesPlugin reescreve/descarta
       <script>/<style> ad-hoc injetados no <head> daquele template.
       r45: NÃO setProperty no parse — forçava style recalc sync ~2.3s (LCP ~2.8s).
       Adia para load+rAF; --awa-scrollbar-w default 0px até lá (scrollbar-gutter:stable). */
    'use strict';

    function applyScrollbarVar() {
        var sw = w.innerWidth - d.documentElement.clientWidth;
        d.documentElement.style.setProperty('--awa-scrollbar-w', Math.max(sw, 0) + 'px');
    }
    function scheduleApply() {
if (w.requestAnimationFrame) {
            w.requestAnimationFrame(function () {
                w.requestAnimationFrame(applyScrollbarVar);
            });
        } else {
            w.setTimeout(applyScrollbarVar, 0);
        }
    }
    if (d.readyState === 'complete') {
        scheduleApply();
    } else {
        w.addEventListener('load', scheduleApply, { once: true });
    }
    /* VS5h (ciclo 2, item C): resize coalescido a 1 apply por frame — o
       evento dispara em rajada (barra de URL mobile) e cada apply fazia 2
       leituras de layout (innerWidth + clientWidth) ~180ms de reflow no trace. */
    var pending = false;
    function agendar() {
        if (pending) { return; }
        pending = true;
        var roda = function () {
            pending = false;
            applyScrollbarVar();
        };
        if (w.requestAnimationFrame) { w.requestAnimationFrame(roda); } else { w.setTimeout(roda, 16); }
    }
    w.addEventListener('resize', agendar, { passive: true });
})(document, window);
