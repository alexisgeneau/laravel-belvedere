import { reactive, watch } from 'vue';

const CONNECTION_KEY = 'belvedereConnection';
const METRICS_RANGE_KEY = 'belvedereMetricsRange';

const remembered = (key) => {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
};

const remember = (key, value) => {
    try {
        value === null ? localStorage.removeItem(key) : localStorage.setItem(key, value);
    } catch {
        // Storage may be unavailable (private mode, disabled cookies): the preference is simply not kept.
    }
};

export const state = reactive({
    connection: remembered(CONNECTION_KEY),
    connections: [],
    metricsRange: remembered(METRICS_RANGE_KEY) ?? '1h',
});

export function syncConnections(connections, current) {
    state.connections = connections;

    if (!connections.includes(state.connection)) {
        state.connection = current;
    }
}

watch(() => state.connection, (connection) => remember(CONNECTION_KEY, connection));
watch(() => state.metricsRange, (range) => remember(METRICS_RANGE_KEY, range));
