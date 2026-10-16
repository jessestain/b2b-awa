define(['jquery', 'mage/translate', 'mage/cookies'], function ($, $t, _cookies) {
    'use strict';

    var MAX_CONCURRENT_PRICE_REQUESTS = 2;
    var PRICE_REQUEST_TIMEOUT_MS = 15000;
    var activePriceRequests = 0;
    var priceRequestQueue = [];

    var PRICE_STATE = {
        PENDING: 'pending',
        READY: 'ready',
        FAILED: 'failed'
    };

    function getFormKey()
    {
        if (window.FORM_KEY) {
            return window.FORM_KEY;
        }

        if ($.mage && $.mage.cookies) {
            return $.mage.cookies.get('form_key') || '';
        }

        return '';
    }

    function showLiveMessage($root, message, type)
    {
        var $live = $root.find('[data-role="reorder-live"]');
        if (!$live.length) {
            $live = $('<div class="b2b-reorder-live" data-role="reorder-live" aria-live="polite"></div>');
            $root.prepend($live);
        }

        $live.removeClass('b2b-reorder-live--success b2b-reorder-live--error')
            .addClass(type === 'success' ? 'b2b-reorder-live--success' : 'b2b-reorder-live--error')
            .text(message);
    }

    function getSubmitButton($card)
    {
        return $card.find('.js-reorder-form [type="submit"]');
    }

    function getDefaultSubmitLabel($submit)
    {
        return $submit.data('labelDefault') || $submit.text();
    }

    function updatePricesStatus($card, message)
    {
        var $status = $card.find('[data-role="reorder-prices-status"]');
        if ($status.length) {
            $status.text(message);
        }
    }

    function ensureRetryButton($root, $card, pricesUrl)
    {
        var $actions = $card.find('.reorder-card__actions');
        if ($actions.find('.js-reorder-retry-prices').length) {
            return;
        }

        var $retry = $('<button type="button" class="action secondary js-reorder-retry-prices"></button>');
        $retry.text($t('Recarregar preços'));
        $retry.on('click', function () {
            $card.removeData('prices-loaded');
            loadPricesForCard($root, $card, pricesUrl, true);
        });
        $actions.prepend($retry);
    }

    function setPriceState($root, $card, state, pricesUrl)
    {
        var $submit = getSubmitButton($card);
        var defaultLabel = getDefaultSubmitLabel($submit);

        $card.attr('data-prices-state', state);

        if (state === PRICE_STATE.PENDING) {
            $submit.prop('disabled', true)
                .addClass('is-prices-pending')
                .removeClass('is-prices-degraded')
                .attr('aria-disabled', 'true')
                .text(defaultLabel);
            updatePricesStatus($card, $t('Buscando preços da sua tabela…'));
            $card.removeClass('reorder-card--prices-failed');
            return;
        }

        if (state === PRICE_STATE.READY) {
            $submit.prop('disabled', false)
                .removeClass('is-prices-pending is-prices-degraded')
                .attr('aria-disabled', 'false')
                .text(defaultLabel);
            $card.removeClass('reorder-card--prices-failed');
            $card.find('.js-reorder-retry-prices').remove();
            updatePricesStatus($card, $t('Preços da tabela carregados.'));
            return;
        }

        $submit.prop('disabled', false)
            .removeClass('is-prices-pending')
            .addClass('is-prices-degraded')
            .attr('aria-disabled', 'false')
            .text(defaultLabel);
        $card.addClass('reorder-card--prices-failed');
        updatePricesStatus(
            $card,
            $t('Preços indisponíveis. Você pode adicionar ao carrinho; o valor final é confirmado na finalização do pedido.')
        );
        ensureRetryButton($root, $card, pricesUrl);
    }

    function isPriceStatePending($card)
    {
        return $card.attr('data-prices-state') === PRICE_STATE.PENDING;
    }

    function resetPriceCells($card)
    {
        $card.find('[data-reorder-item-id]').each(function () {
            var $cell = $(this);
            $cell.removeClass('b2b-reorder-price--increased').removeAttr('title');
            $cell.html(
                '<span class="reorder-price-loading" aria-hidden="true">…</span>' +
                '<span class="sr-only">' + $t('Carregando preço da tabela') + '</span>'
            );
        });
    }

    function markPriceCellsFailed($card)
    {
        $card.find('[data-reorder-item-id]').each(function () {
            var $cell = $(this);
            $cell.find('.reorder-price-loading, .sr-only').remove();
            if (!$cell.text().trim() || $cell.find('.reorder-price-loading').length) {
                $cell.text('—');
            }
        });
    }

    function applyPricePayload($card, items)
    {
        $.each(items, function (itemId, payload) {
            var $cell = $card.find('[data-reorder-item-id="' + itemId + '"]');
            if (!$cell.length) {
                return;
            }

            $cell.find('.reorder-price-loading, .sr-only').remove();
            $cell.text(payload.formatted || '—');
            if (payload.increased) {
                $cell.addClass('b2b-reorder-price--increased')
                    .attr('title', $t('Preço atualizado (+') + payload.change_pct + '%)');
            }
        });

        $card.find('[data-reorder-item-id]').each(function () {
            var $cell = $(this);
            if ($cell.find('.reorder-price-loading').length || !$cell.text().trim()) {
                $cell.find('.reorder-price-loading, .sr-only').remove();
                $cell.text('—');
            }
        });
    }

    function drainPriceQueue()
    {
        while (activePriceRequests < MAX_CONCURRENT_PRICE_REQUESTS && priceRequestQueue.length) {
            var task = priceRequestQueue.shift();
            if (task) {
                task();
            }
        }
    }

    function enqueuePriceRequest(task)
    {
        priceRequestQueue.push(task);
        drainPriceQueue();
    }

    function loadPricesForCard($root, $card, pricesUrl, forceRetry)
    {
        if ($card.data('prices-loading')) {
            return;
        }

        if ($card.data('prices-loaded') && !forceRetry) {
            return;
        }

        var orderId = $card.data('order-id');
        if (!orderId || !pricesUrl) {
            setPriceState($root, $card, PRICE_STATE.READY, pricesUrl);
            return;
        }

        if (forceRetry) {
            $card.removeData('prices-loaded');
            resetPriceCells($card);
        }

        $card.data('prices-loading', true);
        setPriceState($root, $card, PRICE_STATE.PENDING, pricesUrl);

        enqueuePriceRequest(function () {
            activePriceRequests += 1;
            $card.attr('aria-busy', 'true');

            $.ajax({
                url: pricesUrl,
                type: 'GET',
                dataType: 'json',
                data: { order_id: orderId },
                timeout: PRICE_REQUEST_TIMEOUT_MS
            }).done(function (response) {
                if (response && response.success && response.items) {
                    applyPricePayload($card, response.items);
                    setPriceState($root, $card, PRICE_STATE.READY, pricesUrl);
                    return;
                }

                markPriceCellsFailed($card);
                setPriceState($root, $card, PRICE_STATE.FAILED, pricesUrl);
                showLiveMessage(
                    $root,
                    $t('Não foi possível carregar os preços do pedido %1.').replace('%1', orderId),
                    'error'
                );
            }).fail(function (xhr, status) {
                markPriceCellsFailed($card);
                setPriceState($root, $card, PRICE_STATE.FAILED, pricesUrl);

                if (xhr && xhr.status === 403) {
                    showLiveMessage(
                        $root,
                        $t('Sessão expirada. Atualize a página e faça login novamente.'),
                        'error'
                    );
                    return;
                }

                if (status === 'timeout') {
                    showLiveMessage(
                        $root,
                        $t('Tempo esgotado ao buscar preços. Toque em "Recarregar preços" no card.'),
                        'error'
                    );
                    return;
                }

                showLiveMessage(
                    $root,
                    $t('Não foi possível carregar os preços. Você ainda pode adicionar ao carrinho.'),
                    'error'
                );
            }).always(function () {
                $card.data('prices-loaded', true);
                $card.data('prices-loading', false);
                $card.removeAttr('aria-busy');
                activePriceRequests -= 1;
                drainPriceQueue();
            });
        });
    }

    function scheduleCardPrices($root, cardEl, pricesUrl, eager)
    {
        var $card = $(cardEl);

        if (eager) {
            loadPricesForCard($root, $card, pricesUrl, false);
            return;
        }

        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    observer.disconnect();
                    loadPricesForCard($root, $card, pricesUrl, false);
                });
            }, {
                root: null,
                rootMargin: '160px 0px',
                threshold: 0.01
            });

            observer.observe(cardEl);
            setPriceState($root, $card, PRICE_STATE.PENDING, pricesUrl);
            return;
        }

        loadPricesForCard($root, $card, pricesUrl, false);
    }

    return function (config, element) {
        var $root = $(element);
        var pricesUrl = config.pricesUrl || '';
        var addUrl = config.addUrl || '';

        $root.find('.js-reorder-form [type="submit"]').each(function () {
            var $submit = $(this);
            if (!$submit.data('labelDefault')) {
                $submit.data('labelDefault', $submit.text());
            }
        });

        $root.on('change', '.js-reorder-toggle-all', function () {
            var orderId = $(this).data('order-id');
            var checked = $(this).is(':checked');

            $root.find('.js-reorder-item-check[data-order-id="' + orderId + '"]').prop('checked', checked);
        });

        $root.find('.reorder-card').each(function (index) {
            // HOTFIX 2026-10-16 (QA responsivo): eager-load dos 2 primeiros cards.
            // Motivo: nas viewports reais testadas (1440x900 e 768x1024), 2 cards
            // ficam visíveis acima da dobra sem rolagem; no 375x812, o 2º fica logo
            // abaixo da dobra (custo de 1 request leve). O restante segue lazy via
            // IntersectionObserver (fila MAX_CONCURRENT_PRICE_REQUESTS = 2).
            scheduleCardPrices($root, this, pricesUrl, index < 2);
        });

        $root.on('submit', '.js-reorder-form', function (event) {
            if (!addUrl) {
                return;
            }

            event.preventDefault();

            var $form = $(this);
            var $card = $form.closest('.reorder-card');
            var $submit = $form.find('[type="submit"]');
            var orderId = $form.find('[name="order_id"]').val();
            var shouldUnlockSubmit = true;
            var items = $form.find('[name="items[]"]:checked').map(function () {
                return this.value;
            }).get();

            if (isPriceStatePending($card)) {
                showLiveMessage(
                    $root,
                    $t('Espere os preços carregarem antes de adicionar ao carrinho.'),
                    'error'
                );
                return;
            }

            if (!items.length) {
                showLiveMessage($root, $t('Selecione pelo menos um item.'), 'error');
                return;
            }

            if ($submit.prop('disabled') || $submit.hasClass('is-loading')) {
                return;
            }

            $submit.prop('disabled', true)
                .addClass('is-loading')
                .attr('aria-disabled', 'true')
                .attr('aria-busy', 'true');

            $.ajax({
                url: addUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 30000,
                data: {
                    order_id: orderId,
                    items: items,
                    form_key: getFormKey()
                }
            }).done(function (response) {
                if (response && response.message) {
                    showLiveMessage($root, response.message, response.success ? 'success' : 'error');
                }

                if (response && response.success && response.cart_url) {
                    shouldUnlockSubmit = false;
                    $submit.text($t('Redirecionando…'));
                    window.setTimeout(function () {
                        window.location.href = response.cart_url;
                    }, 700);
                    return;
                }

                if (!response || !response.success) {
                    if (!response || !response.message) {
                        showLiveMessage($root, $t('Não foi possível adicionar ao carrinho. Tente novamente.'), 'error');
                    }
                    return;
                }

                if (!response.message) {
                    showLiveMessage($root, $t('Itens adicionados ao carrinho.'), 'success');
                }
            }).fail(function (xhr) {
                if (xhr && xhr.status === 403) {
                    showLiveMessage(
                        $root,
                        $t('Sessão expirada. Atualize a página e faça login novamente.'),
                        'error'
                    );
                    return;
                }

                showLiveMessage($root, $t('Não foi possível adicionar ao carrinho. Tente novamente.'), 'error');
            }).always(function () {
                if (!shouldUnlockSubmit) {
                    return;
                }

                if (!isPriceStatePending($card)) {
                    $submit.prop('disabled', false)
                        .attr('aria-disabled', 'false')
                        .attr('aria-busy', 'false');
                }
                $submit.removeClass('is-loading');
            });
        });
    };
});
