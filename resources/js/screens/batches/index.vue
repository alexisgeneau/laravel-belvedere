<script>
    import listing from '../../mixins/listing';
    import BatchStatus from './status.vue';

    export default {
        mixins: [listing],

        components: {
            BatchStatus,
        },

        mounted() {
            this.setTitle('Batches');
            this.load();
        },

        methods: {
            fetch(cursor) {
                return this.$http.get('/batches', { params: { before: cursor, limit: this.perPage } })
                    .then((response) => ({ items: response.data.batches, nextCursor: response.data.next_before }));
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="refresh" :immediate="false" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Batches</h2>
            </div>

            <loader v-if="!ready" />

            <empty-state v-if="ready && items.length == 0">There aren't any batches.</empty-state>

            <table v-if="ready && items.length > 0" class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Status</th>
                        <th class="text-end">Size</th>
                        <th class="text-end">Failed</th>
                        <th style="width: 220px;">Completion</th>
                        <th class="text-end">Created</th>
                    </tr>
                </thead>

                <tbody>
                    <new-entries v-if="hasNewEntries" @load="reset" />

                    <tr v-for="batch in items" :key="batch.id">
                        <td>
                            <router-link :title="batch.id" :to="{ name: 'batches-preview', params: { batchId: batch.id } }">{{ batch.name || batch.id }}</router-link>
                            <br v-if="batch.name">
                            <small class="text-muted" v-if="batch.name">{{ batch.id }}</small>
                        </td>
                        <td><batch-status :batch="batch" /></td>
                        <td class="text-end text-muted">{{ formatNumber(batch.total_jobs) }}</td>
                        <td class="text-end" :class="batch.failed_jobs > 0 ? 'text-danger' : 'text-muted'">{{ formatNumber(batch.failed_jobs) }}</td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="progress flex-grow-1 me-2">
                                    <div class="progress-bar" :class="batch.failed_jobs > 0 ? 'bg-danger' : 'bg-success'" :style="{ width: `${batch.progress}%` }"></div>
                                </div>
                                <small class="text-muted">{{ batch.progress }}%</small>
                            </div>
                        </td>
                        <td class="text-end table-fit text-muted">{{ formatDate(batch.created_at) }}</td>
                    </tr>
                </tbody>
            </table>

            <pagination v-if="ready && items.length > 0" :page="page" :has-previous="hasPrevious" :has-next="hasNext" @previous="previous" @next="next" />
        </div>
    </div>
</template>
