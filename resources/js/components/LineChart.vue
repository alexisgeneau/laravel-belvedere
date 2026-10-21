<script>
    import { CategoryScale, Chart, Filler, LineController, LineElement, LinearScale, PointElement, Tooltip } from 'chart.js';

    Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler);

    const GRID_COLOR = 'rgba(107, 114, 128, 0.2)';
    const TICK_COLOR = '#6b7280';

    export default {
        props: {
            labels: {
                type: Array,
                required: true,
            },

            series: {
                type: Array,
                required: true,
            },

            format: {
                type: Function,
                default: (value) => value,
            },
        },

        data() {
            return {
                chart: null,
            };
        },

        watch: {
            series() {
                this.render();
            },
        },

        mounted() {
            this.render();
        },

        beforeUnmount() {
            this.chart?.destroy();
        },

        methods: {
            render() {
                this.chart?.destroy();

                this.chart = new Chart(this.$refs.canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: this.labels,
                        datasets: this.series.map((dataset) => ({
                            label: dataset.label,
                            data: dataset.data,
                            borderColor: dataset.color,
                            backgroundColor: dataset.fill ? `${dataset.color}22` : 'transparent',
                            pointBackgroundColor: '#fff',
                            pointBorderColor: dataset.color,
                            pointRadius: this.labels.length > 120 ? 0 : 2,
                            borderWidth: 2,
                            tension: 0,
                            fill: Boolean(dataset.fill),
                            spanGaps: true,
                        })),
                    },
                    options: {
                        animation: false,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: (context) => `${context.dataset.label}: ${this.format(context.parsed.y)}`,
                                },
                            },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grace: '25%',
                                grid: { color: GRID_COLOR },
                                ticks: {
                                    color: TICK_COLOR,
                                    precision: 0,
                                    callback: (value) => this.format(value),
                                },
                            },
                            x: {
                                grid: { color: GRID_COLOR },
                                ticks: {
                                    color: TICK_COLOR,
                                    maxRotation: 0,
                                    autoSkip: true,
                                    maxTicksLimit: 12,
                                },
                            },
                        },
                    },
                });
            },
        },
    };
</script>

<template>
    <div style="position: relative; height: 240px;">
        <canvas ref="canvas"></canvas>
    </div>
</template>
