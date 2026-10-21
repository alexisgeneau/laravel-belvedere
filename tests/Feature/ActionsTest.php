<?php

declare(strict_types=1);

use Belvedere\Enums\JobStatus;
use Belvedere\Models\Action;
use Belvedere\Models\Job;
use Belvedere\Queues\JobActions;
use Belvedere\Queues\QueuePauser;
use Belvedere\Tests\Fixtures\Jobs\FailingJob;
use Belvedere\Tests\Fixtures\Jobs\TaggedJob;
use Illuminate\Support\Facades\DB;

it('retries a failed job with the same uuid and records the new attempt', function (): void {
    FailingJob::dispatch();
    $this->workQueue();

    $uuid = Job::query()->value('uuid');
    $failedId = (string) DB::table('failed_jobs')->value('uuid');

    expect(app(JobActions::class)->retry($failedId, 'jane@example.com'))->toBeTrue()
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(Job::query()->forUuid($uuid)->orderByDesc('attempt')->first()->status)->toBe(JobStatus::Queued)
        ->and(Action::query()->sole()->actor)->toBe('jane@example.com');

    $this->workQueue();

    $statuses = Job::query()->forUuid($uuid)->orderBy('attempt')->pluck('status')
        ->map(fn (JobStatus $status): string => $status->value)
        ->all();

    expect($statuses)->toBe(['released', 'failed', 'released', 'failed'])
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('copies the tags of the previous attempt onto the retried one', function (): void {
    TaggedJob::dispatch();

    app(JobActions::class)->retry(moveToFailedJobs());

    $latest = Job::query()->orderByDesc('attempt')->first();

    expect($latest->attempt)->toBe(2)
        ->and($latest->status)->toBe(JobStatus::Queued)
        ->and($latest->tags)->toBe(['reports', 'tenant:42']);
});

it('returns false when the failed job does not exist', function (): void {
    expect(app(JobActions::class)->retry('missing'))->toBeFalse()
        ->and(Action::query()->count())->toBe(0);
});

it('pauses and resumes queues through the cache flag', function (): void {
    $actions = app(JobActions::class);
    $pauser = app(QueuePauser::class);

    $actions->pause('database', 'default');

    expect($pauser->isPaused('database', 'default'))->toBeTrue()
        ->and($pauser->shouldSleep('database', 'default'))->toBeTrue()
        ->and($pauser->shouldSleep('database', 'high,default'))->toBeFalse();

    $actions->pause('database', 'high');

    expect($pauser->shouldSleep('database', 'high,default'))->toBeTrue();

    $actions->resume('database', 'default');

    expect($pauser->isPaused('database', 'default'))->toBeFalse()
        ->and($pauser->shouldSleep('database', 'high,default'))->toBeFalse()
        ->and(Action::query()->orderBy('id')->pluck('action')->map(fn ($action): string => $action->value)->all())->toBe(['pause', 'pause', 'resume']);
});

function moveToFailedJobs(): string
{
    $job = DB::table('jobs')->sole();
    $payload = json_decode((string) $job->payload, true);

    DB::table('failed_jobs')->insert([
        'uuid' => $payload['uuid'],
        'connection' => 'database',
        'queue' => $job->queue,
        'payload' => $job->payload,
        'exception' => 'RuntimeException: boom',
        'failed_at' => now(),
    ]);
    DB::table('jobs')->delete();

    Job::query()->forUuid($payload['uuid'])->update(['status' => JobStatus::Failed->value]);

    return (string) $payload['uuid'];
}
