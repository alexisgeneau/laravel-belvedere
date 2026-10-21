export default {
    data() {
        return {
            ready: false,
            items: [],
            perPage: 50,
            cursor: null,
            nextCursor: null,
            previousCursors: [],
            hasNewEntries: false,
            live: false,
        };
    },

    computed: {
        page() {
            return this.previousCursors.length + 1;
        },

        hasPrevious() {
            return this.previousCursors.length > 0;
        },

        hasNext() {
            return this.nextCursor !== null;
        },
    },

    watch: {
        connection() {
            this.reset();
        },

        '$root.autoLoadsNewEntries'(autoLoads) {
            if (autoLoads && this.hasNewEntries) {
                this.reset();
            }
        },
    },

    methods: {
        load() {
            this.ready = false;

            return this.fetch(this.cursor).then((page) => {
                this.apply(page);
                this.ready = true;
            });
        },

        refresh() {
            if (this.page !== 1) {
                return;
            }

            this.fetch(null).then((page) => {
                if (this.announcesNewEntries(page.items)) {
                    this.hasNewEntries = true;
                } else {
                    this.apply(page);
                }

                this.ready = true;
            });
        },

        announcesNewEntries(items) {
            return !this.live
                && !this.$root.autoLoadsNewEntries
                && this.items.length > 0
                && items.length > 0
                && this.itemKey(items[0]) !== this.itemKey(this.items[0]);
        },

        apply({ items, nextCursor }) {
            this.items = items;
            this.nextCursor = items.length >= this.perPage ? nextCursor ?? null : null;
            this.hasNewEntries = false;
        },

        reset() {
            this.cursor = null;
            this.previousCursors = [];
            this.hasNewEntries = false;

            return this.load();
        },

        next() {
            this.previousCursors.push(this.cursor);
            this.cursor = this.nextCursor;
            this.hasNewEntries = false;

            this.load();
        },

        previous() {
            this.cursor = this.previousCursors.pop() ?? null;
            this.hasNewEntries = false;

            this.load();
        },

        itemKey(item) {
            return item.id;
        },
    },
};
