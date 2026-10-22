<?php

declare(strict_types=1);

/*
 * Measures what Belvedere costs: the overhead per job, the API latency once the
 * history holds a large number of rows, and the duration of belvedere:prune.
 *
 *   php workbench/bench.php --baseline   # same measures with the collector disabled
 *   php workbench/bench.php              # with Belvedere, compared to the baseline
 *
 * Options: --jobs=2000 --rows=1000000 --skip-api --skip-prune
 * Environment: BENCH_DB_CONNECTION=mysql|pgsql with BENCH_DB_HOST, BENCH_DB_PORT,
 * BENCH_DB_DATABASE, BENCH_DB_USERNAME and BENCH_DB_PASSWORD (SQLite otherwise).
 */

use Belvedere\Belvedere;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Workbench\App\Jobs\BenchmarkJob;

$options = getopt('', ['baseline', 'jobs::', 'rows::', 'skip-api', 'skip-prune']);
$baseline = isset($options['baseline']);
$jobs = max(100, (int) ($options['jobs'] ?? 2000));
$rows = max(1000, (int) ($options['rows'] ?? 1_000_000));

if ($baseline) {
    $_ENV['BELVEDERE_ENABLED'] = $_SERVER['BELVEDERE_ENABLED'] = 'false';
}

define('TESTBENCH_WORKING_PATH', dirname(__DIR__));

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

// The API requests go through the web middleware, which needs an encryption key; a fresh
// checkout has no .env in the skeleton, so provide one for the duration of the run.
if (blank(config('app.key'))) {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
}

if (($driver = getenv('BENCH_DB_CONNECTION')) !== false && $driver !== '') {
    config([
        'database.connections.bench' => [
            'driver' => $driver,
            'host' => getenv('BENCH_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('BENCH_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('BENCH_DB_DATABASE') ?: 'belvedere_bench',
            'username' => getenv('BENCH_DB_USERNAME') ?: 'root',
            'password' => getenv('BENCH_DB_PASSWORD') ?: '',
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'search_path' => 'public',
        ],
        'database.default' => 'bench',
        'queue.failed.database' => 'bench',
        'queue.batching.database' => 'bench',
    ]);

    DB::purge('bench');
} else {
    // A fresh file for every run: the figures must not depend on what a previous run left behind.
    $path = (string) config('database.connections.sqlite.database');

    if ($path !== ':memory:') {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            @unlink($path.$suffix);
        }

        touch($path);
        DB::purge();
    }
}

$out = static fn (string $line = ''): int => fwrite(STDOUT, $line.PHP_EOL);
$ms = static fn (float $nanoseconds): float => round($nanoseconds / 1e6, 2);
$percentile = static function (array $values, int $p): float {
    sort($values);

    return $values[(int) min(count($values) - 1, floor(count($values) * $p / 100))];
};

$queries = ['count' => 0, 'slowest' => 0.0];
DB::listen(static function (QueryExecuted $query) use (&$queries): void {
    $queries['count']++;
    $queries['slowest'] = max($queries['slowest'], $query->time);
});
$resetQueries = static function () use (&$queries): void {
    $queries = ['count' => 0, 'slowest' => 0.0];
};

$queueTables = require dirname(__DIR__).'/tests/database/migrations/create_queue_tables.php';
$belvedereTables = require dirname(__DIR__).'/database/migrations/create_belvedere_tables.php';
$belvedereTables->down();
$queueTables->down();
$queueTables->up();
$belvedereTables->up();

$mode = $baseline ? 'baseline (collector disabled)' : 'belvedere';
$driver = DB::connection()->getDriverName();
$server = $driver.' '.DB::connection()->getServerVersion();

if ($driver === 'sqlite') {
    $pragma = static fn (string $name): string => (string) DB::connection()->selectOne("pragma {$name}")->{$name};
    $server .= sprintf(' (journal_mode=%s, synchronous=%s, secure_delete=%s)', $pragma('journal_mode'), $pragma('synchronous'), $pragma('secure_delete'));
}

$out("Belvedere bench — {$mode} — {$server} — PHP ".PHP_VERSION);
$out();

// Overhead per job: dispatch latency and worker throughput.
$dispatch = [];
$resetQueries();

for ($i = 1; $i <= $jobs; $i++) {
    $start = hrtime(true);
    BenchmarkJob::dispatch($i, 'ORD-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT));
    $dispatch[] = hrtime(true) - $start;
}

$dispatchQueries = $queries['count'];
$resetQueries();

$start = hrtime(true);
Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);
$processing = hrtime(true) - $start;
$processingQueries = $queries['count'];

$results = [
    'jobs' => $jobs,
    'dispatch_p50_ms' => $ms($percentile($dispatch, 50)),
    'dispatch_p95_ms' => $ms($percentile($dispatch, 95)),
    'dispatch_queries_per_job' => round($dispatchQueries / $jobs, 2),
    'processing_ms_per_job' => $ms($processing / $jobs),
    'processing_queries_per_job' => round($processingQueries / $jobs, 2),
];

$out(sprintf('Overhead per job (%d jobs)', $jobs));
$out(sprintf('  dispatch        p50 %6.2f ms   p95 %6.2f ms   %.2f queries/job', $results['dispatch_p50_ms'], $results['dispatch_p95_ms'], $results['dispatch_queries_per_job']));
$out(sprintf('  processing      mean %5.2f ms/job               %.2f queries/job', $results['processing_ms_per_job'], $results['processing_queries_per_job']));

$resultsPath = $app->storagePath($baseline ? 'bench-baseline.json' : 'bench-belvedere.json');
file_put_contents($resultsPath, json_encode($results, JSON_PRETTY_PRINT));

if (! $baseline && is_file($app->storagePath('bench-baseline.json'))) {
    $reference = json_decode((string) file_get_contents($app->storagePath('bench-baseline.json')), true);

    $out(sprintf(
        '  versus baseline dispatch %+.2f ms (p50)   processing %+.2f ms/job   %+.2f queries/job',
        $results['dispatch_p50_ms'] - $reference['dispatch_p50_ms'],
        $results['processing_ms_per_job'] - $reference['processing_ms_per_job'],
        ($results['dispatch_queries_per_job'] + $results['processing_queries_per_job']) - ($reference['dispatch_queries_per_job'] + $reference['processing_queries_per_job']),
    ));
}

$out();

if ($baseline || isset($options['skip-api'])) {
    exit(0);
}

// API latency with a large history.
$out(sprintf('Seeding %s history rows...', number_format($rows)));

$queues = ['default', 'emails', 'reports', 'webhooks', 'exports'];
$names = ['App\\Jobs\\SendInvoice', 'App\\Jobs\\GenerateReport', 'App\\Jobs\\SyncCatalog', 'App\\Jobs\\ProcessPayment', 'App\\Jobs\\RenderThumbnail', 'App\\Jobs\\NotifyCustomer', 'App\\Jobs\\RefreshSearchIndex', 'App\\Jobs\\ArchiveOrder'];
$statuses = array_merge(array_fill(0, 90, 'completed'), array_fill(0, 5, 'failed'), array_fill(0, 3, 'released'), array_fill(0, 2, 'timed_out'));
$uuid = static function (): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
};

$start = hrtime(true);
$now = Carbon::now();
$sampleUuid = null;
$inserted = 0;
$hostname = gethostname();
$base = (int) DB::table('belvedere_jobs')->max('id');

while ($inserted < $rows) {
    $chunk = [];
    $tagChunk = [];
    $firstId = $base + $inserted + 1;

    for ($i = 0; $i < min(1000, $rows - $inserted); $i++) {
        $id = $firstId + $i;
        $finished = $now->copy()->subSeconds(random_int(0, 7 * 86400));
        $wait = random_int(50, 5000);
        $run = random_int(5, 3000);
        $status = $statuses[array_rand($statuses)];

        $chunk[] = [
            'id' => $id,
            'uuid' => $rowUuid = $uuid(),
            'attempt' => 1,
            'connection' => 'database',
            'queue' => $queues[$id % 5],
            'name' => $names[$id % 8],
            'status' => $status,
            'tags' => $id % 10 === 0 ? json_encode(['tenant:'.(intdiv($id, 10) % 100), 'order:'.$id]) : null,
            'snapshot' => json_encode(['orderId' => $id, 'reference' => 'ORD-'.$id]),
            'exception' => $status === 'failed' ? "RuntimeException: boom\n#0 /app/app/Jobs/Job.php(12): handle()" : null,
            'batch_id' => null,
            'worker_id' => $hostname.':'.(4000 + $id % 4),
            'queued_at' => $finished->copy()->subMilliseconds($wait + $run)->format('Y-m-d H:i:s.v'),
            'started_at' => $finished->copy()->subMilliseconds($run)->format('Y-m-d H:i:s.v'),
            'finished_at' => $finished->format('Y-m-d H:i:s.v'),
            'wait_ms' => $wait,
            'run_ms' => $run,
            'memory_kb' => random_int(20000, 90000),
        ];

        if ($id % 10 === 0) {
            // One job in ten is tagged, spread over a hundred tenants: a tenant tag matches 0.1 % of the history.
            $tagChunk[] = ['job_id' => $id, 'tag' => 'tenant:'.(intdiv($id, 10) % 100)];
            $tagChunk[] = ['job_id' => $id, 'tag' => 'order:'.$id];
            $sampleUuid ??= $rowUuid;
        }
    }

    DB::table('belvedere_jobs')->insert($chunk);
    DB::table('belvedere_job_tags')->insert($tagChunk);
    $inserted += count($chunk);
}

DB::table('belvedere_metrics')->delete();

$metrics = [];
$minutes = 7 * 1440;

for ($minute = 0; $minute < $minutes; $minute++) {
    $bucket = $now->copy()->subMinutes($minute)->startOfMinute()->format('Y-m-d H:i:s');

    foreach ([['queue', $queues], ['job', $names]] as [$type, $subjects]) {
        foreach ($subjects as $subject) {
            $processed = random_int(5, 60);
            $metrics[] = [
                'connection' => 'database', 'subject_type' => $type, 'subject' => $subject, 'bucket' => $bucket,
                'processed' => $processed, 'failed' => random_int(0, 3), 'released' => random_int(0, 2),
                'wait_ms_sum' => $processed * random_int(100, 2000), 'wait_ms_max' => random_int(2000, 8000),
                'run_ms_sum' => $processed * random_int(50, 900), 'run_ms_max' => random_int(900, 5000),
            ];
        }
    }

    if (count($metrics) >= 1000) {
        DB::table('belvedere_metrics')->insert($metrics);
        $metrics = [];
    }
}

DB::table('belvedere_metrics')->insert($metrics);

$failed = [];

for ($i = 1; $i <= 1000; $i++) {
    $failedUuid = $uuid();
    $failed[] = [
        'uuid' => $failedUuid,
        'connection' => 'database',
        'queue' => $queues[$i % 5],
        'payload' => json_encode(['uuid' => $failedUuid, 'displayName' => $names[$i % 8], 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'maxTries' => 3, 'data' => ['commandName' => $names[$i % 8], 'command' => 'O:1:"x":0:{}'], 'belvedere' => ['tags' => ['tenant:'.($i % 100)], 'batch' => null, 'snapshot' => ['orderId' => $i]]]),
        'exception' => "RuntimeException: boom\n#0 /app/app/Jobs/Job.php(12): handle()",
        'failed_at' => $now->copy()->subMinutes($i)->format('Y-m-d H:i:s'),
    ];
}

DB::table('failed_jobs')->insert($failed);
DB::table('belvedere_monitored_tags')->insert(['tag' => 'tenant:1', 'created_at' => $now]);

for ($i = 0; $i < 4; $i++) {
    DB::table('belvedere_workers')->insert([
        'id' => $hostname.':'.(4000 + $i), 'hostname' => $hostname, 'pid' => 4000 + $i, 'connection' => 'database', 'queues' => 'default,emails',
        'status' => 'running', 'jobs_processed' => 1000, 'memory_kb' => 50000, 'started_at' => $now->copy()->subHour(), 'last_seen_at' => $now,
    ]);
}

$out(sprintf('  seeded in %.1f s', (hrtime(true) - $start) / 1e9));

// The statistics a production server gathers by itself over time (autovacuum, InnoDB persistent
// statistics); right after a bulk load the planner would otherwise work from stale estimates.
// SQLite keeps none unless an application runs ANALYZE, so it is measured as it runs.
$tables = ['belvedere_jobs', 'belvedere_job_tags', 'belvedere_metrics', 'belvedere_workers', 'belvedere_monitored_tags', 'jobs', 'failed_jobs', 'job_batches'];

match (DB::connection()->getDriverName()) {
    'pgsql' => DB::statement('ANALYZE'),
    'mysql', 'mariadb' => DB::select('ANALYZE TABLE '.implode(', ', $tables)),
    default => null,
};

$out();

Belvedere::auth(static fn (): bool => true);
$kernel = $app->make(HttpKernel::class);
$endpoints = [
    'overview', 'queues', 'jobs/pending', 'jobs/running', 'jobs/history', 'jobs/history?status=failed', 'jobs/history?tag=tenant:1',
    'jobs/history?search=SendInvoice', 'jobs/history/'.$sampleUuid, 'jobs/failed', 'jobs/failed?search=tenant:1', 'batches', 'workers',
    'jobs/history?queue=emails', 'metrics/queue?range=7d', 'metrics/queue/default?range=7d', 'metrics/job/'.rawurlencode('App\\Jobs\\SendInvoice').'?range=24h', 'monitoring', 'monitoring/'.rawurlencode('tenant:1').'/jobs',
];

$out(sprintf('API latency with %s history rows (median of 5, limit 200 ms)', number_format($rows)));
$slow = [];

foreach ($endpoints as $endpoint) {
    $timings = [];

    for ($run = 0; $run < 5; $run++) {
        $request = Request::create('/belvedere/api/'.$endpoint, 'GET');
        $start = hrtime(true);
        $response = $kernel->handle($request);
        $timings[] = (hrtime(true) - $start) / 1e6;
        $kernel->terminate($request, $response);

        if ($response->getStatusCode() !== 200) {
            $out(sprintf('  %-52s HTTP %d', $endpoint, $response->getStatusCode()));

            continue 2;
        }
    }

    sort($timings);
    $median = $timings[2];
    $out(sprintf('  %-52s %7.1f ms  (max %7.1f ms)%s', $endpoint, $median, max($timings), $median > 200 ? '  SLOW' : ''));

    if ($median > 200) {
        $slow[] = $endpoint;
    }
}

$out();

if (! isset($options['skip-prune'])) {
    $resetQueries();
    $start = hrtime(true);
    Artisan::call('belvedere:prune', ['--hours' => 1]);
    $duration = (hrtime(true) - $start) / 1e9;

    $out(sprintf('belvedere:prune on %s rows: %.1f s, %d queries, longest query %.0f ms', number_format($rows), $duration, $queries['count'], $queries['slowest']));
    $out(trim(Artisan::output()));
    $out();
}

$out($slow === [] ? 'Every endpoint stayed under 200 ms.' : 'Endpoints over 200 ms: '.implode(', ', $slow));

exit($slow === [] ? 0 : 1);
