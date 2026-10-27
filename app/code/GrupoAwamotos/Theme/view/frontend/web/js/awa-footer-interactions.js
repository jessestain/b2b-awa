define([
    'jquery',
    'GrupoAwamotos_Theme/js/awa-swiper-shared'
], function ($, swiperShared) {
    'use strict';

    return function (config, element) {
        var $root = $(element);
        var mobileBreakpoint = Number(config.mobileBreakpoint) || 768;
        var titleSelector = config.titleSelector || '.awa-footer-section__toggle, .footer-container .footer-block-title';
        var panelSelector = config.panelSelector || '.velaContent, .footer-block-content';
        var sliderSelector = config.sliderSelector || '.footer_brand_list_slider';
        var brandSliderInitialized = false;
        var resizeDelay = Number(config.resizeDelay) || 120;
        var resizeTimer = null;

        if (!$root.length || $root.data('awaFooterInteractionsInit')) {
            return;
        }

        $root.data('awaFooterInteractionsInit', 1);

        function isMobileViewport()
        {
            return window.matchMedia('(max-width:' + String(mobileBreakpoint - 1) + 'px)').matches;
        }

        function shouldAnimatePanels()
        {
            return isMobileViewport() && !swiperShared.prefersReducedMotion();
        }

        function getPanel($trigger)
        {
            var $directNext = $trigger.next(panelSelector).first();

            if ($directNext.length) {
                return $directNext;
            }

            if ($trigger.is('.awa-footer-section__toggle')) {
                return $trigger.closest('.velaFooterTitle, .footer-block-title')
                    .next(panelSelector)
                    .first();
            }

            return $trigger.closest('.velaFooterMenu, .vela-content, .footer-block')
                .find(panelSelector)
                .first();
        }

        function getSectionHeading($trigger)
        {
            if ($trigger.is('.awa-footer-section__toggle')) {
                return $trigger.closest('.velaFooterTitle, .footer-block-title');
            }

            return $trigger;
        }

        function normalizeLabel(value)
        {
            return String(value || '').replace(/\s+/g, ' ').trim();
        }

        function ensureLabelAttributes($elements)
        {
            $elements.each(function () {
                var $element = $(this);
                var label = normalizeLabel($element.attr('aria-label') || $element.attr('title') || $element.text());

                if (!label) {
                    return;
                }

                if (!$element.attr('aria-label')) {
                    $element.attr('aria-label', label);
                }

                if (!$element.attr('title')) {
                    $element.attr('title', label);
                }
            });
        }

        function applyPanelVisibility($panel, shouldExpand, animate)
        {
            if (!$panel.length) {
                return;
            }

            $panel.attr('aria-hidden', shouldExpand ? 'false' : 'true');
            $panel.toggleClass('active', shouldExpand);

            var $menu = $panel.closest('.velaFooterMenu');

            if ($menu.length) {
                $menu.toggleClass('is-open', shouldExpand);
            }

            if (shouldExpand) {
                $panel.removeAttr('inert').prop('hidden', false);

                if (animate) {
                    /* B2 (CICLO 4): expansao por transicao CSS de max-height.
                       O jQuery slideDown lia height/padding/margin via curCSS a cada
                       frame. Aqui: 1 leitura de scrollHeight + transition nativa.
                       max-height inline com !important porque killers legados do
                       tema (sem layer, !important) venceriam a camada normal. */
                    var panelEl = $panel[0];

                    if (!panelEl || panelEl.__awaFooterAnimating) {
                        return;
                    }

                    panelEl.__awaFooterAnimating = 1;
                    panelEl.style.setProperty('overflow', 'hidden', 'important');
                    panelEl.style.setProperty('transition', 'max-height 180ms ease', 'important');
                    panelEl.style.setProperty('max-height', '0px', 'important');

                    void panelEl.offsetHeight;

                    var contentHeight = panelEl.scrollHeight;

                    var cleanupFooterAnim = function () {
                        panelEl.removeEventListener('transitionend', onFooterTransitionEnd);
                        panelEl.style.removeProperty('max-height');
                        panelEl.style.removeProperty('overflow');
                        panelEl.style.removeProperty('transition');
                        panelEl.__awaFooterAnimating = 0;
                    };

                    var onFooterTransitionEnd = function (event) {
                        if (event.target === panelEl && event.propertyName === 'max-height') {
                            cleanupFooterAnim();
                        }
                    };

                    panelEl.addEventListener('transitionend', onFooterTransitionEnd);

                    window.requestAnimationFrame(function () {
                        window.requestAnimationFrame(function () {
                            panelEl.style.setProperty('max-height', contentHeight + 'px', 'important');
                        });
                    });

                    window.setTimeout(cleanupFooterAnim, 260);
                    return;
                }

                $panel.stop(true, true).css('display', '');
                return;
            }

            /* B2 (CICLO 4): fechamento preservado (instantaneo, como antes —
               hidden era setado antes do slideUp). */
            $panel.removeClass('awa-footer-anim awa-footer-expanded');
            $panel.prop('hidden', true).attr('inert', '');
        }

        function setPanelState($trigger, shouldExpand)
        {
            var $panel = getPanel($trigger);
            var $heading = getSectionHeading($trigger);
            var animate = shouldAnimatePanels();

            $trigger.toggleClass('active', shouldExpand)
                .attr('aria-expanded', shouldExpand ? 'true' : 'false');

            if ($heading.length) {
                $heading.toggleClass('active', shouldExpand);
            }

            applyPanelVisibility($panel, shouldExpand, animate);
        }

        function syncFooterSections()
        {
            var isMobile = isMobileViewport();

            $root.find(titleSelector).each(function () {
                var $trigger = $(this);
                var isExpanded = $trigger.attr('aria-expanded') === 'true';

                if (!isMobile) {
                    setPanelState($trigger, true);
                    return;
                }

                setPanelState($trigger, isExpanded);
            });
        }

        function scheduleResizeSync()
        {
            if (resizeTimer) {
                window.clearTimeout(resizeTimer);
            }

            resizeTimer = window.setTimeout(function () {
                syncFooterSections();
                syncCategoriesExpand();
            }, resizeDelay);
        }

        function bindFooterSections()
        {
            $root.find(titleSelector)
                .off('.awaFooter')
                .on('click.awaFooter', function (event) {
                    var $trigger = $(this);

                    if (!isMobileViewport()) {
                        return;
                    }

                    event.preventDefault();

                    var willExpand = $trigger.attr('aria-expanded') !== 'true';

                    if (willExpand) {
                        $root.find(titleSelector).each(function () {
                            var $otherTrigger = $(this);

                            if ($otherTrigger.is($trigger)) {
                                return;
                            }

                            setPanelState($otherTrigger, false);
                        });
                    }

                    setPanelState($trigger, willExpand);
                })
                .on('keydown.awaFooter', function (event) {
                    if ($(this).is('button')) {
                        return;
                    }

                    if (event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }

                    event.preventDefault();
                    $(this).trigger('click.awaFooter');
                });
        }

        function ensureFooterSectionAccessibility()
        {
            $root.find(titleSelector).each(function (index) {
                var $trigger = $(this);
                var $panel = getPanel($trigger);
                var triggerId = $trigger.attr('id') || 'awa-footer-toggle-' + String(index + 1);

                $trigger.attr('id', triggerId);

                if (!$trigger.attr('aria-expanded')) {
                    $trigger.attr('aria-expanded', isMobileViewport() ? 'false' : 'true');
                }

                if (!$panel.length) {
                    return;
                }

                var panelId = $panel.attr('id') || 'awa-footer-panel-' + String(index + 1);

                $panel.attr('id', panelId);

                if (!$panel.attr('role')) {
                    $panel.attr('role', 'region');
                }

                $panel.attr('aria-labelledby', triggerId);
                $trigger.attr('aria-controls', panelId);
            });

            ensureLabelAttributes($root.find('a, button'));
            ensureLabelAttributes($('.fixed-right a, .fixed-right button, .fixed-bottom a, .fixed-bottom button, #back-top'));

            $('.fixed-right .fixed-right-ul .scroll-top').each(function () {
                var $element = $(this);

                if (!$element.find('button').length) {
                    $element.attr({
                        role: 'button',
                        tabindex: '0'
                    });

                    if (!$element.attr('aria-label')) {
                        $element.attr('aria-label', 'Voltar ao topo');
                    }

                    if (!$element.attr('title')) {
                        $element.attr('title', 'Voltar ao topo');
                    }
                }
            });
        }

        function initBrandSlider()
        {
            // D4 (2026-10-24): brand slider/Swiper apenas na home (demais paginas nao baixam o swiper-bundle).
            if (!document.body.classList.contains('cms-index-index')) {
                return;
            }
            var $slider = $root.find(sliderSelector);
            var slidesCount;
            var maxSlidesPerView;
            var shouldLoop;

            if (!$slider.length || brandSliderInitialized || $slider.data('awaSwiperInit')) {
                return;
            }

            require(['swiper'], function (Swiper) {
                if (brandSliderInitialized || $slider.data('awaSwiperInit')) {
                    return;
                }

                brandSliderInitialized = true;
                $slider.data('awaSwiperInit', 1);
                $slider.attr('data-awa-footer-slider-ready', '1');

                if (!$slider.find('.swiper-wrapper').length) {
                    $slider.addClass('swiper');
                    $slider.children().wrap('<div class="swiper-slide"></div>');
                    $slider.children('.swiper-slide').wrapAll('<div class="swiper-wrapper"></div>');
                    $slider.append('<div class="swiper-button-prev" aria-label="Marca anterior"><span aria-hidden="true">&#8249;</span></div>');
                    $slider.append('<div class="swiper-button-next" aria-label="Próxima marca"><span aria-hidden="true">&#8250;</span></div>');
                }

                slidesCount = $slider.find('.swiper-slide').length;
                maxSlidesPerView = 6;
                shouldLoop = slidesCount > maxSlidesPerView;

                new Swiper($slider[0], {
                    slidesPerView: 2,
                    spaceBetween: 0,
                    loop: shouldLoop,
                    watchOverflow: true,
                    navigation: {
                        nextEl: $slider.find('.swiper-button-next')[0],
                        prevEl: $slider.find('.swiper-button-prev')[0]
                    },
                    autoplay: swiperShared.prefersReducedMotion() ? false : swiperShared.autoplayConfig(3000),
                    a11y: {
                        prevSlideMessage: 'Marca anterior',
                        nextSlideMessage: 'Próxima marca'
                    },
                    breakpoints: {
                        480: { slidesPerView: 3 },
                        768: { slidesPerView: 4 },
                        992: { slidesPerView: 5 },
                        1200: { slidesPerView: 6 }
                    }
                });
            });
        }

        function scheduleBrandSliderInit()
        {
            if (config.enableBrandSlider === false) {
                return;
            }

            var $slider = $root.find(sliderSelector);
            var sliderElement;

            if (!$slider.length || brandSliderInitialized) {
                return;
            }

            sliderElement = $slider.get(0);

            if (window.IntersectionObserver && sliderElement) {
                new window.IntersectionObserver(function (entries, observer) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) {
                            return;
                        }

                        initBrandSlider();
                        observer.disconnect();
                    });
                }, {
                    rootMargin: '160px 0px'
                }).observe(sliderElement);

                return;
            }

            if (typeof window.requestIdleCallback === 'function') {
                window.requestIdleCallback(function () {
                    initBrandSlider();
                }, {
                    timeout: 1200
                });

                return;
            }

            window.setTimeout(initBrandSlider, 250);
        }

        function getCategoriesToggleButtons()
        {
            var $toggleBtn = $root.closest('.page_footer, .page-footer')
                .find('[data-awa-categories-toggle]');

            if (!$toggleBtn.length) {
                $toggleBtn = $('[data-awa-categories-toggle]');
            }

            return $toggleBtn;
        }

        function initCategoriesToggle()
        {
            getCategoriesToggleButtons().each(function () {
                var $btn = $(this);

                if ($btn.data('awaCategoriesToggleBound')) {
                    return;
                }

                $btn.data('awaCategoriesToggleBound', 1);

                var panelId = $btn.attr('aria-controls');
                var $panel = panelId ? $('#' + panelId) : $btn.parent().find('.awa-footer-categories-expand__panel');

                if (!$panel.length) {
                    return;
                }

                $btn.on('click.awaCategories keydown.awaCategories', function (event) {
                    if (!isMobileViewport()) {
                        return;
                    }

                    if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }

                    if (event.type === 'keydown') {
                        event.preventDefault();
                    }

                    var expanded = $btn.attr('aria-expanded') === 'true';
                    var willExpand = !expanded;
                    var animate = !swiperShared.prefersReducedMotion();

                    $btn.attr('aria-expanded', String(willExpand));
                    $btn.toggleClass('is-expanded', willExpand);
                    applyPanelVisibility($panel, willExpand, animate);
                });
            });
        }

        function syncCategoriesExpand()
        {
            getCategoriesToggleButtons().each(function () {
                var $btn = $(this);
                var panelId = $btn.attr('aria-controls');
                var $panel = panelId ? $('#' + panelId) : $btn.parent().find('.awa-footer-categories-expand__panel');

                if (!$panel.length) {
                    return;
                }

                if (!isMobileViewport()) {
                    $btn.attr('aria-expanded', 'true').addClass('is-expanded');
                    applyPanelVisibility($panel, true, false);
                    return;
                }

                applyPanelVisibility($panel, $btn.attr('aria-expanded') === 'true', false);
            });
        }

        initCategoriesToggle();
        ensureFooterSectionAccessibility();
        bindFooterSections();
        syncFooterSections();
        syncCategoriesExpand();
        $root.addClass('awa-footer-js-ready');
        scheduleBrandSliderInit();

        $(window)
            .off('resize.awaFooterSections')
            .on('resize.awaFooterSections', scheduleResizeSync);
    };
});
