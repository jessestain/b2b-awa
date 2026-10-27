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

        // VS5g (ciclo 2, item C): leitura de scrollPosition por rAF custava
        // ~270ms de forced reflow no trace (a leitura obrigava o layout após
        // escritas de outros scripts a cada frame de scroll). Com
        // IntersectionObserver num sentinela no topo da página + rootMargin
        // POSITIVO = threshold (expande a área de observação para cima), o
        // callback dispara SOMENTE quando o scroll cruza o limiar — zero
        // leituras por scroll. Fallback: caminho rAF anterior quando IO não
        // existe.
        if ('IntersectionObserver' in window) {
            let sentinel = document.createElement('div');
            sentinel.setAttribute('aria-hidden', 'true');
            sentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:1px;visibility:hidden;';
            (document.body || document.documentElement).appendChild(sentinel);

            let observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        hideButton();
                    } else {
                        showButton();
                    }
                });
            }, { rootMargin: threshold + 'px 0px 0px 0px' });

            observer.observe(sentinel);
        } else {
            // Fallback: coalescer em 1 leitura por frame via requestAnimationFrame.
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
        }

        element.addEventListener('click', () => {
            const prefersReducedMotion = window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth'});
        });
    };
});
