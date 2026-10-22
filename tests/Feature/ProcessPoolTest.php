<?php

declare(strict_types=1);

use Belvedere\Supervision\Events\UnableToLaunchProcess;
use Belvedere\Supervision\Events\WorkerProcessRestarting;
use Belvedere\Supervision\ProcessPool;
use Belvedere\Supervision\SupervisorOptions;
use Belvedere\Supervision\WorkerProcess;
use Belvedere\Tests\Fixtures\Supervision\FakeProcess;
use Illuminate\Support\Facades\Event;

function pool(array $options = []): ProcessPool
{
    return new ProcessPool(
        test()->supervisorOptions($options),
        static fn (SupervisorOptions $options): WorkerProcess => new WorkerProcess(new FakeProcess($options->queue)),
        static fn (string $type, string $line): null => null,
    );
}

function fakeOf(WorkerProcess $process): FakeProcess
{
    return $process->process instanceof FakeProcess ? $process->process : throw new LogicException('Not a fake process.');
}

it('starts workers when scaled up and asks the leaving ones to finish when scaled down', function (): void {
    $pool = pool(['timeout' => 60]);

    $pool->scale(3);

    expect($pool->count())->toBe(3)
        ->and($pool->totalProcessCount())->toBe(3)
        ->and($pool->processes()->every(static fn (WorkerProcess $process): bool => $process->isRunning()))->toBeTrue();

    $pool->scale(1);

    expect($pool->count())->toBe(1)
        ->and($pool->terminatingProcesses())->toHaveCount(2)
        ->and($pool->totalProcessCount())->toBe(3)
        ->and($pool->runningProcesses())->toHaveCount(3)
        ->and($pool->terminatingProcesses()->map(static fn (WorkerProcess $process): array => fakeOf($process)->signals)->all())->toBe([[SIGTERM], [SIGTERM]]);

    fakeOf($pool->terminatingProcesses()->first())->exitWith(0);

    expect($pool->terminatingProcesses())->toHaveCount(1)
        ->and($pool->totalProcessCount())->toBe(2);
});

it('stops a leaving worker that is still running past the timeout', function (): void {
    $this->freezeTime();

    $pool = pool(['timeout' => 30]);
    $pool->scale(1);
    $pool->scale(0);

    $leaving = fakeOf($pool->terminatingProcesses()->sole());

    $this->travel(29)->seconds();
    $pool->pruneTerminatingProcesses();

    expect($leaving->stopped)->toBeFalse();

    $this->travel(2)->seconds();
    $pool->pruneTerminatingProcesses();

    expect($leaving->stopped)->toBeTrue()
        ->and($pool->terminatingProcesses())->toHaveCount(0);
});

it('restarts a worker that died, a second after its launch at the earliest', function (): void {
    Event::fake([WorkerProcessRestarting::class, UnableToLaunchProcess::class]);
    $this->freezeTime();

    $pool = pool();
    $pool->scale(1);

    $worker = $pool->processes()->sole();
    fakeOf($worker)->exitWith(1);

    $pool->monitor();

    expect(fakeOf($worker)->starts)->toBe(1);

    $this->travel(2)->seconds();
    $pool->monitor();

    expect(fakeOf($worker)->starts)->toBe(2)
        ->and($pool->count())->toBe(1);

    Event::assertDispatched(WorkerProcessRestarting::class);
});

it('waits a minute before relaunching a worker that dies right after its launch', function (): void {
    Event::fake([WorkerProcessRestarting::class, UnableToLaunchProcess::class]);
    $this->freezeTime();

    $pool = pool();
    $pool->scale(1);
    $worker = $pool->processes()->sole();

    // First death: restarted after a second, but it started less than a minute ago: a crash loop.
    fakeOf($worker)->exitWith(255);
    $this->travel(2)->seconds();
    $pool->monitor();

    expect(fakeOf($worker)->starts)->toBe(2)
        ->and($worker->coolingDown())->toBeTrue();

    Event::assertDispatched(UnableToLaunchProcess::class);

    fakeOf($worker)->exitWith(255);
    $this->travel(30)->seconds();
    $pool->monitor();

    expect(fakeOf($worker)->starts)->toBe(2);

    $this->travel(31)->seconds();
    $pool->monitor();

    expect(fakeOf($worker)->starts)->toBe(3);
});

it('relaunches at once a worker that exited on purpose, however soon', function (): void {
    Event::fake([UnableToLaunchProcess::class]);
    $this->freezeTime();

    $pool = pool();
    $pool->scale(1);
    $worker = $pool->processes()->sole();

    foreach ([1, 2, 3] as $round) {
        fakeOf($worker)->exitWith(0);
        $this->travel(2)->seconds();
        $pool->monitor();

        expect(fakeOf($worker)->starts)->toBe($round + 1);
    }

    Event::assertNotDispatched(UnableToLaunchProcess::class);
});

it('pauses, continues and restarts its workers', function (): void {
    $pool = pool();
    $pool->scale(2);

    $pool->pause();
    $pool->continue();

    expect($pool->processes()->map(static fn (WorkerProcess $process): array => fakeOf($process)->signals)->all())->toBe([[SIGUSR2, SIGCONT], [SIGUSR2, SIGCONT]])
        ->and($pool->working)->toBeTrue();

    $before = $pool->processes()->all();
    $pool->restart();

    expect($pool->count())->toBe(2)
        ->and($pool->terminatingProcesses())->toHaveCount(2)
        ->and($pool->processes()->all())->not->toBe($before);
});
