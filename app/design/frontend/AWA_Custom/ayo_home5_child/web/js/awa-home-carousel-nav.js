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
 *
 * PERF-2026-10-24 (INP/reflow) — sem mudança de comportamento:
 *   - geometria (slideW, slideN, clientW, maxScroll, pageW) medida numa ÚNICA
 *     batida de leituras por prateleira e cacheada; revalidada apenas em resize
 *     real/load — as setas não medem mais nada por clique;
 *   - resize coalescido: no máximo 1 rAF pendente por prateleira;
 *   - escritas de chrome (disabled/aria/width) memoizadas: só escreve quando o
 *     estado realmente muda;
 *   - scrollTo respeita prefers-reduced-motion (behavior auto);
 *   - um único listener de load global em vez de um por prateleira.
 */
(function (window, document) {
    'use strict';

    var SHELF_SELECTOR = '.awa-shelf--carousel';
    var VIEWPORT_SELECTOR = '.awa-carousel__viewport';
    var SLIDE_SELECTOR = '.awa-carousel__slide';
    var READY_FLAG = 'awaCheapInit';
    var boundStates = [];

    var reducedQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    function prefersReducedMotion() {
        return reducedQuery ? reducedQuery.matches : false;
    }
    function scrollBehavior() {
        return prefersReducedMotion() ? 'auto' : 'smooth';
    }

    /** Batida única de leituras de layout (revalidada só em resize/load). */
    function medir(state) {
        var vp = state.viewport;
        var slide = vp.querySelector(SLIDE_SELECTOR);
        var slideW = slide ? slide.offsetWidth : 0;
        var clientW = vp.clientWidth;
        var max = Math.max(0, vp.scrollWidth - clientW);
        var pageW;
        if (slideW > 0) {
            var perView = Math.max(1, Math.floor(clientW / slideW));
            pageW = slideW * perView;
        } else {
            pageW = Math.round(clientW * 0.8);
        }
        state._slideW = slideW;
        state._slideN = vp.querySelectorAll(SLIDE_SELECTOR).length;
        state._clientW = clientW;
        state._maxScroll = max;
        state._pageW = pageW;
    }

    function maxScroll(state) {
        if (state._maxScroll == null) { medir(state); }
        return state._maxScroll;
    }

    /** Sincroniza o estado visual das setas e a barra de progresso (memoizado). */
    function syncChrome(state) {
        var vp = state.viewport;
        if (state._maxScroll == null) { medir(state); }
        var max = state._maxScroll;
        var x = vp.scrollLeft;
        var atStart = x <= 2;
        var atEnd = x >= max - 2;

        if (state.prev) {
            var pd = atStart || max <= 10;
            if (state._lastPrevDisabled !== pd) {
                state._lastPrevDisabled = pd;
                state.prev.disabled = pd;
                state.prev.setAttribute('aria-disabled', pd ? 'true' : 'false');
            }
        }
        if (state.next) {
            var nd = atEnd || max <= 10;
            if (state._lastNextDisabled !== nd) {
                state._lastNextDisabled = nd;
                state.next.disabled = nd;
                state.next.setAttribute('aria-disabled', nd ? 'true' : 'false');
            }
        }

        if (state.bar) {
            var ratio = max > 0 ? Math.min(1, Math.max(0, x / max)) : 0;
            var perView = 1;
            if (state._slideW > 0) {
                perView = Math.min(1, (state._clientW / state._slideW) / Math.max(1, state._slideN));
            }
            var w = Math.max(8, Math.round((perView + (1 - perView) * ratio) * 100));
            if (state._lastBarW !== w) {
                state._lastBarW = w;
                state.bar.style.width = w + '%';
            }
        }
    }

    function step(state, direction) {
        var vp = state.viewport;
        if (state._maxScroll == null) { medir(state); }
        var alvo = vp.scrollLeft + direction * state._pageW;
        vp.scrollTo({
            left: Math.max(0, Math.min(state._maxScroll, alvo)),
            behavior: scrollBehavior()
        });
    }

    function bind(state) {
        if (state.root.dataset[READY_FLAG] === '1') {
            return;
        }
        state.root.dataset[READY_FLAG] = '1';
        state.root.classList.add('awa-carousel-ready');
        boundStates.push(state);

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
            else if (e.key === 'Home') { e.preventDefault(); state.viewport.scrollTo({ left: 0, behavior: scrollBehavior() }); }
            else if (e.key === 'End') { e.preventDefault(); state.viewport.scrollTo({ left: maxScroll(state), behavior: scrollBehavior() }); }
        });

        // PERF 2026-10-24 r8: prateleiras fora da tela não pintam. O scroll-snap
        // nativo gera scroll contínuo até em prateleiras fora do viewport — cada
        // frame pintava (escritas de estilo/ARIA) e invalidava layout à toa.
        // IO persistente mantém o flag SEM leitura de layout por frame; na
        // reentrada, pinta imediatamente.
        state._inView = true;
        if (typeof IntersectionObserver !== 'undefined') {
            var visIO = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var wasIn = state._inView;
                    state._inView = entry.isIntersecting;
                    if (!wasIn && state._inView) { syncChrome(state); }
                });
            }, { rootMargin: '200px 0px', threshold: 0 });
            visIO.observe(state.viewport);
        }

        // Um único listener passivo, com rAF — sem medir nada no handler
        var pendente = false;
        state.viewport.addEventListener('scroll', function () {
            if (pendente || !state._inView) { return; }
            pendente = true;
            window.requestAnimationFrame(function () {
                pendente = false;
                if (state._inView) { syncChrome(state); }
            });
        }, { passive: true });

        // PERF: resize coalescido — no máximo 1 rAF pendente por prateleira
        // (o resize dispara em rajada, ex.: barra de URL do mobile).
        var resizePending = false;
        window.addEventListener('resize', function () {
            if (resizePending) { return; }
            resizePending = true;
            window.requestAnimationFrame(function () {
                resizePending = false;
                state._maxScroll = null;
                syncChrome(state);
            });
        }, { passive: true });

        syncChrome(state);
    }

    // PERF: um único listener de load global revalida todas as prateleiras
    // (antes: um listener por prateleira).
    window.addEventListener('load', function () {
        for (var i = 0; i < boundStates.length; i += 1) {
            boundStates[i]._maxScroll = null;
            syncChrome(boundStates[i]);
        }
    }, { once: true, passive: true });

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

   PERF-2026-10-24: por paint, o teste de visibilidade usa um set de índices em
   vez de slides.indexOf() dentro do loop (CPU, não layout); as escritas de ARIA
   continuam guardadas por chave de visibilidade (nada muda visualmente).
   ============================================================================ */
(function (w, d) {
    'use strict';
    if (w.__awaHomeCarouselContract) { return; }
    w.__awaHomeCarouselContract = true;
    if (!d.body || !/(^|\s)(cms-index-index|cms-home|cms-homepage_ayo_home5)(\s|$)/.test(d.body.className)) { return; }

    function controles(el) {
        var lista = [];
        if (el.matches && el.matches('a[href], button, input, select, textarea, [tabindex]')) { lista.push(el); }
        Array.prototype.forEach.call(el.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'), function (c) { lista.push(c); });
        return lista;
    }

    /* VS4h: geometria dos slides em coordenadas de scroll. FAST PATH: grade
       uniforme — mede o 1º slide (1 rect) + confere o último (1 rect); se a
       grade for uniforme, deriva o resto aritmeticamente (zero reads por slide).
       Fallback: medida por slide quando a grade não for uniforme. */
    function medirGeometria(vp, slides) {
        var r = vp.getBoundingClientRect();
        var x0 = vp.scrollLeft;
        var n = slides.length;
        var offsets = new Array(n);
        var i, q0, qn, uniforme = false, passo = 0;

        if (n) {
            q0 = slides[0].getBoundingClientRect();
            var left0 = q0.left - r.left + x0;
            var w0 = q0.width;
            if (n === 1) {
                offsets[0] = { left: left0, right: left0 + w0 };
            } else {
                qn = slides[n - 1].getBoundingClientRect();
                var leftN = qn.left - r.left + x0;
                var wN = qn.width;
                passo = (leftN - left0) / (n - 1);
                uniforme = Math.abs(qn.width - w0) <= 1.5 && Math.abs(((leftN + wN) - (left0 + w0)) - (passo * (n - 1))) <= 2;
            }
        }

        if (uniforme && n > 1) {
            for (i = 0; i < n; i += 1) {
                var L = left0 + i * passo;
                offsets[i] = { left: L, right: L + w0 };
            }
        } else {
            for (i = 0; i < n; i += 1) {
                var q = slides[i].getBoundingClientRect();
                var li = q.left - r.left + x0;
                offsets[i] = { left: li, right: li + q.width };
            }
        }

        return { offsets: offsets, clientW: vp.clientWidth, max: Math.max(0, vp.scrollWidth - vp.clientWidth) };
    }

    function visiveis(vp, slides, geo, scrollL) {
        var L = scrollL === undefined ? vp.scrollLeft : scrollL;
        var R = L + geo.clientW;
        var vis = [];
        for (var i = 0; i < slides.length; i += 1) {
            var o = geo.offsets[i];
            if ((o.right - o.left) > 1 && o.right > L + 8 && o.left < R - 8) { vis.push(slides[i]); }
        }
        return vis;
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
        var controlesCache = slides.map(controles);
        // PERF: índice por slide memoizado (evita slides.indexOf() dentro do loop).
        for (var idx0 = 0; idx0 < slides.length; idx0 += 1) { slides[idx0].__awaSlideIdx = idx0; }
        var ultimaChave = '';
        var geo = null;

        function refreshGeo() { geo = medirGeometria(vp, slides); }

        function pintar() {
            if (!geo) { refreshGeo(); }
            // VS5f: 1 unica leitura de scrollLeft por paint (antes: 3 — visiveis,
            // fracao e ultimaChave) — cada leitura forcava reflow apos os writes.
            var scrollL = vp.scrollLeft;
            var vis = visiveis(vp, slides, geo, scrollL);
            var max = geo.max;
            var fracao = max > 0 ? Math.min(1, Math.max(0, scrollL / max)) : 1;
            var primeiro = vis.length ? vis[0].__awaSlideIdx + 1 : 1;
            var chave = vis.map(function (s) { return s.__awaSlideIdx; }).join(',');
            var visSet = {};
            var vi;

            if (chave !== ultimaChave) {
                ultimaChave = chave;
                for (vi = 0; vi < vis.length; vi += 1) { visSet[vis[vi].__awaSlideIdx] = true; }
                slides.forEach(function (s, i) {
                    var dentro = visSet[i] === true;
                    if (!s.getAttribute('aria-roledescription')) { s.setAttribute('aria-roledescription', 'slide'); }
                    var rotulo = (i + 1) + ' de ' + total;
                    if (s.getAttribute('aria-label') !== rotulo) { s.setAttribute('aria-label', rotulo); }
                    s.setAttribute('aria-hidden', dentro ? 'false' : 'true');
                    (controlesCache[i] || []).forEach(function (c) {
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
            }

            var pct = String(Math.round(fracao * 100));
            if (progresso) {
                if (progresso.getAttribute('aria-valuenow') !== pct) { progresso.setAttribute('aria-valuenow', pct); }
                var vtext = 'Item ' + primeiro + ' de ' + total;
                if (progresso.getAttribute('aria-valuetext') !== vtext) { progresso.setAttribute('aria-valuetext', vtext); }
            }
            if (barra) {
                var prog = String(Math.max(0.08, fracao));
                if (barra.__awaProgress !== prog) { barra.__awaProgress = prog; barra.style.setProperty('--awa-progress', prog); }
            }
            if (live && vis.length) {
                var alvo = vis[0].querySelector('.product-name a, .product-name, .awa-cat-tile__name, .awa-category-carousel__label, .awa-b2b-sku');
                var nome = alvo && alvo.textContent ? alvo.textContent.replace(/\s+/g, ' ').trim().slice(0, 120) : '';
                if (nome && live.textContent !== nome) { live.textContent = nome; }
            }
        }

        var agendado = false;
        // PERF 2026-10-24 r8: paint só com o viewport perto da tela (o snap
        // nativo re-snapa até prateleiras fora do viewport, gerando scroll
        // contínuo; pintá-las por frame só invalidava estilo).
        var inView = true;
        if (typeof IntersectionObserver !== 'undefined') {
            var visIO2 = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var wasIn = inView;
                    inView = entry.isIntersecting;
                    if (!wasIn && inView) { agendar(); }
                });
            }, { rootMargin: '200px 0px', threshold: 0 });
            visIO2.observe(vp);
        }
        function agendar() {
            if (agendado) { return; }
            agendado = true;
            var roda = function () { agendado = false; if (inView) { pintar(); } };
            if (w.requestAnimationFrame) { w.requestAnimationFrame(roda); } else { w.setTimeout(roda, 16); }
        }
        function agendarRegeo() {
            // VS5f: coalesce resize/load por frame — o resize dispara em rajada
            // (barra de URL do mobile) e cada evento re-media ~60+ rects. Agora
            // no maximo 1 re-medida por frame.
            if (regeoAgendado) { return; }
            regeoAgendado = true;
            var roda = function () {
                regeoAgendado = false;
                geo = null;
                agendar();
            };
            if (w.requestAnimationFrame) { w.requestAnimationFrame(roda); } else { w.setTimeout(roda, 16); }
        }
        var regeoAgendado = false;

        vp.addEventListener('scroll', agendar, { passive: true });
        w.addEventListener('resize', agendarRegeo, { passive: true });
        w.addEventListener('load', agendarRegeo, { passive: true });
        // P5 (F23): adia a medicao/1o paint para idle - evita reflow sincrono no boot.
        function inicializar() { refreshGeo(); pintar(); }
        if (w.requestIdleCallback) { w.requestIdleCallback(inicializar, { timeout: 2000 }); }
        else { w.setTimeout(inicializar, 0); }
        // VS4h v2: rounds de settle = paint-only (geo revalidada só em resize/load);
        // o paint com offsets em cache não faz leitura de layout.
        w.setTimeout(agendar, 600);
        w.setTimeout(agendar, 2000);
    }

    function varrer() {
        Array.prototype.forEach.call(d.querySelectorAll('.awa-carousel__viewport, .awa-category-carousel__viewport'), ligar);
    }

    varrer();
    if ('MutationObserver' in w) {
        var t = null;
        // PERF-2026-10-19: o body muda constantemente (hidratacao do minicart, badges do
        // social proof, dots). Antes, QUALQUER mutacao agendava varrer() (querySelectorAll
        // no body inteiro + refreshGeo por viewport) a cada 300ms. Agora so re-varre quando
        // um viewport de carrossel novo entra/sai do DOM.
        new w.MutationObserver(function (records) {
            var relevante = false;
            for (var i = 0; i < records.length && !relevante; i += 1) {
                var r = records[i];
                if (r.type !== 'childList') { continue; }
                var nos = r.addedNodes;
                for (var j = 0; j < nos.length; j += 1) {
                    var n = nos[j];
                    if (!n || n.nodeType !== 1) { continue; }
                    if ((n.classList && (n.classList.contains('awa-carousel__viewport') || n.classList.contains('awa-category-carousel__viewport')))
                        || (n.querySelector && n.querySelector('.awa-carousel__viewport, .awa-category-carousel__viewport'))) {
                        relevante = true;
                        break;
                    }
                }
            }
            if (!relevante) { return; }
            if (t) { w.clearTimeout(t); }
            t = w.setTimeout(varrer, 300);
        }).observe(d.body, { childList: true, subtree: true });
    }
}(window, document));
