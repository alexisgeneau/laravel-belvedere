<?php

declare(strict_types=1);

use Belvedere\Supervision\AutoScaler;
use Belvedere\Supervision\ProcessPool;
use Belvedere\Supervision\Supervisor;
use Belvedere\Supervision\WorkerProcess;
use Belvedere\Tests\Fixtures\Supervision\FakeProcess;
use Belvedere\Tests\Fixtures\Supervision\FakeQueueLoad;

/** @return array<string, int> queue => workers */
function poolSizes(Supervisor $supervisor): array
{
    return $supervisor->processPools->mapWithKeys(static fn (ProcessPool $pool): array => [$pool->queue() => $pool->count()])->all();
}

/** Scales, letting the workers asked to leave finish between two rounds as real ones would. */
function scaleTimes(Supervisor $supervisor, FakeQueueLoad $load, int $times, bool $leavingFinish = true): void
{
    $scaler = new AutoScaler($load);

    for ($i = 0; $i < $times; $i++) {
        $scaler->scale($supervisor);

        if ($leavingFinish) {
            $supervisor->processPools->each(static fn (ProcessPool $pool) => $pool->terminatingProcesses()->each(static fn (WorkerProcess $process) => $process->process instanceof FakeProcess ? $process->process->exitWith(0) : null));
        }
    }
}

it('gives the busy queue the processes, balanceMaxShift at a time, and leaves minProcesses to the others', function (): void {
    $load = new FakeQueueLoad(['default' => 100, 'media' => 0], ['default' => 1000.0, 'media' => 1000.0]);
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 10]);

    scaleTimes($supervisor, $load, 1);

    expect(poolSizes($supervisor))->toBe(['default' => 1, 'media' => 1]);

    scaleTimes($supervisor, $load, 1);

    expect(poolSizes($supervisor))->toBe(['default' => 2, 'media' => 1]);

    scaleTimes($supervisor, $load, 20);

    expect(poolSizes($supervisor))->toBe(['default' => 9, 'media' => 1])
        ->and($supervisor->totalProcessCount())->toBe(10);
});

it('shares the processes by the time needed to clear each queue', function (): void {
    // media: 10 jobs of 10 s, default: 100 jobs of 100 ms: media needs ten times longer.
    $load = new FakeQueueLoad(['default' => 100, 'media' => 10], ['default' => 100.0, 'media' => 10000.0]);
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 11, 'balanceMaxShift' => 5]);

    scaleTimes($supervisor, $load, 10);

    expect(poolSizes($supervisor))->toBe(['default' => 1, 'media' => 10]);
});

it('gives the only free slot to the busiest queue when every pool asks for more', function (): void {
    // media needs about 55 s of work and default 35 s, for three processes at most: both pools
    // round up to two, and the one slot left must go to media, not to the first pool in line.
    $load = new FakeQueueLoad(['default' => 700, 'media' => 220], ['default' => 50.0, 'media' => 250.0]);
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 3]);

    scaleTimes($supervisor, $load, 5);

    expect(poolSizes($supervisor))->toBe(['default' => 1, 'media' => 2]);
});

it('shares the processes by the number of jobs with the size strategy', function (): void {
    $load = new FakeQueueLoad(['default' => 100, 'media' => 10], ['default' => 100.0, 'media' => 10000.0]);
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => 'auto', 'autoScalingStrategy' => 'size', 'minProcesses' => 1, 'maxProcesses' => 11, 'balanceMaxShift' => 5]);

    scaleTimes($supervisor, $load, 10);

    expect(poolSizes($supervisor))->toBe(['default' => 10, 'media' => 1]);
});

it('comes back to minProcesses when the queues are empty', function (): void {
    $busy = new FakeQueueLoad(['default' => 100, 'media' => 0], ['default' => 1000.0]);
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 6, 'balanceMaxShift' => 2]);

    scaleTimes($supervisor, $busy, 10);

    expect(poolSizes($supervisor))->toBe(['default' => 5, 'media' => 1]);

    scaleTimes($supervisor, new FakeQueueLoad, 10);

    expect(poolSizes($supervisor))->toBe(['default' => 1, 'media' => 1]);
});

it('fills the non-empty queues up to the maximum when no runtime is known yet', function (): void {
    $load = new FakeQueueLoad(['default' => 5, 'media' => 0]);
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 4, 'balanceMaxShift' => 4]);

    scaleTimes($supervisor, $load, 2);

    expect(poolSizes($supervisor))->toBe(['default' => 3, 'media' => 1]);
});

it('splits the processes evenly with the simple strategy, whatever the load', function (): void {
    $load = new FakeQueueLoad(['default' => 100, 'media' => 0], ['default' => 1000.0]);
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => 'simple', 'minProcesses' => 1, 'maxProcesses' => 6, 'balanceMaxShift' => 6]);

    scaleTimes($supervisor, $load, 3);

    expect(poolSizes($supervisor))->toBe(['default' => 3, 'media' => 3]);
});

it('runs one pool over the ordered queues without balancing, sized by the jobs ready', function (): void {
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => false, 'minProcesses' => 1, 'maxProcesses' => 5, 'balanceMaxShift' => 5]);

    expect($supervisor->processPools)->toHaveCount(1)
        ->and($supervisor->processPools->first()->queue())->toBe('default,media');

    scaleTimes($supervisor, new FakeQueueLoad(['default' => 2, 'media' => 1]), 2);

    expect(poolSizes($supervisor))->toBe(['default,media' => 3]);

    scaleTimes($supervisor, new FakeQueueLoad(['default' => 100]), 2);

    expect(poolSizes($supervisor))->toBe(['default,media' => 5]);

    scaleTimes($supervisor, new FakeQueueLoad, 2);

    expect(poolSizes($supervisor))->toBe(['default,media' => 1]);
});

it('counts the workers still finishing their job before adding more', function (): void {
    $this->freezeTime();

    $busy = new FakeQueueLoad(['default' => 100, 'media' => 0], ['default' => 1000.0]);
    $supervisor = $this->supervisor(['queue' => 'default,media', 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 4, 'balanceMaxShift' => 4, 'timeout' => 60], workersExitOnTerm: false);

    scaleTimes($supervisor, $busy, 2);

    expect(poolSizes($supervisor))->toBe(['default' => 3, 'media' => 1]);

    // The load moves to media: default scales down, its two leaving workers still count
    // while they finish their job, so media cannot grow yet.
    $media = new FakeQueueLoad(['default' => 0, 'media' => 100], ['media' => 1000.0]);

    scaleTimes($supervisor, $media, 1, leavingFinish: false);

    expect(poolSizes($supervisor))->toBe(['default' => 1, 'media' => 1])
        ->and($supervisor->totalProcessCount())->toBe(4);

    scaleTimes($supervisor, $media, 1, leavingFinish: false);

    expect(poolSizes($supervisor))->toBe(['default' => 1, 'media' => 1]);

    scaleTimes($supervisor, $media, 2);

    expect(poolSizes($supervisor))->toBe(['default' => 1, 'media' => 3]);
});
