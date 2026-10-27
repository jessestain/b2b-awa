(function () {
    'use strict';
    /* B4/E12 (CICLO 4, 18/10/2026) — FONTE ÚNICA dos props inline do breadcrumb.
       Consolida os 3 writers legados: (1) este script (mobile, existente desde
       AWA-TAP-CRUMB-MOBILE 2026-10-05), (2) o bloco crumbProps de
       awa-ux-enhancements.js e (3) o crumbApply de awa-touch-44-terminal-js.js
       (os dois blocos concorrentes foram REMOVIDOS). Regras preservadas:
       - mobile (<768px): alvo de toque 44px (WCAG 2.5.8) + line-height 1.4;
       - desktop (>=768px): compacto (min-height 0, padding-block 2px).
       Sem leitura de layout por frame; reaplica em DOMContentLoaded/load/300/1200ms,
       resize/orientationchange e travessia do breakpoint.
       Desligamento sem deploy: basta remover a tag <script> deste arquivo
       (Magento_Theme/templates/html/awa-head-preload.phtml) — o CSS do tema
       (critical-home) mantém o layout base. */
    var SEL = '.breadcrumbs a, .nav-breadcrumbs a';
    var CHAIN = '.breadcrumbs ul.items, .nav-breadcrumbs ul.items, .breadcrumbs ul.items > .item, .nav-breadcrumbs ul.items > .item';
    var PROPS_M = { 'display': 'inline-flex', 'align-items': 'center', 'min-height': '44px',
        'min-block-size': '44px', 'height': 'auto', 'max-height': 'none',
        'line-height': '1.4', 'padding-block': '0', 'padding-top': '0', 'padding-bottom': '0',
        'overflow': 'visible', 'box-sizing': 'border-box' };
    var CHAIN_M = { 'min-height': '44px', 'height': 'auto', 'max-height': 'none',
        'overflow': 'visible', 'align-items': 'center' };
    var PROPS_D = { 'display': 'inline-flex', 'align-items': 'center', 'min-height': '0',
        'min-block-size': '0', 'height': 'auto', 'padding-block': '2px', 'box-sizing': 'border-box' };
    var CHAIN_D = { 'min-height': '0', 'height': 'auto', 'max-height': 'none' };
    var KEYS_M = ['display', 'align-items', 'min-height', 'min-block-size', 'height', 'max-height',
        'line-height', 'padding-block', 'padding-top', 'padding-bottom', 'overflow', 'box-sizing'];
    var KEYS_D = ['display', 'align-items', 'min-height', 'min-block-size', 'height', 'max-height',
        'line-height', 'padding-block', 'padding-top', 'padding-bottom', 'overflow', 'box-sizing'];
    function mobile() {
        try { return !window.matchMedia('(min-width: 768px)').matches; } catch (e) { return window.innerWidth < 768; }
    }
    function limpaNos(nos, keys) {
        var i, k;
        for (i = 0; i < nos.length; i++) {
            for (k = 0; k < keys.length; k++) { nos[i].style.removeProperty(keys[k]); }
        }
    }
    function aplicaNos(nos, props) {
        var i, k;
        for (i = 0; i < nos.length; i++) {
            for (k in props) { if (Object.prototype.hasOwnProperty.call(props, k)) {
                nos[i].style.setProperty(k, props[k], 'important'); } }
        }
    }
    function roda() {
        var mob = mobile();
        var nos = document.querySelectorAll(SEL);
        var cadeia = document.querySelectorAll(CHAIN);
        if (mob) {
            limpaNos(nos, KEYS_D);
            aplicaNos(nos, PROPS_M);
            limpaNos(cadeia, KEYS_D);
            aplicaNos(cadeia, CHAIN_M);
        } else {
            limpaNos(nos, KEYS_M);
            aplicaNos(nos, PROPS_D);
            limpaNos(cadeia, KEYS_M);
            aplicaNos(cadeia, CHAIN_D);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', roda);
    } else { roda(); }
    window.addEventListener('load', roda);
    // reaplica depois de escritores tardios de terceiros (ex.: touch-44-terminal no rAF)
    setTimeout(roda, 300);
    setTimeout(roda, 1200);
    // e quando a viewport cruza o breakpoint
    try {
        var mq = window.matchMedia('(min-width: 768px)');
        var h = function () { roda(); };
        if (mq.addEventListener) { mq.addEventListener('change', h); } else if (mq.addListener) { mq.addListener(h); }
    } catch (e) {}
    // Alvo de toque do link do cookie banner (WCAG 2.5.8).
    // A SEÇÃO 51.32 do design system zera padding/min-height dele para caber
    // inline no paragrafo — mas isso deixa a area clicavel em 134x18px.
    // padding-block em elemento inline ESTENDE a area clicavel sem quebrar o
    // fluxo do texto (nao troca display, entao a quebra de linha continua igual).
    // 4px em cima + 4px embaixo levam os 18px para >=24px.
    function ajustaCookieLink() {
        var links = document.querySelectorAll('#awa-cookie-banner .awa-cookie-banner__link, .awa-cookie-banner__link');
        for (var i = 0; i < links.length; i++) {
            links[i].style.setProperty('padding-block', '4px', 'important');
            links[i].style.setProperty('padding-top', '4px', 'important');
            links[i].style.setProperty('padding-bottom', '4px', 'important');
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ajustaCookieLink);
    } else { ajustaCookieLink(); }
    window.addEventListener('load', ajustaCookieLink);
    setTimeout(ajustaCookieLink, 1500);

    window.addEventListener('resize', roda, { passive: true });
    window.addEventListener('orientationchange', roda, { passive: true });
})();
