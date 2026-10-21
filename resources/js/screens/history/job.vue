<script>
    import Stacktrace from '../../components/Stacktrace.vue';

    export default {
        components: {
            Stacktrace,
        },

        data() {
            return {
                ready: false,
                missing: false,
                job: null,
                attempts: [],
            };
        },

        computed: {
            uuid() {
                return this.$route.params.uuid;
            },

            hasSnapshot() {
                return Boolean(this.job?.snapshot) && Object.keys(this.job.snapshot).length > 0;
            },

            exception() {
                return [...this.attempts].reverse().find((attempt) => attempt.exception)?.exception ?? null;
            },

            isFinal() {
                return this.job && !['queued', 'running'].includes(this.job.status);
            },
        },

        watch: {
            uuid() {
                this.load();
            },
        },

        mounted() {
            this.setTitle('Job Detail');
        },

        methods: {
            load() {
                return this.$http.get(`/jobs/history/${encodeURIComponent(this.uuid)}`)
                    .then((response) => {
                        this.job = response.data.job;
                        this.attempts = response.data.attempts;
                        this.missing = false;
                        this.ready = true;

                        this.setTitle(this.jobBaseName(this.job.name));
                    })
                    .catch((error) => {
                        if (error.response?.status !== 404) {
                            throw error;
                        }

                        this.missing = true;
                        this.ready = true;
                    });
            },

            refresh() {
                if (!this.isFinal) {
                    this.load();
                }
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="refresh" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0" v-if="!ready || missing">Job Preview</h2>
                <h2 class="h6 m-0" v-if="ready && job">{{ job.name }}</h2>

                <status-badge v-if="ready && job" :status="job.status" />
            </div>

            <loader v-if="!ready" />

            <empty-state v-if="ready && missing">
                Nothing has been recorded for the job <code class="mx-1">{{ uuid }}</code> yet: it was either pushed in bulk and not processed so far, or its history has been pruned.
            </empty-state>

            <div class="card-body card-bg-secondary" v-if="ready && job">
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">UUID</div>
                    <div class="col">{{ job.uuid }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Connection</div>
                    <div class="col">{{ job.connection }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Queue</div>
                    <div class="col">{{ job.queue }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Attempts</div>
                    <div class="col">{{ attempts.length }}</div>
                </div>
                <div class="row mb-2" v-if="job.tags.length">
                    <div class="col-md-2 text-muted">Tags</div>
                    <div class="col">{{ job.tags.join(', ') }}</div>
                </div>
                <div class="row mb-2" v-if="job.batch_id">
                    <div class="col-md-2 text-muted">Batch</div>
                    <div class="col">
                        <router-link :to="{ name: 'batches-preview', params: { batchId: job.batch_id } }">{{ job.batch_id }}</router-link>
                    </div>
                </div>
                <div class="row mb-2" v-if="job.worker_id">
                    <div class="col-md-2 text-muted">Worker</div>
                    <div class="col">{{ job.worker_id }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Queued</div>
                    <div class="col">{{ formatDate(job.queued_at) }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Started</div>
                    <div class="col">{{ formatDate(job.started_at) }}<span class="text-muted" v-if="job.wait_ms !== null"> (waited {{ formatDuration(job.wait_ms) }})</span></div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Finished</div>
                    <div class="col">{{ formatDate(job.finished_at) }}<span class="text-muted" v-if="job.run_ms !== null"> (ran for {{ formatDuration(job.run_ms) }})</span></div>
                </div>
                <div class="row">
                    <div class="col-md-2 text-muted">Memory</div>
                    <div class="col">{{ formatMemory(job.memory_kb) }}</div>
                </div>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready && exception">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">{{ job.status === 'lost' ? 'Why the job was marked as lost' : 'Exception' }}</h2>
            </div>

            <stacktrace :trace="exception.split('\n')" />
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready && job">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Data</h2>
            </div>

            <div class="card-body code-bg text-white" v-if="hasSnapshot">
                <vue-json-pretty :data="job.snapshot" :deep="2" />
            </div>

            <empty-state v-else-if="job.snapshot">This job has no public properties.</empty-state>

            <empty-state v-else>No snapshot of the job properties was recorded (snapshots are disabled, or the job is encrypted).</empty-state>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready && attempts.length > 0">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Attempts</h2>
            </div>

            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="text-end">#</th>
                        <th>Status</th>
                        <th>Queue</th>
                        <th>Started</th>
                        <th>Finished</th>
                        <th class="text-end">Runtime</th>
                        <th>Worker</th>
                        <th>Error</th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="attempt in attempts" :key="attempt.id">
                        <td class="text-end text-muted table-fit">{{ attempt.attempt }}</td>
                        <td><status-badge :status="attempt.status" /></td>
                        <td class="text-muted">{{ attempt.queue }}</td>
                        <td class="table-fit text-muted">{{ formatDate(attempt.started_at) }}</td>
                        <td class="table-fit text-muted">{{ formatDate(attempt.finished_at) }}</td>
                        <td class="table-fit text-end text-muted">{{ formatDuration(attempt.run_ms) }}</td>
                        <td class="text-muted">{{ attempt.worker_id ?? '-' }}</td>
                        <td class="text-muted"><span class="exception-summary" :title="attempt.exception_summary">{{ attempt.exception_summary ?? '-' }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
