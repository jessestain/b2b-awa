(function (w, d) {
    'use strict';

    function isFooterMobile() {
        return typeof w.matchMedia === 'function' && w.matchMedia('(max-width: 767px)').matches;
    }

    function syncFooterToggleAria() {
        var mobile = isFooterMobile();
        d.querySelectorAll('button.awa-footer-section__toggle').forEach(function (btn) {
            if (!mobile && btn.getAttribute('aria-expanded') !== 'true') {
                btn.setAttribute('aria-expanded', 'true');
            }
            if (mobile && btn.getAttribute('aria-expanded') !== 'true') {
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function syncFooterCategoriesShell() {
        var mobile = isFooterMobile();

        d.querySelectorAll('[data-awa-categories-toggle]').forEach(function (btn) {
            var panelId = btn.getAttribute('aria-controls');
            var panel = panelId ? d.getElementById(panelId) : null;

            if (!panel) {
                return;
            }

            if (mobile) {
                if (btn.getAttribute('aria-expanded') !== 'true') {
                    btn.setAttribute('aria-expanded', 'false');
                    btn.classList.remove('is-expanded');
                    panel.setAttribute('aria-hidden', 'true');
                }
                return;
            }

            btn.setAttribute('aria-expanded', 'true');
            btn.classList.add('is-expanded');
            panel.removeAttribute('hidden');
            panel.removeAttribute('inert');
            panel.setAttribute('aria-hidden', 'false');
        });
    }

    syncFooterToggleAria();
    syncFooterCategoriesShell();
    if (typeof w.matchMedia === 'function') {
        var mq = w.matchMedia('(max-width: 767px)');
        var onBreakpoint = function () {
            syncFooterToggleAria();
            syncFooterCategoriesShell();
        };
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onBreakpoint);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onBreakpoint);
        }
    }

    var bootStarted = false;

    function bootFooterInteractions() {
        if (bootStarted || w.__awaFooterInteractionsHomeInit) {
            return;
        }

        var footer = d.querySelector('.page_footer, .page-footer');
        var cfgNode = d.getElementById('awa-footer-interactions-config-json');
        if (!footer || !cfgNode || !cfgNode.textContent) {
            return;
        }

        bootStarted = true;

        var run = function () {
            if (footer.getAttribute('data-awa-footer-interactions-boot') === '1') {
                return;
            }

            w.require(['awaFooterInteractions'], function (footerInteractions) {
                var config;
                try {
                    config = JSON.parse(cfgNode.textContent);
                } catch (e) {
                    return;
                }

                footer.setAttribute('data-awa-footer-interactions-boot', '1');
                footerInteractions(config, footer);
            });
        };

        if (typeof w.awaRunWhenRequire === 'function') {
            w.awaRunWhenRequire(run, { key: 'footer-interactions-critical' });
        } else if (typeof w.require === 'function' && !w.require._awaStub) {
            run();
        } else {
            var pollAttempts = 0;
            var pollTimer = w.setInterval(function () {
                pollAttempts += 1;
                if (typeof w.require === 'function' && !w.require._awaStub) {
                    w.clearInterval(pollTimer);
                    run();
                    return;
                }
                if (pollAttempts >= 80) {
                    w.clearInterval(pollTimer);
                }
            }, 250);
        }
    }

    function scheduleFooterBoot() {
        var footer = d.querySelector('.page_footer, .page-footer');
        if (!footer) {
            return;
        }

        footer.addEventListener('click', function (evt) {
            if (!evt.target || !evt.target.closest) {
                return;
            }
            if (evt.target.closest('.awa-footer-section__toggle, .awa-footer-categories-expand__toggle')) {
                bootFooterInteractions();
            }
        }, { capture: true, passive: true });

        if ('IntersectionObserver' in w) {
            var observer = new w.IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    observer.disconnect();
                    bootFooterInteractions();
                });
            }, { rootMargin: '320px 0px 0px 0px' });
            observer.observe(footer);
            return;
        }

        bootFooterInteractions();
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', scheduleFooterBoot, { once: true });
    } else {
        scheduleFooterBoot();
    }
}(window, document));
/*§boot-r16d*/
