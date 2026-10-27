define([], function () {
    'use strict';
    var forms = new WeakMap();
    var nextFormId = 0;
    function normalize(value) { return String(value || '').trim().toLowerCase(); }

    function create(options) {
        options = options || {};
        var now = options.now || Date.now;
        var setTimer = options.setTimeout || setTimeout;
        var clearTimer = options.clearTimeout || clearTimeout;
        var listeners = [];
        var requests = Object.create(null);
        var timers = Object.create(null);
        var sequence = 0;
        var state = {
            id: options.id || 'awa-search-test', epoch: 0, query: '', context: '',
            open: false, owner: 'none', native: 'idle', enhanced: false,
            nativeExpected: false, startedAt: 0, selection: -1, fallbackFailed: false, composing: false,
            minLength: 2, mode: 'query', skuFailed: false
        };
        function snapshot() { return Object.assign({}, state); }
        function emit(reason) { listeners.slice().forEach(function (fn) { fn(snapshot(), reason); }); }
        function cancelTimer(kind) {
            var entry = timers[kind];
            delete timers[kind];
            if (entry) { (entry.cancel || clearTimer)(entry.handle); }
        }
        function cancelRequest(kind) {
            var entry = requests[kind];
            // Invalidate before abort: jQuery abort callbacks may be synchronous.
            delete requests[kind];
            if (entry && entry.abort) { try { entry.abort(); } catch (_abortError) { /* Already invalidated. */ } }
        }
        function cancelAll() {
            Object.keys(timers).forEach(cancelTimer);
            Object.keys(requests).forEach(cancelRequest);
        }
        function currentQuery(query, epoch) {
            return state.open && !state.composing && state.epoch === epoch && state.query === normalize(query);
        }
        function current(ticket) {
            var entry = ticket && requests[ticket.kind];
            return !!entry && entry.ticket === ticket && currentQuery(ticket.query, ticket.epoch)
                && !(ticket.kind !== 'native' && state.owner === 'native');
        }
        function reset(query, context, open) {
            state.epoch += 1;
            state.query = normalize(query);
            state.context = String(context || '');
            state.open = open;
            state.owner = 'none';
            state.native = state.nativeExpected ? 'waiting' : 'absent';
            state.startedAt = now();
            state.selection = -1;
            state.fallbackFailed = false;
            state.skuFailed = false;
            state.composing = false;
            cancelAll();
        }
        var api = {
            snapshot: snapshot,
            configure: function (configuration) {
                if (configuration.enhanced && !state.enhanced) {
                    state.enhanced = true;
                    cancelRequest('sku'); cancelTimer('sku');
                    if (state.owner === 'sku') { state.owner = 'none'; state.selection = -1; }
                    emit('enhanced-available');
                }
                if (configuration.nativeExpected) { state.nativeExpected = true; }
                if (Number(configuration.minLength) >= 1) { state.minLength = Number(configuration.minLength); }
            },
            begin: function (query, context, mode) {
                if (state.composing) { return snapshot(); }
                query = normalize(query);
                context = String(context || '');
                mode = mode || 'query';
                if (query.length < state.minLength && mode !== 'popular') {
                    if (state.open || state.query !== query) { reset(query, context, false); emit('clear'); }
                    return snapshot();
                }
                if (!state.open || state.query !== query || state.context !== context || state.mode !== mode) {
                    reset(query, context, true); state.mode = mode; emit('begin');
                }
                return snapshot();
            },
            close: function (reason) {
                reset(state.query, state.context, false);
                emit(reason || 'close');
            },
            beginComposition: function () {
                if (state.composing) { return; }
                reset(state.query, state.context, false);
                state.composing = true;
                emit('composition');
            },
            endComposition: function (query, context) {
                if (!state.composing) { return snapshot(); }
                state.composing = false;
                return api.begin(query, context);
            },
            subscribe: function (fn) {
                listeners.push(fn);
                return function () { listeners = listeners.filter(function (item) { return item !== fn; }); };
            },
            currentQuery: currentQuery,
            reserve: function (kind) {
                if (!state.open || state.composing || (state.mode === 'popular' && kind !== 'native') || (kind === 'sku' && state.enhanced)
                    || (kind !== 'native' && state.owner === 'native')) { return null; }
                cancelRequest(kind);
                var ticket = Object.freeze({kind: kind, epoch: state.epoch, query: state.query,
                    context: state.context, id: ++sequence});
                requests[kind] = {ticket: ticket, abort: null};
                if (kind === 'native') { state.native = 'loading'; }
                return ticket;
            },
            track: function (ticket, abort) {
                if (!current(ticket)) { if (abort) { abort(); } return false; }
                requests[ticket.kind].abort = abort;
                return true;
            },
            current: current,
            replaceNativeView: function (ticket) {
                if (!current(ticket) || ticket.kind !== 'native') { return false; }
                // A same-query render retains its generation, but the old ownership
                // commit cannot survive destruction of its DOM and block recovery.
                if (state.owner === 'native') { state.owner = 'none'; state.selection = -1; }
                state.native = 'loading';
                state.fallbackFailed = false;
                state.startedAt = now();
                emit('native-render');
                return current(ticket);
            },
            commit: function (ticket) {
                if (!current(ticket)) { return false; }
                if (ticket.kind === 'sku' && state.enhanced) { return false; }
                state.owner = ticket.kind;
                state.selection = -1;
                if (ticket.kind === 'native') {
                    state.native = 'ready';
                    ['fallback', 'sku'].forEach(function (kind) { cancelTimer(kind); cancelRequest(kind); });
                } else if (ticket.kind === 'fallback') {
                    // Preserve native token: a later valid native render must win.
                    cancelTimer('sku'); cancelRequest('sku');
                }
                emit('commit'); return true;
            },
            fail: function (ticket, reason) {
                if (!current(ticket) || reason === 'abort') { return false; }
                if (ticket.kind === 'native') {
                    state.native = reason === 'paint' ? 'paint-failed' : 'failed';
                    if (reason === 'paint' && state.owner === 'native') { state.owner = 'none'; state.selection = -1; }
                }
                if (ticket.kind === 'fallback') { state.fallbackFailed = true; }
                if (ticket.kind === 'sku') { state.skuFailed = true; }
                emit('failure'); return true;
            },
            finish: function (ticket) {
                var entry = ticket && requests[ticket.kind];
                if (!entry || entry.ticket !== ticket) { return false; }
                delete requests[ticket.kind]; emit('finish'); return true;
            },
            canFallback: function (wait) {
                return state.open && !state.composing && state.mode !== 'popular' && state.owner !== 'native' && !state.fallbackFailed
                    && (!state.nativeExpected || state.native === 'failed' || state.native === 'paint-failed'
                        || now() - state.startedAt >= wait);
            },
            fallbackDelay: function (wait, minimum) {
                if (!state.nativeExpected || state.native === 'failed' || state.native === 'paint-failed') { return minimum; }
                return Math.max(minimum, wait - (now() - state.startedAt));
            },
            schedule: function (kind, callback, delay) {
                cancelTimer(kind);
                var epoch = state.epoch, query = state.query;
                var entry = {};
                timers[kind] = entry;
                entry.handle = setTimer(function () {
                    if (timers[kind] !== entry || !currentQuery(query, epoch)) { return; }
                    delete timers[kind]; callback();
                }, delay);
            },
            scheduleTask: function (kind, callback, schedule, cancel) {
                cancelTimer(kind);
                var epoch = state.epoch, query = state.query;
                var entry = {cancel: cancel};
                timers[kind] = entry;
                entry.handle = schedule(function () {
                    if (timers[kind] !== entry || !currentQuery(query, epoch)) { return; }
                    delete timers[kind]; callback();
                });
            },
            cancelTimer: cancelTimer,
            select: function (direction, count) {
                if (!state.open || state.owner === 'none' || !count) { return -1; }
                state.selection = state.selection < 0
                    ? (direction > 0 ? 0 : count - 1)
                    : (state.selection + direction + count) % count;
                emit('selection'); return state.selection;
            }
        };
        return api;
    }

    function forForm(form) {
        if (!form || form.tagName !== 'FORM') { throw new Error('Search state requires its own form'); }
        if (!forms.has(form)) { forms.set(form, create({id: 'awa-search-' + (++nextFormId)})); }
        return forms.get(form);
    }
    function context(form) {
        var category = form.querySelector('[name="cat"]');
        return category ? String(category.value || '') : '';
    }
    function hosts(form) {
        var machine = forForm(form), id = machine.snapshot().id;
        function exclusive(selector, kind) {
            var nodes = Array.prototype.filter.call(form.querySelectorAll(selector), function (node) {
                return node.closest('form') === form;
            });
            // Do not silently pick one of duplicate hosts.
            if (nodes.length !== 1) { return null; }
            nodes[0].id = id + '-' + kind;
            nodes[0].setAttribute('data-awa-search-owner', kind);
            return nodes[0];
        }
        return {
            native: exclusive('.mst-searchautocomplete__autocomplete', 'native'),
            fallback: exclusive('.search-autocomplete:not(.mst-searchautocomplete__autocomplete), .searchsuite-autocomplete', 'fallback')
        };
    }
    function prepareForm(form) {
        form.style.setProperty('overflow', 'visible', 'important');
        Array.prototype.forEach.call(form.querySelectorAll('.field.search'), function (field) {
            if (field.closest('form') === form) { field.style.setProperty('overflow', 'visible', 'important'); }
        });
        form.setAttribute('data-awa-search-integrated', 'r4');
    }
    var focusBound = new WeakSet();
    function bindFocus(form) {
        if (focusBound.has(form)) { return; }
        focusBound.add(form);
        form.addEventListener('focusout', function () {
            setTimeout(function () {
                if (!form.contains(document.activeElement)) { forForm(form).close('focusout'); }
            }, 0);
        });
    }
    function syncAria(form) {
        var state = forForm(form).snapshot(), panels = hosts(form);
        var input = form.querySelector('input[name="q"]');
        if (!input) { return; }
        var panel = panels[state.owner];
        if (state.owner === 'sku') { panel = form.querySelector('.awa-sku-suggest'); }
        var open = !!(state.open && !state.composing && panel && !panel.hidden
            && panel.getAttribute('data-awa-query') === state.query
            && panel.getAttribute('data-awa-epoch') === String(state.epoch));
        input.setAttribute('aria-expanded', open ? 'true' : 'false');
        input.setAttribute('aria-busy', state.open && !state.composing && state.owner === 'none'
            && !state.fallbackFailed && !state.skuFailed && state.native !== 'paint-failed' && state.native !== 'failed' ? 'true' : 'false');
        if (open) { input.setAttribute('aria-controls', panel.id); }
        else { input.removeAttribute('aria-controls'); }
        if (!open || state.selection < 0) { input.removeAttribute('aria-activedescendant'); }
    }
    return {create: create, forForm: forForm, normalize: normalize, context: context, hosts: hosts,
        prepareForm: prepareForm, bindFocus: bindFocus, syncAria: syncAria};
});
