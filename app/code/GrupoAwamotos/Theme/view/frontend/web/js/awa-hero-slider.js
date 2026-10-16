/**
 * AWA Motos - Hero slider homepage (Swiper 11).
 * Above-the-fold initializer with no blocking AMD dependency besides RequireJS itself.
 */
define([], function () {
    'use strict';

    var swiperAssetLoading = false;
    var swiperRetryBySlider = {};
    var SWIPER_RETRY_MAX = 60;
    var SWIPER_RETRY_DELAY_MS = 250;

    function isPlainObject(value)
    {
        return !!value && Object.prototype.toString.call(value) === '[object Object]';
    }

    function deepMerge(target)
    {
        var output = target || {};
        var i;
        var source;
        var key;

        for (i = 1; i < arguments.length; i++) {
            source = arguments[i];
            if (!source) {
                continue;
            }

            Object.keys(source).forEach(function (sourceKey) {
                key = sourceKey;
                if (isPlainObject(source[key])) {
                    output[key] = deepMerge(isPlainObject(output[key]) ? output[key] : {}, source[key]);
                    return;
                }

                output[key] = source[key];
            });
        }

        return output;
    }

    function prefersReducedMotion()
    {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function prefersFinePointer()
    {
        return !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
    }

    function autoplayConfig(value)
    {
        var delay;

        if (value === false || value === 0 || value === '0' || value === 'false') {
            return false;
        }

        delay = parseInt(String(value), 10);
        if (isNaN(delay) || delay < 1000) {
            delay = 5000;
        }

        return {
            delay: delay,
            disableOnInteraction: false,
            pauseOnMouseEnter: prefersFinePointer()
        };
    }

    function animateBannerText(slideEl)
    {
        if (!slideEl) {
            return;
        }

        slideEl.querySelectorAll(".text-banner [data-animation^='animated']").forEach(function (item) {
            var animation = item.getAttribute('data-animation');

            if (!animation) {
                return;
            }

            item.classList.add(animation);
            window.setTimeout(function () {
                item.classList.remove(animation);
            }, 900);
        });
    }

    function getSwiperCtor()
    {
        if (typeof window.Swiper === 'function') {
            return window.Swiper;
        }

        if (window.Swiper && typeof window.Swiper.default === 'function') {
            return window.Swiper.default;
        }

        if (window.Swiper && typeof window.Swiper.Swiper === 'function') {
            return window.Swiper.Swiper;
        }

        return null;
    }

    function resolveSwiperAssetUrl()
    {
        var url;
        var requireScript;
        var staticBase;

        if (typeof window.require === 'function' && typeof window.require.toUrl === 'function') {
            url = window.require.toUrl('swiper');
            return /\.js(?:\?|#|$)/.test(url) ? url : (url + '.js');
        }

        requireScript = document.querySelector('script[src*="/requirejs/require"]');
        if (requireScript && requireScript.src) {
            staticBase = requireScript.src.split('/requirejs/require')[0];
            return staticBase + '/GrupoAwamotos_Theme/js/vendor/swiper-bundle.min.js';
        }

        return '/static/frontend/AWA_Custom/ayo_home5_child/pt_BR/GrupoAwamotos_Theme/js/vendor/swiper-bundle.min.js';
    }

    function afterHeroLcp(fn)
    {
        var done = false;
        function run(why)
        {
            if (done) {
                return;
            }
            done = true;
fn();
        }
        try {
            new PerformanceObserver(function (list) {
                if (list.getEntries().at(-1)) {
                    window.requestAnimationFrame(function () {
                        window.requestAnimationFrame(function () { run('lcp'); });
                    });
                }
            }).observe({ type: 'largest-contentful-paint', buffered: true });
        } catch (_e) {}
        window.setTimeout(function () { run('timeout'); }, 2200);
    }

    function loadSwiperAsset()
    {
        var existing;
        var script;

        if (getSwiperCtor() || swiperAssetLoading) {
            return;
        }

        existing = document.getElementById('awa-swiper-runtime-script')
            || document.querySelector('script[src*="GrupoAwamotos_Theme/js/vendor/swiper-bundle.min"]');

        if (existing) {
            return;
        }

        swiperAssetLoading = true;
        afterHeroLcp(function () {
            if (getSwiperCtor()) {
                swiperAssetLoading = false;
                return;
            }
            script = document.createElement('script');
            script.id = 'awa-swiper-runtime-script';
            script.async = true;
            script.fetchPriority = 'low';
            script.setAttribute('fetchpriority', 'low');
            script.src = resolveSwiperAssetUrl();
            script.onload = function () {
                swiperAssetLoading = false;
            };
            script.onerror = function () {
                swiperAssetLoading = false;
            };
            document.head.appendChild(script);
        });
    }

    function getHeroState(rawId)
    {
        var sliderId = String(rawId);

        if (!swiperRetryBySlider[sliderId]) {
            swiperRetryBySlider[sliderId] = {
                retryCount: 0,
                scheduled: false,
                timer: null,
                initialized: false
            };
        }

        return swiperRetryBySlider[sliderId];
    }

    function clearHeroRetry(state)
    {
        if (!state || !state.timer) {
            return;
        }

        window.clearTimeout(state.timer);
        state.timer = null;
        state.scheduled = false;
    }

    function scheduleHeroInitRetry(payload, state)
    {
        if (!state || state.initialized || state.scheduled || state.retryCount >= SWIPER_RETRY_MAX) {
            return;
        }

        state.scheduled = true;
        state.retryCount += 1;
        state.timer = window.setTimeout(function () {
            state.scheduled = false;
            initHeroSlider(payload);
        }, SWIPER_RETRY_DELAY_MS);
    }

    function markSlidesReady(target)
    {
        if (!target) {
            return;
        }

        target.querySelectorAll('.swiper-slide').forEach(function (slide) {
            slide.style.setProperty('display', 'block', 'important');
            slide.style.setProperty('visibility', 'visible', 'important');
            slide.style.removeProperty('height');
            slide.style.removeProperty('min-height');
            slide.style.removeProperty('max-height');
        });
    }

    function isMobileHeroTarget(target)
    {
        var root = target && target.closest ? target.closest('.wrapper_slider') : null;
        return !!(root && root.classList.contains('visible-xs'));
    }

    function measureHeroControlOffset(target)
    {
        var controls;
        var offset = 0;

        if (!target) {
            return 0;
        }

        controls = target.querySelectorAll(
            '.swiper-pagination, .swiper-button-prev, .swiper-button-next, .awa-hero-pause-btn'
        );
        if (!controls.length) {
            return 0;
        }

        // Controls are absolutely positioned; only ones anchored past the
        // container's own bottom edge (negative `bottom`) need extra room
        // reserved below the slide content. Reading the declared CSS value
        // (instead of a live bounding rect relative to `target`) avoids
        // measuring against a height this same function previously locked in,
        // which caused an unbounded feedback loop.
        controls.forEach(function (control) {
            var style;
            var bottomValue;

            if (!control) {
                return;
            }

            style = window.getComputedStyle(control);
            if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) {
                return;
            }

            if (style.position !== 'absolute' && style.position !== 'fixed') {
                return;
            }

            bottomValue = parseFloat(style.bottom);
            if (!isNaN(bottomValue) && bottomValue < 0) {
                offset = Math.max(offset, Math.ceil(-bottomValue));
            }
        });

        return offset;
    }

    function lockMobileHeroHeight(target, reason)
    {
        var root;
        var slides;
        var maxSlideHeight = 0;
        var controlOffset;
        var lockHeight;
        var previousLock;

        if (!isMobileHeroTarget(target)) {
            return;
        }

        root = target.closest('.wrapper_slider');
        slides = target.querySelectorAll('.swiper-slide');
        if (!slides.length) {
            return;
        }

        // Swiper's default CSS gives `.swiper-slide` `height: 100%` of its
        // container when `autoHeight` is false (as configured here). Once
        // this function locks a height on `target`, every slide's own
        // bounding rect just reflects that same locked height back — not its
        // real content size — which fed an unbounded loop on re-measurement.
        // `.banner_item_bg` wraps the actual image/content and keeps its
        // intrinsic size regardless of the parent slide's forced height, so
        // measure that instead.
        slides.forEach(function (slide) {
            var style;
            var height;
            var contentEl;

            if (!slide) {
                return;
            }

            style = window.getComputedStyle(slide);
            if (style.display === 'none' || style.visibility === 'hidden') {
                return;
            }

            contentEl = slide.querySelector('.banner_item_bg') || slide;
            height = Math.ceil(contentEl.getBoundingClientRect().height);
            if (height > maxSlideHeight) {
                maxSlideHeight = height;
            }
        });

        if (maxSlideHeight <= 0) {
            return;
        }

        controlOffset = measureHeroControlOffset(target);
        lockHeight = Math.ceil(maxSlideHeight + controlOffset);

        previousLock = parseInt(target.getAttribute('data-awa-hero-locked-height') || '0', 10);

        if (Number.isFinite(previousLock) && previousLock > lockHeight) {
            lockHeight = previousLock;
        }

        if (lockHeight <= 0) {
            return;
        }

        target.setAttribute('data-awa-hero-locked-height', String(lockHeight));
        target.style.setProperty('height', lockHeight + 'px', 'important');
        target.style.setProperty('min-height', lockHeight + 'px', 'important');

        if (root) {
            root.style.setProperty('min-height', lockHeight + 'px', 'important');
        }
    }

    function bindMobileHeroHeightLock(target)
    {
        if (!isMobileHeroTarget(target) || target.getAttribute('data-awa-hero-height-lock-bound') === '1') {
            return;
        }

        target.setAttribute('data-awa-hero-height-lock-bound', '1');
        target.querySelectorAll('img').forEach(function (img) {
            if (!img || img.complete) {
                return;
            }

            img.addEventListener('load', function () {
                lockMobileHeroHeight(target, 'img-load');
            }, { passive: true, once: true });
            img.addEventListener('error', function () {
                lockMobileHeroHeight(target, 'img-error');
            }, { passive: true, once: true });
        });
    }

    function clearMobileAutoplay(swiper)
    {
        if (!swiper || !swiper.__awaMobileAutoplayTimer) {
            return;
        }

        window.clearInterval(swiper.__awaMobileAutoplayTimer);
        swiper.__awaMobileAutoplayTimer = null;
        swiper.__awaMobileAutoplayRunning = false;
    }

    function startMobileAutoplay(swiper, slideCount, autoplaySettings)
    {
        var transitionSpeed;

        clearMobileAutoplay(swiper);

        if (!autoplaySettings || slideCount <= 1) {
            return;
        }

        transitionSpeed = prefersReducedMotion() ? 0 : (Number(swiper.params.speed) || 500);
        swiper.__awaMobileAutoplayTimer = window.setInterval(function () {
            var nextIndex;

            if (swiper.destroyed || document.hidden) {
                return;
            }

            nextIndex = swiper.realIndex + 1;
            if (nextIndex >= slideCount) {
                swiper.slideTo(0, transitionSpeed);
                return;
            }

            swiper.slideTo(nextIndex, transitionSpeed);
        }, autoplaySettings.delay);
        swiper.__awaMobileAutoplayRunning = true;
        swiper.__awaMobileAutoplayPaused = false;
    }

    function isHeroAutoplayRunning(swiper)
    {
        if (!swiper || swiper.destroyed) {
            return false;
        }

        if (swiper.__awaUseMobileAutoplay) {
            return !!(swiper.__awaMobileAutoplayRunning && !swiper.__awaMobileAutoplayPaused);
        }

        return !!(swiper.autoplay && swiper.autoplay.running);
    }

    function swipeHasAutoplay(swiper)
    {
        if (!swiper || prefersReducedMotion()) {
            return false;
        }

        if (swiper.__awaUseMobileAutoplay) {
            return !!(swiper.__awaMobileAutoplaySettings && swiper.__awaSlideCount > 1);
        }

        return !!(swiper.params && swiper.params.autoplay && swiper.params.autoplay !== false);
    }

    function pauseHeroAutoplay(swiper)
    {
        if (!swiper || swiper.destroyed) {
            return;
        }

        if (swiper.__awaUseMobileAutoplay) {
            clearMobileAutoplay(swiper);
            swiper.__awaMobileAutoplayPaused = true;
            return;
        }

        if (swiper.autoplay && swiper.autoplay.running) {
            swiper.autoplay.stop();
        }
    }

    function resumeHeroAutoplay(swiper)
    {
        var settings;

        if (!swiper || swiper.destroyed) {
            return;
        }

        if (swiper.__awaUseMobileAutoplay) {
            settings = swiper.__awaMobileAutoplaySettings;
            if (settings && swiper.__awaSlideCount > 1) {
                startMobileAutoplay(swiper, swiper.__awaSlideCount, settings);
                swiper.__awaMobileAutoplayPaused = false;
            }
            return;
        }

        if (swiper.autoplay) {
            swiper.autoplay.start();
        }
    }

    function setHeroPauseButtonState(swiper, pauseBtn, iconEl, pauseLabel, resumeLabel)
    {
        if (!pauseBtn || !iconEl) {
            return;
        }

        if (!swipeHasAutoplay(swiper)) {
            pauseBtn.classList.remove('awa-hero-pause-btn--visible');
            pauseBtn.style.display = 'none';
            return;
        }

        pauseBtn.style.display = '';
        pauseBtn.classList.add('awa-hero-pause-btn--visible');

        if (isHeroAutoplayRunning(swiper)) {
            pauseBtn.setAttribute('aria-pressed', 'false');
            pauseBtn.setAttribute('aria-label', pauseLabel);
            iconEl.textContent = '\u258d\u258d';
            return;
        }

        pauseBtn.setAttribute('aria-pressed', 'true');
        pauseBtn.setAttribute('aria-label', resumeLabel);
        iconEl.textContent = '\u25b6';
    }

    function bindHeroPauseButton(swiper)
    {
        var pauseBtn = swiper && swiper.el ? swiper.el.querySelector('.awa-hero-pause-btn') : null;
        var iconEl;
        var pauseLabel;
        var resumeLabel;

        if (!pauseBtn || pauseBtn.dataset.awaHeroPauseBound === '1') {
            return;
        }

        pauseBtn.dataset.awaHeroPauseBound = '1';
        iconEl = pauseBtn.querySelector('.awa-hero-pause-btn__icon');
        pauseLabel = pauseBtn.getAttribute('data-pause-label') || 'Pausar apresentação';
        resumeLabel = pauseBtn.getAttribute('data-resume-label') || 'Retomar apresentação';

        pauseBtn.addEventListener('click', function () {
            if (isHeroAutoplayRunning(swiper)) {
                pauseHeroAutoplay(swiper);
            } else {
                resumeHeroAutoplay(swiper);
            }

            setHeroPauseButtonState(swiper, pauseBtn, iconEl, pauseLabel, resumeLabel);
        });

        setHeroPauseButtonState(swiper, pauseBtn, iconEl, pauseLabel, resumeLabel);
    }

    /**
     * @param {{sliderId: number, swiperConfig?: object}} payload
     * @returns {boolean}
     */
    function initHeroSlider(payload)
    {
        var sliderId;
        var config;
        var deskSelector;
        var mobSelector;
        var mq;
        var activeMobileSwiper = null;
        var state;
        var SwiperCtor;

        if (!payload || payload.sliderId === undefined || payload.sliderId === null) {
            return false;
        }

        sliderId = Number(payload.sliderId);
        if (!Number.isFinite(sliderId) || sliderId < 0) {
            return false;
        }

        state = getHeroState(sliderId);
        state.initialized = false;
        SwiperCtor = getSwiperCtor();

        if (typeof SwiperCtor !== 'function') {
            loadSwiperAsset();
            scheduleHeroInitRetry(payload, state);
            return false;
        }

        clearHeroRetry(state);
        state.initialized = true;
        state.retryCount = 0;

        config = deepMerge({}, {
            slidesPerView: 1,
            autoHeight: false,
            effect: 'fade',
            loop: true,
            speed: 500,
            navigation: true,
            pagination: true,
            autoplay: false
        }, payload.swiperConfig || {});

        if (prefersReducedMotion()) {
            config.autoplay = false;
            config.speed = 0;
        }

        deskSelector = '.slider_' + sliderId + ' .awa-hero-swiper';
        mobSelector = '.slider_' + sliderId + '_mobile .awa-hero-swiper';
        mq = window.matchMedia('(max-width: 767px)');

        function initHeroSwiper(selector)
        {
            var target = document.querySelector(selector);
            var slideCount;
            var wantsCycle;
            var disableMobileAutoplay;
            var useCustomMobileAutoplay;
            var autoplaySettings;
            var paginationEl;
            var options;
            var instance;

            if (!target || target.getAttribute('data-awa-hero-swiper-init') === '1') {
                return false;
            }

            slideCount = target.querySelectorAll('.swiper-slide').length;
            if (slideCount < 1) {
                return false;
            }

            wantsCycle = slideCount > 1 && config.loop === true;
            // Mobile: keep carousel manual to avoid late autoplay-triggered CLS spikes.
            disableMobileAutoplay = isMobileHeroTarget(target);
            useCustomMobileAutoplay = !disableMobileAutoplay
                && mq.matches
                && wantsCycle
                && config.autoplay !== false
                && !prefersReducedMotion();
            autoplaySettings = slideCount > 1 ? autoplayConfig(config.autoplay) : false;
            if (disableMobileAutoplay) {
                autoplaySettings = false;
            }
            paginationEl = target.querySelector('.swiper-pagination');

            markSlidesReady(target);
            bindMobileHeroHeightLock(target);
            lockMobileHeroHeight(target, 'pre-swiper-init');

            options = deepMerge({}, config, {
                slidesPerView: 1,
                autoHeight: false,
                effect: config.effect || 'fade',
                loop: wantsCycle && slideCount > 3,
                rewind: wantsCycle && slideCount <= 3,
                watchOverflow: true,
                navigation: slideCount > 1 && config.navigation !== false ? {
                    prevEl: target.querySelector('.swiper-button-prev'),
                    nextEl: target.querySelector('.swiper-button-next')
                } : false,
                pagination: slideCount > 1 && config.pagination !== false ? {
                    el: paginationEl,
                    clickable: true,
                    renderBullet: function (index, className) {
                        return '<button class="' + className + '" type="button" aria-label="Ir para o slide ' + (index + 1) + '"></button>';
                    }
                } : false,
                autoplay: disableMobileAutoplay ? false : (useCustomMobileAutoplay ? false : autoplaySettings),
                keyboard: {
                    enabled: true,
                    onlyInViewport: true,
                    pageUpDown: false
                },
                fadeEffect: {
                    crossFade: true
                },
                a11y: {
                    enabled: true,
                    prevSlideMessage: 'Slide anterior',
                    nextSlideMessage: 'Próximo slide',
                    firstSlideMessage: 'Primeiro slide',
                    lastSlideMessage: 'Ultimo slide'
                },
                on: {
                    init: function (swiper) {
                        swiper.el.classList.add('awa-hero-swiper-ready');
                        swiper.el.querySelectorAll('.banner_item').forEach(function (item) {
                            item.classList.remove('awa-hero-fallback-primary', 'awa-hero-fallback-secondary');
                        });

                        markSlidesReady(swiper.el);
                        swiper.update();
                        bindMobileHeroHeightLock(swiper.el);
                        lockMobileHeroHeight(swiper.el, 'swiper-on-init');

                        if (swiper.params.watchOverflow) {
                            swiper.checkOverflow();
                        }

                        if (swiper.autoplay && swiper.params.autoplay && !swiper.autoplay.running) {
                            swiper.autoplay.start();
                        }

                        if (useCustomMobileAutoplay && autoplaySettings) {
                            swiper.__awaSlideCount = slideCount;
                            swiper.__awaUseMobileAutoplay = true;
                            swiper.__awaMobileAutoplaySettings = autoplaySettings;
                            swiper.__awaMobileAutoplayPaused = false;
                            activeMobileSwiper = swiper;
                            startMobileAutoplay(swiper, slideCount, autoplaySettings);
                        } else {
                            swiper.__awaUseMobileAutoplay = false;
                        }

                        bindHeroPauseButton(swiper);
                        animateBannerText(swiper.slides[swiper.activeIndex] || swiper.slides[0]);
                    },
                    slideChangeTransitionStart: function (swiper) {
                        animateBannerText(swiper.slides[swiper.activeIndex]);
                        lockMobileHeroHeight(swiper.el, 'slide-change');
                    },
                    destroy: function (swiper) {
                        clearMobileAutoplay(swiper);

                        if (activeMobileSwiper === swiper) {
                            activeMobileSwiper = null;
                        }
                    }
                }
            });

            target.setAttribute('data-awa-hero-swiper-init', '1');

            try {
                instance = new SwiperCtor(target, options);
                if (!instance) {
                    target.removeAttribute('data-awa-hero-swiper-init');
                    return false;
                }
            } catch (e) {
                target.removeAttribute('data-awa-hero-swiper-init');
                return false;
            }

            return true;
        }

        function activeSelector()
        {
            return mq.matches ? mobSelector : deskSelector;
        }

        function initActiveHeroSwiper()
        {
            return initHeroSwiper(activeSelector());
        }

        function syncAriaAndLazyInit()
        {
            var desk = document.querySelector('.wrapper_slider.hidden-xs.slider_' + sliderId);
            var mob = document.querySelector('.wrapper_slider.visible-xs.slider_' + sliderId + '_mobile');

            if (!desk || !mob) {
                return;
            }

            function apply()
            {
                var isMobile = mq.matches;
                var inactiveRoot;
                var inactiveEl;
                var inactiveSwiper;

                desk.setAttribute('aria-hidden', isMobile ? 'true' : 'false');
                mob.setAttribute('aria-hidden', isMobile ? 'false' : 'true');

                inactiveRoot = isMobile ? desk : mob;
                inactiveEl = inactiveRoot.querySelector('.awa-hero-swiper');

                if (!isMobile) {
                    clearMobileAutoplay(activeMobileSwiper);
                    activeMobileSwiper = null;
                }

                inactiveSwiper = inactiveEl && inactiveEl.swiper ? inactiveEl.swiper : null;
                if (inactiveSwiper) {
                    clearMobileAutoplay(inactiveSwiper);
                }

                initActiveHeroSwiper();
                if (isMobile) {
                    lockMobileHeroHeight(mob.querySelector('.awa-hero-swiper'), 'mq-sync-apply');
                }
            }

            apply();

            if (mq.addEventListener) {
                mq.addEventListener('change', apply);
            } else if (mq.addListener) {
                mq.addListener(apply);
            }
        }

        initActiveHeroSwiper();
        syncAriaAndLazyInit();

        document.addEventListener('visibilitychange', function () {
            var settings;
            var slideCount;

            if (!activeMobileSwiper || activeMobileSwiper.destroyed) {
                return;
            }

            if (document.hidden) {
                clearMobileAutoplay(activeMobileSwiper);
                return;
            }

            if (mq.matches && config.autoplay !== false && !prefersReducedMotion() && !activeMobileSwiper.__awaMobileAutoplayPaused) {
                settings = autoplayConfig(config.autoplay);
                slideCount = Number(activeMobileSwiper.__awaSlideCount) || 0;

                if (settings && slideCount > 1) {
                    startMobileAutoplay(activeMobileSwiper, slideCount, settings);
                }
            }
        });

        return true;
    }

    return initHeroSlider;
});
