<?php

declare(strict_types=1);

use Belvedere\Collector\QueueEventSubscriber;
use Belvedere\Collector\Sampler;
use Belvedere\Enums\JobStatus;
use Belvedere\Models\Job;
use Belvedere\Models\JobTag;
use Belvedere\Support\Redactor;
use Belvedere\Support\Storage;
use Belvedere\Tests\Fixtures\Jobs\FailingJob;
use Belvedere\Tests\Fixtures\Jobs\ReleasingJob;
use Belvedere\Tests\Fixtures\Jobs\SecretJob;
use Belvedere\Tests\Fixtures\Jobs\SelfFailingJob;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Belvedere\Tests\Fixtures\Jobs\TaggedJob;
use Belvedere\Tests\Fixtures\Product;
use Illuminate\Support\Facades\DB;

it('records a queued job with its tags and snapshot', function (): void {
    $product = Product::query()->create(['name' => 'Lamp']);

    SucceedingJob::dispatch('hello', $product);

    $job = Job::query()->sole();

    expect($job->status)->toBe(JobStatus::Queued)
        ->and($job->attempt)->toBe(1)
        ->and($job->connection)->toBe('database')
        ->and($job->queue)->toBe('default')
        ->and($job->name)->toBe(SucceedingJob::class)
        ->and($job->tags)->toBe([Product::class.':'.$product->id])
        ->and($job->snapshot)->toBe(['label' => 'hello', 'product' => Product::class.':'.$product->id])
        ->and($job->queued_at)->not->toBeNull()
        ->and(JobTag::query()->where('job_id', $job->id)->pluck('tag')->all())->toBe([Product::class.':'.$product->id]);
});

it('completes the execution once the worker has processed the job', function (): void {
    TaggedJob::dispatch();

    $this->workQueue();

    $job = Job::query()->sole();

    expect($job->status)->toBe(JobStatus::Completed)
        ->and($job->started_at)->not->toBeNull()
        ->and($job->finished_at)->not->toBeNull()
        ->and($job->wait_ms)->toBeGreaterThanOrEqual(0)
        ->and($job->run_ms)->toBeGreaterThanOrEqual(0)
        ->and($job->memory_kb)->toBeGreaterThan(0)
        ->and($job->worker_id)->toBe(gethostname().':'.getmypid())
        ->and($job->tags)->toBe(['reports', 'tenant:42'])
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('records every attempt of a job that fails after being released', function (): void {
    FailingJob::dispatch();

    $this->workQueue();

    $attempts = Job::query()->orderBy('attempt')->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->status)->toBe(JobStatus::Released)
        ->and($attempts[0]->exception)->toBe('RuntimeException: boom')
        ->and($attempts[1]->status)->toBe(JobStatus::Failed)
        ->and($attempts[1]->exception)->toStartWith('RuntimeException: boom')
        ->and($attempts[1]->queued_at?->toIso8601String())->toBe($attempts[0]->finished_at?->toIso8601String())
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('marks a job released by itself and completes its next attempt', function (): void {
    ReleasingJob::dispatch();

    $this->workQueue();

    $statuses = Job::query()->orderBy('attempt')->pluck('status')->map(fn (JobStatus $status): string => $status->value)->all();

    expect($statuses)->toBe(['released', 'completed']);
});

it('keeps the failed status when a job fails itself during its run', function (): void {
    SelfFailingJob::dispatch();

    $this->workQueue();

    expect(Job::query()->sole()->status)->toBe(JobStatus::Failed)
        ->and(DB::table('failed_jobs')->count())->toBe(1);
});

it('redacts secrets in snapshots', function (): void {
    SecretJob::dispatch();

    expect(Job::query()->sole()->snapshot)->toBe([
        'password' => Redactor::PLACEHOLDER,
        'apiToken' => Redactor::PLACEHOLDER,
        'email' => 'jane@example.com',
    ]);
});

it('aggregates per-minute metrics by queue and by job class', function (): void {
    $this->freezeTime();

    SucceedingJob::dispatch();
    SucceedingJob::dispatch();
    FailingJob::dispatch();

    $this->workQueue();

    $metrics = app(Storage::class)->query('metrics')->get()->keyBy(fn (object $row): string => $row->subject_type.':'.$row->subject);

    expect((int) $metrics['queue:default']->processed)->toBe(2)
        ->and((int) $metrics['queue:default']->failed)->toBe(1)
        ->and((int) $metrics['queue:default']->released)->toBe(1)
        ->and((int) $metrics['job:'.SucceedingJob::class]->processed)->toBe(2)
        ->and((int) $metrics['job:'.FailingJob::class]->failed)->toBe(1);
});

it('skips recording when the sampler rejects the job', function (): void {
    config()->set('belvedere.sampling', 1_000_000);
    $this->app->forgetInstance(Sampler::class);
    $this->app->forgetInstance(QueueEventSubscriber::class);

    SucceedingJob::dispatch();

    $this->workQueue();

    expect(Job::query()->count())->toBe(0);
});
