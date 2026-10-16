/**
 * AWA Motos — Home Category Carousel
 * RequireJS widget: scroll nav, swipe, dots, keyboard, entrance animation.
 * Inicializado via awa-home-category-carousel-init.phtml (idle + intent).
 * r22k: delegação de clique no root (nav no header).
 * r22l: esconde nav/dots quando não há scroll significativo.
 * r22m: re-sync idle após reveal/imagens (race do maxScroll).
 */
define([], function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function scrollBehavior() {
        return prefersReducedMotion() ? 'auto' : 'smooth';
    }

    return function (config, track) {
        let root;
        let scroller;
        let prev;
        let next;
        let dotsWrap;
        let items;
        let pageOffsets = [0];
        let startX = 0;
        let startScrollLeft = 0;
        let isDragging = false;
        let resizeTimer;
        let scrollRaf = 0;

        if (!track || track.dataset.awaCategoryCarouselInit === '1') {
            return;
        }

        track.dataset.awaCategoryCarouselInit = '1';

        root = track.closest('.top-home-content--category-carousel') || document;
        scroller = track.closest('.awa-category-carousel__viewport') || track;
        prev = root.querySelector('.awa-category-carousel__prev');
        next = root.querySelector('.awa-category-carousel__next');
        dotsWrap = root.querySelector('#awa-cat-dots');
        items = track.querySelectorAll('.awa-category-carousel__item');

        if (!items.length) {
            return;
        }

        scroller.style.touchAction = 'pan-x pan-y';
        scroller.style.overscrollBehaviorX = 'contain';
        if (!track.id) {
            track.id = 'awa-cat-carousel-track';
        }
        track.setAttribute('role', 'region');
        track.setAttribute('aria-roledescription', 'carrossel');
        track.setAttribute('aria-label', root.getAttribute('aria-label') || 'Carrossel de categorias');

        function getScrollAmount() {
            // Scroll by almost a full visible page to match dots logic.
            let scroll = scroller.clientWidth;
            let item = items[0];
            if (item) {
                let style = getComputedStyle(track);
                let gap = parseInt(style.gap, 10) || 16;
                let itemW = item.offsetWidth + gap;
                // Round down to nearest whole item to prevent cutting
                scroll = Math.floor(scroller.clientWidth / itemW) * itemW;
            }
            return Math.max(scroll, 280);
        }

        function getMaxScroll() {
            return Math.max(0, scroller.scrollWidth - scroller.clientWidth);
        }

        // r22l: ignore leftover sub-card scroll (desktop often has 8–40px) so we
        // do not show a fake 2nd page / enabled Next that moves almost nothing.
        function hasMeaningfulScroll() {
            let maxScroll = getMaxScroll();
            let item = items[0];
            let minDelta = 48;
            if (item) {
                let style = getComputedStyle(track);
                let gap = parseInt(style.gap, 10) || 16;
                minDelta = Math.max(48, Math.floor((item.offsetWidth + gap) * 0.45));
            }
            return maxScroll >= minDelta;
        }

        function syncNavChrome() {
            let meaningful = hasMeaningfulScroll();
            let section = track.closest('.top-home-content--category-carousel')
                || (root && root.classList && root.querySelector
                    ? root
                    : null);
            if (section && section.classList) {
                section.classList.toggle('is-awa-cat-nav-idle', !meaningful);
            }
            if (!meaningful) {
                pageOffsets = [0];
                if (dotsWrap) {
                    dotsWrap.innerHTML = '';
                    dotsWrap.style.display = 'none';
                    dotsWrap.setAttribute('aria-hidden', 'true');
                    dotsWrap.setAttribute('inert', '');
                }
                [prev, next].forEach(function (button) {
                    if (!button) {
                        return;
                    }
                    button.disabled = true;
                    button.classList.add('is-disabled');
                    button.setAttribute('aria-disabled', 'true');
                    button.style.opacity = '0.45';
                    button.style.pointerEvents = 'none';
                    button.hidden = true;
                });
                return false;
            }
            [prev, next].forEach(function (button) {
                if (!button) {
                    return;
                }
                button.hidden = false;
            });
            return true;
        }

        function buildPageOffsets() {
            let rawTrackW = Number(scroller.clientWidth) || 0;
            let trackW = Math.max(1, Math.floor(rawTrackW));
            let maxScroll = Math.max(0, Math.floor(getMaxScroll()));
            let maxPages = 60;
            let approxPages;
            let safePages;
            let step;
            let i;
            let offset;

            pageOffsets = [0];

            if (trackW <= 0 || maxScroll <= 0) {
                return;
            }

            // Avoid phantom pages for sub-card leftovers (e.g. maxScroll=32 on 1440).
            if (!hasMeaningfulScroll()) {
                return;
            }

            // Guard rail: avoid long while-loops when layout glitches produce
            // very large scroll ranges and tiny viewport widths.
            approxPages = Math.max(1, Math.ceil(maxScroll / trackW));
            safePages = Math.min(maxPages, approxPages);
            step = Math.max(1, Math.ceil(maxScroll / safePages));

            for (i = 1; i <= safePages; i += 1) {
                offset = Math.min(maxScroll, i * step);
                if (pageOffsets[pageOffsets.length - 1] !== offset) {
                    pageOffsets.push(offset);
                }
            }

            if (pageOffsets[pageOffsets.length - 1] !== maxScroll) {
                pageOffsets.push(maxScroll);
            }
        }

        function getCurrentPage() {
            let currentScroll = scroller.scrollLeft;
            let activeIndex = 0;
            let activeDistance = Infinity;

            pageOffsets.forEach(function (offset, idx) {
                let distance = Math.abs(currentScroll - offset);

                if (distance < activeDistance) {
                    activeDistance = distance;
                    activeIndex = idx;
                }
            });

            return activeIndex;
        }

        function scheduleDotsUpdate() {
            if (scrollRaf) {
                return;
            }

            if (typeof window.requestAnimationFrame === 'function') {
                scrollRaf = window.requestAnimationFrame(function () {
                    scrollRaf = 0;
                    updateDots();
                });
                return;
            }

            scrollRaf = window.setTimeout(function () {
                scrollRaf = 0;
                updateDots();
            }, 16);
        }

        function updateNavState() {
            let maxScroll = getMaxScroll();
            let meaningful = hasMeaningfulScroll();
            let atStart = scroller.scrollLeft <= 4;
            let atEnd = scroller.scrollLeft >= (maxScroll - 4);

            [prev, next].forEach(function (button, idx) {
                let disabled;

                if (!button) {
                    return;
                }

                if (!meaningful) {
                    button.hidden = true;
                    button.disabled = true;
                    button.classList.add('is-disabled');
                    button.setAttribute('aria-disabled', 'true');
                    button.style.opacity = '0.45';
                    button.style.pointerEvents = 'none';
                    return;
                }

                button.hidden = false;
                disabled = idx === 0 ? atStart : atEnd;
                button.disabled = disabled;
                button.classList.toggle('is-disabled', disabled);
                button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
                button.style.opacity = disabled ? '0.45' : '';
                button.style.pointerEvents = disabled ? 'none' : '';
            });
        }

        function buildDots() {
            let trackW;
            let scrollW;
            let pages;
            let i;

            trackW = scroller.offsetWidth;
            scrollW = scroller.scrollWidth;
            buildPageOffsets();

            // r22m: always sync idle nav even when dots node is missing/empty.
            if (!syncNavChrome() || scrollW <= trackW || pageOffsets.length < 2) {
                if (dotsWrap) {
                    dotsWrap.innerHTML = '';
                    dotsWrap.style.display = 'none';
                    dotsWrap.setAttribute('aria-hidden', 'true');
                    dotsWrap.setAttribute('inert', '');
                    dotsWrap.removeAttribute('aria-label');
                }
                updateNavState();
                return;
            }

            if (!dotsWrap) {
                updateNavState();
                return;
            }

            dotsWrap.innerHTML = '';

            dotsWrap.style.display = '';
            dotsWrap.removeAttribute('aria-hidden');
            dotsWrap.removeAttribute('inert');
            dotsWrap.setAttribute('aria-label', 'Navegacao do carrossel de categorias');
            pages = pageOffsets.length;

            for (i = 0; i < pages; i++) {
                (function (pageIndex) {
                    let dot = document.createElement('button');
                    let isActive = pageIndex === 0;

                    dot.className = 'awa-category-carousel__dot';
                    dot.type = 'button';
                    dot.setAttribute('aria-controls', track.id);
                    dot.setAttribute('aria-label', 'Ir para página ' + (pageIndex + 1) + ' de ' + pages);
                    dot.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    if (isActive) {
                        dot.setAttribute('aria-current', 'page');
                    }

                    if (isActive) {
                        dot.classList.add('active');
                    }

                    dot.addEventListener('click', function () {
                        scroller.scrollTo({left: pageOffsets[pageIndex] || 0, behavior: scrollBehavior()});
                    });

                    dotsWrap.appendChild(dot);
                })(i);
            }

            updateNavState();
        }

        function updateDots() {
            let dots;
            let currentPage;

            if (!dotsWrap) {
                return;
            }

            dots = dotsWrap.querySelectorAll('.awa-category-carousel__dot');
            if (!dots.length) {
                return;
            }

            currentPage = getCurrentPage();

            dots.forEach(function (dot, idx) {
                let isActive = idx === currentPage;

                dot.classList.toggle('active', isActive);
                dot.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                if (isActive) {
                    dot.setAttribute('aria-current', 'page');
                } else {
                    dot.removeAttribute('aria-current');
                }
            });

            updateNavState();
        }

        function bindNavButton(button, dir) {
            if (!button) {
                return;
            }
            button.setAttribute('aria-controls', track.id);
            button.setAttribute('aria-keyshortcuts', dir < 0 ? 'ArrowLeft' : 'ArrowRight');
        }

        bindNavButton(prev, -1);
        bindNavButton(next, 1);

        // r22k: delegate clicks on section root so header-moved buttons always work,
        // even if nodes are replaced after first paint.
        root.addEventListener('click', function (event) {
            var btn = event.target && event.target.closest
                ? event.target.closest('.awa-category-carousel__prev, .awa-category-carousel__next')
                : null;
            if (!btn || !root.contains(btn) || btn.disabled) {
                return;
            }
            if (btn.classList.contains('awa-category-carousel__prev')) {
                scroller.scrollBy({left: -getScrollAmount(), behavior: scrollBehavior()});
                return;
            }
            if (btn.classList.contains('awa-category-carousel__next')) {
                scroller.scrollBy({left: getScrollAmount(), behavior: scrollBehavior()});
            }
        });

        track.addEventListener('touchstart', function (event) {
            startX = event.touches[0].pageX;
            startScrollLeft = scroller.scrollLeft;
            isDragging = true;
        }, {passive: true});

        track.addEventListener('touchmove', function (event) {
            if (!isDragging) {
                return;
            }

            scroller.scrollLeft = startScrollLeft - (event.touches[0].pageX - startX);
        }, {passive: true});

        track.addEventListener('touchend', function () {
            isDragging = false;
            if (prefersReducedMotion()) {
                return;
            }
            let page = getCurrentPage();
            scroller.scrollTo({
                left: pageOffsets[page] || 0,
                behavior: scrollBehavior()
            });
        }, {passive: true});
        track.addEventListener('touchcancel', function () {
            isDragging = false;
        }, {passive: true});

        track.setAttribute('tabindex', '0');
        track.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowRight') {
                scroller.scrollBy({left: getScrollAmount(), behavior: scrollBehavior()});
                event.preventDefault();
            }

            if (event.key === 'ArrowLeft') {
                scroller.scrollBy({left: -getScrollAmount(), behavior: scrollBehavior()});
                event.preventDefault();
            }

            if (event.key === 'Home') {
                scroller.scrollTo({left: 0, behavior: scrollBehavior()});
                event.preventDefault();
            }

            if (event.key === 'End') {
                buildPageOffsets();
                scroller.scrollTo({left: pageOffsets[pageOffsets.length - 1] || 0, behavior: scrollBehavior()});
                event.preventDefault();
            }
        });

        scroller.addEventListener('scroll', scheduleDotsUpdate, {passive: true});
        buildDots();
        updateDots();
        syncNavChrome();
        updateNavState();

        // PERF: remountChrome era chamado 4x no load (2x rAF + 300ms + 1200ms + load),
        // e cada execucao custa ~130ms => ~520ms de TBT medidos no Lighthouse/LoAF.
        // O guard abaixo mantem a funcionalidade (resize e mudanca de slides ainda remontam)
        // mas evita reexecucao quando NADA mudou: nº de itens, largura do viewport,
        // largura rolavel do track (captura assentamento de fontes/imagens) e reduced-motion.
        let remountSig = '';
        function remountChrome() {
            const sig = [
                track.children.length,
                window.innerWidth,
                Math.round(track.scrollWidth),
                prefersReducedMotion() ? '1' : '0',
            ].join('|');
            if (sig === remountSig) {
                return;
            }
            remountSig = sig;
            buildDots();
            updateDots();
            syncNavChrome();
            updateNavState();
        }

        // r22m: layout settles after fonts/images — re-check phantom scroll leftovers.
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(remountChrome);
            });
        }
        window.setTimeout(remountChrome, 300);
        window.setTimeout(remountChrome, 1200);
        window.addEventListener('load', remountChrome, {once: true, passive: true});

        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(remountChrome, 200);
        }, {passive: true});

        if ('IntersectionObserver' in window && !prefersReducedMotion()) {
            let animItems = track.querySelectorAll('.awa-category-carousel__item');

            animItems.forEach(function (el) {
                el.classList.add('awa-carousel-hidden');
            });

            /* Reveal hidden items in the track, with optional stagger delay. */
            function revealTrackItems(stagger) {
                let cards = track.querySelectorAll('.awa-carousel-hidden');

                if (!cards.length) {
                    return;
                }

                cards.forEach(function (card, i) {
                    let delay = stagger ? i * 80 : 0;

                    setTimeout(function () {
                        card.classList.remove('awa-carousel-hidden');
                        card.classList.add('awa-carousel-visible');
                        if (i === cards.length - 1) {
                            // Last card revealed — remeasure scroll leftover vs nav chrome.
                            setTimeout(function () {
                                buildDots();
                                updateDots();
                            }, 30);
                        }
                    }, delay);
                });
            }

            /* IO reveals items when track scrolls into (or near) viewport.
               rootMargin 400px ensures items just below fold are revealed early. */
            let io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        revealTrackItems(true);
                        io.unobserve(entry.target);
                    }
                });
            }, {threshold: 0.05, rootMargin: '0px 0px 400px 0px'});

            /* Always start observing — IO fires immediately if track is in the
               extended zone (within 400px below fold). */
            io.observe(track);

            /* Fallback: on window load, the hero slider has finished collapsing
               (Slick init), so the track position is final. If it is near the
               fold and IO hasn't fired yet, reveal at once. */
            function tryRevealAfterLoad() {
                let remaining = track.querySelectorAll('.awa-carousel-hidden');

                if (!remaining.length) {
                    return; // IO already handled it
                }

                let rect = track.getBoundingClientRect();

                if (rect.top < window.innerHeight + 400) {
                    io.unobserve(track);
                    revealTrackItems(false); // No stagger for fold-visible items
                }
            }

            if (document.readyState === 'complete') {
                tryRevealAfterLoad();
            } else {
                window.addEventListener('load', tryRevealAfterLoad, {once: true});
            }
        }
    };
});
