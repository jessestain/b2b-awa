define([
    'jquery',
    'awaChartjs'
], function ($, Chart) {
    'use strict';

    function tokenColor(name)
    {
        return window.getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function tokenRgb(name)
    {
        let value = tokenColor(name);
        if (!value) {
            return null;
        }

        let probe = document.createElement('span');
        probe.style.color = value;
        document.documentElement.appendChild(probe);
        let computed = window.getComputedStyle(probe).color;
        probe.remove();

        let match = computed.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
        if (!match) {
            return null;
        }

        return {
            r: match[1],
            g: match[2],
            b: match[3]
        };
    }

    function rgbaToken(name, alpha)
    {
        let rgb = tokenRgb(name);
        if (!rgb) {
            return tokenColor(name);
        }

        return 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', ' + alpha + ')';
    }

    return function (config) {
        let chartId = config.chartId || 'b2b-purchase-chart';
        let $canvas = $('#' + chartId);

        if (!$canvas.length) {
            return;
        }

        let rawData = $canvas.data('chart-data');
        if (!rawData || !Array.isArray(rawData)) {
            return;
        }

        let labels = rawData.map(function (item) {
            return item.label;
        });
        let values = rawData.map(function (item) {
            return item.value;
        });

        let canvasEl = $canvas[0];
        let ChartConstructor = (typeof Chart === 'function')
            ? Chart
            : (Chart && Chart.default) || window.Chart;

        if (typeof ChartConstructor !== 'function') {
            return;
        }

        if (typeof ChartConstructor.getChart === 'function') {
            let existing = ChartConstructor.getChart(canvasEl);
            if (existing && typeof existing.destroy === 'function') {
                existing.destroy();
            }
        }

        if (canvasEl.dataset.awaChartBound === '1') {
            return;
        }
        canvasEl.dataset.awaChartBound = '1';

        let ctx = canvasEl.getContext('2d');
        let primary = tokenColor('--awa-primary');
        let surface = tokenColor('--awa-bg-surface') || tokenColor('--awa-bg');
        let muted = tokenColor('--awa-text-muted') || tokenColor('--awa-text-secondary');
        let border = tokenColor('--awa-border');
        let overlay = rgbaToken('--awa-text', 0.9);
        let primaryFill = rgbaToken('--awa-primary', 0.4);
        let primaryFade = rgbaToken('--awa-primary', 0.02);
        let grid = rgbaToken('--awa-border-subtle', 0.5) || rgbaToken('--awa-border', 0.5);

        let gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, primaryFill || primary);
        gradient.addColorStop(1, primaryFade || primary);

        new ChartConstructor(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Compras (R$)',
                    data: values,
                    borderColor: primary,
                    backgroundColor: gradient,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: surface,
                    pointBorderWidth: 2,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: primary
                }]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: overlay,
                        titleColor: surface,
                        bodyColor: surface,
                        borderColor: border,
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('pt-BR', {
                                        style: 'currency',
                                        currency: 'BRL'
                                    }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: muted,
                            font: {
                                family: 'Inter, sans-serif',
                                size: 11
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: grid,
                            drawBorder: false
                        },
                        ticks: {
                            color: muted,
                            font: {
                                family: 'Inter, sans-serif',
                                size: 11
                            },
                            callback: function (value) {
                                if (value >= 1000) {
                                    return 'R$ ' + (value / 1000) + 'k';
                                }
                                return 'R$ ' + value;
                            }
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'nearest'
                }
            }
        });
    };
});
