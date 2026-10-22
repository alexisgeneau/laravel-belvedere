<script>
    import WorkersTable from './table.vue';

    export default {
        components: {
            WorkersTable,
        },

        data() {
            return {
                ready: false,
                workers: [],
                supervisor: '',
            };
        },

        computed: {
            alive() {
                return this.workers.filter((worker) => ['running', 'paused'].includes(worker.status)).length;
            },

            supervisors() {
                return [...new Set(this.workers.map((worker) => worker.supervisor).filter(Boolean))].sort();
            },

            shown() {
                if (this.supervisor === '') {
                    return this.workers;
                }

                if (this.supervisor === 'none') {
                    return this.workers.filter((worker) => !worker.supervisor);
                }

                return this.workers.filter((worker) => worker.supervisor === this.supervisor);
            },
        },

        mounted() {
            this.setTitle('Workers');
        },

        methods: {
            load() {
                return this.$http.get('/workers').then((response) => {
                    this.workers = response.data.workers;
                    this.ready = true;
                });
            },

            forget(worker) {
                this.confirm(`Forget the worker ${worker.id}? It will reappear if it reports again.`, () => {
                    this.$http.delete(`/workers/${encodeURIComponent(worker.id)}`).then(() => this.load());
                });
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="load" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Workers</h2>

                <div class="d-flex align-items-center" v-if="ready">
                    <select class="form-select form-select-sm me-3" v-model="supervisor" v-if="supervisors.length > 0" title="Supervisor">
                        <option value="">Every supervisor</option>
                        <option v-for="name in supervisors" :key="name" :value="name">{{ name }}</option>
                        <option value="none">Started by hand</option>
                    </select>

                    <small class="text-muted text-nowrap">{{ alive }} alive / {{ workers.length }} known</small>
                </div>
            </div>

            <loader v-if="!ready" />

            <empty-state v-if="ready && workers.length == 0">
                No worker has reported yet. Workers appear as soon as <code>queue:work</code> runs with Belvedere installed.
            </empty-state>

            <empty-state v-if="ready && workers.length > 0 && shown.length == 0">No worker matches this supervisor.</empty-state>

            <workers-table v-if="ready && shown.length > 0" :workers="shown" @forget="forget" />
        </div>
    </div>
</template>
