<script>
    export default {
        props: {
            page: {
                type: Number,
                required: true,
            },

            hasPrevious: Boolean,
            hasNext: Boolean,
        },

        emits: ['previous', 'next'],
    };
</script>

<template>
    <div class="p-3 d-flex justify-content-between align-items-center border-top">
        <button @click="$emit('previous')" class="btn btn-secondary btn-sm" :disabled="!hasPrevious">Previous</button>
        <small class="text-muted">Page {{ page }}</small>
        <button @click="$emit('next')" class="btn btn-secondary btn-sm" :disabled="!hasNext">Next</button>
    </div>
</template>
