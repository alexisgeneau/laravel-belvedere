<script>
    const CLASSES = {
        completed: 'badge-success',
        running: 'badge-info',
        queued: 'badge-secondary',
        pending: 'badge-secondary',
        delayed: 'badge-secondary',
        released: 'badge-warning',
        stuck: 'badge-warning',
        paused: 'badge-warning',
        failed: 'badge-danger',
        timed_out: 'badge-danger',
        lost: 'badge-danger',
        dead: 'badge-danger',
        stopped: 'badge-secondary',
        inactive: 'badge-danger',
    };

    export default {
        props: {
            status: {
                type: String,
                required: true,
            },
        },

        computed: {
            badgeClass() {
                return CLASSES[this.status] ?? 'badge-secondary';
            },
        },
    };
</script>

<template>
    <small class="badge badge-sm" :class="badgeClass">{{ humanStatus(status) }}</small>
</template>
