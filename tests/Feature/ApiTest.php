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
        ->assertJsonPath('workers.alive', 0)
        ->assertJsonPath('lost_24h', 0);

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

it('searches the history by job name or by uuid without choking on other terms', function (): void {
    $this->authorize();

    TaggedJob::dispatch();
    SucceedingJob::dispatch();
    $this->workQueue();

    $uuid = (string) Job::query()->where('name', TaggedJob::class)->value('uuid');

    $this->getJson('/belvedere/api/jobs/history?search=TaggedJob')->assertOk()->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.name', TaggedJob::class);
    $this->getJson("/belvedere/api/jobs/history?search={$uuid}")->assertOk()->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.uuid', $uuid);
    $this->getJson('/belvedere/api/jobs/history?search=not-a-uuid-nor-a-name')->assertOk()->assertJsonCount(0, 'jobs');
    $this->getJson('/belvedere/api/jobs/history?search=%27%22%25_')->assertOk()->assertJsonCount(0, 'jobs');

    $this->getJson("/belvedere/api/jobs/history/{$uuid}")->assertOk()->assertJsonPath('job.name', TaggedJob::class);
    $this->getJson('/belvedere/api/jobs/history/not-a-uuid')->assertNotFound();
    $this->getJson('/belvedere/api/jobs/history/12345678-1234-1234-1234-123456789abc')->assertNotFound();
});

it('keeps silenced job classes out of the completed list and serves them apart', function (): void {
    $this->authorize();
    config()->set('belvedere.silenced', [TaggedJob::class]);

    TaggedJob::dispatch();
    SucceedingJob::dispatch();
    $this->workQueue();

    $this->getJson('/belvedere/api/jobs/history?status=completed')
        ->assertOk()
        ->assertJsonCount(1, 'jobs')
        ->assertJsonPath('jobs.0.name', SucceedingJob::class);

    $this->getJson('/belvedere/api/jobs/history?status=completed&silenced=1')
        ->assertOk()
        ->assertJsonCount(1, 'jobs')
        ->assertJsonPath('jobs.0.name', TaggedJob::class);

    $this->getJson('/belvedere/api/jobs/history')->assertOk()->assertJsonCount(2, 'jobs');

    $this->get('/belvedere')->assertOk()->assertSee('\u0022silenced\u0022:true', false);

    config()->set('belvedere.silenced', []);

    $this->getJson('/belvedere/api/jobs/history?status=completed')->assertOk()->assertJsonCount(2, 'jobs');
    $this->getJson('/belvedere/api/jobs/history?status=completed&silenced=1')->assertOk()->assertJsonCount(0, 'jobs');
    $this->get('/belvedere')->assertOk()->assertSee('\u0022silenced\u0022:false', false);
});

it('pages through the executions of a tag along the tag index', function (): void {
    $this->authorize();

    TaggedJob::dispatch();
    TaggedJob::dispatch();
    TaggedJob::dispatch();
    SucceedingJob::dispatch();

    $this->workQueue();

    $expected = Job::query()->where('name', TaggedJob::class)->orderByDesc('id')->pluck('id')->all();

    foreach (['/belvedere/api/jobs/history?tag=reports', '/belvedere/api/monitoring/reports/jobs?'] as $url) {
        $first = $this->getJson($url.'&limit=2')->assertOk()->assertJsonCount(2, 'jobs')->json();
        $second = $this->getJson($url.'&limit=2&cursor='.$first['next_cursor'])->assertOk()->assertJsonCount(1, 'jobs')->json();

        expect(array_merge(array_column($first['jobs'], 'id'), array_column($second['jobs'], 'id')))->toBe($expected)
            ->and($second['next_cursor'])->toBeNull()
            ->and($first['jobs'][0])->not->toHaveKey('job_id');
    }
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
