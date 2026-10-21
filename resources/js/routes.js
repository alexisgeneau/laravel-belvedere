import dashboard from './screens/dashboard.vue';
import monitoring from './screens/monitoring/index.vue';
import monitoringTag from './screens/monitoring/tag.vue';
import monitoringTagJobs from './screens/monitoring/tag-jobs.vue';
import metrics from './screens/metrics/index.vue';
import metricsSubjects from './screens/metrics/subjects.vue';
import metricsPreview from './screens/metrics/preview.vue';
import batches from './screens/batches/index.vue';
import batchesPreview from './screens/batches/preview.vue';
import nativeJobs from './screens/nativeJobs/index.vue';
import history from './screens/history/index.vue';
import jobDetail from './screens/history/job.vue';
import failedJobs from './screens/failedJobs/index.vue';
import failedJobDetail from './screens/failedJobs/job.vue';
import workers from './screens/workers/index.vue';

export default [
    { path: '/', redirect: '/dashboard' },

    {
        path: '/dashboard',
        name: 'dashboard',
        component: dashboard,
    },

    {
        path: '/monitoring',
        children: [
            {
                path: '',
                name: 'monitoring',
                component: monitoring,
            },
            {
                path: ':tag',
                component: monitoringTag,
                children: [
                    {
                        path: 'jobs',
                        name: 'monitoring-jobs',
                        component: monitoringTagJobs,
                        props: { type: 'jobs' },
                    },
                    {
                        path: 'failed',
                        name: 'monitoring-failed',
                        component: monitoringTagJobs,
                        props: { type: 'failed' },
                    },
                ],
            },
        ],
    },

    {
        path: '/metrics',
        redirect: '/metrics/jobs',
        children: [
            {
                path: 'jobs',
                component: metrics,
                children: [{ path: '', name: 'metrics-jobs', component: metricsSubjects, props: { type: 'job' } }],
            },
            {
                path: 'queues',
                component: metrics,
                children: [{ path: '', name: 'metrics-queues', component: metricsSubjects, props: { type: 'queue' } }],
            },
            {
                path: ':type(job|queue)/:name',
                name: 'metrics-preview',
                component: metricsPreview,
            },
        ],
    },

    {
        path: '/batches',
        children: [
            {
                path: '',
                name: 'batches',
                component: batches,
            },
            {
                path: ':batchId',
                name: 'batches-preview',
                component: batchesPreview,
            },
        ],
    },

    {
        path: '/jobs/pending',
        children: [
            {
                path: '',
                name: 'pending-jobs',
                component: nativeJobs,
                props: { states: ['pending', 'delayed'], title: 'Pending Jobs' },
            },
            {
                path: ':uuid',
                name: 'pending-job-preview',
                component: jobDetail,
            },
        ],
    },

    {
        path: '/jobs/running',
        children: [
            {
                path: '',
                name: 'running-jobs',
                component: nativeJobs,
                props: { states: ['running', 'stuck'], title: 'Running Jobs' },
            },
            {
                path: ':uuid',
                name: 'running-job-preview',
                component: jobDetail,
            },
        ],
    },

    {
        path: '/jobs/completed',
        children: [
            {
                path: '',
                name: 'completed-jobs',
                component: history,
            },
            {
                path: ':uuid',
                name: 'job-preview',
                component: jobDetail,
            },
        ],
    },

    {
        path: '/failed',
        children: [
            {
                path: '',
                name: 'failed-jobs',
                component: failedJobs,
            },
            {
                path: ':jobId',
                name: 'failed-jobs-preview',
                component: failedJobDetail,
            },
        ],
    },

    {
        path: '/workers',
        name: 'workers',
        component: workers,
    },
];
