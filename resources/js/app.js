import { createApp } from 'vue/dist/vue.esm-bundler.js';
import { createRouter, createWebHistory } from 'vue-router';
import VueJsonPretty from 'vue-json-pretty';
import 'vue-json-pretty/lib/styles.css';
import http, { errorMessage } from './api';
import settings from './settings';
import Base from './base';
import Routes from './routes';
import Alert from './components/Alert.vue';
import ConnectionPicker from './components/ConnectionPicker.vue';
import EmptyState from './components/EmptyState.vue';
import Loader from './components/Loader.vue';
import NewEntries from './components/NewEntries.vue';
import Pagination from './components/Pagination.vue';
import Poll from './components/Poll.vue';
import SchemeToggler from './components/SchemeToggler.vue';
import StatusBadge from './components/StatusBadge.vue';

const AUTOLOAD_KEY = 'belvedereAutoLoadsNewEntries';

const app = createApp({
    data() {
        return {
            alert: {
                type: null,
                message: '',
                autoClose: 0,
                confirmationProceed: null,
                confirmationCancel: null,
            },
            autoLoadsNewEntries: localStorage.getItem(AUTOLOAD_KEY) === '1',
        };
    },

    methods: {
        autoLoadNewEntries() {
            this.autoLoadsNewEntries = !this.autoLoadsNewEntries;
            localStorage.setItem(AUTOLOAD_KEY, this.autoLoadsNewEntries ? '1' : '0');
        },
    },
});

app.config.globalProperties.$http = http;

app.use(createRouter({
    history: createWebHistory(`${settings.basePath}/`),
    routes: Routes,
}));

app.component('vue-json-pretty', VueJsonPretty);
app.component('alert', Alert);
app.component('connection-picker', ConnectionPicker);
app.component('empty-state', EmptyState);
app.component('loader', Loader);
app.component('new-entries', NewEntries);
app.component('pagination', Pagination);
app.component('poll', Poll);
app.component('scheme-toggler', SchemeToggler);
app.component('status-badge', StatusBadge);

app.mixin(Base);

const root = app.mount('#belvedere');

http.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.config?.method !== 'get') {
            root.alert = {
                type: 'error',
                message: errorMessage(error),
                autoClose: 0,
                confirmationProceed: null,
                confirmationCancel: null,
            };
        }

        return Promise.reject(error);
    },
);
