import axios from 'axios';
import settings from './settings';
import { state } from './state';

const token = document.head.querySelector('meta[name="csrf-token"]');

const http = axios.create({
    baseURL: `${settings.basePath}/api`,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token.content } : {}),
    },
});

http.interceptors.request.use((config) => {
    if (state.connection && !(config.params && 'connection' in config.params)) {
        config.params = { ...config.params, connection: state.connection };
    }

    return config;
});

export function errorMessage(error) {
    if (!error.response) {
        return 'Belvedere could not reach the server.';
    }

    const { status, data } = error.response;

    if (status === 401 || status === 419) {
        return 'Your session has expired. Reload the page to sign in again.';
    }

    return data?.message || `The server responded with an error (HTTP ${status}).`;
}

export default http;
