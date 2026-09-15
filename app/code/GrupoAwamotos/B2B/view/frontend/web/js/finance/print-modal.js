/**
 * Abre a pagina de impressao do boleto dentro de um modal (iframe), evitando
 * navegacao para nova aba. O boleto e carregado no iframe (mesma pagina ja
 * "limpa" usada na impressao direta) e o botao "Imprimir" do modal aciona
 * a impressao apenas do conteudo do iframe.
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Ui/js/modal/modal',
    'Magento_Ui/js/modal/alert'
], function ($, $t, modal, alertModal) {
    'use strict';

    function showAlert(message) {
        alertModal({
            title: $t('B2B'),
            content: message
        });
    }

    /**
     * Referencia ao popup atualmente aberto (evita 2+ modais empilhados
     * se o usuario clicar em "Imprimir" mais de uma vez).
     */
    var activePopup = null;

    /**
     * Evita registrar binding global mais de uma vez.
     */
    var globalBindingApplied = false;

    /**
     * Tempo maximo (ms) esperando o iframe carregar antes de mostrar um
     * aviso de erro com opcao de abrir em nova aba (evita ficar preso em
     * "Carregando boleto..." para sempre caso algo impeca o load).
     */
    var LOAD_TIMEOUT_MS = 15000;

    /**
     * Browser embutido do Cursor/Electron nao tem preview de impressao.
     * Nesses ambientes abrimos a pagina limpa sem autoprint.
     *
     * @returns {boolean}
     */
    function supportsPrintPreview() {
        if (window.cursorBrowser) {
            return false;
        }

        var ua = navigator.userAgent || '';

        return !(/Electron/i.test(ua) || /Cursor\/[\d.]+/i.test(ua));
    }

    /**
     * Abre URL em nova aba. Nao usar window.open(..., 'noopener') — em Chromium
     * isso retorna null. Nunca navega a aba atual (o boleto deve permanecer no modal).
     *
     * @param {string} url
     * @returns {boolean}
     */
    function openPrintTab(url) {
        var win = null;

        try {
            win = window.open(url, '_blank');
        } catch (e) {
            win = null;
        }

        if (!win) {
            return false;
        }

        try {
            win.opener = null;
        } catch (e2) {
            // ignore
        }

        return true;
    }

    /**
     * Normaliza e valida URL de impressão para evitar protocolos inseguros.
     *
     * @param {string} rawUrl
     * @returns {string}
     */
    function normalizePrintUrl(rawUrl) {
        var parsed;

        try {
            parsed = new URL(rawUrl, window.location.origin);
        } catch (e) {
            return '';
        }

        if (!/^https?:$/.test(parsed.protocol)) {
            return '';
        }

        return parsed.toString();
    }

    /**
     * Aviso no modal quando o ambiente nao tem preview de impressao.
     *
     * @param {jQuery} $content
     * @param {string} message
     */
    function showModalNotice($content, message) {
        var $existing = $content.find('.b2b-finance-print-modal__notice');

        if ($existing.length) {
            $existing.text(message);
            return;
        }

        $content.prepend(
            '<p class="b2b-finance-print-modal__notice" role="status">' +
                message +
            '</p>'
        );
    }

    /**
     * Corrige stack overlay/modal: Magento grava z-index inline e pode deixar
     * o overlay acima do dialog (bloqueia clique e parece "travado").
     */
    function fixModalStack() {
        var modalEl = document.querySelector('.b2b-finance-print-modal-wrap._show');
        var overlayEl = document.querySelector('.modals-overlay');

        if (overlayEl && overlayEl.style) {
            overlayEl.style.setProperty('z-index', '100200', 'important');
        }
        if (modalEl && modalEl.style) {
            modalEl.style.setProperty('z-index', '100220', 'important');
            modalEl.style.setProperty('pointer-events', 'auto', 'important');
        }
    }

    /**
     * @param {jQuery} $loading
     */
    function hideLoading($loading) {
        $loading
            .addClass('is-hidden')
            .attr('aria-hidden', 'true')
            .hide()
            .empty();
    }

    /**
     * URL do iframe em modo embutido (sem toolbar interna — Imprimir fica no rodape do modal).
     *
     * @param {string} url
     * @returns {string}
     */
    function toEmbedUrl(url) {
        if (!url || url.indexOf('about:blank') === 0) {
            return url;
        }

        return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'embed=1';
    }

    /**
     * @param {string} url
     */
    function openPrintModal(url) {
        if (activePopup) {
            activePopup.closeModal();
            activePopup = null;
        }

        var $content = $(
            '<div class="b2b-finance-print-modal">' +
                '<div class="b2b-finance-print-modal__loading">' + $t('Carregando boleto…') + '</div>' +
                '<iframe class="b2b-finance-print-modal__frame" title="' + $t('Boleto para impressão') + '"></iframe>' +
            '</div>'
        );
        var $frame = $content.find('.b2b-finance-print-modal__frame');
        var $loading = $content.find('.b2b-finance-print-modal__loading');
        var timeoutId;
        var isFrameReady = false;
        var embedUrl = toEmbedUrl(url);

        var popup = modal({
            title: $t('Imprimir boleto'),
            modalClass: 'b2b-finance-print-modal-wrap',
            responsive: true,
            innerScroll: false,
            buttons: [
                {
                    text: $t('Fechar'),
                    class: 'action-secondary',
                    click: function () {
                        this.closeModal();
                    }
                },
                {
                    text: $t('Imprimir'),
                    class: 'action-primary',
                    click: function () {
                        var frameSrc = $frame.attr('src') || '';
                        var frameEl = $frame[0];
                        var canPreview = supportsPrintPreview();
                        // src do iframe pode ter ?embed=1 — limpar para abrir pagina de impressao
                        var baseUrl = frameSrc.replace(/([?&])embed=1(&)?/, function (m, sep, amp) {
                            return amp ? sep : '';
                        }).replace(/[?&]$/, '');

                        if (!isFrameReady && (!baseUrl || baseUrl.indexOf('about:blank') === 0)) {
                            showModalNotice($content, $t('Aguarde o boleto terminar de carregar para imprimir.'));
                            return;
                        }

                        // Cursor/Electron: manter boleto no modal, sem navegar nem dialogo vazio.
                        if (!canPreview) {
                            // So avisa se o iframe ja carregou (evita aviso + "Carregando…").
                            var readyDoc = frameEl && frameEl.contentDocument;
                            var boletoReady = !!(readyDoc && readyDoc.querySelector('#b2b-print-boleto-root'));

                            if (boletoReady) {
                                showModalNotice(
                                    $content,
                                    $t('O boleto já está visível acima. Para imprimir com preview, use Chrome ou Edge.')
                                );
                            } else {
                                showModalNotice($content, $t('Aguarde o boleto terminar de carregar para imprimir.'));
                            }

                            return;
                        }

                        // Chrome/Edge: nova aba com autoprint; fallback iframe.print().
                        if (baseUrl && baseUrl.indexOf('about:blank') !== 0) {
                            var printUrl = baseUrl +
                                (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'autoprint=1';

                            if (openPrintTab(printUrl)) {
                                return;
                            }
                        }

                        if (frameEl && frameEl.contentWindow) {
                            try {
                                frameEl.contentWindow.focus();
                                frameEl.contentWindow.print();
                            } catch (error) {
                                showModalNotice(
                                    $content,
                                    $t('Não foi possível abrir a impressão no momento. Tente novamente.')
                                );
                            }
                        }
                    }
                }
            ],
            closed: function () {
                clearTimeout(timeoutId);
                try {
                    if (typeof readyPollId !== 'undefined' && readyPollId) {
                        window.clearInterval(readyPollId);
                    }
                } catch (e) {
                    // ignore
                }
                $frame.attr('src', 'about:blank');
                $content.remove();
                activePopup = null;
            }
        }, $content);

        activePopup = popup;

        /**
         * Esconde toolbar interna do boleto no iframe do modal.
         */
        function hideIframeToolbar() {
            try {
                var frameDoc = $frame[0] && $frame[0].contentDocument;

                if (!frameDoc) {
                    return;
                }

                frameDoc.documentElement.classList.add('b2b-print-boleto-embed');
                if (frameDoc.body) {
                    frameDoc.body.classList.add('b2b-print-boleto-embed');
                }
                var root = frameDoc.getElementById('b2b-print-boleto-root');
                if (root) {
                    root.classList.add('is-embed');
                }
                if (!frameDoc.getElementById('b2b-embed-hide-toolbar')) {
                    var style = frameDoc.createElement('style');
                    style.id = 'b2b-embed-hide-toolbar';
                    style.textContent =
                        '.b2b-print-boleto__toolbar,' +
                        '.b2b-print-boleto__btn[data-action="print-boleto"]' +
                        '{display:none!important;visibility:hidden!important;}';
                    (frameDoc.head || frameDoc.documentElement).appendChild(style);
                }
            } catch (embedErr) {
                // ignore cross-origin
            }
        }

        /**
         * Marca iframe como pronto (esconde loading + limpa toolbar).
         */
        function onFrameReady(source) {
            if ($loading.hasClass('is-hidden')) {
                return;
            }

            isFrameReady = true;
            clearTimeout(timeoutId);
            if (readyPollId) {
                window.clearInterval(readyPollId);
                readyPollId = null;
            }

            hideLoading($loading);
            hideIframeToolbar();
            fixModalStack();
            window.setTimeout(hideIframeToolbar, 50);
            window.setTimeout(function () {
                hideIframeToolbar();
                fixModalStack();
            }, 300);

        }

        /**
         * @returns {boolean}
         */
        function frameHasBoleto() {
            try {
                var doc = $frame[0] && $frame[0].contentDocument;

                return !!(doc && doc.querySelector('#b2b-print-boleto-root'));
            } catch (e) {
                return false;
            }
        }

        var readyPollId = null;

        $frame.on('load.b2bPrintFrame', function () {
            var frameSrc = $frame.attr('src') || '';

            // Ignora load do about:blank (fechamento / reset).
            if (!frameSrc || frameSrc.indexOf('about:blank') === 0) {
                return;
            }

            onFrameReady('load');
        });

        // Fallback: evento load do iframe falha/atrasa com SW/cache — poll do DOM.
        readyPollId = window.setInterval(function () {
            if (frameHasBoleto()) {
                onFrameReady('poll');
            }
        }, 200);

        timeoutId = setTimeout(function () {
            if ($loading.hasClass('is-hidden')) {
                return;
            }
            if (readyPollId) {
                window.clearInterval(readyPollId);
                readyPollId = null;
            }
            $loading.removeClass('is-hidden').html(
                '<p>' + $t('Isso está demorando mais que o esperado.') + '</p>' +
                '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' +
                    $t('Abrir em nova aba') +
                '</a>'
            ).show();
        }, LOAD_TIMEOUT_MS);

        if (popup && typeof popup.openModal === 'function') {
            popup.openModal();
            fixModalStack();
            window.setTimeout(fixModalStack, 0);
            window.setTimeout(fixModalStack, 100);
            // embed=1: boleto limpo no iframe (Imprimir so no rodape do modal).
            // Re-resolve o iframe apos o Magento mover o DOM do modal.
            $frame = $('.b2b-finance-print-modal-wrap._show .b2b-finance-print-modal__frame');
            if (!$frame.length) {
                $frame = $content.find('.b2b-finance-print-modal__frame');
            }
            $frame.off('load.b2bPrintFrame').on('load.b2bPrintFrame', function () {
                var frameSrc = $frame.attr('src') || '';

                if (!frameSrc || frameSrc.indexOf('about:blank') === 0) {
                    return;
                }
                onFrameReady('load-rebound');
            });
            $frame.attr('src', embedUrl);
            return;
        }

        // Fallback para temas/plugins que exponham apenas API jQuery modal.
        if (typeof $content.modal === 'function') {
            $content.modal('openModal');
            fixModalStack();
            $frame.attr('src', embedUrl);
        } else {
            $frame.attr('src', embedUrl);
        }
    }

    function bindClickHandler() {
        var $scope = $(document);

        $scope.off('click.b2bPrintModal', '[data-print-boleto-url]');
        $scope.on('click.b2bPrintModal', '[data-print-boleto-url]', function (event) {
            var rawUrl = $(event.currentTarget).attr('data-print-boleto-url');
            var url = normalizePrintUrl(rawUrl);

            event.preventDefault();

            if (!url) {
                showAlert($t('Não foi possível abrir o boleto. Atualize a página e tente novamente.'));
                return;
            }

            try {
                openPrintModal(url);
            } catch (error) {
                if (!openPrintTab(url)) {
                    window.location.href = url;
                }
            }
        });
    }

    /**
     * O binding e feito SEMPRE em document (delegado), uma unica vez, mesmo que
     * este modulo seja inicializado mais de uma vez na pagina (ex.: mais de um
     * data-mage-init apontando pra ele). Bindar em escopos diferentes (ex.: no
     * container da tabela E em document) faz o mesmo clique disparar 2+ handlers
     * via bubbling, o que abre e fecha o modal quase instantaneamente (o
     * singleton "activePopup" fecha o popup recem-aberto ao ver a 2a chamada) --
     * bug real que causava "nao abre o modal" para o usuario.
     */
    return function () {

        if (!globalBindingApplied) {
            bindClickHandler();
            globalBindingApplied = true;
        }
    };
});
