/**
 * AWA Motos — Back to Top Button
 * RequireJS widget: mostra/esconde botão e scroll suave ao topo.
 */
define([], function () {
    'use strict';

    return function (config, element) {
        if (!element) {
            return;
        }

        const threshold = config.threshold || 600;
        let shown = false;

        function showButton() {
            element.hidden = false;
            element.removeAttribute('hidden');
            element.setAttribute('aria-hidden', 'false');
            element.classList.add('is-visible');
            shown = true;
        }

        function hideButton() {
            element.hidden = true;
            element.setAttribute('hidden', '');
            element.setAttribute('aria-hidden', 'true');
            element.classList.remove('is-visible');
            shown = false;
        }

        function toggle() {
            const y = window.pageYOffset || document.documentElement.scrollTop;

            if (y > threshold && !shown) {
                showButton();
            } else if (y <= threshold && shown) {
                hideButton();
            }
        }

        // Keep only the new AWA button active; hide legacy fixed-right scroll-top item.
        document.querySelectorAll('.fixed-right .scroll-top, .fixed-right-ul .scroll-top').forEach((legacyNode) => {
            legacyNode.style.display = 'none';
        });

        hideButton();

        // PERF 08/10/2026 — `toggle` estava ligado DIRETO no scroll, sem throttle. Ele le
  // `pageYOffset` (forca reflow se houver estilo pendente) e escreve atributos/classe.
  // Medido com throttle do PSI: `awa-back-to-top.js` custava *** 1.397ms *** de main
  // thread bloqueada — desproporcional para um botao. A causa e layout thrashing ENTRE
  // scripts: outros (minicart, carrossel) escrevem estilo a cada frame e esta leitura
  // obrigava o navegador a recalcular o layout a cada evento de scroll (milhares no
  // mobile). Correcao: coalescer em 1 leitura por frame via requestAnimationFrame.
  // O comportamento visual e identico.
  let scrollRafPendente = false;
  function onScrollThrottled() {
    if (scrollRafPendente) {
      return;
    }
    scrollRafPendente = true;
    window.requestAnimationFrame(function () {
      scrollRafPendente = false;
      toggle();
    });
  }

  window.addEventListener('scroll', onScrollThrottled, {passive: true});

        element.addEventListener('click', () => {
            const prefersReducedMotion = window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth'});
        });

        toggle();
    };
});
