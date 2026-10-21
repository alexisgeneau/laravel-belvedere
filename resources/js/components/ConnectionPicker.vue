<script>
    import { state } from '../state';

    export default {
        computed: {
            connections() {
                return state.connections;
            },

            selected: {
                get() {
                    return state.connection ?? '';
                },

                set(value) {
                    state.connection = value === '' ? null : value;
                },
            },
        },
    };
</script>

<template>
    <select v-if="connections.length > 1" class="form-select form-select-sm me-2" v-model="selected" title="Queue connection">
        <option v-for="connection in connections" :key="connection" :value="connection">{{ connection }}</option>
    </select>
</template>
