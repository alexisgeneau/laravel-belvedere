<script>
    import { state } from '../../state';

    export default {
        props: {
            type: {
                type: String,
                required: true,
            },
        },

        data() {
            return {
                ready: false,
                subjects: [],
            };
        },

        computed: {
            range() {
                return state.metricsRange;
            },
        },

        watch: {
            type() {
                this.load();
            },

            range() {
                this.load();
            },

            connection() {
                this.load();
            },
        },

        mounted() {
            this.load();
        },

        methods: {
            load() {
                this.ready = false;

                return this.$http.get(`/metrics/${this.type}`, { params: { range: this.range } }).then((response) => {
                    this.subjects = response.data.subjects;
                    this.ready = true;
                });
            },

            failureRate(subject) {
                const total = subject.processed + subject.failed + subject.released;

                return total > 0 ? `${Math.round((subject.failed / total) * 1000) / 10}%` : '-';
            },
        },
    };
</script>

<template>
    <div>
        <loader v-if="!ready" />

        <empty-state v-if="ready && subjects.length == 0">No {{ type }} has been processed during this period.</empty-state>

        <table v-if="ready && subjects.length > 0" class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>{{ type == 'job' ? 'Job' : 'Queue' }}</th>
                    <th class="text-end">Processed</th>
                    <th class="text-end">Failed</th>
                    <th class="text-end">Released</th>
                    <th class="text-end">Failure Rate</th>
                    <th class="text-end">Avg Wait</th>
                    <th class="text-end">Avg Runtime</th>
                    <th class="text-end">Max Runtime</th>
                </tr>
            </thead>

            <tbody>
                <tr v-for="subject in subjects" :key="subject.name">
                    <td>
                        <router-link :title="subject.name" :to="{ name: 'metrics-preview', params: { type, name: subject.name } }">
                            {{ type == 'job' ? jobBaseName(subject.name) : subject.name }}
                        </router-link>
                    </td>
                    <td class="text-end text-muted">{{ formatNumber(subject.processed) }}</td>
                    <td class="text-end" :class="subject.failed > 0 ? 'text-danger' : 'text-muted'">{{ formatNumber(subject.failed) }}</td>
                    <td class="text-end text-muted">{{ formatNumber(subject.released) }}</td>
                    <td class="text-end text-muted">{{ failureRate(subject) }}</td>
                    <td class="text-end text-muted">{{ formatDuration(subject.wait_ms_avg) }}</td>
                    <td class="text-end text-muted">{{ formatDuration(subject.run_ms_avg) }}</td>
                    <td class="text-end text-muted">{{ formatDuration(subject.run_ms_max) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
