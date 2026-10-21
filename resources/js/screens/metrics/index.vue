<script>
    import MetricsRange from './range.vue';

    export default {
        components: {
            MetricsRange,
        },

        created() {
            this.setTitle('Metrics');
        },
    };
</script>

<template>
    <div>
        <div class="card overflow-hidden">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h2 class="h6 m-0">Metrics</h2>

                <metrics-range />
            </div>

            <ul class="nav nav-pills card-bg-secondary">
                <li class="nav-item">
                    <router-link class="nav-link" active-class="active" :to="{ name: 'metrics-jobs' }">Jobs</router-link>
                </li>

                <li class="nav-item">
                    <router-link class="nav-link" active-class="active" :to="{ name: 'metrics-queues' }">Queues</router-link>
                </li>
            </ul>

            <router-view />
        </div>
    </div>
</template>
