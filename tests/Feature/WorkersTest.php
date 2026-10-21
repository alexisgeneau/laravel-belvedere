<?php

declare(strict_types=1);

use Belvedere\Enums\WorkerStatus;
use Belvedere\Models\Worker;
use Belvedere\Queues\JobActions;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

it('registers a heartbeat for the worker and marks it stopped when it exits', function (): void {
    SucceedingJob::dispatch();

    $this->workQueue();

    $worker = Worker::query()->sole();

    expect($worker->id)->toBe(gethostname().':'.getmypid())
        ->and($worker->connection)->toBe('database')
        ->and($worker->queues)->toBe('default')
        ->and($worker->status)->toBe(WorkerStatus::Stopped)
        ->and($worker->jobs_processed)->toBe(1)
        ->and($worker->memory_kb)->toBeGreaterThan(0);
});

it('puts the worker to sleep while every queue it consumes is paused', function (): void {
    app(JobActions::class)->pause('database', 'default');

    expect(Event::until(new Looping('database', 'default')))->toBeFalse()
        ->and(Worker::query()->sole()->status)->toBe(WorkerStatus::Paused);

    app(JobActions::class)->resume('database', 'default');

    expect(Event::until(new Looping('database', 'default')))->toBeNull()
        ->and(Worker::query()->sole()->status)->toBe(WorkerStatus::Running);
});

it('reports a worker dead once its heartbeat is too old', function (): void {
    Event::until(new Looping('database', 'default'));

    $worker = Worker::query()->sole();

    expect($worker->isDead(90))->toBeFalse();

    $this->travel(2)->minutes();

    expect(Worker::query()->sole()->isDead(90))->toBeTrue()
        ->and(Worker::query()->dead(90)->count())->toBe(1)
        ->and(app(JobActions::class)->forgetWorker($worker->id))->toBeTrue()
        ->and(Worker::query()->count())->toBe(0);
});
