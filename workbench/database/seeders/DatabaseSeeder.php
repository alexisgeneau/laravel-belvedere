<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Belvedere\Enums\JobStatus;
use Belvedere\Enums\WorkerStatus;
use Belvedere\Models\Job;
use Belvedere\Models\JobTag;
use Belvedere\Models\MonitoredTag;
use Belvedere\Models\Worker;
use Belvedere\Support\Storage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Workbench\App\Jobs\GenerateReport;
use Workbench\App\Jobs\ProcessPayment;
use Workbench\App\Jobs\RenderThumbnail;
use Workbench\App\Jobs\SendInvoice;
use Workbench\App\Jobs\SyncCatalog;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        MonitoredTag::query()->firstOrCreate(['tag' => 'billing'], ['created_at' => Carbon::now()]);
        MonitoredTag::query()->firstOrCreate(['tag' => 'tenant:42'], ['created_at' => Carbon::now()->subDay()]);

        for ($i = 1; $i <= 30; $i++) {
            SendInvoice::dispatch(1000 + $i, "customer{$i}@example.com");
        }

        for ($i = 1; $i <= 6; $i++) {
            RenderThumbnail::dispatch(500 + $i)->onQueue('media');
        }

        GenerateReport::dispatch(42);
        GenerateReport::dispatch(42, 'xlsx');
        GenerateReport::dispatch(7);
        SyncCatalog::dispatch('ftp://supplier.example.com/catalog.csv');
        ProcessPayment::dispatch(2001, 4990);
        ProcessPayment::dispatch(2002, 12900);

        Bus::batch([new SendInvoice(3001, 'acme@example.com'), new SendInvoice(3002, 'globex@example.com'), new ProcessPayment(3003, 7500)])
            ->name('Nightly invoices')
            ->allowFailures()
            ->dispatch();

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default,media', '--stop-when-empty' => true, '--sleep' => 0]);

        for ($i = 1; $i <= 8; $i++) {
            GenerateReport::dispatch(100 + $i)->onQueue('reports');
        }

        SyncCatalog::dispatch('ftp://supplier.example.com/catalog.csv')->delay(Carbon::now()->addMinutes(30));
        SendInvoice::dispatch(1099, 'late@example.com')->delay(Carbon::now()->addHours(2));

        $this->deadWorker();
        $this->supervisedWorkers();

        Artisan::call('belvedere:check');
    }

    /** A master supervisor started three hours ago, one supervisor balancing two queues, and its three workers. */
    private function supervisedWorkers(): void
    {
        $now = Carbon::now();
        $storage = app(Storage::class);

        $storage->query('masters')->updateOrInsert(['name' => 'web-03-k3f9'], [
            'hostname' => 'web-03', 'pid' => 9100, 'environment' => 'production', 'status' => 'running',
            'supervisors' => json_encode(['web-03-k3f9:supervisor-1']),
            'started_at' => $now->copy()->subHours(3), 'last_seen_at' => $now,
        ]);

        $storage->query('supervisors')->updateOrInsert(['name' => 'web-03-k3f9:supervisor-1'], [
            'master' => 'web-03-k3f9', 'hostname' => 'web-03', 'pid' => 9101, 'status' => 'running',
            'processes' => json_encode(['database:default' => 2, 'database:media' => 1]),
            'options' => json_encode(['queue' => 'default,media', 'balance' => 'auto', 'autoScalingStrategy' => 'time', 'minProcesses' => 1, 'maxProcesses' => 4, 'timeout' => 60]),
            'started_at' => $now->copy()->subHours(3), 'last_seen_at' => $now,
        ]);

        foreach ([9113 => 'default', 9114 => 'default', 9115 => 'media'] as $pid => $queue) {
            Worker::query()->updateOrCreate(['id' => "web-03:{$pid}"], [
                'hostname' => 'web-03', 'pid' => $pid, 'connection' => 'database', 'queues' => $queue, 'supervisor' => 'web-03-k3f9:supervisor-1',
                'status' => WorkerStatus::Running, 'jobs_processed' => 140 + $pid % 7, 'memory_kb' => 48_000 + $pid,
                'started_at' => $now->copy()->subHours(2), 'last_seen_at' => $now,
            ]);
        }
    }

    /** A worker killed two hours ago, with two jobs in flight and one job that left the queue table. */
    private function deadWorker(): void
    {
        $now = Carbon::now();

        Worker::query()->updateOrCreate(['id' => 'web-03:9112'], [
            'hostname' => 'web-03', 'pid' => 9112, 'connection' => 'database', 'queues' => 'default,media',
            'status' => WorkerStatus::Running, 'jobs_processed' => 418, 'memory_kb' => 52_100,
            'started_at' => $now->copy()->subHours(3), 'last_seen_at' => $now->copy()->subHours(2),
        ]);

        $executions = [
            [SendInvoice::class, 'default', $now->copy()->subMinutes(7), $now->copy()->subMinutes(6)->subSeconds(40), ['invoiceId' => 1077, 'email' => 'customer77@example.com'], ['billing', 'invoice:1077']],
            [RenderThumbnail::class, 'media', $now->copy()->subMinutes(6)->subSeconds(10), $now->copy()->subMinutes(6)->subSeconds(5), ['mediaId' => 512], ['media:512']],
            [SyncCatalog::class, 'default', $now->copy()->subMinutes(10), null, ['source' => 'ftp://supplier.example.com/catalog.csv'], ['catalog']],
        ];

        foreach ($executions as [$name, $queue, $queuedAt, $startedAt, $snapshot, $tags]) {
            $job = Job::query()->create([
                'uuid' => (string) Str::uuid(), 'attempt' => 1, 'connection' => 'database', 'queue' => $queue, 'name' => $name,
                'status' => $startedAt !== null ? JobStatus::Running : JobStatus::Queued,
                'tags' => $tags, 'snapshot' => $snapshot,
                'worker_id' => $startedAt !== null ? 'web-03:9112' : null,
                'queued_at' => $queuedAt, 'started_at' => $startedAt,
                'wait_ms' => $startedAt !== null ? (int) $queuedAt->diffInMilliseconds($startedAt) : null,
            ]);

            JobTag::query()->insert(array_map(static fn (string $tag): array => ['job_id' => $job->id, 'tag' => $tag], $tags));
        }
    }
}
