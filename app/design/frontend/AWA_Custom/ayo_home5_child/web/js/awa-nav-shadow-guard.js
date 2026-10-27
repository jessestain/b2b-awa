(function (w, d) {
    'use strict';
    if (w.__awaNavShadowGuardInit) {
        return;
    }
    w.__awaNavShadowGuardInit = true;

    function isDrawerActuallyOpen() {
        return d.body.classList.contains('awa-menu-drawer-open')
            || d.body.classList.contains('background_shadow_show')
            || d.body.classList.contains('background_shadow')
            || !!d.querySelector('#awa-primary-navigation.is-awa-mobile-open, #awa-category-navigation.is-awa-mobile-open, [data-awa-nav-shell="true"].is-awa-mobile-open');
    }

    function clearStaleNavOverlay(reason) {
        if (isDrawerActuallyOpen()) {
            return false;
        }
        if (!d.body.classList.contains('nav-open')
            && !d.body.classList.contains('awa-nav-preflight')
            && !d.body.classList.contains('nav-before-open')
            && !d.body.classList.contains('background_shadow_show')) {
            return false;
        }
        d.body.classList.remove(
            'nav-open',
            'awa-nav-preflight',
            'awa-menu-drawer-open',
            'nav-before-open',
            'background_shadow',
            'background_shadow_show',
            'awa-vertical-menu-open'
        );
        d.documentElement.classList.remove('nav-open', 'nav-before-open');
        var toggle = d.querySelector('[data-awa-nav-toggle="true"], [data-action="toggle-nav"]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
        return true;
    }

    function onShadowDismiss(ev) {
        var target = ev.target;
        if (!target || typeof target.closest !== 'function') {
            return;
        }
        if (!target.closest('.shadow_bkg, .shadow_bkg_show')) {
            return;
        }
        if (!isDrawerActuallyOpen()) {
            clearStaleNavOverlay('shadow_click_stale');
            return;
        }
        if (typeof w.__awaMenuControllerClose === 'function') {
            w.__awaMenuControllerClose();
            return;
        }
        clearStaleNavOverlay('shadow_click_fallback');
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () {
            clearStaleNavOverlay('dom_ready');
        }, { once: true });
    } else {
        clearStaleNavOverlay('immediate');
    }

    d.addEventListener('click', onShadowDismiss, true);
    d.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            clearStaleNavOverlay('escape');
        }
    }, true);
}(window, document));
