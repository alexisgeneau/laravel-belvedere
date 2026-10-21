<script>
    import listing from '../../mixins/listing';

    export default {
        mixins: [listing],

        data() {
            return {
                total: 0,
                search: '',
                searchTimeout: null,
                busy: [],
                retryingAll: false,
            };
        },

        watch: {
            search() {
                clearTimeout(this.searchTimeout);

                this.searchTimeout = setTimeout(() => this.reset(), 400);
            },
        },

        mounted() {
            this.setTitle('Failed Jobs');
            this.load();
        },

        methods: {
            fetch(cursor) {
                return this.$http.get('/jobs/failed', {
                    params: { search: this.search || undefined, before: cursor, limit: this.perPage },
                }).then((response) => {
                    this.total = response.data.total;

                    return { items: response.data.jobs, nextCursor: response.data.next_before };
                });
            },

            isBusy(job) {
                return this.busy.includes(job.id);
            },

            act(job, request) {
                if (this.isBusy(job)) {
                    return;
                }

                this.busy.push(job.id);

                request()
                    .then(() => this.load())
                    .finally(() => {
                        this.busy = this.busy.filter((id) => id !== job.id);
                    });
            },

            retry(job) {
                this.act(job, () => this.$http.post(`/jobs/failed/${encodeURIComponent(job.id)}/retry`));
            },

            forget(job) {
                this.confirm(`Delete the failed job "${this.jobBaseName(job.name)}"? It will not be retried.`, () => {
                    this.act(job, () => this.$http.delete(`/jobs/failed/${encodeURIComponent(job.id)}`));
                });
            },

            retryAll() {
                const scope = this.search ? 'every failed job matching your search' : 'every failed job';

                this.confirm(`Retry ${scope}?`, () => {
                    this.retryingAll = true;

                    this.$http.post('/jobs/failed/retry', null, { params: { search: this.search || undefined } })
                        .then((response) => {
                            this.notify(`${response.data.retried} job(s) pushed back to the queue.`);

                            return this.reset();
                        })
                        .finally(() => {
                            this.retryingAll = false;
                        });
                });
            },

            flush() {
                this.confirm('Delete every failed job? This cannot be undone.', () => {
                    this.$http.delete('/jobs/failed').then(() => this.reset());
                });
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="refresh" :immediate="false" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Failed Jobs <small class="text-muted fw-normal" v-if="ready">({{ formatNumber(total) }})</small></h2>

                <div class="d-flex align-items-center">
                    <div class="form-control-with-icon">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon">
                                <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                            </svg>
                        </div>

                        <input type="text" class="form-control w-100" :style="search ? 'padding-right: 2rem' : ''" v-model="search" placeholder="Search failed jobs">

                        <a v-if="search" href="#" @click.prevent="search = ''" class="clear-search">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    </div>

                    <template v-if="!readOnly && ready && items.length > 0">
                        <button class="btn btn-primary btn-sm ms-3" @click="retryAll" :disabled="retryingAll">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon" fill="currentColor" :class="{ spin: retryingAll }">
                                <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" />
                            </svg>
                            {{ search ? 'Retry Matching' : 'Retry All' }}
                        </button>

                        <button class="btn btn-muted btn-sm ms-2" @click="flush" v-if="!search">Flush</button>
                    </template>
                </div>
            </div>

            <loader v-if="!ready" />

            <empty-state v-if="ready && items.length == 0">{{ search ? 'No failed job matches your search.' : "There aren't any failed jobs." }}</empty-state>

            <table v-if="ready && items.length > 0" class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Exception</th>
                        <th>Failed</th>
                        <th class="text-end" v-if="!readOnly"></th>
                    </tr>
                </thead>

                <tbody>
                    <new-entries v-if="hasNewEntries" @load="reset" />

                    <tr v-for="job in items" :key="job.id">
                        <td>
                            <router-link :title="job.name" :to="{ name: 'failed-jobs-preview', params: { jobId: job.id } }">{{ jobBaseName(job.name) }}</router-link>

                            <br>

                            <small class="text-muted">
                                Queue: {{ job.queue }}
                                <span v-if="job.attempts"> | Attempts: {{ job.attempts }}</span>
                                <span v-if="job.batch_id">
                                    | Batch: <router-link :to="{ name: 'batches-preview', params: { batchId: job.batch_id } }">{{ job.batch_id.split('-')[0] }}</router-link>
                                </span>
                                <span v-if="job.tags.length" class="text-break">
                                    | Tags: {{ job.tags.slice(0, 3).join(', ') }}<span class="text-secondary" v-if="job.tags.length > 3"> +{{ job.tags.length - 3 }} more</span>
                                </span>
                            </small>
                        </td>
                        <td class="text-muted"><span class="exception-summary" :title="job.exception_summary">{{ job.exception_summary }}</span></td>
                        <td class="table-fit text-muted" :title="formatDate(job.failed_at)">{{ fromNow(job.failed_at) }}</td>
                        <td class="text-end table-fit" v-if="!readOnly">
                            <a href="#" class="control-action" title="Retry Job" @click.prevent="retry(job)">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isBusy(job) }">
                                    <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" />
                                </svg>
                            </a>

                            <a href="#" class="control-action control-action-danger" title="Delete Failed Job" @click.prevent="forget(job)">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <pagination v-if="ready && items.length > 0" :page="page" :has-previous="hasPrevious" :has-next="hasNext" @previous="previous" @next="next" />
        </div>
    </div>
</template>
