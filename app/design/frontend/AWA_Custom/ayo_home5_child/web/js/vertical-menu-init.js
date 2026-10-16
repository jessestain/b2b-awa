/**
 * AWA Motos — Vertical Menu Toggle Controller
 *
 * Manages the sidebar vertical-menu lifecycle:
 *  - Desktop >= 992 px : hover/focus opens category dropdown (Home 5 default behavior)
 *  - Mobile  <  992 px : animated drawer + overlay + submenu accordions
 *
 * The native Rokanthemes VerticalMenu jQuery plugin is still initialised for
 * its flyout-positioning logic (hover on desktop).  AWA does NOT duplicate
 * that behaviour; it only adds: open/close of the category list,
 * expand/collapse "Show More", and mobile submenu toggles when the
 * Rokanthemes widget is absent.
 *
 * @module js/vertical-menu-init
 */
define([
    'jquery',
    'rokanthemes/verticalmenu'
], function ($) {
    'use strict';

    /* ================================================================ */
    /*  Helpers (shared across instances)                               */
    /* ================================================================ */

    /**
     * Initialise the Rokanthemes VerticalMenu plugin (idempotent).
     * @param  {jQuery} $menus
     * @return {boolean} true when at least one widget is active
     */
    function initRokanWidget($menus) {
        if (!$.isFunction($.fn.VerticalMenu)) {
            return false;
        }

        var ok = false;

        $menus.each(function () {
            var $m = $(this);

            if (!$m.data('awaRokanInit')) {
                $m.VerticalMenu();
                $m.data('awaRokanInit', 1);
            }

            ok = true;
        });

        return ok;
    }

    /** Trailing-edge debounce. */
    function debounce(fn, ms) {
        var t;

        return function () {
            var ctx  = this;
            var args = arguments;

            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms || 120);
        };
    }

    /** Remove empty CMS placeholder <li> nodes the block renderer may inject. */
    function pruneEmptyBlocks($list) {
        if (!$list || !$list.length) {
            return;
        }

        var has = 'img[src],picture source[srcset],video source[src],iframe[src],a[href],.block,.cms-block';

        $list.find('> li.vertical-menu-custom-block, > li.vertical-bg-img').each(function () {
            var $li = $(this);

            if (!$.trim($li.text()).length && !$li.find(has).length) {
                $li.remove();
            }
        });
    }

    /* ================================================================ */
    /*  Component (called once per x-magento-init match)                */
    /* ================================================================ */

    return function (config, element) {
        var $nav        = $(element);
        var $title      = $nav.find('.title-category-dropdown');
        var $list       = $nav.find('.togge-menu');
        var $expandLink = $nav.find('.vm-toggle-categories');
        var $items      = $nav.find('.ui-menu-item.level0');

        /* ---- config ------------------------------------------------ */
        var safeUid = ($nav.attr('id') || $title.attr('aria-controls') || 'avm-' + Math.random().toString(36).slice(2))
                          .replace(/[^a-zA-Z0-9_-]/g, '');
        var overlaySelector   = (config && config.overlaySelector) || '.shadow_bkg_show';
        var desktopBreakpoint = parseInt(config && config.desktopBreakpoint, 10) || 992;
        var limitItemShow     = parseInt($list.attr('data-limit-show'), 10)
                                || parseInt(config && config.limitShow, 10) || 0;
        var childPanelSelector = '.submenu, ul.level0, .subchildmenu';
        var NS = '.awaVM-' + safeUid;

        /* ---- guard: never double-init ------------------------------ */
        if (!$nav.length || $nav.data('awaVMInit')) {
            return;
        }

        $nav.data('awaVMInit', 1);
        $nav.attr('data-awa-verticalmenu-owner', 'vertical-menu-init');

        /* ---- Rokanthemes flyout widget ------------------------------ */
        var rokanActive = initRokanWidget(
            $nav.filter('.verticalmenu').add($nav.find('.verticalmenu'))
        );

        pruneEmptyBlocks($list);

        /* ---- viewport ---------------------------------------------- */
        var mql = window.matchMedia
            ? window.matchMedia('(min-width: ' + desktopBreakpoint + 'px)')
            : null;

        function isDesktop() {
            return mql ? mql.matches : window.innerWidth >= desktopBreakpoint;
        }

        function isHomeContext() {
            var body = document.body;
            if (!body) {
                return false;
            }

            return body.classList.contains('cms-index-index')
                || body.classList.contains('cms-home')
                || body.classList.contains('cms-homepage_ayo_home5')
                || body.classList.contains('cms-homepage_ayo_home5_demo_stage');
        }

        function keepDesktopMenuExpanded() {
            return isDesktop() && isHomeContext();
        }

        /* ============================================================ */
        /*  Open / Close                                                */
        /* ============================================================ */

        function openMenu() {
            $title.addClass('active').attr('aria-expanded', 'true');

            /* INP: deferir o layout do mega-menu para o proximo frame.
               O .addClass('menu-open') + .show() sincronos custavam ~300ms no
               pointerdown (layout do flyout inteiro). O estado do botao muda
               imediatamente; o painel pinta 1 frame depois (~16ms). */
            window.requestAnimationFrame(function () {
                $list.addClass('menu-open');
                if (isDesktop()) {
                    /* Clear stale inline display from mobile fadeOut()/hide() after viewport switch. */
                    $list.stop(true, true).removeAttr('style').show();
                    $('body').removeClass('background_shadow_show');
                } else {
                    $list.stop(true, true).fadeIn(200);
                    $('body').addClass('background_shadow_show');
                }
            });
        }

        function closeMenu() {
            $list.removeClass('menu-open');
            $title.removeClass('active').attr('aria-expanded', 'false');

            if (isDesktop()) {
                $list.stop(true, true).hide();
            } else {
                $list.stop(true, true).fadeOut(200);
            }

            $('body').removeClass('background_shadow_show');
        }

        function isOpen() {
            return $list.hasClass('menu-open');
        }

        /* ============================================================ */
        /*  Mobile sub-menu toggles                                     */
        /* ============================================================ */

        function ensureMobileToggles() {
            $nav.find('.ui-menu-item.parent, .ui-menu-item.level0.parent').each(function () {
                var $li = $(this);
                var $t  = $li.children('.open-children-toggle');

                if (!$t.length) {
                    $li.append(
                        '<div class="open-children-toggle" role="button"' +
                        ' aria-label="Expandir subcategorias" aria-expanded="false" tabindex="0"></div>'
                    );
                } else {
                    $t.attr({
                        'role':          'button',
                        'tabindex':      '0',
                        'aria-label':    $t.attr('aria-label') || 'Expandir subcategorias',
                        'aria-expanded': $t.attr('aria-expanded') || 'false'
                    });
                }
            });
        }

        function getParentItems($root) {
            return ($root || $nav).find('.ui-menu-item.parent, .ui-menu-item.level0.parent');
        }

        function getDirectChildPanels($item) {
            return $item.children(childPanelSelector);
        }

        function getFirstDirectChildPanel($item) {
            return getDirectChildPanels($item).first();
        }

        function resetParentItemState($item, animateNested) {
            var nestedAnimate = !!animateNested;

            $item.removeClass('_active');
            $item.children('a').removeClass('ui-state-active');
            $item.children('.open-children-toggle').attr('aria-expanded', 'false');

            getDirectChildPanels($item).each(function () {
                var $panel = $(this);

                $panel.removeClass('opened');

                if ($panel.hasClass('subchildmenu')) {
                    if (nestedAnimate) {
                        $panel.stop(true, true).slideUp(200);
                    } else {
                        $panel.stop(true, true).removeAttr('style');
                    }
                } else {
                    $panel.removeAttr('style');
                }
            });

            getParentItems($item).each(function () {
                var $child = $(this);

                if ($child[0] === $item[0]) {
                    return;
                }

                $child.removeClass('_active');
                $child.children('a').removeClass('ui-state-active');
                $child.children('.open-children-toggle').attr('aria-expanded', 'false');
                $child.children(childPanelSelector).removeClass('opened').removeAttr('style');
            });
        }

        function closeSiblingParentItems($item, animateNested) {
            $item.siblings('.ui-menu-item.parent, .ui-menu-item.level0.parent').each(function () {
                resetParentItemState($(this), animateNested);
            });
        }

        function syncParentItemStateFromPanels($item) {
            var $panel = getFirstDirectChildPanel($item);
            var opened = $panel.length && $panel.hasClass('opened');

            $item.toggleClass('_active', !!opened);
            $item.children('a').toggleClass('ui-state-active', !!opened);
            $item.children('.open-children-toggle').attr('aria-expanded', opened ? 'true' : 'false');
        }

        function syncAllMobileParentStates() {
            getParentItems().each(function () {
                syncParentItemStateFromPanels($(this));
            });
        }

        function focusFirstCategoryLink() {
            var $first = $list.children('.ui-menu-item.level0:visible').children('a').first();

            if ($first.length) {
                $first.trigger('focus');
            }
        }

        function bindRokanMobileBridgeHandlers() {
            var $toggles = $nav.find('.open-children-toggle');

            $toggles.off('click' + NS + ' keydown' + NS);

            $toggles.on('click' + NS, function () {
                var $t = $(this);
                var $p = $t.parent();

                if (isDesktop()) {
                    return;
                }

                window.setTimeout(function () {
                    var $panel = getFirstDirectChildPanel($p);
                    var opened = $panel.length && $panel.hasClass('opened');

                    if (opened) {
                        closeSiblingParentItems($p, true);
                    }

                    syncParentItemStateFromPanels($p);
                    syncAllMobileParentStates();
                }, 0);
            });

            $toggles.on('keydown' + NS, function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                    return;
                }

                if (e.key === 'Escape' && !isDesktop()) {
                    e.preventDefault();
                    e.stopPropagation();
                    resetParentItemState($(this).parent(), true);
                }
            });
        }

        /** Reset accordion & visibility when viewport crosses the breakpoint. */
        function syncOnResize() {
            if (isDesktop()) {
                /* Clean up mobile accordion + stale Rokanthemes "opened" class */
                getParentItems().each(function () {
                    resetParentItemState($(this), false);
                });

                /* Re-sync list visibility to current state */
                $list.stop(true, true).removeAttr('style');

                if (keepDesktopMenuExpanded()) {
                    $list.addClass('menu-open');
                }

                if (isOpen()) {
                    $list.show();
                    $title.addClass('active').attr('aria-expanded', 'true');
                } else {
                    $list.hide();
                    $title.removeClass('active').attr('aria-expanded', 'false');
                }

                $('body').removeClass('background_shadow_show');
            } else {
                /* Entering mobile → collapse */
                $list.removeClass('menu-open').hide();
                $title.removeClass('active').attr('aria-expanded', 'false');
                $('body').removeClass('background_shadow_show');
            }
        }

        /* ============================================================ */
        /*  Event binding                                               */
        /* ============================================================ */

        /* ---- title click (main toggle) ----------------------------- */
        $title.on('click' + NS, function (e) {
            e.preventDefault();

            if (isDesktop()) {
                if (keepDesktopMenuExpanded()) {
                    openMenu();
                    return;
                }

                isOpen() ? closeMenu() : openMenu();
                return;
            }

            isOpen() ? closeMenu() : openMenu();
        });

        $title.on('keydown' + NS, function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeMenu();
                return;
            }

            if (e.key === 'ArrowDown' && isDesktop()) {
                e.preventDefault();
                openMenu();
                focusFirstCategoryLink();
                return;
            }

            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (isDesktop()) {
                    openMenu();
                    focusFirstCategoryLink();
                    return;
                }
                $title.trigger('click' + NS);
            }
        });

        /* ---- desktop hover/focus (Home 5 default behavior) ---------- */
        $nav.on('mouseenter' + NS, function () {
            if (isDesktop()) {
                openMenu();
            }
        });

        $nav.on('mouseleave' + NS, function () {
            var root = $nav.get(0);
            var active = document.activeElement;

            if (!isDesktop()) {
                return;
            }

            if (root && active && root.contains(active)) {
                return;
            }

            if (keepDesktopMenuExpanded()) {
                openMenu();
                return;
            }

            closeMenu();
        });

        $nav.on('focusin' + NS, function () {
            if (isDesktop()) {
                openMenu();
            }
        });

        $nav.on('focusout' + NS, function () {
            if (!isDesktop()) {
                return;
            }

            window.setTimeout(function () {
                var root = $nav.get(0);
                var active = document.activeElement;

                if (root && active && root.contains(active)) {
                    return;
                }

                if (keepDesktopMenuExpanded()) {
                    openMenu();
                    return;
                }

                closeMenu();
            }, 0);
        });

        $nav.on('keydown' + NS, function (e) {
            if (e.key !== 'Escape') {
                return;
            }

            if (!isDesktop() && !isOpen()) {
                return;
            }

            if (keepDesktopMenuExpanded()) {
                openMenu();
                return;
            }

            e.stopPropagation();
            closeMenu();
            $title.trigger('focus');
        });

        if (rokanActive) {
            /* Keyboard desktop should reuse Rokanthemes flyout-positioning handlers. */
            $nav.on('focusin' + NS, 'li.level0.parent, li.classic .subchildmenu > li.parent', function () {
                if (isDesktop()) {
                    $(this).triggerHandler('mouseenter');
                }
            });
        }

        /* ---- mobile submenu accordion (only when Rokanthemes absent) */
        if (!rokanActive) {
            $nav.on('click' + NS, '.open-children-toggle', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (isDesktop()) {
                    return;
                }

                var $t = $(this);
                var $p = $t.parent();
                var expanding = !$p.hasClass('_active');

                if (expanding) {
                    closeSiblingParentItems($p, true);
                }

                $p.toggleClass('_active');
                $t.attr('aria-expanded', expanding ? 'true' : 'false');
                $p.children(childPanelSelector).stop(true, true).slideToggle(200);
            });

            $nav.on('keydown' + NS, '.open-children-toggle', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                    return;
                }

                if (e.key === 'Escape' && !isDesktop()) {
                    e.preventDefault();
                    e.stopPropagation();
                    resetParentItemState($(this).parent(), true);
                }
            });
        } else {
            /* Rokanthemes binds direct click handlers with stopPropagation().
               Bridge must be direct too, otherwise delegated handlers on $nav won't fire. */
            bindRokanMobileBridgeHandlers();
        }

        /* ---- "Show more / Show less" -------------------------------- */
        (function initExpandLink() {
            if (limitItemShow <= 0) {
                $expandLink.closest('.expand-category-link').hide();
                return;
            }

            if ($items.length <= limitItemShow) {
                $expandLink.closest('.expand-category-link').hide();
                return;
            }

            $items.each(function (i) {
                if (i >= limitItemShow) {
                    $(this).addClass('orther-link').hide();
                }
            });

            $expandLink.closest('.expand-category-link').show();

            $expandLink.on('click' + NS, function (e) {
                e.preventDefault();

                var $a        = $(this);
                var $hidden   = $nav.find('.ui-menu-item.level0.orther-link');
                var expanding = !$a.hasClass('expanding');

                $a.toggleClass('expanding', expanding)
                   .closest('.expand-category-link').toggleClass('expanding', expanding);

                if ($a.data('show-text') && $a.data('hide-text')) {
                    $a.find('span').text(expanding ? $a.data('hide-text') : $a.data('show-text'));
                }

                $a.attr('aria-expanded', expanding ? 'true' : 'false');
                $hidden.stop(true, true)[expanding ? 'fadeIn' : 'fadeOut'](180);
            }).on('keydown' + NS, function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    $(this).trigger('click');
                    return;
                }

                if (e.key === 'Escape' && $(this).hasClass('expanding')) {
                    e.preventDefault();
                    $(this).trigger('click');
                }
            });
        })();

        /* ---- overlay click → close (mobile) ------------------------ */
        $(overlaySelector).on('click' + NS, function () {
            if (!isDesktop()) {
                closeMenu();
            }
        });

        /* ---- resize ------------------------------------------------ */
        $(window).on('resize' + NS, debounce(function () {
            ensureMobileToggles();
            if (rokanActive) {
                bindRokanMobileBridgeHandlers();
            }
            syncOnResize();
        }, 120));

        /* ---- cleanup on DOM removal -------------------------------- */
        $nav.on('remove' + NS, function () {
            $(window).off(NS);
            $(overlaySelector).off(NS);
        });

        /* ============================================================ */
        /*  ARIA fix — prevent tabs-widget aria-hidden from hiding      */
        /*  the focusable trigger h2 from assistive technology.         */
        /*                                                              */
        /*  Magento tabs widget marks closed panels aria-hidden="true". */
        /*  Since FIX-53 CSS hides the tab-trigger visually (not via   */
        /*  JS), the panel is never "opened" by the widget, so it keeps */
        /*  aria-hidden on the ancestor while our h2 (tabindex="0") is  */
        /*  focusable — a WCAG 1.3.1 / ARIA violation.                 */
        /*                                                              */
        /*  Fix: remove aria-hidden from every ancestor panel on init,  */
        /*  and observe for re-injection via MutationObserver.          */
        /* ============================================================ */

        function fixSectionAriaHidden() {
            /* Collect the two relevant ancestor layers:
               1. The Magento tabs content panel (data-role="content" / .section-item-content)
               2. The outer sections wrapper (#nav-sections / .sections.nav-sections) */
            var $panels = $nav
                .closest('[data-role="content"], .section-item-content')
                .add($nav.closest('#nav-sections, .sections.nav-sections.category-dropdown'));

            if (!$panels.length) {
                return;
            }

            /* Remove on current render */
            $panels.removeAttr('aria-hidden');

            /* Guard against the tabs widget re-adding it after init */
            if (typeof MutationObserver === 'undefined') {
                return;
            }

            $panels.each(function () {
                var el  = this;
                var obs = new MutationObserver(function (mutations) {
                    var i, m;

                    for (i = 0; i < mutations.length; i++) {
                        m = mutations[i];

                        if (m.attributeName === 'aria-hidden'
                                && el.getAttribute('aria-hidden') !== null) {
                            el.removeAttribute('aria-hidden');
                        }
                    }
                });

                obs.observe(el, { attributes: true, attributeFilter: ['aria-hidden'] });

                /* Disconnect when the nav is removed from the DOM */
                $nav.one('remove' + NS, function () { obs.disconnect(); });
            });
        }

        /* ============================================================ */
        /*  Boot                                                        */
        /* ============================================================ */

        ensureMobileToggles();
        if (rokanActive) {
            bindRokanMobileBridgeHandlers();
        }
        syncOnResize();
        fixSectionAriaHidden();
    };
});
