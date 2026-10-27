/**
 * AWA Scroll Reveal — IntersectionObserver-based reveal animations.
 *
 * Magento 2 AMD module. Loaded via data-mage-init or x-magento-init.
 *
 * Usage:
 *   Add class "awa-reveal" to any element you want to animate on scroll.
 *   Variants: "awa-reveal--scale", "awa-reveal--left"
 *   Stagger children: add "awa-reveal-stagger" to parent.
 *
 * The module also auto-discovers common Magento/Ayo sections and
 * applies reveal if they don't already have it (progressive enhancement).
 */
define([], function () {
    'use strict';

    let REVEALED_CLASS = 'awa-revealed';
    let REVEAL_SELECTOR = '.awa-reveal, .awa-reveal-stagger';
    let THRESHOLD = 0.12;
    let ROOT_MARGIN = '0px 0px 400px 0px';

    /* Sections auto-tagged for reveal (homepage, PLP, footer) */
    let AUTO_REVEAL_SELECTORS = [
        '.awa-hero-b2b-cta',
        '.awa-home-section',
        '.awa-carousel-section',
        '.awa-footer-trust-bar',
        '.awa-footer-brands',
        '.awa-category-carousel__viewport',
        '.rokan-product-heading',
        '.block_cat',
        '.super-deal',
        '.velaNewsletterFooter'
    ];

    let AUTO_STAGGER_SELECTORS = [
        '.awa-footer-trust-grid',
        '.products-grid .product-items',
        '.awa-category-carousel__track'
    ];

    function prefersReducedMotion() {
        return window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function autoTag() {
        let i, elements, el;

        for (i = 0; i < AUTO_REVEAL_SELECTORS.length; i++) {
            elements = document.querySelectorAll(AUTO_REVEAL_SELECTORS[i]);
            for (let j = 0; j < elements.length; j++) {
                el = elements[j];
                /* Prateleiras AWA já têm shimmer/pending próprio — evita animação dupla */
                if (el.classList.contains('awa-carousel-section') &&
                    el.querySelector('.awa-shelf--carousel')) {
                    continue;
                }
                if (!el.classList.contains('awa-reveal') &&
                    !el.classList.contains('awa-reveal-stagger') &&
                    !el.classList.contains(REVEALED_CLASS)) {
                    el.classList.add('awa-reveal');
                }
            }
        }

        for (i = 0; i < AUTO_STAGGER_SELECTORS.length; i++) {
            elements = document.querySelectorAll(AUTO_STAGGER_SELECTORS[i]);
            for (let k = 0; k < elements.length; k++) {
                el = elements[k];
                if (!el.classList.contains('awa-reveal-stagger') &&
                    !el.classList.contains(REVEALED_CLASS)) {
                    el.classList.add('awa-reveal-stagger');
                }
            }
        }
    }

    function initObserver() {
        if (!('IntersectionObserver' in window)) {
            /* Fallback: show all immediately */
            let all = document.querySelectorAll(REVEAL_SELECTOR);
            for (let i = 0; i < all.length; i++) {
                all[i].classList.add(REVEALED_CLASS);
            }
            return;
        }

        let observer = new IntersectionObserver(function (entries) {
            for (let i = 0; i < entries.length; i++) {
                if (entries[i].isIntersecting) {
                    entries[i].target.classList.add(REVEALED_CLASS);
                    observer.unobserve(entries[i].target);
                }
            }
        }, {
            threshold: THRESHOLD,
            rootMargin: ROOT_MARGIN
        });

        let targets = document.querySelectorAll(REVEAL_SELECTOR);
        for (let j = 0; j < targets.length; j++) {
            let rect = targets[j].getBoundingClientRect();
            if (rect.top < window.innerHeight + 400 && rect.bottom > -100) {
                targets[j].classList.add(REVEALED_CLASS);
            } else {
                observer.observe(targets[j]);
            }
        }

        /* Fast scroll: revelar seções que entram no viewport antes do IO disparar */
        var revealVisible = function () {
            var pending = document.querySelectorAll(REVEAL_SELECTOR + ':not(.' + REVEALED_CLASS + ')');
            for (var i = 0; i < pending.length; i++) {
                var r = pending[i].getBoundingClientRect();
                if (r.top < window.innerHeight + 400 && r.bottom > -100) {
                    pending[i].classList.add(REVEALED_CLASS);
                }
            }
        };
        /* PERF-2026-10-26 (INP): coalesce scroll/wheel em 1 batida por frame —
           revealVisible lia getBoundingClientRect de todos os alvos pendentes a
           CADA evento de scroll/wheel (rajada) sem throttling. */
        var rafPending = false;
        var scheduleReveal = function () {
            if (rafPending) { return; }
            rafPending = true;
            window.requestAnimationFrame(function () {
                rafPending = false;
                revealVisible();
            });
        };
        window.addEventListener('scroll', scheduleReveal, { passive: true });
        window.addEventListener('wheel', scheduleReveal, { passive: true });
    }

    return function () {
        if (document.body && (
            document.body.classList.contains('catalog-category-view') ||
            document.body.classList.contains('catalogsearch-result-index')
        )) {
            return;
        }

        if (prefersReducedMotion()) {
            /* Show everything immediately, no animations */
            return;
        }

        function run() {
            document.body.classList.add('awa-scroll-ready');
            autoTag();
            initObserver();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    };
});
