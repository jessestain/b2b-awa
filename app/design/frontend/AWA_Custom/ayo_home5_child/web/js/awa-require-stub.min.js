/**
 * Home — stub require/define até awa-home-bootstrap-defer.js carregar o bundle AMD.
 */
(function (w) {
    'use strict';

    if (w.__awaRequireStubInstalled) {
        return;
    }
    w.__awaRequireStubInstalled = true;

    w.__awaRequireQueue = w.__awaRequireQueue || [];
    w.__awaDefineQueue = w.__awaDefineQueue || [];
    w.__awaRunWhenRequireQueue = w.__awaRunWhenRequireQueue || [];

    function stubRequire() {
        w.__awaRequireQueue.push(Array.prototype.slice.call(arguments));
    }

    stubRequire._awaStub = true;
    stubRequire.config = function () {};
    stubRequire.undef = function () {};
    stubRequire.toUrl = function (id) {
        return String(id);
    };
    stubRequire.defined = function () {
        return false;
    };
    stubRequire.specified = function () {
        return false;
    };
    stubRequire.onError = function () {};
    stubRequire.version = '0.0.0-stub';

    w.require = stubRequire;
    w.define = function () {
        w.__awaDefineQueue.push(Array.prototype.slice.call(arguments));
    };
    w.define.amd = {};

    w.awaRunWhenRequire = function (fn, opts) {
        if (typeof fn !== 'function') {
            return;
        }
        if (typeof w.require === 'function' && !w.require._awaStub) {
            fn();
            return;
        }
        w.__awaRunWhenRequireQueue.push({
            fn: fn,
            key: opts && opts.key ? String(opts.key) : ''
        });
    };

    w.awaWhenRequire = w.awaRunWhenRequire;

    w.awaFlushRequireQueue = function () {
        if (typeof w.require !== 'function' || w.require._awaStub) {
            return false;
        }

        var realRequire = w.require;
        var realDefine = w.define;
        var reqQueue = w.__awaRequireQueue || [];
        var defQueue = w.__awaDefineQueue || [];
        var whenQueue = w.__awaRunWhenRequireQueue || [];

        w.__awaRequireQueue = [];
        w.__awaDefineQueue = [];
        w.__awaRunWhenRequireQueue = [];

        defQueue.forEach(function (args) {
            try {
                realDefine.apply(w, args);
            } catch (e) {
                /* ignore */
            }
        });

        reqQueue.forEach(function (args) {
            try {
                realRequire.apply(w, args);
            } catch (e) {
                /* ignore */
            }
        });

        whenQueue.forEach(function (item) {
            try {
                item.fn();
            } catch (e) {
                /* ignore */
            }
        });

        try {
            w.__awaBootstrapReady = true;
            w.dispatchEvent(new CustomEvent('awa-bootstrap-ready'));
        } catch (e) {
            /* IE11 fallback omitted — Magento 2.4 targets modern browsers */
        }

        return true;
    };

    function flushWhenDomReady() {
        if (typeof w.awaFlushRequireQueue === 'function') {
            w.awaFlushRequireQueue();
        }
    }

    var doc = w.document;
    if (doc) {
        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', flushWhenDomReady, { once: true });
        } else {
            flushWhenDomReady();
        }
    }
})(window);
