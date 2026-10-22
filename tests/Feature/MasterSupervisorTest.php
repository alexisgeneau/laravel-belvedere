<?php

declare(strict_types=1);

use Belvedere\Supervision\MasterSupervisor;
use Belvedere\Supervision\Repositories\CommandQueue;
use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Belvedere\Supervision\SupervisorProcess;
use Belvedere\Tests\Fixtures\Supervision\FakeProcess;

function supervisorFakes(MasterSupervisor $master): array
{
    return $master->supervisors->map(static fn (SupervisorProcess $supervisor): FakeProcess => $supervisor->process instanceof FakeProcess ? $supervisor->process : throw new LogicException)->all();
}

beforeEach(function (): void {
    MasterSupervisor::determineNameUsing(static fn (): string => 'web-1');
});

afterEach(function (): void {
    MasterSupervisor::determineNameUsing(null);
});

it('is named after the host and starts the supervisors it is given', function (): void {
    $master = $this->master('production');

    expect($master->name)->toStartWith('web-1-')
        ->and(strlen($master->name))->toBe(10)
        ->and(MasterSupervisor::name())->toBe($master->name);

    $master->provision($this->supervisorOptions(['name' => $master->name.':supervisor-1']));
    $master->provision($this->supervisorOptions(['name' => $master->name.':supervisor-2', 'queue' => 'reports']));
    $master->persist();

    $state = app(MasterRepository::class)->find($master->name);

    expect($master->supervisors)->toHaveCount(2)
        ->and(supervisorFakes($master)[0]->starts)->toBe(1)
        ->and($state?->environment)->toBe('production')
        ->and($state?->status)->toBe('running')
        ->and($state?->supervisors)->toBe([$master->name.':supervisor-1', $master->name.':supervisor-2'])
        ->and($state?->pid)->toBe(getmypid());
});

it('refuses a second master on the same machine', function (): void {
    $this->master()->persist();

    expect(fn () => $this->master()->ensureNoOtherMasterSupervisors())
        ->toThrow(RuntimeException::class, 'A master supervisor is already running on this machine.');
});

it('starts a supervisor again when it dies, unless it exited on purpose', function (): void {
    $this->freezeTime();

    $master = $this->master();
    $master->provision($this->supervisorOptions(['name' => $master->name.':restarted']));
    $master->provision($this->supervisorOptions(['name' => $master->name.':done']));
    $master->provision($this->supervisorOptions(['name' => $master->name.':duplicate']));

    [$restarted, $done, $duplicate] = supervisorFakes($master);

    $restarted->exitWith(1);
    $done->exitWith(0);
    $duplicate->exitWith(13);

    $this->travel(2)->seconds();
    $master->loop();

    $names = $master->supervisorNames();

    expect($names)->toBe([$master->name.':restarted'])
        ->and(supervisorFakes($master)[0])->not->toBe($restarted)
        ->and(supervisorFakes($master)[0]->isRunning())->toBeTrue();
});

it('relays pause and continue to its supervisors and asks them to exit with the code 1 on restart', function (): void {
    $master = $this->master();
    $master->provision($this->supervisorOptions(['name' => $master->name.':supervisor-1']));

    $master->pause();

    expect($master->working)->toBeFalse()
        ->and(supervisorFakes($master)[0]->signals)->toBe([SIGUSR2]);

    $master->continue();

    expect($master->working)->toBeTrue()
        ->and(supervisorFakes($master)[0]->signals)->toBe([SIGUSR2, SIGCONT]);

    $master->restart();

    expect(app(CommandQueue::class)->pending($master->name.':supervisor-1'))->toBe([['command' => 'terminate', 'payload' => ['status' => 1]]]);
});

it('obeys the orders left in the commands table and exits with the memory code when it outgrows its memory', function (): void {
    $master = $this->master(memoryLimit: 1024);
    $master->provision($this->supervisorOptions(['name' => $master->name.':supervisor-1']));
    $master->loop();

    app(CommandQueue::class)->push($master->name, CommandQueue::PAUSE);
    $this->travel(6)->seconds();
    $master->loop();

    expect($master->working)->toBeFalse()
        ->and(app(MasterRepository::class)->find($master->name)?->status)->toBe('paused');

    $this->master(memoryLimit: 0)->loop();

    expect($this->exits())->toBe([MasterSupervisor::MEMORY_EXIT_CODE]);
});

it('forgets its row at once on terminate, then waits for its supervisors', function (): void {
    $master = $this->master();
    $master->provision($this->supervisorOptions(['name' => $master->name.':supervisor-1']));
    $master->persist();

    $this->supervisor(['name' => $master->name.':supervisor-1'])->persist();

    $master->terminate();

    expect($this->exits())->toBe([0])
        ->and(app(MasterRepository::class)->recent())->toHaveCount(0)
        ->and(app(SupervisorRepository::class)->recent())->toHaveCount(0)
        ->and(supervisorFakes($master)[0]->signals)->toBe([SIGTERM])
        ->and(supervisorFakes($master)[0]->isRunning())->toBeFalse();
});
