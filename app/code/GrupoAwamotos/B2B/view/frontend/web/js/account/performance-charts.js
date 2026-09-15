define([
    'jquery',
    'awaChartjs',
    'mage/translate'
], function ($, Chart, $t) {
    'use strict';

    return function (config, element) {
        const $element = $(element);
        const rawData = $element.data('chart-data');

        if (!rawData || !rawData.length) {
            return;
        }

        const labels = rawData.map(item => {
            // Format YYYY-MM to Month Name
            const date = new Date(item.month + '-01');
            return date.toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' });
        });

        const revenueData = rawData.map(item => item.revenue);
        const orderData = rawData.map(item => item.order_count);

        const canvas = element instanceof HTMLCanvasElement ? element : $element[0];
        if (!canvas || !canvas.getContext) {
            return;
        }

        const ChartConstructor = (typeof Chart === 'function')
            ? Chart
            : (Chart && Chart.default) || window.Chart;

        if (typeof ChartConstructor !== 'function') {
            return;
        }

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        if (typeof ChartConstructor.getChart === 'function') {
            const existing = ChartConstructor.getChart(canvas);
            if (existing && typeof existing.destroy === 'function') {
                existing.destroy();
            }
        }

        if (canvas.dataset.awaChartBound === '1') {
            return;
        }
        canvas.dataset.awaChartBound = '1';

        new ChartConstructor(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: $t('Volume de Compras (R$)'),
                        data: revenueData,
                        borderColor: '#b73337',
                        backgroundColor: 'rgba(183, 51, 55, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y'
                },
                    {
                        label: $t('Número de Pedidos'),
                        data: orderData,
                        borderColor: '#1e293b',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.4,
                        yAxisID: 'y1'
                }
                ]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                animation: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 6
                        }
                    },
                    tooltip: {
                        padding: 12,
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: '#1e293b',
                        bodyColor: '#64748b',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        displayColors: true,
                        callbacks: {
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.datasetIndex === 0) {
                                    label += new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed.y);
                                } else {
                                    label += context.parsed.y;
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: {
                            drawOnChartArea: true,
                        },
                        ticks: {
                            callback: function (value) {
                                return 'R$ ' + value.toLocaleString('pt-BR');
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    };
});
