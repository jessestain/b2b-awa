/* global define, window, document */
define(['jquery'], function ($) {
    'use strict';

    /* ----------------------------------------------------------------
     * AWA Home/Category Compat JS
     * Applies ARIA/accessibility attributes to Rokanthemes components.
     * Runs on: homepage, catalog-category-view, catalogsearch-result-index
     * ---------------------------------------------------------------- */

    let OBSERVER_KEY   = '__awaHomeCategoryCompatObserver';
    let SCHEDULED_KEY  = '__awaHomeCategoryCompatScheduled';
    let DEBUG_ONCE_KEY = '__awaHomeCategoryCompatDebugOnce';
    let QUICKVIEW_OBSERVER_KEY = '__awaQuickviewA11yObserver';
    let MAX_OBS_FIRES  = 12;     /* disconnect after N relevant mutations */
    let obsFireCount   = 0;

    /* Selectors that constitute "relevant" DOM additions worth re-running for */
    let RELEVANT_SELECTORS = [
        '.navigation.verticalmenu',
        '.togge-menu',
        '.navigation.custommenu',
        '.open-children-toggle',
        '.filter-options-item',
        '.toolbar-products',
        '.products-grid',
        '.owl-prev', '.owl-next',
        '.owl-controls'
    ];

    function isHomePage() {
        let b = document.body;
        return !!(b && (
            b.classList.contains('cms-index-index') ||
            b.classList.contains('cms-home')
        ));
    }

    function isCatalogListPage() {
        let b = document.body;
        return !!(b && (
            b.classList.contains('catalog-category-view') ||
            b.classList.contains('catalogsearch-result-index')
        ));
    }

    function isRelevantPage() {
        return isHomePage() || isCatalogListPage();
    }

    function debugOnce(hypothesisId, message, data) {
        window[DEBUG_ONCE_KEY] = window[DEBUG_ONCE_KEY] || {};
        if (window[DEBUG_ONCE_KEY][message]) {
            return;
        }
        window[DEBUG_ONCE_KEY][message] = true;
    }

    function isVisible(el) {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }

    function setLabel($el, label) {
        if ($el.length && label) {
            if (!$el.attr('title'))     { $el.attr('title', label); }
            if (!$el.attr('aria-label')) { $el.attr('aria-label', label); }
        }
    }

    function trimText(val) {
        return $.trim(String(val || '').replace(/\s+/g, ' '));
    }

    /* ---- Vertical Menu ---- */
    function applyVerticalMenuA11y() {
        var $nav = $('.navigation.verticalmenu.side-verticalmenu');
        if (!$nav.length) { return; }

        $nav.attr('data-awa-component', $nav.attr('data-awa-component') || 'vertical-menu');

        var $panel = $nav.find('.togge-menu').first();
        let isOpen = $panel.hasClass('menu-open') || isVisible($panel.get(0));
        $nav.toggleClass('is-open', !!isOpen);

        $nav.find('.ui-menu-item.parent, .ui-menu-item.level0.parent').each(function () {
            var $li      = $(this);
            var $link    = $li.children('a').first();
            var $toggle  = $li.children('.open-children-toggle').first();
            var $submenu = $li.children('.submenu, .subchildmenu, ul.level0').first();
            let label    = trimText($link.text()) || 'Submenu';
            let open     = $li.hasClass('_active') || $li.hasClass('active') ||
                           ($submenu.length && ($submenu.hasClass('opened') || isVisible($submenu.get(0))));

            $li.toggleClass('is-open', !!open).attr('data-awa-parent-item', 'true');

            if ($toggle.length) {
                let panelId = $submenu.attr('id');
                if (!panelId && $submenu.length) {
                    panelId = 'awa-vm-panel-' + Math.random().toString(36).slice(2, 9); // nosemgrep: rules/lgpl/javascript/crypto/rule-node-insecure-random-generator
                    $submenu.attr('id', panelId);
                }
                $toggle.attr({
                    role:           'button',
                    tabindex:       $toggle.attr('tabindex') || '0',
                    'aria-expanded': open ? 'true' : 'false'
                });
                if (panelId) { $toggle.attr('aria-controls', panelId); }
                setLabel($toggle, (open ? 'Recolher ' : 'Expandir ') + label);
            }

            if ($submenu.length) { $submenu.attr('aria-hidden', open ? 'false' : 'true'); }
            if ($link.length)    { setLabel($link, label); }
        });

        $('.shadow_bkg_show').attr('aria-hidden',
            $('body').hasClass('background_shadow_show') ? 'false' : 'true');

        if (!isVisible($panel.get(0))) {
            $('body').removeClass('awa-vertical-menu-open background_shadow_show');
        }
    }

    /* ---- Custom (Horizontal) Menu ---- */
    function applyCustomMenuA11y() {
        $('.navigation.custommenu.main-nav').each(function () {
            var $nav = $(this);
            $nav.attr('data-awa-component', $nav.attr('data-awa-component') || 'main-nav');

            $nav.find('a[href]').each(function () {
                var $a    = $(this);
                let label = trimText($a.text());
                if (!label && $a.find('i.fa').length) { label = 'Link de navegação'; }
                setLabel($a, label);
            });

            $nav.find('.open-children-toggle').each(function () {
                var $t       = $(this);
                var $li      = $t.closest('li');
                let label    = trimText($li.children('a').first().text()) || 'Submenu';
                var $sub     = $li.children('.submenu, .groupmenu, .subchildmenu').first();
                let expanded = $li.hasClass('active') || $li.hasClass('_active') ||
                               ($sub.length && isVisible($sub.get(0)));
                $t.attr('aria-expanded', expanded ? 'true' : 'false');
                setLabel($t, (expanded ? 'Recolher ' : 'Expandir ') + label);
            });
        });
    }

    /* ---- Search Form ---- */
    function applySearchA11y() {
        var $form = $('#search_mini_form, form.form.minisearch').first();
        if (!$form.length) { return; }

        var $input = $form.find('#search, #search-input-autocomplate, input[name="q"]').first();
        var $autocomplete = $('#search_autocomplete, .searchsuite-autocomplete').first();
        let acVisible = $autocomplete.length && isVisible($autocomplete.get(0));

        $form.attr('data-awa-component', $form.attr('data-awa-component') || 'search-form')
             .toggleClass('is-open', !!acVisible);

        if ($input.length) {
            setLabel($input, $input.attr('placeholder') || 'Buscar produtos');
            $input.attr('aria-expanded', acVisible ? 'true' : 'false');
        }

        $form.find('.action.search').each(function () { setLabel($(this), 'Pesquisar'); });

        if ($autocomplete.length) {
            $autocomplete.attr('aria-hidden', acVisible ? 'false' : 'true');
            $autocomplete.find('a[href]').each(function () {
                var $a = $(this); setLabel($a, trimText($a.text()));
            });
        }
    }

    /* ---- PLP live region (filter/AJAX updates) ---- */
    function ensurePlpLiveRegion() {
        if (!document.getElementById('awa-plp-live-region')) {
            var live = document.createElement('div');
            live.id = 'awa-plp-live-region';
            live.className = 'sr-only';
            live.setAttribute('role', 'status');
            live.setAttribute('aria-live', 'polite');
            live.setAttribute('aria-atomic', 'true');
            document.body.appendChild(live);
        }
    }

    function announcePlpUpdate(message) {
        var live = document.getElementById('awa-plp-live-region');
        if (!live || !message) { return; }
        live.textContent = '';
        window.setTimeout(function () {
            live.textContent = message;
        }, 60);
    }

    function bindPlpContentUpdated() {
        if (window.__awaPlpContentUpdatedBound) { return; }
        window.__awaPlpContentUpdatedBound = true;

        $(document).on('contentUpdated', '#layered-ajax-list-products, #layered-ajax-filter-block', function () {
            applyPlpA11y();
            applyProductCardA11y();
            var countEl = document.querySelector('#toolbar-amount-top, #toolbar-amount-bottom, .toolbar-amount');
            var countText = countEl ? trimText(countEl.textContent) : '';
            announcePlpUpdate(countText ? ('Lista atualizada. ' + countText) : 'Lista de produtos atualizada.');
        });
    }

    function bindFilterOverlayWatchdog() {
        if (window.__awaFilterOverlayWatchdog) { return; }
        window.__awaFilterOverlayWatchdog = true;

        var overlayTimer = null;
        var hideStuckOverlay = function () {
            var $overlay = $('#layered_ajax_overlay');
            if (!$overlay.length || !$overlay.is(':visible')) { return; }
            $overlay.hide().attr('aria-hidden', 'true');
            announcePlpUpdate('Não foi possível atualizar os filtros. A página foi restaurada.');
        };

        $(document).ajaxSend(function (_e, _xhr, settings) {
            if (!settings || !settings.url || settings.url.indexOf('isAjax=1') === -1) { return; }
            window.clearTimeout(overlayTimer);
            overlayTimer = window.setTimeout(hideStuckOverlay, 20000);
        });

        $(document).ajaxComplete(function () {
            window.clearTimeout(overlayTimer);
            var $overlay = $('#layered_ajax_overlay');
            if ($overlay.length && !$overlay.is(':visible')) {
                $overlay.attr('aria-hidden', 'true');
            }
        });
    }

    /* ---- PLP / Filters ---- */
    function ensureFilterTitle() {
        var blocks = document.querySelectorAll('#layered-ajax-filter-block, .sidebar-main .block.filter, .block.filter');
        var i;
        var block;
        var content;
        var title;
        var closeButton;

        for (i = 0; i < blocks.length; i++) {
            block = blocks[i];
            content = block.querySelector('.block-content.filter-content');
            if (!content) {
                continue;
            }

            title = content.querySelector('.block-title.filter-title');
            if (!title) {
                title = document.createElement('div');
                title.className = 'block-title filter-title';
                title.setAttribute('role', 'heading');
                title.setAttribute('aria-level', '2');
                title.innerHTML = '<strong data-role="title">Comprar por</strong>';
                content.insertBefore(title, content.firstChild);
            }

            if (!title.id) {
                title.id = 'awa-plp-filter-title';
            }

            if (!title.querySelector('[data-awa-filter-close="true"]')) {
                closeButton = document.createElement('button');
                closeButton.type = 'button';
                closeButton.className = 'action awa-filter-dialog-close';
                closeButton.setAttribute('data-awa-filter-close', 'true');
                closeButton.setAttribute('aria-label', 'Fechar filtros');
                closeButton.innerHTML = '<span aria-hidden="true">&times;</span>';
                title.appendChild(closeButton);
            }
        }
    }

    function ensureFilterDialogOverlay() {
        var overlay = document.querySelector('.awa-filter-drawer-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'awa-filter-drawer-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            document.body.appendChild(overlay);
        }
        return $(overlay);
    }

    /**
     * Labels de preço do layered nav chegam entity-encoded (&lt;span class="price"&gt;).
     * Decodifica no DOM quando o HTML ainda contém entidades (FPC/AJAX legado).
     */
    function fixEscapedPriceFilterLabels(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var nodes = scope.querySelectorAll(
            '.filter-options-content a, .filter-options-content li.item, .filter-current .items .item'
        );
        var fixed = 0;
        nodes.forEach(function (el) {
            var html = el.innerHTML || '';
            if (html.indexOf('&lt;span') === -1 && html.indexOf('&amp;lt;span') === -1) {
                return;
            }
            el.innerHTML = html
                .replace(/&amp;lt;/g, '&lt;')
                .replace(/&amp;gt;/g, '&gt;')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>');
            fixed += 1;
        });
        return fixed;
    }

    function syncQuickviewTriggerState() {
        var $triggers = $('.products-grid .quickview-link, .products-grid [data-role="quickview-button"]');
        var $dialog = $('#quick-window').first();
        var open = $dialog.length &&
            $dialog.attr('aria-hidden') === 'false' &&
            $('body').hasClass('quickview-open');

        $triggers.attr('aria-expanded', 'false');
        if (open) {
            $triggers.filter('.active').first().attr('aria-expanded', 'true');
        }
    }

    function ensureQuickviewObserver() {
        var $dialog = $('#quick-window').first();
        if (!$dialog.length || !window.MutationObserver || window[QUICKVIEW_OBSERVER_KEY]) {
            return;
        }

        window[QUICKVIEW_OBSERVER_KEY] = new window.MutationObserver(syncQuickviewTriggerState);
        window[QUICKVIEW_OBSERVER_KEY].observe($dialog.get(0), {
            attributes: true,
            attributeFilter: ['aria-hidden', 'class', 'style']
        });
    }

    function applyPlpControlA11y() {
        var quickSelector = '.products-grid .quickview-link, .products-grid [data-role="quickview-button"]';

        $('.modes').attr({ role: 'group', 'aria-label': 'Modo de exibição dos produtos' });
        $('.modes .modes-mode.active').attr({
            role: 'button',
            'aria-pressed': 'true',
            'aria-disabled': 'true'
        });
        $('.modes a[data-role="mode-switcher"]').attr({
            role: 'button',
            'aria-pressed': 'false'
        });

        $(document)
            .off('keydown.awaModeSwitcher', '.modes a[data-role="mode-switcher"]')
            .on('keydown.awaModeSwitcher', '.modes a[data-role="mode-switcher"]', function (e) {
                if (e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });

        $(quickSelector).attr({
            role: 'button',
            'aria-haspopup': 'dialog',
            'aria-controls': 'quick-window',
            'aria-expanded': 'false'
        });

        $(document)
            .off('click.awaQuickviewTrigger', quickSelector)
            .on('click.awaQuickviewTrigger', quickSelector, function () {
                $(quickSelector).attr('aria-expanded', 'false');
                $(this).attr('aria-expanded', 'true');
                window.setTimeout(ensureQuickviewObserver, 0);
            })
            .off('click.awaQuickviewClose', '#quickview-close, #quick-background')
            .on('click.awaQuickviewClose', '#quickview-close, #quick-background', function () {
                $(quickSelector).attr('aria-expanded', 'false');
            });

        ensureQuickviewObserver();
        syncQuickviewTriggerState();
    }

    function applyPlpA11y() {
        let isMobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
        let isCategoryOrSearch = document.body &&
            (document.body.classList.contains('catalog-category-view') ||
             document.body.classList.contains('catalogsearch-result-index'));

        $('.toolbar.toolbar-products').each(function () {
            $(this).attr('data-awa-component', $(this).attr('data-awa-component') || 'plp-toolbar');
        });

        applyPlpControlA11y();

        fixEscapedPriceFilterLabels(document);

        var $catSearch = $('#mst_categorySearch');
        if ($catSearch.length && !$catSearch.attr('placeholder')) {
            $catSearch.attr('placeholder', 'Buscar produtos nesta categoria');
        }

        /* Filter panel heading — FPC/AJAX pode omitir .filter-title do template */
        ensureFilterTitle();

        /* Layered filter groups */
        $('.filter-options-item').each(function () {
            var $item    = $(this);
            var $title   = $item.children('.filter-options-title').first();
            var $content = $item.children('.filter-options-content').first();
            let expanded = $item.hasClass('active') || ($content.length && isVisible($content.get(0)));
            let label    = trimText($title.text());

            if (!$title.length) { return; }
            $item.attr('data-awa-component', 'filter-group');
            $title.attr({
                role:           'button',
                tabindex:       $title.attr('tabindex') || '0',
                'aria-expanded': expanded ? 'true' : 'false'
            });
            setLabel($title, (expanded ? 'Recolher filtro ' : 'Expandir filtro ') + (label || 'Filtro'));

            if ($content.length) {
                let cid = $content.attr('id');
                if (!cid) {
                    cid = 'awa-filter-content-' + Math.random().toString(36).slice(2, 9); // nosemgrep: rules/lgpl/javascript/crypto/rule-node-insecure-random-generator
                    $content.attr('id', cid);
                }
                $title.attr('aria-controls', cid);
            }
        });

        $title_keydown_bind:
        $('.filter-options-title').off('keydown.awaFilterA11y').on('keydown.awaFilterA11y', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).trigger('click');
            }
        });

        var $layerOverlay = $('#layered_ajax_overlay');
        if ($layerOverlay.length && !$layerOverlay.data('awa-overlay-a11y')) {
            $layerOverlay.data('awa-overlay-a11y', true);
            var overlayNode = $layerOverlay.get(0);
            if (overlayNode && typeof MutationObserver !== 'undefined') {
                var syncOverlayA11y = function () {
                    $layerOverlay.attr('aria-hidden', $layerOverlay.is(':visible') ? 'false' : 'true');
                };
                syncOverlayA11y();
                new MutationObserver(syncOverlayA11y).observe(overlayNode, {
                    attributes: true,
                    attributeFilter: ['style', 'class']
                });
            }
        }

        /* Mobile filter toggle */
        if (isCategoryOrSearch) {
            var $body    = $('body');
            var $filter  = $('#layered-ajax-filter-block, .block.filter').first();
            var $toolbar = $('.shop-tab-select .toolbar.toolbar-products').first();
            var $label   = $toolbar.find('.modes .modes-label').first();

            if ($filter.length && $toolbar.length && $label.length) {
                $label.attr({ role: 'button', tabindex: '0', 'data-awa-filter-toggle': 'true' });
                /* modes-label vira toggle de filtros: remove aria-labelledby dos
                   mode switchers (evita "Mostrar Filtros · Lista" no SR). */
                $label.siblings('a.modes-mode').each(function () {
                    var $mode = $(this);
                    var modeName = trimText($mode.find('span').first().text() || $mode.attr('title') || '');
                    $mode.removeAttr('aria-labelledby');
                    if (modeName) {
                        $mode.attr('aria-label', 'Ver como ' + modeName);
                    }
                });

                /* e86806: sync classes to LIVE viewport (not init-time isMobile).
                   CDP: load desktop then Emulation 375 → label "Ocultar" while
                   .block.filter display:none (aria-expanded true). */
                syncFilterViewportState();

                $label.off('keydown.awaFilterToggle').on('keydown.awaFilterToggle', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        $(this).trigger('click');
                    }
                });

                $(document)
                    .off('click.awaFilterToggle', '.toolbar .modes .modes-label[data-awa-filter-toggle="true"]')
                    .on('click.awaFilterToggle', '.toolbar .modes .modes-label[data-awa-filter-toggle="true"]', function (e) {
                        if (isMobileViewport()) {
                            e.preventDefault();
                            setMobileFilterExpanded($body.hasClass('awa-plp-filters-collapsed'), true);
                        }
                    });

                $(document)
                    .off('click.awaFilterDialogClose', '[data-awa-filter-close="true"], .awa-filter-drawer-overlay')
                    .on('click.awaFilterDialogClose', '[data-awa-filter-close="true"], .awa-filter-drawer-overlay', function (e) {
                        if (!isMobileViewport() || $body.hasClass('awa-plp-filters-collapsed')) {
                            return;
                        }
                        e.preventDefault();
                        setMobileFilterExpanded(false, true);
                    });

                var onFilterViewport = function () {
                    syncFilterViewportState();
                    updateFilterLabel();
                };
                if (window.matchMedia) {
                    var filterMql = window.matchMedia('(max-width: 767px)');
                    if (filterMql.addEventListener) {
                        filterMql.addEventListener('change', onFilterViewport);
                    } else if (filterMql.addListener) {
                        filterMql.addListener(onFilterViewport);
                    }
                }
                /* CDP/Emulation e alguns browsers: resize sem 'change' no MQL */
                var filterResizeTimer = null;
                $(window).off('resize.awaFilterToggle').on('resize.awaFilterToggle', function () {
                    if (filterResizeTimer) {
                        clearTimeout(filterResizeTimer);
                    }
                    filterResizeTimer = setTimeout(onFilterViewport, 50);
                });

                updateFilterLabel();
            }
        }

        function isMobileViewport() {
            return !!(window.matchMedia && window.matchMedia('(max-width: 767px)').matches);
        }

        function syncFilterViewportState() {
            var $b = $('body');
            if (isMobileViewport()) {
                if (!$b.attr('data-awa-filter-init')) {
                    $b.attr('data-awa-filter-init', 'true');
                }
                if (!$b.hasClass('awa-plp-filters-collapsed') && !$b.hasClass('awa-plp-filters-expanded')) {
                    $b.addClass('awa-plp-filters-collapsed').removeClass('awa-plp-filters-expanded');
                }
            } else {
                $b.removeClass('awa-plp-filters-collapsed awa-plp-filters-expanded');
            }
        }

        function filterFocusableElements($panel) {
            return $panel.find(
                'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
                'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            ).filter(function () {
                return isVisible(this) && window.getComputedStyle(this).visibility !== 'hidden';
            });
        }

        function bindFilterDialogKeyboard($panel) {
            $(document).off('keydown.awaFilterDialog').on('keydown.awaFilterDialog', function (e) {
                var $focusable;
                var first;
                var last;
                var active;

                if (!isMobileViewport() || $('body').hasClass('awa-plp-filters-collapsed')) {
                    return;
                }

                if (e.key === 'Escape') {
                    e.preventDefault();
                    setMobileFilterExpanded(false, true);
                    return;
                }

                if (e.key !== 'Tab') {
                    return;
                }

                $focusable = filterFocusableElements($panel);
                if (!$focusable.length) {
                    e.preventDefault();
                    $panel.get(0).focus();
                    return;
                }

                first = $focusable.get(0);
                last = $focusable.get($focusable.length - 1);
                active = document.activeElement;

                if (!$.contains($panel.get(0), active)) {
                    e.preventDefault();
                    first.focus();
                } else if (e.shiftKey && active === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && active === last) {
                    e.preventDefault();
                    first.focus();
                }
            });
        }

        function setMobileFilterExpanded(expanded, restoreFocus) {
            var $b = $('body');
            var $panel = $('#layered-ajax-filter-block, .block.filter').first();
            var $toggle = $('.shop-tab-select .toolbar.toolbar-products').find('.modes .modes-label').first();
            var returnFocus = window.__awaFilterReturnFocus;

            if (!isMobileViewport() || !$panel.length) {
                return;
            }

            if (expanded) {
                window.__awaFilterReturnFocus = $toggle.get(0) || document.activeElement;
                $b.removeClass('awa-plp-filters-collapsed').addClass('awa-plp-filters-expanded');
                updateFilterLabel();
                bindFilterDialogKeyboard($panel);
                window.setTimeout(function () {
                    var close = $panel.find('[data-awa-filter-close="true"]').get(0);
                    (close || $panel.get(0)).focus({ preventScroll: true });
                }, 0);
                return;
            }

            $b.addClass('awa-plp-filters-collapsed').removeClass('awa-plp-filters-expanded');
            updateFilterLabel();
            $(document).off('keydown.awaFilterDialog');
            if (restoreFocus && returnFocus && typeof returnFocus.focus === 'function') {
                window.setTimeout(function () {
                    returnFocus.focus({ preventScroll: true });
                }, 0);
            }
            window.__awaFilterReturnFocus = null;
        }

        function isDesktopSidebarHidden() {
            var cols = document.querySelector('#maincontent.page-main > .columns.layout.layout-2-col.row, #maincontent.page-main > .columns.layout-2-col.row');
            var side;
            var style;
            var rect;
            if (!cols) {
                return false;
            }
            side = cols.querySelector('.col-xs-12.col-sm-3.col-md-3.col-lg-2, .sidebar-main');
            if (!side && cols.children && cols.children.length) {
                side = cols.children[0];
            }
            if (!side) {
                return false;
            }
            style = window.getComputedStyle(side);
            rect = side.getBoundingClientRect();
            return !!(
                side.hidden === true
                || side.getAttribute('aria-hidden') === 'true'
                || !style
                || style.display === 'none'
                || style.visibility === 'hidden'
                || !rect
                || rect.width <= 1
            );
        }

        function updateFilterLabel() {
            var $body  = $('body');
            var $label = $('.shop-tab-select .toolbar.toolbar-products').find('.modes .modes-label').first();
            var $filter = $('#layered-ajax-filter-block, .block.filter').first();
            var desktopHidden = false;
            var collapsed = false;
            if (!$label.length) { return; }
            syncFilterViewportState();
            let isMob    = isMobileViewport();
            if (isMob) {
                collapsed = $body.hasClass('awa-plp-filters-collapsed');
            } else {
                desktopHidden = isDesktopSidebarHidden();
                collapsed = desktopHidden;
            }
            var filterText = collapsed ? 'Mostrar Filtros' : 'Ocultar Filtros';
            $label.text(filterText)
                  .attr({
                      'aria-expanded': collapsed ? 'false' : 'true',
                      'aria-label': filterText
                  });
            if ($filter.length) {
                $filter.attr('aria-hidden', collapsed ? 'true' : 'false');
                if (isMob && !collapsed) {
                    ensureFilterTitle();
                    $filter.attr({
                        role: 'dialog',
                        'aria-modal': 'true',
                        'aria-labelledby': 'awa-plp-filter-title',
                        tabindex: '-1',
                        'data-awa-filter-dialog': 'open'
                    });
                    ensureFilterDialogOverlay().addClass('is-active');
                    $body.attr('data-awa-filter-dialog-open', 'true');
                } else {
                    if ($filter.attr('data-awa-filter-dialog')) {
                        $filter.removeAttr('role aria-modal aria-labelledby tabindex data-awa-filter-dialog');
                    }
                    ensureFilterDialogOverlay().removeClass('is-active');
                    $body.removeAttr('data-awa-filter-dialog-open');
                }
            }
            if (!isMob && !window.__awaCompatDesktopFilterLogged) {
                window.__awaCompatDesktopFilterLogged = true;
            }
        }
    }

    /* ---- Product Cards ---- */
    function applyProductCardA11y() {
        $('.products-grid .product-item-info, .products-grid .item-product').each(function () {
            var $card  = $(this);
            var $name  = $card.find('.product-item-name a').first();
            let label  = trimText($name.text());

            $card.attr('data-awa-component', $card.attr('data-awa-component') || 'product-card');
            if (label) { setLabel($name, label); }

            $card.find('.actions-primary .action, .product-item-actions .action').each(function () {
                var $btn  = $(this);
                let bText = trimText($btn.text()) || label;
                if ($btn.hasClass('tocart'))     { setLabel($btn, label ? 'Comprar ' + label : 'Comprar produto'); }
                else if ($btn.hasClass('towishlist')) { setLabel($btn, label ? 'Adicionar ' + label + ' aos favoritos' : 'Adicionar aos favoritos'); }
                else if ($btn.hasClass('tocompare'))  { setLabel($btn, label ? 'Comparar ' + label : 'Comparar produto'); }
                else                                  { setLabel($btn, bText); }
            });
        });
    }

    /* ---- OWL Nav Controls ---- */
    function applyOwlA11y() {
        $('.owl-prev, .owl-next').each(function () {
            var $b = $(this);
            let isPrev = $b.hasClass('owl-prev');
            setLabel($b, isPrev ? 'Ver itens anteriores' : 'Ver próximos itens');
            $b.attr('aria-disabled', $b.hasClass('disabled') ? 'true' : 'false');
        });
        $('.owl-controls .owl-buttons div').each(function () {
            var $b = $(this);
            let isPrev = $b.hasClass('owl-prev');
            if (isPrev || $b.hasClass('owl-next')) {
                setLabel($b, isPrev ? 'Ver itens anteriores' : 'Ver próximos itens');
            }
        });
    }

    /* ---- Master run ---- */
    function runAll() {
        if (!isRelevantPage()) { return; }

        if (!isHomePage()) {
            /* PLP/search: evita varreduras completas que geravam travadas intermitentes. */
            applySearchA11y();
            applyPlpA11y();
            debugOnce('H21', 'heavy run skipped on catalog list route', {
                routeType: 'catalog-list'
            });
            return;
        }

        applyVerticalMenuA11y();
        applyCustomMenuA11y();
        applySearchA11y();
        applyPlpA11y();
        applyProductCardA11y();
        applyOwlA11y();
    }

    /* ---- Debounced scheduler (LCP-safe: 3s delay para nao criar LCP re-candidate) ---- */
    function schedule() {
        if (window[SCHEDULED_KEY]) { return; }
        window[SCHEDULED_KEY] = true;
        window.setTimeout(function () {
            window[SCHEDULED_KEY] = false;
            runAll();
        }, 3000);
    }

    /* ---- MutationObserver — only fires for RELEVANT node additions ---- */
    function hasRelevantNode(mutations) {
        let i, j, node, $node;
        for (i = 0; i < mutations.length; i++) {
            let added = mutations[i].addedNodes;
            for (j = 0; j < added.length; j++) {
                node = added[j];
                if (node.nodeType !== 1) { continue; }       /* element nodes only */
                /* Skip OWL internal clones — they are never relevant */
                if (node.classList &&
                    (node.classList.contains('owl-item') ||
                     node.classList.contains('owl-wrapper') ||
                     node.classList.contains('owl-wrapper-outer'))) {
                    continue;
                }
                $node = $(node);
                for (let k = 0; k < RELEVANT_SELECTORS.length; k++) {
                    if ($node.is(RELEVANT_SELECTORS[k]) || $node.find(RELEVANT_SELECTORS[k]).length) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    function resolveObserverRoots() {
        let roots = [];
        let home = document.querySelector('.content-top-home');
        let main = document.querySelector('.page-main, .column.main, #maincontent');
        let nav = document.querySelector('.navigation.verticalmenu, .navigation.custommenu');

        if (home) {
            roots.push(home);
        }
        if (main) {
            roots.push(main);
        }
        if (nav) {
            roots.push(nav);
        }

        return roots;
    }

    function setupObserver() {
        let roots;
        let i;

        if (!window.MutationObserver || window[OBSERVER_KEY]) { return; }
        if (!document.body) { return; }
        if (!isHomePage()) {
            debugOnce('H22', 'compat observer skipped on non-home route', {
                routeType: 'catalog-list'
            });
            return;
        }

        roots = resolveObserverRoots();
        if (!roots.length) {
            return;
        }

        window[OBSERVER_KEY] = new window.MutationObserver(function (mutations) {
            if (!hasRelevantNode(mutations)) { return; }
            obsFireCount++;
            if (obsFireCount > MAX_OBS_FIRES) {
                /* Site is stable — no more significant DOM additions expected */
                window[OBSERVER_KEY].disconnect();
                return;
            }
            schedule();
        });

        for (i = 0; i < roots.length; i++) {
            window[OBSERVER_KEY].observe(roots[i], { childList: true, subtree: true });
        }
    }

    /* ---- Entry point (called by awa-custom-compat-bootstrap.js) ---- */
    return function () {
        if (!isRelevantPage()) { return; }

        /* PLP: filtros recolhidos + toggle antes do defer de 5s (adapt mobile) */
        if (document.body.classList.contains('catalog-category-view') ||
            document.body.classList.contains('catalogsearch-result-index')) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', ensureFilterTitle);
            } else {
                ensureFilterTitle();
            }
            document.addEventListener('contentUpdated', ensureFilterTitle);

            $(function () {
                ensurePlpLiveRegion();
                bindPlpContentUpdated();
                bindFilterOverlayWatchdog();
                applyPlpA11y();
            });
            debugOnce('H21', 'catalog bootstrap running in lightweight mode', {
                routeType: 'catalog-list'
            });
            return;
        }

        /* Adiado para apos o LCP:
         * runAll() imediato ou em 2000ms causava long tasks que criavam
         * novas LCP candidates via forced layout (isVisible -> offsetWidth).
         * Com schedule() usando 3s debounce + primeiro runAll em 5s,
         * o LCP da hero img e capturado em ~2.6s sem interferencia.
         * ARIA/A11y attributes do menu sao imperceptiveis ao usuario em 5s.
         */
        window.setTimeout(runAll, 5000);

        /* Retry: apenas uma vez, bem apos o LCP estar estabilizado */
        window.setTimeout(runAll, 8000);

        /* Observer for dynamic content (AJAX navigation, lazy blocks) */
        setupObserver();
    };
});
