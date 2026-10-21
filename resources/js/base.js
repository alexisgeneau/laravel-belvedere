import settings from './settings';
import { state } from './state';

const pad = (value) => String(value).padStart(2, '0');

const relativeFormatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

const RELATIVE_UNITS = [
    ['year', 31536000],
    ['month', 2592000],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
    ['second', 1],
];

export default {
    computed: {
        Belvedere() {
            return settings;
        },

        connection() {
            return state.connection;
        },

        readOnly() {
            return settings.readOnly;
        },
    },

    methods: {
        setTitle(title) {
            document.title = `${title} - Belvedere`;
        },

        formatDate(iso) {
            if (!iso) {
                return '-';
            }

            const date = new Date(iso);

            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
        },

        fromNow(iso) {
            if (!iso) {
                return '-';
            }

            const seconds = (new Date(iso).getTime() - Date.now()) / 1000;

            for (const [unit, size] of RELATIVE_UNITS) {
                if (Math.abs(seconds) >= size || unit === 'second') {
                    return relativeFormatter.format(Math.round(seconds / size), unit);
                }
            }
        },

        formatDuration(milliseconds) {
            if (milliseconds === null || milliseconds === undefined) {
                return '-';
            }

            if (milliseconds < 1000) {
                return `${Math.round(milliseconds)}ms`;
            }

            const seconds = milliseconds / 1000;

            if (seconds < 60) {
                return `${seconds.toFixed(2)}s`;
            }

            const minutes = Math.floor(seconds / 60);

            if (minutes < 60) {
                return `${minutes}m ${pad(Math.round(seconds % 60))}s`;
            }

            return `${Math.floor(minutes / 60)}h ${pad(minutes % 60)}m`;
        },

        formatNumber(value) {
            return Number(value ?? 0).toLocaleString();
        },

        formatMemory(kilobytes) {
            if (kilobytes === null || kilobytes === undefined) {
                return '-';
            }

            return kilobytes >= 1024 ? `${(kilobytes / 1024).toFixed(1)} MB` : `${kilobytes} KB`;
        },

        jobBaseName(name) {
            return name.includes('\\') ? name.split('\\').pop() : name;
        },

        upperFirst(value) {
            return value.charAt(0).toUpperCase() + value.slice(1);
        },

        humanStatus(status) {
            return this.upperFirst(String(status).replace('_', ' '));
        },

        confirm(message, proceed) {
            this.$root.alert = {
                type: 'confirmation',
                message,
                autoClose: 0,
                confirmationProceed: proceed,
                confirmationCancel: null,
            };
        },

        notify(message) {
            this.$root.alert = {
                type: 'success',
                message,
                autoClose: 2500,
                confirmationProceed: null,
                confirmationCancel: null,
            };
        },

        fail(message) {
            this.$root.alert = {
                type: 'error',
                message,
                autoClose: 0,
                confirmationProceed: null,
                confirmationCancel: null,
            };
        },
    },
};
