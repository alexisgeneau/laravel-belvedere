<?php

declare(strict_types=1);

use Belvedere\Belvedere;
use Belvedere\Models\Job;
use Belvedere\Models\MonitoredTag;
use Belvedere\Tests\Fixtures\Jobs\FailingJob;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Belvedere\Tests\Fixtures\Jobs\TaggedJob;
use Illuminate\Support\Facades\DB;

it('denies access outside the local environment until a gate or callback allows it', function (): void {
    $this->getJson('/belvedere/api/overview')->assertForbidden();

    Belvedere::auth(static fn (): bool => true);

    $this->getJson('/belvedere/api/overview')->assertOk();
    $this->get('/belvedere')
        ->assertOk()
        ->assertSee('id="belvedere"', false)
        ->assertSee('<style data-scheme="dark">', false)
        ->assertSee('window.Belvedere = ', false)
        ->assertSee('refreshSeconds', false);
});

it('serves the overview with per-queue counts and worker totals', function (): void {
    $this->authorize();

    SucceedingJob::dispatch();
    SucceedingJob::dispatch()->delay(300);

    $this->getJson('/belvedere/api/overview')
        ->assertOk()
        ->assertJsonPath('connection', 'database')
        ->assertJsonPath('supports_database_inspection', true)
        ->assertJsonPath('queues.0.queue', 'default')
        ->assertJsonPath('queues.0.pending', 1)
        ->assertJsonPath('queues.0.delayed', 1)
        ->assertJsonPath('queues.0.paused', false)
        ->assertJsonPath('workers.alive', 0);

    $this->getJson('/belvedere/api/overview?connection=unknown')->assertNotFound();
});

it('lists native jobs, history and failed jobs with their details', function (): void {
    $this->authorize();

    TaggedJob::dispatch();
    FailingJob::dispatch();

    $this->getJson('/belvedere/api/jobs/pending')
        ->assertOk()
        ->assertJsonCount(2, 'jobs')
        ->assertJsonPath('jobs.0.tags', [])
        ->assertJsonPath('jobs.1.tags', ['reports', 'tenant:42']);

    $this->workQueue();

    $this->getJson('/belvedere/api/jobs/history')
        ->assertOk()
        ->assertJsonCount(3, 'jobs');

    $this->getJson('/belvedere/api/jobs/history?status=failed')
        ->assertOk()
        ->assertJsonCount(1, 'jobs')
        ->assertJsonPath('jobs.0.name', FailingJob::class);

    $this->getJson('/belvedere/api/jobs/history?tag=reports')
        ->assertOk()
        ->assertJsonCount(1, 'jobs')
        ->assertJsonPath('jobs.0.name', TaggedJob::class);

    $uuid = Job::query()->where('name', FailingJob::class)->value('uuid');

    $this->getJson("/belvedere/api/jobs/history/{$uuid}")
        ->assertOk()
        ->assertJsonCount(2, 'attempts')
        ->assertJsonPath('job.status', 'failed')
        ->assertJsonPath('attempts.0.status', 'released');

    $failedId = DB::table('failed_jobs')->value('uuid');

    $this->getJson('/belvedere/api/jobs/failed')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('jobs.0.id', $failedId)
        ->assertJsonPath('jobs.0.attempts', 2);

    $this->getJson("/belvedere/api/jobs/failed/{$failedId}")
        ->assertOk()
        ->assertJsonPath('job.name', FailingJob::class)
        ->assertJsonPath('job.exception_summary', fn (string $summary): bool => str_starts_with($summary, 'RuntimeException: boom'));
});

it('performs actions through the api and refuses them in read-only mode', function (): void {
    $this->authorize();

    FailingJob::dispatch();
    $this->workQueue();

    $failedId = DB::table('failed_jobs')->value('uuid');

    $this->postJson("/belvedere/api/jobs/failed/{$failedId}/retry")->assertOk()->assertJsonPath('retried', 1);
    $this->postJson("/belvedere/api/jobs/failed/{$failedId}/retry")->assertNotFound();

    $pendingId = DB::table('jobs')->value('id');

    $this->postJson('/belvedere/api/queues/database/default/pause')->assertOk()->assertJsonPath('paused', true);
    $this->getJson('/belvedere/api/queues')->assertJsonPath('queues.0.paused', true);
    $this->postJson('/belvedere/api/queues/database/default/resume')->assertOk()->assertJsonPath('paused', false);
    $this->deleteJson("/belvedere/api/jobs/database/{$pendingId}")->assertOk();
    $this->deleteJson("/belvedere/api/jobs/database/{$pendingId}")->assertNotFound();

    config()->set('belvedere.read_only', true);

    $this->postJson('/belvedere/api/queues/database/default/pause')->assertForbidden();
    $this->getJson('/belvedere/api/queues')->assertOk();
});

it('manages monitored tags and lists their jobs', function (): void {
    $this->authorize();

    TaggedJob::dispatch();

    $this->postJson('/belvedere/api/monitoring', ['tag' => 'reports'])->assertCreated();
    $this->postJson('/belvedere/api/monitoring', ['tag' => ''])->assertStatus(422);

    $this->getJson('/belvedere/api/monitoring')
        ->assertOk()
        ->assertJsonPath('tags.0.tag', 'reports')
        ->assertJsonPath('tags.0.jobs', 1);

    $this->getJson('/belvedere/api/monitoring/reports/jobs')
        ->assertOk()
        ->assertJsonCount(1, 'jobs')
        ->assertJsonPath('jobs.0.name', TaggedJob::class);

    $this->deleteJson('/belvedere/api/monitoring/reports')->assertOk();

    expect(MonitoredTag::query()->count())->toBe(0);
});

it('exposes metrics aggregates and series', function (): void {
    $this->authorize();
    $this->freezeTime();

    SucceedingJob::dispatch();
    $this->workQueue();

    $this->getJson('/belvedere/api/metrics/queue')
        ->assertOk()
        ->assertJsonPath('subjects.0.name', 'default')
        ->assertJsonPath('subjects.0.processed', 1);

    $this->getJson('/belvedere/api/metrics/job/'.urlencode(SucceedingJob::class).'?range=24h')
        ->assertOk()
        ->assertJsonPath('range', '24h')
        ->assertJsonCount(97, 'series');

    $this->getJson('/belvedere/api/metrics/queue/default?range=1y')->assertNotFound();
});
