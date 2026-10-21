<?php

declare(strict_types=1);

use Belvedere\Models\Job;
use Belvedere\Queues\Batches;
use Belvedere\Queues\JobActions;
use Belvedere\Queues\NativeJob;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\NativeState;
use Belvedere\Tests\Fixtures\Jobs\BatchedJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

it('links queued jobs to their batch and exposes the batch progress', function (): void {
    $batch = Bus::batch([new BatchedJob, new BatchedJob(fails: true)])->name('nightly')->allowFailures()->dispatch();

    $pending = app(NativeQueue::class)->jobs('database', NativeState::Pending, null, 10, null);

    expect($pending->map(fn (NativeJob $job): ?string => $job->batchId)->unique()->all())->toBe([$batch->id]);

    $this->workQueue();

    expect(Job::query()->pluck('batch_id')->unique()->all())->toBe([$batch->id]);

    $fresh = app(Batches::class)->find($batch->id);
    $summary = app(Batches::class)->toArray($fresh);

    expect($summary['name'])->toBe('nightly')
        ->and($summary['total_jobs'])->toBe(2)
        ->and($summary['failed_jobs'])->toBe(1)
        ->and($summary['pending_jobs'])->toBe(1)
        ->and($summary['failed_job_ids'])->toHaveCount(1)
        ->and(app(Batches::class)->list(10, null)->first()->id)->toBe($batch->id);
});

it('cancels a batch and retries its failed jobs', function (): void {
    $batch = Bus::batch([new BatchedJob(fails: true)])->allowFailures()->dispatch();

    $this->workQueue();

    $actions = app(JobActions::class);

    expect($actions->retryBatch($batch->id))->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($actions->cancelBatch($batch->id))->toBeTrue()
        ->and($actions->cancelBatch($batch->id))->toBeFalse()
        ->and(app(Batches::class)->find($batch->id)->cancelled())->toBeTrue();
});

it('exposes a batch with its failed jobs through the api', function (): void {
    $this->authorize();

    $batch = Bus::batch([new BatchedJob, new BatchedJob(fails: true)])->allowFailures()->dispatch();

    $this->workQueue();

    $this->getJson('/belvedere/api/batches')
        ->assertOk()
        ->assertJsonPath('batches.0.id', $batch->id);

    $this->getJson("/belvedere/api/batches/{$batch->id}")
        ->assertOk()
        ->assertJsonPath('batch.failed_jobs', 1)
        ->assertJsonCount(1, 'failed_jobs')
        ->assertJsonPath('failed_jobs.0.batch_id', $batch->id)
        ->assertJsonPath('failed_jobs.0.name', BatchedJob::class);

    $this->getJson('/belvedere/api/batches/missing')->assertNotFound();
});
