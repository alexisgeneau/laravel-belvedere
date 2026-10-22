<?php

declare(strict_types=1);

use Belvedere\Supervision\Repositories\CommandQueue;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Belvedere\Supervision\Supervisor;
use Belvedere\Supervision\WorkerProcess;
use Belvedere\Support\Storage;
use Belvedere\Support\WorkerIdentity;
use Belvedere\Tests\Fixtures\Supervision\FakeProcess;
use Belvedere\Tests\Fixtures\Supervision\FakeQueueLoad;

function fakes(Supervisor $supervisor): array
{
    return $supervisor->processes()->map(static fn (WorkerProcess $process): FakeProcess => $process->process instanceof FakeProcess ? $process->process : throw new LogicException)->all();
}

it('writes its heartbeat with its pools and options, and forgets it when terminated', function (): void {
    $supervisor = $this->supervisor(['queue' => 'default,media', 'maxProcesses' => 4]);
    $supervisor->scale(2);
    $supervisor->persist();

    $state = app(SupervisorRepository::class)->find('test-master:supervisor-1');

    expect($state)->not->toBeNull()
        ->and($state->master)->toBe('test-master')
        ->and($state->pid)->toBe(getmypid())
        ->and($state->status)->toBe('running')
        ->and($state->processes)->toBe(['database:default' => 1, 'database:media' => 1])
        ->and($state->options['maxProcesses'])->toBe(4)
        ->and($state->timeout())->toBe(60)
        ->and(app(SupervisorRepository::class)->longestActiveTimeout())->toBe(60)
        ->and($supervisor->masterName())->toBe('test-master');

    $this->travel(31)->seconds();

    expect(app(SupervisorRepository::class)->find('test-master:supervisor-1'))->toBeNull()
        ->and(app(SupervisorRepository::class)->recent())->toHaveCount(1);

    $this->travelBack();
    $supervisor->terminate();

    expect($this->exits())->toBe([0])
        ->and(app(SupervisorRepository::class)->recent())->toHaveCount(0)
        ->and(array_map(static fn (FakeProcess $process): array => $process->signals, fakes($supervisor)))->toBe([[SIGTERM], [SIGTERM]])
        ->and($supervisor->runningProcesses())->toHaveCount(0);
});

it('refuses to start twice under the same name', function (): void {
    $this->supervisor()->persist();

    expect(fn () => $this->supervisor()->ensureNoDuplicateSupervisors())
        ->toThrow(RuntimeException::class, 'A supervisor named [test-master:supervisor-1] is already running.');
});

it('obeys the orders left for it in the commands table at its heartbeat', function (): void {
    $supervisor = $this->supervisor(['balance' => false, 'minProcesses' => 1, 'maxProcesses' => 3], new FakeQueueLoad(['default' => 3]));
    $supervisor->loop();

    expect($supervisor->processes())->toHaveCount(1);

    app(CommandQueue::class)->push('test-master:supervisor-1', CommandQueue::PAUSE);
    app(CommandQueue::class)->push('someone-else', CommandQueue::TERMINATE);

    $this->travel(6)->seconds();
    $supervisor->loop();

    expect($supervisor->isPaused())->toBeTrue()
        ->and(app(SupervisorRepository::class)->find('test-master:supervisor-1')?->status)->toBe('paused')
        ->and(fakes($supervisor)[0]->signals)->toBe([SIGUSR2])
        ->and(app(CommandQueue::class)->pending('someone-else'))->toHaveCount(1);

    app(CommandQueue::class)->push('test-master:supervisor-1', CommandQueue::CONTINUE);
    $this->travel(6)->seconds();
    $supervisor->loop();

    expect($supervisor->isPaused())->toBeFalse()
        ->and(fakes($supervisor)[0]->signals)->toBe([SIGUSR2, SIGCONT]);

    app(CommandQueue::class)->push('test-master:supervisor-1', CommandQueue::TERMINATE, ['status' => 1]);
    $this->travel(6)->seconds();
    $supervisor->loop();

    expect($this->exits())->toBe([1]);
});

it('does not balance nor relaunch workers while paused', function (): void {
    $supervisor = $this->supervisor(['balance' => false, 'minProcesses' => 1, 'maxProcesses' => 3], new FakeQueueLoad(['default' => 3]));
    $supervisor->loop();
    $supervisor->pause();

    fakes($supervisor)[0]->exitWith(1);
    $this->travel(6)->seconds();
    $supervisor->loop();

    expect(fakes($supervisor)[0]->starts)->toBe(1)
        ->and($supervisor->processes())->toHaveCount(1);

    $supervisor->continue();
    $supervisor->loop();

    expect(fakes($supervisor)[0]->starts)->toBe(2)
        ->and($supervisor->processes())->toHaveCount(2);
});

it('reports for its workers while they are paused, since a paused worker no longer loops', function (): void {
    $supervisor = $this->supervisor(['balance' => false, 'minProcesses' => 2, 'maxProcesses' => 2]);
    $supervisor->scale(2);

    $pids = fakes($supervisor);
    $row = static fn (int $pid): array => [
        'id' => WorkerIdentity::hostname().":{$pid}", 'hostname' => WorkerIdentity::hostname(), 'pid' => $pid, 'connection' => 'database', 'queues' => 'default',
        'supervisor' => 'test-master:supervisor-1', 'status' => 'running', 'jobs_processed' => 0, 'started_at' => now(), 'last_seen_at' => now(),
    ];
    app(Storage::class)->query('workers')->insert([$row((int) $pids[0]->getPid()), $row((int) $pids[1]->getPid()), [...$row(9999), 'hostname' => 'elsewhere', 'id' => 'elsewhere:9999']]);

    $supervisor->pause();
    $this->travel(2)->minutes();
    $supervisor->loop();

    $workers = app(Storage::class)->query('workers')->orderBy('pid')->get();

    expect($workers->pluck('status')->all())->toBe(['paused', 'paused', 'running'])
        ->and($workers[0]->last_seen_at)->toBe(now()->toDateTimeString())
        ->and($workers[2]->last_seen_at)->toBe(now()->subMinutes(2)->toDateTimeString());

    $supervisor->continue();

    expect(app(Storage::class)->query('workers')->orderBy('pid')->pluck('status')->all())->toBe(['running', 'running', 'running']);
});

it('exits with the memory code when it outgrows its memory, for the master to start it again', function (): void {
    $supervisor = $this->supervisor(['memory' => 0]);
    $supervisor->loop();

    expect($this->exits())->toBe([Supervisor::MEMORY_EXIT_CODE]);
});

it('kills the workers that have not finished when the timeout has passed after terminate', function (): void {
    $this->freezeTime();

    $supervisor = $this->supervisor(['timeout' => 0], workersExitOnTerm: false);
    $supervisor->scale(1);
    $supervisor->terminate();

    expect($this->exits())->toBe([0])
        ->and(fakes($supervisor)[0]->stopped)->toBeTrue();
});
