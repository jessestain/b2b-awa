/* * SHIM TEMPORÁRIO de consentimento — task-15 — REMOVER quando o pipeline estático
 * for corrigido. Este arquivo é o texto que vai INLINE dentro do
 * `GrupoAwamotos_CookieConsent/templates/banner.phtml` (override do tema
 * AWA_Custom/ayo_home5_child). Ele fica em arquivo separado só para revisão e para
 * o teste de aceite consumir exatamente os mesmos bytes.
 *
 * POR QUE EXISTE (medido em 02/10/2026 — V-017, `03-backend-operacoes/aplicacao-fase1.md` §15.9/15.10)
 * A correção correta já está aplicada na FONTE do tema (remover `inert` no
 * `showBanner()` e disparar `awa:cookie-consent` no `saveConsent()`), mas não chega
 * ao navegador: `pub/static/frontend/AWA_Custom/ayo_home5_child/pt_BR/
 * GrupoAwamotos_CookieConsent/js/cookie-consent.min.js` é uma duplicata velha que
 * SOMBREIA a versão nova — o nginx só chama o materializador oficial quando o
 * arquivo não existe — e `static_content_on_demand_in_production` está NULL, então
 * remover a duplicata daria 404 ao visitante. `.phtml` é renderizado pelo PHP e não
 * passa por esse caminho, por isso o shim entra por aqui.
 *
 * CONTRATO — o shim faz DUAS coisas e só:
 *   (a) quando o banner ficar visível, remove o `inert` que o
 *       Rokanthemes_Themeoption/templates/html/header.phtml (linhas 256-275,
 *       `syncInitialInert`, linha 260) aplica no estado oculto — isso bloqueia
 *       clique E foco no overlay;
 *   (b) depois que o módulo GRAVAR o consentimento, dispara
 *       `window.dispatchEvent(new CustomEvent('awa:cookie-consent', { detail: { value } }))`,
 *       que é o evento de `app/code/GrupoAwamotos/CookieConsent/view/frontend/web/js/cookie-consent.js:65`
 *       e que `Magento_GoogleGtag/templates/ga.phtml:76` e
 *       `Meta_Conversion/templates/pixel/head-idle.phtml:103` escutam (ambos só agem
 *       quando `detail.value === 'all'`).
 * O shim NÃO grava chave nenhuma e NÃO decide o que é "all" ou "essential": isso é
 * do módulo `GrupoAwamotos_CookieConsent/js/cookie-consent` e já funciona.
 *
 * POR QUE O GATILHO É A CHAVE GRAVADA E NÃO O CLIQUE NO BOTÃO (medido)
 * O módulo registra um listener DELEGADO no `document` em fase de CAPTURA
 * (`cookie-consent.js:165`) que chama `stopPropagation()` — nenhum listener no botão
 * (captura ou bubble) chega a rodar. Disparar o evento antes da gravação também não
 * serve: `allowsMarketing()` ainda leria `null`, o `boot()` sairia sem marcar
 * `applied` e as tags não carregariam nunca nesta sessão. A chave gravada é
 * exatamente o efeito do clique, então observar a chave é fiel — e falha em silêncio
 * se o módulo não gravar nada.
 *
 * IDEMPOTÊNCIA: guarda global (`__awaConsentShim`), flag de evento emitido (e
 * ouvinte do próprio evento, para nunca duplicar se o módulo voltar a disparar),
 * remoção do `inert` feita uma única vez. Se `#awa-cookie-banner` não existir na
 * página, o shim não faz absolutamente nada.
 */
(function (w, d) {
    'use strict';

    if (w.__awaConsentShim) {
        return;
    }
    w.__awaConsentShim = true;

    var banner = d.getElementById('awa-cookie-banner');
    if (!banner) {
        return;
    }

    var STORAGE_KEY = 'awa_cookie_consent';
    var COOKIE_NAME = 'awa_cookies_accepted';
    var EVENT_NAME = 'awa:cookie-consent';

    var inertRemovido = false;
    var eventoEmitido = false;
    var vigia = null;

    /* Se o módulo (corrigido) voltar a disparar o evento, o shim não duplica. */
    w.addEventListener(EVENT_NAME, function () {
        eventoEmitido = true;
        if (vigia) {
            w.clearInterval(vigia);
            vigia = null;
        }
    });

    function bannerVisivel() {
        if (!banner || banner.hasAttribute('hidden')) {
            return false;
        }
        var cs = w.getComputedStyle(banner);
        return cs.display !== 'none' && cs.visibility !== 'hidden';
    }

    function valorGravado() {
        var valor = null;
        try {
            valor = w.localStorage && w.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            valor = null;
        }
        if (valor) {
            return valor;
        }
        var m = /(?:^|;\s*)awa_cookies_accepted=([^;]+)/.exec(d.cookie || '');
        return m ? decodeURIComponent(m[1]) : null;
    }

    function emitir(valor) {
        if (eventoEmitido || !valor) {
            return;
        }
        eventoEmitido = true;
        try {
            w.dispatchEvent(new w.CustomEvent(EVENT_NAME, { detail: { value: valor } }));
        } catch (e) {
            try {
                w.dispatchEvent(new w.Event(EVENT_NAME));
            } catch (e2) {
                /* ambiente sem CustomEvent: o módulo também silencia aqui */
            }
        }
    }

    function vigiarGravacao() {
        if (vigia || eventoEmitido) {
            return;
        }
        vigia = w.setInterval(function () {
            var valor = valorGravado();
            if (!valor) {
                return;
            }
            w.clearInterval(vigia);
            vigia = null;
            emitir(valor);
        }, 250);
    }

    /* FIX 12/10/2026 (TBT/INP — mesmo padrao do minicart): a guarda `inertRemovido`
     * estava DEPOIS da leitura CARA (`bannerVisivel` = getComputedStyle, medido em 296ms)
     * e o MutationObserver NUNCA era desconectado => CADA mutacao do banner (class/style/
     * aria-hidden — e o proprio banner muda de classe ao aparecer) pagava um recalculo
     * forcado. Perfil: bannerVisivel 296ms + sincronizar 279ms + as leituras do observer.
     * Agora: guarda BARATA primeiro; e o observer e desconectado quando o trabalho acaba. */
    var observer = null;

    function sincronizar() {
        if (inertRemovido) {
            return;
        }

        if (!bannerVisivel()) {
            return;
        }

        banner.removeAttribute('inert');
        if ('inert' in banner) {
            banner.inert = false;
        }
        if (banner.getAttribute('aria-hidden') === 'true') {
            banner.setAttribute('aria-hidden', 'false');
        }
        inertRemovido = true;

        vigiarGravacao();

        if (observer) {
            try { observer.disconnect(); } catch (e) { /* segue o fluxo */ }
            observer = null;
        }
    }

    sincronizar();

    if (w.MutationObserver) {
        observer = new w.MutationObserver(sincronizar);
        observer.observe(banner, {
            attributes: true,
            attributeFilter: ['class', 'hidden', 'style', 'aria-hidden', 'inert']
        });
    }
}(window, document));

