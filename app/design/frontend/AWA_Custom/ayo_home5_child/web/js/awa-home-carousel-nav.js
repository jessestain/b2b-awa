/**
 * AWA Motos — cheap init das setas do carrossel de prateleiras da home.
 *
 * POR QUE ESTE ARQUIVO EXISTE
 * O awa-scroll-carousel.js foi removido da home de propósito
 * (Magento_Theme/layout/cms_index_index.xml, "Isolation r4") porque causava um
 * longtask de ~2.3s que atrapalhava o LCP do hero. O CSS scroll-snap ficou, então
 * arrastar/tocar funciona — mas as setas, o autoplay e a barra de progresso
 * morreram junto, e o próprio layout deixou escrito:
 *
 *     "No JS arrows until a cheap init is re-enabled."
 *
 * Este é esse init. O que ele NÃO faz (era o que custava caro):
 *   - não faz querySelectorAll global nem varredura de DOM no load
 *   - não usa MutationObserver
 *   - não mede getBoundingClientRect em loop
 *   - não roda nada antes do primeiro scroll/intent (senão volta o problema de LCP)
 *
 * O que ele faz, por prateleira, só quando ela entra na viewport:
 *   - liga as setas ‹ › a um scroll suave de uma "página" (largura do viewport)
 *   - mantém o estado disabled das setas correto
 *   - atualiza a barra de progresso
 *   - navegação por teclado (setas ← → e Home/End), que o viewport já anuncia
 *
 * Autoplay: NÃO. Ele exige timers + IntersectionObserver permanentes e é a maior
 * parte do custo do motor antigo. Se o dono quiser de volta, é um acréscimo
 * isolado e mensurável.
 *
 * Custo estimado: um listener de scroll por viewport, passivo, com rAF.
 */
(function (window, document) {
    'use strict';

    var SHELF_SELECTOR = '.awa-shelf--carousel';
    var VIEWPORT_SELECTOR = '.awa-carousel__viewport';
    var SLIDE_SELECTOR = '.awa-carousel__slide';
    var READY_FLAG = 'awaCheapInit';

    /** Uma "página" = o que cabe no viewport. Cai para 0.8× se não houver slide medível. */
    function pageWidth(viewport) {
        var slide = viewport.querySelector(SLIDE_SELECTOR);
        if (slide && slide.offsetWidth > 0) {
            var perView = Math.max(1, Math.floor(viewport.clientWidth / slide.offsetWidth));
            return slide.offsetWidth * perView;
        }
        return Math.round(viewport.clientWidth * 0.8);
    }

    function maxScroll(viewport) {
        return Math.max(0, viewport.scrollWidth - viewport.clientWidth);
    }

    /** Sincroniza o estado visual das setas e a barra de progresso. */
    function syncChrome(state) {
        var vp = state.viewport;
        var max = maxScroll(vp);
        var x = vp.scrollLeft;
        var atStart = x <= 2;
        var atEnd = x >= max - 2;

        if (state.prev) {
            state.prev.disabled = atStart || max <= 10;
            state.prev.setAttribute('aria-disabled', (atStart || max <= 10) ? 'true' : 'false');
        }
        if (state.next) {
            state.next.disabled = atEnd || max <= 10;
            state.next.setAttribute('aria-disabled', (atEnd || max <= 10) ? 'true' : 'false');
        }

        if (state.bar) {
            var ratio = max > 0 ? Math.min(1, Math.max(0, x / max)) : 0;
            var perView = 1;
            var slide = vp.querySelector(SLIDE_SELECTOR);
            if (slide && slide.offsetWidth > 0) {
                perView = Math.min(1, (vp.clientWidth / slide.offsetWidth) / Math.max(1, vp.querySelectorAll(SLIDE_SELECTOR).length));
            }
            var w = Math.max(8, Math.round((perView + (1 - perView) * ratio) * 100));
            state.bar.style.width = w + '%';
        }
    }

    function step(state, direction) {
        var vp = state.viewport;
        var alvo = vp.scrollLeft + direction * pageWidth(vp);
        vp.scrollTo({
            left: Math.max(0, Math.min(maxScroll(vp), alvo)),
            behavior: 'smooth'
        });
    }

    function bind(state) {
        if (state.root.dataset[READY_FLAG] === '1') {
            return;
        }
        state.root.dataset[READY_FLAG] = '1';
        state.root.classList.add('awa-carousel-ready');

        if (state.prev) {
            state.prev.addEventListener('click', function (e) { e.preventDefault(); step(state, -1); });
        }
        if (state.next) {
            state.next.addEventListener('click', function (e) { e.preventDefault(); step(state, 1); });
        }

        // Teclado: o viewport já tem aria-keyshortcuts="ArrowLeft ArrowRight Home End"
        state.viewport.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); step(state, 1); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); step(state, -1); }
            else if (e.key === 'Home') { e.preventDefault(); state.viewport.scrollTo({ left: 0, behavior: 'smooth' }); }
            else if (e.key === 'End') { e.preventDefault(); state.viewport.scrollTo({ left: maxScroll(state.viewport), behavior: 'smooth' }); }
        });

        // Um único listener passivo, com rAF — sem medir nada no handler
        var pendente = false;
        state.viewport.addEventListener('scroll', function () {
            if (pendente) { return; }
            pendente = true;
            window.requestAnimationFrame(function () {
                pendente = false;
                syncChrome(state);
            });
        }, { passive: true });

        window.addEventListener('resize', function () {
            window.requestAnimationFrame(function () { syncChrome(state); });
        }, { passive: true });

        syncChrome(state);
    }

    function preparar(shelf) {
        var vp = shelf.querySelector(VIEWPORT_SELECTOR);
        if (!vp) {
            return null;
        }
        var nav = shelf.querySelector('.awa-carousel__nav, .awa-owl-nav');
        return {
            root: shelf,
            viewport: vp,
            prev: nav ? nav.querySelector('.awa-owl-nav__btn--prev') : null,
            next: nav ? nav.querySelector('.awa-owl-nav__btn--next') : null,
            bar: shelf.querySelector('.awa-owl-progress__bar')
        };
    }

    /**
     * Só inicializa quando a prateleira chega perto da tela — assim o primeiro
     * paint da home fica livre, que era o motivo da remoção original.
     */
    function observar(shelf) {
        if (typeof IntersectionObserver === 'undefined') {
            var s = preparar(shelf);
            if (s) { bind(s); }
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) { return; }
                io.unobserve(entry.target);
                var s = preparar(entry.target);
                if (s) { bind(s); }
            });
        }, { rootMargin: '200px 0px', threshold: 0.01 });
        io.observe(shelf);
    }

    function boot() {
        var shelves = document.querySelectorAll(SHELF_SELECTOR);
        for (var i = 0; i < shelves.length; i += 1) {
            if (shelves[i].dataset[READY_FLAG] === '1') { continue; }
            observar(shelves[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}(window, document));


/* ============================================================================
   AWA home carousel contract 2026-10-12 — o motor global (awa-scroll-carousel.js)
   NAO roda na home por decisao do tema, entao a chrome SSR cobria so o container.
   Aqui, por viewport: rotulo "N de M" + aria-roledescription por slide, aria-hidden e
   tabindex=-1 nos slides fora da caixa, progressbar com aria-valuenow/aria-valuetext e
   --awa-progress, e live region com o nome do primeiro produto visivel.
   Idempotente, passivo (rAF), sem dependencias.
   ============================================================================ */
(function (w, d) {
    'use strict';
    if (w.__awaHomeCarouselContract) { return; }
    w.__awaHomeCarouselContract = true;
    if (!d.body || !/(^|\s)(cms-index-index|cms-home|cms-homepage_ayo_home5)(\s|$)/.test(d.body.className)) { return; }

    function visiveis(vp, slides) {
        var r = vp.getBoundingClientRect();
        return slides.filter(function (s) {
            var q = s.getBoundingClientRect();
            return q.width > 1 && q.right > r.left + 8 && q.left < r.right - 8;
        });
    }

    function controles(el) {
        var lista = [];
        if (el.matches && el.matches('a[href], button, input, select, textarea, [tabindex]')) { lista.push(el); }
        Array.prototype.forEach.call(el.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'), function (c) { lista.push(c); });
        return lista;
    }

    function ligar(vp) {
        if (!vp || vp.getAttribute('data-awa-contract') === '1') { return; }
        var slides = Array.prototype.slice.call(vp.querySelectorAll('.awa-carousel__slide, .awa-category-carousel__item'));
        if (slides.length < 2) { return; }
        vp.setAttribute('data-awa-contract', '1');

        var raiz = vp.closest('.awa-shelf, .awa-carousel-section, section') || vp.parentElement;
        var progresso = raiz ? raiz.querySelector('.awa-owl-progress') : null;
        var live = raiz ? raiz.querySelector('.awa-carousel-live') : null;
        var barra = progresso ? progresso.querySelector('.awa-owl-progress__bar') : null;
        var total = slides.length;

        function pintar() {
            var vis = visiveis(vp, slides);
            var max = Math.max(0, vp.scrollWidth - vp.clientWidth);
            var fracao = max > 0 ? Math.min(1, Math.max(0, vp.scrollLeft / max)) : 1;
            var primeiro = vis.length ? slides.indexOf(vis[0]) + 1 : 1;

            slides.forEach(function (s, i) {
                var dentro = vis.indexOf(s) >= 0;
                if (!s.getAttribute('aria-roledescription')) { s.setAttribute('aria-roledescription', 'slide'); }
                var rotulo = (i + 1) + ' de ' + total;
                if (s.getAttribute('aria-label') !== rotulo) { s.setAttribute('aria-label', rotulo); }
                s.setAttribute('aria-hidden', dentro ? 'false' : 'true');
                controles(s).forEach(function (c) {
                    if (dentro) {
                        if (c.hasAttribute('data-awa-orig-tab')) {
                            var o = c.getAttribute('data-awa-orig-tab');
                            if (o === '__none__') { c.removeAttribute('tabindex'); } else { c.setAttribute('tabindex', o); }
                            c.removeAttribute('data-awa-orig-tab');
                        }
                    } else if (!c.hasAttribute('data-awa-orig-tab')) {
                        c.setAttribute('data-awa-orig-tab', c.hasAttribute('tabindex') ? c.getAttribute('tabindex') : '__none__');
                        c.setAttribute('tabindex', '-1');
                    }
                });
            });

            if (progresso) {
                progresso.setAttribute('aria-valuenow', String(Math.round(fracao * 100)));
                progresso.setAttribute('aria-valuetext', 'Item ' + primeiro + ' de ' + total);
            }
            if (barra) { barra.style.setProperty('--awa-progress', String(Math.max(0.08, fracao))); }
            if (live && vis.length) {
                var alvo = vis[0].querySelector('.product-name a, .product-name, .awa-cat-tile__name, .awa-category-carousel__label, .awa-b2b-sku');
                var nome = alvo && alvo.textContent ? alvo.textContent.replace(/\s+/g, ' ').trim().slice(0, 120) : '';
                if (nome && live.textContent !== nome) { live.textContent = nome; }
            }
        }

        var agendado = false;
        function agendar() {
            if (agendado) { return; }
            agendado = true;
            var roda = function () { agendado = false; pintar(); };
            if (w.requestAnimationFrame) { w.requestAnimationFrame(roda); } else { w.setTimeout(roda, 16); }
        }

        vp.addEventListener('scroll', agendar, { passive: true });
        w.addEventListener('resize', agendar, { passive: true });
        pintar();
        w.setTimeout(pintar, 600);
        w.setTimeout(pintar, 2000);
    }

    function varrer() {
        Array.prototype.forEach.call(d.querySelectorAll('.awa-carousel__viewport, .awa-category-carousel__viewport'), ligar);
    }

    varrer();
    if ('MutationObserver' in w) {
        var t = null;
        new w.MutationObserver(function () {
            if (t) { w.clearTimeout(t); }
            t = w.setTimeout(varrer, 300);
        }).observe(d.body, { childList: true, subtree: true });
    }
}(window, document));