<script>
    import listing from '../../mixins/listing';

    const STATUSES = [
        { value: 'completed', label: 'Completed', empty: "There aren't any completed jobs." },
        { value: 'released', label: 'Released', empty: "There aren't any released jobs." },
        { value: 'timed_out', label: 'Timed Out', empty: "There aren't any timed out jobs." },
    ];

    export default {
        mixins: [listing],

        data() {
            return {
                status: 'completed',
                search: '',
                searchTimeout: null,
            };
        },

        computed: {
            statuses() {
                return STATUSES;
            },

            emptyMessage() {
                return this.search ? 'No job matches your search.' : STATUSES.find((status) => status.value === this.status).empty;
            },
        },

        watch: {
            status() {
                this.reset();
            },

            search() {
                clearTimeout(this.searchTimeout);

                this.searchTimeout = setTimeout(() => this.reset(), 400);
            },
        },

        mounted() {
            this.setTitle('Completed Jobs');
            this.load();
        },

        methods: {
            fetch(cursor) {
                return this.$http.get('/jobs/history', {
                    params: { status: this.status, search: this.search || undefined, cursor, limit: this.perPage },
                }).then((response) => ({ items: response.data.jobs, nextCursor: response.data.next_cursor }));
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="refresh" :immediate="false" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Completed Jobs</h2>

                <div class="form-control-with-icon">
                    <div class="icon-wrapper">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon">
                            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                        </svg>
                    </div>

                    <input type="text" class="form-control w-100" :style="search ? 'padding-right: 2rem' : ''" v-model="search" placeholder="Search by job name or UUID">

                    <a v-if="search" href="#" @click.prevent="search = ''" class="clear-search">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </div>
            </div>

            <ul class="nav nav-pills card-bg-secondary">
                <li class="nav-item" v-for="candidate in statuses" :key="candidate.value">
                    <a href="#" class="nav-link" :class="{ active: status === candidate.value }" @click.prevent="status = candidate.value">{{ candidate.label }}</a>
                </li>
            </ul>

            <loader v-if="!ready" />

            <empty-state v-if="ready && items.length == 0">{{ emptyMessage }}</empty-state>

            <table v-if="ready && items.length > 0" class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Queue</th>
                        <th>Queued</th>
                        <th>Finished</th>
                        <th class="text-end">Wait</th>
                        <th class="text-end">Runtime</th>
                    </tr>
                </thead>

                <tbody>
                    <new-entries v-if="hasNewEntries" @load="reset" />

                    <tr v-for="job in items" :key="job.id">
                        <td>
                            <router-link :title="job.name" :to="{ name: 'job-preview', params: { uuid: job.uuid } }">{{ jobBaseName(job.name) }}</router-link>

                            <small class="badge badge-secondary badge-sm ms-1" v-if="job.attempt > 1" :title="`Attempt #${job.attempt}`">Attempt {{ job.attempt }}</small>

                            <br>

                            <small class="text-muted">
                                {{ job.uuid.split('-')[0] }}
                                <span v-if="job.batch_id">
                                    | Batch: <router-link :to="{ name: 'batches-preview', params: { batchId: job.batch_id } }">{{ job.batch_id.split('-')[0] }}</router-link>
                                </span>
                                <span v-if="job.tags.length" class="text-break">
                                    | Tags: {{ job.tags.slice(0, 3).join(', ') }}<span class="text-secondary" v-if="job.tags.length > 3"> +{{ job.tags.length - 3 }} more</span>
                                </span>
                            </small>
                        </td>
                        <td class="text-muted">{{ job.queue }}</td>
                        <td class="table-fit text-muted">{{ formatDate(job.queued_at) }}</td>
                        <td class="table-fit text-muted">{{ formatDate(job.finished_at) }}</td>
                        <td class="table-fit text-end text-muted">{{ formatDuration(job.wait_ms) }}</td>
                        <td class="table-fit text-end text-muted">{{ formatDuration(job.run_ms) }}</td>
                    </tr>
                </tbody>
            </table>

            <pagination v-if="ready && items.length > 0" :page="page" :has-previous="hasPrevious" :has-next="hasNext" @previous="previous" @next="next" />
        </div>
    </div>
</template>
