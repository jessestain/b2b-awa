/**
 * awa-ux-enhancements — Melhorias UX globais v2 (2026-05).
 *
 *  01. fixHashLinks           — corrige âncoras #hash que rolam para cima
 *  02. fixSocialAria          — labels WCAG em ícones de redes sociais
 *  03. injectWhatsApp         — widget flutuante WhatsApp
 *  04. initActiveFiltersBadge — badge de filtros ativos na PLP
 *  05. fixBreadcrumb          — microdata schema no breadcrumb
 *  06. fixFooterEmail         — previne truncamento de email
 *  07. contentVisibilityFix   — desativa content-visibility nos carrosséis
 *  08. initScrollProgress     — barra de progresso de scroll
 *  09. initImageLazyLoad      — lazy loading para imagens off-screen
 *  10. initCartFeedback       — feedback visual ao adicionar ao carrinho
 *  11. fixMobileTouch         — corrige touch targets WCAG no mobile
 *  12. initSearchEnhance      — limpeza do campo de busca
 */
define(['jquery'], function ($) {
    'use strict';

    /* ============================================================
       01. fixHashLinks
    ============================================================ */
    function fixHashLinks() {
        try {
            document.querySelectorAll('a[href="#"]').forEach(function (el) {
                el.addEventListener('click', function (e) { e.preventDefault(); });
            });
        } catch (e) {}
    }

    /* ============================================================
       02. fixSocialAria
    ============================================================ */
    function fixSocialAria() {
        try {
            let socialMap = {
                'facebook':  'Seguir AWA Motos no Facebook',
                'instagram': 'Seguir AWA Motos no Instagram',
                'youtube':   'Canal AWA Motos no YouTube',
                'whatsapp':  'Contato pelo WhatsApp',
                'twitter':   'Seguir AWA Motos no Twitter',
                'linkedin':  'AWA Motos no LinkedIn'
            };
            document.querySelectorAll('.social-links a, .social a, [class*="social"] a').forEach(function (el) {
                if (el.getAttribute('aria-label')) return;
                let href = (el.href || '').toLowerCase();
                let cls  = (el.className || '').toLowerCase();
                let label = '';
                Object.keys(socialMap).forEach(function (key) {
                    if ((href + cls).indexOf(key) !== -1) label = socialMap[key];
                });
                if (label) {
                    el.setAttribute('aria-label', label);
                    el.setAttribute('title', label);
                    let icon = el.querySelector('em, i, svg, [class*="icon"]');
                    if (icon) icon.setAttribute('aria-hidden', 'true');
                }
            });
        } catch (e) {}
    }

    /* ============================================================
       03. injectWhatsApp
    ============================================================ */
    function injectWhatsApp() {
        try {
            let WA_NUMBER = '5516997367588';
            let WA_MSG    = encodeURIComponent('Olá! Vim pelo site awamotos.com e gostaria de saber mais.');
            let WA_URL    = 'https://wa.me/' + WA_NUMBER + '?text=' + WA_MSG;

            if (document.getElementById('awa-whatsapp-fab')) return;

            let fab = document.createElement('a');
            fab.id         = 'awa-whatsapp-fab';
            fab.href       = WA_URL;
            fab.target     = '_blank';
            fab.rel        = 'noopener noreferrer';
            fab.setAttribute('aria-label', 'Falar com AWA Motos pelo WhatsApp');
            fab.setAttribute('title', 'WhatsApp AWA Motos');
            fab.innerHTML  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="#fff" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';

            let style = document.createElement('style');
            style.id   = 'awa-wa-fab-style';
            style.textContent = [
                '#awa-whatsapp-fab{position:fixed;bottom:80px;right:20px;z-index:9998;width:56px;height:56px;',
                'background:#25d366;border-radius:50%;display:flex;align-items:center;justify-content:center;',
                'box-shadow:0 4px 12px rgba(0,0,0,.3);transition:transform .2s,box-shadow .2s;text-decoration:none}',
                '#awa-whatsapp-fab:hover{transform:scale(1.1);box-shadow:0 6px 20px rgba(0,0,0,.4)}',
                '#awa-whatsapp-fab svg{width:32px;height:32px}',
                '@media(max-width:767px){#awa-whatsapp-fab{bottom:64px;right:12px;width:48px;height:48px}',
                '#awa-whatsapp-fab svg{width:26px;height:26px}}'
            ].join('');
            document.head.appendChild(style);
            document.body.appendChild(fab);
        } catch (e) {}
    }

    /* ============================================================
       05. initActiveFiltersBadge
    ============================================================ */
    function initActiveFiltersBadge() {
        try {
            if (!document.body.classList.contains('catalog-category-view') &&
                !document.body.classList.contains('catalogsearch-result-index')) return;

            function updateBadge() {
                let count  = document.querySelectorAll('.filter-current .item, .active-filter-item').length;
                let btn    = document.querySelector('.filter-title strong, .filter-title button, [data-role="filter-button"]');
                if (!btn) return;

                let badge = btn.querySelector('.awa-filter-badge');
                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'awa-filter-badge';
                        badge.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;background:#b73337;color:#fff;border-radius:50%;font-size:11px;font-weight:700;margin-left:6px;vertical-align:middle;';
                        btn.appendChild(badge);
                    }
                    badge.textContent = count;
                    badge.setAttribute('aria-label', count + ' filtros ativos');
                } else if (badge) {
                    badge.remove();
                }
            }

            updateBadge();
            if (typeof MutationObserver !== 'undefined') {
                let filterEl = document.querySelector('.filter-current, .sidebar-filter');
                if (filterEl) new MutationObserver(updateBadge).observe(filterEl, { childList: true, subtree: true });
            }
        } catch (e) {}
    }

    /* ============================================================
       06. fixBreadcrumb — adiciona schema microdata ao breadcrumb
    ============================================================ */
    function fixBreadcrumb() {
        try {
            let bc = document.querySelector('.breadcrumbs, nav.breadcrumbs, [class*="breadcrumb"]');
            if (!bc || bc.hasAttribute('itemscope')) return;

            bc.setAttribute('itemscope', '');
            bc.setAttribute('itemtype', 'https://schema.org/BreadcrumbList');

            let items = bc.querySelectorAll('li, .item');
            items.forEach(function (item, idx) {
                item.setAttribute('itemprop', 'itemListElement');
                item.setAttribute('itemscope', '');
                item.setAttribute('itemtype', 'https://schema.org/ListItem');

                let link = item.querySelector('a');
                if (link) {
                    link.setAttribute('itemprop', 'item');
                    let nameMeta = document.createElement('meta');
                    nameMeta.setAttribute('itemprop', 'name');
                    nameMeta.setAttribute('content', link.textContent.trim());
                    link.appendChild(nameMeta);
                }

                let posMeta = document.createElement('meta');
                posMeta.setAttribute('itemprop', 'position');
                posMeta.setAttribute('content', idx + 1);
                item.appendChild(posMeta);
            });
        } catch (e) {}
    }

    /* ============================================================
       08. fixFooterEmail
    ============================================================ */
    function fixFooterEmail() {
        try {
            document.querySelectorAll('a[href^="mailto:"]').forEach(function (el) {
                el.style.wordBreak = 'break-all';
                el.style.overflowWrap = 'break-word';
                el.style.display = 'inline-block';
                el.style.maxWidth = '100%';
            });
        } catch (e) {}
    }

    /* ============================================================
       09. contentVisibilityFix
    ============================================================ */
    function contentVisibilityFix() {
        try {
            let selectors = [
                '.awa-carousel-section',
                '.awa-home-section',
                '.rokan-newproduct',
                '.rokan-bestseller',
                '.rokan-mostviewed',
                '.awa-home-products'
            ].join(', ');
            /* FIX 12/10/2026 (TBT — o batch de leitura/escrita canonico): o loop fazia
             * getComputedStyle(el) (LEITURA que forca recalc) e logo em seguida escrevia
             * el.style.* (ESCRITA que suja o DOM) => a proxima iteracao forcava OUTRO reflow.
             * Medido no perfil: 249ms de long task. Agora: le TODOS primeiro, escreve depois. */
            var alvos = [];
            document.querySelectorAll(selectors).forEach(function (el) {
                if (el.classList.contains('awa-home-section--below-fold')) {
                    return;
                }
                if (window.getComputedStyle(el).contentVisibility === 'auto') {
                    alvos.push(el);
                }
            });
            alvos.forEach(function (el) {
                el.style.contentVisibility = 'visible';
                el.style.containIntrinsicSize = 'unset';
            });
        } catch (e) {}
    }

    /* ============================================================
       10. initScrollProgress — desativado (ui-ux-pro-max: ruído visual)
    ============================================================ */
    function initScrollProgress() {
        return;
    }

    /* ============================================================
       11. initImageLazyLoad — lazy loading nativo para imagens
    ============================================================ */
    function initImageLazyLoad() {
        try {
            if (!('IntersectionObserver' in window)) return;

            let imgs = document.querySelectorAll('img:not([loading])');
            imgs.forEach(function (img) {
                let rect = img.getBoundingClientRect();
                if (rect.top > window.innerHeight) {
                    img.setAttribute('loading', 'lazy');
                }
            });
        } catch (e) {}
    }

    /* ============================================================
       12. initCartFeedback — animação ao adicionar produto
    ============================================================ */
    function initCartFeedback() {
        try {
            if (document.getElementById('awa-cart-feedback-style')) return;

            let style = document.createElement('style');
            style.id   = 'awa-cart-feedback-style';
            style.textContent = [
                '.awa-cart-added{animation:awa-cart-pop .4s ease-out}',
                '@keyframes awa-cart-pop{0%{transform:scale(1)}50%{transform:scale(1.12);background:#1a9e4a!important}100%{transform:scale(1)}}'
            ].join('');
            document.head.appendChild(style);

            document.addEventListener('click', function (e) {
                let btn = e.target.closest('[data-action="add-to-cart"], .action.tocart, [class*="add-to-cart"]');
                if (btn) {
                    btn.classList.add('awa-cart-added');
                    btn.addEventListener('animationend', function () {
                        btn.classList.remove('awa-cart-added');
                    }, { once: true });
                }
            });
        } catch (e) {}
    }

    /* ============================================================
       13. fixMobileTouch — touch targets WCAG (min 44x44)
    ============================================================ */
    function fixMobileTouch() {
        try {
            /* Não abortar em desktop: sorter/breadcrumb/footer falham ≥768 (Pixel-QA). */
            let existing = document.getElementById('awa-touch-style');
            if (existing) {
                existing.remove();
            }

            let style = document.createElement('style');
            style.id   = 'awa-touch-style';
            style.textContent = [
                '.nav-toggle,.action.showcart,.action.towishlist,',
                '.product-item-actions .action{min-width:44px!important;min-height:44px!important}',
                '.nav-toggle{display:flex!important;align-items:center!important;justify-content:center!important}',
                'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view) .page-wrapper .wrapper.grid.products-grid .item-product :is(.product-name>a.product-item-link,.product-item-name>a.product-item-link,a.product-item-link),',
                'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index,.catalog-product-view) .page-wrapper :is(.products-grid,.awa-pdp-related,.rx-pdp-crosssell,.block.related,.block.upsell) :is(.item-product,.product-item,li.item-product) :is(.product-name>a.product-item-link,.product-item-name>a.product-item-link,a.product-item-link){',
                'min-height:44px!important;height:auto!important;max-height:none!important;',
                'padding-top:4px!important;padding-bottom:4px!important;box-sizing:border-box!important}',
                'html body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body#html-body.catalog-product-view .page-wrapper :is(.rx-pdp-login-link,.awa-related-price-notice__login-link){',
                'display:inline-flex!important;align-items:center!important;min-height:44px!important;',
                'height:auto!important;padding-top:10px!important;padding-bottom:10px!important;',
                'line-height:1.2!important;box-sizing:border-box!important}',
                /* select nativo: height:auto + min-height perde o piso em Chrome — forçar 44px */
                'html body#html-body#html-body#html-body#html-body:is(.catalog-category-view,.catalogsearch-result-index) .page-wrapper .toolbar.toolbar-products :is(.sorter-options,select.limiter-options,#sorter,#limiter){',
                'min-height:44px!important;height:44px!important;max-height:none!important;',
                'box-sizing:border-box!important;padding-block:8px!important}',
                /* H-crumbs-compact: só ≥768px — mobile precisa alvo ≥36px (Onda 5F) */
                '@media (min-width:768px){',
                'html body#html-body#html-body#html-body#html-body :is(.breadcrumbs,.nav-breadcrumbs) a{',
                'display:inline-flex!important;align-items:center!important;min-height:0!important;min-block-size:0!important;',
                'height:auto!important;padding-block:2px!important;box-sizing:border-box!important}',
                '}',
                'html body#html-body#html-body#html-body#html-body .page-wrapper :is(.page_footer,.page-footer) :is(button.awa-footer-section__toggle,.awa-footer-section__toggle){',
                'min-height:44px!important;height:auto!important;max-height:none!important;',
                'padding-block:10px!important;box-sizing:border-box!important;display:flex!important;align-items:center!important}'
            ].join('');
            (document.body || document.documentElement).appendChild(style);

            function forceTouch44(nodes, props) {
                nodes.forEach(function (el) {
                    Object.keys(props).forEach(function (prop) {
                        el.style.setProperty(prop, props[prop], 'important');
                    });
                });
            }

            forceTouch44(document.querySelectorAll(
                '.wrapper.grid.products-grid .item-product .product-item-link,' +
                '.products-grid .item-product .product-item-link,' +
                '.awa-pdp-related .product-item-link,' +
                '.rx-pdp-crosssell .product-item-link'
            ), {
                'min-height': '44px',
                'height': 'auto',
                'max-height': 'none',
                'padding-top': '4px',
                'padding-bottom': '4px',
                'box-sizing': 'border-box'
            });

            // PDP only: keep long, model-specific recommendation names readable.
            // The touch-target pass writes inline !important styles, so apply the
            // matching three-line clamp in the same existing pass.
            if (document.body.classList.contains('catalog-product-view')) {
                forceTouch44(document.querySelectorAll('.awa-pdp-related .product-item-link'), {
                    '-webkit-line-clamp': '3'
                });
            }

            forceTouch44(document.querySelectorAll('.rx-pdp-login-link, .awa-related-price-notice__login-link'), {
                'display': 'inline-flex',
                'align-items': 'center',
                'min-height': '44px',
                'height': 'auto',
                'padding-top': '10px',
                'padding-bottom': '10px',
                'line-height': '1.2',
                'box-sizing': 'border-box'
            });

            forceTouch44(document.querySelectorAll(
                '.toolbar.toolbar-products #sorter,' +
                '.toolbar.toolbar-products select.sorter-options,' +
                '.toolbar.toolbar-products select.limiter-options,' +
                '.toolbar.toolbar-products #limiter'
            ), {
                'min-height': '44px',
                'height': '44px',
                'max-height': 'none',
                'box-sizing': 'border-box'
            });


            forceTouch44(document.querySelectorAll(
                '.page_footer .awa-footer-section__toggle, .page-footer .awa-footer-section__toggle'
            ), {
                'min-height': '44px',
                'height': 'auto',
                'max-height': 'none',
                'padding-block': '10px',
                'display': 'flex',
                'align-items': 'center',
                'box-sizing': 'border-box'
            });
        } catch (e) {}
    }

    /* ============================================================
       14. initSearchEnhance — botão limpar busca
    ============================================================ */
    function setSearchClearVisible(clearBtn, visible) {
        clearBtn.hidden = !visible;
        clearBtn.style.display = visible ? 'inline-flex' : 'none';
        clearBtn.setAttribute('aria-hidden', visible ? 'false' : 'true');
    }

    function initSearchEnhance() {
        // AWA 2026-07-22: busca só com lupa — sem botão limpar (X).
        return;
        try {
            let searchInput = document.querySelector('#search, .header-search input[type="text"], .block-search input.input-text');
            if (!searchInput || document.getElementById('awa-search-clear')) return;

            let clearBtn = document.createElement('button');
            clearBtn.id = 'awa-search-clear';
            clearBtn.type = 'button';
            clearBtn.className = 'awa-search-clear-btn';
            clearBtn.setAttribute('aria-label', 'Limpar busca');
            clearBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

            /* Append ao fim do .control — NÃO após o input (quebra $input.next() do Mirasvit). */
            let control = searchInput.closest('.control[data-awa-search-control], .field.search .control, .control')
                || searchInput.parentElement;

            if (control) {
                if (getComputedStyle(control).position === 'static') {
                    control.style.position = 'relative';
                }
                control.appendChild(clearBtn);
            }

            setSearchClearVisible(clearBtn, false);
            searchInput.classList.add('awa-search-input--clearable');

            searchInput.addEventListener('input', function () {
                setSearchClearVisible(clearBtn, !!this.value);
            });
            clearBtn.addEventListener('click', function () {
                searchInput.value = '';
                setSearchClearVisible(clearBtn, false);
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));
                searchInput.focus();
            });
        } catch (e) {}
    }

    /* ============================================================
       15. initSearchAutocompletePanelFix — geometria MST + layout mobile (sem alterar Mirasvit)
    ============================================================ */
    function initSearchAutocompletePanelFix() {
        try {
            if (window.__awaSearchAutocompletePanelFixInit) {
                return;
            }

            let root = document.querySelector('.block-search.awa-professional-search');
            let control = root && root.querySelector('.field.search .control[data-awa-search-control], .field.search .control');

            if (!control) {
                return;
            }

            window.__awaSearchAutocompletePanelFixInit = true;

            function syncSearchLayout() {
                let searchRoot = document.querySelector('.block-search.awa-professional-search');
                let searchControl = searchRoot && searchRoot.querySelector('.field.search .control');
                let form = searchRoot && searchRoot.querySelector('#search_mini_form');
                let input = searchRoot && searchRoot.querySelector('#search');
                let panel = searchControl && searchControl.querySelector('.mst-searchautocomplete__autocomplete._active');
                let isMobile = window.matchMedia('(max-width: 767px)').matches;
                let isActive = !!(form && (
                    form.matches(':focus-within')
                    || form.classList.contains('searchautocomplete__active')
                    || panel
                    || (input && input.value && input.value.length > 0)
                ));

                let searchCol = document.querySelector('.awa-header-search-col');
                let field = searchRoot && searchRoot.querySelector('.field.search');
                let blockContent = searchRoot && searchRoot.querySelector('.block-content');
                /* BUG-SHELL-MOBILE-CONDENSED-1ROW: não expandir search full-bleed no sticky condensed
                   (grid-column:1/-1 + width:100% disputava com ícone 44px → overlap no logo). */
                let isCondensed = !!(document.querySelector('.awa-site-header.awa-header-condensed')
                    || document.querySelector('.header-wrapper-sticky.awa-header-condensed'));

                if (isMobile && searchRoot && isCondensed) {
                    if (searchCol) {
                        searchCol.style.setProperty('width', '44px', 'important');
                        searchCol.style.setProperty('max-width', '44px', 'important');
                        searchCol.style.setProperty('min-width', '44px', 'important');
                        searchCol.style.setProperty('grid-column', 'auto', 'important');
                        searchCol.style.setProperty('grid-area', 'search', 'important');
                    }
                    if (searchRoot) {
                        searchRoot.style.setProperty('width', '44px', 'important');
                        searchRoot.style.setProperty('max-width', '44px', 'important');
                    }
                    if (form) {
                        form.style.setProperty('width', '44px', 'important');
                        form.style.setProperty('max-width', '44px', 'important');
                    }
                } else if (isMobile && searchRoot) {
                    if (searchCol) {
                        searchCol.style.setProperty('width', '100%', 'important');
                        searchCol.style.setProperty('max-width', '100%', 'important');
                        searchCol.style.setProperty('grid-column', '1 / -1', 'important');
                    }

                    searchRoot.style.setProperty('width', '100%', 'important');
                    searchRoot.style.setProperty('max-width', '100%', 'important');

                    if (blockContent) {
                        blockContent.style.setProperty('width', '100%', 'important');
                        blockContent.style.setProperty('max-width', '100%', 'important');
                    }

                    if (field) {
                        field.style.setProperty('display', 'block', 'important');
                        field.style.setProperty('width', '100%', 'important');
                        field.style.setProperty('flex', '1 1 auto', 'important');
                        field.style.setProperty('min-width', '0', 'important');
                    }

                    if (form) {
                        form.style.setProperty('width', '100%', 'important');
                        form.style.setProperty('max-width', '100%', 'important');
                    }
                } else if (searchRoot) {
                    /* Desktop: remover inline mobile — !important inline vence CSS estrutural */
                    if (searchCol) {
                        searchCol.style.removeProperty('width');
                        searchCol.style.removeProperty('max-width');
                        searchCol.style.removeProperty('grid-column');
                    }

                    searchRoot.style.removeProperty('width');
                    searchRoot.style.removeProperty('max-width');

                    if (blockContent) {
                        blockContent.style.removeProperty('width');
                        blockContent.style.removeProperty('max-width');
                    }

                    if (field) {
                        field.style.removeProperty('display');
                        field.style.removeProperty('width');
                        field.style.removeProperty('flex');
                        field.style.removeProperty('min-width');
                    }

                    if (form) {
                        form.style.removeProperty('width');
                        form.style.removeProperty('max-width');
                    }

                    if (input) {
                        input.style.removeProperty('width');
                        input.style.removeProperty('flex');
                        input.style.removeProperty('min-width');
                        input.style.removeProperty('box-sizing');
                    }
                }

                if (isMobile && searchControl) {
                    searchControl.style.setProperty('display', 'block', 'important');
                    searchControl.style.setProperty('width', '100%', 'important');
                    searchControl.style.setProperty('min-width', '0', 'important');
                    searchControl.style.setProperty('overflow', 'visible', 'important');
                }

                if (isMobile && input && isActive) {
                    input.style.setProperty('width', '100%', 'important');
                    input.style.setProperty('flex', 'none', 'important');
                    input.style.setProperty('min-width', '0', 'important');
                    input.style.setProperty('box-sizing', 'border-box', 'important');

                    if (searchControl) {
                        let controlWidth = searchControl.getBoundingClientRect().width;

                        if (controlWidth > 80) {
                            input.style.setProperty('width', Math.round(controlWidth - 36) + 'px', 'important');
                        }
                    }
                }

                if (form && isActive) {
                    form.style.setProperty('overflow', 'visible', 'important');
                }

                if (panel) {
                    panel.style.setProperty('width', '100%', 'important');
                    panel.style.setProperty('left', '0', 'important');
                    panel.style.setProperty('right', '0', 'important');
                    panel.style.setProperty('max-width', '100%', 'important');
                    panel.style.setProperty('min-height', '84px', 'important');
                    panel.style.setProperty('height', 'auto', 'important');
                    panel.style.setProperty('overflow-y', 'auto', 'important');
                }
            }

            if (window.MutationObserver) {
                new window.MutationObserver(function () {
                    syncSearchLayout();
                }).observe(control, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['class', 'style']
                });
            }

            control.addEventListener('focusin', syncSearchLayout, true);
            document.addEventListener('input', function (event) {
                if (event.target && event.target.id === 'search') {
                    window.requestAnimationFrame(syncSearchLayout);
                }
            }, true);
            document.addEventListener('focusin', function (event) {
                if (event.target && event.target.id === 'search') {
                    syncSearchLayout();
                }
            }, true);
            window.addEventListener('resize', syncSearchLayout, { passive: true });

            syncSearchLayout();
            [150].forEach(function (delay) {
                window.setTimeout(syncSearchLayout, delay);
            });
        } catch (e) {}
    }

    function isHomePage() {
        let body = document.body;
        if (!body) {
            return false;
        }

        return body.classList.contains('cms-index-index') ||
            body.classList.contains('cms-home') ||
            body.classList.contains('cms-homepage_ayo_home5');
    }

    function isCatalogListingPage() {
        let body = document.body;
        if (!body) {
            return false;
        }

        return body.classList.contains('catalog-category-view') ||
            body.classList.contains('catalogsearch-result-index');
    }

    /* ============================================================
       INIT
    ============================================================ */
    function init() {
        if (isCatalogListingPage()) {
            /*
             * PLP/search stability: this module's search autocomplete observer and
             * image/lazyload enhancements were reproducing Chrome "page unresponsive"
             * after product images started loading. Keep only tiny safe fixes here;
             * Magento native filters, product cards and add-to-cart remain untouched.
             */
            fixHashLinks();
            fixFooterEmail();
            fixMobileTouch();
            return;
        }

        fixHashLinks();
        fixSocialAria();
        fixFooterEmail();
        contentVisibilityFix();
        fixBreadcrumb();
        fixMobileTouch();
        // injectWhatsApp(); // Removido para evitar duplicidade (Bug #3)
        if (!isHomePage()) {
            initActiveFiltersBadge();
            initScrollProgress();
        }
        initImageLazyLoad();
        initCartFeedback();
        initSearchEnhance();
        initSearchAutocompletePanelFix();
    }

    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    }

    window.setTimeout(function () {
        if (isCatalogListingPage()) {
            return;
        }

        fixSocialAria();
        fixFooterEmail();
        contentVisibilityFix();
        initSearchEnhance();
        initSearchAutocompletePanelFix();
    }, 1500);


    /**
     * A11y WCAG 2.4.3/4.1.2: containers com aria-hidden="true" nao podem conter
     * elementos focaveis (ex.: slides fora da viewport do carrossel). Aplica
     * inert (remove do tab + da arvore de acessibilidade) e sincroniza via
     * MutationObserver — padrao recomendado (inert e nativo nos navegadores).
     */
    /* Lighthouse a11y: (1) tabs legados desabilitados mantem role=tablist + tabs
       focaveis de 3px; (2) jQuery UI cria .ui-dialog sem nome acessivel no minicart. */
    var fixLegacyA11y = function () {
        document.querySelectorAll('.product.data.items.mage-tabs-disabled[role="tablist"]').forEach(function (el) {
            el.removeAttribute('role');
        });
        document.querySelectorAll('.product.data.items.mage-tabs-disabled [role="tab"]').forEach(function (t) {
            t.removeAttribute('role');
            t.removeAttribute('tabindex');
        });
        document.querySelectorAll('.minicart-wrapper .ui-dialog, .awa-header-cart .ui-dialog').forEach(function (d) {
            if (!d.getAttribute('aria-label')) {
                d.setAttribute('aria-label', 'Meu Carrinho');
            }
        });
    };
    fixLegacyA11y();
    window.setTimeout(fixLegacyA11y, 1500);
    window.setTimeout(fixLegacyA11y, 3500);

    var syncInert = function () {
        document.querySelectorAll('[aria-hidden="true"]').forEach(function (el) {
            if (el.closest('[inert]')) { return; }
            var hasFocusable = el.querySelector('a[href], button, input, select, textarea, [tabindex]');
            if (hasFocusable) {
                if (typeof el.inert !== 'undefined') { el.inert = true; }
            }
        });
        document.querySelectorAll('[aria-hidden="false"]').forEach(function (el) {
            if (el.inert) { el.inert = false; }
        });
    };
    if ('inert' in document.createElement('div')) {
        var inertQueued = false;
        var queueInert = function () {
            if (inertQueued) { return; }
            inertQueued = true;
            window.requestAnimationFrame(function () {
                inertQueued = false;
                syncInert();
            });
        };
        queueInert();
        try {
            new MutationObserver(queueInert)
                .observe(document.body, { attributes: true, attributeFilter: ['aria-hidden', 'inert'], subtree: true });
        } catch (e) { /* observador e progressivo; guard roda tambem em load */ }
        window.addEventListener('load', queueInert);
    }

    return {};
});

/* Lighthouse aria-dialog-name: re-aplica o aria-label quando o .ui-dialog do
   minicart e criado pelo jQuery APOS o nosso fixLegacyA11y inicial */
function applyMinicartDialogName() {
    var dialogs = document.querySelectorAll('.minicart-wrapper .ui-dialog[role="dialog"]');
    for (var i = 0; i < dialogs.length; i++) {
        if (!dialogs[i].getAttribute('aria-label') || dialogs[i].getAttribute('aria-label').indexOf('Meu Carrinho') !== 0) {
            dialogs[i].setAttribute('aria-label', 'Meu Carrinho de Compras');
        }
    }
}
if (window.MutationObserver) {
    var minicartDialogObserver = new MutationObserver(function () {
        applyMinicartDialogName();
    });
    minicartDialogObserver.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'role'] });
} else {
    setInterval(applyMinicartDialogName, 1200);
}

/* AWA-VS1 (17/10/2026) — navegação confiável do menu mobile.
   O drawer mobile recebe o clique mas o hit-test cai no container (camadas de
   carrossel/hero por cima), impedindo o link de navegar. Fallback por
   coordenada: com o menu aberto, clique sobre um link de categoria navega. */
(function () {
  if (window.__awaMenuLinkFix) { return; }
  window.__awaMenuLinkFix = true;
  function menuOpen() {
    var b = document.body;
    return !!(b && (b.classList.contains('nav-open') || b.classList.contains('awa-menu-drawer-open') || b.classList.contains('awa-mobile-drawer-open')));
  }
  document.addEventListener('click', function (e) {
    try {
      if (!menuOpen()) { return; }
      var links = document.querySelectorAll('.section-items a[href], .nav-sections a[href], .side-verticalmenu a[href]');
      var x = e.clientX, y = e.clientY;
      for (var i = 0; i < links.length; i++) {
        var a = links[i], r = a.getBoundingClientRect();
        if (r.width > 5 && r.height > 5 && x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) {
          var href = a.getAttribute('href');
          if (href && href.charAt(0) !== '#' && href.indexOf('javascript:') !== 0) {
            e.preventDefault();
            window.location.assign(a.href);
          }
          return;
        }
      }
    } catch (err) {}
  }, true);
})();
