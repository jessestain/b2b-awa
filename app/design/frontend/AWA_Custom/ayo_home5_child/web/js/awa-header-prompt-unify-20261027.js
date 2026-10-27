/**
 * AWA 2026-10-27 â€” unificacao do prompt de conta (defeito C).
 * A cascata de locks da PLP/busca (ate 22x #html-body) impoe 12px/32px nos
 * links "Entrar/cadastre-se", divergindo de home/PDP (13px/16px).
 * Inline !important via CSSOM vence qualquer folha de estilo; aplicamos os
 * MESMOS valores ja vigentes em home/PDP (13px/1.2/auto/0 2px), tornando o
 * padrao identico em home/PLP/PDP/busca em >=992px.
 * Escopo: apenas os links textuais da line2 no estado "guest" (o icone fica
 * intacto). Reaplicacao idempotente cobre re-render do prompt pelo runtime.
 * Reversao: remover a <script> de awa-visual-qa-fixes-css.phtml e este arquivo.
 */
(function () {
    'use strict';

    var SELECTOR = '.awa-header-account-prompt[data-awa-auth-state="guest"]'
        + ' .awa-header-account-prompt__line2 .awa-header-account-prompt__link';

    function apply() {
        if (!window.matchMedia || !window.matchMedia('(min-width: 992px)').matches) {
            return;
        }
        var links = document.querySelectorAll(SELECTOR);
        for (var i = 0; i < links.length; i++) {
            var l = links[i];
            l.style.setProperty('font-size', '13px', 'important');
            l.style.setProperty('min-height', '0', 'important');
            l.style.setProperty('height', 'auto', 'important');
            l.style.setProperty('line-height', '1.2', 'important');
            l.style.setProperty('padding', '0 2px', 'important');
        }
    }

    [250, 1200, 2800, 5000].forEach(function (ms) {
        window.setTimeout(apply, ms);
    });
    window.addEventListener('load', apply);
    window.addEventListener('resize', apply);
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        apply();
    }
})();
