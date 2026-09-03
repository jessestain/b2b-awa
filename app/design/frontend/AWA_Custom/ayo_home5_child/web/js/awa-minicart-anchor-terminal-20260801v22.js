(function() {
    var w = window, d = document, tid = 0, scheduleTimers = [], closeLockUntil = 0, openAtPointerDown = false;
function hdr(trigger) {
        if (trigger) {
            var triggerRect = trigger.getBoundingClientRect();
            if (triggerRect.width > 0 && triggerRect.height > 0) {
                return triggerRect.bottom;
            }
        }
        var s = [ ".header-wrapper-sticky.is-sticky", ".header-wrapper-sticky", ".awa-site-header" ], b = 0, i, el, r;
        for (i = 0; i < s.length; i++) {
            el = d.querySelector(s[i]);
            if (!el) continue;
            r = el.getBoundingClientRect();
            if (r.height > 0 && r.top < 160 && r.bottom > b) b = r.bottom;
        }
        return b > 0 ? b : 64;
    }
    function isOpen() {
        if (Date.now() < closeLockUntil) return false;
        // Magento 2 parentClass (data-mage-init dropdownDialog). Extras AWA
        // (active/show/--expanded/_active) nao podem reabrir o painel apos close.
        return !!d.querySelector("[data-block=\"minicart\"].is-open, .minicart-wrapper.is-open");
    }
    function markOpenState(p) {
        if (!p || !isOpen()) return;
        var shell = p.closest ? p.closest(".awa-header-minicart") : null;
        // Magento 2 owns wrap/trigger is-open (parentClass/triggerClass).
        if (shell) {
            shell.classList.add("awa-header-minicart--expanded");
            shell.setAttribute("data-awa-minicart-expanded", "1");
        }
        p.setAttribute("aria-hidden", "false");
    }
    function unlockDialog(p) {
        if (!isOpen()) return;
        var dialog = p && p.closest ? p.closest(".ui-dialog,.mage-dropdown-dialog") : null;
        if (!dialog || !dialog.style) return;
        dialog.style.setProperty("display", "block", "important");
        dialog.style.setProperty("visibility", "visible", "important");
        dialog.style.setProperty("opacity", "1", "important");
        dialog.style.setProperty("position", "static", "important");
        dialog.style.setProperty("width", "auto", "important");
        dialog.style.setProperty("height", "auto", "important");
        dialog.style.setProperty("max-height", "none", "important");
        dialog.style.setProperty("min-height", "0", "important");
        dialog.style.setProperty("overflow", "visible", "important");
        dialog.style.setProperty("inset", "auto", "important");
    }
    function isCartPage() {
        return !!(d.body && d.body.classList.contains("checkout-cart-index"));
    }
    function clearStickyContainingBlock(stickyEl) {
        if (!stickyEl || !stickyEl.style) return;
        stickyEl.style.setProperty("backdrop-filter", "none", "important");
        stickyEl.style.setProperty("-webkit-backdrop-filter", "none", "important");
        stickyEl.setAttribute("data-awa-mc-bf", "off");
    }
    function restoreStickyContainingBlock(stickyEl) {
        if (!stickyEl || !stickyEl.style) return;
        if (stickyEl.getAttribute("data-awa-mc-bf") !== "off") return;
        stickyEl.style.removeProperty("backdrop-filter");
        stickyEl.style.removeProperty("-webkit-backdrop-filter");
        stickyEl.removeAttribute("data-awa-mc-bf");
    }
    /** Remove dock inline !important so Magento pode esconder o painel. */
    function releaseDockStyles(p) {
        if (!p || !p.style) return;
        [
            "display", "visibility", "opacity", "pointer-events", "contain",
            "position", "top", "right", "left", "bottom", "transform",
            "width", "min-width", "max-width", "height", "max-height", "min-height", "z-index",
            "background", "background-color", "background-image", "isolation",
            "overflow-x", "overflow-y", "flex-direction"
        ].forEach(function (prop) {
            p.style.removeProperty(prop);
        });
        var dialog = p.closest ? p.closest(".ui-dialog,.mage-dropdown-dialog") : null;
        if (dialog && dialog.style) {
            [
                "display", "visibility", "opacity", "position", "width",
                "height", "max-height", "overflow", "inset"
            ].forEach(function (prop) {
                dialog.style.removeProperty(prop);
            });
        }
    }
    function releaseAllDockStyles() {
        d.querySelectorAll(".block-minicart").forEach(releaseDockStyles);
        restoreStickyContainingBlock(d.querySelector(".header-wrapper-sticky"));
    }
    function clearAwaOpenClasses() {
        d.querySelectorAll(".minicart-wrapper").forEach(function (wrap) {
            wrap.classList.remove("active", "show");
            var showcart = wrap.querySelector(".showcart, .action.showcart");
            if (showcart) {
                showcart.classList.remove("active");
            }
        });
        d.querySelectorAll(".awa-header-minicart").forEach(function (shell) {
            shell.classList.remove("awa-header-minicart--expanded");
            shell.setAttribute("data-awa-minicart-expanded", "0");
        });
        d.querySelectorAll(".block-minicart").forEach(function (p) {
            p.classList.remove("_active", "active", "is-open");
            p.setAttribute("aria-hidden", "true");
        });
        d.documentElement.classList.remove("awa-minicart-overlay-active");
        if (d.body) {
            d.body.classList.remove("awa-minicart-overlay-active");
        }
    }
    function syncClosedUi() {
        clearAwaOpenClasses();
        releaseAllDockStyles();
    }
    function cancelSchedule() {
        scheduleTimers.forEach(function (id) { w.clearTimeout(id); });
        scheduleTimers = [];
    }
    function forceClose() {
        closeLockUntil = Date.now() + 500;
        openAtPointerDown = false;
        cancelSchedule();
        d.querySelectorAll(".minicart-wrapper").forEach(function (wrap) {
            wrap.classList.remove("active", "show", "is-open");
            var showcart = wrap.querySelector(".showcart, .action.showcart");
            if (showcart) {
                showcart.classList.remove("is-open", "active");
                showcart.setAttribute("aria-expanded", "false");
            }
        });
        d.querySelectorAll(".awa-header-minicart").forEach(function (shell) {
            shell.classList.remove("awa-header-minicart--expanded");
            shell.setAttribute("data-awa-minicart-expanded", "0");
        });
        d.querySelectorAll(".block-minicart").forEach(function (p) {
            p.classList.remove("_active", "active", "is-open");
            p.setAttribute("aria-hidden", "true");
            releaseDockStyles(p);
            // Não forçar display:none !important — isso impede o Magento de reabrir.
        });
        restoreStickyContainingBlock(d.querySelector(".header-wrapper-sticky"));
        try {
            if (typeof w.require === "function") {
                w.require(["jquery"], function ($) {
                    try {
                        $('[data-block="minicart"]').find('[data-role="dropdownDialog"]').dropdownDialog("close");
                    } catch (eDd) { /* ignore */ }
                });
            }
        } catch (eReq) { /* ignore */ }
    }
    function anchor() {
        var stickyEl = d.querySelector(".header-wrapper-sticky");
        if (!isOpen()) {
            syncClosedUi();
            return null;
        }
        var p = d.querySelector("[data-block=\"minicart\"].is-open .block-minicart, .minicart-wrapper.is-open .block-minicart");
        if (!p) {
            p = d.querySelector(".awa-header-minicart .block-minicart, .minicart-wrapper .block-minicart");
        }
        if (!p || !p.style) return null;
        if (isCartPage()) {
            return {
                skipped: true,
                reason: "checkout-cart-index"
            };
        }
        markOpenState(p);
        unlockDialog(p);
        if (!isOpen()) {
            syncClosedUi();
            return null;
        }
        clearStickyContainingBlock(stickyEl);
        if (stickyEl) {
            void stickyEl.offsetHeight;
        }
        var t = d.querySelector('[data-block="minicart"] .action.showcart,.minicart-wrapper .action.showcart,a.action.showcart');
        p.style.setProperty("position", "fixed", "important");
        var rr = {
            top: 0,
            right: d.documentElement.clientWidth,
            width: d.documentElement.clientWidth
        };
        var hb = hdr(t);
        if (!Number.isFinite(hb) || hb < 0 || hb > w.innerHeight) {
            hb = 64;
        }
        var top = Math.max(0, Math.round(hb));
        var g = 16;
        var narrow = w.innerWidth < 768;
        var pw = Math.min(380, Math.max(280, w.innerWidth - g * 2));
        var right = g;
        var tr;
        var dockH = Math.max(240, Math.min(
            Math.round(w.innerHeight - hb),
            Math.round(w.innerHeight)
        ));
        p.style.setProperty("display", "flex", "important");
        p.style.setProperty("flex-direction", "column", "important");
        p.style.setProperty("visibility", "visible", "important");
        p.style.setProperty("opacity", "1", "important");
        p.style.setProperty("pointer-events", "auto", "important");
        p.style.setProperty("contain", "none", "important");
        p.style.setProperty("top", top + "px", "important");
        p.style.setProperty("bottom", "auto", "important");
        p.style.setProperty("transform", "none", "important");
        p.style.setProperty("z-index", "var(--awa-z-minicart,1300)", "important");
        p.style.setProperty("min-height", "0", "important");
        p.style.setProperty("overflow-x", "hidden", "important");
        p.style.setProperty("overflow-y", "auto", "important");
        p.style.setProperty("background-color", "#fff", "important");
        p.style.setProperty("background-image", "none", "important");
        p.style.setProperty("isolation", "isolate", "important");
        var isEmpty = p.classList.contains("empty") || !!p.querySelector(".awa-minicart-empty");
        if (isEmpty) {
            p.style.setProperty("height", "auto", "important");
            p.style.setProperty("max-height", dockH + "px", "important");
        } else {
            p.style.setProperty("height", dockH + "px", "important");
            p.style.setProperty("max-height", dockH + "px", "important");
        }
        if (narrow) {
            p.style.setProperty("left", g + "px", "important");
            p.style.setProperty("right", g + "px", "important");
            p.style.setProperty("width", "auto", "important");
            p.style.setProperty("min-width", "0", "important");
            p.style.setProperty("max-width", "none", "important");
        } else {
            if (t) {
                tr = t.getBoundingClientRect();
                if (tr.width > 0) right = Math.max(g, rr.right - tr.right);
                if (right + pw > rr.width - g) right = Math.max(g, rr.width - g - pw);
            }
            p.style.setProperty("right", right + "px", "important");
            p.style.setProperty("left", "auto", "important");
            p.style.setProperty("width", pw + "px", "important");
            p.style.setProperty("min-width", pw + "px", "important");
            p.style.setProperty("max-width", pw + "px", "important");
        }
        return {
            anchored: true,
            right: right,
            top: top,
            rootTop: rr.top,
            headerBottom: hb
        };
    }
    function schedule() {
        cancelSchedule();
        anchor();
        [ 0, 32, 100, 250, 600, 1200, 2e3 ].forEach(function(ms) {
            scheduleTimers.push(w.setTimeout(anchor, ms));
        });
    }
    function patchAmd() {
        if (typeof w.require !== "function" || w.require._awaStub) return;
        try {
            w.require([ "js/awa-minicart-position" ], function(m) {
                if (!m || m.__awaAnchored) return;
                m.__awaAnchored = 1;
                m.centerOpenMinicartPanel = function() {
                    return anchor();
                };
                m.scheduleCenterOpenMinicartPanel = function() {
                    schedule();
                };
                m.centerCurrentMinicart = function() {
                    schedule();
                };
            });
        } catch (e) {}
    }
    function noteShowcartPointerState(e) {
        var el = e && e.target;
        if (!el || !el.closest) return;
        if (el.closest(".action.showcart,.showcart,[data-awa-minicart-defer=\"trigger\"]")) {
            openAtPointerDown = isOpen();
        }
    }
    d.addEventListener("pointerdown", noteShowcartPointerState, true);
    d.addEventListener("mousedown", noteShowcartPointerState, true);
    d.addEventListener("click", function(e) {
        var el = e && e.target;
        if (!el || !el.closest) return;
        if (el.closest("#btn-minicart-close, .block-minicart .action.close, .block-minicart [data-action=\"close\"]")) {
            e.preventDefault();
            e.stopImmediatePropagation();
            forceClose();
            w.setTimeout(forceClose, 50);
            return;
        }
        if (el.closest('.action.showcart,.showcart,[data-awa-minicart-defer="trigger"]')) {
            // Fechar só se já estava aberto no pointerdown (evita fechar o reopen).
            var shouldClose = !!openAtPointerDown;
            if (shouldClose) {
                e.preventDefault();
                e.stopImmediatePropagation();
                forceClose();
                openAtPointerDown = false;
                return;
            }
            closeLockUntil = 0;
            openAtPointerDown = false;
            e.preventDefault();
            e.stopImmediatePropagation();
            d.querySelectorAll(".block-minicart").forEach(releaseDockStyles);
            var panel = d.querySelector(".awa-header-minicart .block-minicart, .minicart-wrapper .block-minicart");
            if (panel) {
                markOpenState(panel);
            }
            try {
                if (typeof w.require === "function") {
                    w.require(["jquery"], function ($) {
                        try {
                            $('[data-block="minicart"]').find('[data-role="dropdownDialog"]').dropdownDialog("open");
                        } catch (eOpen) { /* ignore */ }
                    });
                }
            } catch (eReqOpen) { /* ignore */ }
            schedule();
            patchAmd();
        }
    }, true);
    d.addEventListener("keydown", function(e) {
        if ((e.key === "Escape" || e.keyCode === 27) && isOpen()) {
            forceClose();
        }
    }, true);
    [ 0, 800, 2e3, 4e3 ].forEach(function(ms) {
        w.setTimeout(patchAmd, ms);
    });
    w.addEventListener("resize", function() {
        if (isOpen()) anchor();
        else syncClosedUi();
    }, {
        passive: true
    });
    if (!(d.body && d.body.classList.contains("cms-index-index"))) {
        w.addEventListener("scroll", function() {
            if (!isOpen()) {
                syncClosedUi();
                return;
            }
            if (tid) w.clearTimeout(tid);
            tid = w.setTimeout(anchor, 50);
        }, {
            passive: true
        });
    }
    function watchClosedState() {
        if (d.__awaMinicartClosedWatch) {
            return;
        }
        d.__awaMinicartClosedWatch = 1;
        if (typeof MutationObserver !== 'function') {
            return;
        }
        var root = d.querySelector('.awa-header-minicart, .minicart-wrapper');
        if (!root) {
            return;
        }
        /* 51.52: never call syncClosedUi synchronously inside MO — classList
         * mutations re-enter the same observer and freeze the main thread
         * (CDP heartbeat dies ~2–6s on PDP/PLP). Debounce + re-entry lock. */
        var syncing = false;
        var scheduled = false;
        var mo = new MutationObserver(function () {
            if (syncing || scheduled || isOpen()) {
                return;
            }
            scheduled = true;
            w.requestAnimationFrame(function () {
                scheduled = false;
                if (syncing || isOpen()) {
                    return;
                }
                syncing = true;
                try {
                    syncClosedUi();
                } finally {
                    syncing = false;
                }
            });
        });
        mo.observe(root, {
            attributes: true,
            attributeFilter: ['class'],
            subtree: true
        });
    }
    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', watchClosedState, { once: true });
    } else {
        watchClosedState();
    }
})();
