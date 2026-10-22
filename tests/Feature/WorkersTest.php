<?php

declare(strict_types=1);

use Belvedere\Collector\QueueEventSubscriber;
use Belvedere\Collector\WorkerHeartbeat;
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

it('starts over when a new process inherits the id of a previous worker', function (): void {
    // A restarted container keeps its hostname and runs the worker as pid 1 again: same id, new process.
    $this->freezeTime();

    app(WorkerHeartbeat::class)->jobProcessed();
    Event::until(new Looping('database', 'default'));

    $previous = Worker::query()->sole();

    expect($previous->jobs_processed)->toBe(1);

    $this->travel(5)->minutes();
    $this->app->forgetInstance(WorkerHeartbeat::class);
    $this->app->forgetInstance(QueueEventSubscriber::class);

    Event::until(new Looping('database', 'high'));

    $worker = Worker::query()->sole();

    expect($worker->id)->toBe($previous->id)
        ->and($worker->started_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($worker->started_at->greaterThan($previous->started_at))->toBeTrue()
        ->and($worker->jobs_processed)->toBe(0)
        ->and($worker->queues)->toBe('high');
});
