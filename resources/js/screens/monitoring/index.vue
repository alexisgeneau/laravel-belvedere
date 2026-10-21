<script>
    import { Modal } from 'bootstrap';

    export default {
        data() {
            return {
                ready: false,
                newTag: '',
                modal: null,
                tags: [],
            };
        },

        mounted() {
            this.setTitle('Monitoring');
        },

        beforeUnmount() {
            this.modal?.dispose();
        },

        methods: {
            load() {
                return this.$http.get('/monitoring').then((response) => {
                    this.tags = response.data.tags;
                    this.ready = true;
                });
            },

            openModal() {
                this.modal = Modal.getOrCreateInstance(this.$refs.modal, { backdrop: 'static' });
                this.modal.show();

                this.$refs.modal.addEventListener('shown.bs.modal', () => this.$refs.input.focus(), { once: true });
            },

            closeModal() {
                this.modal?.hide();
                this.newTag = '';
            },

            monitor() {
                const tag = this.newTag.trim();

                if (tag === '') {
                    this.$refs.input.focus();

                    return;
                }

                this.$http.post('/monitoring', { tag })
                    .then(() => {
                        this.closeModal();

                        return this.load();
                    });
            },

            stopMonitoring(tag) {
                this.confirm(`Stop monitoring the tag "${tag}"? Its jobs stay in the history.`, () => {
                    this.$http.delete(`/monitoring/${encodeURIComponent(tag)}`).then(() => this.load());
                });
            },
        },
    };
</script>

<template>
    <div>
        <poll @poll="load" />

        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Monitoring</h2>

                <button @click="openModal" class="btn btn-primary btn-sm" v-if="!readOnly">Monitor Tag</button>
            </div>

            <loader v-if="!ready" />

            <empty-state v-if="ready && tags.length == 0">You're not monitoring any tags.</empty-state>

            <table v-if="ready && tags.length > 0" class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th class="text-end">Jobs</th>
                        <th class="text-end">Since</th>
                        <th class="text-end" v-if="!readOnly"></th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="tag in tags" :key="tag.tag">
                        <td>
                            <router-link :to="{ name: 'monitoring-jobs', params: { tag: tag.tag } }">{{ tag.tag }}</router-link>
                        </td>
                        <td class="text-end text-muted">{{ formatNumber(tag.jobs) }}</td>
                        <td class="text-end text-muted table-fit">{{ formatDate(tag.created_at) }}</td>
                        <td class="text-end table-fit" v-if="!readOnly">
                            <a href="#" @click.prevent="stopMonitoring(tag.tag)" class="control-action control-action-danger" title="Stop Monitoring">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="modal" ref="modal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">Monitor New Tag</div>

                    <div class="modal-body">
                        <input type="text" class="form-control" placeholder="App\Models\User:6352" ref="input" v-on:keyup.enter="monitor" v-model="newTag">
                    </div>

                    <div class="modal-footer justify-content-start flex-row-reverse">
                        <button class="btn btn-primary" @click="monitor">Monitor</button>
                        <button class="btn" @click="closeModal">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
