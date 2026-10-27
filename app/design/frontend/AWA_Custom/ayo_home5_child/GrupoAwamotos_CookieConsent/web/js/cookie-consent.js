define([], function () {
    'use strict';

    const STORAGE_KEY = 'awa_cookie_consent';
    const COOKIE_NAME = 'awa_cookies_accepted';
    const MAGENTO_COOKIE_NAME = 'user_allowed_save_cookie';
    const REOPEN_ID = 'awa-cookie-reopen';
    const COOKIE_DAYS = 365;
    let isInitialized = false;
    let isDelegatedClickBound = false;
    let isResizeBound = false;

    function setCookie(name, value, days) {
        let expires = '';
        if (days) {
            const date = new Date();
            date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        const nameEQ = name + '=';
        const cookies = document.cookie.split(';');
        for (let i = 0; i < cookies.length; i++) {
            const c = cookies[i].trim();
            if (c.indexOf(nameEQ) === 0) {
                return decodeURIComponent(c.substring(nameEQ.length));
            }
        }
        return null;
    }

    function hasConsented() {
        try {
            if (localStorage.getItem(STORAGE_KEY)) {
                return true;
            }
        } catch (e) {
            // localStorage bloqueado
        }
        return getCookie(COOKIE_NAME) !== null;
    }

    function saveConsent(value) {
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // silencia erros de localStorage
        }
        setCookie(COOKIE_NAME, value, COOKIE_DAYS);
        setCookie(MAGENTO_COOKIE_NAME, JSON.stringify({ 1: 1 }), COOKIE_DAYS);

        /* task-14: o ga.phtml (linha 76) e o Meta pixel head-idle.phtml (linha 103)
         * só liberam as tags DEPOIS deste evento; sem ele o consentimento só vale
         * na próxima navegação. Contrato copiado de
         * app/code/GrupoAwamotos/CookieConsent/view/frontend/web/js/cookie-consent.js:65. */
        try {
            window.dispatchEvent(new CustomEvent('awa:cookie-consent', { detail: { value: value } }));
        } catch (e) {
            // CustomEvent indisponível
        }
    }

    function hideMagentoNativeCookie() {
        const nativeBlock = document.getElementById('notice-cookie-block');
        if (nativeBlock) {
            nativeBlock.style.display = 'none';
            nativeBlock.setAttribute('aria-hidden', 'true');
        }
    }

    /* ── A2: botão permanente de reabrir preferências ────────────────────
     * LGPD Art. 8º §5 e Art. 18 — revogar precisa ser tão fácil quanto
     * consentir. Antes disto o banner sumia para sempre no primeiro clique.
     * Invariante: botão e banner nunca ficam visíveis ao mesmo tempo (ocupam
     * a mesma posição no canto inferior-esquerdo). */

    function getReopenButton() {
        return document.getElementById(REOPEN_ID);
    }

    function showReopenButton() {
        const button = getReopenButton();
        if (!button) {
            return;
        }

        button.removeAttribute('hidden');
        button.setAttribute('aria-hidden', 'false');

        /* Mesmo motivo do banner (ver task-14 em showBanner): o
         * `syncInitialInert` do Rokanthemes_Themeoption marca como `inert`
         * todo nó que nasce com `hidden`/`aria-hidden="true"`. Sem remover,
         * o botão aparece mas não recebe clique nem foco. */
        button.removeAttribute('inert');
        if ('inert' in button) {
            button.inert = false;
        }

        button.classList.add('awa-cookie-reopen--visible');
    }

    function hideReopenButton() {
        const button = getReopenButton();
        if (!button) {
            return;
        }

        if (document.activeElement === button) {
            button.blur();
        }

        button.classList.remove('awa-cookie-reopen--visible');
        button.setAttribute('aria-hidden', 'true');
        button.setAttribute('hidden', 'hidden');
    }

    function openPreferences() {
        hideReopenButton();

        /* Reabrir pode acontecer sem que o fluxo normal do banner tenha rodado
         * (quem já consentiu não passa por startBannerFlow). */
        if (!isResizeBound) {
            window.addEventListener('resize', syncBannerHeight);
            isResizeBound = true;
        }

        showBanner();
    }

    function applyConsent(value) {
        saveConsent(value);
        hideMagentoNativeCookie();
        hideBanner();
    }

    function syncBannerHeight() {
        const banner = document.getElementById('awa-cookie-banner');
        if (!banner || !banner.classList.contains('awa-cookie-banner--visible')) {
            document.documentElement.style.removeProperty('--awa-cookie-banner-height');
            document.documentElement.style.removeProperty('--awa-cookie-banner-top-gap');
            return;
        }

        /* A1: o banner virou card inferior-ESQUERDO. No desktop ele não ocupa
         * a faixa inferior inteira, então os flutuantes do canto direito (FAB
         * da IA, WhatsApp, back-to-top) NÃO devem mais ser empurrados — se a
         * altura continuasse sendo exposta, eles subiriam ~170px sem motivo.
         * Abaixo de 640px o card ocupa quase toda a largura, aí a compensação
         * continua valendo. */
        const spansViewport = !window.matchMedia
            || window.matchMedia('(max-width: 640px)').matches;

        requestAnimationFrame(function () {
            if (!banner.classList.contains('awa-cookie-banner--visible')) {
                return;
            }

            const style = document.documentElement.style;

            /* Contrato antigo, mantido: altura do card. Consumido pelo
             * cascade lock (.awa-whatsapp-float, #awa-back-to-top) e pelo
             * padding do .page-wrapper. */
            style.setProperty(
                '--awa-cookie-banner-height',
                (spansViewport ? banner.offsetHeight : 0) + 'px'
            );

            /* Contrato novo: distância do rodapé da viewport até a BORDA
             * SUPERIOR do card, + 12px de folga. Absorve bottom nav,
             * safe-area e qualquer override de posição (ex.: as regras de
             * body.b2b-auth-shell). É o que o FAB da IA consome para nunca
             * cobrir o consentimento.
             *
             * NÃO usar getBoundingClientRect() aqui: este rAF roda logo após
             * a classe --visible ser aplicada, com o translateY(100%) da
             * animação ainda em vigor, e o rect sairia 180px abaixo do lugar.
             * O `bottom` computado resolve o calc() (incluindo o bottom nav)
             * e é imune a transform. */
            const resolvedBottom = parseFloat(window.getComputedStyle(banner).bottom) || 0;
            const topGap = Math.round(resolvedBottom + banner.offsetHeight) + 12;

            style.setProperty(
                '--awa-cookie-banner-top-gap',
                (spansViewport ? topGap : 0) + 'px'
            );
        });
    }

    function setBannerActive(isActive) {
        if (document.body) {
            document.body.classList.toggle('awa-cookie-banner-active', isActive);
        }

        if (isActive) {
            syncBannerHeight();
            return;
        }

        document.documentElement.style.removeProperty('--awa-cookie-banner-height');
        document.documentElement.style.removeProperty('--awa-cookie-banner-top-gap');
    }

    function hideBanner() {
        const banner = document.getElementById('awa-cookie-banner');
        if (!banner) {
            setBannerActive(false);
            return;
        }

        let cleaned = false;
        const cleanup = function () {
            if (cleaned) {
                return;
            }
            cleaned = true;
            if (document.activeElement && banner.contains(document.activeElement)) {
                document.activeElement.blur();
            }
            banner.classList.remove('awa-cookie-banner--visible');
            banner.setAttribute('aria-hidden', 'true');
            banner.setAttribute('hidden', 'hidden');
            banner.style.display = 'none';
            banner.style.pointerEvents = 'none';
            setBannerActive(false);

            /* A2: com o card fechado e consentimento gravado, o botão de
             * revogar assume o canto. Sem consentimento (ex.: fechamento
             * programático) nada aparece. */
            if (hasConsented()) {
                showReopenButton();
            }
        };

        banner.classList.remove('awa-cookie-banner--visible');
        banner.addEventListener('transitionend', cleanup, { once: true });
        window.setTimeout(cleanup, 450);
    }

    function showBanner() {
        const banner = document.getElementById('awa-cookie-banner');
        if (!banner) {
            return;
        }

        /* A2: invariante — card e botão de revogar ocupam a mesma posição,
         * nunca ficam visíveis ao mesmo tempo. */
        hideReopenButton();

        banner.removeAttribute('hidden');
        banner.setAttribute('aria-hidden', 'false');
        banner.style.display = 'block';
        banner.style.pointerEvents = 'auto';

        /* task-14: o Rokanthemes_Themeoption/templates/html/header.phtml (linhas 256-275,
         * `syncInitialInert`) marca com `inert` todo nó com `hidden` ou
         * `aria-hidden="true"` — inclusive `#awa-cookie-banner[hidden]` (linha 260).
         * Como o banner nasce com os dois atributos, ele entra na página inerte; sem
         * remover aqui, o overlay fica visível, cobre a tela, mas NÃO recebe clique
         * nem foco: o clique atravessa e atinge o que está atrás (medido: clique real
         * em "Permitir cookies" caiu em DIV.awa-section-header__left e, em outra
         * rolagem, navegou para uma PDP). */
        banner.removeAttribute('inert');
        if ('inert' in banner) {
            banner.inert = false;
        }

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                banner.classList.add('awa-cookie-banner--visible');
                setBannerActive(true);
            });
        });
    }

    function bindButtonHandler(button, consentValue) {
        if (!button || button.getAttribute('data-awa-cookie-bound') === '1') {
            return;
        }

        button.setAttribute('data-awa-cookie-bound', '1');
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            applyConsent(consentValue);
        });
    }

    function bindReopenHandler() {
        const button = getReopenButton();
        if (!button || button.getAttribute('data-awa-cookie-bound') === '1') {
            return;
        }

        button.setAttribute('data-awa-cookie-bound', '1');
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openPreferences();
        });
    }

    function bindEvents() {
        bindButtonHandler(document.getElementById('awa-cookie-accept'), 'all');
        bindButtonHandler(document.getElementById('awa-cookie-decline'), 'essential');
        bindReopenHandler();

        if (isDelegatedClickBound) {
            return;
        }

        document.addEventListener('click', function (event) {
            const target = event.target && event.target.closest
                ? event.target.closest('#awa-cookie-accept, #awa-cookie-decline, #' + REOPEN_ID)
                : null;

            if (!target) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (target.id === 'awa-cookie-accept') {
                applyConsent('all');
                return;
            }

            if (target.id === 'awa-cookie-decline') {
                applyConsent('essential');
                return;
            }

            if (target.id === REOPEN_ID) {
                openPreferences();
            }
        }, true);
        isDelegatedClickBound = true;
    }

    return {
        init: function () {
            if (isInitialized) {
                return;
            }
            isInitialized = true;

            hideMagentoNativeCookie();

            /* A2: as ligações precisam existir nos DOIS caminhos — com e sem
             * consentimento — senão o botão de revogar nasce inerte para quem
             * já aceitou. bindEvents() é idempotente. */
            function startConsentedFlow() {
                bindEvents();
                hideBanner();
                showReopenButton();
            }

            if (hasConsented()) {
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', startConsentedFlow, { once: true });
                } else {
                    startConsentedFlow();
                }
                return;
            }

            function startBannerFlow() {
                bindEvents();
                if (!isResizeBound) {
                    window.addEventListener('resize', syncBannerHeight);
                    isResizeBound = true;
                }
                window.setTimeout(showBanner, 400);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', startBannerFlow, { once: true });
            } else {
                startBannerFlow();
            }
        }
    };
});
