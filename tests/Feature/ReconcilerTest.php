<?php

declare(strict_types=1);

use Belvedere\Collector\Reconciler;
use Belvedere\Collector\WorkerHeartbeat;
use Belvedere\Enums\JobStatus;
use Belvedere\Enums\WorkerStatus;
use Belvedere\Models\Job;
use Belvedere\Models\Worker;
use Belvedere\Queues\JobActions;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Belvedere\Tests\Fixtures\Jobs\TaggedJob;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('marks the running executions of a worker that stopped responding as lost', function (): void {
    Event::until(new Looping('database', 'default'));

    $worker = Worker::query()->sole();
    $job = startedExecution(['worker_id' => $worker->id]);

    Artisan::call('belvedere:check');

    expect($job->fresh()?->status)->toBe(JobStatus::Running);

    $this->travel(2)->minutes();

    Artisan::call('belvedere:check');

    $job->refresh();

    expect($job->status)->toBe(JobStatus::Lost)
        ->and($job->finished_at)->not->toBeNull()
        ->and($job->run_ms)->toBeNull()
        ->and($job->exception)->toBe("Worker [{$worker->id}] stopped responding while running this job.")
        ->and(Artisan::output())->toContain('Lost executions (dead workers)');
});

it('marks the running executions of a stopped worker as lost', function (): void {
    SucceedingJob::dispatch();
    $this->workQueue();

    $worker = Worker::query()->sole();
    $job = startedExecution(['worker_id' => $worker->id]);

    app(Reconciler::class)->run();

    expect($job->fresh()?->status)->toBe(JobStatus::Lost)
        ->and($job->fresh()?->exception)->toBe("Worker [{$worker->id}] stopped without reporting the outcome of this job.");
});

it('marks a running attempt as lost once a later attempt exists', function (): void {
    $first = startedExecution();
    $second = startedExecution(['uuid' => $first->uuid, 'attempt' => 2, 'worker_id' => 'ghost:2']);

    $lost = app(Reconciler::class)->run();

    expect($lost['superseded attempts'])->toBe(1)
        ->and($first->fresh()?->status)->toBe(JobStatus::Lost)
        ->and($first->fresh()?->exception)->toBe('Superseded by attempt 2: the worker never reported the outcome of this attempt.')
        ->and($second->fresh()?->status)->toBe(JobStatus::Running);
});

it('marks a running execution as lost once its reservation has expired', function (): void {
    $stale = startedExecution(['started_at' => now()->subSeconds(100)]);
    $recent = startedExecution(['started_at' => now()->subSeconds(10)]);

    $lost = app(Reconciler::class)->run();

    expect($lost['expired reservations'])->toBe(1)
        ->and($stale->fresh()?->status)->toBe(JobStatus::Lost)
        ->and($stale->fresh()?->exception)->toBe("No outcome was reported within the connection's retry_after (90 s): the worker was probably killed.")
        ->and($recent->fresh()?->status)->toBe(JobStatus::Running);
});

it('lets a worker that finishes late override the lost verdict', function (): void {
    SucceedingJob::dispatch();

    Event::listen(JobProcessing::class, function (): void {
        Job::query()->update(['status' => JobStatus::Lost->value, 'exception' => 'gone', 'finished_at' => now()]);
    });

    $this->workQueue();

    $job = Job::query()->sole();

    expect($job->status)->toBe(JobStatus::Completed)
        ->and($job->exception)->toBeNull()
        ->and($job->run_ms)->not->toBeNull();
});

it('marks queued executions as lost when their jobs left the queue table', function (): void {
    SucceedingJob::dispatch();
    SucceedingJob::dispatch();
    SucceedingJob::dispatch()->delay(3600);

    [$first, $second, $delayed] = DB::table('jobs')->orderBy('id')->pluck('id')->all();

    DB::table('jobs')->whereIn('id', [$first, $second])->delete();

    app(Reconciler::class)->run();

    expect(Job::query()->where('status', JobStatus::Queued->value)->count())->toBe(3);

    $this->travel(3)->minutes();

    $lost = app(Reconciler::class)->run();

    expect($lost['jobs missing from the queue table'])->toBe(2)
        ->and(Job::query()->where('status', JobStatus::Lost->value)->count())->toBe(2)
        ->and(Job::query()->where('status', JobStatus::Lost->value)->value('exception'))
        ->toBe('The job left the [jobs] table without being processed: the queue was cleared, or the job was deleted outside the dashboard.')
        ->and(DB::table('jobs')->where('id', $delayed)->exists())->toBeTrue()
        ->and(Job::query()->where('status', JobStatus::Queued->value)->count())->toBe(1);
});

it('leaves queued executions alone while the queue table still holds their jobs', function (): void {
    SucceedingJob::dispatch();
    SucceedingJob::dispatch()->delay(3600);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{"job":"raw"}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

    $this->travel(3)->minutes();

    $lost = app(Reconciler::class)->run();

    expect(array_sum($lost))->toBe(0)
        ->and(Job::query()->where('status', JobStatus::Queued->value)->count())->toBe(2);
});

it('reads the whole payload when the uuid is not at its start', function (): void {
    $uuid = (string) Str::uuid();
    $payload = json_encode(['padding' => str_repeat('x', 600), 'uuid' => $uuid, 'displayName' => SucceedingJob::class]);

    DB::table('jobs')->insert(['queue' => 'default', 'payload' => $payload, 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
    $present = startedExecution(['uuid' => $uuid, 'status' => JobStatus::Queued, 'worker_id' => null, 'started_at' => null]);
    $orphan = startedExecution(['status' => JobStatus::Queued, 'worker_id' => null, 'started_at' => null]);

    $this->travel(3)->minutes();

    $lost = app(Reconciler::class)->run();

    expect($lost['jobs missing from the queue table'])->toBe(1)
        ->and($present->fresh()?->status)->toBe(JobStatus::Queued)
        ->and($orphan->fresh()?->status)->toBe(JobStatus::Lost);
});

it('marks executions as lost when their jobs are deleted or purged from the dashboard', function (): void {
    SucceedingJob::dispatch();
    SucceedingJob::dispatch();
    TaggedJob::dispatch();

    $actions = app(JobActions::class);
    $first = (int) DB::table('jobs')->orderBy('id')->value('id');

    expect($actions->delete('database', $first, 'jane@example.com'))->toBeTrue()
        ->and(Job::query()->where('status', JobStatus::Lost->value)->value('exception'))->toBe('Deleted from the dashboard by jane@example.com.')
        ->and($actions->purge('database', 'default'))->toBe(2)
        ->and(Job::query()->where('status', JobStatus::Queued->value)->count())->toBe(0)
        ->and(Job::query()->where('name', TaggedJob::class)->value('exception'))->toBe('Purged with queue [default] from the dashboard.')
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('marks the running executions of a forgotten worker as lost', function (): void {
    Event::until(new Looping('database', 'default'));

    $worker = Worker::query()->sole();
    $job = startedExecution(['worker_id' => $worker->id]);

    app(JobActions::class)->forgetWorker($worker->id, 'jane@example.com');

    expect($job->fresh()?->status)->toBe(JobStatus::Lost)
        ->and($job->fresh()?->exception)->toBe("Worker [{$worker->id}] was forgotten from the dashboard by jane@example.com before reporting the outcome of this job.");
});

it('revives a lost queued execution when the job is processed after all', function (): void {
    SucceedingJob::dispatch();

    Job::query()->update(['status' => JobStatus::Lost->value, 'exception' => 'gone', 'finished_at' => now()]);

    $this->workQueue();

    $job = Job::query()->sole();

    expect($job->status)->toBe(JobStatus::Completed)
        ->and($job->attempt)->toBe(1)
        ->and($job->exception)->toBeNull()
        ->and($job->started_at)->not->toBeNull();
});

it('lists lost executions in the history', function (): void {
    $this->authorize();

    $job = startedExecution(['started_at' => now()->subSeconds(100)]);

    app(Reconciler::class)->run();

    $this->getJson('/belvedere/api/jobs/history?status=lost')
        ->assertOk()
        ->assertJsonCount(1, 'jobs')
        ->assertJsonPath('jobs.0.uuid', $job->uuid)
        ->assertJsonPath('jobs.0.status', 'lost');

    $this->getJson('/belvedere/api/jobs/history')
        ->assertOk()
        ->assertJsonCount(1, 'jobs');

    $this->getJson("/belvedere/api/jobs/history/{$job->uuid}")
        ->assertOk()
        ->assertJsonPath('job.status', 'lost')
        ->assertJsonPath('attempts.0.exception', fn (string $exception): bool => str_starts_with($exception, 'No outcome was reported'));

    $this->getJson('/belvedere/api/overview')
        ->assertOk()
        ->assertJsonPath('lost_24h', 1);
});

it('counts only running and paused workers as alive', function (): void {
    $this->authorize();

    Event::until(new Looping('database', 'default'));

    $this->getJson('/belvedere/api/overview')->assertJsonPath('workers.alive', 1)->assertJsonPath('workers.dead', 0);

    app(WorkerHeartbeat::class)->stopping();

    $this->getJson('/belvedere/api/overview')->assertJsonPath('workers.alive', 0)->assertJsonPath('workers.dead', 0);

    Worker::query()->update(['status' => WorkerStatus::Running->value, 'last_seen_at' => now()->subMinutes(5)]);

    $this->getJson('/belvedere/api/overview')->assertJsonPath('workers.alive', 0)->assertJsonPath('workers.dead', 1);
});

it('prunes lost executions with the completed ones', function (): void {
    startedExecution(['started_at' => now()->subSeconds(100)]);

    app(Reconciler::class)->run();

    $this->travel(8)->days();

    Artisan::call('belvedere:prune');

    expect(Job::query()->count())->toBe(0);
});

/** @param  array<string, mixed>  $attributes */
function startedExecution(array $attributes = []): Job
{
    return Job::query()->create([
        'uuid' => (string) Str::uuid(),
        'attempt' => 1,
        'connection' => 'database',
        'queue' => 'default',
        'name' => SucceedingJob::class,
        'status' => JobStatus::Running,
        'worker_id' => 'ghost:1',
        'queued_at' => now()->subSeconds(5),
        'started_at' => now(),
        ...$attributes,
    ]);
}
