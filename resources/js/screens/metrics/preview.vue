<script>
    import { state } from '../../state';
    import LineChart from '../../components/LineChart.vue';
    import MetricsRange from './range.vue';

    const PRIMARY = '#059669';
    const DANGER = '#ef4444';
    const INFO = '#3b82f6';

    export default {
        components: {
            LineChart,
            MetricsRange,
        },

        data() {
            return {
                ready: false,
                series: [],
            };
        },

        computed: {
            type() {
                return this.$route.params.type;
            },

            name() {
                return this.$route.params.name;
            },

            range() {
                return state.metricsRange;
            },

            hasData() {
                return this.series.some((bucket) => bucket.processed + bucket.failed + bucket.released > 0);
            },

            labels() {
                return this.series.map((bucket) => this.label(bucket.time));
            },

            throughput() {
                return [
                    { label: 'Processed', color: PRIMARY, fill: true, data: this.series.map((bucket) => bucket.processed) },
                    { label: 'Failed', color: DANGER, data: this.series.map((bucket) => bucket.failed) },
                    { label: 'Released', color: INFO, data: this.series.map((bucket) => bucket.released) },
                ];
            },

            runtime() {
                return [
                    { label: 'Average', color: PRIMARY, fill: true, data: this.series.map((bucket) => this.seconds(bucket.run_ms_avg)) },
                    { label: 'Maximum', color: INFO, data: this.series.map((bucket) => this.seconds(bucket.run_ms_max)) },
                ];
            },

            wait() {
                return [
                    { label: 'Average wait', color: PRIMARY, fill: true, data: this.series.map((bucket) => this.seconds(bucket.wait_ms_avg)) },
                ];
            },
        },

        watch: {
            range() {
                this.load();
            },

            connection() {
                this.load();
            },

            '$route.params'() {
                this.load();
            },
        },

        mounted() {
            this.setTitle(`${this.type == 'job' ? this.jobBaseName(this.name) : this.name} Metrics`);
            this.load();
        },

        methods: {
            load() {
                this.ready = false;

                return this.$http.get(`/metrics/${this.type}/${encodeURIComponent(this.name)}`, { params: { range: this.range } })
                    .then((response) => {
                        this.series = response.data.series;
                        this.ready = true;
                    });
            },

            refresh() {
                this.$http.get(`/metrics/${this.type}/${encodeURIComponent(this.name)}`, { params: { range: this.range } })
                    .then((response) => {
                        this.series = response.data.series;
                    });
            },

            label(iso) {
                const date = new Date(iso);
                const pad = (value) => String(value).padStart(2, '0');

                return this.range === '1h'
                    ? `${pad(date.getHours())}:${pad(date.getMinutes())}`
                    : `${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
            },

            seconds(milliseconds) {
                return milliseconds === null ? null : Math.round(milliseconds / 10) / 100;
            },

            formatSeconds(value) {
                return value === null ? '-' : `${value}s`;
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="refresh" :immediate="false" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Throughput - {{ name }}</h2>

                <metrics-range />
            </div>

            <loader v-if="!ready" />

            <div class="card-body card-bg-secondary" v-if="ready">
                <p class="text-center m-0 p-5" v-if="!hasData">Not Enough Data</p>

                <line-chart v-else :labels="labels" :series="throughput" />
            </div>
        </div>

        <div class="card overflow-hidden mt-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Runtime - {{ name }}</h2>
            </div>

            <loader v-if="!ready" />

            <div class="card-body card-bg-secondary" v-if="ready">
                <p class="text-center m-0 p-5" v-if="!hasData">Not Enough Data</p>

                <line-chart v-else :labels="labels" :series="runtime" :format="formatSeconds" />
            </div>
        </div>

        <div class="card overflow-hidden mt-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Wait Time - {{ name }}</h2>
            </div>

            <loader v-if="!ready" />

            <div class="card-body card-bg-secondary" v-if="ready">
                <p class="text-center m-0 p-5" v-if="!hasData">Not Enough Data</p>

                <line-chart v-else :labels="labels" :series="wait" :format="formatSeconds" />
            </div>
        </div>
    </div>
</template>
