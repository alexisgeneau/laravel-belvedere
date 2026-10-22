<?php

declare(strict_types=1);

return [

    'enabled' => (bool) env('BELVEDERE_ENABLED', true),

    'path' => env('BELVEDERE_PATH', 'belvedere'),

    'domain' => env('BELVEDERE_DOMAIN'),

    'middleware' => ['web'],

    'read_only' => (bool) env('BELVEDERE_READ_ONLY', false),

    'connections' => ['database'],

    'storage' => [
        'connection' => env('BELVEDERE_DB_CONNECTION'),
        'prefix' => 'belvedere_',
    ],

    'cache_store' => env('BELVEDERE_CACHE_STORE'),

    'sampling' => (int) env('BELVEDERE_SAMPLING', 1),

    'metrics' => true,

    'snapshot' => [
        'enabled' => true,
        'max_bytes' => 16384,
        'hidden_keys' => ['password', 'token', 'secret', 'authorization', 'api_key'],
    ],

    // Job classes kept out of the Completed Jobs list (still recorded, counted and shown under the Silenced tab).
    'silenced' => [
        // App\Jobs\RefreshSearchIndex::class,
    ],

    'retention' => [
        'completed_hours' => 168,
        'failed_hours' => 720,
        'metrics_hours' => 168,
        'actions_hours' => 720,
        'workers_hours' => 24,
    ],

    'workers' => [
        'heartbeat_seconds' => 15,
        'dead_after_seconds' => 90,
    ],

    // The workers `php artisan belvedere` starts and supervises: the keys of Horizon's
    // `defaults` and `environments`, with the queue connection changed. The dashboard
    // works without this command; it only observes the workers started by hand.
    'supervisor' => [
        // Memory (MB) the master process may use before it restarts.
        'memory_limit' => 64,
        'heartbeat_seconds' => 5,
        'inactive_after_seconds' => 30,

        'defaults' => [
            'supervisor-1' => [
                'connection' => 'database',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'maxTime' => 0,
                'maxJobs' => 0,
                'memory' => 128,
                'tries' => 1,
                'timeout' => 60,
                'nice' => 0,
            ],
        ],

        'environments' => [
            'production' => [
                'supervisor-1' => [
                    'maxProcesses' => 6,
                ],
            ],

            'local' => [
                'supervisor-1' => [
                    'maxProcesses' => 2,
                ],
            ],
        ],
    ],

    'alerts' => [
        'routes' => [
            'mail' => env('BELVEDERE_ALERT_MAIL'),
            'slack' => env('BELVEDERE_ALERT_SLACK'),
        ],
        'queue_size' => 1000,
        'wait_seconds' => 60,
        'wait_seconds_per_queue' => [
            // 'database:reports' => 900,
        ],
        'failure_rate_percent' => 10,
        'failure_rate_minimum_jobs' => 20,
        'window_minutes' => 15,
        'dead_workers' => true,
        // A master supervisor gone silent, or workers left behind by a dead supervisor.
        'supervisor' => true,
        'cooldown_minutes' => 30,
    ],

    'refresh_seconds' => 5,

];
