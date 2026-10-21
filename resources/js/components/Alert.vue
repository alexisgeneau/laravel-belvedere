<script>
    import { Modal } from 'bootstrap';

    export default {
        props: ['type', 'message', 'autoClose', 'confirmationProceed', 'confirmationCancel'],

        data() {
            return {
                timeout: null,
                modal: null,
                anotherModalOpened: document.body.classList.contains('modal-open'),
            };
        },

        mounted() {
            const element = this.$refs.modal;

            this.modal = Modal.getOrCreateInstance(element, { backdrop: 'static' });
            this.modal.show();

            element.addEventListener('hidden.bs.modal', () => {
                this.$root.alert = {
                    type: null,
                    message: '',
                    autoClose: 0,
                    confirmationProceed: null,
                    confirmationCancel: null,
                };

                if (this.anotherModalOpened) {
                    document.body.classList.add('modal-open');
                }
            });

            if (this.autoClose) {
                this.timeout = setTimeout(() => this.close(), this.autoClose);
            }
        },

        beforeUnmount() {
            clearTimeout(this.timeout);
            this.modal.dispose();
        },

        methods: {
            close() {
                clearTimeout(this.timeout);
                this.modal.hide();
            },

            confirm() {
                this.confirmationProceed();
                this.close();
            },

            cancel() {
                if (this.confirmationCancel) {
                    this.confirmationCancel();
                }

                this.close();
            },
        },
    };
</script>

<template>
    <div class="modal" id="alertModal" ref="modal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <p class="m-0 py-4">{{ message }}</p>
                </div>

                <div class="modal-footer justify-content-start flex-row-reverse">
                    <button v-if="type == 'error'" class="btn btn-primary" @click="close">Close</button>
                    <button v-if="type == 'success'" class="btn btn-primary" @click="close">Okay</button>
                    <button v-if="type == 'confirmation'" class="btn btn-danger" @click="confirm">Yes</button>
                    <button v-if="type == 'confirmation'" class="btn" @click="cancel">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
    #alertModal {
        z-index: 99999;
        background: rgba(0, 0, 0, 0.5);
    }
</style>
