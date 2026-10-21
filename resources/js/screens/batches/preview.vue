<script>
    import BatchStatus from './status.vue';

    export default {
        components: {
            BatchStatus,
        },

        data() {
            return {
                ready: false,
                missing: false,
                busy: false,
                batch: null,
                failedJobs: [],
            };
        },

        computed: {
            batchId() {
                return this.$route.params.batchId;
            },

            canCancel() {
                return this.batch && !this.batch.finished && !this.batch.cancelled;
            },

            canRetry() {
                return this.batch && this.batch.failed_jobs > 0 && !this.batch.cancelled;
            },
        },

        mounted() {
            this.setTitle('Batch');
        },

        methods: {
            load() {
                return this.$http.get(`/batches/${encodeURIComponent(this.batchId)}`)
                    .then((response) => {
                        this.batch = response.data.batch;
                        this.failedJobs = response.data.failed_jobs;
                        this.ready = true;

                        this.setTitle(this.batch.name || 'Batch');
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
                if (!this.missing && (!this.batch || !this.batch.finished)) {
                    this.load();
                }
            },

            act(request) {
                if (this.busy) {
                    return;
                }

                this.busy = true;

                request()
                    .then(() => this.load())
                    .finally(() => {
                        this.busy = false;
                    });
            },

            retry() {
                this.act(() => this.$http.post(`/batches/${encodeURIComponent(this.batchId)}/retry`)
                    .then((response) => this.notify(`${response.data.retried} failed job(s) pushed back to the queue.`)));
            },

            cancel() {
                this.confirm('Cancel this batch? Its pending jobs will be skipped by the workers.', () => {
                    this.act(() => this.$http.post(`/batches/${encodeURIComponent(this.batchId)}/cancel`));
                });
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="refresh" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0" v-if="!ready || missing">Batch Preview</h2>
                <h2 class="h6 m-0" v-if="ready && batch">{{ batch.name || batch.id }}</h2>

                <div v-if="ready && batch && !readOnly">
                    <button class="btn btn-muted btn-sm me-2" v-if="canCancel" @click.prevent="cancel" :disabled="busy">Cancel Batch</button>

                    <button class="btn btn-primary btn-sm" v-if="canRetry" @click.prevent="retry" :disabled="busy">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon" fill="currentColor" :class="{ spin: busy }">
                            <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" />
                        </svg>
                        Retry Failed Jobs
                    </button>
                </div>
            </div>

            <loader v-if="!ready" />

            <empty-state v-if="ready && missing">The batch <code class="mx-1">{{ batchId }}</code> does not exist or has been pruned.</empty-state>

            <div class="card-body card-bg-secondary" v-if="ready && batch">
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">ID</div>
                    <div class="col">{{ batch.id }} <batch-status class="ms-1" :batch="batch" /></div>
                </div>
                <div class="row mb-2" v-if="batch.name">
                    <div class="col-md-2 text-muted">Name</div>
                    <div class="col">{{ batch.name }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Created</div>
                    <div class="col">{{ formatDate(batch.created_at) }}</div>
                </div>
                <div class="row mb-2" v-if="batch.finished_at">
                    <div class="col-md-2 text-muted">Finished</div>
                    <div class="col">{{ formatDate(batch.finished_at) }}</div>
                </div>
                <div class="row mb-2" v-if="batch.cancelled_at">
                    <div class="col-md-2 text-muted">Cancelled</div>
                    <div class="col">{{ formatDate(batch.cancelled_at) }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Total Jobs</div>
                    <div class="col">{{ formatNumber(batch.total_jobs) }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Pending Jobs</div>
                    <div class="col">{{ formatNumber(batch.pending_jobs) }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">Failed Jobs</div>
                    <div class="col">{{ formatNumber(batch.failed_jobs) }}</div>
                </div>
                <div class="row">
                    <div class="col-md-2 text-muted">Processed Jobs<br><small>(Including Failed)</small></div>
                    <div class="col">{{ formatNumber(batch.processed_jobs) }} ({{ batch.progress }}%)</div>
                </div>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready && batch && batch.failed_jobs > 0">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Failed Jobs</h2>

                <small class="text-muted" v-if="failedJobs.length < batch.failed_jobs">Showing {{ failedJobs.length }} of {{ batch.failed_jobs }}</small>
            </div>

            <empty-state v-if="failedJobs.length == 0">The failed jobs of this batch are no longer in the failed jobs table: they have been retried or deleted.</empty-state>

            <table v-else class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Exception</th>
                        <th class="text-end">Failed</th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="job in failedJobs" :key="job.id">
                        <td>
                            <router-link :title="job.name" :to="{ name: 'failed-jobs-preview', params: { jobId: job.id } }">{{ jobBaseName(job.name) }}</router-link>
                            <br>
                            <small class="text-muted">Queue: {{ job.queue }}</small>
                        </td>
                        <td class="text-muted"><span class="exception-summary" :title="job.exception_summary">{{ job.exception_summary }}</span></td>
                        <td class="text-end text-muted table-fit">{{ formatDate(job.failed_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
