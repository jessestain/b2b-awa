/**
 * AWA Motos — theme.js (override do tema pai)
 *
 * Correção crítica de performance: o MutationObserver original chamava
 * applyAwaPublicHotfix(node) de forma SÍNCRONA para cada elemento adicionado
 * ao .page-wrapper (childList + subtree: true). Com os múltiplos carrosséis
 * de produtos da homepage isso disparava querySelectorAll('a[href]') centenas
 * de vezes em sequência, bloqueando a thread principal → "Página sem resposta".
 *
 * Fix v2: applyAwaPublicHotfix e o batch do MutationObserver são diferidos
 * via requestIdleCallback, empurrando o trabalho para fora da janela TTI e
 * eliminando o long task de ~1365ms atribuído ao theme.js no LH trace.
 */
define([
    'jquery',
    'mage/smart-keyboard-handler',
    'mage/mage',
    'domReady!'
], function ($, keyboardHandler) {
    'use strict';

    if ($('body').hasClass('checkout-cart-index')) {
        if ($('#co-shipping-method-form .fieldset.rates').length > 0 && $('#co-shipping-method-form .fieldset.rates :checked').length === 0) {
            $('#block-shipping').on('collapsiblecreate', function () {
                $('#block-shipping').collapsible('forceActivate');
            });
        }
    }

    // Carrinho: NUNCA mage/sticky — só CSS position:sticky (+ guard de footer).
    // (path + body class: cobre race de body class / bundle Luma)
    var isCartPath = /^\/(?:index\.php\/?)?checkout\/cart(?:\/|$)/.test(
        (window.location && window.location.pathname) ? window.location.pathname : ''
    );

    if (!isCartPath && !$('body').hasClass('checkout-cart-index')) {
        $('.cart-summary').mage('sticky', {
            container: '#maincontent'
        });
    }

    $('.panel.header > .header.links').clone().appendTo('#store\\.links');

    let bodyEl = document.body;
    let pathName = (window.location && window.location.pathname) ? window.location.pathname : '';
    let isHomePath = /^\/(?:index\.php\/?)?$/.test(pathName);
    let bodyClassName = bodyEl ? bodyEl.className : '';
    let isHomePage = isHomePath || /\bcms-index-index\b|\bcms-home\b|\bcms-homepage_ayo_home5\b/.test(bodyClassName);
    let isCatalogPage = /\bcatalog-category-view\b|\bcatalogsearch-result-index\b/.test(bodyClassName);
    let isCartPage = !!(bodyEl && bodyEl.classList.contains('checkout-cart-index'));
    let isCheckoutFlowPage = isCartPage || !!(bodyEl && (
        bodyEl.classList.contains('checkout-index-index') ||
        bodyEl.classList.contains('rokanthemes-onepagecheckout') ||
        bodyEl.classList.contains('onepagecheckout-index-index')
    ));
    let shouldRunAwaPublicHotfix = !isHomePage && !isCheckoutFlowPage;

    function applyPlpSearchFontFix() {
        if (!isCatalogPage) {
            return;
        }
        let searchInput = document.querySelector('#search');
        if (!searchInput) {
            return;
        }
        let isMobile = window.innerWidth <= 767;
        let searchFontSize = isMobile ? '16px' : '14px';
        searchInput.style.setProperty('font-size', searchFontSize, 'important');
        searchInput.style.setProperty('line-height', isMobile ? '1.25' : '1.35', 'important');
        searchInput.style.setProperty('height', '44px', 'important');
        searchInput.style.setProperty('min-height', '44px', 'important');
    }

    function applyPlpB2bCtaFontFix() {
        if (!isCatalogPage) {
            return;
        }
        // Guest gate (__title/__message): NÃO injetar inline — background #fff + font 13px
        // anulavam o CSS terminal (debug cc2a40). Só limpa inlines legados.
        document.querySelectorAll('.b2b-login-to-see-price').forEach(function (cta) {
            var isGuestGate = !!cta.querySelector('.b2b-login-to-see-price__title, .b2b-login-to-see-price__message')
                || !cta.querySelector('a');
            if (isGuestGate) {
                cta.style.removeProperty('font-size');
                cta.style.removeProperty('background');
                cta.style.removeProperty('border-radius');
                cta.querySelectorAll('.b2b-login-to-see-price__title, .b2b-login-to-see-price__message, span').forEach(function (child) {
                    child.style.removeProperty('font-size');
                    if (!child.getAttribute('style')) {
                        child.removeAttribute('style');
                    }
                });
                if (!cta.getAttribute('style')) {
                    cta.removeAttribute('style');
                }
                return;
            }
            cta.style.setProperty('font-size', '13px', 'important');
            cta.style.setProperty('border-radius', '8px', 'important');
            cta.querySelectorAll('.price-label, a, span').forEach(function (child) {
                child.style.setProperty('font-size', '13px', 'important');
            });
        });
    }

    function applyPlpFootGapFix() {
        if (!isCatalogPage || window.innerWidth < 992) {
            return;
        }
        let grid = document.querySelector('.wrapper.grid.products-grid');
        let footRow = document.querySelector('.col-main .product-content-right > .row');
        if (!grid || !footRow) {
            return;
        }
        let gap = footRow.getBoundingClientRect().top - grid.getBoundingClientRect().bottom;
        if (gap > 16) {
            footRow.style.marginTop = (-1 * (gap - 12)) + 'px';
        } else {
            footRow.style.removeProperty('margin-top');
        }
    }

    applyPlpSearchFontFix();
    applyPlpB2bCtaFontFix();
    applyPlpFootGapFix();

    if (isCatalogPage && window.MutationObserver) {
        let footGapTarget = document.querySelector('.product-content-right') || document.querySelector('.page-main');
        if (footGapTarget) {
            let footGapRaf = 0;
            let footGapObserver = new MutationObserver(function () {
                if (footGapRaf) {
                    return;
                }
                footGapRaf = window.requestAnimationFrame(function () {
                    footGapRaf = 0;
                    applyPlpFootGapFix();
                });
            });
            // childList apenas — attributes em <img src> disparava recálculo em loop durante fallbacks
            footGapObserver.observe(footGapTarget, { childList: true, subtree: true });
        }
        window.addEventListener('resize', applyPlpFootGapFix);
    }

    // PERF HOME (experimento controlado): evita executar blocos pesados no caminho crítico.
    if (isHomePage) {
        return;
    }

    function applyAwaPublicHotfix(root) {
        let scope = root && root.querySelectorAll ? root : document;

        if (!shouldRunAwaPublicHotfix) {
            return;
        }

        scope.querySelectorAll('.contact-index-index h1, .contact-index-index h2, .contact-index-index h3, .contact-index-index button, .contact-index-index .action.submit').forEach(function (el) {
            let text = (el.textContent || '').trim();
            if (text === 'Drop Us A Message') {
                el.textContent = 'Envie sua mensagem';
            } else if (text === 'Send Message' || text === 'Send message') {
                el.textContent = 'Enviar mensagem';
            }
        });

        scope.querySelectorAll('.contact-index-index input[placeholder], .contact-index-index textarea[placeholder]').forEach(function (el) {
            let placeholder = (el.getAttribute('placeholder') || '').trim();
            if (placeholder === "What's on your mind?") {
                el.setAttribute('placeholder', 'Como podemos ajudar?');
            } else if (placeholder === 'Phone Number') {
                el.setAttribute('placeholder', 'Telefone');
            } else if (placeholder === 'Your Message') {
                el.setAttribute('placeholder', 'Sua mensagem');
            }
        });

        scope.querySelectorAll('.cms-page-view h2, .cms-page-view h3, .cms-page-view h4').forEach(function (heading) {
            let txt = (heading.textContent || '').trim();
            if (txt && /^\?{2,}/.test(txt)) {
                heading.textContent = txt.replace(/^\?+\s*/, '').trim();
            }
        });

        scope.querySelectorAll('a[href]').forEach(function (anchor) {
            let hrefAttr = anchor.getAttribute('href');
            if (!hrefAttr) return;

            let href = hrefAttr.trim();
            if (!/\/ofertas\/?($|[?#])/i.test(href)) return;

            try {
                let url = new URL(href, window.location.origin);
                let path = (url.pathname || '').replace(/\/+$/, '').toLowerCase();
                if (path !== '/ofertas') return;

                url.pathname = '/ofertas.html';
                let normalized = /^\//.test(href) && !/^https?:\/\//i.test(href)
                    ? (url.pathname + url.search + url.hash)
                    : url.toString();

                anchor.setAttribute('href', normalized);
            } catch (e) {
                // noop
            }
        });
    }

    // Diferido: hotfix DOM + footer aria-label não são críticos para LCP/interatividade.
    // requestIdleCallback garante execução após o browser estar ocioso (pós-TTI).
    let _ric = window.requestIdleCallback || function (cb) { setTimeout(cb, 300); };

    _ric(function () {
        if (shouldRunAwaPublicHotfix) {
            applyAwaPublicHotfix(document);
        }

        /*
         * WCAG 2.5.3 fix: footer contact links have aria-labels that don't contain
         * the full visible text ("WhatsApp Comercial Resposta rápida..."). Removing
         * the mismatched aria-label lets the accessible name fall back to the
         * visible text content, which is already descriptive and satisfies 2.5.3.
         */
        document.querySelectorAll('.awa-footer-business-contact__action[aria-label]').forEach(function (link) {
            let ariaLabel = (link.getAttribute('aria-label') || '').toLowerCase();
            // textContent instead of innerText — innerText forces layout recalculation (reflow)
            let visibleText = (link.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (visibleText && !ariaLabel.includes(visibleText)) {
                link.removeAttribute('aria-label');
            }
        });
    });

    /*
     * WCAG 4.1.3 / aria-hidden-focus: O mega-menu usa visibility:hidden +
     * aria-hidden="true" nos submenus fechados, mas links internos ficam
     * focalizáveis via teclado. O atributo `inert` corrige isso: bloqueia
     * foco, eventos e AT em toda a subárvore, sincronizado com aria-hidden.
     */
    (function () {
        if (isHomePage) {
            return;
        }

        function isDecorativeInsideVisibleControl(el) {
            if (!el || typeof el.closest !== 'function') {
                return false;
            }
            var control = el.closest('a, button, [role="button"], [role="link"]');
            if (!control || control === el) {
                return false;
            }
            return control.getAttribute('aria-hidden') !== 'true'
                && !control.hasAttribute('hidden');
        }

        function syncInert(el) {
            if (el.getAttribute('aria-hidden') === 'true') {
                if (isDecorativeInsideVisibleControl(el)) {
                    el.removeAttribute('inert');
                    return;
                }
                el.setAttribute('inert', '');
            } else {
                el.removeAttribute('inert');
            }
        }
        // Aplicar estado inicial + re-verificar após scripts de terceiros (footer accordion, modal B2B)
        document.querySelectorAll('[aria-hidden]').forEach(syncInert);
        setTimeout(function () {
            document.querySelectorAll('[aria-hidden]').forEach(syncInert);
        }, 800);
        // Observar mudanças em todo o documento (footer + modais estão fora do nav)
        if (window.MutationObserver) {
            new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    if (m.attributeName === 'aria-hidden') {
                        syncInert(m.target);
                    }
                });
            }).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['aria-hidden'] });
        }
    }());

    /*
     * WCAG 1.3.6 / landmark-one-main + WCAG 1.1.1 / image-alt
     * Diferido para requestIdleCallback — getComputedStyle força layout reflow.
     * requestIdleCallback executa quando browser está ocioso (após LCP).
     */
    (window.requestIdleCallback || function (cb) { setTimeout(cb, 0); })(function () {
        let mainEl = document.querySelector('main#maincontent');
        if (mainEl && window.getComputedStyle(mainEl).display === 'none') {
            let contentTopHome = document.querySelector('.content-top-home');
            if (contentTopHome) {
                contentTopHome.setAttribute('role', 'main');
                contentTopHome.setAttribute('aria-label', 'Conteúdo principal');
            }
        }
        let authLogo = document.querySelector('.block-authentication .wave-top img.logo');
        if (authLogo && !authLogo.getAttribute('alt')) {
            authLogo.setAttribute('alt', 'AWA Motos');
        }
    });

    /*
     * P1-B (2026-07-27) — WCAG 4.1.2 / axe aria-required-children + aria-allowed-attr.
     * Dois widgets mage/tabs|collapsible ficam permanentemente "disabled" neste tema
     * (o CSS mantém todo o conteúdo visível), mas continuam injetando semântica de
     * tabs órfã e re-escrevendo os atributos a cada clique:
     *  - PDP .product.data.items: painéis sempre empilhados/visíveis em TODOS os
     *    breakpoints (mapeado em 1280 e 390), porém o widget alterna aria-hidden
     *    em conteúdo visível e mantém role=tablist/tab/tabpanel sem comportamento
     *    de tabs. Saneamento: remover roles/estados órfãos; os títulos voltam a ser
     *    âncoras de salto legítimas (#description/#additional).
     *  - PLP #narrow-by-list: acordeão FUNCIONAL (aria-expanded correto), mas com
     *    role=tablist no container, role=tabpanel nos painéis e aria-selected
     *    (inválido em role=button) re-aplicado pelo widget a cada toggle.
     *    Saneamento: manter button/aria-expanded, relacionar controle↔conteúdo via
     *    aria-controls/id e remover só o vocabulário de tabs.
     * O widget re-escreve atributos nos handlers síncronos de clique/teclado; um
     * listener delegado + requestAnimationFrame garante a palavra final sem
     * MutationObserver contínuo. Re-executa após AJAX da navegação por camadas
     * (contentUpdated) porque o sidebar é re-renderizado com os roles de novo.
     */
    (function () {
        if (isHomePage) {
            return;
        }

        function sanitizePdpTabs() {
            let wrap = document.querySelector('.product.data.items');
            if (!wrap) return;
            if (wrap.getAttribute('role') === 'tablist') {
                wrap.removeAttribute('role');
            }
            wrap.querySelectorAll(':scope > .title').forEach(function (t) {
                ['role', 'aria-selected', 'aria-expanded', 'aria-controls', 'tabindex'].forEach(function (attr) {
                    if (t.hasAttribute(attr)) t.removeAttribute(attr);
                });
                let a = t.querySelector('a[href]');
                if (a && a.getAttribute('tabindex') === '-1') {
                    a.removeAttribute('tabindex');
                }
            });
            wrap.querySelectorAll(':scope > .content').forEach(function (p) {
                if (p.getAttribute('role') === 'tabpanel') p.removeAttribute('role');
                // conteúdo visível não pode ficar aria-hidden (o sync de inert
                // acima remove o inert junto quando o atributo sai)
                if (p.hasAttribute('aria-hidden')) p.removeAttribute('aria-hidden');
            });
        }

        function sanitizePlpFilters() {
            let list = document.getElementById('narrow-by-list');
            if (!list) return;
            if (list.getAttribute('role') === 'tablist') {
                list.removeAttribute('role');
            }
            let seq = 0;
            list.querySelectorAll('.filter-options-item').forEach(function (item) {
                let title = item.querySelector('.filter-options-title');
                let content = item.querySelector('.filter-options-content');
                if (title) {
                    if (title.hasAttribute('aria-selected')) title.removeAttribute('aria-selected');
                    if (content) {
                        if (!content.id) {
                            seq += 1;
                            content.id = 'awa-filter-content-' + seq;
                        }
                        if (title.getAttribute('aria-controls') !== content.id) {
                            title.setAttribute('aria-controls', content.id);
                        }
                    }
                }
                if (content && content.getAttribute('role') === 'tabpanel') {
                    content.removeAttribute('role');
                }
            });
        }

        function runSanitize() {
            sanitizePdpTabs();
            sanitizePlpFilters();
        }

        /*
         * O mage/collapsible re-aplica aria-selected/aria-hidden no callback de
         * conclusão da animação (~300ms), portanto DEPOIS de um requestAnimationFrame.
         * Um rAF isolado perderia a corrida; a cadeia curta e limitada abaixo garante
         * a palavra final sem MutationObserver contínuo (mesmo padrão do sync de inert).
         */
        let _sanitizePending = false;
        function scheduleSanitize() {
            if (_sanitizePending) return;
            _sanitizePending = true;
            requestAnimationFrame(runSanitize);
            setTimeout(runSanitize, 150);
            setTimeout(function () {
                _sanitizePending = false;
                runSanitize();
            }, 500);
        }

        // Estado inicial: widgets inicializam async via RequireJS; repetir em
        // janelas curtas cobre a corrida sem observer (padrão do sync de inert acima).
        scheduleSanitize();
        setTimeout(scheduleSanitize, 800);
        setTimeout(scheduleSanitize, 2500);
        window.addEventListener('load', scheduleSanitize, { once: true });

        // Palavra final após cada interação nos dois componentes. Captura roda antes
        // do handler do widget; a cadeia de scheduleSanitize cobre o pós-animação.
        ['click', 'keydown', 'keyup'].forEach(function (evt) {
            document.addEventListener(evt, function (e) {
                let t = e.target;
                if (t && t.closest && t.closest('.product.data.items, #narrow-by-list')) {
                    scheduleSanitize();
                }
            }, true);
        });

        // Sidebar re-renderizado pelo LayeredAjax dispara contentUpdated.
        $(document).on('contentUpdated', scheduleSanitize);
    }());

    if (window.MutationObserver && shouldRunAwaPublicHotfix) {
        let observerTarget = document.querySelector('.page-wrapper') || document.body;
        if (observerTarget) {
            /*
             * CORREÇÃO DE PERFORMANCE:
             * Acumula os nós adicionados e processa em batch no próximo frame
             * (requestAnimationFrame), em vez de chamar applyAwaPublicHotfix()
             * de forma síncrona para cada mutação. Isso impede que carrosséis
             * com muitos produtos travem a thread principal do browser.
             */
            let _pendingNodes = [];
            let _rafScheduled = false;

            let hotfixObserver = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (node && node.nodeType === 1) {
                            _pendingNodes.push(node);
                        }
                    });
                });

                if (!_rafScheduled && _pendingNodes.length > 0) {
                    _rafScheduled = true;
                    _ric(function () {
                        let nodes = _pendingNodes.splice(0);
                        _rafScheduled = false;
                        nodes.forEach(applyAwaPublicHotfix);
                    });
                }
            });

            hotfixObserver.observe(observerTarget, {
                childList: true,
                subtree: true
            });
        }
    }

    function runKeyboardHandlerOnce() {
        if (runKeyboardHandlerOnce._done) {
            return;
        }

        runKeyboardHandlerOnce._done = true;
        keyboardHandler.apply();
    }

    if (isHomePage) {
        // PERF home: evita long task no caminho crítico do LCP/TTI.
        // Acessibilidade é preservada em interação real ou fallback tardio.
        ['pointerdown', 'touchstart', 'keydown', 'scroll', 'mousemove'].forEach(function (evtName) {
            window.addEventListener(evtName, runKeyboardHandlerOnce, { once: true, passive: true });
        });
        window.setTimeout(runKeyboardHandlerOnce, 7000);
    } else {
        runKeyboardHandlerOnce();
    }

    /**
     * Fallback para imagens de produto quebradas (ex: _3.jpg no second-thumb).
     * 1) Tenta _N → _1 uma vez; 2) second-thumb inválido → desativa hover swap;
     * 3) imagem principal → placeholder uma vez, sem loop de error handlers.
     */
    var awaProductPlaceholderUrl = '';

    function resolveProductPlaceholderUrl() {
        if (awaProductPlaceholderUrl) {
            return awaProductPlaceholderUrl;
        }
        try {
            if (typeof require !== 'undefined' && typeof require.toUrl === 'function') {
                awaProductPlaceholderUrl = require.toUrl('Magento_Catalog/images/product/placeholder/image.jpg');
            }
        } catch (e) {
            awaProductPlaceholderUrl = '';
        }
        return awaProductPlaceholderUrl;
    }

    function markProductImageLoaded(img) {
        if (!img || img.dataset.awaLoaded === '1') {
            return;
        }
        img.dataset.awaLoaded = '1';
        img.classList.add('awa-loaded');

        let thumb = img.closest('[data-awa-thumb-stabilized]');
        if (thumb) {
            thumb.classList.add('awa-thumb-ready');
        }

        let wrapper = img.closest('.product-image-wrapper');
        if (wrapper) {
            wrapper.style.setProperty('animation', 'none', 'important');
            wrapper.style.setProperty('transform', 'none', 'important');
            wrapper.style.setProperty('transition', 'none', 'important');
        }
    }

    function bindProductImageLoaded(img) {
        if (img.dataset.awaLoadBound === '1') {
            return;
        }
        img.dataset.awaLoadBound = '1';
        img.addEventListener('load', function () {
            markProductImageLoaded(img);
        });
        if (img.complete && img.naturalWidth > 0) {
            markProductImageLoaded(img);
        }
    }

    function detachBrokenImageHandlers(img) {
        if (img.__awaBrokenErrorHandler) {
            img.removeEventListener('error', img.__awaBrokenErrorHandler);
            img.__awaBrokenErrorHandler = null;
        }
    }

    function disableSecondThumbSwap(img) {
        let second = img.closest('.second-thumb');
        let thumb = img.closest('.product-thumb');

        if (second) {
            second.style.display = 'none';
            second.setAttribute('aria-hidden', 'true');
        }
        if (thumb) {
            thumb.setAttribute('data-no-swap', 'true');
        }
    }

    function applyBrokenProductPlaceholder(img) {
        if (img.dataset.awaBrokenFinal === '1') {
            return;
        }

        detachBrokenImageHandlers(img);

        let placeholder = resolveProductPlaceholderUrl();
        img.style.visibility = 'visible';
        img.style.opacity = '1';
        img.classList.add('awa-no-image');

        if (!placeholder || img.getAttribute('src') === placeholder) {
            img.dataset.awaBrokenFinal = '1';
            return;
        }

        img.dataset.awaPlaceholderTried = '1';
        img.addEventListener('error', function onPlaceholderError() {
            img.removeEventListener('error', onPlaceholderError);
            img.dataset.awaBrokenFinal = '1';
        }, { once: true });
        img.setAttribute('src', placeholder);
    }

    function finalizeBrokenImage(img) {
        img.dataset.awaBrokenFinal = '1';
        detachBrokenImageHandlers(img);

        if (img.closest('.second-thumb')) {
            disableSecondThumbSwap(img);
            return;
        }

        applyBrokenProductPlaceholder(img);
    }

    function handleBrokenProductImage(img) {
        if (img.dataset.awaBrokenFinal === '1') {
            return;
        }

        let src = img.getAttribute('src') || '';
        let fallback = src.replace(/_\d+(\.(?:jpg|jpeg|png|webp))$/i, '_1$1');

        if (fallback !== src && img.dataset.awaVariantTried !== '1') {
            img.dataset.awaVariantTried = '1';
            img.setAttribute('src', fallback);
            return;
        }

        finalizeBrokenImage(img);
    }

    function fixBrokenProductImages(root) {
        let scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('img[src*="/media/catalog/product/"]').forEach(function (img) {
            if (img.dataset.awaBrokenHandled === '1') {
                return;
            }
            img.dataset.awaBrokenHandled = '1';

            bindProductImageLoaded(img);

            img.__awaBrokenErrorHandler = function () {
                handleBrokenProductImage(img);
            };
            img.addEventListener('error', img.__awaBrokenErrorHandler);

            if (img.complete && img.naturalWidth === 0) {
                handleBrokenProductImage(img);
            }
        });
    }

    function lockPlpProductThumbsStatic() {
        if (!isCatalogPage) {
            return;
        }
        document.querySelectorAll('.wrapper.grid.products-grid .product-thumb').forEach(function (thumb) {
            thumb.setAttribute('data-no-swap', 'true');
            thumb.setAttribute('data-awa-plp-static-thumb', 'true');
        });
    }

    if (!isHomePage) {
        document.querySelectorAll('img.product-image-photo, .product-thumb img').forEach(bindProductImageLoaded);
        lockPlpProductThumbsStatic();
    }

    // PERF: na homepage este scan varre centenas de imagens e causa long task >1s.
    // Carrinho/checkout: scan + observer em .page-wrapper travavam a aba ("Página sem resposta").
    if (!isHomePage && !isCheckoutFlowPage) {
        fixBrokenProductImages(document);

        // Imagens ocultas no second-thumb (display:none) não disparam error — probe tardio
        window.setTimeout(function () {
            document.querySelectorAll('.second-thumb img[src*="/media/catalog/product/"]').forEach(function (img) {
                if (img.dataset.awaBrokenFinal === '1' || img.dataset.awaSecondProbe === '1') {
                    return;
                }
                img.dataset.awaSecondProbe = '1';

                if (img.complete && img.naturalWidth > 0) {
                    return;
                }

                let src = img.getAttribute('src') || '';
                if (!src) {
                    finalizeBrokenImage(img);
                    return;
                }

                let probe = new Image();
                probe.onload = function () {
                    if (probe.naturalWidth === 0) {
                        finalizeBrokenImage(img);
                    }
                };
                probe.onerror = function () {
                    finalizeBrokenImage(img);
                };
                probe.src = src;
            });

            lockPlpProductThumbsStatic();
            document.dispatchEvent(new CustomEvent('awa:product-images-ready', {
                bubbles: true,
                detail: { source: 'theme-heavy-second-probe' }
            }));
        }, 1200);

        // Also apply to dynamically loaded carousels
        if (window.MutationObserver) {
            let imgObserverTarget = document.querySelector('.page-wrapper') || document.body;
            if (imgObserverTarget) {
                let _imgPendingNodes = [];
                let _imgRafScheduled = false;
                let imgObserver = new MutationObserver(function (mutations) {
                    mutations.forEach(function (mutation) {
                        mutation.addedNodes.forEach(function (node) {
                            if (node && node.nodeType === 1) { _imgPendingNodes.push(node); }
                        });
                    });
                    if (!_imgRafScheduled && _imgPendingNodes.length > 0) {
                        _imgRafScheduled = true;
                        _ric(function () {
                            let nodes = _imgPendingNodes.splice(0);
                            _imgRafScheduled = false;
                            nodes.forEach(function (node) {
                                fixBrokenProductImages(node);
                                lockPlpProductThumbsStatic();
                            });
                        });
                    }
                });
                imgObserver.observe(imgObserverTarget, { childList: true, subtree: true });
            }
        }
    }
});
