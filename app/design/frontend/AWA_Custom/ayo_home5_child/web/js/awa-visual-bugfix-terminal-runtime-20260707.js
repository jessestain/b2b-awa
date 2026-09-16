(function () {
    'use strict';

    var debugEnabled = window.location.search.indexOf('awa_debug_logs=1') !== -1;
    var debugState = {
        count: 0,
        max: 60
    };
    var lastDriftKey = '';

    function readBox(selector) {
        var el = document.querySelector(selector);
        if (!el) {
            return null;
        }

        var rect = el.getBoundingClientRect();
        var style = window.getComputedStyle(el);

        return {
            h: Number(rect.height.toFixed(2)),
            w: Number(rect.width.toFixed(2)),
            top: Number(rect.top.toFixed(2)),
            left: Number(rect.left.toFixed(2)),
            minHeight: style.minHeight,
            display: style.display,
            marginTop: style.marginTop,
            marginBottom: style.marginBottom,
            paddingTop: style.paddingTop,
            paddingBottom: style.paddingBottom,
            lineHeight: style.lineHeight,
            fontSize: style.fontSize,
            whiteSpace: style.whiteSpace,
            wordBreak: style.wordBreak,
            overflowWrap: style.overflowWrap,
            textAlign: style.textAlign,
            overflow: style.overflow,
            inlineStyle: el.getAttribute('style') || '',
            textLength: (el.textContent || '').trim().length
        };
    }

    function readCopyrightDetail() {
        var root = document.querySelector('.awa-footer-bottom__copyright');
        if (!root) {
            return null;
        }

        var legal = root.querySelector('.awa-footer-copyright__legal');
        var disclaimer = root.querySelector('.awa-footer-copyright__disclaimer');
        var legalBox = legal ? legal.getBoundingClientRect() : null;
        var discBox = disclaimer ? disclaimer.getBoundingClientRect() : null;
        var legalStyle = legal ? window.getComputedStyle(legal) : null;
        var discStyle = disclaimer ? window.getComputedStyle(disclaimer) : null;

        return {
            childCount: root.children.length,
            legal: legal ? {
                h: Number(legalBox.height.toFixed(2)),
                w: Number(legalBox.width.toFixed(2)),
                lineHeight: legalStyle.lineHeight,
                fontSize: legalStyle.fontSize,
                textLength: (legal.textContent || '').trim().length
            } : null,
            disclaimer: disclaimer ? {
                h: Number(discBox.height.toFixed(2)),
                w: Number(discBox.width.toFixed(2)),
                lineHeight: discStyle.lineHeight,
                fontSize: discStyle.fontSize,
                textLength: (disclaimer.textContent || '').trim().length
            } : null
        };
    }

    function snapshot(trigger) {
        return {
            trigger: trigger,
            classes: document.documentElement.className,
            fontStatus: document.fonts && document.fonts.status ? document.fonts.status : 'na',
            viewport: {
                width: window.innerWidth,
                height: window.innerHeight,
                scrollY: window.scrollY
            },
            footer: readBox('footer.page-footer'),
            shell: readBox('footer.page-footer > .page_footer'),
            container: readBox('#footer.footer-container'),
            footerBottom: readBox('footer.page-footer .footer-bottom'),
            newsletter: readBox('.awa-footer-newsletter'),
            atendimento: readBox('.awa-footer-atendimento'),
            devby: readBox('.awa-footer-devby'),
            pay: readBox('.awa-footer-pay-sec'),
            ul: readBox('.awa-footer-pay-logos'),
            legal: readBox('.awa-footer-copyright__legal'),
            copy: readBox('.awa-footer-bottom__copyright'),
            copyDetail: readCopyrightDetail()
        };
    }

    function debugLog(hypothesisId, message, data) {
        if (!debugEnabled || debugState.count >= debugState.max) {
            return;
        }

        debugState.count += 1;
        /* Opt-in only via ?awa_debug_logs=1 — no remote ingest. */
    }

    function driftKey(data) {
        return JSON.stringify({
            classes: data.classes,
            fontStatus: data.fontStatus,
            viewportWidth: data.viewport ? data.viewport.width : null,
            viewportHeight: data.viewport ? data.viewport.height : null,
            scrollY: data.viewport ? data.viewport.scrollY : null,
            footerH: data.footer ? data.footer.h : null,
            footerW: data.footer ? data.footer.w : null,
            footerMarginTop: data.footer ? data.footer.marginTop : null,
            shellH: data.shell ? data.shell.h : null,
            shellW: data.shell ? data.shell.w : null,
            shellMarginTop: data.shell ? data.shell.marginTop : null,
            containerH: data.container ? data.container.h : null,
            containerW: data.container ? data.container.w : null,
            containerMinHeight: data.container ? data.container.minHeight : null,
            footerBottomH: data.footerBottom ? data.footerBottom.h : null,
            footerBottomW: data.footerBottom ? data.footerBottom.w : null,
            newsletterH: data.newsletter ? data.newsletter.h : null,
            atendimentoH: data.atendimento ? data.atendimento.h : null,
            devbyH: data.devby ? data.devby.h : null,
            payH: data.pay ? data.pay.h : null,
            ulH: data.ul ? data.ul.h : null,
            legalH: data.legal ? data.legal.h : null,
            copyH: data.copy ? data.copy.h : null,
            copyW: data.copy ? data.copy.w : null,
            copyLineHeight: data.copy ? data.copy.lineHeight : null,
            copyWhiteSpace: data.copy ? data.copy.whiteSpace : null,
            copyWordBreak: data.copy ? data.copy.wordBreak : null,
            legalInnerH: data.copyDetail && data.copyDetail.legal ? data.copyDetail.legal.h : null,
            legalInnerW: data.copyDetail && data.copyDetail.legal ? data.copyDetail.legal.w : null,
            discInnerH: data.copyDetail && data.copyDetail.disclaimer ? data.copyDetail.disclaimer.h : null,
            discInnerW: data.copyDetail && data.copyDetail.disclaimer ? data.copyDetail.disclaimer.w : null
        });
    }

    function logIfDrift(trigger, hypothesisId) {
        if (!debugEnabled) {
            return;
        }

        var snap = snapshot(trigger);
        var currentKey = driftKey(snap);
        if (currentKey === lastDriftKey) {
            return;
        }

        lastDriftKey = currentKey;
}

    function setupDebugObservers() {
        if (!debugEnabled || window.__awaFooterRuntimeProbeInit) {
            return;
        }

        window.__awaFooterRuntimeProbeInit = true;
var classObserver = new MutationObserver(function () {
});
        classObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class']
        });

        var footerRoot = document.querySelector('footer.page-footer');
        if (footerRoot) {
            var footerMutationObserver = new MutationObserver(function (mutations) {
                var compact = mutations.slice(0, 4).map(function (mutation) {
                    return {
                        type: mutation.type,
                        attr: mutation.attributeName || '',
                        target: mutation.target && mutation.target.className ? mutation.target.className : mutation.target.nodeName
                    };
                });
});

            footerMutationObserver.observe(footerRoot, {
                attributes: true,
                attributeFilter: ['class', 'style', 'hidden', 'aria-hidden'],
                childList: true,
                subtree: true
            });
        }

        if (document.fonts && typeof document.fonts.addEventListener === 'function') {
            document.fonts.addEventListener('loading', function () {
});

            document.fonts.addEventListener('loadingdone', function () {
});
        }

        window.addEventListener('scroll', function () {
            logIfDrift('scroll', 'H44');
        }, { passive: true });

        window.addEventListener('resize', function () {
            logIfDrift('resize', 'H47');
        }, { passive: true });

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }

            if (target.closest('.awa-footer-categories-expand__toggle, .awa-footer-section__toggle, .footer-bottom')) {
                logIfDrift('footer-click', 'H45');
            }
        }, { passive: true });

        window.setTimeout(function () {
            logIfDrift('delayed-3000', 'H46');
        }, 3000);
        window.setTimeout(function () {
            logIfDrift('delayed-6000', 'H46');
        }, 6000);
    }

    function s(el, prop, value) {
        if (el) {
            el.style.setProperty(prop, value, 'important');
        }
    }

    function clearBg(el) {
        if (!el || !el.style) {
            return;
        }

        [
            'background-image',
            'background-position',
            'background-position-x',
            'background-position-y',
            'background-size',
            'background-repeat',
            'background-attachment',
            'background-origin',
            'background-clip'
        ].forEach(function (prop) {
            var value = (el.style.getPropertyValue(prop) || '').trim();
            if (value === '') {
                el.style.removeProperty(prop);
            }
        });
    }

    function surface(el, color) {
        if (!el) {
            return;
        }

        el.style.removeProperty('background');
        clearBg(el);
        s(el, 'background-color', color);
        s(el, 'background-image', 'none');
    }

    function f(trigger) {
        var isMobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
        var isDesktop = window.matchMedia && window.matchMedia('(min-width: 992px)').matches;
        /* r64: content-driven — locks 566/339/111 impediam densificar o footer */
        var containerMinHeight = '0';
        var newsletterMinHeight = '0';
        var atendimentoMinHeight = '0';
// Não forçar estilos inline no shell do footer/trust bar.
        // Mantemos essas superfícies controladas por CSS para evitar drift visual
        // e style="" inflado no DOM.
        document.querySelectorAll('footer.page-footer,.page-footer').forEach(function (el) {
            [
                'background',
                'background-color',
                'background-image',
                'color',
                'min-height',
                'height',
                'max-height',
                'overflow',
                'overflow-x',
                'overflow-y',
                'padding-block',
                'padding-inline',
                'padding-left',
                'padding-right',
                'padding',
                'margin-top',
                'margin-bottom',
                'margin-left',
                'margin-right',
                'margin-inline',
                'max-width',
                'width'
            ].forEach(function (prop) { el.style.removeProperty(prop); });
            s(el, 'max-width', 'none');
            s(el, 'width', '100%');
            s(el, 'margin-left', '0');
            s(el, 'margin-right', '0');
            s(el, 'margin-inline', '0');
            s(el, 'padding-left', '0');
            s(el, 'padding-right', '0');
            s(el, 'padding-inline', '0');
            s(el, 'box-sizing', 'border-box');
        });
        document.querySelectorAll('footer.page-footer > .page_footer, .page-footer > .page_footer').forEach(function (el) {
            s(el, 'max-width', 'none');
            s(el, 'width', '100%');
            s(el, 'margin-left', '0');
            s(el, 'margin-right', '0');
            s(el, 'margin-inline', '0');
            s(el, 'padding-left', '0');
            s(el, 'padding-right', '0');
            s(el, 'padding-inline', '0');
            s(el, 'box-sizing', 'border-box');
        });
        document.querySelectorAll('.page_footer .awa-footer-trust-bar,.page-footer .awa-footer-trust-bar').forEach(function (el) {
            [
                'background',
                'background-color',
                'background-image',
                'color',
                'display',
                'padding-block',
                'margin-bottom'
            ].forEach(function (prop) { el.style.removeProperty(prop); });
            if (!(el.getAttribute('style') || '').trim()) {
                el.removeAttribute('style');
            }
        });

        document.querySelectorAll('footer.page-footer > .page_footer, .page-footer > .page_footer').forEach(function (el) {
            s(el, 'margin-top', '16px');
            s(el, 'margin-bottom', '0');
        });

        document.querySelectorAll('.page_footer .awa-footer-section__toggle,.page-footer .awa-footer-section__toggle,.page_footer .velaContent a,.page-footer .velaContent a').forEach(function (el) {
            s(el, 'color', '#333333');
            s(el, '-webkit-text-fill-color', '#333333');
        });

        document.querySelectorAll('.page_footer #footer,.page-footer #footer,.page_footer .footer-container,.page-footer .footer-container').forEach(function (el) {
            surface(el, 'transparent');
            s(el, 'color', 'var(--awa-text,CanvasText)');
            s(el, 'min-height', containerMinHeight);
            s(el, 'height', 'auto');
        });

        document.querySelectorAll('.page_footer .awa-footer-newsletter,.page-footer .awa-footer-newsletter').forEach(function (el) {
            s(el, 'min-height', newsletterMinHeight);
            s(el, 'height', 'auto');
        });

        document.querySelectorAll('.page_footer .awa-footer-atendimento,.page-footer .awa-footer-atendimento').forEach(function (el) {
            s(el, 'min-height', atendimentoMinHeight);
            s(el, 'height', 'auto');
        });

        document.querySelectorAll('.page_footer .awa-footer-devby,.page-footer .awa-footer-devby').forEach(function (el) {
            s(el, 'min-height', '0');
            s(el, 'height', 'auto');
        });

        document.querySelectorAll('.page_footer .vela-content,.page-footer .vela-content,.page_footer .velaFooterMenu,.page-footer .awa-footer-atendimento').forEach(function (el) {
            surface(el, 'transparent');
            s(el, 'color', 'var(--awa-text,CanvasText)');
            s(el, 'box-shadow', 'none');
        });

        document.querySelectorAll('.page_footer .velaFooterTitle,.page-footer .velaFooterLinks a,.page-footer .awa-footer-atendimento p,.page-footer .awa-footer-atendimento__label').forEach(function (el) {
            s(el, 'color', 'var(--awa-text,CanvasText)');
            s(el, '-webkit-text-fill-color', 'var(--awa-text,CanvasText)');
        });

        document.querySelectorAll('.page_footer .awa-footer-atendimento__store').forEach(function (el) {
            /* store-flat-r21b: flatten soft card — was var(--awa-bg) inline !important */
            surface(el, 'transparent');
            s(el, 'color', 'var(--awa-text,CanvasText)');
        });

        document.querySelectorAll('.page_footer .awa-footer-atendimento__store p,.page_footer .awa-footer-atendimento__store-name,.page_footer .awa-footer-atendimento__store-address').forEach(function (el) {
            s(el, 'color', 'var(--awa-text,CanvasText)');
            s(el, '-webkit-text-fill-color', 'var(--awa-text,CanvasText)');
        });

        document.querySelectorAll('.page_footer .footer-bottom,.page-footer .footer-bottom').forEach(function (el) {
            s(el, 'box-sizing', 'border-box');
            /* cat-white-r9: flat white, no soft card */
            surface(el, '#fff');
            s(el, 'border', '0');
            s(el, 'border-radius', '0');
            s(el, 'color', 'var(--awa-text,CanvasText)');
/* PIXEL-QA: preencher o shell; sem max-width/margin que recentralizam (+16). */
            s(el, 'margin-inline', '0');
            s(el, 'max-width', '100%');
            s(el, 'width', '100%');
            /* PIXEL-QA: eixo no footer.page-footer; sem pad horizontal aninhado. */
            s(el, 'padding-inline', '0');
            /* visible: hidden cortava copyright/disclaimer no mobile (H91) */
            s(el, 'overflow', 'visible');
            s(el, 'box-shadow', 'none');
        });

        document.querySelectorAll('.page_footer .footer-bottom .footer-bottom-inner,.page-footer .footer-bottom .footer-bottom-inner').forEach(function (el) {
            s(el, 'box-sizing', 'border-box');
            s(el, 'max-width', '100%');
            s(el, 'padding-inline', '0');
            s(el, 'width', '100%');
        });

        document.querySelectorAll('.products-grid .product-thumb').forEach(function (el) {
            s(el, 'position', 'relative');
            s(el, 'overflow', 'hidden');
        });

        document.querySelectorAll('.products-grid .quickview-link').forEach(function (el) {
            s(el, 'box-sizing', 'border-box');
            s(el, 'inline-size', '40px');
            s(el, 'block-size', '40px');
            s(el, 'max-width', '40px');
            s(el, 'min-width', '0');
            s(el, 'right', '0');
            s(el, 'inset-inline-end', '0');
        });
window.requestAnimationFrame(function () {
});
    }

    setupDebugObservers();

    if (document.readyState !== 'loading') {
        f('ready');
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            f('domcontentloaded');
        }, { once: true });
    }
    window.addEventListener('load', function () {
        f('load');
    }, { once: true, passive: true });
    window.setTimeout(function () {
        f('timeout-800');
    }, 800);
    window.setTimeout(function () {
        f('timeout-2400');
    }, 2400);
}());
