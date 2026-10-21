<script>
    export default {
        props: {
            batch: {
                type: Object,
                required: true,
            },
        },

        computed: {
            status() {
                if (this.batch.cancelled) {
                    return { label: 'Cancelled', badge: 'badge-warning' };
                }

                if (this.batch.finished) {
                    return this.batch.failed_jobs > 0
                        ? { label: 'Finished with failures', badge: 'badge-danger' }
                        : { label: 'Finished', badge: 'badge-success' };
                }

                return this.batch.failed_jobs > 0
                    ? { label: 'Failures', badge: 'badge-danger' }
                    : { label: 'Pending', badge: 'badge-secondary' };
            },
        },
    };
</script>

<template>
    <small class="badge badge-sm" :class="status.badge">{{ status.label }}</small>
</template>
