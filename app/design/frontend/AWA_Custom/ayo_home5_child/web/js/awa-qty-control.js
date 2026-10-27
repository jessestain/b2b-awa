define([], function () {
    'use strict';

    function emit(root, type, detail) {
        if (!root || typeof root.dispatchEvent !== 'function') {
            return;
        }

        root.dispatchEvent(new CustomEvent(type, {
            bubbles: true,
            detail: detail || {}
        }));
    }

    function getPrecision(step) {
        let normalized = String(step || 1);
        let decimalIndex = normalized.indexOf('.');

        if (decimalIndex === -1) {
            return 0;
        }

        return normalized.slice(decimalIndex + 1).length;
    }

    function normalizeNumber(value, fallback) {
        let parsed = Number.parseFloat(value);

        return Number.isFinite(parsed) ? parsed : fallback;
    }

    /**
     * @param {HTMLInputElement} input
     * @param {HTMLElement} root
     * @param {string} triggerSelector
     */
    function syncTriggerState(input, root, triggerSelector) {
        let min = normalizeNumber(input.getAttribute('min'), 0);
        let current = normalizeNumber(input.value, min);
        let decrement = root.querySelector('[data-awa-direction="decrement"]');
        let atMin = current <= min;

        if (!decrement) {
            return;
        }

        decrement.disabled = atMin;
        decrement.setAttribute('aria-disabled', atMin ? 'true' : 'false');
    }

    return function (config, element) {
        let root = element;
        let options = config || {};
        let selectors = options.selectors || {};
        let flags = options.flags || {};
        let inputSelector = selectors.input || '[data-awa-role="qty-input"]';
        let triggerSelector = selectors.trigger || '[data-awa-role="qty-trigger"]';
        let input = root ? root.querySelector(inputSelector) : null;

        if (!root || root.nodeType !== 1 || root.getAttribute('data-awa-qty-bound') === 'true') {
            return;
        }

        root.setAttribute('data-awa-qty-bound', 'true');
        root.setAttribute('data-awa-component', 'awa-qty-control');

        if (input) {
            syncTriggerState(input, root, triggerSelector);
            input.addEventListener('input', function () {
                syncTriggerState(input, root, triggerSelector);
            });
            input.addEventListener('change', function () {
                syncTriggerState(input, root, triggerSelector);
            });
        }

        /**
         * Window capture runs before document capture listeners that call
         * stopImmediatePropagation (tooling overlays, aggressive header guards).
         * Scoping via root.contains keeps each stepper isolated.
         *
         * @param {MouseEvent} event
         */
        function onQtyTriggerClick(event) {
            let trigger;
            let min;
            let step;
            let precision;
            let current;
            let nextValue;
            let direction;
            let target = event && event.target;

            if (!target || typeof target.closest !== 'function') {
                return;
            }

            if (!root.isConnected) {
                window.removeEventListener('click', onQtyTriggerClick, true);
                return;
            }

            trigger = target.closest(triggerSelector);

            if (!trigger || !root.contains(trigger)) {
                return;
            }

            if (trigger.disabled) {
                return;
            }

            input = root.querySelector(inputSelector);
            if (!input) {
                emit(root, 'awa:qty-control:error', {
                    reason: 'missing-input'
                });
                return;
            }

            direction = trigger.getAttribute('data-awa-direction') === 'decrement' ? -1 : 1;
            min = normalizeNumber(input.getAttribute('min'), 0);
            step = normalizeNumber(input.getAttribute('step'), 1);
            precision = getPrecision(step);
            current = normalizeNumber(input.value, min);
            nextValue = current + (direction * step);

            if (nextValue < min) {
                nextValue = min;
            }

            input.value = precision > 0 ? nextValue.toFixed(precision) : String(Math.round(nextValue));

            if (flags.dispatchEvents !== false) {
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
            }

            syncTriggerState(input, root, triggerSelector);

            emit(root, 'awa:qty-control:change', {
                value: input.value
            });
        }

        window.addEventListener('click', onQtyTriggerClick, true);

        // Auditoria 2026-10-23 (teclado): Enter nao ativa o botao neste tema
        // (um guard global engole o default). Garante a ativacao por teclado
        // (Enter/NumpadEnter) alem do Space nativo.
        window.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') { return; }
            var target = event.target;
            if (!target || typeof target.closest !== 'function') { return; }
            var trigger = target.closest(triggerSelector);
            if (!trigger || !root.contains(trigger) || trigger.disabled) { return; }
            event.preventDefault();
            trigger.click();
        }, true);

        emit(root, 'awa:qty-control:ready');
    };
});
