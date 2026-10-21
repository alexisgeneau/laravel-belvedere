<script>
    import listing from '../../mixins/listing';

    export default {
        mixins: [listing],

        props: {
            type: {
                type: String,
                required: true,
            },
        },

        computed: {
            tag() {
                return this.$route.params.tag;
            },
        },

        watch: {
            type() {
                this.reset();
            },

            tag() {
                this.reset();
            },
        },

        mounted() {
            this.setTitle('Monitoring');
            this.load();
        },

        methods: {
            fetch(cursor) {
                const request = this.type === 'failed'
                    ? this.$http.get('/jobs/history', { params: { tag: this.tag, status: 'failed', cursor, limit: this.perPage } })
                    : this.$http.get(`/monitoring/${encodeURIComponent(this.tag)}/jobs`, { params: { cursor, limit: this.perPage } });

                return request.then((response) => ({ items: response.data.jobs, nextCursor: response.data.next_cursor }));
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="refresh" :immediate="false" />

        <loader v-if="!ready" />

        <empty-state v-if="ready && items.length == 0">There aren't any {{ type == 'failed' ? 'failed ' : '' }}jobs for this tag.</empty-state>

        <table v-if="ready && items.length > 0" class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Job</th>
                    <th>Queue</th>
                    <th>Status</th>
                    <th>Queued</th>
                    <th>Finished</th>
                    <th class="text-end">Runtime</th>
                </tr>
            </thead>

            <tbody>
                <new-entries v-if="hasNewEntries" @load="reset" />

                <tr v-for="job in items" :key="job.id">
                    <td>
                        <router-link :title="job.name" :to="{ name: 'job-preview', params: { uuid: job.uuid } }">{{ jobBaseName(job.name) }}</router-link>
                        <small class="badge badge-secondary badge-sm ms-1" v-if="job.attempt > 1">Attempt {{ job.attempt }}</small>
                    </td>
                    <td class="text-muted">{{ job.queue }}</td>
                    <td><status-badge :status="job.status" /></td>
                    <td class="table-fit text-muted">{{ formatDate(job.queued_at) }}</td>
                    <td class="table-fit text-muted">{{ formatDate(job.finished_at) }}</td>
                    <td class="table-fit text-end text-muted">{{ formatDuration(job.run_ms) }}</td>
                </tr>
            </tbody>
        </table>

        <pagination v-if="ready && items.length > 0" :page="page" :has-previous="hasPrevious" :has-next="hasNext" @previous="previous" @next="next" />
    </div>
</template>
