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
            };
        },

        computed: {
            alive() {
                return this.workers.filter((worker) => ['running', 'paused'].includes(worker.status)).length;
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

                <small class="text-muted" v-if="ready">{{ alive }} alive / {{ workers.length }} known</small>
            </div>

            <loader v-if="!ready" />

            <empty-state v-if="ready && workers.length == 0">
                No worker has reported yet. Workers appear as soon as <code>queue:work</code> runs with Belvedere installed.
            </empty-state>

            <workers-table v-if="ready && workers.length > 0" :workers="workers" @forget="forget" />
        </div>
    </div>
</template>
