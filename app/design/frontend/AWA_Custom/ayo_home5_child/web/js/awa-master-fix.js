/**
 * ===========================================
 * AWA MOTOS - JAVASCRIPT MASTER FIX (v2 — Consolidated)
 * Tema: AYO/Rokanthemes
 *
 * VERSÃO LIMPA: Removidas funções cujas causas-raiz
 * agora são tratadas por CSS consolidado (awa-core/layout/components/fixes).
 *
 * REMOVIDOS:
 *   - hideSoldInfo()          → CSS: .sold-qty, .vendor-name { display:none }
 *   - fixButtonPosition()     → CSS: flex order em product cards
 *   - fixDuplicatedProductNames() → CSS: product name display
 *   - fixPlaceholderHoverImages() → CSS: FIX-03 em awa-fixes.css
 *   - hideEmptyHeadings()     → CSS: FIX-05 em awa-fixes.css
 *   - fixProductCardHeight()  → CSS: grid layout em awa-components.css
 *   - initLazyLoad()          → delegado ao Rokanthemes theme.js
 *
 * MANTIDOS (comportamento de runtime não-substituível por CSS):
 *   - fixPrices()             → R$ 0,01 → "Consulte" (lógica de negócio)
 *   - translateTexts()        → pt_BR fallback (remover ao instalar i18n pack)
 *   - hideMagentoCode()       → Esconde {{block}} leaks em runtime
 *   - addInputMasks()         → Máscaras de telefone/CEP/CPF
 *   - initBackToTop()         → Cria botão + scroll listener
 *   - initWhatsAppButton()    → Cria botão WhatsApp flutuante
 *   - initStickyHeaderSpacer()→ MutationObserver p/ --awa-header-height
 *   - initMobileNavClose()    → Overlay + close + ESC handler
 *   - deduplicateHorizontalNav() → Remove itens duplicados por href
 *   - fixVerticalMenuToggles()→ A11y: role, tabindex, aria-expanded
 *   - fixSocialShareAlts()    → A11y: alt text para share images
 *   - fixNavToggleLabel()     → A11y: aria-label para nav toggle
 *   - fixReviewCount()        → Texto: "0 Avaliação" → "Seja o primeiro"
 *   - addSkipToMain()         → A11y: skip-to-main link
 *   - fixSliderAltText()      → A11y: alt text para slider
 *   - hideEmptyImages()       → Esconde img[src=""] carregadas dinamicamente
 *   - fixAyoModuleAlignment() → Fix inline-styles do Magento widgets
 *   - sanitizeEscapedProductImageCssText() → Remove CSS text leaked no DOM
 *   - OWL Tab Carousel fixes  → normalizeOwlItemClasses + refreshHomeTabCarousels
 *
 * INSTALAÇÃO:
 * Copiar para: app/design/frontend/ayo/ayo_home5/web/js/awa-master-fix.js
 * ===========================================
 */

(function () {
    'use strict';

    if (window.__AWA_MASTER_FIX_V2_LOADED__) {
        return;
    }
    window.__AWA_MASTER_FIX_V2_LOADED__ = true;

    var AWA_CONFIG = {
        debug: false,
        hidePrice001: true,
        translateTexts: true,  // TEMPORÁRIO — remover ao instalar pt_BR pack
        hideMagentoCode: true,
        deduplicateMenu: true, // Workaround: AYO pode gerar itens duplicados no menu horizontal
        // Layout — lidos via CSS custom properties quando disponíveis
        containerWidth: 1200,
        breakpoints: {
            desktopXL: 1400,        // AYO: ≥1400px (5 cols, container 1600px)
            desktop: 1200,
            desktopSmall: 992,
            tabletLandscape: 1199,  // AYO: ≤1199px
            tablet: 768,
            mobile: 480,
            mobileXS: 375,
            mobileXXS: 320
        },
        owlItems: {
            desktopXL: 5,   // AYO XL: 5 produtos
            desktop: 4,
            desktopSmall: 3,
            tablet: 2,
            mobile: 1
        },
        /* R17-11: timing constants — evita magic numbers */
        timings: {
            mutationDebounce: 350,
            resizeDebounce: 250,
            stickyResizeDebounce: 150,
            focusDelay: 300,
            tabRefreshDelay: 120,
            popupFocusDelay: 150,
            owlRetryInterval: 300
        }
    };

    /* R17-09: cache do .page-wrapper para evitar 15+ lookups redundantes */
    var _pageWrapper = null;
    function getPageWrapper() {
        if (!_pageWrapper || !_pageWrapper.isConnected) {
            _pageWrapper = document.querySelector('.page-wrapper');
        }
        return _pageWrapper || document.body;
    }

    /**
     * Lê --awa-container do CSS (fonte única de verdade).
     * CSS já faz o escalonamento via @media (1600px em XL, 1200px em desktop).
     * Fallback: AWA_CONFIG.containerWidth.
     */
    function getContainerWidth() {
        var cssVal = getComputedStyle(document.documentElement)
            .getPropertyValue('--awa-container');
        if (cssVal) {
            var trimmed = cssVal.trim();
            /* R17-01: se o valor é percentual (ex: "100%" em ≤1199px), usar viewport */
            if (trimmed.indexOf('%') !== -1) {
                return window.innerWidth;
            }
            var parsed = parseInt(trimmed, 10);
            if (parsed > 0) return parsed;
        }
        // Fallback dinâmico: 1600 em XL, 1200 caso contrário
        if (window.innerWidth >= AWA_CONFIG.breakpoints.desktopXL) {
            return 1600;
        }
        return AWA_CONFIG.containerWidth;
    }

    function log(msg) {
        if (AWA_CONFIG.debug) {
            console.log('[AWA Fix]', msg);
        }
    }

    // ===========================================
    // 0. NORMALIZAÇÃO DE LINKS LEGADOS (ofertas)
    // Corrige URLs antigas que podem apontar para /ofertas/ (404)
    // ===========================================
    function normalizeLegacyOfferLinks(roots) {
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        var fixed = 0;

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;

            root.querySelectorAll('a[href]').forEach(function (anchor) {
                var hrefAttr = anchor.getAttribute('href');
                if (!hrefAttr) return;

                var normalized = hrefAttr.trim();
                if (!/ofertas\/?($|[?#])|ofertas\.html\/?($|[?#])/i.test(normalized)) {
                    return;
                }

                var url;
                try {
                    url = new URL(normalized, window.location.origin);
                } catch (e) {
                    return;
                }

                var path = (url.pathname || '').replace(/\/+$/, '').toLowerCase();
                if (path === '/ofertas' || path === '/ofertas.html') {
                    url.pathname = '/ofertas.html';
                    var updated = url.toString();

                    if (/^\//.test(normalized) && !/^https?:\/\//i.test(normalized)) {
                        updated = url.pathname + url.search + url.hash;
                    }

                    if (updated !== hrefAttr) {
                        anchor.setAttribute('href', updated);
                        fixed++;
                    }
                }
            });
        });

        if (fixed > 0) {
            log('Legacy offer links normalized: ' + fixed);
        }
    }

    // ===========================================
    // 0B. SANEAMENTO DE HEADING CORROMPIDO (LGPD/CMS)
    // Remove prefixos "????" exibidos em alguns títulos institucionais
    // ===========================================
    function sanitizeCorruptedInstitutionalHeadings(roots) {
        var body = document.body;
        if (!body || !body.classList.contains('cms-page-view')) {
            return;
        }

        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        var cleaned = 0;

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;

            root.querySelectorAll('.awa-inst h2, .awa-inst h3, .awa-inst h4, .cms-page-view h2, .cms-page-view h3, .cms-page-view h4').forEach(function (heading) {
                var txt = (heading.textContent || '').trim();
                if (!txt) return;

                var normalized = txt.replace(/^[^\p{L}\p{N}]+\s*/u, '').trim();
                if (normalized !== txt) {
                    heading.textContent = normalized;
                    cleaned++;
                }
            });
        });

        if (cleaned > 0) {
            log('Corrupted institutional headings sanitized: ' + cleaned);
        }
    }

    /* R10-01: Translations hoisted to module scope (avoids re-allocation per call) */
    var AWA_TRANSLATIONS = {
        'Add to Cart': 'Adicionar ao Carrinho',
        'ADD TO CART': 'ADICIONAR AO CARRINHO',
        'Add to Wishlist': 'Adicionar \u00e0 Lista de Desejos',
        'Add to Compare': 'Comparar',
        'SSL Secure Connection': 'Conex\u00e3o SSL Segura',
        'Google Safe Browsing': 'Navega\u00e7\u00e3o Segura Google',
        'Out of stock': 'Esgotado',
        'OUT OF STOCK': 'ESGOTADO',
        'In stock': 'Em estoque',
        'IN STOCK': 'EM ESTOQUE',
        'Load more': 'Carregar mais',
        'LOAD MORE': 'CARREGAR MAIS',
        'Quick View': 'Ver Detalhes',
        'QUICK VIEW': 'VER DETALHES',
        'Review': 'Avalia\u00e7\u00e3o',
        'Reviews': 'Avalia\u00e7\u00f5es',
        'No reviews': 'Sem avalia\u00e7\u00f5es',
        'Be the first to review': 'Seja o primeiro a avaliar',
        'Qty': 'Qtd',
        'Quantity': 'Quantidade',
        'Subtotal': 'Subtotal',
        'View Cart': 'Ver Carrinho',
        'VIEW CART': 'VER CARRINHO',
        'Checkout': 'Finalizar Compra',
        'CHECKOUT': 'FINALIZAR COMPRA',
        'Proceed to Checkout': 'Finalizar Compra',
        'Continue Shopping': 'Continuar Comprando',
        'Apply': 'Aplicar',
        'Remove': 'Remover',
        'Update': 'Atualizar',
        'Clear All': 'Limpar Tudo',
        'Compare Products': 'Comparar Produtos',
        'My Wishlist': 'Minha Lista de Desejos',
        'My Cart': 'Meu Carrinho',
        'Search': 'Buscar',
        'Sign In': 'Entrar',
        'Sign Out': 'Sair',
        'Create an Account': 'Criar Conta',
        'Forgot Password': 'Esqueci minha senha',
        'Email Address': 'E-mail',
        'Password': 'Senha',
        'Confirm Password': 'Confirmar Senha',
        'First Name': 'Nome',
        'Last Name': 'Sobrenome',
        'Subscribe': 'Inscrever-se',
        'SUBSCRIBE': 'INSCREVER-SE',
        'Newsletter': 'Newsletter',
        'Sort By': 'Ordenar por',
        'Show': 'Mostrar',
        'per page': 'por p\u00e1gina',
        'Items': 'Itens',
        'Item': 'Item',
        'of': 'de',
        'Page': 'P\u00e1gina',
        'Next': 'Pr\u00f3ximo',
        'Previous': 'Anterior',
        'Home': 'In\u00edcio',
        'Shop': 'Loja',
        'Contact': 'Contato',
        'About Us': 'Sobre N\u00f3s',
        'Categories': 'Categorias',
        'All Categories': 'Todas as Categorias',
        'Customer Service': 'Atendimento ao Cliente',
        'Help': 'Ajuda',
        'FAQ': 'Perguntas Frequentes',
        'Shipping': 'Frete',
        'Returns': 'Devolu\u00e7\u00f5es',
        'Privacy Policy': 'Pol\u00edtica de Privacidade',
        'Terms of Service': 'Termos de Servi\u00e7o',
        'Order Status': 'Status do Pedido',
        'Track Order': 'Rastrear Pedido',
        'Entering your email also subscribe you to the latest Netro shop news and offers.':
            'Ao cadastrar seu e-mail, voc\u00ea receber\u00e1 novidades e ofertas exclusivas.',
        'Do not shop this popup again': 'N\u00e3o mostrar novamente',
        'Do not show this popup again': 'N\u00e3o mostrar novamente',
        'Drop Us A Message': 'Envie sua mensagem',
        "What's on your mind?": 'Como podemos ajudar?',
        'Your Message': 'Sua mensagem',
        'Phone Number': 'Telefone',
        'Send Message': 'Enviar mensagem',
        'Send message': 'Enviar mensagem',
        'Alternar Nav': 'Menu',
        'Toggle Nav': 'Menu'
    };

    /* R10-09: Regex hoisted to module scope (avoids re-compilation per call) */
    var RE_CSS_LEAK = /^\s*\.product-image-container-\d+\s*\{[^}]*width:\s*\d+px/i;

    // ===========================================
    // 1. CORRIGIR PREÇOS R$ 0,01 → "Consulte"
    // Lógica de negócio: produtos sem preço real exibem 0,01.
    // ===========================================
    function fixPrices(roots) {
        if (!AWA_CONFIG.hidePrice001) return;

        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll('.price').forEach(function (el) {
                var text = el.textContent.trim();
                if (text === 'R$ 0,01' || text === 'R$0,01' || text === 'R$ 0.01' || text === 'R$0.01') {
                    el.textContent = 'Consulte';
                    el.setAttribute('aria-label', 'Preço sob consulta');
                    el.classList.add('awa-price-consulte');
                    log('Fixed price: ' + text);
                }
            });
        });
    }

    // ===========================================
    // 2. TRADUZIR TEXTOS EM INGLÊS
    // TEMPORÁRIO — substituir por pacote i18n pt_BR oficial.
    // ===========================================
    function translateTexts(roots) {
        if (!AWA_CONFIG.translateTexts) return;

        var translations = AWA_TRANSLATIONS; /* R10-01: referencia constante do módulo */

        /* Percorre apenas os roots fornecidos (MutationObserver) ou page-wrapper inteiro (init) */
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            /* Ignora nós de texto soltos (não-Element) passados como root */
            if (!root || !root.querySelectorAll) return;

            var walker = document.createTreeWalker(
                root,
                NodeFilter.SHOW_TEXT,
                /* R18-01: skip text inside <script>/<style>/<textarea> to prevent corruption */
                {
                    acceptNode: function (n) {
                        var t = n.parentElement ? n.parentElement.tagName : '';
                        return (t === 'SCRIPT' || t === 'STYLE' || t === 'TEXTAREA')
                            ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
                    }
                },
                false
            );

            var node;
            while ((node = walker.nextNode())) {
                var text = node.textContent.trim();
                if (!text || text.length < 2) continue; /* R15-10: skip whitespace/single-char nodes */
                if (translations[text]) {
                    node.textContent = node.textContent.replace(text, translations[text]);
                }
            }

            root.querySelectorAll('input[placeholder], textarea[placeholder]').forEach(function (el) {
                var placeholder = el.getAttribute('placeholder');
                if (translations[placeholder]) {
                    el.setAttribute('placeholder', translations[placeholder]);
                }
            });

            /* R9-18: Restrict title translation to interactive elements only */
            root.querySelectorAll('a[title], button[title], .action[title], [role="button"][title], input[title]').forEach(function (el) {
                var title = el.getAttribute('title');
                if (translations[title]) {
                    el.setAttribute('title', translations[title]);
                }
            });
        });
    }

    // ===========================================
    // 3. ESCONDER CÓDIGO MAGENTO EXPOSTO
    // ===========================================
    function hideMagentoCode(roots) {
        if (!AWA_CONFIG.hideMagentoCode) return;

        // Patterns específicos de diretivas Magento — 'template="' removido
        // por alto risco de falso positivo em conteúdo legítimo
        var patterns = ['{{block', '{{widget', '{{store', '{{media', 'class="Magento'];

        /* R9-01: Aceita roots do MutationObserver para evitar full-DOM scan */
        var searchRoots;
        if (roots && roots.length) {
            searchRoots = roots;
        } else {
            /* R17-09: seletores já incluem .page-wrapper — query deve ser no document */
            searchRoots = document.querySelectorAll(
                '.page-wrapper .widget, .page-wrapper .block-cms-link, ' +
                '.page-wrapper .cms-page-view, .page-wrapper .homebuilder-section, ' +
                '.page-wrapper .block-static-block'
            );
            if (searchRoots.length === 0) {
                searchRoots = [getPageWrapper()];
            }
        }

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            var walker = document.createTreeWalker(
                root,
                NodeFilter.SHOW_TEXT,
                /* R18-01: skip text inside <script>/<style>/<textarea> to prevent corruption */
                {
                    acceptNode: function (n) {
                        var t = n.parentElement ? n.parentElement.tagName : '';
                        return (t === 'SCRIPT' || t === 'STYLE' || t === 'TEXTAREA')
                            ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
                    }
                },
                false
            );

            var node;
            while ((node = walker.nextNode())) {
                var text = node.textContent;
                if (!text || text.length < 5) continue;
                for (var i = 0; i < patterns.length; i++) {
                    if (text.indexOf(patterns[i]) !== -1) {
                        var parent = node.parentElement;
                        if (parent && !parent.classList.contains('awa-hidden-leak')) {
                            parent.classList.add('awa-hidden-leak');
                            log('Hidden Magento leak: ' + patterns[i]);
                        }
                        break;
                    }
                }
            }
        });
    }

    // ===========================================
    // 4. MÁSCARAS DE INPUT (Telefone, CEP, CPF/CNPJ)
    // ===========================================
    function addInputMasks(roots) {
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;

            root.querySelectorAll('input[name="telephone"], input[type="tel"]').forEach(function (input) {
                if (input.dataset.awaMask) return;
                input.dataset.awaMask = '1';
                function maskTel(e) {
                    var value = e.target.value.replace(/\D/g, '');
                    if (value.length === 0) { e.target.value = ''; return; }
                    if (value.length <= 2) {
                        value = '(' + value;
                    } else if (value.length <= 7) {
                        value = '(' + value.substring(0, 2) + ') ' + value.substring(2);
                    } else {
                        value = '(' + value.substring(0, 2) + ') ' + value.substring(2, 7) + '-' + value.substring(7, 11);
                    }
                    e.target.value = value;
                }
                input.addEventListener('input', maskTel);
                input.addEventListener('paste', function () {
                    setTimeout(function () { maskTel({ target: input }); }, 0);
                });
            });

            root.querySelectorAll('input[name="postcode"], input[name*="cep"]').forEach(function (input) {
                if (input.dataset.awaMask) return;
                input.dataset.awaMask = '1';
                input.addEventListener('input', function (e) {
                    var value = e.target.value.replace(/\D/g, '');
                    if (value.length > 5) {
                        value = value.substring(0, 5) + '-' + value.substring(5, 8);
                    }
                    e.target.value = value;
                });
            });

            root.querySelectorAll('input[name*="cpf"], input[name*="cnpj"], input[name*="taxvat"]').forEach(function (input) {
                if (input.dataset.awaMask) return;
                input.dataset.awaMask = '1';
                input.addEventListener('input', function (e) {
                    var value = e.target.value.replace(/\D/g, '');
                    if (value.length <= 11) {
                        if (value.length > 9) {
                            value = value.substring(0, 3) + '.' + value.substring(3, 6) + '.' + value.substring(6, 9) + '-' + value.substring(9, 11);
                        } else if (value.length > 6) {
                            value = value.substring(0, 3) + '.' + value.substring(3, 6) + '.' + value.substring(6);
                        } else if (value.length > 3) {
                            value = value.substring(0, 3) + '.' + value.substring(3);
                        }
                    } else {
                        /* CNPJ: xx.xxx.xxx/xxxx-xx — evita trailing dash quando < 14 dígitos */
                        var cnpj = value.substring(0, 2) + '.' + value.substring(2, 5) + '.' + value.substring(5, 8) + '/' + value.substring(8, 12);
                        if (value.length > 12) {
                            cnpj += '-' + value.substring(12, 14);
                        }
                        value = cnpj;
                    }
                    e.target.value = value;
                });
            });
        }); /* end searchRoots.forEach */

        log('Input masks initialized');
    }

    // ===========================================
    // 5. BACK TO TOP BUTTON
    // ===========================================
    function initBackToTop() {
        var btn = document.getElementById('awaBackToTop');
        var legacyBtn = document.getElementById('back-top');
        var fixedRightTopTriggers = document.querySelectorAll('.fixed-right .scroll-top');

        if (!btn && legacyBtn) {
            btn = legacyBtn;
            btn.classList.add('awa-backtotop-legacy');
            if (!btn.getAttribute('aria-label')) {
                btn.setAttribute('aria-label', 'Voltar ao topo');
            }
        }

        if (!btn) {
            btn = document.createElement('button');
            btn.id = 'awaBackToTop';
            btn.innerHTML = '&#8593;';
            btn.setAttribute('aria-label', 'Voltar ao topo');
            document.body.appendChild(btn);
        }

        function scrollToTop() {
            var motionOk = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({ top: 0, behavior: motionOk ? 'smooth' : 'auto' });
        }

        if (btn && btn.dataset.awaBackTopBound !== '1') {
            btn.dataset.awaBackTopBound = '1';
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                scrollToTop();
            });
        }

        fixedRightTopTriggers.forEach(function (trigger) {
            if (trigger.dataset.awaBackTopBound === '1') return;
            trigger.dataset.awaBackTopBound = '1';
            if (!trigger.getAttribute('role')) {
                trigger.setAttribute('role', 'button');
            }
            if (!trigger.getAttribute('tabindex')) {
                trigger.setAttribute('tabindex', '0');
            }
            if (!trigger.getAttribute('aria-label')) {
                trigger.setAttribute('aria-label', 'Voltar ao topo');
            }
            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                scrollToTop();
            });
            trigger.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    scrollToTop();
                }
            });
        });

        if (document.body.dataset.awaBackTopScrollBound !== '1') {
            document.body.dataset.awaBackTopScrollBound = '1';
            var scrollTicking = false;
            window.addEventListener('scroll', function () {
                if (!scrollTicking) {
                    window.requestAnimationFrame(function () {
                        if (btn) {
                            if (window.scrollY > 300) {
                                btn.classList.add('visible');
                            } else {
                                btn.classList.remove('visible');
                            }
                        }
                        scrollTicking = false;
                    });
                    scrollTicking = true;
                }
            }, { passive: true });
        }

        log('Back to top initialized');
    }

    // ===========================================
    // 6. STICKY HEADER SPACER
    // ===========================================
    // 6. STICKY HEADER SPACER
    // ===========================================
    /* R11-09: lógica DRY com função extraída */
    function initStickyHeaderSpacer() {
        var stickyWrapper = document.querySelector('.header-wrapper-sticky');
        var header = document.querySelector('.page-header');
        var target = stickyWrapper || header;
        if (!target) return;

        function updateStickyHeight() {
            var stickyWrapperActive = stickyWrapper &&
                (stickyWrapper.classList.contains('enable-sticky') || stickyWrapper.classList.contains('enabled-header-sticky'));
            var headerActive = header && (header.classList.contains('sticky') || header.classList.contains('fixed'));
            var isSticky = stickyWrapperActive || headerActive;
            if (isSticky) {
                var activeHeader = stickyWrapperActive ? stickyWrapper : (header || stickyWrapper);
                var h = activeHeader ? activeHeader.offsetHeight : 0;
                document.documentElement.style.setProperty('--awa-header-height', h + 'px');
                document.body.classList.add('sticky-header-active'); /* BUG-08: ativa padding-top no body */
            } else {
                document.documentElement.style.setProperty('--awa-header-height', '0px');
                document.body.classList.remove('sticky-header-active'); /* BUG-08: remove padding-top */
            }
        }

        var headerObserver = new MutationObserver(updateStickyHeight);
        var observedTargets = [];

        if (stickyWrapper && observedTargets.indexOf(stickyWrapper) === -1) {
            observedTargets.push(stickyWrapper);
        }
        if (header && observedTargets.indexOf(header) === -1) {
            observedTargets.push(header);
        }
        if (!observedTargets.length && target) {
            observedTargets.push(target);
        }

        observedTargets.forEach(function (el) {
            headerObserver.observe(el, { attributes: true, attributeFilter: ['class'] });
        });

        // Run once on init so CSS spacer stays in sync after hard reload / cached sticky state.
        updateStickyHeight();

        var stickyResizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(stickyResizeTimer);
            stickyResizeTimer = setTimeout(updateStickyHeight, AWA_CONFIG.timings.stickyResizeDebounce);
        }, { passive: true });

        log('Sticky header spacer initialized');
    }

    // ===========================================
    // 8. MOBILE NAV OVERLAY + CLOSE + ESC
    // ===========================================
    function isNavDrawerOpen() {
        return document.body.classList.contains('nav-open') ||
            document.documentElement.classList.contains('nav-open');
    }

    var _navSectionsCache = null;
    var _navToggleCache = null;

    function getNavSections() {
        if (!_navSectionsCache || !_navSectionsCache.isConnected) {
            _navSectionsCache = document.querySelector('.nav-sections');
        }
        return _navSectionsCache;
    }

    function getNavToggle() {
        if (!_navToggleCache || !_navToggleCache.isConnected) {
            _navToggleCache = document.querySelector('.nav-toggle, .action.nav-toggle');
        }
        return _navToggleCache;
    }

    function getNavElements() {
        return {
            navSections: getNavSections(),
            navToggle: getNavToggle()
        };
    }

    function setAriaIfChanged(el, attrName, value) {
        if (!el) return;
        if (el.getAttribute(attrName) !== value) {
            el.setAttribute(attrName, value);
        }
    }

    function syncMobileNavA11yState(isOpen) {
        var nav = getNavElements();
        var overlay = document.querySelector('.awa-nav-overlay');
        var hiddenValue = isOpen ? 'false' : 'true';
        var expandedValue = isOpen ? 'true' : 'false';
        var labelValue = isOpen ? 'Fechar menu de navegação' : 'Abrir menu de navegação';

        if (nav.navSections) {
            if (!nav.navSections.id) {
                nav.navSections.id = 'nav-sections';
            }
            setAriaIfChanged(nav.navSections, 'aria-hidden', hiddenValue);
        }

        if (nav.navToggle && nav.navSections) {
            setAriaIfChanged(nav.navToggle, 'aria-controls', nav.navSections.id);
        }

        setAriaIfChanged(nav.navToggle, 'aria-expanded', expandedValue);
        setAriaIfChanged(nav.navToggle, 'aria-label', labelValue);
        setAriaIfChanged(overlay, 'aria-hidden', hiddenValue);
    }

    function ensureMobileNavElements() {
        if (!document.querySelector('.awa-nav-overlay')) {
            var overlay = document.createElement('div');
            overlay.className = 'awa-nav-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            document.body.appendChild(overlay);
            overlay.addEventListener('click', closeMobileNav);
        }

        var navSections = document.querySelector('.nav-sections');
        if (navSections && !navSections.querySelector('.awa-nav-close')) {
            var closeBtn = document.createElement('button');
            closeBtn.className = 'awa-nav-close';
            closeBtn.innerHTML = '&#10005;';
            closeBtn.setAttribute('aria-label', 'Fechar menu');
            navSections.insertBefore(closeBtn, navSections.firstChild);
            closeBtn.addEventListener('click', closeMobileNav);
        }

        syncMobileNavA11yState(false);
    }

    function cleanupMobileNavElements() {
        closeMobileNav();
        document.querySelectorAll('.awa-nav-close, .awa-nav-overlay').forEach(function (el) {
            el.remove();
        });
    }

    function initMobileNavClose() {
        var navMediaQuery = window.matchMedia('(max-width: 991px)');

        function isMobileNavMediaActive() {
            return navMediaQuery.matches;
        }

        function applyMobileNavMode() {
            if (isMobileNavMediaActive()) {
                ensureMobileNavElements();
            } else {
                cleanupMobileNavElements();
            }
        }

        applyMobileNavMode();

        if (document.body.dataset.awaNavEscBound !== '1') {
            document.body.dataset.awaNavEscBound = '1';
            document.addEventListener('keydown', function (e) {
                if (!isMobileNavMediaActive()) return;
                if (e.key === 'Escape' && isNavDrawerOpen()) {
                    closeMobileNav();
                }
                // Focus trap: Tab dentro do drawer
                if (e.key === 'Tab' && isNavDrawerOpen()) {
                    trapFocusInNav(e);
                }
            });
        }

        // Ao clicar no nav-toggle para abrir, foca o primeiro item
        // R20-02: delegação cobre toggles recriados dinamicamente
        if (document.body && document.body.dataset.awaNavToggleDelegatedBound !== '1') {
            document.body.dataset.awaNavToggleDelegatedBound = '1';

            document.addEventListener('click', function (e) {
                if (!isMobileNavMediaActive()) return;

                var toggle = e.target && e.target.closest
                    ? e.target.closest('.nav-toggle, .action.nav-toggle')
                    : null;

                if (!toggle) return;

                // Aguarda Magento alternar classes do drawer para sincronizar estado real
                setTimeout(function () {
                    var isOpen = isNavDrawerOpen();
                    syncMobileNavA11yState(isOpen);

                    if (isOpen) {
                        // Aguarda transição do drawer abrir
                        setTimeout(focusFirstNavItem, AWA_CONFIG.timings.focusDelay);
                    }
                }, 0);
            }, true);
        }

        if (document.body.dataset.awaNavViewportBound !== '1') {
            document.body.dataset.awaNavViewportBound = '1';

            if (typeof navMediaQuery.addEventListener === 'function') {
                navMediaQuery.addEventListener('change', applyMobileNavMode);
            } else if (typeof navMediaQuery.addListener === 'function') {
                navMediaQuery.addListener(applyMobileNavMode);
            } else {
                var navResizeTimer;
                window.addEventListener('resize', function () {
                    clearTimeout(navResizeTimer);
                    navResizeTimer = setTimeout(applyMobileNavMode, AWA_CONFIG.timings.resizeDebounce);
                }, { passive: true });
            }
        }

        log('Mobile nav close + focus trap initialized');
    }

    /**
     * Prende o foco dentro de .nav-sections enquanto o drawer está aberto.
     * Ao abrir, foco vai para o primeiro elemento focável.
     */
    /* R10-03: Cache focusable elements — refreshed on nav open/close */
    var _navFocusableCache = null;

    function trapFocusInNav(e) {
        var navSections = document.querySelector('.nav-sections');
        if (!navSections) return;

        /* R19-01: Re-query if cache is stale (elements removed or detached from DOM) */
        if (_navFocusableCache && _navFocusableCache.length > 0) {
            var firstCached = _navFocusableCache[0];
            if (!firstCached.isConnected) {
                _navFocusableCache = null;
            }
        }
        if (!_navFocusableCache || !_navFocusableCache.length) {
            _navFocusableCache = navSections.querySelectorAll(
                'a[href], button:not([disabled]), [tabindex="0"]'
            );
        }
        var focusable = _navFocusableCache;
        if (!focusable.length) return;

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === first || !navSections.contains(document.activeElement)) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last || !navSections.contains(document.activeElement)) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function focusFirstNavItem() {
        var navSections = getNavElements().navSections;
        if (!navSections) return;
        var first = navSections.querySelector('a[href], button:not([disabled]), [tabindex="0"]');
        if (first) first.focus();
    }

    function closeMobileNav() {
        var nav = getNavElements();

        document.documentElement.classList.remove('nav-open', 'nav-before-open');
        document.body.classList.remove(
            'nav-open',
            'nav-before-open',
            'awa-nav-preflight',
            'awa-menu-drawer-open',
            'background_shadow',
            'background_shadow_show',
            'awa-vertical-menu-open'
        );
        if (nav.navSections) {
            nav.navSections.classList.remove('active');
        }
        syncMobileNavA11yState(false);
        _navFocusableCache = null; /* R10-03: invalidate cache on close */
        if (nav.navToggle) {
            /* R10-13: Only return focus if user was inside the drawer */
            if (nav.navSections && nav.navSections.contains(document.activeElement)) {
                nav.navToggle.focus();
            }
        }
    }

    // ===========================================
    // 9. ACCESSIBILITY FIXES
    // ===========================================
    function deduplicateHorizontalNav() {
        if (!AWA_CONFIG.deduplicateMenu) return;

        var nav = document.querySelector('.custommenu > ul, .navigation.custommenu > ul');
        if (!nav) return;
        var items = nav.querySelectorAll(':scope > li');
        var seenHrefs = {};
        items.forEach(function (li) {
            var a = li.querySelector(':scope > a');
            if (!a) return;
            var rawHref = a.getAttribute('href') || '';
            // Normaliza: remove protocolo+domínio, trailing slash, extensão .html
            var href;
            try {
                href = new URL(rawHref, location.origin).pathname;
            } catch (e) {
                href = rawHref;
            }
            href = href.replace(/\/+$/, '').replace(/\.html$/i, '').toLowerCase();
            if (!href) return; // ignora links vazios
            if (seenHrefs[href]) {
                li.classList.add('awa-hidden-leak');
                log('Dedup nav: hidden duplicate for ' + href);
            } else {
                seenHrefs[href] = true;
            }
        });
    }

    /**
     * AF-04: Deduplica seções de produto na homepage.
     *
     * Se a CMS page renderizar widgets que duplicam seções já presentes
     * em top-home.phtml (e.g., "Novidades"/"New Products"), a segunda
     * instância é ocultada. Identificação por classe CSS + heading text.
     */
    function deduplicateHomeSections() {
        if (!document.body.classList.contains('cms-index-index') &&
            !document.body.classList.contains('cms-home')) {
            return;
        }

        var sectionSelectors = [
            '.home-new-product',
            '.rokan-newproduct',
            '.home-bestseller',
            '.onsale_product'
        ];

        sectionSelectors.forEach(function (sel) {
            var els = Array.prototype.slice.call(document.querySelectorAll(sel)).filter(function (el) {
                // Home CMS sections inside .ayo-home5-wrapper are intentional and must not be hidden.
                return !el.closest('.ayo-home5-wrapper');
            });
            if (els.length <= 1) return;

            // Keep the first visible one, hide duplicates
            var kept = false;
            els.forEach(function (el) {
                if (!kept) {
                    kept = true;
                    return;
                }
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                el.classList.add('awa-hidden-duplicate');
                log('Dedup section: hidden duplicate ' + sel);
            });
        });
    }

    function fixVerticalMenuToggles(roots) {
        /* R13: aceita roots para processar novos toggles injetados via AJAX */
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll('.verticalmenu:not(.side-verticalmenu):not([data-awa-verticalmenu-owner="vertical-menu-init"]) .open-children-toggle').forEach(function (div) {
                if (div.dataset.awaVtoggle) return; /* R13: guard — já processado */
                div.dataset.awaVtoggle = '1';
                div.setAttribute('role', 'button');
                div.setAttribute('tabindex', '0');
                if (!div.hasAttribute('aria-expanded')) {
                    div.setAttribute('aria-expanded', 'false');
                }
                div.setAttribute('aria-label', 'Expandir subcategorias');
                div.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        div.click();
                    }
                });
            });
        });

        var expandLink = document.querySelector('.verticalmenu:not(.side-verticalmenu):not([data-awa-verticalmenu-owner="vertical-menu-init"]) .expand-category-link a');
        if (expandLink && !expandLink.dataset.awaExpandlinkOwner) {
            expandLink.setAttribute('role', 'button');
            if (!expandLink.hasAttribute('aria-expanded')) {
                expandLink.setAttribute('aria-expanded', 'false');
            }
            expandLink.setAttribute('href', '#');
            /* cursor gerido por CSS */
            expandLink.addEventListener('click', function (e) {
                e.preventDefault();
                var expanded = expandLink.getAttribute('aria-expanded') === 'true';
                expandLink.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            });
            expandLink.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    expandLink.click();
                }
            });
        }
    }

    /* R12-10: escopado a roots */
    function fixSocialShareAlts(roots) {
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        var socialMap = [
            ['a[href*="facebook.com/sharer"] img[alt=""]', 'Compartilhar no Facebook'],
            ['a[href*="twitter.com"] img[alt=""], a[href*="x.com"] img[alt=""]', 'Compartilhar no Twitter'],
            ['a[href*="pinterest.com"] img[alt=""]', 'Compartilhar no Pinterest'],
            ['a[href*="whatsapp"] img[alt=""]', 'Compartilhar no WhatsApp'],
            ['a[href*="linkedin.com"] img[alt=""]', 'Compartilhar no LinkedIn'],
            ['a[href*="instagram.com"] img[alt=""]', 'Ver no Instagram'],
            ['a[href*="t.me"] img[alt=""], a[href*="telegram"] img[alt=""]', 'Compartilhar no Telegram']
        ];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            socialMap.forEach(function (pair) {
                root.querySelectorAll(pair[0]).forEach(function (img) {
                    img.alt = pair[1];
                });
            });
        });
    }

    /* R12-09: escopado a roots */
    function hideEmptyImages(roots) {
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll('img').forEach(function (img) {
                var src = (img.getAttribute('src') || '').trim().toLowerCase();
                var isInvalidSrc = !src || src === '#' || src === 'about:blank' || src === 'javascript:void(0)';
                if (!isInvalidSrc) return;

                var link = img.closest('a');
                if (link && (link.getAttribute('href') === '#' && !link.textContent.trim())) {
                    link.classList.add('awa-hidden-leak');
                    link.setAttribute('aria-hidden', 'true');
                } else {
                    img.classList.add('awa-hidden-leak');
                    img.setAttribute('aria-hidden', 'true');
                }
            });
        });
    }

    /**
     * Fallback de imagem (sem Service Worker):
     * Se uma thumb em /media/catalog/product/cache/<hash>/... falhar (404),
     * troca automaticamente para /media/catalog/product/... para evitar imagem quebrada.
     *
     * Motivação: quando o cache de imagens do Magento está ausente/corrompido,
     * o frontend passa a requisitar URLs de cache que retornam 404.
     */
    var _awaImgCacheFallbackInstalled = false;
    function initProductImageCacheFallback(roots) {
        if (_awaImgCacheFallbackInstalled) {
            return;
        }
        _awaImgCacheFallbackInstalled = true;

        var cachePrefixRe = /\/media\/catalog\/product\/cache\/[^/]+\//i;

        function toOriginalCatalogUrl(src) {
            if (!src) return null;
            var url;
            try {
                url = new URL(src, window.location.origin);
            } catch (e) {
                return null;
            }

            if (url.origin !== window.location.origin) {
                return null;
            }

            if (!cachePrefixRe.test(url.pathname)) {
                return null;
            }

            url.pathname = url.pathname.replace(cachePrefixRe, '/media/catalog/product/');
            return url.toString();
        }

        function swapToFallback(img) {
            if (!img || img.tagName !== 'IMG') return false;
            if (img.dataset && img.dataset.awaCacheFallbackTried === '1') return false;

            var current = img.currentSrc || img.getAttribute('src') || '';
            var fallback = toOriginalCatalogUrl(current);
            if (!fallback) return false;

            if (img.dataset) {
                img.dataset.awaCacheFallbackTried = '1';
            }

            // Evitar que o browser continue tentando srcset com URLs /cache/
            img.removeAttribute('srcset');
            img.removeAttribute('sizes');

            img.src = fallback;
            return true;
        }

        // Captura erros de imagem (não fazem bubble, por isso use capture=true)
        document.addEventListener('error', function (event) {
            var target = event && event.target;
            if (!target || target.tagName !== 'IMG') return;

            swapToFallback(target);
        }, true);

        // Pass inicial: se alguma imagem já falhou antes do listener,
        // tenta recuperar olhando para naturalWidth.
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll('img').forEach(function (img) {
                try {
                    if (img.complete && img.naturalWidth === 0) {
                        swapToFallback(img);
                    }
                } catch (e) {
                    // Ignorar
                }
            });
        });
    }

    function fixNavToggleLabel() {
        var toggles = document.querySelectorAll('.nav-toggle, .action.nav-toggle');
        if (!toggles.length) return;

        var navSections = document.querySelector('.nav-sections');
        if (navSections && !navSections.id) {
            navSections.id = 'nav-sections';
        }

        var isOpen = isNavDrawerOpen();
        var ariaLabel = isOpen ? 'Fechar menu de navegação' : 'Abrir menu de navegação';

        toggles.forEach(function (toggle) {
            if (!toggle.getAttribute('role')) {
                toggle.setAttribute('role', 'button');
            }
            toggle.setAttribute('aria-label', ariaLabel);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

            if (navSections && !toggle.getAttribute('aria-controls')) {
                toggle.setAttribute('aria-controls', navSections.id);
            }

            var span = toggle.querySelector('span');
            if (span && (span.textContent.trim() === 'Alternar Nav' || span.textContent.trim() === 'Toggle Nav')) {
                span.textContent = 'Menu';
            }
        });
    }

    /* R15-02: armazena referência para desconectar em re-renders AJAX */
    var _minicartA11yObserver = null;
    var _minicartStateObserver = null;
    var _minicartContentObserver = null;
    var _minicartLastActionSource = null;
    var _minicartInputModality = 'pointer';

    function fixMinicartA11y() {
        var counter = document.querySelector('.minicart-wrapper .counter.qty');
        if (!counter) {
            return;
        }
        /* R15-02: desconectar observer anterior se existir (AJAX re-render) */
        if (_minicartA11yObserver) {
            _minicartA11yObserver.disconnect();
            _minicartA11yObserver = null;
        }
        if (!counter.getAttribute('aria-live')) {
            counter.setAttribute('aria-live', 'polite');
            counter.setAttribute('role', 'status');
        }
        /* R9-14: Contextual label for screen readers */
        function updateCounterLabel() {
            var q = (counter.textContent || '').trim();
            if (q) {
                counter.setAttribute('aria-label', q + ' ' + (q === '1' ? 'item' : 'itens') + ' no carrinho');
            }
        }
        updateCounterLabel();
        /* Update label when content changes */
        try {
            _minicartA11yObserver = new MutationObserver(updateCounterLabel);
            _minicartA11yObserver.observe(counter, { characterData: true, childList: true, subtree: true });
        } catch (e) {
            log('fixMinicartA11y MO error: ' + e.message);
        }

        var wrapper = document.querySelector('.minicart-wrapper');
        var trigger = wrapper ? wrapper.querySelector('.action.showcart') : null;
        var panel = wrapper ? wrapper.querySelector('.block-minicart') : null;
        var wrappers = document.querySelectorAll('.minicart-wrapper');
        var prompt = document.querySelector('.awa-header-account-prompt');
        if (!wrapper || !trigger || !panel) {
            return;
        }

        if (document.body && document.body.dataset.awaMinicartModalityBound !== '1') {
            document.body.dataset.awaMinicartModalityBound = '1';

            document.addEventListener('keydown', function (event) {
                var key = event.key;
                if (key === 'Tab' || key === 'Enter' || key === ' ' || key === 'Escape' || key === 'ArrowDown' || key === 'ArrowUp') {
                    _minicartInputModality = 'keyboard';
                }
            }, true);

            document.addEventListener('pointerdown', function () {
                _minicartInputModality = 'pointer';
            }, true);
        }

        function restoreFocusToActionSource() {
            var active = document.activeElement;
            var shouldRestore = _minicartInputModality === 'keyboard'
                || active === trigger
                || (panel && panel.contains(active));

            if (!shouldRestore) {
                return;
            }

            var source = _minicartLastActionSource;

            if (source && source.isConnected && source.offsetParent !== null) {
                try {
                    source.focus({ preventScroll: true });
                } catch (e) {
                    source.focus();
                }
                return;
            }

            if (trigger && trigger.isConnected) {
                try {
                    trigger.focus({ preventScroll: true });
                } catch (e2) {
                    trigger.focus();
                }
            }
        }

        if (!panel.id) {
            panel.id = 'awa-minicart-panel';
        }

        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-controls', panel.id);

        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'false');
        panel.setAttribute('aria-label', panel.getAttribute('aria-label') || 'Carrinho de compras');
        if (!panel.getAttribute('tabindex')) {
            panel.setAttribute('tabindex', '-1');
        }

        function isPanelVisible() {
            if (!panel || !wrapper) return false;
            var style = window.getComputedStyle(panel);
            var rect = panel.getBoundingClientRect();
            var wrapperOpen = wrapper.classList.contains('active')
                || wrapper.classList.contains('is-open')
                || wrapper.classList.contains('show');
            var hasDisplay = style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
            var hasGeometry = rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.right > 0;
            var hasActiveClass = panel.classList.contains('_active')
                || panel.classList.contains('active')
                || panel.classList.contains('is-open');
            var fixedCandidate = style.position === 'fixed' && hasGeometry;

            return wrapperOpen
                && hasDisplay
                && (hasGeometry || hasActiveClass)
                && (panel.offsetParent !== null || fixedCandidate || hasActiveClass);
        }

        var minicartLiveRegionId = 'awa-minicart-status';
        var minicartLiveRegion = document.getElementById(minicartLiveRegionId);
        var lastMinicartAnnouncement = '';
        var lastQtySnapshot = null;
        var lastSubtotalSnapshot = '';
        var lastMinicartEventAt = 0;
        var lastFocusedItemKey = '';
        var minicartDeltaDebounceTimer = null;

        if (!minicartLiveRegion) {
            minicartLiveRegion = document.createElement('div');
            minicartLiveRegion.id = minicartLiveRegionId;
            minicartLiveRegion.setAttribute('role', 'status');
            minicartLiveRegion.setAttribute('aria-live', 'polite');
            minicartLiveRegion.setAttribute('aria-atomic', 'true');
            applyVisuallyHiddenStyles(minicartLiveRegion);
            wrapper.insertBefore(minicartLiveRegion, wrapper.firstChild);
        }

        function setMinicartLivePriority(isAssertive) {
            minicartLiveRegion.setAttribute('aria-live', isAssertive ? 'assertive' : 'polite');
        }

        function announceMinicartMessage(message, isAssertive) {
            if (!message || message === lastMinicartAnnouncement) {
                return;
            }

            setMinicartLivePriority(!!isAssertive);
            minicartLiveRegion.textContent = message;
            lastMinicartAnnouncement = message;
        }

        appendAriaToken(trigger, 'aria-describedby', minicartLiveRegionId);

        function announceMinicartSummary() {
            var qty = (counter.textContent || '').replace(/\D/g, '');
            var qtyNum = qty ? parseInt(qty, 10) : 0;
            var subtotalEl = panel.querySelector('.subtotal .price, .amount .price, .minicart-items-wrapper .price');
            var subtotal = subtotalEl ? (subtotalEl.textContent || '').replace(/\s+/g, ' ').trim() : '';

            var message;
            if (qtyNum <= 0) {
                message = 'Carrinho vazio.';
            } else if (subtotal) {
                message = qtyNum + ' ' + (qtyNum === 1 ? 'item' : 'itens') + ' no carrinho. Subtotal ' + subtotal + '.';
            } else {
                message = qtyNum + ' ' + (qtyNum === 1 ? 'item' : 'itens') + ' no carrinho.';
            }

            announceMinicartMessage(message, false);
        }

        function announceMinicartDelta() {
            var now = Date.now();
            var qtyRaw = (counter.textContent || '').replace(/\D/g, '');
            var qtyNum = qtyRaw ? parseInt(qtyRaw, 10) : 0;
            var subtotalEl = panel.querySelector('.subtotal .price, .amount .price, .minicart-items-wrapper .price');
            var subtotal = subtotalEl ? (subtotalEl.textContent || '').replace(/\s+/g, ' ').trim() : '';
            var message = '';

            function getNewestItemData() {
                var item = panel.querySelector('.minicart-items .product-item, .minicart-items-wrapper .product-item, [data-role="dropdownDialog"] .product-item');
                if (!item) {
                    return null;
                }

                var link = item.querySelector('.product-item-name a, a.product-item-photo, a[href]');
                var nameEl = item.querySelector('.product-item-name a, .product-item-name, .product-item-details .product-item-name');
                var name = nameEl ? (nameEl.textContent || '').replace(/\s+/g, ' ').trim() : '';

                return {
                    key: (name || (link && link.getAttribute('href')) || '').toLowerCase(),
                    name: name,
                    focusTarget: link || item
                };
            }

            function maybeFocusNewestItem(itemData) {
                if (!itemData || !itemData.focusTarget || !isPanelVisible()) return;
                if (itemData.key && itemData.key === lastFocusedItemKey) return;

                var active = document.activeElement;
                var canMoveFocus = !active
                    || active === trigger
                    || panel.contains(active)
                    || active === document.body;

                if (!canMoveFocus) return;

                setTimeout(function () {
                    if (!isPanelVisible()) return;
                    try {
                        itemData.focusTarget.focus({ preventScroll: true });
                    } catch (e) {
                        itemData.focusTarget.focus();
                    }
                    if (itemData.key) {
                        lastFocusedItemKey = itemData.key;
                    }
                }, 90);
            }

            if (lastQtySnapshot === null) {
                lastQtySnapshot = qtyNum;
                lastSubtotalSnapshot = subtotal;
                return;
            }

            if (qtyNum > lastQtySnapshot) {
                var newestItem = getNewestItemData();
                if (newestItem && newestItem.name) {
                    message = newestItem.name + ' adicionado ao carrinho. Agora são ' + qtyNum + ' ' + (qtyNum === 1 ? 'item' : 'itens') + '.';
                } else {
                    message = 'Item adicionado ao carrinho. Agora são ' + qtyNum + ' ' + (qtyNum === 1 ? 'item' : 'itens') + '.';
                }
                maybeFocusNewestItem(newestItem);
                lastMinicartEventAt = now;
            } else if (qtyNum < lastQtySnapshot) {
                if (qtyNum <= 0) {
                    message = 'Item removido. Carrinho vazio.';
                } else {
                    message = 'Item removido do carrinho. Restam ' + qtyNum + ' ' + (qtyNum === 1 ? 'item' : 'itens') + '.';
                }
                lastMinicartEventAt = now;
            } else if (subtotal && subtotal !== lastSubtotalSnapshot) {
                // Evita anunciar subtotal logo após add/remove no mesmo ciclo de atualização.
                if (now - lastMinicartEventAt < 800) {
                    lastQtySnapshot = qtyNum;
                    lastSubtotalSnapshot = subtotal;
                    return;
                }
                message = 'Subtotal atualizado para ' + subtotal + '.';
                lastMinicartEventAt = now;
            }

            lastQtySnapshot = qtyNum;
            lastSubtotalSnapshot = subtotal;

            announceMinicartMessage(message, qtyNum < lastQtySnapshot);
        }

        function getFocusableElements() {
            if (!panel || !panel.querySelectorAll) return [];

            return Array.prototype.slice.call(
                panel.querySelectorAll(
                    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
                )
            ).filter(function (el) {
                return el.offsetParent !== null;
            });
        }

        function focusFirstPanelElement() {
            var focusables = getFocusableElements();
            if (focusables.length > 0) {
                focusables[0].focus();
                return;
            }
            panel.focus();
        }

        function closeMinicart() {
            if (!isPanelVisible()) return;

            var closeBtn = panel.querySelector('.action.close, .action.close[data-role="closeBtn"], [data-action="close"]');
            if (closeBtn) {
                closeBtn.click();
            } else if (trigger) {
                trigger.click();
            } else {
                wrapper.classList.remove('active');
            }

            // Limpa anúncio para não repetir estado antigo em reabertura.
            minicartLiveRegion.textContent = '';
            lastMinicartAnnouncement = '';
        }

        function isTransparentColor(colorValue) {
            return colorValue === 'transparent' || colorValue === 'rgba(0, 0, 0, 0)';
        }

        function enforceMinicartSurface(stage, isOpen) {
            var computed = window.getComputedStyle(panel);
            var panelRect = panel.getBoundingClientRect();
            var panelBg = computed.backgroundColor || '';
            var panelZ = parseInt(computed.zIndex || '0', 10);
            var panelHost = panel.querySelector('.block-content, .minicart-items-wrapper, .minicart-content-wrapper, .widget_shopping_cart_content');
            var hostBg = panelHost ? (window.getComputedStyle(panelHost).backgroundColor || '') : '';
            var transparentPanel = isTransparentColor(panelBg);
            var transparentHost = panelHost ? isTransparentColor(hostBg) : false;
            var weakZ = !isFinite(panelZ) || panelZ < 10020;
            var pointX;
            var pointY;
            var topNode;
            var topInside = 1;
            var topClass = '';
            var topTag = '';
            var topZ = '';
            var topPos = '';
            var changed = false;
            var stylePriority = 'important';

            window.__awaMinicartSurfaceProbeCount = window.__awaMinicartSurfaceProbeCount || 0;

            if (panelRect.width > 1 && panelRect.height > 1) {
                pointX = Math.max(1, Math.round(panelRect.left + Math.min(20, panelRect.width - 1)));
                pointY = Math.max(1, Math.round(panelRect.top + Math.min(20, panelRect.height - 1)));
                topNode = document.elementFromPoint(pointX, pointY);
                topInside = topNode && panel.contains(topNode) ? 1 : 0;
                topClass = topNode && topNode.className ? topNode.className : '';
                topTag = topNode && topNode.tagName ? topNode.tagName : '';
                if (topNode) {
                    topZ = window.getComputedStyle(topNode).zIndex || '';
                    topPos = window.getComputedStyle(topNode).position || '';
                }
            }

            if (
                window.__awaMinicartSurfaceProbeCount < 6
                && (transparentPanel || transparentHost || weakZ || (isOpen && topInside === 0))
            ) {
                window.__awaMinicartSurfaceProbeCount += 1;
            }

            if (transparentPanel || weakZ) {
                panel.style.setProperty('background-color', 'var(--awa-bg, #ffffff)', stylePriority);
                panel.style.setProperty('background-image', 'none', stylePriority);
                panel.style.setProperty('z-index', '10040', stylePriority);
                panel.style.setProperty('isolation', 'isolate', stylePriority);
                panel.style.setProperty('backdrop-filter', 'none', stylePriority);
                changed = true;
            }

            if (panelHost && transparentHost) {
                panelHost.style.setProperty('background-color', 'var(--awa-bg, #ffffff)', stylePriority);
                panelHost.style.setProperty('background-image', 'none', stylePriority);
                changed = true;
            }

            if (changed && window.__awaMinicartSurfaceProbeCount < 10) {
                window.__awaMinicartSurfaceProbeCount += 1;
            } else if (!changed && isOpen && topInside === 1 && window.__awaMinicartSurfaceProbeCount < 10) {
                window.__awaMinicartSurfaceProbeCount += 1;
            }
        }

        function sampleVisibleMinicarts(stage) {
            var nodes = document.querySelectorAll('.block-minicart, [data-role=\"dropdownDialog\"]');
            var visible = [];
            var i;
            var node;
            var cs;
            var rect;

            for (i = 0; i < nodes.length; i += 1) {
                node = nodes[i];
                if (!node) {
                    continue;
                }
                cs = window.getComputedStyle(node);
                rect = node.getBoundingClientRect();
                if (
                    cs.display !== 'none'
                    && cs.visibility !== 'hidden'
                    && parseFloat(cs.opacity || '1') > 0
                    && rect.width > 2
                    && rect.height > 2
                ) {
                    visible.push({
                        cls: node.className || '',
                        z: cs.zIndex || '',
                        pos: cs.position || '',
                        w: Math.round(rect.width),
                        h: Math.round(rect.height),
                        x: Math.round(rect.x),
                        y: Math.round(rect.y)
                    });
                }
            }

            window.__awaMinicartVisibleProbeCount = window.__awaMinicartVisibleProbeCount || 0;
            if (window.__awaMinicartVisibleProbeCount < 6) {
                window.__awaMinicartVisibleProbeCount += 1;
            }
        }

        function syncMinicartState() {
            var isOpen = isPanelVisible();
            var wasOpen = wrapper.getAttribute('data-awa-minicart-open') === '1';
            var promptRect = prompt ? prompt.getBoundingClientRect() : null;
            var panelRect = panel ? panel.getBoundingClientRect() : null;
            var panelComputed = window.getComputedStyle(panel);
            var currentHidden = panel.getAttribute('aria-hidden') || '';
            var wrapperLooksOpen = wrapper.classList.contains('active') || wrapper.classList.contains('is-open') || wrapper.classList.contains('show');
            var overlapPx = (promptRect && panelRect)
                ? Math.max(0, Math.round(promptRect.bottom - panelRect.top))
                : -1;
            window.__awaMinicartSyncCount = (window.__awaMinicartSyncCount || 0) + 1;
            window.__awaMinicartAriaProbeCount = window.__awaMinicartAriaProbeCount || 0;
            if (window.__awaMinicartSyncCount <= 4 || window.__awaMinicartSyncCount === 20 || (isOpen && overlapPx > 0)) {
            }

            enforceMinicartSurface(isOpen ? 'sync_open' : 'sync_closed', isOpen);

            if (wrapperLooksOpen && currentHidden === 'true' && window.__awaMinicartAriaProbeCount < 8) {
                window.__awaMinicartAriaProbeCount += 1;
            }

            if (!wrapperLooksOpen && panelRect && panelRect.height > 60 && panelComputed.display !== 'none' && panelComputed.visibility !== 'hidden' && window.__awaMinicartAriaProbeCount < 8) {
                window.__awaMinicartAriaProbeCount += 1;
            }

            if (window.__awaMinicartSyncCount <= 3 || window.__awaMinicartSyncCount === 20 || window.__awaMinicartSyncCount === 100) {
            }

            var nextExpanded = isOpen ? 'true' : 'false';
            if (trigger.getAttribute('aria-expanded') !== nextExpanded) {
                trigger.setAttribute('aria-expanded', nextExpanded);
            }

            var nextHidden = isOpen ? 'false' : 'true';
            if (panel.getAttribute('aria-hidden') !== nextHidden) {
                panel.setAttribute('aria-hidden', nextHidden);
                if (window.__awaMinicartAriaProbeCount < 8) {
                    window.__awaMinicartAriaProbeCount += 1;
                }
            }

            if (isOpen && !wasOpen) {
                setTimeout(focusFirstPanelElement, 60);
                setTimeout(announceMinicartSummary, 90);
            }

            wrapper.setAttribute('data-awa-minicart-open', isOpen ? '1' : '0');

            if (!isOpen) {
                if (minicartDeltaDebounceTimer) {
                    clearTimeout(minicartDeltaDebounceTimer);
                    minicartDeltaDebounceTimer = null;
                }
                minicartLiveRegion.textContent = '';
                lastMinicartAnnouncement = '';

                if (wasOpen) {
                    setTimeout(restoreFocusToActionSource, 40);
                }
            }
        }

        if (document.body && document.body.dataset.awaMinicartSourceBound !== '1') {
            document.body.dataset.awaMinicartSourceBound = '1';
            window.__awaMinicartShowcartProbeCount = window.__awaMinicartShowcartProbeCount || 0;

            document.addEventListener('click', function (event) {
                var source = event.target && event.target.closest
                    ? event.target.closest('.action.tocart, .tocart, button[data-post*="checkout/cart/add"], form[action*="checkout/cart/add"] button[type="submit"], form[action*="checkout/cart/add"] .action')
                    : null;
                var showcartHit = event.target && event.target.closest
                    ? event.target.closest('.minicart-wrapper .action.showcart, .showcart')
                    : null;

                if (source) {
                    _minicartLastActionSource = source;
                }
                if (showcartHit && window.__awaMinicartShowcartProbeCount < 3) {
                    window.__awaMinicartShowcartProbeCount += 1;
                }
            }, true);
        }

        if (document.body && document.body.dataset.awaMinicartAnyCartProbeBound !== '1') {
            document.body.dataset.awaMinicartAnyCartProbeBound = '1';
            window.__awaMinicartAnyCartProbeCount = window.__awaMinicartAnyCartProbeCount || 0;

            document.addEventListener('click', function (event) {
                var target = event && event.target && event.target.closest
                    ? event.target.closest('[aria-label*=\"carrinho\" i], [class*=\"cart\" i], [id*=\"cart\" i], a[href*=\"checkout/cart\" i], button[title*=\"carrinho\" i], .minicart-wrapper, .mini-cart-wrapper')
                    : null;
                var inHeader;

                if (!target) {
                    return;
                }

                inHeader = target.closest && target.closest('.awa-site-header, .header, .header_main, .header-main, .minicart-wrapper, .mini-cart-wrapper');
                if (!inHeader) {
                    return;
                }

                if (window.__awaMinicartAnyCartProbeCount < 6) {
                    window.__awaMinicartAnyCartProbeCount += 1;
                }

                setTimeout(function () {
                    sampleVisibleMinicarts('post_any_cart_click');
                }, 120);
            }, true);
        }

        if (trigger.dataset.awaMinicartBound !== '1') {
            trigger.dataset.awaMinicartBound = '1';
            trigger.addEventListener('click', function () {
                var promptRect = prompt ? prompt.getBoundingClientRect() : null;
                var panelRect = panel ? panel.getBoundingClientRect() : null;
                var overlapPx = (promptRect && panelRect)
                    ? Math.max(0, Math.round(promptRect.bottom - panelRect.top))
                    : -1;
                setTimeout(syncMinicartState, 0);
            });
        }

        if (document.body && document.body.dataset.awaMinicartKeyBound !== '1') {
            document.body.dataset.awaMinicartKeyBound = '1';
            document.addEventListener('keydown', function (event) {
                if (!isPanelVisible()) return;

                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeMinicart();
                    setTimeout(function () {
                        if (trigger) trigger.focus();
                    }, 30);
                    return;
                }

                if (event.key !== 'Tab') return;

                var focusables = getFocusableElements();
                if (!focusables.length) {
                    event.preventDefault();
                    panel.focus();
                    return;
                }

                var first = focusables[0];
                var last = focusables[focusables.length - 1];
                var active = document.activeElement;

                if (event.shiftKey) {
                    if (active === first || !panel.contains(active)) {
                        event.preventDefault();
                        last.focus();
                    }
                } else if (active === last || !panel.contains(active)) {
                    event.preventDefault();
                    first.focus();
                }
            });
        }

        try {
            if (_minicartStateObserver) {
                _minicartStateObserver.disconnect();
                _minicartStateObserver = null;
            }

            _minicartStateObserver = new MutationObserver(function (mutations) {
                var ariaMutationSeen = false;
                var i;
                window.__awaMinicartStateObserverHits = (window.__awaMinicartStateObserverHits || 0) + 1;

                for (i = 0; i < mutations.length; i += 1) {
                    if (mutations[i] && mutations[i].attributeName === 'aria-hidden') {
                        ariaMutationSeen = true;
                        break;
                    }
                }
                if (window.__awaMinicartStateObserverHits <= 3 || window.__awaMinicartStateObserverHits === 20 || window.__awaMinicartStateObserverHits === 100) {
                }
                if (window.__awaMinicartStateObserverHits <= 4) {
                }
                if (ariaMutationSeen && window.__awaMinicartAriaProbeCount < 8) {
                    window.__awaMinicartAriaProbeCount += 1;
                }
                syncMinicartState();
            });
            _minicartStateObserver.observe(wrapper, {
                attributes: true,
                attributeFilter: ['class']
            });
            _minicartStateObserver.observe(panel, {
                attributes: true,
                attributeFilter: ['class', 'style', 'aria-hidden']
            });
        } catch (e2) {
            log('fixMinicartA11y state MO error: ' + e2.message);
        }

        try {
            if (_minicartContentObserver) {
                _minicartContentObserver.disconnect();
                _minicartContentObserver = null;
            }

            _minicartContentObserver = new MutationObserver(function () {
                if (minicartDeltaDebounceTimer) {
                    clearTimeout(minicartDeltaDebounceTimer);
                }

                minicartDeltaDebounceTimer = setTimeout(function () {
                    minicartDeltaDebounceTimer = null;
                    announceMinicartDelta();
                }, 120);
            });

            _minicartContentObserver.observe(counter, {
                characterData: true,
                childList: true,
                subtree: true
            });

            _minicartContentObserver.observe(panel, {
                childList: true,
                subtree: true,
                characterData: true
            });
        } catch (e3) {
            log('fixMinicartA11y content MO error: ' + e3.message);
        }

        syncMinicartState();
        announceMinicartDelta();
    }

    /* R11-15: escopado a roots */
    function fixReviewCount(roots) {
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll('.reviews-actions .action.add, .reviews-actions a').forEach(function (el) {
                var text = el.textContent.trim();
                if (/^0\s+Avalia/.test(text)) {
                    el.textContent = 'Seja o primeiro a avaliar';
                } else {
                    var match = text.match(/^(\d+)\s+Avaliação$/);
                    if (match) {
                        var n = parseInt(match[1], 10);
                        el.textContent = n === 1 ? '1 Avaliação' : n + ' Avaliações';
                    }
                }
            });
        });
    }

    function addSkipToMain() {
        if (document.querySelector('a.skip-to-main, .action.skip, .awa-skip-to-main, a[href="#maincontent"]')) return;

        var target = document.getElementById('maincontent') ||
            document.querySelector('main, [role="main"], .page-main');

        if (!target) return;

        if (!target.id) {
            target.id = 'maincontent';
        }

        if (!target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '-1');
        }

        var skip = document.createElement('a');
        skip.href = '#maincontent';
        skip.className = 'awa-skip-to-main';
        skip.textContent = 'Pular para conteúdo principal';
        skip.setAttribute('aria-label', 'Pular para conteúdo principal');

        skip.addEventListener('click', function () {
            window.setTimeout(function () {
                try {
                    target.focus({ preventScroll: true });
                } catch (e) {
                    target.focus();
                }
            }, 0);
        });
        /* Estilos geridos por CSS (.awa-skip-to-main / :focus em awa-core.css) */

        document.body.insertBefore(skip, document.body.firstChild);
    }

    function fixSliderAltText(roots) {
        /* R13: aceita roots do MutationObserver para evitar full-DOM scan */
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll('img[alt="Homepage 5 Slider"], a[title="Homepage 5 Slider"]').forEach(function (el) {
                if (el.tagName === 'IMG') {
                    el.setAttribute('alt', 'Banner Promoção AWA Motos');
                }
                if (el.getAttribute('title') === 'Homepage 5 Slider') {
                    el.setAttribute('title', 'Banner Promoção AWA Motos');
                }
            });

            root.querySelectorAll('.banner-slider, .wrapper_slider, .banner_item').forEach(function (container) {
                var walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null, false);
                var node;
                while ((node = walker.nextNode())) {
                    if (node.textContent.trim() === 'Homepage 5 Slider') {
                        node.textContent = '';
                    }
                }
            });
        });
    }

    // ===========================================
    // 10. AYO MODULE ALIGNMENT (inline styles)
    // ===========================================
    function isHome5LikePage() {
        if (!document.body || !document.body.classList) {
            return false;
        }

        return document.body.classList.contains('cms-index-index')
            || document.body.classList.contains('cms-home')
            || document.body.classList.contains('cms-homepage_ayo_home5');
    }

    function fixAyoModuleAlignment() {
        if (!isHome5LikePage()) {
            return;
        }

        var containerWidth = getContainerWidth();
        var moduleSelectors = [
            '.homebuilder-section',
            '.home-bestseller',
            '.home-new-product',
            '.onsale_product',
            '.rokan-onsale',
            '.rokan-onsaleproduct',
            '.featured-products',
            '.tab_product',
            '.list-tab-product',
            '.categorytab-container'
        ].join(', ');
        var moduleRoots = document.querySelectorAll(moduleSelectors);

        moduleRoots.forEach(function (root) {
            // Passo 1: Clamp inline max-width excessivos ao container
            root.querySelectorAll('[style*="max-width"]').forEach(function (el) {
                if (el.classList.contains('product-image-container')) return;
                if (el.classList.contains('product-image-wrapper')) return;

                var inlineMax = parseInt(el.style.maxWidth, 10);
                if (inlineMax && inlineMax > containerWidth && inlineMax < 9999) {
                    el.style.maxWidth = containerWidth + 'px';
                    el.style.marginLeft = 'auto';
                    el.style.marginRight = 'auto';
                }
            });

            // Passo 2/3 REMOVIDOS — OWL flex/float agora é gerido 100% por CSS
            // (awa-components.css: .owl-stage display:flex !important, .owl-item float:none !important)
        });
    }

    // ===========================================
    // 11. SANITIZE ESCAPED CSS TEXT IN PRODUCT CARDS
    // ===========================================
    /* R12-08: escopado a roots */
    function sanitizeEscapedProductImageCssText(roots) {
        var cssLeak = RE_CSS_LEAK;
        var cleaned = 0;

        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;

            root.querySelectorAll('.product-item a, .item-product a').forEach(function (a) {
                var txt = (a.textContent || '').trim();
                if (cssLeak.test(txt)) {
                    a.textContent = '';
                    a.style.display = 'none';
                    cleaned++;
                }
            });

            root.querySelectorAll('.product-item, .item-product').forEach(function (card) {
                var walker = document.createTreeWalker(card, NodeFilter.SHOW_TEXT, null, false);
                var node;
                while ((node = walker.nextNode())) {
                    var text = (node.textContent || '').trim();
                    if (!cssLeak.test(text)) continue;
                    var parent = node.parentElement;
                    if (parent) {
                        parent.textContent = '';
                        parent.style.display = 'none';
                    } else {
                        node.textContent = '';
                    }
                    cleaned++;
                }
            });
        });

        if (cleaned > 0) {
            log('Sanitized escaped CSS text: ' + cleaned);
        }
    }

    // ===========================================
    // 12. OWL TAB CAROUSEL SELF-HEAL
    // ===========================================
    function normalizeOwlItemClasses(roots) {
        /* R14: aceita roots para evitar full-DOM scan */
        var sel = '.tab_product .owl-carousel .owl-item.grid, ' +
            '.categorytab-container .owl-carousel .owl-item.grid, ' +
            '.list-tab-product .owl-carousel .owl-item.grid';
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll(sel).forEach(function (item) {
                item.classList.remove('grid');
                if (item.style.display === 'grid') {
                    item.style.display = '';
                }
                if (item.closest('.owl-wrapper') && item.style.float === 'none') {
                    item.style.float = 'left';
                }
            });
        });
    }

    function getOwlItemsForViewport() {
        if (window.innerWidth >= AWA_CONFIG.breakpoints.desktopXL) return AWA_CONFIG.owlItems.desktopXL;
        if (window.innerWidth >= AWA_CONFIG.breakpoints.desktop) return AWA_CONFIG.owlItems.desktop;
        if (window.innerWidth >= AWA_CONFIG.breakpoints.desktopSmall) return AWA_CONFIG.owlItems.desktopSmall;
        if (window.innerWidth >= AWA_CONFIG.breakpoints.tablet) return AWA_CONFIG.owlItems.tablet;
        return AWA_CONFIG.owlItems.mobile;
    }

    function healBrokenOwlV1Layout(el) {
        if (!el || !el.querySelector) return;

        var wrapper = el.querySelector('.owl-wrapper');
        if (!wrapper) return;

        var rawChildren = wrapper.children || [];
        var items = [];
        for (var i = 0; i < rawChildren.length; i++) {
            if (rawChildren[i].classList && rawChildren[i].classList.contains('owl-item')) {
                items.push(rawChildren[i]);
            }
        }
        if (items.length < 2) return;

        var first = items[0];
        var firstRect = first.getBoundingClientRect();
        var wrapperRect = wrapper.getBoundingClientRect();

        var firstTop = first.offsetTop;
        var stacked = false;
        for (var j = 1; j < Math.min(items.length, 4); j++) {
            if (items[j].offsetTop > firstTop + 1) {
                stacked = true;
                break;
            }
        }

        var floatBroken = false;
        try {
            floatBroken = getComputedStyle(first).float === 'none';
        } catch (e) {
            floatBroken = false;
        }

        var collapsedWrapper = !!(firstRect.width && wrapperRect.width && wrapperRect.width <= (firstRect.width * 1.1));
        if (!stacked && !floatBroken && !collapsedWrapper) return;

        var visibleItems = Math.max(1, getOwlItemsForViewport());
        var carouselWidth = Math.round(el.getBoundingClientRect().width || 0);
        if (!carouselWidth) return;

        var itemWidth = Math.max(1, Math.floor(carouselWidth / visibleItems));
        wrapper.style.display = 'block';
        wrapper.style.width = (itemWidth * items.length) + 'px';

        items.forEach(function (item) {
            item.style.width = itemWidth + 'px';
            item.style.float = 'left';
            item.style.clear = 'none';
            item.style.display = 'block';
        });
    }

    function refreshOwlCarousel(el) {
        if (!el) return;

        var refreshed = false;
        if (window.jQuery) {
            var $ = window.jQuery;
            var $el = $(el);
            var owlV1 = $el.data('owlCarousel');
            if (owlV1) {
                try {
                    if (window.innerWidth >= AWA_CONFIG.breakpoints.desktopSmall) {
                        var isTabModule = el.closest('.tab_product, .categorytab-container, .list-tab-product');
                        if (isTabModule && owlV1.options && owlV1.options.items < 2) {
                            // XL: 5 itens (padrão AYO)
                            var itemsForScreen = (window.innerWidth >= AWA_CONFIG.breakpoints.desktopXL)
                                ? AWA_CONFIG.owlItems.desktopXL
                                : AWA_CONFIG.owlItems.desktop;
                            owlV1.options.items = itemsForScreen;
                            owlV1.options.itemsDesktop = [AWA_CONFIG.breakpoints.desktop, AWA_CONFIG.owlItems.desktop];
                            owlV1.options.itemsDesktopSmall = [AWA_CONFIG.breakpoints.desktopSmall, AWA_CONFIG.owlItems.desktopSmall];
                            owlV1.options.itemsTablet = [AWA_CONFIG.breakpoints.tablet, AWA_CONFIG.owlItems.tablet];
                            owlV1.options.itemsMobile = [AWA_CONFIG.breakpoints.mobile, AWA_CONFIG.owlItems.mobile];
                        }
                    }

                    if (typeof owlV1.reinit === 'function') {
                        owlV1.reinit();
                        refreshed = true;
                    } else if (typeof owlV1.updateVars === 'function') {
                        owlV1.updateVars();
                        refreshed = true;
                    }
                } catch (owlErr) {
                    log('OWL v1 refresh error: ' + owlErr.message);
                    /* Fallback: force reflow to recover layout */
                    refreshed = false;
                }
            }

            if ($el.data('owl.carousel')) {
                $el.trigger('refresh.owl.carousel');
                refreshed = true;
            }
        }

        if (!refreshed) {
            /* R9-04: Target only the carousel element, not global resize
               which triggers all Magento/AYO/3rd-party resize listeners */
            try {
                el.style.display = 'none';
                void el.offsetHeight; /* force reflow */
                el.style.display = '';
            } catch (e) { /* fallback silencioso */ }
        }

        // OWL v1 às vezes mantém wrapper/item com largura inválida em tabs/carouséis ocultos.
        healBrokenOwlV1Layout(el);
    }

    function refreshHomeTabCarousels(roots) {
        if (!document.querySelector('.tab_product, .categorytab-container, .list-tab-product')) return;
        normalizeOwlItemClasses(roots);

        document.querySelectorAll(
            '.tab_product .owl-carousel, ' +
            '.categorytab-container .owl-carousel, ' +
            '.list-tab-product .owl-carousel'
        ).forEach(function (carousel) {
            refreshOwlCarousel(carousel);
        });
    }

    function bindTabCarouselRefresh() {
        if (!document.querySelector('.tab_product, .categorytab-container, .list-tab-product')) return;
        if (document.body.getAttribute('data-awa-tab-carousel-bind') === '1') return;
        document.body.setAttribute('data-awa-tab-carousel-bind', '1');

        document.addEventListener('click', function (e) {
            var trigger = e.target.closest(
                '.list-tab-product .tabs li, ' +
                '.categorytab-container .tabs li, ' +
                '.tab_product .tabs li, ' +
                '.list-tab-product .tab-title, ' +
                '.categorytab-container .tab-title'
            );
            if (!trigger) return;

            setTimeout(function () {
                refreshHomeTabCarousels();
            }, AWA_CONFIG.timings.tabRefreshDelay);
        });
    }

    // ===========================================
    // 13. STATE CLASSES (substitui dependências de :has())
    // Antes: CSS usava :has() em alguns pontos (box_language vazio,
    // propagação de estado ativo no menu, placeholders de hover, etc.).
    // Agora: aplicamos classes de estado via JS para manter CSS simples
    // e mais resiliente.
    // ===========================================
    /* R15-11: Cache do resultado de CSS.supports para evitar reavaliação a cada resize */
    var _hasSelectorSupported = null;
    var _lastStateRefreshAt = 0;

    function detectHasSupport() {
        if (_hasSelectorSupported === null) {
            try {
                _hasSelectorSupported = CSS && CSS.supports && CSS.supports('selector(:has(*))');
            } catch (e) {
                _hasSelectorSupported = false;
            }
        }
        return _hasSelectorSupported;
    }

    function setClass(el, className, enabled) {
        if (!el) return;
        if (enabled) {
            el.classList.add(className);
        } else {
            el.classList.remove(className);
        }
    }

    function refreshBoxLanguageState() {
        var headerNav = document.querySelector('.header-nav');
        if (!headerNav) return;

        var anyEmpty = false;
        headerNav.querySelectorAll('.box_language').forEach(function (boxLang) {
            var topHeader = boxLang.querySelector('.top-header');
            var isEmpty = false;

            /* Mantém o mesmo critério do CSS anterior: apenas .top-header:empty */
            if (topHeader) {
                var textEmpty = (topHeader.textContent || '').trim() === '';
                var childrenEmpty = topHeader.children.length === 0;
                isEmpty = textEmpty && childrenEmpty;
            }

            setClass(boxLang, 'awa-is-empty', isEmpty);
            if (isEmpty) {
                anyEmpty = true;
            }

            var row = boxLang.closest('.row');
            setClass(row, 'awa-box-language-empty', isEmpty);
        });

        setClass(document.body, 'awa-box-language-empty', anyEmpty);
    }

    function refreshSecuritySealsState() {
        var hasSeals = !!document.querySelector('.ayo-home5-wrapper .security-seals, .security-seals');
        setClass(document.body, 'awa-has-security-seals', hasSeals);
    }

    function refreshEmptyHomeSectionsState() {
        if (!document.querySelector('.ayo-home5-wrapper, .ayo-home5-section')) return;

        document.querySelectorAll('.ayo-home5-section').forEach(function (section) {
            var grid = section.querySelector('.ayo-home5-product-grid');
            if (!grid) return;

            var hasAnyItem = !!grid.querySelector('.item-product, .product-item, .owl-item, .note-msg');
            setClass(section, 'awa-is-empty', !hasAnyItem);
        });
    }

    function refreshMenuCurrentDescendantState() {
        var nav = document.querySelector('.header-nav .menu_primary .navigation.custommenu.main-nav');
        if (!nav) return;

        var ul = nav.querySelector('ul');
        if (!ul) return;

        Array.prototype.slice.call(ul.children).forEach(function (li) {
            if (!li || li.nodeType !== 1) return;
            if (li.tagName && li.tagName.toLowerCase() !== 'li') return;

            var hasCurrent = !!li.querySelector('.submenu a[aria-current="page"], a[aria-current="page"]');
            setClass(li, 'awa-has-current-descendant', hasCurrent);
        });
    }

    function refreshSecondThumbPlaceholderState() {
        document.querySelectorAll('.product-item').forEach(function (item) {
            var placeholderImg = item.querySelector('.second-thumb img[src*="placeholder/placeholder"]');
            setClass(item, 'awa-second-thumb-placeholder', !!placeholderImg);
        });
    }

    function applyHasFallback() {
        /* Throttle para evitar custo em resize contínuo */
        var now = Date.now();
        if (now - _lastStateRefreshAt < 150) return;
        _lastStateRefreshAt = now;

        // Mantém a detecção para compat/telemetria (não dependemos mais disso no CSS)
        detectHasSupport();

        refreshBoxLanguageState();
        refreshSecuritySealsState();
        refreshEmptyHomeSectionsState();
        refreshMenuCurrentDescendantState();
        refreshSecondThumbPlaceholderState();

        log('State classes refreshed');
    }

    // ===========================================
    // FOOTER ACCORDION (mobile ≤767px)
    // AYO hides .velaContent by default and toggles via JS.
    // We hook into .velaFooterTitle clicks to add .active.
    // ===========================================
    function initFooterAccordion() {
        if (window.innerWidth > 767) return;

        var titles = document.querySelectorAll('.page-footer .velaFooterTitle');
        titles.forEach(function (title, idx) {
            if (title.dataset.awaAccordion) return;
            title.dataset.awaAccordion = '1';

            /* aria-controls: vincula título ao painel */
            var content = title.nextElementSibling;
            if (content) {
                var contentId = content.id || ('awa-footer-panel-' + idx);
                content.id = contentId;
                content.setAttribute('role', 'region');
                title.setAttribute('aria-controls', contentId);
                /* R9-10: Bi-directional aria link panel↔title */
                var titleId = title.id || ('awa-footer-title-' + idx);
                title.id = titleId;
                content.setAttribute('aria-labelledby', titleId);
            }

            /* cursor gerido por CSS (.velaFooterTitle[role="button"]) */
            title.setAttribute('role', 'button');
            title.setAttribute('aria-expanded', 'false');

            function toggleAccordion() {
                var panel = this.nextElementSibling;
                if (!panel) return;
                var isOpen = panel.classList.contains('active');
                if (isOpen) {
                    panel.classList.remove('active');
                    this.setAttribute('aria-expanded', 'false');
                } else {
                    panel.classList.add('active');
                    this.setAttribute('aria-expanded', 'true');
                }
            }

            title.setAttribute('tabindex', '0');
            title.addEventListener('click', toggleAccordion);
            title.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleAccordion.call(this);
                }
            });
        });

        log('Footer accordion initialized (with keyboard support)');
    }

    // ===========================================
    // CATEGORY FILTER TOGGLE (mobile ≤991px)
    // Cria botão "Filtrar" que mostra/esconde sidebar
    // ===========================================
    function initCategoryFilterToggle() {
        var existing = document.querySelector('.awa-filter-toggle');
        if (window.innerWidth > 991) {
            /* Desktop: remove toggle button and reset sidebar visibility */
            if (existing) {
                existing.remove();
                document.body.classList.remove('awa-filter-visible');
            }
            return;
        }
        if (existing) return;

        var sidebar = document.querySelector('.sidebar-main');
        if (!sidebar) return;

        var toolbar = document.querySelector('.toolbar-products');
        if (!toolbar) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'awa-filter-toggle';
        btn.textContent = 'Filtrar';
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-controls', 'awa-sidebar-filter');
        sidebar.id = 'awa-sidebar-filter';

        btn.addEventListener('click', function () {
            var isOpen = document.body.classList.toggle('awa-filter-visible');
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            btn.textContent = isOpen ? 'Fechar Filtros' : 'Filtrar';
        });

        toolbar.parentNode.insertBefore(btn, toolbar);
        log('Category filter toggle created');
    }

    // ===========================================
    // OWL CAROUSEL A11Y LABELS
    // Adiciona aria-label para OWL nav buttons
    // ===========================================
    function fixOwlNavA11y(roots) {
        /* R14: aceita roots do MutationObserver para evitar full-DOM scan */
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;

            root.querySelectorAll('.owl-carousel').forEach(function (carousel, i) {
                if (carousel.getAttribute('role')) return;
                carousel.setAttribute('role', 'region');
                carousel.setAttribute('aria-roledescription', 'carrossel');
                var section = carousel.closest('.homebuilder-section, .home-bestseller, .home-new-product, .home-category, .onsale_product, .hot-deal-section, .featured-products, .testimonials-home, .the_blog, .tab_product, .list-tab-product, .banner-slider, .categorytab-container');
                var titleEl = section ? section.querySelector('.block-title, .section-title, .title-category, .box-title') : null;
                var label = titleEl ? titleEl.textContent.trim() : ('Carrossel ' + (i + 1));
                carousel.setAttribute('aria-label', label);
            });

            root.querySelectorAll('.owl-carousel .owl-nav button.owl-prev').forEach(function (btn) {
                if (!btn.getAttribute('aria-label')) btn.setAttribute('aria-label', 'Anterior');
            });
            root.querySelectorAll('.owl-carousel .owl-nav button.owl-next').forEach(function (btn) {
                if (!btn.getAttribute('aria-label')) btn.setAttribute('aria-label', 'Próximo');
            });
            root.querySelectorAll('.owl-carousel .owl-dots button.owl-dot').forEach(function (dot, i) {
                if (!dot.getAttribute('aria-label')) dot.setAttribute('aria-label', 'Slide ' + (i + 1));
                dot.setAttribute('role', 'tab');
                dot.setAttribute('aria-selected', dot.classList.contains('active') ? 'true' : 'false');
            });
            root.querySelectorAll('.owl-carousel .owl-dots').forEach(function (dots) {
                if (dots.getAttribute('role')) return;
                dots.setAttribute('role', 'tablist');
                dots.setAttribute('aria-label', 'Navegação de slides');
            });
        });

        /* jQuery event binding permanece global — uma vez por carousel */
        if (window.jQuery) {
            window.jQuery('.owl-carousel').each(function () {
                var $carousel = window.jQuery(this);
                if ($carousel.data('awa-dot-a11y')) return;
                $carousel.data('awa-dot-a11y', true);
                $carousel.on('changed.owl.carousel translated.owl.carousel', function () {
                    $carousel.find('.owl-dot').each(function () {
                        this.setAttribute('aria-selected', this.classList.contains('active') ? 'true' : 'false');
                    });
                });
            });
        }

        log('OWL carousel a11y labels applied');
    }

    var _searchAutocompleteObserver = null;

    function applyVisuallyHiddenStyles(el) {
        if (!el || !el.style) return;

        el.style.position = 'absolute';
        el.style.width = '1px';
        el.style.height = '1px';
        el.style.padding = '0';
        el.style.margin = '-1px';
        el.style.overflow = 'hidden';
        el.style.clip = 'rect(0, 0, 0, 0)';
        el.style.whiteSpace = 'nowrap';
        el.style.border = '0';
    }

    function appendAriaToken(el, attr, token) {
        if (!el || !attr || !token) return;

        var current = (el.getAttribute(attr) || '').trim();
        var tokens = current ? current.split(/\s+/) : [];

        if (tokens.indexOf(token) === -1) {
            tokens.push(token);
            el.setAttribute(attr, tokens.join(' ').trim());
        }
    }

    // ===========================================
    // R13: SEARCH AUTOCOMPLETE A11Y
    // Adiciona roles ARIA para screen readers
    // ===========================================
    function fixSearchAutocompleteA11y() {
        var autocomplete = document.querySelector('.search-autocomplete');
        var input = document.querySelector('#search');
        if (!autocomplete || !input) return;
        autocomplete.classList.add('searchsuite-autocomplete');
        autocomplete.setAttribute('role', 'listbox');
        if (!autocomplete.getAttribute('aria-label')) {
            autocomplete.setAttribute('aria-label', 'Sugestões de busca');
        }
        autocomplete.id = autocomplete.id || 'awa-search-autocomplete';
        input.setAttribute('aria-controls', autocomplete.id);
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-haspopup', 'listbox');

        var liveRegionId = 'awa-search-autocomplete-status';
        var liveRegion = document.getElementById(liveRegionId);
        var liveAnnounceTimer = null;
        var lastLiveAnnouncement = '';
        var lastSelectionAnnouncementKey = '';

        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.id = liveRegionId;
            liveRegion.setAttribute('role', 'status');
            liveRegion.setAttribute('aria-live', 'polite');
            liveRegion.setAttribute('aria-atomic', 'true');
            applyVisuallyHiddenStyles(liveRegion);
            input.parentNode.insertBefore(liveRegion, input.nextSibling);
        }

        appendAriaToken(input, 'aria-describedby', liveRegionId);

        function announceSearchStatus(message, immediate) {
            var normalized = (message || '').trim();

            if (normalized === lastLiveAnnouncement) {
                return;
            }

            if (liveAnnounceTimer) {
                clearTimeout(liveAnnounceTimer);
                liveAnnounceTimer = null;
            }

            var applyAnnouncement = function () {
                liveRegion.textContent = normalized;
                lastLiveAnnouncement = normalized;
            };

            if (immediate) {
                applyAnnouncement();
                return;
            }

            liveAnnounceTimer = setTimeout(function () {
                applyAnnouncement();
                liveAnnounceTimer = null;
            }, 140);
        }

        function getAutocompleteOptionLabel(option) {
            if (!option) return '';

            var rawText = (option.textContent || '').replace(/\s+/g, ' ').trim();
            return rawText;
        }

        function announceActiveSelection(option, total) {
            if (!option || !total) return;

            var optionLabel = getAutocompleteOptionLabel(option);
            if (!optionLabel) return;

            var allOptions = Array.prototype.slice.call(
                autocomplete.querySelectorAll('li[role="option"], [role="option"]')
            );
            var index = allOptions.indexOf(option) + 1;
            if (index <= 0) {
                index = 1;
            }

            var key = (option.id || optionLabel) + '|' + index + '|' + total;
            if (key === lastSelectionAnnouncementKey) {
                return;
            }

            lastSelectionAnnouncementKey = key;
            announceSearchStatus('Sugestão ' + index + ' de ' + total + ': ' + optionLabel, true);
        }

        if (input.dataset.awaSearchA11yBound !== '1') {
            input.dataset.awaSearchA11yBound = '1';

            input.addEventListener('input', function () {
                var hasQuery = !!(input.value && input.value.trim());
                var hasMinChars = hasQuery && input.value.trim().length >= 2;

                if (!hasMinChars) {
                    input.setAttribute('aria-expanded', 'false');
                    input.removeAttribute('aria-activedescendant');
                    input.removeAttribute('aria-busy');
                    autocomplete.setAttribute('aria-hidden', 'true');
                    lastSelectionAnnouncementKey = '';
                    announceSearchStatus(hasQuery ? 'Digite ao menos 2 caracteres para ver sugestões.' : '', true);
                    return;
                }

                input.setAttribute('aria-busy', 'true');
            });

            input.addEventListener('keydown', function (event) {
                var key = event.key;
                if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Enter' || key === 'Escape') {
                    setTimeout(function () {
                        tagAutocompleteItems();
                    }, 0);
                }
            });

            input.addEventListener('blur', function () {
                setTimeout(function () {
                    if (!autocomplete.contains(document.activeElement)) {
                        input.setAttribute('aria-expanded', 'false');
                        input.removeAttribute('aria-activedescendant');
                        autocomplete.setAttribute('aria-hidden', 'true');
                    }
                }, 120);
            });
        }

        /* R15-03: Marcar itens individuais com role="option" para screen readers */
        function tagAutocompleteItems() {
            var items = autocomplete.querySelectorAll('li, [role="option"]');

            items.forEach(function (li, i) {
                if (!li.getAttribute('role')) {
                    li.setAttribute('role', 'option');
                }
                if (!li.id) {
                    li.id = 'awa-ac-option-' + i;
                }
                if (!li.hasAttribute('aria-selected')) {
                    li.setAttribute('aria-selected', 'false');
                }

                if (li.dataset.awaAcItemBound !== '1') {
                    li.dataset.awaAcItemBound = '1';

                    li.addEventListener('mouseenter', function () {
                        var siblings = li.parentElement ? li.parentElement.querySelectorAll('li, [role="option"]') : [];
                        siblings.forEach(function (sib) {
                            sib.setAttribute('aria-selected', sib === li ? 'true' : 'false');
                        });
                        if (li.id) {
                            input.setAttribute('aria-activedescendant', li.id);
                        }
                    });
                }
            });

            // R20-04: fallback de provider — converte classes ativas em aria-selected
            var activeOption = autocomplete.querySelector('li[aria-selected="true"], [role="option"][aria-selected="true"]');
            if (!activeOption) {
                activeOption = autocomplete.querySelector('li.selected, li.active, li[aria-current="true"], li[data-selected="true"], [role="option"].selected, [role="option"].active, [role="option"][aria-current="true"], [role="option"][data-selected="true"]');
            }

            if (activeOption) {
                items.forEach(function (li) {
                    li.setAttribute('aria-selected', li === activeOption ? 'true' : 'false');
                    li.classList.toggle('awa-ac-active', li === activeOption);
                });
            } else {
                // R20-03: listbox deve expor no máximo uma opção ativa
                var selectedOptions = autocomplete.querySelectorAll('li[aria-selected="true"], [role="option"][aria-selected="true"]');
                if (selectedOptions.length > 1) {
                    for (var s = 1; s < selectedOptions.length; s++) {
                        selectedOptions[s].setAttribute('aria-selected', 'false');
                    }
                }

                items.forEach(function (li) {
                    li.classList.toggle('awa-ac-active', li.getAttribute('aria-selected') === 'true');
                });
            }

            var total = items.length;
            var hasQuery = !!(input.value && input.value.trim());
            var hasMinChars = hasQuery && input.value.trim().length >= 2;
            var isExpanded = hasMinChars && total > 0;

            input.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            input.setAttribute('aria-busy', 'false');
            autocomplete.setAttribute('aria-hidden', isExpanded ? 'false' : 'true');

            if (autocomplete.getAttribute('data-awa-last-count') !== String(total)) {
                autocomplete.setAttribute('data-awa-last-count', String(total));

                if (!hasQuery) {
                    announceSearchStatus('', false);
                } else if (!hasMinChars) {
                    announceSearchStatus('Digite ao menos 2 caracteres para ver sugestões.', false);
                } else if (total === 0) {
                    announceSearchStatus('Nenhum resultado encontrado para a busca.', false);
                } else if (total === 1) {
                    announceSearchStatus('1 resultado encontrado na busca.', false);
                } else {
                    announceSearchStatus(total + ' resultados encontrados na busca.', false);
                }
            }

            var selectedOption = autocomplete.querySelector('li[aria-selected="true"], [role="option"][aria-selected="true"]');
            if (selectedOption && selectedOption.id) {
                input.setAttribute('aria-activedescendant', selectedOption.id);
                announceActiveSelection(selectedOption, total);
            } else {
                input.removeAttribute('aria-activedescendant');
                lastSelectionAnnouncementKey = '';
            }
        }
        tagAutocompleteItems();
        /* MutationObserver para itens inseridos dinamicamente pelo Magento */
        try {
            if (_searchAutocompleteObserver) {
                _searchAutocompleteObserver.disconnect();
            }

            _searchAutocompleteObserver = new MutationObserver(function () {
                tagAutocompleteItems();
            });
            _searchAutocompleteObserver.observe(autocomplete, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'aria-selected', 'aria-current', 'data-selected']
            });
        } catch (e) {
            log('Search autocomplete MO error: ' + e.message);
        }

        log('Search autocomplete a11y applied');
    }

    // ===========================================
    // DEBOUNCED RESIZE LISTENER
    // Re-runs breakpoint-aware functions on viewport change
    // ===========================================
    var resizeTimer = null;
    function initResizeListener() {
        if (document.body && document.body.dataset.awaResizeBound === '1') {
            return;
        }

        if (document.body) {
            document.body.dataset.awaResizeBound = '1';
        }

        window.addEventListener('resize', function () {
            if (AWA_CONFIG._suppressResizeRefresh) return;
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (isHome5LikePage()) {
                    fixAyoModuleAlignment();
                }
                refreshHomeTabCarousels();
                initFooterAccordion();
                initCategoryFilterToggle();
                /* R12-17: fixOwlNavA11y removido — carousels não mudam em resize */
                applyHasFallback(); /* R11-16: recalcula em resize */
                log('Resize handler executed');
            }, AWA_CONFIG.timings.resizeDebounce);
        });
        log('Resize listener initialized');
    }

    // ===========================================
    // LAZY IMAGE FADE-IN (complementa CSS)
    // Marca imagens lazy como .awa-loaded quando carregam
    // para evitar flash de opacity:0 em imgs cacheadas
    // ===========================================
    function initLazyImageFade(roots) {
        /* R10-14: Accept roots from MutationObserver to avoid full-document scan */
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;
            root.querySelectorAll('img[loading="lazy"]:not(.awa-loaded):not(.awa-load-error)').forEach(function (img) {
                if (img.complete) {
                    /* R9-11: Only mark loaded if image actually decoded */
                    if (img.naturalWidth > 0) {
                        img.classList.add('awa-loaded');
                    } else {
                        img.classList.add('awa-load-error');
                    }
                } else {
                    img.addEventListener('load', function () {
                        this.classList.add('awa-loaded');
                    }, { once: true });
                    img.addEventListener('error', function () {
                        this.classList.add('awa-load-error');
                        this.classList.add('awa-loaded'); /* still show via fallback opacity */
                    }, { once: true });
                }
            });
        });
    }
    // ===========================================
    // R16-09: FORM VALIDATION A11Y
    // Adiciona role="alert" para anúncio em screen readers
    // ===========================================
    function fixFormValidationA11y(roots) {
        var searchRoots = roots && roots.length
            ? roots
            : [getPageWrapper()];

        var errorIdCounter = 0;

        function ensureErrorId(el) {
            if (!el.id) {
                errorIdCounter += 1;
                el.id = 'awa-form-error-' + errorIdCounter;
            }

            return el.id;
        }

        function appendDescribedBy(target, errorId) {
            var existing = (target.getAttribute('aria-describedby') || '').trim();
            var tokens = existing ? existing.split(/\s+/) : [];

            if (tokens.indexOf(errorId) === -1) {
                tokens.push(errorId);
                target.setAttribute('aria-describedby', tokens.join(' ').trim());
            }
        }

        function ensureValidationLiveRegion(form) {
            var liveRegion = form.querySelector('.awa-form-validation-live');

            if (!liveRegion) {
                liveRegion = document.createElement('div');
                liveRegion.className = 'awa-form-validation-live';
                liveRegion.setAttribute('role', 'status');
                liveRegion.setAttribute('aria-live', 'polite');
                liveRegion.setAttribute('aria-atomic', 'true');
                applyVisuallyHiddenStyles(liveRegion);
                form.insertBefore(liveRegion, form.firstChild);
            }

            return liveRegion;
        }

        searchRoots.forEach(function (root) {
            if (!root || !root.querySelectorAll) return;

            root.querySelectorAll('.field.required input, .field.required select, .field.required textarea').forEach(function (el) {
                if (!el.getAttribute('aria-required')) {
                    el.setAttribute('aria-required', 'true');
                }
            });

            root.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (el) {
                if (!el.getAttribute('aria-required')) {
                    el.setAttribute('aria-required', 'true');
                }
            });

            root.querySelectorAll('div.mage-error, label.mage-error, .mage-error').forEach(function (el) {
                el.setAttribute('role', 'alert');

                var errorId = ensureErrorId(el);
                var targetId = el.getAttribute('for');

                if (targetId) {
                    var target = document.getElementById(targetId);
                    if (target) {
                        target.setAttribute('aria-invalid', 'true');
                        appendDescribedBy(target, errorId);
                    }
                }
            });

            root.querySelectorAll('input.mage-error, select.mage-error, textarea.mage-error').forEach(function (el) {
                el.setAttribute('aria-invalid', 'true');
            });

            root.querySelectorAll('form').forEach(function (form) {
                var liveRegion = ensureValidationLiveRegion(form);
                var errors = form.querySelectorAll('.mage-error');
                var totalErrors = errors.length;
                var lastCount = form.getAttribute('data-awa-error-count') || '0';

                if (String(totalErrors) !== lastCount) {
                    if (totalErrors > 0) {
                        liveRegion.textContent = totalErrors === 1
                            ? 'Existe 1 erro no formulário. Revise o campo destacado.'
                            : 'Existem ' + totalErrors + ' erros no formulário. Revise os campos destacados.';
                    } else {
                        liveRegion.textContent = '';
                    }

                    form.setAttribute('data-awa-error-count', String(totalErrors));
                }
            });
        });

        if (document.body && document.body.dataset.awaFormA11ySubmitBound !== '1') {
            document.body.dataset.awaFormA11ySubmitBound = '1';

            document.addEventListener('submit', function (event) {
                var form = event.target;
                if (!form || form.tagName !== 'FORM') {
                    return;
                }

                setTimeout(function () {
                    var firstInvalid = form.querySelector('input.mage-error, select.mage-error, textarea.mage-error, [aria-invalid="true"]');

                    if (firstInvalid && typeof firstInvalid.focus === 'function') {
                        try {
                            firstInvalid.focus({ preventScroll: true });
                        } catch (e) {
                            firstInvalid.focus();
                        }

                        if (typeof firstInvalid.scrollIntoView === 'function') {
                            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                }, 120);
            }, true);
        }
    }

    // ===========================================
    // R16-08: NEWSLETTER POPUP A11Y
    // Dialog role + Escape key + focus on open
    // ===========================================
    function initNewsletterPopupA11y() {
        var popup = document.querySelector('.newsletterpopup');
        if (!popup || popup.dataset.awaPopupA11y) return;
        popup.dataset.awaPopupA11y = '1';

        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-modal', 'true');
        popup.setAttribute('aria-label', 'Newsletter');

        /* Focus primeiro campo quando popup visível */
        var popupObserver = new MutationObserver(function () {
            var isVisible = popup.offsetParent !== null &&
                getComputedStyle(popup).display !== 'none' &&
                getComputedStyle(popup).visibility !== 'hidden';
            if (isVisible) {
                var firstInput = popup.querySelector('input[type="email"], input, button, a[href]');
                if (firstInput) setTimeout(function () { firstInput.focus(); }, AWA_CONFIG.timings.popupFocusDelay);
            }
        });
        popupObserver.observe(popup, { attributes: true, attributeFilter: ['style', 'class'] });

        /* Escape para fechar */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && popup.offsetParent !== null) {
                var closeBtn = popup.querySelector('.close-popup, .btn-close, .action-close, [data-dismiss]');
                if (closeBtn) closeBtn.click();
            }
        });

        log('Newsletter popup a11y initialized');
    }

    // ===========================================
    // R16-07: Helper para execução segura (error boundary)
    // ===========================================
    function safeRun(fn, name) {
        var label = name || fn.name || 'anonymous';
        var t0;
        if (AWA_CONFIG.debug && typeof performance !== 'undefined' && performance.mark) {
            t0 = performance.now();
        }
        try {
            fn();
        } catch (e) {
            var message = e && e.message ? e.message : String(e);
            log('Error in ' + label + ': ' + message);
            if (AWA_CONFIG.debug && e && e.stack) {
                log('Stack in ' + label + ': ' + e.stack);
            }
        }
        if (t0 !== undefined) {
            var elapsed = (performance.now() - t0).toFixed(2);
            if (parseFloat(elapsed) > 5) { /* log only slow fns (>5ms) */
                log('[perf] ' + label + ': ' + elapsed + 'ms');
            }
        }
    }

    // ===========================================
    // INICIALIZAÇÃO
    // ===========================================
    function init() {
        log('Initializing AWA Master Fix v2...');
        var isHomeLike = isHome5LikePage();

        // Core fixes — R16-07: safeRun impede cascata de falhas
        safeRun(fixPrices, 'fixPrices');
        safeRun(translateTexts, 'translateTexts');
        safeRun(hideMagentoCode, 'hideMagentoCode');
        safeRun(normalizeLegacyOfferLinks, 'normalizeLegacyOfferLinks');
        safeRun(sanitizeCorruptedInstitutionalHeadings, 'sanitizeCorruptedInstitutionalHeadings');
        safeRun(addInputMasks, 'addInputMasks');

        // UI elements
        safeRun(initBackToTop, 'initBackToTop');
        safeRun(initStickyHeaderSpacer, 'initStickyHeaderSpacer');
        safeRun(initMobileNavClose, 'initMobileNavClose');

        // Accessibility
        safeRun(deduplicateHorizontalNav, 'deduplicateHorizontalNav');
        if (isHomeLike) {
            safeRun(deduplicateHomeSections, 'deduplicateHomeSections'); /* AF-04 */
        }
        safeRun(fixVerticalMenuToggles, 'fixVerticalMenuToggles');
        safeRun(fixSocialShareAlts, 'fixSocialShareAlts');
        safeRun(hideEmptyImages, 'hideEmptyImages');
        safeRun(initProductImageCacheFallback, 'initProductImageCacheFallback');
        safeRun(fixNavToggleLabel, 'fixNavToggleLabel');
        safeRun(fixReviewCount, 'fixReviewCount');
        safeRun(addSkipToMain, 'addSkipToMain');
        safeRun(fixSliderAltText, 'fixSliderAltText');
        safeRun(fixMinicartA11y, 'fixMinicartA11y');
        safeRun(fixFormValidationA11y, 'fixFormValidationA11y'); /* R16-09 */

        // Layout alignment
        if (isHomeLike) {
            safeRun(fixAyoModuleAlignment, 'fixAyoModuleAlignment');
        }
        safeRun(sanitizeEscapedProductImageCssText, 'sanitizeEscapedProductImageCssText');
        if (isHomeLike) {
            safeRun(bindTabCarouselRefresh, 'bindTabCarouselRefresh');
        }
        safeRun(applyHasFallback, 'applyHasFallback');
        safeRun(fixOwlNavA11y, 'fixOwlNavA11y');
        safeRun(initLazyImageFade, 'initLazyImageFade');

        // R16-08: Defer non-critical UI inits to idle time
        var deferInit = typeof requestIdleCallback === 'function'
            ? requestIdleCallback
            : function (fn) { return setTimeout(fn, 200); };
        deferInit(function () {
            safeRun(initFooterAccordion, 'initFooterAccordion');
            safeRun(initCategoryFilterToggle, 'initCategoryFilterToggle');
            safeRun(fixSearchAutocompleteA11y, 'fixSearchAutocompleteA11y');
            safeRun(initNewsletterPopupA11y, 'initNewsletterPopupA11y');
            safeRun(initResizeListener, 'initResizeListener');
        });

        if (isHomeLike) {
            // OWL retry inteligente: suporta OWL v2 (.owl-stage) e v1 (.owl-wrapper)
            (function waitForOwlAndRefresh(retries) {
                if (retries <= 0) return;
                var hasOwlV2 = document.querySelector(
                    '.tab_product .owl-carousel .owl-stage, ' +
                    '.categorytab-container .owl-carousel .owl-stage, ' +
                    '.list-tab-product .owl-carousel .owl-stage'
                );
                var hasOwlV1 = document.querySelector(
                    '.tab_product .owl-carousel .owl-wrapper .owl-item, ' +
                    '.categorytab-container .owl-carousel .owl-wrapper .owl-item, ' +
                    '.list-tab-product .owl-carousel .owl-wrapper .owl-item'
                );

                if (hasOwlV2 || hasOwlV1) {
                    refreshHomeTabCarousels();
                    sanitizeEscapedProductImageCssText();
                } else {
                    setTimeout(function () { waitForOwlAndRefresh(retries - 1); }, AWA_CONFIG.timings.owlRetryInterval);
                }
            })(5);

            // Extra pass after full load to recalculate widths in initially hidden tabs.
            window.addEventListener('load', function () {
                safeRun(refreshHomeTabCarousels, 'refreshHomeTabCarousels.onload');
            }, { once: true });
        }

        log('AWA Master Fix v2 loaded successfully!');
    }

    function scheduleInitialInit() {
        /* Autopilot 2026-07-02: awa-master-fix.js e carregado com `defer`.
         * Em paginas de catalogo, chamar init() diretamente durante o estado
         * `interactive` bloqueava o DOMContentLoaded com varreduras completas
         * de texto/imagens/produtos. Um timer curto libera o evento critico
         * primeiro e mantem os fixes de runtime no tick seguinte. */
        if (typeof requestIdleCallback === 'function') {
            requestIdleCallback(init, { timeout: 800 });
            return;
        }
        setTimeout(init, 0);
    }

    function isCheckoutFlowPageForMasterFix() {
        var body = document.body;
        return !!(body && (
            body.classList.contains('checkout-cart-index') ||
            body.classList.contains('checkout-index-index') ||
            body.classList.contains('rokanthemes-onepagecheckout') ||
            body.classList.contains('onepagecheckout-index-index')
        ));
    }

    if (isCheckoutFlowPageForMasterFix()) {
        /* 2026-07-05 — fix de travamento crítico no carrinho/checkout.
         * Evidência runtime (probe Playwright A/B):
         * - baseline: evaluate timeout em ~2s e página fechando logo em seguida;
         * - bloqueando apenas js/awa-master-fix.js: readyState=complete em ~1.7s;
         * - bloqueando apenas theme-heavy.min.js: travamento permanece.
         * Conclusão: o custo do awa-master-fix.js nessas rotas está saturando
         * a main thread. Nelas, este bundle não é essencial para operação do
         * carrinho/checkout; portanto fazemos bypass completo do init/observers.
         */
        return;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleInitialInit, { once: true });
    } else {
        scheduleInitialInit();
    }

    function shouldSkipGlobalMutationObserver() {
        var body = document.body;
        return !!(body && (
            body.classList.contains('catalog-category-view') ||
            body.classList.contains('catalogsearch-result-index') ||
            body.classList.contains('catalog-product-view') ||
            isHome5LikePage()
        ));
    }

    var shouldSkipMutationObserver = shouldSkipGlobalMutationObserver();
    if (shouldSkipMutationObserver) {
        /* Autopilot 2026-07-02: PLP/PDP/busca ja executam o init inicial.
         * O observer global de AJAX reprocessava mutacoes de outros scripts
         * (carrosseis, filtros, autocomplete, header) e mantinha Chromium/Firefox
         * ocupados por dezenas de segundos apos DOMContentLoaded. Nessas rotas,
         * manter apenas o init inicial evita o congelamento sem mexer em checkout,
         * home ou conta. */
        return;
    }

    // MutationObserver para conteúdo AJAX
    // Escopo: .page-wrapper (não document.body) com debounce 350ms
    // Coleta addedNodes para processar apenas nós novos quando possível
    var debounceTimer = null;
    var pendingNodes = [];
    var pendingNodesSet = typeof Set === 'function' ? new Set() : null;
    var processFullPass = false;
    var MAX_PENDING_MUTATION_NODES = 120;
    var scheduleCallback = typeof requestIdleCallback === 'function'
        ? requestIdleCallback
        : function (fn) { return setTimeout(fn, 350); };
    var cancelScheduledCallback = typeof cancelIdleCallback === 'function'
        ? cancelIdleCallback
        : function (id) { clearTimeout(id); };
    var hasDeferredMutationPass = false;
    var visibilityDeferredScheduled = false;
    var scheduledMutationWorkId = null;
    var mutationObserverStopped = false;
    var mutationPassCount = 0;
    var mutationObserverStartTs = Date.now();
    var MAX_MUTATION_PASSES = isHome5LikePage() ? 260 : 520;
    /* Patch 2026-07-02 (child theme override): páginas fora da home (PLP/PDP/etc.)
       ficavam SEM limite de tempo de execução para o watchdog de MutationObserver
       (valor 0 = desativado), protegidas apenas pela contagem de passes (520).
       Em páginas com filtros/listas dinâmicas (ex.: categoria com layered nav,
       infinite scroll, tabs de produto) uma sequência de mutações em cadeia pode
       levar bem mais tempo que o esperado para esgotar 520 passes, mantendo o
       observer + requestIdleCallback trabalhando de forma prolongada e
       contribuindo para páginas "travando" ao interagir. Alinhamos com o mesmo
       padrão defensivo já usado nas páginas home (corte por tempo, não só por
       contagem), com um teto um pouco menor por serem páginas mais simples. */
    var MUTATION_OBSERVER_MAX_RUNTIME_MS = isHome5LikePage() ? 180000 : 120000;

    function queueMutationWork(fn) {
        if (document.hidden) {
            hasDeferredMutationPass = true;
            visibilityDeferredScheduled = false;
            return;
        }

        if (scheduledMutationWorkId !== null) {
            cancelScheduledCallback(scheduledMutationWorkId);
            scheduledMutationWorkId = null;
        }

        scheduledMutationWorkId = scheduleCallback(function () {
            scheduledMutationWorkId = null;

            if (mutationObserverStopped) {
                return;
            }

            mutationPassCount += 1;
            if (mutationPassCount > MAX_MUTATION_PASSES) {
                stopMutationObserver('max-passes');
                return;
            }

            if (MUTATION_OBSERVER_MAX_RUNTIME_MS > 0 && (Date.now() - mutationObserverStartTs) > MUTATION_OBSERVER_MAX_RUNTIME_MS) {
                stopMutationObserver('max-runtime');
                return;
            }

            fn();
        });
    }

    function cancelMutationWork() {
        if (scheduledMutationWorkId !== null) {
            cancelScheduledCallback(scheduledMutationWorkId);
            scheduledMutationWorkId = null;
        }
    }

    function movePendingMutationsToDeferredState() {
        hasDeferredMutationPass = true;
        pendingNodes = [];
        if (pendingNodesSet) {
            pendingNodesSet.clear();
        }
        processFullPass = false;
    }

    function cleanupOnDocumentHidden() {
        clearTimeout(debounceTimer);
        debounceTimer = null;
        cancelMutationWork();
        visibilityDeferredScheduled = false;
    }

    function stopMutationObserver(reason) {
        if (mutationObserverStopped) {
            return;
        }

        mutationObserverStopped = true;
        cleanupOnDocumentHidden();
        pendingNodes = [];
        if (pendingNodesSet) {
            pendingNodesSet.clear();
        }
        processFullPass = false;

        try {
            observer.disconnect();
        } catch (e) {
            /* noop */
        }

        log('Mutation observer stopped: ' + reason);
    }

    /* R20-01: Gating seletivo para mutações — reduz trabalho em nós irrelevantes */
    var MUTATION_RELEVANT_SELECTOR = [
        '.price',
        '.product-item',
        '.item-product',
        '.reviews-actions',
        'img',
        '.owl-carousel',
        '.homebuilder-section',
        '.tab_product',
        '.categorytab-container',
        '.list-tab-product',
        '.verticalmenu .open-children-toggle',
        '.nav-sections',
        '.search-autocomplete',
        'form',
        '.field.required',
        '.mage-error',
        '.velaFooterTitle',
        '.sidebar-main',
        '.toolbar-products',
        '.widget',
        '.block-cms-link',
        '.cms-page-view',
        '.block-static-block',
        'a[href]',
        'input[placeholder]',
        'textarea[placeholder]'
    ].join(', ');

    function nodeMatchesRelevantSelector(node) {
        if (!node || node.nodeType !== 1) return false;

        try {
            if (node.matches && node.matches(MUTATION_RELEVANT_SELECTOR)) {
                return true;
            }

            if (node.querySelector && node.querySelector(MUTATION_RELEVANT_SELECTOR)) {
                return true;
            }
        } catch (e) {
            /* fallback seguro: em caso improvável de erro de seletor, evita full-scan */
            return false;
        }

        return false;
    }

    function nodeOrDescMatches(node, selector) {
        if (!node || node.nodeType !== 1) return false;
        try {
            return (node.matches && node.matches(selector))
                || (node.querySelector && !!node.querySelector(selector));
        } catch (e) {
            return false;
        }
    }

    function enqueuePendingNode(node) {
        if (!node || node.nodeType !== 1) return false;

        if (processFullPass) {
            return false;
        }

        var tag = node.tagName;
        if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT' || tag === 'TEMPLATE') {
            return false;
        }

        if (!nodeMatchesRelevantSelector(node)) {
            return false;
        }

        if (pendingNodesSet) {
            if (pendingNodesSet.has(node)) {
                return false;
            }
        } else if (pendingNodes.indexOf(node) !== -1) {
            return false;
        }

        if (pendingNodes.length >= MAX_PENDING_MUTATION_NODES) {
            processFullPass = true;
            pendingNodes = [];
            if (pendingNodesSet) {
                pendingNodesSet.clear();
            }
            return true;
        }

        pendingNodes.push(node);
        if (pendingNodesSet) {
            pendingNodesSet.add(node);
        }
        return true;
    }

    var observer = new MutationObserver(function (mutations) {
        var hasNewNodes = processFullPass;
        for (var i = 0; i < mutations.length; i++) {
            if (mutations[i].addedNodes.length > 0) {
                for (var j = 0; j < mutations[i].addedNodes.length; j++) {
                    if (enqueuePendingNode(mutations[i].addedNodes[j])) {
                        hasNewNodes = true;
                    }
                }
            }
        }

        if (document.hidden) {
            if (hasNewNodes) {
                cleanupOnDocumentHidden();
                visibilityDeferredScheduled = false;
                movePendingMutationsToDeferredState();
            }
            return;
        }

        if (hasNewNodes) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                var nodes = processFullPass
                    ? [getPageWrapper()]
                    : pendingNodes.slice();

                pendingNodes = [];
                if (pendingNodesSet) {
                    pendingNodesSet.clear();
                }
                processFullPass = false;

                queueMutationWork(function () {
                    if (document.hidden) {
                        hasDeferredMutationPass = true;
                        return;
                    }

                    var pageWrapper = getPageWrapper();
                    var isFullPass = nodes.length === 1 && nodes[0] === pageWrapper;
                    var isHomeLike = isHome5LikePage();

                    var hasProducts = false;
                    var hasImages = false;
                    var hasOwl = false;
                    var hasModules = false;
                    var hasSearch = false;
                    var hasFooterOrSidebar = false;
                    var hasForms = false;
                    var hasLinks = false;
                    var hasNavUi = false;

                    if (isFullPass) {
                        hasProducts = true;
                        hasImages = true;
                        hasOwl = true;
                        hasModules = true;
                        hasSearch = true;
                        hasFooterOrSidebar = true;
                        hasForms = true;
                        hasLinks = true;
                        hasNavUi = true;
                    } else {
                        for (var k = 0; k < nodes.length; k++) {
                            var n = nodes[k];
                            if (!hasProducts && nodeOrDescMatches(n, '.price, .product-item, .item-product, .reviews-actions')) hasProducts = true;
                            if (!hasImages && nodeOrDescMatches(n, 'img')) hasImages = true;
                            if (!hasOwl && nodeOrDescMatches(n, '.owl-carousel')) hasOwl = true;
                            if (!hasModules && nodeOrDescMatches(n, '.homebuilder-section, .tab_product, .categorytab-container, .list-tab-product')) hasModules = true;
                            if (!hasSearch && nodeOrDescMatches(n, '#search, #search_mini_form, .search-autocomplete')) hasSearch = true;
                            if (!hasForms && nodeOrDescMatches(n, 'form, .field.required, .mage-error, .message-error, .message.notice')) hasForms = true;
                            if (!hasLinks && nodeOrDescMatches(n, 'a[href], .navigation, .verticalmenu, .nav-sections')) hasLinks = true;
                            if (!hasNavUi && nodeOrDescMatches(n, '.nav-toggle, .action.nav-toggle, .nav-sections, .navigation, .verticalmenu')) hasNavUi = true;
                            if (!hasFooterOrSidebar && nodeOrDescMatches(n, '.footer, .page-footer, .velaFooter, .sidebar-main, .toolbar-products, .filter-options, .velaFooterTitle')) {
                                hasFooterOrSidebar = true;
                            }
                            if (hasProducts && hasImages && hasOwl && hasModules && hasSearch && hasFooterOrSidebar && hasForms && hasLinks && hasNavUi) break;
                        }
                    }

                    /* R17-10: use safeRun() instead of repetitive try-catch */
                    if (isFullPass || hasLinks || hasSearch || hasProducts || hasFooterOrSidebar) {
                        safeRun(function () { translateTexts(nodes); }, 'translateTexts');
                        safeRun(function () { normalizeLegacyOfferLinks(nodes); }, 'normalizeLegacyOfferLinks');
                        safeRun(function () { sanitizeCorruptedInstitutionalHeadings(nodes); }, 'sanitizeCorruptedInstitutionalHeadings');
                        safeRun(function () { hideMagentoCode(nodes); }, 'hideMagentoCode');
                    }

                    if (isFullPass || hasProducts) {
                        safeRun(function () { fixPrices(nodes); }, 'fixPrices');
                    }

                    if (isFullPass || hasForms || hasSearch) {
                        safeRun(function () { addInputMasks(nodes); }, 'addInputMasks');
                    }

                    if (hasProducts) {
                        safeRun(function () { fixReviewCount(nodes); }, 'fixReviewCount');
                        safeRun(function () { sanitizeEscapedProductImageCssText(nodes); }, 'sanitizeEscapedProductImageCssText');
                    }
                    if (hasImages) {
                        safeRun(function () { hideEmptyImages(nodes); }, 'hideEmptyImages');
                        safeRun(function () { fixSocialShareAlts(nodes); }, 'fixSocialShareAlts');
                        safeRun(function () { initLazyImageFade(nodes); }, 'initLazyImageFade');
                        safeRun(function () { fixSliderAltText(nodes); }, 'fixSliderAltText');
                    }
                    if (hasOwl) {
                        if (isHomeLike) {
                            safeRun(bindTabCarouselRefresh, 'bindTabCarouselRefresh');
                            safeRun(function () { refreshHomeTabCarousels(nodes); }, 'refreshHomeTabCarousels');
                        }
                        safeRun(function () { fixOwlNavA11y(nodes); }, 'fixOwlNavA11y');
                    }
                    if (hasSearch) {
                        safeRun(fixSearchAutocompleteA11y, 'fixSearchAutocompleteA11y');
                    }
                    if (isFullPass || hasLinks || hasModules) {
                        safeRun(function () { fixVerticalMenuToggles(nodes); }, 'fixVerticalMenuToggles');
                        if (isFullPass || hasNavUi) {
                            safeRun(fixNavToggleLabel, 'fixNavToggleLabel');
                        }
                    }
                    if (hasModules && isHomeLike) {
                        safeRun(fixAyoModuleAlignment, 'fixAyoModuleAlignment');
                    }

                    if (isFullPass || hasFooterOrSidebar || hasModules) {
                        safeRun(initFooterAccordion, 'initFooterAccordion');
                        safeRun(initCategoryFilterToggle, 'initCategoryFilterToggle');
                    }

                    if (isFullPass || hasForms || hasSearch) {
                        safeRun(function () { fixFormValidationA11y(nodes); }, 'fixFormValidationA11y');
                    }
                });
            }, AWA_CONFIG.timings.mutationDebounce);
        }
    });

    var observeTargets = [];

    function pushObserveTarget(node) {
        if (!node || !node.nodeType) {
            return;
        }
        if (observeTargets.indexOf(node) === -1) {
            observeTargets.push(node);
        }
    }

    if (isHome5LikePage()) {
        [
            '.search-autocomplete',
            '.minicart-wrapper',
            '.nav-sections',
            '.wrapper_slider',
            '.list-tab-product',
            '.categorytab-container',
            '.homebuilder-section',
            '.products.wrapper',
            '.page-footer'
        ].forEach(function (selector) {
            var node = document.querySelector(selector);
            if (node) {
                pushObserveTarget(node);
            }
        });
    }

    if (!observeTargets.length) {
        var observeTarget = getPageWrapper();
        if (!observeTarget || !observeTarget.nodeType) {
            observeTarget = document.body || document.documentElement;
        }
        pushObserveTarget(observeTarget);
    }

    observeTargets.forEach(function (target) {
        observer.observe(target, {
            childList: true,
            subtree: true
        });
    });

    if (MUTATION_OBSERVER_MAX_RUNTIME_MS > 0) {
        setTimeout(function () {
            stopMutationObserver('max-runtime-timer');
        }, MUTATION_OBSERVER_MAX_RUNTIME_MS);
    }

    if (document && document.addEventListener) {
        document.addEventListener('visibilitychange', function () {
            if (mutationObserverStopped) {
                return;
            }

            if (document.hidden) {
                if (visibilityDeferredScheduled) {
                    hasDeferredMutationPass = true;
                }
                cleanupOnDocumentHidden();
                if (pendingNodes.length > 0 || processFullPass) {
                    movePendingMutationsToDeferredState();
                }
                return;
            }

            if (!hasDeferredMutationPass) {
                return;
            }

            if (visibilityDeferredScheduled) {
                return;
            }

            visibilityDeferredScheduled = true;

            hasDeferredMutationPass = false;

            queueMutationWork(function () {
                visibilityDeferredScheduled = false;

                if (document.hidden) {
                    hasDeferredMutationPass = true;
                    return;
                }

                var deferredNodes = [getPageWrapper()];
                var isHomeLike = isHome5LikePage();

                safeRun(function () { translateTexts(deferredNodes); }, 'translateTexts.visibilityDeferred');
                safeRun(function () { normalizeLegacyOfferLinks(deferredNodes); }, 'normalizeLegacyOfferLinks.visibilityDeferred');
                safeRun(function () { sanitizeCorruptedInstitutionalHeadings(deferredNodes); }, 'sanitizeCorruptedInstitutionalHeadings.visibilityDeferred');
                safeRun(function () { hideMagentoCode(deferredNodes); }, 'hideMagentoCode.visibilityDeferred');
                safeRun(function () { fixPrices(deferredNodes); }, 'fixPrices.visibilityDeferred');
                safeRun(function () { addInputMasks(deferredNodes); }, 'addInputMasks.visibilityDeferred');
                safeRun(function () { fixReviewCount(deferredNodes); }, 'fixReviewCount.visibilityDeferred');
                safeRun(function () { sanitizeEscapedProductImageCssText(deferredNodes); }, 'sanitizeEscapedProductImageCssText.visibilityDeferred');
                safeRun(function () { hideEmptyImages(deferredNodes); }, 'hideEmptyImages.visibilityDeferred');
                safeRun(function () { fixSocialShareAlts(deferredNodes); }, 'fixSocialShareAlts.visibilityDeferred');
                safeRun(function () { initLazyImageFade(deferredNodes); }, 'initLazyImageFade.visibilityDeferred');
                safeRun(function () { fixSliderAltText(deferredNodes); }, 'fixSliderAltText.visibilityDeferred');
                safeRun(function () { fixVerticalMenuToggles(deferredNodes); }, 'fixVerticalMenuToggles.visibilityDeferred');
                safeRun(fixNavToggleLabel, 'fixNavToggleLabel.visibilityDeferred');
                safeRun(fixSearchAutocompleteA11y, 'fixSearchAutocompleteA11y.visibilityDeferred');
                safeRun(initFooterAccordion, 'initFooterAccordion.visibilityDeferred');
                safeRun(initCategoryFilterToggle, 'initCategoryFilterToggle.visibilityDeferred');
                safeRun(function () { fixFormValidationA11y(deferredNodes); }, 'fixFormValidationA11y.visibilityDeferred');

                if (isHomeLike) {
                    safeRun(fixAyoModuleAlignment, 'fixAyoModuleAlignment.visibilityDeferred');
                    safeRun(bindTabCarouselRefresh, 'bindTabCarouselRefresh.visibilityDeferred');
                    safeRun(function () { refreshHomeTabCarousels(deferredNodes); }, 'refreshHomeTabCarousels.visibilityDeferred');
                }

                safeRun(function () { fixOwlNavA11y(deferredNodes); }, 'fixOwlNavA11y.visibilityDeferred');
            });
        });
    }

})();
