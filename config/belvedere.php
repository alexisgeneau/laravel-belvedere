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

    'alerts' => [
        'routes' => [
            'mail' => env('BELVEDERE_ALERT_MAIL'),
            'slack' => env('BELVEDERE_ALERT_SLACK'),
        ],
        'queue_size' => 1000,
        'failure_rate_percent' => 10,
        'failure_rate_minimum_jobs' => 20,
        'window_minutes' => 15,
        'dead_workers' => true,
        'cooldown_minutes' => 30,
    ],

    'refresh_seconds' => 5,

];
