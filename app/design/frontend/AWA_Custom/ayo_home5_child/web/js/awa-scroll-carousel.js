/**
 * AWA Product Shelf Carousel — CSS Scroll-Snap runtime (zero dependencias).
 *
 * Substitui o motor Swiper. Normaliza os blocos (owl / grid / swiper) para a
 * estrutura unificada:
 *   .awa-carousel > .awa-carousel__viewport > .awa-carousel__track > .awa-carousel__slide
 * e usa scroll-snap nativo do browser para a navegacao. As setas e a barra de
 * progresso (CSS ja existente: .awa-owl-nav / .awa-owl-progress) sao ligadas via
 * scrollBy() + listener de scroll. Alturas iguais sao garantidas por CSS
 * (align-items:stretch), sem medicao em JS.
 */
(function () {
    'use strict';

    if (window.__awaScrollCarouselInit) {
        return;
    }
    window.__awaScrollCarouselInit = true;

    var SVG_PREV = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="15 18 9 12 15 6"/></svg>';
    var SVG_NEXT = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="9 6 15 12 9 18"/></svg>';
    var SVG_PAUSE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><rect x="7" y="5" width="3.5" height="14" rx="1"/><rect x="13.5" y="5" width="3.5" height="14" rx="1"/></svg>';
    var SVG_PLAY = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5.8v12.4c0 .8.9 1.3 1.6.9l9.2-6.2c.6-.4.6-1.3 0-1.8L9.6 4.9C8.9 4.5 8 5 8 5.8z"/></svg>';

    var SHELF_SELECTORS = [
        '.awa-shelf--carousel',
        '.awa-carousel-section .rokan-bestseller',
        '.awa-carousel-section .rokan-newproduct',
        '.awa-grid-section .rokan-newproduct',
        '.awa-grid-section .rokan-bestseller',
        '.awa-home-niche-shelves__panel .rokan-bestseller',
        '.awa-home-niche-shelves__panel .rokan-newproduct',
        '.rokan-bestseller.awa-shelf',
        '.rokan-newproduct.awa-shelf',
        '.hot-deal-tab-slider',
        '.awa-super-offers-carousel',
        '.block.related',
        '.block.upsell',
        '.awa-pdp-related',
        '.rokan-mostviewed',
        '.rokan-toprate',
        '.rokan-featured',
        '.rokan-featuredproduct',
        '.rokan-onsale',
        '.rokan-onsaleproduct',
        '.categorytab-container'
    ];

    var AUTO_DELAY = 4200;
    var AUTO_RESUME_DELAY = 5200;
    var REFRESH_DELAY = 140;

    /* Home freeze 2026-09-01: init ONE shelf per idle/rAF tick.
     * Eager sync init of ~48 home shelves + scroll rescan was a multi-second
     * Style&Layout wedge (Lighthouse ~75s on this file). */
    var pendingShelfInits = [];
    var shelfInitPumping = false;

    function scheduleIdle(fn, timeoutMs) {
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(fn, { timeout: timeoutMs || 200 });
            return;
        }
        window.setTimeout(fn, 16);
    }

    function enqueueShelfInit(shelf) {
        if (!shelf || shelf.dataset.awaCarouselQueued === '1' || shelf.classList.contains('awa-carousel-ready')) {
            return;
        }
        shelf.dataset.awaCarouselQueued = '1';
        pendingShelfInits.push(shelf);
        pumpShelfInits();
    }

    function pumpShelfInits() {
        if (shelfInitPumping) {
            return;
        }
        shelfInitPumping = true;

        function next() {
            var shelf = pendingShelfInits.shift();
            if (!shelf) {
                shelfInitPumping = false;
                return;
            }
            try {
                if (shelf.matches && shelf.matches('.category_tab_slider, .productTabContent')) {
                    initTabSliderShelf(shelf);
                } else {
                    initScrollShelf(shelf);
                }
            } catch (ignore) {
                /* one shelf must not block the rest */
            }
            scheduleIdle(function () {
                window.requestAnimationFrame(next);
            }, 120);
        }

        window.requestAnimationFrame(next);
    }

    function qsa(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    /* R22: agendador global de frame — a home tem dezenas de carrosseis
     * (.awa-shelf--carousel), cada um com seu proprio scroll/resize
     * listener. Um unico evento de resize dispara scheduleUpdate() em TODOS
     * eles ao mesmo tempo; se cada carrossel chamasse requestAnimationFrame
     * de forma independente, o browser executaria seus callbacks em
     * sequencia dentro do MESMO frame — e mesmo com leitura/escrita já
     * separadas DENTRO de cada update(), a escrita do carrossel N ainda
     * suja o layout que a leitura do carrossel N+1 precisa, forcando um
     * reflow sincrono por carrossel (confirmado pelo audit "Forced reflow"
     * apontando para carouselMaxScroll — a 1a leitura de cada update — como
     * a maior fonte isolada, ~16s de main-thread no mobile). Este
     * agendador junta todos os "measure" pendentes de um frame, executa
     * TODOS antes de qualquer "apply", e so entao executa todos os
     * "apply" — window inteira faz NO MAXIMO um recalculo de layout por
     * frame, nao um por carrossel. */
    var pendingCarouselMeasures = [];
    var carouselFrameScheduled = false;

    function queueCarouselFrame(measureFn) {
        pendingCarouselMeasures.push(measureFn);
        if (!carouselFrameScheduled) {
            carouselFrameScheduled = true;
            window.requestAnimationFrame(runCarouselFrame);
        }
    }

    function runCarouselFrame() {
        carouselFrameScheduled = false;
        var queue = pendingCarouselMeasures;
        pendingCarouselMeasures = [];

        var applies = [];
        var i;
        for (i = 0; i < queue.length; i += 1) {
            applies.push(queue[i]());
        }
        for (i = 0; i < applies.length; i += 1) {
            if (typeof applies[i] === 'function') {
                applies[i]();
            }
        }
    }

    /* PERF-002: gating de visibilidade do measureUpdate(). Mesmo com o
     * lote unico do R22 acima, o boot inicial + os listeners de
     * resize/refresh disparam scheduleUpdate() nos ~48 carrosseis da
     * home ao mesmo tempo, inclusive nos que estao muito abaixo da
     * dobra — confirmado pelo audit "bootup-time" do Lighthouse
     * atribuindo ~75s de Style & Layout a este arquivo. Um unico
     * IntersectionObserver compartilhado marca cada viewport como
     * "relevante" (dentro de uma margem generosa da tela, para nao
     * haver "pop" visual ao rolar rapido); scheduleUpdate() pula o
     * measure/apply para carrosseis fora dessa margem, e o proprio
     * observer forca um scheduleUpdate() de catch-up assim que o
     * carrossel entra nela. Nao muda contagem, arquitetura ou CSS dos
     * carrosseis — apenas adia o calculo de geometria dos que ainda
     * nao importam visualmente. */
    var carouselVisibilityState = typeof WeakMap === 'function' ? new WeakMap() : null;
    var carouselVisibilityCallbacks = typeof WeakMap === 'function' ? new WeakMap() : null;
    var sharedCarouselVisibilityObserver = null;

    function isCarouselViewportRelevant(viewport) {
        if (!carouselVisibilityState) {
            return true;
        }
        return carouselVisibilityState.get(viewport) !== false;
    }

    function observeCarouselVisibility(viewport, onBecomeRelevant) {
        if (typeof IntersectionObserver === 'undefined' || !carouselVisibilityState) {
            return;
        }

        if (!sharedCarouselVisibilityObserver) {
            sharedCarouselVisibilityObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var wasRelevant = carouselVisibilityState.get(entry.target) !== false;
                    var isRelevant = entry.isIntersecting;

                    carouselVisibilityState.set(entry.target, isRelevant);

                    if (isRelevant && !wasRelevant) {
                        var callback = carouselVisibilityCallbacks
                            ? carouselVisibilityCallbacks.get(entry.target)
                            : null;
                        if (typeof callback === 'function') {
                            callback();
                        }
                    }
                });
            }, { rootMargin: '600px 0px', threshold: 0 });
        }

        /* A11Y: nao inicializar como false — scheduleUpdate() no boot precisa
         * aplicar aria-hidden antes do 1o callback do IntersectionObserver.
         * O IO continua setando false para off-screen apos a 1a medicao. */
        if (carouselVisibilityCallbacks) {
            carouselVisibilityCallbacks.set(viewport, onBecomeRelevant);
        }
        sharedCarouselVisibilityObserver.observe(viewport);
    }

    function shelfI18n(key, fallback) {
        var i18n = window.AWA_SHELF_I18N || {};
        return i18n[key] || fallback;
    }

    function reducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function isHomePage() {
        return !!(document.body && (
            document.body.classList.contains('cms-index-index') ||
            document.body.classList.contains('cms-home') ||
            document.body.classList.contains('cms-homepage_ayo_home5')
        ));
    }

    function stopLegacyAutoplay(root) {
        if (!root || !isHomePage()) {
            return;
        }

        qsa('.swiper, .awa-hero-swiper, .products-swiper, .owl-carousel, .owl-loaded', root).forEach(function (el) {
            if (el.swiper && el.swiper.autoplay && typeof el.swiper.autoplay.stop === 'function') {
                el.swiper.autoplay.stop();
                if (el.swiper.params) {
                    el.swiper.params.autoplay = false;
                }
            }

            if (window.jQuery) {
                try {
                    window.jQuery(el)
                        .trigger('stop.owl.autoplay')
                        .trigger('owl.stop');
                } catch (ignore) {
                    /* Legacy carousel may not be a jQuery plugin instance. */
                }
            }
        });
    }

    /* Retorna o rect quando o elemento está "em caixa visível" (dimensões reais e
     * não display:none/visibility:hidden), ou null caso contrário. Usado nos pontos
     * quentes (snapOffsets/updateSlideAccessibility) que precisam do rect E do
     * booleano de visibilidade — assim o getBoundingClientRect é feito uma única vez
     * em vez de uma para o filtro e outra para medir a posição do slide. */
    function visibleBoxRect(el) {
        if (!el || !el.getBoundingClientRect) {
            return null;
        }
        var rect = el.getBoundingClientRect();

        /* Curto-circuito: elementos com caixa vazia (display:none, desmontados,
         * fora do fluxo) já falham aqui sem precisar de getComputedStyle — que é
         * mais um forced reflow. Chamado para CADA slide de CADA carrossel em
         * cada scroll/resize, então evitar essa segunda leitura quando o rect já
         * decide o resultado reduz bastante o "Style & Layout" do main-thread. */
        if (rect.width <= 1 || rect.height <= 1) {
            return null;
        }

        var style = window.getComputedStyle ? window.getComputedStyle(el) : null;
        if (style && (style.display === 'none' || style.visibility === 'hidden')) {
            return null;
        }

        return rect;
    }

    function visibleBox(el) {
        return !!visibleBoxRect(el);
    }

    function carouselMaxScroll(viewport) {
        if (!viewport) {
            return 0;
        }
        return Math.max(0, Math.ceil(viewport.scrollWidth - viewport.clientWidth));
    }

    function isScrollableViewport(viewport) {
        return !!viewport && visibleBox(viewport) && carouselMaxScroll(viewport) > 10;
    }

    function carouselAnalytics(action, detail) {
        var payload = detail || {};
        payload.action = action;
        payload.component = 'awa_shelf_carousel';
        payload.timestamp = Date.now();

        try {
            document.dispatchEvent(new CustomEvent('awa:carousel:analytics', { detail: payload }));
        } catch (ignore) {
            /* CustomEvent may be unavailable in very old browsers. */
        }

        if (Array.isArray(window.dataLayer)) {
            window.dataLayer.push({
                event: 'awa_carousel_interaction',
                awa_carousel_action: action,
                awa_carousel_label: payload.label || '',
                awa_carousel_index: payload.index || 0,
                awa_carousel_total: payload.total || 0,
                awa_carousel_direction: payload.direction || '',
                awa_carousel_source: payload.source || '',
                awa_carousel_product: payload.product || '',
                awa_carousel_target: payload.target || '',
                awa_carousel_url: payload.url || ''
            });
        }
    }

    function textLabel(el) {
        if (!el || !el.textContent) {
            return '';
        }

        return el.textContent.trim().replace(/\s+/g, ' ').slice(0, 140);
    }

    function productMetaFromTarget(target, viewport) {
        var slide = target && target.closest ? target.closest('.awa-carousel__slide') : null;
        var nameEl = slide ? slide.querySelector('.product-name a, .product-item-link, .product-name, .product-item-name') : null;
        var link = target && target.closest ? target.closest('a[href]') : null;
        var slides = viewport ? qsa('.awa-carousel__slide', viewport).filter(visibleBox) : [];
        var index = slide && slides.length ? slides.indexOf(slide) + 1 : 0;

        return {
            index: Math.max(0, index),
            total: slides.length,
            product: textLabel(nameEl),
            url: link ? link.href : ''
        };
    }

    function updateFocusableState(focusable, enabled) {
        if (!focusable) {
            return;
        }

        if (!enabled) {
            if (!focusable.dataset.awaOriginalTabindex) {
                focusable.dataset.awaOriginalTabindex = focusable.hasAttribute('tabindex') ?
                    focusable.getAttribute('tabindex') :
                    '__none__';
            }
            focusable.setAttribute('tabindex', '-1');
            return;
        }

        if (!focusable.dataset.awaOriginalTabindex) {
            return;
        }
        if (focusable.dataset.awaOriginalTabindex === '__none__') {
            focusable.removeAttribute('tabindex');
        } else {
            focusable.setAttribute('tabindex', focusable.dataset.awaOriginalTabindex);
        }
        delete focusable.dataset.awaOriginalTabindex;
    }

    function imageUrl(img) {
        if (!img) {
            return '';
        }

        return img.currentSrc ||
            img.getAttribute('src') ||
            img.getAttribute('data-src') ||
            img.getAttribute('data-original') ||
            '';
    }

    function preloadImageOnce(img) {
        var href = imageUrl(img);
        var cache = window.__awaCarouselPreloadedImages || {};
        var keys;

        if (!href || cache[href]) {
            return;
        }

        keys = Object.keys(cache);
        if (keys.length >= 16) {
            return;
        }

        cache[href] = true;
        window.__awaCarouselPreloadedImages = cache;

        if (!img) {
            return;
        }

        /* Native lazy-load is enough below the fold. Do not inject
         * <link rel=preload as=image> (duplicates the img request and
         * competes with hero LCP). Warm only when caller confirmed the
         * carousel is on-screen. */
        img.decoding = 'async';
        if (img.loading === 'lazy') {
            img.loading = 'eager';
        }
    }

    function findHeaderForMount(mount) {
        var panel;
        var container;
        var section;
        var header = null;

        if (!mount || !mount.closest) {
            return null;
        }

        panel = mount.closest('.awa-home-niche-shelves__panel');
        if (panel) {
            try {
                header = panel.querySelector(':scope > .awa-home-niche-shelves__panel-head');
            } catch (ignore) {
                header = null;
            }
            header = header || panel.querySelector('.awa-home-niche-shelves__panel-head');
            if (header) {
                header.classList.add('awa-carousel-nav-host');
                return header;
            }
        }

        container = mount.closest('.container');
        if (container) {
            try {
                header = container.querySelector(':scope > .awa-section-header, :scope > header.awa-section-header');
            } catch (ignoreScope) {
                header = null;
            }
            header = header || container.querySelector('.awa-section-header, header.awa-section-header');
        }

        if (!header) {
            section = mount.closest('.awa-carousel-section, .awa-grid-section, .awa-home-section');
            header = section ? section.querySelector('.awa-section-header, header.awa-section-header') : null;
        }

        if (header) {
            header.classList.add('awa-carousel-nav-host');
        }

        return header;
    }

    function clearInlineCarouselChrome(nav) {
        var navProps = [
            'bottom',
            'display',
            'height',
            'left',
            'margin',
            'max-height',
            'max-width',
            'min-height',
            'min-width',
            'opacity',
            'overflow',
            'pointer-events',
            'position',
            'right',
            'top',
            'transform',
            'visibility',
            'width'
        ];
        var buttonProps = [
            'bottom',
            'display',
            'height',
            'left',
            'margin',
            'max-height',
            'max-width',
            'min-height',
            'min-width',
            'opacity',
            'padding',
            'pointer-events',
            'position',
            'right',
            'top',
            'transform',
            'visibility',
            'width'
        ];

        if (!nav) {
            return;
        }

        navProps.forEach(function (prop) {
            nav.style.removeProperty(prop);
        });

        qsa('.awa-owl-nav__btn, .awa-carousel__arrow', nav).forEach(function (button) {
            buttonProps.forEach(function (prop) {
                button.style.removeProperty(prop);
            });
        });
    }

    function removeHeaderNavPlaceholders(header, realNav) {
        var controlId = realNav ? realNav.getAttribute('aria-controls') : '';

        if (!header) {
            return;
        }

        qsa('.awa-owl-nav', header).forEach(function (nav) {
            if (nav === realNav) {
                return;
            }
            if (nav.classList.contains('awa-owl-nav--header-slot')) {
                nav.remove();
                return;
            }
            if (controlId && nav.getAttribute('aria-controls') === controlId) {
                nav.remove();
            }
        });
    }

    function markImageLoaded(img) {
        if (!img || img.tagName !== 'IMG') {
            return;
        }
        img.classList.add('awa-loaded');
        img.dataset.loaded = '1';
        img.dataset.awaLoaded = '1';
    }

    function markImageFailed(img) {
        if (!img || img.tagName !== 'IMG') {
            return;
        }
        img.classList.add('awa-load-error');
        img.dataset.awaLoadError = '1';
    }

    function markExistingImagesLoaded(root) {
        qsa('img.product-image-photo, .product-item-photo img, .product-thumb img', root).forEach(function (img) {
            if (img.complete && img.naturalWidth > 0) {
                markImageLoaded(img);
            } else if (img.complete && img.naturalWidth === 0) {
                markImageFailed(img);
            }
        });
    }

    function markProductCards(root) {
        qsa('.content-item-product, .item-product .content-item-product, .product-item-info', root).forEach(function (card) {
            card.classList.add('awa-product-card');
        });
    }

    function getSectionLabel(mount) {
        var section = mount && mount.closest ? mount.closest('.awa-carousel-section') : null;
        if (section && section.getAttribute('aria-label')) {
            return section.getAttribute('aria-label');
        }
        var title = mount ? mount.querySelector('.awa-shelf__title, .awa-section-header__title, h2, strong') : null;
        return title && title.textContent ? title.textContent.trim() : 'Produtos';
    }

    function applyCarouselModifiers(shelf, carousel) {
        var hasContainer;

        if (!shelf || !carousel) {
            return;
        }

        carousel.classList.add('awa-carousel--common');
        carousel.classList.remove(
            'awa-carousel--hero',
            'awa-carousel--product',
            'awa-carousel--brand',
            'awa-carousel--category'
        );

        if (shelf.matches('.rokan-brand, .rokanthemes-brand, .brand, [data-awa-shelf="brand"]')) {
            carousel.classList.add('awa-carousel--brand');
        } else if (shelf.matches('.categorytab-container, .category_tab_slider, .awa-category-carousel, [data-awa-shelf="category"]')) {
            carousel.classList.add('awa-carousel--category');
        } else {
            carousel.classList.add('awa-carousel--product');
        }

        hasContainer = !!(shelf.closest && shelf.closest('.container'));
        carousel.classList.toggle('awa-carousel--contained', hasContainer);
        carousel.classList.toggle('awa-carousel--fullbleed', !hasContainer);
    }

    function markPending(shelf) {
        var section = shelf && shelf.closest ? shelf.closest('.awa-carousel-section') : null;
        if (shelf && !shelf.classList.contains('awa-carousel-ready')) {
            shelf.classList.add('awa-carousel-pending');
        }
        if (section && !section.classList.contains('awa-carousel-ready')) {
            section.classList.add('awa-carousel-pending');
        }
    }

    function markReady(shelf, container) {
        var section = shelf && shelf.closest ? shelf.closest('.awa-carousel-section') : null;
        if (shelf) {
            shelf.classList.remove('awa-carousel-pending', 'awa-carousel-fallback');
            shelf.classList.add('awa-carousel-ready');
            shelf.setAttribute('data-awa-carousel-mode', 'scroll-snap');
        }
        if (section) {
            section.classList.remove('awa-carousel-pending');
            section.classList.add('awa-carousel-ready');
        }
        if (container) {
            container.classList.add('awa-carousel-ready');
        }
    }

    function buildNav() {
        var frag = document.createElement('div');
        frag.innerHTML =
            '<div class="awa-owl-nav awa-carousel__nav" aria-hidden="false">' +
            '<button type="button" class="awa-owl-nav__btn awa-carousel__arrow awa-carousel__arrow--prev awa-owl-nav__btn--prev" aria-label="' + shelfI18n('prev', 'Anterior') + '">' + SVG_PREV + '</button>' +
            '<button type="button" class="awa-owl-nav__btn awa-carousel__arrow awa-carousel__arrow--next awa-owl-nav__btn--next" aria-label="' + shelfI18n('next', 'Próximo') + '">' + SVG_NEXT + '</button>' +
            '<button type="button" class="awa-owl-nav__btn awa-carousel__toggle" aria-label="' + shelfI18n('pause', 'Pausar carrossel') + '" title="' + shelfI18n('pause', 'Pausar carrossel') + '" aria-pressed="false" hidden>' + SVG_PAUSE + '</button>' +
            '</div>';
        return frag.firstChild;
    }

    function buildAutoplayToggle() {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'awa-owl-nav__btn awa-carousel__toggle';
        button.hidden = true;
        button.innerHTML = SVG_PAUSE;
        setAutoplayToggleState(button, false);

        return button;
    }

    function setAutoplayToggleState(button, paused) {
        var label = paused ? shelfI18n('play', 'Retomar carrossel') : shelfI18n('pause', 'Pausar carrossel');

        if (!button) {
            return;
        }

        button.classList.toggle('is-paused', !!paused);
        button.setAttribute('aria-pressed', paused ? 'true' : 'false');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.innerHTML = paused ? SVG_PLAY : SVG_PAUSE;
    }

    function syncAutoplayToggleAvailability(chrome, enabled, paused) {
        // AWA 2026-08-09: botao de pausa removido da UI a pedido do produto.
        // O autoplay do carrossel continua funcionando normalmente (bindAutoMotion);
        // apenas o controle visual de pausa/retomada deixa de ser exibido.
        var button = chrome && chrome.toggle ? chrome.toggle : null;

        if (!button) {
            return;
        }

        button.hidden = true;
        button.disabled = true;
    }

    function resolveNavMountRoot(mount, nav) {
        var carousel = null;

        if (nav && nav.closest) {
            carousel = nav.closest('.awa-carousel');
        }

        if (!carousel && mount && mount.querySelector) {
            carousel = mount.querySelector('.awa-carousel');
        }

        if (!carousel && mount && mount.closest) {
            carousel = mount.closest('.awa-carousel');
        }

        return carousel;
    }

    /* R22: leitura (measure) e escrita (apply) separadas explicitamente.
     * syncAnchoredNavViewportBoxes precisa medir TODOS os navs antes de
     * escrever em QUALQUER um deles — do contrario, cada nav processado
     * invalida o layout que o proximo precisa ler, forcando um reflow
     * sincrono por nav (confirmado pelo audit "Forced reflow" do Lighthouse
     * apontando para esta funcao, ~8.8s de main-thread so aqui no mobile). */
    function measureNavViewportBox(carousel, nav) {
        var viewport;
        var viewportRect;

        if (!carousel || !nav || !nav.style) {
            return null;
        }

        viewport = carousel.querySelector('.awa-carousel__viewport');
        if (!viewport) {
            return { nav: nav, empty: true };
        }

        viewportRect = viewport.getBoundingClientRect ? viewport.getBoundingClientRect() : null;

        return {
            nav: nav,
            empty: false,
            topOffset: Math.max(0, viewport.offsetTop || 0),
            viewportHeight: Math.max(
                0,
                viewportRect && viewportRect.height ? viewportRect.height : (viewport.offsetHeight || 0)
            )
        };
    }

    function applyNavViewportBox(measurement) {
        var nav = measurement.nav;

        if (measurement.empty) {
            nav.style.removeProperty('top');
            nav.style.removeProperty('height');
            nav.style.removeProperty('bottom');
            nav.style.removeProperty('margin');
            nav.style.removeProperty('padding');
            nav.style.removeProperty('border');
            nav.style.removeProperty('box-sizing');
            nav.removeAttribute('data-awa-nav-box');
            return;
        }

        nav.style.setProperty('top', measurement.topOffset + 'px', 'important');
        nav.style.setProperty('height', measurement.viewportHeight + 'px', 'important');
        nav.style.setProperty('bottom', 'auto', 'important');
        nav.style.setProperty('margin', '0', 'important');
        nav.style.setProperty('padding', '0', 'important');
        nav.style.setProperty('border', '0', 'important');
        nav.style.setProperty('box-sizing', 'border-box', 'important');
        nav.setAttribute('data-awa-nav-box', 'viewport');
    }

    function syncNavViewportBox(carousel, nav) {
        var measurement = measureNavViewportBox(carousel, nav);
        if (measurement) {
            applyNavViewportBox(measurement);
        }
    }

    function bindNavViewportObserver(carousel, nav) {
        var viewport;
        var observer;

        if (!carousel || !nav || typeof window.ResizeObserver !== 'function') {
            return;
        }

        viewport = carousel.querySelector('.awa-carousel__viewport');
        if (!viewport) {
            return;
        }

        if (nav.__awaNavViewportObserver && nav.__awaNavViewportObserverTarget === viewport) {
            return;
        }

        if (nav.__awaNavViewportObserver) {
            nav.__awaNavViewportObserver.disconnect();
        }

        observer = new window.ResizeObserver(function () {
            syncNavViewportBox(carousel, nav);
        });
        observer.observe(viewport);
        nav.__awaNavViewportObserver = observer;
        nav.__awaNavViewportObserverTarget = viewport;
    }

    function syncAnchoredNavViewportBoxes(scope) {
        var root = scope && scope.nodeType === 1 ? scope : document;

        /* Fase de leitura para TODOS os navs primeiro, depois fase de
         * escrita para todos — evita o read/write intercalado entre navs. */
        var measurements = qsa('.awa-carousel .awa-carousel__nav[data-awa-nav-anchor="viewport"]', root)
            .map(function (nav) {
                var carousel = nav.closest('.awa-carousel');
                return carousel ? measureNavViewportBox(carousel, nav) : null;
            })
            .filter(function (measurement) {
                return !!measurement;
            });

        measurements.forEach(applyNavViewportBox);
    }

    function queueAnchoredNavViewportResync(scope) {
        var root = scope && scope.nodeType === 1 ? scope : document;
        var delays = [120, 480, 1200, 2200, 3200];

        delays.forEach(function (delay) {
            window.setTimeout(function () {
                syncAnchoredNavViewportBoxes(root);
            }, delay);
        });

        bindFontsReadyResync();
    }

    /**
     * Troca de fonte web (FOUT/FOIT) pode alterar a altura de titulos/cards
     * DEPOIS da ultima resync agendada em queueAnchoredNavViewportResync,
     * causando drift residual de poucos px entre nav e viewport. document.fonts.ready
     * eh o sinal correto (nao arbitrario) para disparar uma resync final.
     */
    function bindFontsReadyResync() {
        if (window.__awaFontsReadyResyncBound) {
            return;
        }
        if (!document.fonts || typeof document.fonts.ready === 'undefined' || typeof document.fonts.ready.then !== 'function') {
            return;
        }
        window.__awaFontsReadyResyncBound = true;
        document.fonts.ready.then(function () {
            syncAnchoredNavViewportBoxes(document);
            window.setTimeout(function () {
                syncAnchoredNavViewportBoxes(document);
            }, 160);
        });
    }

    /** Ancora setas no viewport real do carrossel, nunca no header. */
    /* R22: skipBoxSync evita chamar syncNavViewportBox (leitura de geometria)
     * logo apos as escritas acima (appendChild/classList/removeProperty) —
     * essa leitura imediata era forcada pelas proprias escritas desta funcao
     * (confirmado pelo audit "Forced reflow", ~10.4s de main-thread so
     * aqui no mobile). mountAllShelfNavInHeaders roda este mount para TODOS
     * os carrosseis da home a cada scan (boot, scroll debounce, ajax,
     * troca de aba) e ja chama syncAnchoredNavViewportBoxes (leitura+escrita
     * em lote) logo depois do loop — entao pedimos para pular aqui e deixar
     * o lote de fora cuidar disso, sem nenhuma leitura forcada por item. */
    function mountNavInHeader(mount, nav, skipBoxSync) {
        var carousel;
        var header;
        var prevBtn;
        var nextBtn;

        if (!mount || !nav) {
            return;
        }

        carousel = resolveNavMountRoot(mount, nav);
        if (!carousel) {
            return;
        }

        header = findHeaderForMount(mount);
        if (header) {
            removeHeaderNavPlaceholders(header, nav);
            header.classList.remove('awa-carousel-nav-host', 'has-carousel-autoplay-toggle', 'is-awa-not-scrollable');
        }

        if (nav.parentElement !== carousel) {
            carousel.appendChild(nav);
        }

        clearInlineCarouselChrome(nav);
        nav.classList.add('awa-carousel__nav');
        nav.classList.remove('awa-owl-nav--header-mounted', 'awa-owl-nav--header-slot');
        nav.setAttribute('aria-hidden', 'false');
        nav.setAttribute('data-awa-nav-anchor', 'viewport');
        nav.hidden = false;
        if (!skipBoxSync) {
            syncNavViewportBox(carousel, nav);
        }
        bindNavViewportObserver(carousel, nav);

        qsa('.awa-owl-nav__btn, .awa-carousel__arrow', nav).forEach(function (button) {
            button.classList.add('awa-carousel__button');
            button.classList.remove('owl-prev', 'owl-next');
            button.style.removeProperty('left');
            button.style.removeProperty('right');
            button.style.removeProperty('top');
            button.style.removeProperty('transform');
        });

        prevBtn = nav.querySelector('.awa-owl-nav__btn--prev, .awa-carousel__arrow--prev');
        nextBtn = nav.querySelector('.awa-owl-nav__btn--next, .awa-carousel__arrow--next');
        if (prevBtn) {
            prevBtn.classList.add('awa-carousel__button--prev');
            prevBtn.setAttribute('aria-label', shelfI18n('prev_slide', 'Slide anterior'));
        }
        if (nextBtn) {
            nextBtn.classList.add('awa-carousel__button--next');
            nextBtn.setAttribute('aria-label', shelfI18n('next_slide', 'Próximo slide'));
        }
    }

    function mountAllShelfNavInHeaders(scope) {
        var root = scope && scope.nodeType === 1 ? scope : document;

        qsa('.awa-shelf--carousel, .rokan-bestseller, .rokan-newproduct, .hot-deal-tab-slider, .awa-super-offers-carousel, .block.related, .block.upsell', root).forEach(function (mount) {
            var nav = mount.querySelector('.awa-owl-nav.awa-carousel__nav:not(.awa-owl-nav--header-slot), .awa-owl-nav:not(.awa-owl-nav--header-slot)');
            var header;

            if (!nav) {
                header = findHeaderForMount(mount);
                nav = header ? header.querySelector('.awa-owl-nav.awa-carousel__nav:not(.awa-owl-nav--header-slot)') : null;
            }
            if (nav) {
                /* skipBoxSync: syncAnchoredNavViewportBoxes(root), chamado
                 * logo abaixo apos o loop inteiro, mede e escreve o box de
                 * TODOS os navs em lote — nao precisamos (nem queremos)
                 * fazer isso aqui dentro, um por um. */
                mountNavInHeader(mount, nav, true);
            }
        });

        syncAnchoredNavViewportBoxes(root);
        queueAnchoredNavViewportResync(root);
    }

    window.__awaMountShelfNavInHeaders = function (scope) {
        /* Home: do not remount/rescan every shelf from bootstrap onload. */
        if (isHomePage() && (!scope || scope === document || scope.nodeType === 9)) {
            return;
        }
        mountAllShelfNavInHeaders(scope);
    };

    window.addEventListener('resize', function () {
        syncAnchoredNavViewportBoxes(document);
    }, { passive: true });

    function buildProgress() {
        var frag = document.createElement('div');
        frag.innerHTML =
            '<div class="awa-owl-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="' +
            shelfI18n('progress', 'Progresso do carrossel') +
            '"><div class="awa-owl-progress__bar"></div><span class="awa-owl-progress__text visually-hidden"></span></div>';
        return frag.firstChild;
    }

    function prepareChrome(shelf, container) {
        var mount = shelf || container.closest('.awa-shelf') || container.parentElement;
        var carouselRoot = resolveNavMountRoot(mount, null);
        var nav;
        var toggle;
        var progress;
        var live;

        if (!mount) {
            return {};
        }

        mount.classList.add('awa-carousel-mounted');
        nav = carouselRoot ? carouselRoot.querySelector('.awa-owl-nav') : null;
        if (!nav) {
            nav = mount.querySelector('.awa-owl-nav');
        }
        if (!nav) {
            nav = buildNav();
            if (carouselRoot) {
                carouselRoot.appendChild(nav);
            } else {
                mount.appendChild(nav);
            }
        } else {
            if (nav.getAttribute('data-awa-chrome') === 'ssr') {
                mount.classList.add('awa-carousel-chrome-ssr');
            }
            if (carouselRoot && nav.parentElement !== carouselRoot) {
                carouselRoot.appendChild(nav);
            }
            nav.classList.add('awa-carousel__nav');
            nav.setAttribute('aria-hidden', 'false');
        }

        if (!carouselRoot) {
            carouselRoot = resolveNavMountRoot(mount, nav);
        }

        toggle = nav.querySelector('.awa-carousel__toggle');
        if (!toggle) {
            toggle = buildAutoplayToggle();
            nav.appendChild(toggle);
        }

        mountNavInHeader(mount, nav);

        progress = carouselRoot ? carouselRoot.querySelector('.awa-owl-progress') : null;
        if (!progress) {
            progress = mount.querySelector('.awa-owl-progress');
        }
        if (!progress) {
            progress = buildProgress();
            if (carouselRoot) {
                carouselRoot.appendChild(progress);
            } else {
                mount.appendChild(progress);
            }
        } else if (progress.getAttribute('data-awa-chrome') === 'ssr') {
            mount.classList.add('awa-carousel-chrome-ssr');
            if (carouselRoot && progress.parentElement !== carouselRoot) {
                carouselRoot.appendChild(progress);
            }
        }

        live = carouselRoot ? carouselRoot.querySelector('.awa-carousel-live') : null;
        if (!live) {
            live = mount.querySelector('.awa-carousel-live');
        }
        if (!live) {
            live = document.createElement('div');
            live.className = 'awa-carousel-live visually-hidden';
            live.setAttribute('aria-live', 'polite');
            live.setAttribute('aria-atomic', 'true');
            if (carouselRoot) {
                carouselRoot.appendChild(live);
            } else {
                mount.appendChild(live);
            }
        }

        if (container && container.id) {
            if (nav) {
                nav.setAttribute('aria-controls', container.id);
            }
            var prevBtn = nav ? nav.querySelector('.awa-owl-nav__btn--prev') : null;
            var nextBtn = nav ? nav.querySelector('.awa-owl-nav__btn--next') : null;
            if (prevBtn) {
                prevBtn.setAttribute('aria-controls', container.id);
            }
            if (nextBtn) {
                nextBtn.setAttribute('aria-controls', container.id);
            }
        }

        return {
            nav: nav,
            prev: nav ? nav.querySelector('.awa-owl-nav__btn--prev') : null,
            next: nav ? nav.querySelector('.awa-owl-nav__btn--next') : null,
            toggle: toggle,
            progress: progress,
            live: live,
            mount: mount
        };
    }

    function prepareRelatedBlock(block) {
        if (!block || block.dataset.awaShelfPrepared === '1') {
            return;
        }

        block.classList.add('awa-shelf', 'awa-shelf--carousel');

        var header = block.querySelector('.block-title.title');
        if (header) {
            header.classList.add('awa-shelf__header');
            var title = header.querySelector('strong, h2');
            if (title) {
                title.classList.add('awa-shelf__title');
            }
        }

        var grid = block.querySelector('.products-grid');
        if (grid) {
            grid.classList.remove('awa-grid-auto');
        }

        var list = block.querySelector('ol.product-items, ul.product-items');
        if (list) {
            list.classList.add('awa-carousel__track');
            qsa('li.product-item', list).forEach(function (li) {
                li.classList.add('item', 'awa-carousel__slide');
                li.style.removeProperty('display');
                var info = li.querySelector('.product-item-info');
                if (info) {
                    info.classList.add('awa-product-card');
                }
            });
        }

        block.dataset.awaShelfPrepared = '1';
    }

    function ensureShelfStructure(root) {
        if (!root) {
            return null;
        }

        stopLegacyAutoplay(root);

        if (!root.classList.contains('awa-shelf')) {
            root.classList.add('awa-shelf', 'awa-shelf--carousel');
        }

        if (root.querySelector('.super-deal-countdown, .countdown_block')) {
            root.classList.add('awa-shelf--has-countdown');
        }

        var track = root.querySelector('.awa-carousel__track, .swiper-wrapper, ul.owl, .hot-deal-slide, ol.product-items, ul.product-items');
        if (!track) {
            return null;
        }

        var carousel = root.querySelector('.awa-carousel');
        if (!carousel) {
            carousel = document.createElement('div');
            carousel.className = 'awa-carousel';
            track.parentNode.insertBefore(carousel, track);
            carousel.appendChild(track);
        }

        var viewport = carousel.querySelector('.awa-carousel__viewport');
        if (!viewport) {
            viewport = document.createElement('div');
            viewport.className = 'awa-carousel__viewport';
            carousel.insertBefore(viewport, track);
            viewport.appendChild(track);
        } else if (track.parentNode !== viewport) {
            viewport.appendChild(track);
        }

        viewport.classList.add('awa-carousel__viewport');
        if (!viewport.id) {
            viewport.id = 'awa-carousel-vp-' + Math.random().toString(36).slice(2, 10);
        }
        viewport.setAttribute('role', 'region');
        viewport.setAttribute('aria-roledescription', 'carrossel');
        viewport.setAttribute('aria-label', getSectionLabel(root));
        viewport.setAttribute('aria-keyshortcuts', 'ArrowLeft ArrowRight Home End');
        applyCarouselModifiers(root, carousel);

        // Remove controles nativos do Swiper (Grupo C/PDP): o motor monta sua própria nav.
        qsa('.swiper-button-prev, .swiper-button-next, .swiper-pagination, .swiper-scrollbar', root).forEach(function (el) {
            if (el.classList.contains('awa-owl-nav__btn')) {
                return;
            }
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        });

        track.classList.remove('owl', 'owl-carousel', 'owl-loaded', 'owl-theme', 'swiper-wrapper');
        track.classList.add('awa-carousel__track');
        track.removeAttribute('style');
        track.removeAttribute('aria-live');

        Array.prototype.slice.call(track.children).forEach(function (slide) {
            if (slide.nodeType !== 1) {
                return;
            }
            slide.classList.remove('owl-item', 'cloned', 'swiper-slide');
            slide.classList.add('awa-carousel__slide');
            slide.style.removeProperty('display');
            slide.style.removeProperty('width');
            slide.style.removeProperty('transform');
        });

        markProductCards(root);
        markExistingImagesLoaded(root);
        bindImageLoadEqualize(root);

        return {
            shelf: root,
            container: viewport,
            track: track
        };
    }

    function bindImageLoadEqualize(shelf) {
        if (!shelf || shelf.dataset.awaImgBound === '1') {
            return;
        }
        shelf.dataset.awaImgBound = '1';

        shelf.addEventListener('load', function (event) {
            if (event.target && event.target.tagName === 'IMG') {
                markImageLoaded(event.target);
            }
        }, true);

        shelf.addEventListener('error', function (event) {
            if (event.target && event.target.tagName === 'IMG') {
                markImageFailed(event.target);
            }
        }, true);
    }

    /* R22: separado em compute (leitura de offsetLeft/scrollLeft) + apply
     * (escrita de textContent), para poder ser chamado na fase de leitura
     * de update() sem intercalar com as escritas (classList/hidden/etc.)
     * que aconteciam antes dela na mesma execucao. */
    function computeLiveLabel(viewport) {
        if (!viewport) {
            return null;
        }
        var slides = qsa('.awa-carousel__slide', viewport);
        if (!slides.length) {
            return null;
        }
        var vpLeft = viewport.scrollLeft;
        var active = slides[0];
        for (var i = 0; i < slides.length; i += 1) {
            if (slides[i].offsetLeft - viewport.offsetLeft >= vpLeft - 4) {
                active = slides[i];
                break;
            }
        }
        var nameEl = active ? active.querySelector('.product-name, .product-item-name, .product-name a, .product-item-link') : null;
        var label = nameEl && nameEl.textContent ? nameEl.textContent.trim().replace(/\s+/g, ' ') : '';
        return label ? label.slice(0, 120) : null;
    }

    function applyLiveLabel(live, label) {
        if (live && label) {
            live.textContent = label;
        }
    }

    function bindAutoMotion(viewport, chrome, stepForward) {
        var mount = chrome && chrome.mount ? chrome.mount : viewport.closest('.awa-shelf--carousel');
        var activeInViewport = true;
        var pausedByUser = !!(mount && mount.dataset.awaCarouselPaused === '1');
        var holdUntil = 0;
        var timer = null;

        if (!viewport || !isHomePage() || reducedMotion() || viewport.dataset.awaAutoMotionBound === '1') {
            syncAutoplayToggleAvailability(chrome, isHomePage() && !reducedMotion(), pausedByUser);
            return;
        }

        viewport.dataset.awaAutoMotionBound = '1';
        syncAutoplayToggleAvailability(chrome, true, pausedByUser);

        function pause(delay) {
            holdUntil = Date.now() + (delay || AUTO_RESUME_DELAY);
        }

        function setPausedByUser(paused) {
            pausedByUser = !!paused;
            if (mount) {
                mount.classList.toggle('is-autoplay-paused', pausedByUser);
                mount.dataset.awaCarouselPaused = pausedByUser ? '1' : '0';
            }
            syncAutoplayToggleAvailability(chrome, true, pausedByUser);
            carouselAnalytics(pausedByUser ? 'pause' : 'resume', {
                label: viewport.getAttribute('aria-label') || ''
            });

            if (pausedByUser) {
                window.clearTimeout(timer);
                return;
            }

            pause(900);
            schedule(900);
        }

        function canMove() {
            if (pausedByUser || document.hidden || reducedMotion() || !activeInViewport) {
                return false;
            }
            if (Date.now() < holdUntil) {
                return false;
            }
            if (mount && mount.classList.contains('is-user-controlling')) {
                return false;
            }
            return isScrollableViewport(viewport);
        }

        function advance() {
            var max = carouselMaxScroll(viewport);

            if (max <= 10) {
                return;
            }
            if (viewport.scrollLeft >= max - 4) {
                carouselAnalytics('auto_reset', {
                    label: viewport.getAttribute('aria-label') || '',
                    source: 'autoplay'
                });
                viewport.scrollTo({
                    left: 0,
                    behavior: reducedMotion() ? 'auto' : 'smooth'
                });
                return;
            }
            stepForward();
        }

        function schedule(extraDelay) {
            window.clearTimeout(timer);
            timer = window.setTimeout(tick, AUTO_DELAY + (extraDelay || 0));
        }

        function tick() {
            if (pausedByUser) {
                return;
            }
            if (canMove()) {
                advance();
            }
            schedule(Math.round(Math.random() * 450));
        }

        function bindPauseTarget(target) {
            if (!target || target.dataset.awaAutoPauseBound === '1') {
                return;
            }
            target.dataset.awaAutoPauseBound = '1';
            target.addEventListener('pointerenter', function () {
                if (mount) {
                    mount.classList.add('is-user-controlling');
                }
                pause();
            }, { passive: true });
            target.addEventListener('pointerleave', function () {
                if (mount) {
                    mount.classList.remove('is-user-controlling');
                }
                pause(900);
            }, { passive: true });
            target.addEventListener('pointerdown', function () {
                pause();
            }, { passive: true });
            target.addEventListener('touchstart', function () {
                pause();
            }, { passive: true });
            target.addEventListener('focusin', function () {
                if (mount) {
                    mount.classList.add('is-user-controlling');
                }
                pause();
            });
            target.addEventListener('focusout', function () {
                if (mount) {
                    mount.classList.remove('is-user-controlling');
                }
                pause(900);
            });
        }

        bindPauseTarget(viewport);
        bindPauseTarget(chrome.nav);

        if (chrome.toggle && chrome.toggle.dataset.awaAutoToggleBound !== '1') {
            chrome.toggle.dataset.awaAutoToggleBound = '1';
            chrome.toggle.addEventListener('click', function (event) {
                event.preventDefault();
                setPausedByUser(!pausedByUser);
            });
        }

        if (typeof IntersectionObserver !== 'undefined') {
            activeInViewport = false;
            new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.target !== viewport) {
                        return;
                    }
                    activeInViewport = !!entry.isIntersecting;
                });
            }, { rootMargin: '160px 0px', threshold: 0.12 }).observe(viewport);
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                pause();
            }
        });

        if (pausedByUser) {
            setPausedByUser(true);
        } else {
            schedule(Math.round(Math.random() * 700));
        }
    }

    function wireScroll(viewport, chrome) {
        if (!viewport) {
            return;
        }
        if (viewport.dataset.awaScrollBound === '1') {
            return;
        }
        var alreadyBound = false;
        viewport.dataset.awaScrollBound = '1';

        /* BUG-2463a2: scroll-padding-inline sem gutter no track desloca o snap.
         * Mais Vendidos reserva o track (52px) para as setas overlay — o
         * scroll-padding tem de ser o mesmo valor, senão o snap nativo come o gutter. */
        var trackForPad = viewport.querySelector('.awa-carousel__track');
        var trackPadInline = trackForPad
            ? (parseFloat(window.getComputedStyle(trackForPad).paddingLeft) || 0)
            : 0;
        viewport.style.setProperty(
            'scroll-padding-inline',
            trackPadInline > 0 ? trackPadInline + 'px' : '0',
            'important'
        );

        var prev = chrome.prev;
        var next = chrome.next;
        var progressBar = chrome.progress ? chrome.progress.querySelector('.awa-owl-progress__bar') : null;
        var progressText = chrome.progress ? chrome.progress.querySelector('.awa-owl-progress__text') : null;
        var rafPending = false;
        var mount = chrome.mount || viewport.closest('.awa-shelf--carousel');
        var dragStartX = 0;
        var dragStartScroll = 0;
        var dragTracking = false;

        /* R21: cache por "tick" de measureUpdate(). snapOffsets() fazia um querySelectorAll +
         * getBoundingClientRect/getComputedStyle por slide, e era chamado várias vezes
         * (activeSlideMeta, targetSnap, updateSlideAccessibility) dentro de uma ÚNICA
         * execução síncrona de measureUpdate() — nada muda no layout entre essas chamadas, então
         * recalcular é puro desperdício. invalidateFrameCache() é chamado no início de
         * measureUpdate() para nunca servir dados de um scroll/resize anterior. */
        var snapOffsetsCache = null;

        function invalidateFrameCache() {
            snapOffsetsCache = null;
        }

        function snapOffsets() {
            if (snapOffsetsCache) {
                return snapOffsetsCache;
            }

            var track = viewport.querySelector('.awa-carousel__track');
            var trackPad = 0;
            if (track) {
                trackPad = parseFloat(window.getComputedStyle(track).paddingLeft) || 0;
            }
            var offsets = qsa('.awa-carousel__slide', viewport)
                .filter(visibleBox)
                .map(function (slide) {
                    /* offsetLeft already is the viewport scroll coordinate. Subtracting
                     * track.offsetLeft made "previous" target the current native snap
                     * point whenever the track had an outer gutter (for example 8px). */
                    return Math.max(0, Math.round(slide.offsetLeft - trackPad));
                })
                .filter(function (value, index, list) {
                    return index === 0 || Math.abs(value - list[index - 1]) > 2;
                });

            if (offsets.length && offsets[offsets.length - 1] < carouselMaxScroll(viewport)) {
                offsets.push(carouselMaxScroll(viewport));
            }

            snapOffsetsCache = offsets;
            return offsets;
        }

        function activeSlideMeta() {
            var offsets = snapOffsets();
            var x = viewport.scrollLeft;
            var nearest = 0;
            var distance = Infinity;

            offsets.forEach(function (offset, index) {
                var currentDistance = Math.abs(offset - x);
                if (currentDistance < distance) {
                    distance = currentDistance;
                    nearest = index;
                }
            });

            return {
                index: offsets.length ? nearest + 1 : 1,
                total: Math.max(1, offsets.length)
            };
        }

        function slideAtSnap(offset) {
            var track = viewport.querySelector('.awa-carousel__track');
            var trackPad = track
                ? (parseFloat(window.getComputedStyle(track).paddingLeft) || 0)
                : 0;
            var slides = qsa('.awa-carousel__slide', viewport).filter(visibleBox);
            var chosen = slides[0] || null;
            var distance = Infinity;

            slides.forEach(function (slide) {
                var currentDistance = Math.abs(Math.max(0, Math.round(slide.offsetLeft - trackPad)) - offset);
                if (currentDistance < distance) {
                    distance = currentDistance;
                    chosen = slide;
                }
            });

            return chosen;
        }

        function findImageAtSnap(offset) {
            var slide = slideAtSnap(offset);
            return slide ? slide.querySelector('img.product-image-photo, .product-item-photo img, .product-thumb img, img') : null;
        }

        function preloadForOffset(offset) {
            preloadImageOnce(findImageAtSnap(offset));
        }

        /* R22: separado em compute (so leitura de geometria) + apply (so
         * escrita no DOM). Antes, esta funcao lia getBoundingClientRect de
         * cada slide e, na mesma passada, ja escrevia atributos/classes no
         * slide anterior — cada escrita invalidava o layout que o proximo
         * slide precisava ler, forcando reflow sincrono repetido dentro do
         * proprio loop. Ver tambem update(), que agora chama compute() na
         * fase de leitura e apply() na fase de escrita. */
        function computeSlideAccessibilityPlan() {
            var slideEntries = qsa('.awa-carousel__slide', viewport)
                .map(function (slide) {
                    return { slide: slide, rect: visibleBoxRect(slide) };
                })
                .filter(function (entry) {
                    return !!entry.rect;
                });
            var viewportRect = viewport.getBoundingClientRect();
            var active = activeSlideMeta();
            var totalSlides = slideEntries.length;

            return slideEntries.map(function (entry, index) {
                var slide = entry.slide;
                var rect = entry.rect;
                var activeElementInside = document.activeElement && slide.contains(document.activeElement);
                var visible = activeElementInside ||
                    (rect.right > viewportRect.left + 8 && rect.left < viewportRect.right - 8);

                return {
                    slide: slide,
                    index: index,
                    total: totalSlides,
                    visible: visible,
                    isCurrent: index + 1 === active.index
                };
            });
        }

        function applySlideAccessibilityPlan(plan) {
            var focusableSelector = 'a[href], button, input, select, textarea, [tabindex]';

            plan.forEach(function (entry) {
                var slide = entry.slide;

                /* A11Y-001: role="group" nao e permitido em <li> (aria-allowed-role) e
                 * quebra o parentesco <ul>/<li> exigido pelo audit "list" (axe-core).
                 * O <li> ja carrega semantica de item de lista via role implicito
                 * "listitem"; aria-roledescription/aria-label continuam funcionando
                 * normalmente sobre esse role implicito, entao a remocao do role
                 * explicito nao reduz a informacao exposta ao leitor de tela. */
                slide.setAttribute('aria-roledescription', shelfI18n('slide', 'slide'));
                slide.setAttribute('aria-label', (entry.index + 1) + ' ' + shelfI18n('of', 'de') + ' ' + entry.total);
                slide.setAttribute('aria-hidden', entry.visible ? 'false' : 'true');
                slide.classList.toggle('is-awa-visible-slide', entry.visible);
                slide.classList.toggle('is-awa-current-slide', entry.isCurrent);
                if (entry.isCurrent) {
                    slide.setAttribute('aria-current', 'true');
                } else {
                    slide.removeAttribute('aria-current');
                }

                qsa(focusableSelector, slide).forEach(function (focusable) {
                    if (focusable.closest('.awa-owl-nav, .awa-carousel__nav')) {
                        return;
                    }
                    updateFocusableState(focusable, entry.visible);
                });
            });
        }

        function targetSnap(direction) {
            var offsets = snapOffsets();
            var x = viewport.scrollLeft;
            var fallback = Math.max(180, Math.round(viewport.clientWidth * 0.72));
            var max = carouselMaxScroll(viewport);

            if (!offsets.length) {
                return Math.max(0, Math.min(max, x + (direction * fallback)));
            }

            if (direction > 0) {
                for (var i = 0; i < offsets.length; i += 1) {
                    if (offsets[i] > x + 4) {
                        return Math.min(max, offsets[i]);
                    }
                }
                return max;
            }

            for (var j = offsets.length - 1; j >= 0; j -= 1) {
                if (offsets[j] < x - 4) {
                    return Math.max(0, offsets[j]);
                }
            }

            return 0;
        }

        function emitViewportAnalytics(action, extra) {
            var meta = activeSlideMeta();
            var payload = extra || {};

            payload.label = payload.label || viewport.getAttribute('aria-label') || '';
            payload.index = payload.index || meta.index;
            payload.total = payload.total || meta.total;
            carouselAnalytics(action, payload);
        }

        function step(direction, source) {
            var target = targetSnap(direction);

            preloadForOffset(target);
            emitViewportAnalytics(direction > 0 ? 'next' : 'prev', {
                direction: direction > 0 ? 'next' : 'prev',
                source: source || 'button'
            });

            viewport.scrollTo({
                left: target,
                behavior: reducedMotion() ? 'auto' : 'smooth'
            });
        }

        /* R22: fase de leitura — TODO acesso a geometria/layout
         * (scrollWidth/clientWidth, scrollLeft, offsetLeft,
         * getBoundingClientRect, getComputedStyle) acontece aqui, e o
         * resultado e retornado como uma funcao "apply" contendo APENAS
         * escritas no DOM, para ser executada depois — por este carrossel
         * E por todos os outros pendentes no mesmo frame (ver
         * queueCarouselFrame/runCarouselFrame no topo do arquivo). Antes
         * desta mudanca, cada carrossel lia e escrevia no DOM dentro da
         * mesma chamada de update(), e com dezenas de carrosseis na home
         * disparando update() no mesmo frame (resize, boot), a escrita do
         * carrossel N sujava o layout que a leitura do carrossel N+1
         * precisava, forcando reflow sincrono repetido — confirmado pelo
         * audit "Forced reflow" do Lighthouse (~16s de main-thread so na
         * leitura de scrollWidth/clientWidth no mobile). */
        function measureUpdate() {
            invalidateFrameCache();

            var max = carouselMaxScroll(viewport);
            var x = viewport.scrollLeft;
            var scrollable = max > 2;
            var carouselRoot = viewport.closest('.awa-carousel');
            var host = chrome.nav && chrome.nav.parentElement ? chrome.nav.parentElement : null;
            var meta = activeSlideMeta();
            var accessibilityPlan = computeSlideAccessibilityPlan();
            var liveLabel = computeLiveLabel(viewport);
            var viewportIsOnScreen = !!(carouselVisibilityState && carouselVisibilityState.get(viewport) === true);
            var preloadImg = (scrollable && viewportIsOnScreen) ? findImageAtSnap(targetSnap(1)) : null;

            return function applyUpdate() {
                if (carouselRoot) {
                    carouselRoot.classList.toggle('is-awa-scrollable', scrollable);
                }
                if (mount) {
                    mount.classList.toggle('is-awa-scrollable', scrollable);
                    mount.classList.toggle('is-awa-not-scrollable', !scrollable);
                }
                if (host) {
                    host.classList.toggle('has-carousel-overflow', scrollable);
                    host.classList.toggle('is-awa-not-scrollable', !scrollable);
                }
                if (chrome.nav) {
                    chrome.nav.hidden = !scrollable;
                    chrome.nav.setAttribute('aria-hidden', scrollable ? 'false' : 'true');
                }
                if (chrome.progress) {
                    chrome.progress.hidden = !scrollable;
                    chrome.progress.setAttribute('aria-hidden', scrollable ? 'false' : 'true');
                }
                syncAutoplayToggleAvailability(chrome, isHomePage() && !reducedMotion() && scrollable, !!(mount && mount.dataset.awaCarouselPaused === '1'));
                if (prev) {
                    var atStart = x <= 2;
                    prev.classList.toggle('is-disabled', atStart);
                    prev.disabled = atStart;
                    prev.setAttribute('aria-disabled', atStart ? 'true' : 'false');
                }
                if (next) {
                    var atEnd = x >= max - 2;
                    next.classList.toggle('is-disabled', atEnd);
                    next.disabled = atEnd;
                    next.setAttribute('aria-disabled', atEnd ? 'true' : 'false');
                }
                if (progressBar) {
                    var rawPct = max <= 0 ? 1 : Math.min(1, Math.max(0, x / max));
                    var visualPct = scrollable ? Math.max(0.08, rawPct) : 1;
                    var valueText = shelfI18n('progressText', 'Item') + ' ' + meta.index + ' ' + shelfI18n('of', 'de') + ' ' + meta.total;

                    progressBar.style.setProperty('--awa-progress', String(visualPct));
                    viewport.dataset.awaCarouselIndex = String(meta.index);
                    if (chrome.progress) {
                        chrome.progress.setAttribute('aria-valuenow', String(Math.round(rawPct * 100)));
                        chrome.progress.setAttribute('aria-valuetext', valueText);
                    }
                    if (progressText) {
                        progressText.textContent = valueText;
                    }
                }
                if (preloadImg) {
                    preloadImageOnce(preloadImg);
                }
                applySlideAccessibilityPlan(accessibilityPlan);
                applyLiveLabel(chrome.live, liveLabel);
            };
        }

        function scheduleUpdate() {
            if (!isCarouselViewportRelevant(viewport)) {
                return;
            }
            if (!rafPending) {
                rafPending = true;
                queueCarouselFrame(function () {
                    rafPending = false;
                    return measureUpdate();
                });
            }
        }

        function markUserControl(active) {
            if (mount) {
                mount.classList.toggle('is-user-controlling', !!active);
            }
        }

        if (prev && prev.dataset.awaScrollBtnBound !== '1') {
            prev.dataset.awaScrollBtnBound = '1';
            prev.addEventListener('click', function () { step(-1, 'button'); });
        }
        if (next && next.dataset.awaScrollBtnBound !== '1') {
            next.dataset.awaScrollBtnBound = '1';
            next.addEventListener('click', function () { step(1, 'button'); });
        }
        if (!alreadyBound) {
            viewport.setAttribute('tabindex', '0');
            viewport.addEventListener('keydown', function (event) {
                var key = event.key;
                if (key === 'ArrowRight') {
                    event.preventDefault();
                    step(1, 'keyboard');
                } else if (key === 'ArrowLeft') {
                    event.preventDefault();
                    step(-1, 'keyboard');
                } else if (key === 'Home') {
                    event.preventDefault();
                    emitViewportAnalytics('jump_start', { direction: 'start', source: 'keyboard' });
                    viewport.scrollTo({ left: 0, behavior: reducedMotion() ? 'auto' : 'smooth' });
                } else if (key === 'End') {
                    event.preventDefault();
                    emitViewportAnalytics('jump_end', { direction: 'end', source: 'keyboard' });
                    viewport.scrollTo({
                        left: viewport.scrollWidth,
                        behavior: reducedMotion() ? 'auto' : 'smooth'
                    });
                }
            });
            viewport.addEventListener('scroll', scheduleUpdate, { passive: true });
            viewport.addEventListener('pointerenter', function () {
                stopLegacyAutoplay(viewport);
                markUserControl(true);
            }, { passive: true });
            viewport.addEventListener('pointerleave', function () {
                markUserControl(false);
            }, { passive: true });
            viewport.addEventListener('focusin', function () {
                stopLegacyAutoplay(viewport);
                markUserControl(true);
            });
            viewport.addEventListener('focusout', function () {
                markUserControl(false);
            });
            viewport.addEventListener('touchstart', function () {
                stopLegacyAutoplay(viewport);
                markUserControl(true);
            }, { passive: true });
            viewport.addEventListener('pointerdown', function (event) {
                dragStartX = event.clientX || 0;
                dragStartScroll = viewport.scrollLeft;
                dragTracking = true;
            }, { passive: true });
            viewport.addEventListener('pointerup', function (event) {
                var deltaX;
                var deltaScroll;

                if (!dragTracking) {
                    return;
                }
                dragTracking = false;
                deltaX = (event.clientX || 0) - dragStartX;
                deltaScroll = viewport.scrollLeft - dragStartScroll;
                if (Math.abs(deltaX) < 24 && Math.abs(deltaScroll) < 32) {
                    return;
                }
                emitViewportAnalytics('swipe', {
                    direction: deltaScroll > 0 || deltaX < 0 ? 'next' : 'prev',
                    source: 'pointer'
                });
            }, { passive: true });
            viewport.addEventListener('pointercancel', function () {
                dragTracking = false;
            }, { passive: true });
            viewport.addEventListener('click', function (event) {
                var link;
                var meta;

                if (!event.target || !event.target.closest) {
                    return;
                }
                link = event.target.closest('a[href], button');
                if (!link || !viewport.contains(link)) {
                    return;
                }
                meta = productMetaFromTarget(link, viewport);
                if (!meta.product && !meta.url) {
                    return;
                }
                carouselAnalytics(link.matches('.b2b-login-link, .b2b-login-to-buy-btn, button') ? 'cta_click' : 'product_click', {
                    label: viewport.getAttribute('aria-label') || '',
                    index: meta.index,
                    total: meta.total,
                    product: meta.product,
                    url: meta.url,
                    target: textLabel(link),
                    source: 'click'
                });
            }, true);
            window.addEventListener('resize', scheduleUpdate, { passive: true });
            document.addEventListener('awa:carousel:refresh', scheduleUpdate);
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    stopLegacyAutoplay(viewport);
                }
            });

            if (typeof IntersectionObserver !== 'undefined') {
                new IntersectionObserver(function (entries, observer) {
                    entries.forEach(function (entry) {
                        if (entry.target !== viewport || !entry.isIntersecting || viewport.dataset.awaCarouselImpression === '1') {
                            return;
                        }
                        viewport.dataset.awaCarouselImpression = '1';
                        emitViewportAnalytics('impression', { source: 'viewport' });
                        observer.disconnect();
                    });
                }, { rootMargin: '0px 0px -20% 0px', threshold: 0.24 }).observe(viewport);
            } else {
                viewport.dataset.awaCarouselImpression = '1';
                emitViewportAnalytics('impression', { source: 'fallback' });
            }

            observeCarouselVisibility(viewport, scheduleUpdate);
        }

        bindAutoMotion(viewport, chrome, function () {
            step(1, 'autoplay');
        });
        syncAutoplayToggleAvailability(chrome, isHomePage() && !reducedMotion(), !!(mount && mount.dataset.awaCarouselPaused === '1'));
        /* scheduleUpdate() (nao update() direto) para que o boot inicial de
         * varios carrosseis eager entre no mesmo lote via
         * queueCarouselFrame — ver comentario em measureUpdate(). */
        scheduleUpdate();
        // Reavalia apos imagens/fonte assentarem (largura do track muda)
        window.setTimeout(scheduleUpdate, 400);
        window.setTimeout(scheduleUpdate, 1200);
    }

    function initScrollShelf(shelf) {
        if (!shelf || shelf.classList.contains('awa-carousel-ready')) {
            return;
        }
        var structure = ensureShelfStructure(shelf);
        if (!structure || !structure.container || !structure.track) {
            return;
        }

        markPending(structure.shelf);

        var chrome = prepareChrome(structure.shelf, structure.container);
        wireScroll(structure.container, chrome);

        markReady(structure.shelf, structure.container);
        if (isHomePage()) {
            refreshOneShelf(structure.shelf);
        }
    }

    /* Grupo D (Rokanthemes Categorytab / ProductTab): o markup agrupa N produtos
       por `.product_row` (linhas do owl). Achatamos as linhas e tratamos o slider
       (.category_tab_slider / .productTabContent) como o shelf, montando a estrutura
       canônica. Delegado por tab-carousel-init.js via AWA_SHELF_CAROUSEL.scan. */
    var TAB_SLIDER_SELECTORS = ['.category_tab_slider', '.productTabContent'];

    function flattenProductRows(scope) {
        qsa('.product_row', scope).forEach(function (row) {
            var parent = row.parentNode;
            if (!parent) {
                return;
            }
            while (row.firstChild) {
                parent.insertBefore(row.firstChild, row);
            }
            parent.removeChild(row);
        });
    }

    function normalizeTabSlider(slider) {
        if (!slider || slider.dataset.awaSliderNorm === '1') {
            return null;
        }
        if (slider.offsetParent === null) {
            return null;
        }
        slider.dataset.awaSliderNorm = '1';

        flattenProductRows(slider);
        slider.classList.remove('owl', 'owl-carousel', 'owl-loaded', 'owl-theme');

        var carousel = document.createElement('div');
        carousel.className = 'awa-carousel';
        var viewport = document.createElement('div');
        viewport.className = 'awa-carousel__viewport';
        viewport.setAttribute('role', 'region');
        viewport.setAttribute('aria-roledescription', 'carrossel');
        var track = document.createElement('div');
        track.className = 'awa-carousel__track';

        while (slider.firstChild) {
            track.appendChild(slider.firstChild);
        }
        viewport.appendChild(track);
        carousel.appendChild(viewport);
        slider.appendChild(carousel);

        if (!slider.classList.contains('awa-shelf')) {
            slider.classList.add('awa-shelf', 'awa-shelf--carousel');
        }
        viewport.setAttribute('aria-label', getSectionLabel(slider));
        applyCarouselModifiers(slider, carousel);

        Array.prototype.slice.call(track.children).forEach(function (slide) {
            if (slide.nodeType === 1) {
                slide.classList.add('awa-carousel__slide');
            }
        });

        markProductCards(slider);
        markExistingImagesLoaded(slider);
        bindImageLoadEqualize(slider);

        return {
            shelf: slider,
            container: viewport,
            track: track
        };
    }

    function scanTabSliders(scope) {
        TAB_SLIDER_SELECTORS.forEach(function (sel) {
            qsa(sel, scope).forEach(function (slider) {
                if (isHomePage()) {
                    enqueueShelfInit(slider);
                    return;
                }
                var structure = normalizeTabSlider(slider);
                if (!structure) {
                    return;
                }
                markPending(structure.shelf);
                var chrome = prepareChrome(structure.shelf, structure.container);
                wireScroll(structure.container, chrome);
                markReady(structure.shelf, structure.container);
            });
        });
    }

    function whenVisible(el, cb, rootMargin) {
        if (typeof IntersectionObserver === 'undefined') {
            window.setTimeout(cb, 120);
            return;
        }
        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                obs.disconnect();
                cb();
            });
        }, { rootMargin: rootMargin || '480px 0px', threshold: 0.01 });
        obs.observe(el);
    }

    function isEagerHomeShelf(shelf) {
        var section = shelf && shelf.closest ? shelf.closest('.awa-carousel-section') : null;
        if (!section) {
            return false;
        }
        return section.classList.contains('awa-carousel-section--featured') ||
            section.classList.contains('awa-carousel-section--standard') ||
            section.classList.contains('awa-carousel-section--compact');
    }

    /** PDP: related, upsell e mostviewed precisam de nav/progresso sem esperar scroll do usuario. */
    function isEagerPdpShelf(shelf) {
        if (!shelf || !document.body || !document.body.classList.contains('catalog-product-view')) {
            return false;
        }
        return shelf.matches('.rokan-mostviewed, .block.related, .block.upsell, .awa-pdp-related');
    }

    function isEagerShelf(shelf) {
        return isEagerHomeShelf(shelf) || isEagerPdpShelf(shelf);
    }

    function scanShelf(root) {
        var scope = root && root.nodeType === 1 ? root : document;
        var NESTED_GUARD = SHELF_SELECTORS.join(',');
        var home = isHomePage();
        SHELF_SELECTORS.forEach(function (sel) {
            qsa(sel, scope).forEach(function (shelf) {
                if (shelf.dataset.awaScrollReady === '1') {
                    if (!shelf.classList.contains('awa-carousel-ready') && visibleBox(shelf)) {
                        enqueueShelfInit(shelf);
                    }
                    return;
                }
                // Wrapper externo que contém OUTRO shelf (ex.: .awa-pdp-related > .rokan-mostviewed):
                // inicializar ambos cria nav/progress duplicados. O shelf interno (onde vive o track)
                // é o real; marca o externo como pronto e pula.
                if (shelf.querySelector(NESTED_GUARD)) {
                    shelf.dataset.awaScrollReady = '1';
                    return;
                }
                if (shelf.matches('.block.related, .block.upsell')) {
                    prepareRelatedBlock(shelf);
                }
                shelf.dataset.awaScrollReady = '1';
                /* Home: never eager-init ALL featured/standard/compact at once.
                 * IO + idle queue inits only the intersecting shelf. */
                if (isEagerShelf(shelf) && !home) {
                    enqueueShelfInit(shelf);
                    return;
                }
                whenVisible(shelf, function () {
                    enqueueShelfInit(shelf);
                }, home ? '160px 0px' : '480px 0px');
            });
        });

        scanTabSliders(scope);

        if (!home) {
            mountAllShelfNavInHeaders(scope);
            document.dispatchEvent(new CustomEvent('awa:carousel:refresh'));
        }
    }

    function guardOverflow() {
        var docW = document.documentElement.clientWidth;

        /* R22: mede a largura de TODOS os viewports antes de aplicar
         * qualquer classList.add — caso contrario, a escrita no 1o viewport
         * invalida o layout que o 2o precisa ler (forced reflow por item). */
        var overflowing = qsa('.awa-carousel__viewport').filter(function (el) {
            return el.getBoundingClientRect().width > docW + 2;
        });

        overflowing.forEach(function (el) {
            el.classList.add('is-awa-viewport-width-guarded');
        });
    }

    function scheduleCarouselRefresh(includeScan) {
        window.clearTimeout(scheduleCarouselRefresh._timer);
        scheduleCarouselRefresh._timer = window.setTimeout(function () {
            if (includeScan) {
                scanShelf(document);
            } else {
                mountAllShelfNavInHeaders(document);
                document.dispatchEvent(new CustomEvent('awa:carousel:refresh'));
            }
            guardOverflow();
        }, REFRESH_DELAY);
    }

    function refreshMountedNav(scope) {
        mountAllShelfNavInHeaders(scope || document);
        document.dispatchEvent(new CustomEvent('awa:carousel:refresh'));
        guardOverflow();
    }

    function bindDynamicRefreshTriggers() {
        if (document.documentElement.dataset.awaCarouselRefreshTriggersBound === '1') {
            return;
        }
        document.documentElement.dataset.awaCarouselRefreshTriggersBound = '1';

        document.addEventListener('click', function (event) {
            if (!isHomePage() || !event.target || !event.target.closest) {
                return;
            }
            if (event.target.closest('.awa-shelf__view-all, .awa-section-header__link')) {
                carouselAnalytics('view_all_click', {
                    label: textLabel(event.target.closest('.awa-carousel-nav-host, .awa-section-header') || event.target),
                    target: textLabel(event.target.closest('a, button') || event.target),
                    url: event.target.closest('a[href]') ? event.target.closest('a[href]').href : '',
                    source: 'click'
                });
            }
        }, true);

    }

    function refreshOneShelf(shelf) {
        if (!shelf || !shelf.nodeType) {
            return;
        }
        mountAllShelfNavInHeaders(shelf);
        var viewport = shelf.querySelector ? shelf.querySelector('.awa-carousel__viewport') : null;
        if (!viewport) {
            return;
        }
        var docW = document.documentElement.clientWidth;
        if (viewport.getBoundingClientRect().width > docW + 2) {
            viewport.classList.add('is-awa-viewport-width-guarded');
        }
    }

    function initTabSliderShelf(slider) {
        if (slider && slider.classList.contains('awa-carousel-ready')) {
            return;
        }
        var structure = normalizeTabSlider(slider);
        if (!structure) {
            return;
        }
        markPending(structure.shelf);
        var chrome = prepareChrome(structure.shelf, structure.container);
        wireScroll(structure.container, chrome);
        markReady(structure.shelf, structure.container);
        if (isHomePage()) {
            refreshOneShelf(structure.shelf);
        }
    }

    function observeHomeShelves() {
        var NESTED_GUARD = SHELF_SELECTORS.join(',');
        var io = null;

        function shelfAlreadyReady(el) {
            return !!(el && (
                el.classList.contains('awa-carousel-ready') ||
                el.dataset.awaCarouselQueued === '1'
            ));
        }

        function observe(el) {
            if (!el || el.dataset.awaHomeObserved === '1') {
                return;
            }
            if (shelfAlreadyReady(el)) {
                el.dataset.awaHomeObserved = '1';
                return;
            }
            el.dataset.awaHomeObserved = '1';
            if (io) {
                io.observe(el);
                return;
            }
            whenVisible(el, function () {
                if (shelfAlreadyReady(el)) {
                    return;
                }
                enqueueShelfInit(el);
            }, '160px 0px');
        }

        if (typeof IntersectionObserver !== 'undefined') {
            io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var el = entry.target;
                    /* Home freeze r3: Mais Vendidos re-entry must be a no-op. */
                    if (shelfAlreadyReady(el)) {
                        io.unobserve(el);
                        return;
                    }
                    if (!entry.isIntersecting) {
                        return;
                    }
                    io.unobserve(el);
                    enqueueShelfInit(el);
                });
            }, { rootMargin: '160px 0px', threshold: 0.01 });
        }

        SHELF_SELECTORS.forEach(function (sel) {
            qsa(sel, document).forEach(function (shelf) {
                if (shelf.dataset.awaScrollReady === '1') {
                    return;
                }
                if (shelf.querySelector(NESTED_GUARD)) {
                    shelf.dataset.awaScrollReady = '1';
                    return;
                }
                if (shelf.matches('.block.related, .block.upsell')) {
                    prepareRelatedBlock(shelf);
                }
                shelf.dataset.awaScrollReady = '1';
                observe(shelf);
            });
        });

        TAB_SLIDER_SELECTORS.forEach(function (sel) {
            qsa(sel, document).forEach(function (slider) {
                if (slider.dataset.awaSliderNorm === '1' || slider.dataset.awaHomeObserved === '1') {
                    return;
                }
                observe(slider);
            });
        });
    }

    function boot() {
        bindDynamicRefreshTriggers();

        if (isHomePage()) {
            /* Home freeze r3: IntersectionObserver + idle one-shelf pump only.
             * Ready/queued shelves are unobserved (no re-init on Mais Vendidos re-entry).
             * Do not scanShelf(document)/refreshMountedNav(document)/guardOverflow()
             * immediately, on load, delayed timers, observer, or window scroll. */
            observeHomeShelves();
            return;
        }

        scanShelf(document);
        refreshMountedNav(document);
        guardOverflow();

        window.addEventListener('load', function () {
            scanShelf(document);
            refreshMountedNav(document);
            guardOverflow();
        }, { once: true });

        if (window.jQuery) {
            window.jQuery(document).on('ajaxComplete contentUpdated awa:shelf:refresh', function () {
                window.clearTimeout(boot._ajaxTimer);
                boot._ajaxTimer = window.setTimeout(function () {
                    scanShelf(document);
                    refreshMountedNav(document);
                    guardOverflow();
                }, 180);
            });
        }

        if (typeof MutationObserver !== 'undefined') {
            var mutationRoot = document.querySelector('.page-wrapper') || document.body;
            var timer = null;
            new MutationObserver(function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    scanShelf(document);
                    refreshMountedNav(document);
                }, 300);
            }).observe(mutationRoot, { childList: true, subtree: false });
        }
    }

    function noop() {}

    /**
     * Terminal adapt Fase 3 — append após cascade-lock (body-end); só home CMS.
     */
    function injectImpeccableAdaptTerminal() {
        var body = document.body;
        var homeId = 'awa-impeccable-fase3-adapt';

        if (!body) {
            return;
        }

        if (
            !body.classList.contains('cms-index-index') &&
            !body.classList.contains('cms-home') &&
            !body.classList.contains('cms-homepage_ayo_home5')
        ) {
            return;
        }

        var css = ''
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.awa-hero-b2b-cta .awa-hero-trust-strip{'
            + 'display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:16px!important}'
            + '@media (min-width:576px) and (max-width:991px){'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.awa-hero-b2b-cta .awa-hero-trust-strip{'
            + 'grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:20px!important}'
            + '}'
            + '@media (min-width:992px){'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.awa-hero-b2b-cta .awa-hero-trust-strip{'
            + 'grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:24px!important}'
            + '}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + ':is(.awa-section-header,.awa-category-carousel__header,.awa-shelf__header){'
            + 'margin-bottom:24px!important;margin-block-end:24px!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.awa-carousel-section--compact .awa-section-header{'
            + 'margin-bottom:16px!important;margin-block-end:16px!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.item-product.awa-carousel-card-slot :is(.actions-primary .action,.btn-add-to-cart,.action.tocart){'
            + 'min-height:44px!important;box-sizing:border-box!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.item-product.awa-carousel-card-slot :is(.b2b-login-to-buy-btn,.b2b-login-to-see-price a){'
            + 'white-space:normal!important;overflow-wrap:anywhere!important;line-height:1.25!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.awa-shelf--carousel .awa-owl-nav__btn:is(:disabled,.is-disabled,[aria-disabled="true"]){'
            + 'opacity:.45!important;cursor:not-allowed!important;pointer-events:none!important}'
            + '@media (prefers-reduced-motion:reduce){'
            + '#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .awa-shelf--carousel '
            + '.awa-carousel__viewport{scroll-behavior:auto!important}'
            + '}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.ayo-home5-wrapper .awa-shelf--carousel .awa-owl-nav__btn,'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper '
            + '.awa-shelf--carousel .awa-owl-nav__btn{'
            + 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important}'
            + 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-trust-grid{'
            + 'display:grid!important;gap:16px!important;grid-template-columns:minmax(0,1fr)!important}'
            + '@media (min-width:576px) and (max-width:991px){'
            + 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-trust-grid{'
            + 'grid-template-columns:repeat(2,minmax(0,1fr))!important}'
            + '}'
            + '@media (min-width:992px){'
            + 'html body#html-body .page-wrapper :is(.page_footer,.page-footer) .awa-footer-trust-grid{'
            + 'grid-template-columns:repeat(4,minmax(0,1fr))!important}'
            + '}';


        css += ''
            + '/* HOME-AUDIT-JS-TERMINAL-TOUCH-20260604 */'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .top-home-content--above-fold .awa-hero-swiper__nav{'
            + 'width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) :is(.page_footer,.page-footer) .footer-tags{gap:8px!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) :is(.page_footer,.page-footer) .footer-tags a{'
            + 'height:auto!important;min-height:44px!important;min-width:44px!important;padding:10px 14px!important;line-height:1.2!important}';


        css += ''
            + '/* HOME-AUDIT-JS-FOOTER-TOUCH-20260604 */'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) :is(.page_footer,.page-footer) '
            + ':is(.awa-footer-section__toggle,.awa-footer-categories-expand__toggle){'
            + 'align-items:center!important;display:flex!important;height:auto!important;min-height:44px!important;padding:10px 0!important;line-height:1.2!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) :is(.page_footer,.page-footer) a{'
            + 'align-items:center!important;display:inline-flex!important;height:auto!important;min-height:44px!important;min-width:44px!important;padding-top:13px!important;padding-bottom:13px!important;line-height:1.2!important;text-decoration:none!important}';


        css += ''
            + '/* HOME-AUDIT-JS-BOTTOM-MODAL-TOUCH-20260604 */'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + ':is(.fixed-bottom,.awa-mobile-bottom-nav) :is(a,button,.toggle-nav-footer){'
            + 'align-items:center!important;display:inline-flex!important;justify-content:center!important;height:auto!important;min-height:44px!important;min-width:44px!important;padding:10px 12px!important;line-height:1.2!important;text-decoration:none!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) '
            + ':is(.b2b-login-modal-close,.b2b-login-option,.b2b-login-modal a,.b2b-login-modal button){'
            + 'align-items:center!important;display:inline-flex!important;justify-content:center!important;height:auto!important;min-height:44px!important;min-width:44px!important;padding:10px 12px!important;line-height:1.2!important;text-decoration:none!important}';


        css += ''
            + '/* HOME-AUDIT-JS-LOGO-TOUCH-20260604 */'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-header-brand .logo a,'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) .page-wrapper .awa-header-brand a.logo{'
            + 'align-items:center!important;display:inline-flex!important;justify-content:center!important;min-height:44px!important;min-width:44px!important;line-height:1!important}';


        css += ''
            + '/* VISUAL-CRAWL-HOME-CONTRAST-20260604 */'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) :is(.page_footer,.page-footer) :is(.awa-footer-trust-item,.awa-footer-business-contact__action,.awa-footer-atendimento__store-badge){'
            + 'background-color:color-mix(in srgb,var(--awa-text, #333) 72%, transparent)!important;color:var(--awa-text-inverse, #fff)!important}'
            + 'html body#html-body:is(.cms-index-index,.cms-home,.cms-homepage_ayo_home5) :is(.page_footer,.page-footer) :is(.awa-footer-trust-copy strong,.awa-footer-trust-copy span,.awa-footer-business-contact__action-copy,.awa-footer-business-contact__action-copy strong,.awa-footer-business-contact__action-copy small,.awa-footer-atendimento__store-badge){'
            + 'color:var(--awa-text-inverse, #fff)!important;text-shadow:none!important}';

        var style = document.getElementById(homeId);

        if (!style) {
            style = document.createElement('style');
            style.id = homeId;
            document.body.appendChild(style);
        }

        style.textContent = css;
    }

    window.AWA_SHELF_CAROUSEL = {
        engine: 'css-scroll-snap',
        scan: scanShelf,
        mountNav: refreshMountedNav,
        equalize: noop,
        scheduleEqualize: noop,
        scheduleEqualizeAll: noop
    };

    function scheduleAdaptTerminal() {
        // Contrato global de carrossel agora vem de assets estáticos do tema.
        // Mantemos o hook por retrocompatibilidade sem injetar CSS dinâmico.
        return;
    }

    /* Harden: terminal antes do boot do carrossel (vence cascade-lock no body-end). */
    scheduleAdaptTerminal();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}());
