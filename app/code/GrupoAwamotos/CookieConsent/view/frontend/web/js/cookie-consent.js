define([], function () {
    'use strict';

    const STORAGE_KEY = 'awa_cookie_consent';
    const COOKIE_NAME = 'awa_cookies_accepted';
    const REOPEN_ID = 'awa-cookie-reopen';
    const COOKIE_DAYS = 365;

    function setCookie(name, value, days)
    {
        let expires = '';
        if (days) {
            const date = new Date();
            date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    function getCookie(name)
    {
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

    function readConsentValue()
    {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                return stored;
            }
        } catch (e) {
            // localStorage bloqueado (modo privado restrito)
        }
        return getCookie(COOKIE_NAME);
    }

    function hasConsented()
    {
        return readConsentValue() !== null;
    }

    /** Marketing (Pixel/GA) só com aceite completo — LGPD. */
    function allowsMarketing()
    {
        return readConsentValue() === 'all';
    }

    function saveConsent(value)
    {
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // silencia erros de localStorage
        }
        setCookie(COOKIE_NAME, value, COOKIE_DAYS);
        try {
            window.dispatchEvent(new CustomEvent('awa:cookie-consent', { detail: { value: value } }));
        } catch (e) {
            // CustomEvent indisponível
        }
    }

    function syncBannerHeight()
    {
        const banner = document.getElementById('awa-cookie-banner');
        if (!banner || !banner.classList.contains('awa-cookie-banner--visible')) {
            document.documentElement.style.removeProperty('--awa-cookie-banner-height');
            document.documentElement.style.removeProperty('--awa-cookie-banner-top-gap');
            return;
        }

        /* A1: o banner virou card inferior-ESQUERDO. No desktop ele não ocupa
         * a faixa inferior inteira, então os flutuantes do canto direito (FAB
         * da IA, WhatsApp, back-to-top) NÃO devem mais ser empurrados. Abaixo
         * de 640px o card ocupa quase toda a largura e a compensação vale. */
        const spansViewport = !window.matchMedia
            || window.matchMedia('(max-width: 640px)').matches;

        // Defer layout read — evita forced synchronous layout.
        requestAnimationFrame(function () {
            if (!banner.classList.contains('awa-cookie-banner--visible')) {
                return;
            }

            const style = document.documentElement.style;

            // Contrato antigo: altura do card.
            style.setProperty(
                '--awa-cookie-banner-height',
                (spansViewport ? banner.offsetHeight : 0) + 'px'
            );

            /* Contrato novo: rodapé da viewport até a BORDA SUPERIOR do card,
             * + 12px de folga. Absorve bottom nav e safe-area. É o que o FAB
             * da IA consome.
             *
             * NÃO usar getBoundingClientRect(): este rAF roda com o
             * translateY(100%) da animação ainda aplicado e o rect sairia
             * ~180px abaixo. O `bottom` computado resolve o calc() e é imune
             * a transform. */
            const resolvedBottom = parseFloat(window.getComputedStyle(banner).bottom) || 0;
            const topGap = Math.round(resolvedBottom + banner.offsetHeight) + 12;

            style.setProperty(
                '--awa-cookie-banner-top-gap',
                (spansViewport ? topGap : 0) + 'px'
            );
        });
    }

    function setBannerActive(isActive)
    {
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

    /* ── A2: botão permanente de reabrir preferências ────────────────────
     * LGPD Art. 8º §5 e Art. 18 — revogar precisa ser tão fácil quanto
     * consentir. Antes disto o banner sumia para sempre no primeiro clique.
     * Invariante: botão e banner nunca ficam visíveis ao mesmo tempo (ocupam
     * a mesma posição no canto inferior-esquerdo). */

    function getReopenButton()
    {
        return document.getElementById(REOPEN_ID);
    }

    function showReopenButton()
    {
        const button = getReopenButton();
        if (!button) {
            return;
        }
        button.removeAttribute('hidden');
        button.setAttribute('aria-hidden', 'false');
        button.removeAttribute('inert');
        if ('inert' in button) {
            button.inert = false;
        }
        button.classList.add('awa-cookie-reopen--visible');
    }

    function hideReopenButton()
    {
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

    function openPreferences()
    {
        hideReopenButton();
        showBanner();
    }

    function hideBanner()
    {
        const banner = document.getElementById('awa-cookie-banner');
        if (banner) {
            let cleaned = false;
            const cleanup = function () {
                if (cleaned) {
                    return;
                }
                cleaned = true;
                banner.style.display = 'none';
                setBannerActive(false);

                if (hasConsented()) {
                    showReopenButton();
                }
            };

            banner.classList.remove('awa-cookie-banner--visible');
            banner.addEventListener('transitionend', cleanup, { once: true });
            setTimeout(cleanup, 500);
        }
    }

    function showBanner()
    {
        const banner = document.getElementById('awa-cookie-banner');
        if (!banner) {
            return;
        }
        hideReopenButton();
        banner.style.display = 'block';
        // Double-rAF: garante paint de display:block antes da animacao CSS — sem forced layout.
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                banner.classList.add('awa-cookie-banner--visible');
                setBannerActive(true);
            });
        });
    }

    function bindEvents()
    {
        const acceptBtn = document.getElementById('awa-cookie-accept');
        const declineBtn = document.getElementById('awa-cookie-decline');

        if (acceptBtn) {
            acceptBtn.addEventListener('click', function () {
                saveConsent('all');
                hideBanner();
            });
        }

        if (declineBtn) {
            declineBtn.addEventListener('click', function () {
                saveConsent('essential');
                hideBanner();
            });
        }

        const reopenBtn = getReopenButton();
        if (reopenBtn && reopenBtn.getAttribute('data-awa-cookie-bound') !== '1') {
            reopenBtn.setAttribute('data-awa-cookie-bound', '1');
            reopenBtn.addEventListener('click', function (event) {
                event.preventDefault();
                openPreferences();
            });
        }
    }

    return {
        allowsMarketing: allowsMarketing,
        hasConsented: hasConsented,
        init: function () {
            /* A2: as ligações precisam existir nos DOIS caminhos — com e sem
             * consentimento — senão o botão de revogar nasce inerte para quem
             * já aceitou. */
            function start(consented) {
                bindEvents();
                window.addEventListener('resize', syncBannerHeight);

                if (consented) {
                    showReopenButton();
                    return;
                }

                setTimeout(showBanner, 800);
            }

            const consented = hasConsented();

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    start(consented);
                });
            } else {
                start(consented);
            }
        }
    };
});
