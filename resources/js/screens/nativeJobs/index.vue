<script>
    import listing from '../../mixins/listing';

    const LABELS = {
        pending: 'Pending',
        delayed: 'Delayed',
        running: 'Running',
        stuck: 'Stuck',
    };

    const EMPTY = {
        pending: "There aren't any pending jobs.",
        delayed: "There aren't any delayed jobs.",
        running: "There aren't any running jobs.",
        stuck: "There aren't any stuck jobs.",
    };

    export default {
        mixins: [listing],

        props: {
            states: {
                type: Array,
                required: true,
            },

            title: {
                type: String,
                required: true,
            },
        },

        data() {
            return {
                live: true,
                state: this.states[0],
                unsupported: false,
                busy: [],
            };
        },

        computed: {
            labels() {
                return LABELS;
            },

            emptyMessage() {
                return EMPTY[this.state];
            },

            detailRoute() {
                return this.states[0] === 'pending' ? 'pending-job-preview' : 'running-job-preview';
            },

            showsReservation() {
                return ['running', 'stuck'].includes(this.state);
            },
        },

        watch: {
            state() {
                this.reset();
            },

            states() {
                this.state = this.states[0];
                this.setTitle(this.title);
            },
        },

        mounted() {
            this.setTitle(this.title);
            this.load();
        },

        methods: {
            fetch(cursor) {
                return this.$http.get(`/jobs/${this.state}`, { params: { before: cursor, limit: this.perPage } })
                    .then((response) => {
                        this.unsupported = false;

                        return { items: response.data.jobs, nextCursor: response.data.next_before };
                    })
                    .catch((error) => {
                        if (error.response?.status === 409) {
                            this.unsupported = true;

                            return { items: [], nextCursor: null };
                        }

                        throw error;
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

            runNow(job) {
                this.act(job, () => this.$http.post(`/jobs/${encodeURIComponent(job.connection)}/${job.id}/run-now`));
            },

            release(job) {
                this.act(job, () => this.$http.post(`/jobs/${encodeURIComponent(job.connection)}/${job.id}/release`));
            },

            remove(job) {
                this.confirm(`Delete the job "${this.jobBaseName(job.name)}" from the queue? It will never run.`, () => {
                    this.act(job, () => this.$http.delete(`/jobs/${encodeURIComponent(job.connection)}/${job.id}`));
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
                <h2 class="h6 m-0">{{ title }}</h2>
            </div>

            <ul class="nav nav-pills card-bg-secondary">
                <li class="nav-item" v-for="candidate in states" :key="candidate">
                    <a href="#" class="nav-link" :class="{ active: state === candidate }" @click.prevent="state = candidate">{{ labels[candidate] }}</a>
                </li>
            </ul>

            <loader v-if="!ready" />

            <empty-state v-if="ready && unsupported">
                This queue connection does not use the database driver, so its jobs cannot be listed.
            </empty-state>

            <empty-state v-if="ready && !unsupported && items.length == 0">{{ emptyMessage }}</empty-state>

            <table v-if="ready && items.length > 0" class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Queue</th>
                        <th class="text-end">Attempts</th>
                        <th>Queued</th>
                        <th v-if="state == 'delayed'">Available</th>
                        <th v-if="showsReservation">Reserved</th>
                        <th class="text-end" v-if="!readOnly"></th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="job in items" :key="job.id">
                        <td>
                            <router-link v-if="job.uuid" :title="job.name" :to="{ name: detailRoute, params: { uuid: job.uuid } }">{{ jobBaseName(job.name) }}</router-link>
                            <span v-else :title="job.name">{{ jobBaseName(job.name) }}</span>

                            <small class="badge badge-warning badge-sm ms-1" v-if="job.state == 'stuck'" :title="`Reserved ${fromNow(job.reserved_at)} and still not released`">Stuck</small>

                            <br>

                            <small class="text-muted">
                                #{{ job.id }}
                                <span v-if="job.batch_id">
                                    | Batch: <router-link :to="{ name: 'batches-preview', params: { batchId: job.batch_id } }">{{ job.batch_id.split('-')[0] }}</router-link>
                                </span>
                                <span v-if="job.tags.length" class="text-break">
                                    | Tags: {{ job.tags.slice(0, 3).join(', ') }}<span class="text-secondary" v-if="job.tags.length > 3"> +{{ job.tags.length - 3 }} more</span>
                                </span>
                            </small>
                        </td>
                        <td class="text-muted">{{ job.queue }}</td>
                        <td class="text-end text-muted">{{ job.attempts }}<span v-if="job.max_tries"> / {{ job.max_tries }}</span></td>
                        <td class="table-fit text-muted" :title="formatDate(job.created_at)">{{ fromNow(job.created_at) }}</td>
                        <td class="table-fit text-muted" v-if="state == 'delayed'" :title="formatDate(job.available_at)">{{ fromNow(job.available_at) }}</td>
                        <td class="table-fit text-muted" v-if="showsReservation" :title="formatDate(job.reserved_at)">{{ fromNow(job.reserved_at) }}</td>
                        <td class="text-end table-fit" v-if="!readOnly">
                            <a href="#" class="control-action" title="Run Now" v-if="job.state == 'delayed'" @click.prevent="runNow(job)">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isBusy(job) }">
                                    <path d="M3.288 4.818A1.5 1.5 0 0 0 1 6.095v7.81a1.5 1.5 0 0 0 2.288 1.276l6.323-3.905c.155-.096.285-.213.389-.344v2.973a1.5 1.5 0 0 0 2.288 1.276l6.323-3.905a1.5 1.5 0 0 0 0-2.552l-6.323-3.906A1.5 1.5 0 0 0 10 6.095v2.972a1.506 1.506 0 0 0-.389-.343L3.288 4.818Z" />
                                </svg>
                            </a>

                            <a href="#" class="control-action" title="Release Back To The Queue" v-if="job.state == 'stuck'" @click.prevent="release(job)">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isBusy(job) }">
                                    <path fill-rule="evenodd" d="M7.793 2.232a.75.75 0 0 1-.025 1.06L3.622 7.25h10.003a5.375 5.375 0 0 1 0 10.75H10.75a.75.75 0 0 1 0-1.5h2.875a3.875 3.875 0 0 0 0-7.75H3.622l4.146 3.957a.75.75 0 0 1-1.036 1.085l-5.5-5.25a.75.75 0 0 1 0-1.085l5.5-5.25a.75.75 0 0 1 1.06.025Z" clip-rule="evenodd" />
                                </svg>
                            </a>

                            <a href="#" class="control-action control-action-danger" title="Delete Job" v-if="job.state != 'running'" @click.prevent="remove(job)">
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
