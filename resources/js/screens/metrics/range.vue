<script>
    import { state } from '../../state';

    const RANGES = [
        { value: '1h', label: '1 hour' },
        { value: '24h', label: '24 hours' },
        { value: '7d', label: '7 days' },
    ];

    export default {
        computed: {
            ranges() {
                return RANGES;
            },

            range() {
                return state.metricsRange;
            },
        },

        methods: {
            select(range) {
                state.metricsRange = range;
            },
        },
    };
</script>

<template>
    <div class="btn-group" role="group" aria-label="Metrics range">
        <button v-for="candidate in ranges" :key="candidate.value" type="button" class="btn btn-muted btn-sm" :class="{ active: range === candidate.value }" @click="select(candidate.value)">
            {{ candidate.label }}
        </button>
    </div>
</template>
