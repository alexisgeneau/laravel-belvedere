<script>
    import Stacktrace from '../../components/Stacktrace.vue';

    export default {
        components: {
            Stacktrace,
        },

        data() {
            return {
                ready: false,
                retrying: false,
                job: null,
            };
        },

        computed: {
            jobId() {
                return this.$route.params.jobId;
            },

            hasSnapshot() {
                return Boolean(this.job?.snapshot) && Object.keys(this.job.snapshot).length > 0;
            },
        },

        mounted() {
            this.setTitle('Failed Job');
            this.load();
        },

        methods: {
            load() {
                return this.$http.get(`/jobs/failed/${encodeURIComponent(this.jobId)}`)
                    .then((response) => {
                        this.job = response.data.job;
                        this.ready = true;

                        this.setTitle(this.jobBaseName(this.job.name));
                    })
                    .catch((error) => {
                        if (error.response?.status !== 404) {
                            throw error;
                        }

                        this.ready = true;
                    });
            },

            retry() {
                if (this.retrying) {
                    return;
                }

                this.retrying = true;

                this.$http.post(`/jobs/failed/${encodeURIComponent(this.jobId)}/retry`)
                    .then(() => {
                        this.notify('The job has been pushed back to the queue.');

                        if (this.job.uuid) {
                            this.$router.replace({ name: 'job-preview', params: { uuid: this.job.uuid } });
                        } else {
                            this.$router.replace({ name: 'failed-jobs' });
                        }
                    })
                    .finally(() => {
                        this.retrying = false;
                    });
            },

            forget() {
                this.confirm('Delete this failed job? It will not be retried.', () => {
                    this.$http.delete(`/jobs/failed/${encodeURIComponent(this.jobId)}`)
                        .then(() => this.$router.replace({ name: 'failed-jobs' }));
                });
            },
        },
    };
</script>

<template>
    <div>
        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0" v-if="!ready || !job">Failed Job</h2>
                <h2 class="h6 m-0" v-if="ready && job">{{ job.name }}</h2>

                <div v-if="ready && job && !readOnly">
                    <button class="btn btn-muted btn-sm me-2" @click.prevent="forget">Delete</button>

                    <button class="btn btn-primary btn-sm" @click.prevent="retry" :disabled="retrying">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon" fill="currentColor" :class="{ spin: retrying }">
                            <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" />
                        </svg>
                        Retry
                    </button>
                </div>
            </div>

            <loader v-if="!ready" />

            <empty-state v-if="ready && !job">This failed job no longer exists: it has been retried or deleted.</empty-state>

            <div class="card-body card-bg-secondary" v-if="ready && job">
                <div class="row mb-2">
                    <div class="col-md-2 text-muted">ID</div>
                    <div class="col">{{ job.id }}</div>
                </div>
                <div class="row mb-2" v-if="job.uuid && job.uuid !== job.id">
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
                <div class="row mb-2" v-if="job.attempts">
                    <div class="col-md-2 text-muted">Attempts</div>
                    <div class="col">
                        {{ job.attempts }}
                        <router-link class="ms-2" v-if="job.uuid" :to="{ name: 'job-preview', params: { uuid: job.uuid } }">View history</router-link>
                    </div>
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
                <div class="row">
                    <div class="col-md-2 text-muted">Failed</div>
                    <div class="col">{{ formatDate(job.failed_at) }} <span class="text-muted">({{ fromNow(job.failed_at) }})</span></div>
                </div>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready && job">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Exception</h2>
            </div>

            <stacktrace :trace="job.exception.split('\n')" />
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
    </div>
</template>
