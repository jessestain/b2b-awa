/**
 * B2B Dashboard — Async data loader (M20)
 * Fetches orders, quotes, credit data and lazy HTML fragments after initial render.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    function toBoundedPercent(value)
    {
        let numeric = Number(value);

        if (!isFinite(numeric)) {
            return null;
        }

        return Math.max(0, Math.min(100, Math.round(numeric)));
    }

    function isSafeCssColor(value)
    {
        if (!value) {
            return false;
        }

        let color = String(value).trim();
        return /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(color) ||
            /^(?:rgb|rgba|hsl|hsla)\([^)]{3,}\)$/.test(color) ||
            /^var\(--[a-zA-Z0-9_-]+\)$/.test(color) ||
            /^[a-zA-Z]+$/.test(color);
    }

    function applyDynamicStyles(rootNode)
    {
        let root = rootNode && rootNode.querySelectorAll ? rootNode : document;

        root.querySelectorAll('.b2b-rfm-badge[data-rfm-color]').forEach(function (element) {
            let color = String(element.getAttribute('data-rfm-color') || '').trim();

            if (!isSafeCssColor(color)) {
                element.style.removeProperty('--b2b-rfm-color');
                return;
            }

            element.style.setProperty('--b2b-rfm-color', color);
        });

        root.querySelectorAll('.rfm-tag[data-rfm-tag-color]').forEach(function (element) {
            let color = String(element.getAttribute('data-rfm-tag-color') || '').trim();

            if (!isSafeCssColor(color)) {
                element.style.removeProperty('--b2b-rfm-tag-bg');
                return;
            }

            element.style.setProperty('--b2b-rfm-tag-bg', color);
        });

        root.querySelectorAll('.progress-fill[data-bar-width], .credit-bar-fill[data-bar-width]').forEach(function (element) {
            let width = toBoundedPercent(element.getAttribute('data-bar-width'));

            if (width === null) {
                element.style.removeProperty('--b2b-bar-width');
                return;
            }

            element.style.setProperty('--b2b-bar-width', String(width));
        });
    }

    return function (config) {
        let baseUrl = config.ajaxUrl || '/b2b/account/dashboardData';
        let fragmentUrl = config.fragmentUrl || '';
        let lazyPanelsStarted = false;
        const inflightSections = {};

        function escapeHtml(str)
        {
            if (!str) {
                return '';
            }
            let div = document.createElement('div');
            div.appendChild(document.createTextNode(String(str)));
            return div.innerHTML;
        }

        function announceLive(message)
        {
            const $live = $('#b2b-dashboard-live');
            if (!$live.length || !message) {
                return;
            }

            $live.text('');
            window.setTimeout(function () {
                $live.text(message);
            }, 30);
        }

        function finishOrdersLoading($container)
        {
            $container.find('.orders-async-loading').remove();
            $container.find('table.orders-table').attr('aria-busy', 'false');
        }

        function renderOrders(data)
        {
            const $container = $('[data-dashboard-section="orders"]').first();
            if (!$container.length) {
                return;
            }

            const $tbody = $container.find('tbody');
            if (!$tbody.length) {
                finishOrdersLoading($container);
                return;
            }

            if (!data.items || !data.items.length) {
                if ($container.attr('data-orders-deferred') === 'true') {
                    finishOrdersLoading($container);
                    if (!$container.find('.b2b-empty-state').length) {
                        $container.append(
                            '<div class="b2b-empty-state" role="status">' +
                            '<p>' + escapeHtml($t('Você ainda não realizou nenhum pedido.')) + '</p>' +
                            '</div>'
                        );
                    }
                } else {
                    finishOrdersLoading($container);
                }
                return;
            }

            let html = '';
            data.items.forEach(function (order) {
                const url = escapeHtml(order.view_url || '#');
                const id = escapeHtml(order.increment_id);
                const status = escapeHtml(order.status);
                const total = escapeHtml(order.grand_total);
                html += '<tr>' +
                    '<td><a href="' + url + '">#' + id + '</a></td>' +
                    '<td>' + formatDate(order.created_at) + '</td>' +
                    '<td><span class="status-badge">' + status + '</span></td>' +
                    '<td>' + total + '</td></tr>';
            });

            finishOrdersLoading($container);
            $tbody.html(html);
            announceLive(
                $t('Pedidos recentes atualizados: %1 itens.')
                    .replace('%1', String(data.items.length))
            );
        }

        function setQuoteCount($el, value)
        {
            if (!$el.length) {
                return;
            }
            $el.text(String(value))
                .removeClass('b2b-stat-value--loading')
                .attr('aria-busy', 'false');
        }

        function renderQuotes(data)
        {
            if (typeof data.pending_count === 'number') {
                setQuoteCount($('[data-quotes-pending-count]'), data.pending_count);
            }
            if (typeof data.approved_count === 'number') {
                setQuoteCount($('[data-quotes-approved-count]'), data.approved_count);
            }

            const $badge = $('[data-dashboard-section="quotes"] .pending-count');
            if ($badge.length && data.pending_count > 0) {
                $badge.text(data.pending_count).css('display', 'inline-flex');
            }

            announceLive($t('Contadores de cotações atualizados.'));
        }

        function getErrorMessage(xhr, section)
        {
            const status = xhr && xhr.status;

            if (status === 401) {
                return $t('Sua sessão expirou. Faça login novamente para atualizar os dados do painel.');
            }
            if (status === 403) {
                return $t('Você não tem permissão para ver esses dados no momento.');
            }
            if (status === 404) {
                return $t('O serviço de dados do painel não foi encontrado. Recarregue a página.');
            }
            if (status >= 500) {
                return $t('O servidor não respondeu. Tente novamente em instantes.');
            }

            const sectionLabel = section === 'quotes'
                ? $t('as cotações')
                : $t('os pedidos');

            return $t('Não foi possível atualizar %1 agora.')
                .replace('%1', sectionLabel);
        }

        function showDashboardNotice(message, type)
        {
            const $dashboard = $('.b2b-dashboard').first();
            if (!$dashboard.length || $('.b2b-async-message--global').length) {
                return;
            }

            $('<div/>', {
                class: 'message ' + (type || 'warning') + ' b2b-async-message b2b-async-message--global',
                role: 'alert'
            }).append($('<span/>').text(message)).prependTo($dashboard);
        }

        function showSectionNotice(section, message, type, onRetry)
        {
            const $section = $('[data-dashboard-section="' + section + '"]').first();
            if (!$section.length) {
                showDashboardNotice(message, type);
                return;
            }

            const noticeSelector = '.b2b-async-message[data-section="' + section + '"]';
            if ($section.find(noticeSelector).length) {
                return;
            }

            const $notice = $('<div/>', {
                class: 'message ' + (type || 'warning') + ' b2b-async-message b2b-async-message--section',
                role: 'alert',
                'data-section': section
            });

            $notice.append($('<span/>').text(message));

            if (typeof onRetry === 'function') {
                const $retry = $('<button/>', {
                    type: 'button',
                    class: 'action secondary b2b-async-retry',
                    text: $t('Tentar novamente')
                }).on('click', function () {
                    $notice.remove();
                    onRetry();
                });
                $notice.append($retry);
            }

            const $anchor = $section.find('.section-header').first();
            if ($anchor.length) {
                $anchor.after($notice);
            } else {
                $section.prepend($notice);
            }
        }

        function formatDate(dateStr)
        {
            if (!dateStr) {
                return '\u2014';
            }
            try {
                return new Date(dateStr).toLocaleDateString('pt-BR');
            } catch (e) {
                return dateStr;
            }
        }

        function loadJsonSection(section, onSuccess, onError, force)
        {
            if (!force && inflightSections[section]) {
                return;
            }

            inflightSections[section] = true;

            $.ajax({
                url: baseUrl,
                data: { section: section },
                type: 'GET',
                dataType: 'json',
                timeout: 15000,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function (resp) {
                    inflightSections[section] = false;
                    onSuccess(resp);
                },
                error: function (xhr) {
                    inflightSections[section] = false;
                    onError(xhr);
                }
            });
        }

        function handleJsonError(xhr, section)
        {
            if (section === 'orders') {
                finishOrdersLoading($('[data-dashboard-section="orders"]'));
            }
            if (section === 'quotes') {
                $('[data-quotes-pending-count], [data-quotes-approved-count]').attr('aria-busy', 'false');
            }

            const message = getErrorMessage(xhr, section);
            announceLive(message);

            if (xhr.status === 401) {
                showDashboardNotice(message, 'warning');
                return;
            }

            const retryHandler = section === 'orders'
                ? function () {
                    loadOrdersSection(true); }
                : function () {
                    loadQuotesSection(true); };

            showSectionNotice(section, message, 'warning', retryHandler);
        }

        function loadOrdersSection(force)
        {
            const $orders = $('[data-dashboard-section="orders"]').first();
            if ($orders.length) {
                $orders.find('table.orders-table').attr('aria-busy', 'true');
            }

            loadJsonSection('orders', function (resp) {
                if (resp.orders) {
                    renderOrders(resp.orders);
                } else {
                    finishOrdersLoading($orders);
                }
            }, function (xhr) {
                handleJsonError(xhr, 'orders');
            }, force);
        }

        function loadQuotesSection(force)
        {
            $('[data-quotes-pending-count], [data-quotes-approved-count]').attr('aria-busy', 'true');

            loadJsonSection('quotes', function (resp) {
                if (resp.quotes) {
                    renderQuotes(resp.quotes);
                } else {
                    $('[data-quotes-pending-count], [data-quotes-approved-count]').attr('aria-busy', 'false');
                }
            }, function (xhr) {
                handleJsonError(xhr, 'quotes');
            }, force);
        }

        function loadJsonSections()
        {
            loadOrdersSection(false);

            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(function () {
                    loadQuotesSection(false);
                }, { timeout: 3200 });
            } else {
                window.setTimeout(function () {
                    loadQuotesSection(false);
                }, 500);
            }
        }

        function isEmptyFragmentHtml(html)
        {
            const trimmed = String(html || '').trim();
            if (!trimmed) {
                return true;
            }

            const probe = document.createElement('div');
            probe.innerHTML = trimmed;

            if (probe.textContent.trim() !== '') {
                return false;
            }

            return probe.querySelector(
                'img, table, canvas, svg, a[href], button, .summary-card, .erp-product-card, .b2b-intelligence-section'
            ) === null;
        }

        function loadLazyPanel($panel)
        {
            const loadState = $panel.attr('data-lazy-loaded');
            if (!$panel.length || loadState === 'true' || loadState === 'loading') {
                return;
            }

            const url = $panel.attr('data-lazy-url') || fragmentUrl;
            if (!url) {
                return;
            }

            $panel.attr('data-lazy-loaded', 'loading');
            $panel.attr('aria-busy', 'true');

            $.ajax({
                url: url,
                type: 'GET',
                dataType: 'html',
                timeout: 20000,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function (html) {
                    $panel.attr('data-lazy-loaded', 'true');
                    if (html && !isEmptyFragmentHtml(html)) {
                        $panel.attr('aria-busy', 'false');
                        $panel.addClass('b2b-dashboard-lazy-panel--loaded');
                        $panel.html(html);
                        applyDynamicStyles($panel.get(0));
                    } else {
                        $panel.remove();
                    }
                },
                error: function () {
                    $panel.attr('data-lazy-loaded', 'error');
                    $panel.attr('aria-busy', 'false');
                    $panel.addClass('b2b-dashboard-lazy-panel--error');
                    const fallback = $panel.attr('data-lazy-fallback') ||
                        $t('Conteúdo indisponível no momento.');
                    const $placeholder = $panel.find('.b2b-dashboard-lazy-placeholder');
                    $placeholder
                        .addClass('b2b-dashboard-lazy-placeholder--error')
                        .attr('aria-busy', 'false');
                    $placeholder.find('[aria-hidden="true"]').remove();
                    $placeholder.find('.b2b-dashboard-lazy-label').text(fallback);
                }
            });
        }

        function initLazyPanels()
        {
            $('[data-dashboard-lazy][data-lazy-priority="high"]').each(function () {
                loadLazyPanel($(this));
            });

            const $deferredPanels = $('[data-dashboard-lazy]').not('[data-lazy-priority="high"]');

            if (lazyPanelsStarted || !window.IntersectionObserver) {
                $deferredPanels.each(function () {
                    loadLazyPanel($(this));
                });
                return;
            }

            lazyPanelsStarted = true;

            const observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }
                    const $panel = $(entry.target);
                    observer.unobserve(entry.target);
                    loadLazyPanel($panel);
                });
            }, {
                rootMargin: '200px 0px',
                threshold: 0.01
            });

            $deferredPanels.each(function () {
                observer.observe(this);
            });
        }

        function scheduleWork()
        {
            applyDynamicStyles(document);
            loadJsonSections();
            initLazyPanels();
        }

        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(scheduleWork, { timeout: 2200 });
        } else {
            window.setTimeout(scheduleWork, 800);
        }
    };
});
