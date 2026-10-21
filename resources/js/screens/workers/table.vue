<script>
    export default {
        props: {
            workers: {
                type: Array,
                required: true,
            },

            compact: Boolean,
        },

        emits: ['forget'],

        methods: {
            canForget(worker) {
                return !this.readOnly && !this.compact && ['dead', 'stopped'].includes(worker.status);
            },
        },
    };
</script>

<template>
    <table class="table table-hover mb-0">
        <thead>
            <tr>
                <th>Worker</th>
                <th>Queues</th>
                <th>Status</th>
                <th class="text-end">Processed</th>
                <th class="text-end" v-if="!compact">Memory</th>
                <th>Current Job</th>
                <th class="text-end" v-if="!compact">Started</th>
                <th class="text-end">Last Seen</th>
                <th v-if="!compact && !readOnly"></th>
            </tr>
        </thead>

        <tbody>
            <tr v-for="worker in workers" :key="worker.id">
                <td>
                    {{ worker.hostname }}<span class="text-muted">:{{ worker.pid }}</span>
                    <br>
                    <small class="text-muted">Connection: {{ worker.connection }}</small>
                </td>
                <td class="text-muted">{{ worker.queues.join(', ') }}</td>
                <td><status-badge :status="worker.status" /></td>
                <td class="text-end text-muted">{{ formatNumber(worker.jobs_processed) }}</td>
                <td class="text-end text-muted" v-if="!compact">{{ formatMemory(worker.memory_kb) }}</td>
                <td>
                    <template v-if="worker.current_job">
                        <router-link :title="worker.current_job.name" :to="{ name: 'running-job-preview', params: { uuid: worker.current_job.uuid } }">
                            {{ jobBaseName(worker.current_job.name) }}
                        </router-link>
                        <br>
                        <small class="text-muted">{{ worker.current_job.queue }} · started {{ fromNow(worker.current_job.started_at) }}</small>
                    </template>
                    <span class="text-muted" v-else>-</span>
                </td>
                <td class="text-end text-muted table-fit" v-if="!compact">{{ formatDate(worker.started_at) }}</td>
                <td class="text-end text-muted table-fit" :title="formatDate(worker.last_seen_at)">{{ fromNow(worker.last_seen_at) }}</td>
                <td class="text-end table-fit" v-if="!compact && !readOnly">
                    <a href="#" class="control-action control-action-danger" title="Forget Worker" v-if="canForget(worker)" @click.prevent="$emit('forget', worker)">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd" />
                        </svg>
                    </a>
                </td>
            </tr>
        </tbody>
    </table>
</template>
