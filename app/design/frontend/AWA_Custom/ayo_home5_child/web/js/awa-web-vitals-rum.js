(function (w, d, p) {
    'use strict';

    if (w.__awaWebVitalsRumInit) {
        return;
    }
    w.__awaWebVitalsRumInit = true;

    var supported = (w.PerformanceObserver && w.PerformanceObserver.supportedEntryTypes)
        ? w.PerformanceObserver.supportedEntryTypes
        : [];
    var sent = {};
    var clsValue = 0;
    var lcpEntry = null;
    var inpValue = 0;
    var inpSource = 'event';
    var pagePath = (w.location && w.location.pathname) ? w.location.pathname : '/';
    var lowerPath = (pagePath || '').toLowerCase();
    var pageKind = (lowerPath === '/' || lowerPath === '/index.php') ? 'home' : 'internal';
    var navEntry = (p && typeof p.getEntriesByType === 'function') ? p.getEntriesByType('navigation')[0] : null;
    var finalized = false;

    function round(value, decimals) {
        var factor;

        if (typeof value !== 'number' || !isFinite(value)) {
            return null;
        }

        factor = Math.pow(10, decimals || 0);

        return Math.round(value * factor) / factor;
    }

    function getRating(name, value) {
        if (name === 'LCP') {
            return value <= 2500 ? 'good' : (value <= 4000 ? 'needs-improvement' : 'poor');
        }
        if (name === 'CLS') {
            return value <= 0.1 ? 'good' : (value <= 0.25 ? 'needs-improvement' : 'poor');
        }
        if (name === 'INP') {
            return value <= 200 ? 'good' : (value <= 500 ? 'needs-improvement' : 'poor');
        }
        if (name === 'FCP') {
            return value <= 1800 ? 'good' : (value <= 3000 ? 'needs-improvement' : 'poor');
        }
        if (name === 'TTFB') {
            return value <= 800 ? 'good' : (value <= 1800 ? 'needs-improvement' : 'poor');
        }

        return 'unknown';
    }

    function pushVital(name, value, extra) {
        var payload;
        var rounded = round(value, name === 'CLS' ? 4 : 0);

        if (rounded === null || sent[name]) {
            return;
        }
        sent[name] = true;

        w.dataLayer = w.dataLayer || [];

        payload = {
            event: 'awa_web_vital',
            web_vital_name: name,
            web_vital_value: rounded,
            web_vital_rating: getRating(name, rounded),
            web_vital_page_path: pagePath,
            web_vital_page_kind: pageKind
        };

        if (extra && typeof extra === 'object') {
            if (extra.id) {
                payload.web_vital_id = extra.id;
            }
            if (extra.delta !== undefined && extra.delta !== null) {
                payload.web_vital_delta = round(extra.delta, name === 'CLS' ? 4 : 0);
            }
            if (extra.navigationType) {
                payload.web_vital_navigation_type = extra.navigationType;
            }
            if (extra.source) {
                payload.web_vital_source = extra.source;
            }
        }

        try {
            w.dataLayer.push(payload);
        } catch (e) {
            // no-op: telemetria não pode afetar a renderização
        }
    }

    function finalizeVitals() {
        if (finalized) {
            return;
        }
        finalized = true;

        if (lcpEntry && typeof lcpEntry.startTime === 'number') {
            pushVital('LCP', lcpEntry.startTime, { id: lcpEntry.id });
        }
        pushVital('CLS', clsValue);

        if (inpValue > 0) {
            pushVital('INP', inpValue, { source: inpSource });
        }
    }

    if (navEntry && typeof navEntry.responseStart === 'number') {
        pushVital('TTFB', navEntry.responseStart, { navigationType: navEntry.type || 'navigate' });
    }

    if (supported.indexOf('paint') !== -1) {
        try {
            (new w.PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (entry && entry.name === 'first-contentful-paint') {
                        pushVital('FCP', entry.startTime, { id: entry.name });
                    }
                });
            })).observe({ type: 'paint', buffered: true });
        } catch (e) {
            // ignore
        }
    }

    if (supported.indexOf('largest-contentful-paint') !== -1) {
        try {
            (new w.PerformanceObserver(function (list) {
                var entries = list.getEntries();
                if (entries.length) {
                    lcpEntry = entries[entries.length - 1];
                }
            })).observe({ type: 'largest-contentful-paint', buffered: true });
        } catch (e) {
            // ignore
        }
    }

    if (supported.indexOf('layout-shift') !== -1) {
        try {
            (new w.PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (entry && !entry.hadRecentInput) {
                        clsValue += entry.value;
                    }
                });
            })).observe({ type: 'layout-shift', buffered: true });
        } catch (e) {
            // ignore
        }
    }

    if (supported.indexOf('event') !== -1) {
        try {
            (new w.PerformanceObserver(function (list) {
                list.getEntries().forEach(function (entry) {
                    if (entry && entry.interactionId && entry.duration > inpValue) {
                        inpValue = entry.duration;
                    }
                });
            })).observe({ type: 'event', buffered: true, durationThreshold: 40 });
        } catch (e) {
            // ignore
        }
    } else if (supported.indexOf('first-input') !== -1) {
        inpSource = 'first-input';
        try {
            (new w.PerformanceObserver(function (list) {
                var firstInput = list.getEntries()[0];
                if (firstInput && firstInput.duration > inpValue) {
                    inpValue = firstInput.duration;
                }
            })).observe({ type: 'first-input', buffered: true });
        } catch (e) {
            // ignore
        }
    }

    w.addEventListener('pagehide', finalizeVitals, { capture: true, once: true });
    d.addEventListener('visibilitychange', function () {
        if (d.visibilityState === 'hidden') {
            finalizeVitals();
        }
    }, { capture: true, once: true });
    w.addEventListener('load', function () {
        w.setTimeout(finalizeVitals, 15000);
    }, { once: true });
})(window, document, window.performance || null);
