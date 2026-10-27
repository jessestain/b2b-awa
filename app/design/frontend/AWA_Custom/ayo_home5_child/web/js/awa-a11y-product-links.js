(function (w, d) {
    'use strict';

    function runA11yGlobals() {
        var logo = d.querySelector('.logo a, a.logo');
        if (logo) {
            logo.removeAttribute('aria-hidden');
            if (!logo.getAttribute('aria-label')) {
                logo.setAttribute('aria-label', 'AWA Motos - Página inicial');
            }
        }

        d.querySelectorAll('.minicart-wrapper img:not([alt]), .minicart-wrapper img[alt=""]').forEach(function (img) {
            img.setAttribute('alt', 'Produto no carrinho');
        });

        var emptyLinkSelectors = [
            '.action.showcart',
            '.action.nav-toggle',
            '.action.wishlist',
            '.action.tocompare',
            'a.action.quickview-handler'
        ];
        var labelMap = {
            showcart: 'Carrinho de compras',
            'nav-toggle': 'Menu de navegação',
            wishlist: 'Lista de desejos',
            tocompare: 'Comparar produto',
            'quickview-handler': 'Visualização rápida'
        };

        emptyLinkSelectors.forEach(function (sel) {
            d.querySelectorAll(sel).forEach(function (link) {
                if (!link.getAttribute('aria-label') && !link.textContent.trim()) {
                    var key = sel.split('.').pop();
                    if (labelMap[key]) {
                        link.setAttribute('aria-label', labelMap[key]);
                    }
                }
            });
        });

        var seen = {};
        d.querySelectorAll('.product-item[id]').forEach(function (el) {
            if (seen[el.id]) {
                el.id = el.id + '-tab-' + (Object.keys(seen).length);
            }
            seen[el.id] = true;
        });
    }

    var a11yIntent = ['pointerdown', 'keydown', 'touchstart'];
    var a11yDone = false;

    function runOnIntent() {
        if (a11yDone) {
            return;
        }
        a11yDone = true;
        a11yIntent.forEach(function (name) {
            w.removeEventListener(name, runOnIntent, true);
        });
        runA11yGlobals();
    }

    a11yIntent.forEach(function (name) {
        w.addEventListener(name, runOnIntent, { capture: true, passive: name !== 'keydown', once: true });
    });
}(window, document));
