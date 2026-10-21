<script>
    export default {
        props: {
            trace: {
                type: Array,
                required: true,
            },
        },

        data() {
            return {
                minimumLines: 5,
                showAll: false,
            };
        },

        computed: {
            lines() {
                return this.showAll ? this.trace : this.trace.slice(0, this.minimumLines);
            },

            truncated() {
                return !this.showAll && this.trace.length > this.minimumLines;
            },
        },
    };
</script>

<template>
    <div class="table-responsive">
        <table class="table mb-0">
            <tbody>
                <tr v-for="(line, index) in lines" :key="index">
                    <td class="card-bg-secondary text-break-all"><code>{{ line }}</code></td>
                </tr>

                <tr v-if="truncated">
                    <td class="card-bg-secondary"><a href="#" v-on:click.prevent="showAll = true">Show All</a></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
