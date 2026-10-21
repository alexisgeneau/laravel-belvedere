<script>
    import settings from '../settings';

    const HIDDEN_INTERVAL = 60 * 60 * 1000;

    export default {
        props: {
            interval: {
                type: Number,
                default: settings.refreshSeconds,
            },

            immediate: {
                type: Boolean,
                default: true,
            },
        },

        emits: ['poll'],

        data() {
            return {
                timer: null,
                lastRun: 0,
            };
        },

        beforeMount() {
            if (this.immediate) {
                this.run();
            }
        },

        mounted() {
            this.start();

            document.addEventListener('visibilitychange', this.visibilityChanged);
        },

        beforeUnmount() {
            this.stop();

            document.removeEventListener('visibilitychange', this.visibilityChanged);
        },

        methods: {
            run() {
                this.lastRun = Date.now();
                this.$emit('poll');
            },

            delay() {
                return document.visibilityState === 'hidden' ? HIDDEN_INTERVAL : this.interval * 1000;
            },

            start() {
                this.timer = setInterval(() => this.run(), this.delay());
            },

            stop() {
                clearInterval(this.timer);
                this.timer = null;
            },

            visibilityChanged() {
                this.stop();
                this.start();

                if (Date.now() - this.lastRun >= this.delay()) {
                    this.run();
                }
            },
        },

        render() {
            return null;
        },
    };
</script>
