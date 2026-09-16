/**
 * AWA - Carousel + dynamic bug fixes
 */
(function (w, d) {
    'use strict';
    if (w.__awaVisualFixesInit) { return; }
    w.__awaVisualFixesInit = true;
    var isHomePage = false;

    // ============================================
    // B22: Adicionar cursor pointer + hover state
    // ============================================
    function fixCardHovers() {
        d.querySelectorAll('.product-item-info, .awa-product-card').forEach(function (card) {
            if (card.style.cursor !== 'pointer') {
                card.style.cursor = 'pointer';
            }
        });
    }

    // ============================================
    // B34: Adicionar pauseOnHover no Owl
    // ============================================
    function fixOwlAutoplay() {
        if (typeof w.jQuery === 'undefined') return;
        w.jQuery('.owl-carousel').each(function () {
            var $el = w.jQuery(this);
            if ($el.data('awa-pause-on-hover')) return;
            $el.on('mouseenter.awaPause', function () {
                $el.trigger('stop.owl.autoplay');
            }).on('mouseleave.awaPause', function () {
                $el.trigger('play.owl.autoplay');
            });
            $el.data('awa-pause-on-hover', 1);
        });
    }

    // ============================================
    // B32: Adicionar tabindex aos botoes so com icone
    // ============================================
    function fixIconButtonTabindex() {
        d.querySelectorAll('button > svg:only-child, button > i.fa:only-child').forEach(function (btn) {
            if (btn.tagName === 'BUTTON' && !btn.getAttribute('aria-label') && !btn.textContent.trim()) {
                btn.setAttribute('aria-hidden', 'true');
            }
        });
    }

    // ============================================
    // B18: aria-current para tabs ativas (secundario)
    // ============================================
    function fixAriaCurrent() {
        d.querySelectorAll('li.active > a, .is-active > a').forEach(function (a) {
            if (!a.hasAttribute('aria-current')) {
                a.setAttribute('aria-current', 'page');
            }
        });
    }

    // ============================================
    // B35: Adicionar fetchpriority dinamicamente
    // ============================================
    function fixLcpPriority() {
        var heroImg = d.querySelector('.owl-carousel .banner_item img, .hero-image img, [data-awa-lcp] img');
        if (heroImg && !heroImg.hasAttribute('fetchpriority')) {
            heroImg.setAttribute('fetchpriority', 'high');
        }
    }

    function detectHomeRoute() {
        return !!(
            d.body &&
            d.body.classList &&
            (d.body.classList.contains('cms-index-index') ||
            d.body.classList.contains('cms-home') ||
            d.body.classList.contains('cms-homepage_ayo_home5'))
        );
    }

    // ============================================
    // Inicializacao
    // ============================================
    isHomePage = detectHomeRoute();

    function applyAllFixes() {
        if (isHomePage) {
            // A home já tem correções de terminal no `awa-home-visual-fixes-inline` + preload.
            // Mantém apenas prioridade de imagem hero se ainda estiver disponível.
            fixLcpPriority();
            return;
        }
        fixCardHovers();
        if (!isHomePage) {
            fixOwlAutoplay();
        }
        fixIconButtonTabindex();
        fixAriaCurrent();
        fixLcpPriority();
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () {
            isHomePage = detectHomeRoute();
            applyAllFixes();
        }, { once: true });
    } else {
        applyAllFixes();
    }

    // Magento layered ajax / KO emite contentUpdated. Observar document.body
    // (subtree) em PLP/search multiplicava applyAllFixes a cada mutação.
    d.addEventListener('contentUpdated', function () {
        applyAllFixes();
    });

    // Re-aplicar apos load (carrossel pode ser inicializado async)
    w.addEventListener('load', function () {
        isHomePage = detectHomeRoute();
        setTimeout(applyAllFixes, 500);
    });
})(window, document);
