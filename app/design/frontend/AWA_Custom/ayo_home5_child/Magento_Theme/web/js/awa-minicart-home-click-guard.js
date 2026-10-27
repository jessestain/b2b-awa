/**
 * Home minicart click guard (sync external) — intercepta .showcart antes da navegacao.
 * Magento 2: parentClass/triggerClass = is-open. Close nativo via dropdowndialogclose.
 */
(function (w, d) {
    'use strict';
    if (w.__awaHomeMinicartClickGuard) {
        return;
    }
    w.__awaHomeMinicartClickGuard = true;

    var openPending = false;
    var warmPending = false;
    /* CartCheckout fix 2026-10-19: quando o dropdown fecha (Escape/X/clique fora),
       os retries pendentes do clique anterior (scheduleOpen/pollForReadyAndOpen)
       NÃO podem reabrir o painel; e cliques SINTÉTICOS com alvo body/html
       (awa-panel-exclusive closeOthers) não podem forçar abertura. */
    var closeRequested = false;

    function isDropdownReady() {
        var jq = w.jQuery;
        if (!jq || !jq.fn || typeof jq.fn.dropdownDialog !== 'function') {
            return false;
        }
        var $panel = jq('[data-block="minicart"] [data-role="dropdownDialog"]');
        return !!($panel.length && $panel.data('mageDropdownDialog'));
    }

    function tryOpenDropdown() {
        var jq = w.jQuery;
        if (!jq) {
            return false;
        }
        if (closeRequested) {
            openPending = false;
            return false;
        }
        var $panel = jq('[data-block="minicart"] [data-role="dropdownDialog"]');
        if ($panel.length && $panel.data('mageDropdownDialog')) {
            $panel.dropdownDialog('open');
            openPending = false;
            bindMagentoDropdownClose();
            if (typeof w.require === 'function') {
                w.require(['js/awa-minicart-position'], function (minicartPosition) {
                    if (minicartPosition && typeof minicartPosition.scheduleCenterOpenMinicartPanel === 'function') {
                        minicartPosition.scheduleCenterOpenMinicartPanel($panel.get(0));
                    }
                });
            }
            return true;
        }
        return false;
    }

    function openDropdownWithRetry(maxAttempts) {
        var attempts = 0;

        if (openPending) {
            return;
        }

        openPending = true;

        function attemptOpen() {
            attempts += 1;
            if (tryOpenDropdown()) {
                return;
            }
            if (attempts < maxAttempts) {
                w.setTimeout(attemptOpen, 75);
                return;
            }

            openPending = false;
        }

        attemptOpen();
    }

    function scheduleOpen(ready) {
        if (!ready || typeof ready.then !== 'function') {
            return;
        }
        ready.then(function () {
            openDropdownWithRetry(1);
        }, function () {
            openDropdownWithRetry(1);
        });
    }

    function pollForReadyAndOpen() {
        function retryOnce() {
            var ready = w.__awaMinicartUiReady;
            if (ready && typeof ready.then === 'function') {
                scheduleOpen(ready);
            }
        }
        if (w.requestAnimationFrame) {
            w.requestAnimationFrame(retryOnce);
        } else {
            w.setTimeout(retryOnce, 0);
        }
    }

    /**
     * Magento dropdownDialog só gere parentClass/triggerClass (is-open).
     * active/show/_active/--expanded são extras AWA que o widget não remove.
     * Não remover is-open do wrap/trigger aqui: o clique no .showcart ainda
     * precisa desse estado para o toggle nativo.
     */
    function stripAwaMinicartExtras() {
        var wrap = d.querySelector('[data-block="minicart"], .minicart-wrapper');
        var panel = wrap ? wrap.querySelector('.block-minicart') : d.querySelector('.block-minicart');
        var shell = d.querySelector('.awa-header-minicart');
        var trigger = wrap ? wrap.querySelector('.showcart, .action.showcart') : null;

        if (wrap) {
            wrap.classList.remove('active', 'show');
        }
        if (panel) {
            panel.classList.remove('_active', 'active', 'is-open');
            panel.setAttribute('aria-hidden', 'true');
        }
        if (shell) {
            shell.classList.remove('awa-header-minicart--expanded');
            shell.setAttribute('data-awa-minicart-expanded', '0');
        }
        if (trigger) {
            trigger.classList.remove('active', 'show');
        }
        d.documentElement.classList.remove('awa-minicart-overlay-active');
    }

    function onMagentoDropdownClose() {
        var wrap = d.querySelector('[data-block="minicart"], .minicart-wrapper');
        var trigger = wrap ? wrap.querySelector('.showcart, .action.showcart') : null;

        /* CartCheckout fix 2026-10-19: cancelar aberturas pendentes no fechamento */
        openPending = false;
        closeRequested = true;

        stripAwaMinicartExtras();
        if (wrap) {
            wrap.classList.remove('is-open');
        }
        if (trigger) {
            trigger.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }
    }

    function bindMagentoDropdownClose() {
        if (w.__awaMinicartMageCloseBound) {
            return;
        }

        function attach($) {
            if (!$ || !$.fn) {
                return false;
            }
            var $block = $('[data-block="minicart"] [data-role="dropdownDialog"]');
            if (!$block.length || !$block.data('mageDropdownDialog')) {
                return false;
            }
            w.__awaMinicartMageCloseBound = true;
            $block.on('dialogclose dropdowndialogclose', onMagentoDropdownClose);
            return true;
        }

        if (attach(w.jQuery)) {
            return;
        }
        if (typeof w.require === 'function') {
            w.require(['jquery'], attach);
        }
    }

    function forceMinicartOpenShell() {
        var shell = d.querySelector('.awa-header-minicart');
        var wrap = d.querySelector('[data-block="minicart"], .minicart-wrapper');
        var panel = d.querySelector('[data-block="minicart"] .block-minicart, .block-minicart');
        var trigger = wrap ? wrap.querySelector('.showcart, .action.showcart') : null;
        if (shell) {
            shell.classList.add('awa-header-minicart--expanded');
            shell.setAttribute('data-awa-minicart-expanded', '1');
        }
        if (wrap) {
            wrap.classList.add('is-open');
            wrap.setAttribute('data-awa-minicart-dropdown', '1');
        }
        if (trigger) {
            trigger.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
        }
        if (panel) {
            /* CartCheckout fix: panel-exclusive pode ter escondido o conteúdo
               com display:none inline; limpar para o shell abrir de novo. */
            panel.style.display = '';
            panel.setAttribute('aria-hidden', 'false');
        }
        d.documentElement.classList.add('awa-minicart-overlay-active');
        bindMagentoDropdownClose();
        if (typeof w.require === 'function') {
            w.require(['js/awa-minicart-position'], function (minicartPosition) {
                if (minicartPosition && typeof minicartPosition.scheduleCenterOpenMinicartPanel === 'function') {
                    minicartPosition.scheduleCenterOpenMinicartPanel(panel || d.querySelector('.block-minicart'));
                }
            });
        }
    }

    function onMinicartClick(event) {
        var target = event && event.target;
        var trigger = null;
        if (!target || typeof target.closest !== 'function') {
            return;
        }
        /* CartCheckout fix 2026-10-19: cliques SINTÉTICOS com alvo body/html
           (awa-panel-exclusive closeOthers) não devem forçar abertura. */
        if (event.isTrusted === false && (target === d.body || target === d.documentElement)) {
            return;
        }
        /* Clique real no trigger re-arma o ciclo (abrir de novo é intencional). */
        if (event.isTrusted !== false) {
            closeRequested = false;
        }
        if (target.closest('[data-role="awa-vertical-menu-trigger"], .title-category-dropdown, .awa-header-categories.menu_left_home1')) {
            return;
        }
        trigger = target.closest('[data-block="minicart"] .showcart, [data-block="minicart"] .action.showcart, .minicart-wrapper .showcart, .minicart-wrapper .action.showcart, .header .showcart, .header .action.showcart');
        if (!trigger) {
            return;
        }

        var wrapNow = d.querySelector('[data-block="minicart"], .minicart-wrapper');
        var alreadyOpen = !!(wrapNow && (wrapNow.classList.contains('active') || wrapNow.classList.contains('is-open') || wrapNow.classList.contains('show')));
        var hasUiReadyPromise = !!(w.__awaMinicartUiReady && typeof w.__awaMinicartUiReady.then === 'function');
        var uiInit = !!w.__awaMinicartUiInit;

        if (isDropdownReady()) {
            // Magento toggles; only recenter when opening.
            if (!alreadyOpen) {
                forceMinicartOpenShell();
            } else {
                stripAwaMinicartExtras();
            }
            bindMagentoDropdownClose();
            return;
        }

        if (uiInit || hasUiReadyPromise) {
            // Do not swallow the click if dropdown is not ready. Retry open once.
            if (typeof w.__awaHomeMinicartBoot === 'function') {
                w.__awaHomeMinicartBoot({ openAfterInit: true });
            }
            if (hasUiReadyPromise) {
                scheduleOpen(w.__awaMinicartUiReady);
            } else {
                pollForReadyAndOpen();
            }
            return;
        }

        // Cold path: try to open the shell, but let the native click through.
        forceMinicartOpenShell();
        w.__awaMinicartHomeBootPending = true;
        w.__awaMinicartOpenAfterInit = true;
        if (typeof w.__awaHomeMinicartBoot === 'function') {
            w.__awaHomeMinicartBoot({ openAfterInit: true });
        }
        if (w.__awaMinicartUiReady && typeof w.__awaMinicartUiReady.then === 'function') {
            scheduleOpen(w.__awaMinicartUiReady);
        } else {
            pollForReadyAndOpen();
        }
    }

    d.addEventListener('click', onMinicartClick, true);

    /* CartCheckout fix 2026-10-19: no caminho "shell" da home o dropdownDialog
       não é dono do estado, então o outside-close nativo não dispara. Fechar
       ao clicar FORA do minicart (clique real, fora do wrapper/drawer). */
    function onMinicartOutsideClick(event) {
        if (!event || event.isTrusted === false) {
            return;
        }
        var t = event.target;
        if (!t || typeof t.closest !== 'function') {
            return;
        }
        if (t.closest('[data-block="minicart"], .minicart-wrapper, .block-minicart, .awa-header-minicart')) {
            return;
        }
        var wrapNow = d.querySelector('[data-block="minicart"], .minicart-wrapper');
        if (!wrapNow || !wrapNow.classList.contains('is-open')) {
            return;
        }
        closeRequested = true;
        openPending = false;
        onMagentoDropdownClose();
    }
    d.addEventListener('click', onMinicartOutsideClick, true);

    /* CartCheckout fix 2026-10-19: Escape fecha também no caminho "shell"
       (abertura via forceMinicartOpenShell, sem o widget dropdownDialog
       como dono do estado — nesse caminho o Escape nativo não fecha).
       Arma SEMPRE o closeRequested: durante a hidratação defer a classe
       is-open pode estar momentaneamente ausente e o re-open tardio ficaria
       sem watchdog (falha intermitente medida em ~1 de 3 runs). */
    d.addEventListener('keydown', function (e) {
        if (!e || e.key !== 'Escape') {
            return;
        }
        closeRequested = true;
        openPending = false;
        var wrapNow = d.querySelector('[data-block="minicart"], .minicart-wrapper');
        if (wrapNow && wrapNow.classList.contains('is-open')) {
            onMagentoDropdownClose();
        }
    }, true);

    /* CartCheckout fix 2026-10-19 (watchdog): a hidratação defer da home pode
       SUBSTITUIR o nó .minicart-wrapper por um novo já com is-open (medido:
       remove 3x no Escape e o nó volta aberto ~600ms depois, sem classList.add
       no nó antigo). Enquanto closeRequested estiver ativo (fechamento por
       Escape/X sem clique real posterior), re-aplicar o estado fechado no nó
       atual. Um clique REAL no trigger re-arma o ciclo. */
    function closeWatchdog() {
        if (!closeRequested) {
            return;
        }
        var wrapNow = d.querySelector('[data-block="minicart"], .minicart-wrapper');
        if (wrapNow && wrapNow.classList.contains('is-open')) {
            onMagentoDropdownClose();
        }
    }
    w.setInterval(closeWatchdog, 250);

    // Warm: só marca pending + preload position AMD. NÃO boot KO no hover
    // (GTmetrix/TBT — hidratação completa só no clique via onMinicartClick).
    function onMinicartWarm(event) {
        var target = event && event.target;
        if (warmPending || w.__awaMinicartUiInit) {
            return;
        }
        if (!target || typeof target.closest !== 'function') {
            return;
        }
        if (!target.closest('[data-block="minicart"], .awa-header-minicart, .minicart-wrapper .showcart, .action.showcart')) {
            return;
        }
        warmPending = true;
        w.__awaMinicartHomeWarmPending = true;
        if (typeof w.require === 'function') {
            w.require(['js/awa-minicart-position'], function () { /* warm AMD cache */ });
        }
        // Não chamar __awaHomeMinicartBoot aqui — evita waterfall KO sem clique.
    }
    d.addEventListener('mouseover', onMinicartWarm, true);
    d.addEventListener('pointerdown', onMinicartWarm, true);

    function bindWarmToShowcart() {
        var nodes = d.querySelectorAll(
            '[data-block="minicart"] .showcart, .minicart-wrapper .action.showcart, .awa-header-minicart .showcart'
        );
        var i;
        var el;

        for (i = 0; i < nodes.length; i += 1) {
            el = nodes[i];
            if (el.getAttribute('data-awa-minicart-warm') === '1') {
                continue;
            }
            el.setAttribute('data-awa-minicart-warm', '1');
            el.addEventListener('mouseenter', onMinicartWarm, { passive: true });
            el.addEventListener('pointerenter', onMinicartWarm, { passive: true });
            el.addEventListener('focus', onMinicartWarm, { passive: true });
        }
    }
    [0, 300, 800, 1500, 3000].forEach(function (t) {
        w.setTimeout(bindWarmToShowcart, t);
    });

    // Keep onMinicartClick permanently: after uiInit it only schedules center
    // (no preventDefault). Removing it caused the 44px uncentered flash once warm.
    function removeWarmOnly() {
        if (w.__awaMinicartUiInit || warmPending) {
            d.removeEventListener('mouseover', onMinicartWarm, true);
            d.removeEventListener('pointerdown', onMinicartWarm, true);
        }
    }
    [2000, 5000, 10000].forEach(function (t) {
        w.setTimeout(removeWarmOnly, t);
    });
}(window, document));
