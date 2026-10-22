<script>
    import { syncConnections } from '../state';
    import WorkersTable from './workers/table.vue';

    export default {
        components: {
            WorkersTable,
        },

        data() {
            return {
                ready: false,
                overview: null,
                workers: [],
                supervision: null,
                busyQueues: [],
                busyTargets: [],
            };
        },

        computed: {
            queues() {
                return this.overview?.queues ?? [];
            },

            stats() {
                const sum = (key) => this.queues.reduce((total, queue) => total + (queue[key] ?? 0), 0);

                return {
                    jobsPerMinute: Math.round(sum('jobs_per_minute') * 100) / 100,
                    processedPastHour: sum('processed_1h'),
                    failedPastDay: sum('failed_24h'),
                    backlog: sum('pending') + sum('delayed'),
                    running: sum('running'),
                    stuck: sum('stuck'),
                };
            },

            status() {
                if (!this.overview) {
                    return null;
                }

                // With a master supervisor, the status is the masters'; without, the workers tell.
                if (this.overview.supervision && this.overview.supervision.status !== 'none') {
                    return this.overview.supervision.status;
                }

                if (this.overview.workers.alive === 0) {
                    return 'inactive';
                }

                if (this.queues.length > 0 && this.queues.every((queue) => queue.paused)) {
                    return 'paused';
                }

                return 'running';
            },

            slowestWait() {
                return this.queues.reduce((slowest, queue) => (
                    queue.wait_ms_avg_1h !== null && (slowest === null || queue.wait_ms_avg_1h > slowest.wait_ms_avg_1h) ? queue : slowest
                ), null);
            },

            slowestRuntime() {
                return this.queues.reduce((slowest, queue) => (
                    queue.run_ms_avg_1h !== null && (slowest === null || queue.run_ms_avg_1h > slowest.run_ms_avg_1h) ? queue : slowest
                ), null);
            },

            busiestQueue() {
                return this.queues.reduce((busiest, queue) => (
                    queue.processed_1h > 0 && (busiest === null || queue.processed_1h > busiest.processed_1h) ? queue : busiest
                ), null);
            },

            masters() {
                return this.supervision?.masters ?? [];
            },
        },

        watch: {
            connection() {
                this.ready = false;
                this.load();
            },
        },

        mounted() {
            this.setTitle('Dashboard');
        },

        methods: {
            load() {
                return Promise.all([
                    this.$http.get('/overview'),
                    this.$http.get('/workers'),
                    this.$http.get('/supervisors'),
                ]).then(([overview, workers, supervision]) => {
                    this.overview = overview.data;
                    this.workers = workers.data.workers;
                    this.supervision = supervision.data;

                    syncConnections(overview.data.connections, overview.data.connection);

                    this.ready = true;
                });
            },

            isTargetBusy(target) {
                return this.busyTargets.includes(target);
            },

            order(target, request) {
                if (this.isTargetBusy(target)) {
                    return;
                }

                this.busyTargets.push(target);

                request()
                    .then(() => this.load())
                    .finally(() => {
                        this.busyTargets = this.busyTargets.filter((name) => name !== target);
                    });
            },

            toggleMaster(master) {
                const verb = master.status === 'paused' ? 'continue' : 'pause';

                this.order(master.name, () => this.$http.post(`/supervisors/${encodeURIComponent(master.name)}/${verb}`));
            },

            toggleSupervisor(master, supervisor) {
                const verb = supervisor.status === 'paused' ? 'continue' : 'pause';

                this.order(supervisor.full_name, () => this.$http.post(`/supervisors/${encodeURIComponent(master.name)}/${encodeURIComponent(supervisor.name)}/${verb}`));
            },

            processesByQueue(supervisor) {
                return Object.entries(supervisor.processes_by_queue).map(([key, count]) => `${key.substring(key.indexOf(':') + 1)} ${count}`).join(', ');
            },

            balancing(supervisor) {
                if (supervisor.balance === false || supervisor.balance === 'false') {
                    return 'none';
                }

                return supervisor.balance === 'auto' ? `auto (${supervisor.auto_scaling_strategy})` : supervisor.balance;
            },

            isBusy(queue) {
                return this.busyQueues.includes(queue.queue);
            },

            act(queue, request, done) {
                if (this.isBusy(queue)) {
                    return;
                }

                this.busyQueues.push(queue.queue);

                request()
                    .then(() => this.load())
                    .then(done)
                    .finally(() => {
                        this.busyQueues = this.busyQueues.filter((name) => name !== queue.queue);
                    });
            },

            pause(queue) {
                this.act(queue, () => this.$http.post(`/queues/${encodeURIComponent(queue.connection)}/${encodeURIComponent(queue.queue)}/pause`));
            },

            resume(queue) {
                this.act(queue, () => this.$http.post(`/queues/${encodeURIComponent(queue.connection)}/${encodeURIComponent(queue.queue)}/resume`));
            },

            purge(queue) {
                this.confirm(`Delete every pending and delayed job of the "${queue.queue}" queue? This cannot be undone.`, () => {
                    this.act(
                        queue,
                        () => this.$http.delete(`/queues/${encodeURIComponent(queue.connection)}/${encodeURIComponent(queue.queue)}`),
                        () => this.notify(`The "${queue.queue}" queue has been purged.`),
                    );
                });
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="load" />

        <div v-if="ready && !overview.supports_database_inspection" class="alert alert-warning">
            The <strong>{{ overview.connection }}</strong> connection does not use the database driver: only the size of its default queue is known and its jobs cannot be listed. History, metrics, failed jobs and workers remain available.
        </div>

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Overview</h2>

                <small class="text-muted" v-if="ready">{{ overview.connection }}</small>
            </div>

            <loader v-if="!ready" />

            <div class="card-bg-secondary" v-if="ready">
                <div class="d-flex">
                    <div class="w-25 stat">
                        <div class="p-4">
                            <small class="text-muted">Jobs Per Minute</small>
                            <p class="h4 mt-2 mb-0">{{ formatNumber(stats.jobsPerMinute) }}</p>
                        </div>
                    </div>

                    <div class="w-25 stat">
                        <div class="p-4">
                            <small class="text-muted">Jobs Past Hour</small>
                            <p class="h4 mt-2 mb-0">{{ formatNumber(stats.processedPastHour) }}</p>
                        </div>
                    </div>

                    <div class="w-25 stat">
                        <div class="p-4">
                            <small class="text-muted">Failed Jobs Past 24 Hours</small>
                            <p class="h4 mt-2 mb-0">
                                {{ formatNumber(stats.failedPastDay) }}
                                <small v-if="overview.lost_24h > 0">
                                    <router-link class="text-danger" :to="{ name: 'completed-jobs', query: { status: 'lost' } }" title="Jobs whose outcome was never reported">({{ overview.lost_24h }} lost)</router-link>
                                </small>
                            </p>
                        </div>
                    </div>

                    <div class="w-25 stat">
                        <div class="p-4">
                            <small class="text-muted">Status</small>

                            <div class="d-flex align-items-center mt-2">
                                <svg v-if="status == 'running'" xmlns="http://www.w3.org/2000/svg" class="text-success" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.5rem; height: 1.5rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>

                                <svg v-if="status == 'paused'" xmlns="http://www.w3.org/2000/svg" class="text-warning" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.5rem; height: 1.5rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9v6m-4.5 0V9M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>

                                <svg v-if="status == 'inactive'" xmlns="http://www.w3.org/2000/svg" class="text-danger" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 1.5rem; height: 1.5rem;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                </svg>

                                <p class="h4 mb-0 ms-2">{{ { running: 'Active', paused: 'Paused', inactive: 'Inactive' }[status] }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex">
                    <div class="w-25 stat">
                        <div class="p-4">
                            <small class="text-muted">Workers</small>
                            <p class="h4 mt-2 mb-0">
                                {{ formatNumber(overview.workers.alive) }}
                                <small class="text-danger" v-if="overview.workers.dead > 0">({{ overview.workers.dead }} dead)</small>
                            </p>
                            <small class="text-muted" v-if="overview.workers.supervised > 0">{{ overview.workers.supervised }} supervised</small>
                        </div>
                    </div>

                    <div class="w-25 stat">
                        <div class="p-4">
                            <small class="text-muted">Backlog</small>
                            <p class="h4 mt-2 mb-0">
                                {{ formatNumber(stats.backlog) }}
                                <small class="text-warning" v-if="stats.stuck > 0">({{ stats.stuck }} stuck)</small>
                            </p>
                        </div>
                    </div>

                    <div class="w-25 stat">
                        <div class="p-4">
                            <small class="text-muted">Max Wait Time</small>
                            <p class="h4 mt-2 mb-0">{{ slowestWait ? formatDuration(slowestWait.wait_ms_avg_1h) : '-' }}</p>
                            <small class="text-muted" v-if="slowestWait">({{ slowestWait.queue }})</small>
                        </div>
                    </div>

                    <div class="w-25 stat">
                        <div class="p-4">
                            <small class="text-muted">Max Runtime</small>
                            <p class="h4 mt-2 mb-0">{{ slowestRuntime ? formatDuration(slowestRuntime.run_ms_avg_1h) : '-' }}</p>
                            <small class="text-muted" v-if="slowestRuntime">({{ slowestRuntime.queue }})</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Current Workload</h2>

                <small class="text-muted" v-if="busiestQueue">Busiest queue: {{ busiestQueue.queue }}</small>
            </div>

            <empty-state v-if="queues.length == 0">No queue has been seen on this connection yet.</empty-state>

            <table v-else class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Queue</th>
                        <th class="text-end">Pending</th>
                        <th class="text-end">Delayed</th>
                        <th class="text-end">Running</th>
                        <th class="text-end">Jobs/min</th>
                        <th class="text-end">Failed (1h)</th>
                        <th class="text-end">Wait</th>
                        <th class="text-end">Runtime</th>
                        <th class="text-end" v-if="!readOnly"></th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="queue in queues" :key="queue.queue">
                        <td>
                            <router-link :to="{ name: 'metrics-preview', params: { type: 'queue', name: queue.queue } }">{{ queue.queue }}</router-link>
                            <status-badge class="ms-2" status="paused" v-if="queue.paused" />
                            <small class="badge badge-warning badge-sm ms-2" v-if="queue.stuck > 0">{{ queue.stuck }} stuck</small>
                        </td>
                        <td class="text-end text-muted">{{ formatNumber(queue.pending) }}</td>
                        <td class="text-end text-muted">{{ formatNumber(queue.delayed) }}</td>
                        <td class="text-end text-muted">{{ formatNumber(queue.running) }}</td>
                        <td class="text-end text-muted">{{ formatNumber(queue.jobs_per_minute) }}</td>
                        <td class="text-end" :class="queue.failed_1h > 0 ? 'text-danger' : 'text-muted'">{{ formatNumber(queue.failed_1h) }}</td>
                        <td class="text-end text-muted">{{ formatDuration(queue.wait_ms_avg_1h) }}</td>
                        <td class="text-end text-muted">{{ formatDuration(queue.run_ms_avg_1h) }}</td>
                        <td class="text-end table-fit" v-if="!readOnly">
                            <a href="#" class="control-action" :title="queue.paused ? 'Resume Queue' : 'Pause Queue'" @click.prevent="queue.paused ? resume(queue) : pause(queue)">
                                <svg v-if="queue.paused" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isBusy(queue) }">
                                    <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm6.39-2.908a.75.75 0 0 1 .766.027l3.5 2.25a.75.75 0 0 1 0 1.262l-3.5 2.25A.75.75 0 0 1 8 12.25v-4.5a.75.75 0 0 1 .39-.658Z" clip-rule="evenodd" />
                                </svg>
                                <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isBusy(queue) }">
                                    <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm5-2.25A.75.75 0 0 1 7.75 7h.5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-.75.75h-.5a.75.75 0 0 1-.75-.75v-4.5Zm4 0a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-.75.75h-.5a.75.75 0 0 1-.75-.75v-4.5Z" clip-rule="evenodd" />
                                </svg>
                            </a>

                            <a href="#" class="control-action control-action-danger" title="Purge Queue" @click.prevent="purge(queue)">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card overflow-hidden mt-4" v-for="master in masters" :key="master.name">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">
                    Supervisor <span class="text-muted">{{ master.name }}</span>
                    <small class="text-muted ms-2">{{ master.hostname }} · {{ master.environment }} · {{ master.processes }} processes</small>
                    <status-badge class="ms-2" :status="master.status" />
                </h2>

                <small class="text-muted" v-if="master.status == 'inactive'" :title="formatDate(master.last_seen_at)">Last seen {{ fromNow(master.last_seen_at) }}</small>

                <a href="#" class="control-action" v-else-if="!readOnly" :title="master.status == 'paused' ? 'Continue every supervisor' : 'Pause every supervisor'" @click.prevent="toggleMaster(master)">
                    <svg v-if="master.status == 'paused'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isTargetBusy(master.name) }">
                        <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm6.39-2.908a.75.75 0 0 1 .766.027l3.5 2.25a.75.75 0 0 1 0 1.262l-3.5 2.25A.75.75 0 0 1 8 12.25v-4.5a.75.75 0 0 1 .39-.658Z" clip-rule="evenodd" />
                    </svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isTargetBusy(master.name) }">
                        <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm5-2.25A.75.75 0 0 1 7.75 7h.5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-.75.75h-.5a.75.75 0 0 1-.75-.75v-4.5Zm4 0a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-.75.75h-.5a.75.75 0 0 1-.75-.75v-4.5Z" clip-rule="evenodd" />
                    </svg>
                </a>
            </div>

            <empty-state v-if="master.supervisors.length == 0">This master has no supervisor: nothing is configured for its environment.</empty-state>

            <table v-else class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Supervisor</th>
                        <th>Processes</th>
                        <th>Queues</th>
                        <th>Balancing</th>
                        <th>Range</th>
                        <th>Status</th>
                        <th class="text-end" v-if="!readOnly && master.status != 'inactive'"></th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="supervisor in master.supervisors" :key="supervisor.full_name">
                        <td>{{ supervisor.name }}</td>
                        <td class="text-muted">
                            {{ supervisor.processes }}
                            <small v-if="Object.keys(supervisor.processes_by_queue).length > 1">({{ processesByQueue(supervisor) }})</small>
                        </td>
                        <td class="text-muted">{{ supervisor.queues.join(', ') }}</td>
                        <td class="text-muted">{{ balancing(supervisor) }}</td>
                        <td class="text-muted">
                            {{ supervisor.min_processes }} to {{ supervisor.max_processes }}
                            <small class="badge badge-warning badge-sm ms-1" title="Every process of this pool is running: more workers would not help, the limit is the database." v-if="supervisor.at_max && supervisor.status == 'running'">at max</small>
                        </td>
                        <td><status-badge :status="supervisor.status" /></td>
                        <td class="text-end table-fit" v-if="!readOnly && master.status != 'inactive'">
                            <a href="#" class="control-action" v-if="supervisor.status != 'inactive'" :title="supervisor.status == 'paused' ? 'Continue Supervisor' : 'Pause Supervisor'" @click.prevent="toggleSupervisor(master, supervisor)">
                                <svg v-if="supervisor.status == 'paused'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isTargetBusy(supervisor.full_name) }">
                                    <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm6.39-2.908a.75.75 0 0 1 .766.027l3.5 2.25a.75.75 0 0 1 0 1.262l-3.5 2.25A.75.75 0 0 1 8 12.25v-4.5a.75.75 0 0 1 .39-.658Z" clip-rule="evenodd" />
                                </svg>
                                <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" :class="{ spin: isTargetBusy(supervisor.full_name) }">
                                    <path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm5-2.25A.75.75 0 0 1 7.75 7h.5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-.75.75h-.5a.75.75 0 0 1-.75-.75v-4.5Zm4 0a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 .75.75v4.5a.75.75 0 0 1-.75.75h-.5a.75.75 0 0 1-.75-.75v-4.5Z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card overflow-hidden mt-4" v-if="ready">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Workers</h2>

                <router-link :to="{ name: 'workers' }">Manage</router-link>
            </div>

            <empty-state v-if="workers.length == 0">No worker has reported yet. Workers appear as soon as <code>queue:work</code> runs with Belvedere installed.</empty-state>

            <workers-table v-else :workers="workers" compact />
        </div>
    </div>
</template>
