<?php

declare(strict_types=1);

use Belvedere\Supervision\MasterSupervisor;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Belvedere\Supervision\SystemProcesses;
use Belvedere\Support\Storage;
use Belvedere\Tests\Fixtures\Supervision\SignalRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

function fakeSignaller(): SignalRecorder
{
    $recorder = new SignalRecorder;

    app()->instance(ProcessSignaller::class, $recorder->signaller());

    return $recorder;
}

function output(string $command, array $parameters = []): string
{
    Artisan::call($command, $parameters);

    return Artisan::output();
}

function masterRow(string $name, int $pid, string $status = 'running', ?Carbon $seen = null, array $supervisors = []): void
{
    app(Storage::class)->query('masters')->insert([
        'name' => $name,
        'hostname' => 'web-1',
        'pid' => $pid,
        'environment' => 'testing',
        'status' => $status,
        'supervisors' => json_encode($supervisors),
        'started_at' => Carbon::now()->subHour(),
        'last_seen_at' => $seen ?? Carbon::now(),
    ]);
}

function supervisorRow(string $name, int $pid, string $status = 'running', array $processes = ['database:default' => 2], array $options = []): void
{
    app(Storage::class)->query('supervisors')->insert([
        'name' => $name,
        'master' => substr($name, 0, (int) strrpos($name, ':')),
        'hostname' => 'web-1',
        'pid' => $pid,
        'status' => $status,
        'processes' => json_encode($processes),
        'options' => json_encode(['queue' => 'default', 'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 2, 'timeout' => 60, ...$options]),
        'started_at' => Carbon::now()->subHour(),
        'last_seen_at' => Carbon::now(),
    ]);
}

beforeEach(function (): void {
    MasterSupervisor::determineNameUsing(static fn (): string => 'web-1');
});

afterEach(function (): void {
    MasterSupervisor::determineNameUsing(null);
});

it('terminates the masters of this machine only, and restarts the workers started by hand', function (): void {
    $recorder = fakeSignaller();
    masterRow('web-1-abcd', 4242);
    masterRow('web-1-ef01', 4243, 'paused');
    masterRow('other-zz99', 9);
    masterRow('web-1-gone', 10, seen: Carbon::now()->subMinutes(2));

    $this->artisan('belvedere:terminate')->assertExitCode(0)->expectsOutputToContain('Sending SIGTERM to processes 4242, 4243.');

    expect($recorder->sent)->toBe([[4242, SIGTERM], [4243, SIGTERM]])
        ->and(Cache::get('illuminate:queue:restart'))->not->toBeNull();
});

it('pauses and resumes the masters of this machine', function (): void {
    $recorder = fakeSignaller();
    masterRow('web-1-abcd', 4242);

    $this->artisan('belvedere:pause')->assertExitCode(0);
    $this->artisan('belvedere:continue')->assertExitCode(0);

    expect($recorder->sent)->toBe([[4242, SIGUSR2], [4242, SIGCONT]]);
});

it('says so when there is nothing to signal', function (): void {
    $recorder = fakeSignaller();

    $this->artisan('belvedere:terminate')->assertExitCode(0)->expectsOutputToContain('No master supervisor to terminate on this machine.');
    $this->artisan('belvedere:pause')->assertExitCode(0)->expectsOutputToContain('No master supervisor to pause on this machine.');

    expect($recorder->sent)->toBe([]);
});

it('pauses and resumes one supervisor by its configuration key', function (): void {
    $recorder = fakeSignaller();
    supervisorRow('web-1-abcd:reports', 777);
    supervisorRow('other-zz99:reports', 778);

    $this->artisan('belvedere:pause-supervisor', ['name' => 'reports'])->assertExitCode(0);
    $this->artisan('belvedere:continue-supervisor', ['name' => 'web-1-abcd:reports'])->assertExitCode(0);
    $this->artisan('belvedere:pause-supervisor', ['name' => 'nope'])->assertExitCode(1)->expectsOutputToContain('No running supervisor named [nope]');

    expect($recorder->sent)->toBe([[777, SIGUSR2], [777, SIGCONT]]);
});

it('reports the supervision in belvedere:status with an exit code scripts can test', function (): void {
    $this->artisan('belvedere:status')->assertExitCode(0)->expectsOutputToContain('not used');

    masterRow('web-1-abcd', 4242, supervisors: ['web-1-abcd:supervisor-1']);
    supervisorRow('web-1-abcd:supervisor-1', 777);

    $this->artisan('belvedere:status')->assertExitCode(0)->expectsOutputToContain('Belvedere is running.');

    app(Storage::class)->query('masters')->update(['status' => 'paused']);

    $this->artisan('belvedere:status')->assertExitCode(1)->expectsOutputToContain('Belvedere is paused.');

    app(Storage::class)->query('masters')->update(['status' => 'running', 'last_seen_at' => Carbon::now()->subMinutes(2)]);

    $this->artisan('belvedere:status')->assertExitCode(2)->expectsOutputToContain('Belvedere is inactive');
});

it('lists the masters and their supervisors', function (): void {
    masterRow('web-1-abcd', 4242, supervisors: ['web-1-abcd:supervisor-1']);
    supervisorRow('web-1-abcd:supervisor-1', 777, processes: ['database:default' => 2, 'database:media' => 1], options: ['queue' => 'default,media', 'maxProcesses' => 3]);

    $listing = output('belvedere:supervisors');

    expect($listing)->toContain('web-1-abcd (web-1)')
        ->toContain('supervisor-1')
        ->toContain('default 2, media 1')
        ->toContain('1 to 3 (at max)');

    app(Storage::class)->query('masters')->delete();

    expect(output('belvedere:supervisors'))->toContain('No master supervisor has reported in the last 24 hours.');
});

it('finds the workers of this machine that no supervisor tracks, and kills them on demand', function (): void {
    $recorder = fakeSignaller();
    supervisorRow('web-1-abcd:supervisor-1', 777);
    supervisorRow('web-1-abcd:reports', 778);

    // pid, parent pid, command line. 1240 descends from its supervisor through a wrapper;
    // 1241 carries the name of a live supervisor, but was reparented to the master when
    // the supervisor it came from was killed.
    app()->instance(SystemProcesses::class, new SystemProcesses(static fn (string $command): array => [
        '    1     0 php artisan belvedere',
        '  777     1 php artisan belvedere:supervisor web-1-abcd:supervisor-1 database',
        '  778     1 php artisan belvedere:supervisor web-1-abcd:reports database',
        ' 1234   777 php artisan queue:work database --name=web-1-abcd:supervisor-1 --queue=default',
        " 1235     1 php artisan queue:work 'database' --name='web-1-abcd:dead' --queue='default'",
        ' 1236     1 php artisan queue:work database --name=other-zz99:supervisor-1',
        ' 1237     1 php artisan queue:work database --queue=mail',
        ' 1238     1 php artisan schedule:work',
        ' 1239   778 php-wrapper',
        ' 1240  1239 php artisan queue:work database --name="web-1-abcd:reports"',
        ' 1241     1 php artisan queue:work database --name=web-1-abcd:reports',
    ]));

    $listing = output('belvedere:orphans');

    expect($listing)->toContain('1235')->toContain('web-1-abcd:dead')->toContain('1241')->toContain('Run again with --kill')
        ->and($listing)->not->toContain('1234')->not->toContain('1240')
        ->and($recorder->sent)->toBe([]);

    $this->artisan('belvedere:orphans', ['--kill' => true])->assertExitCode(0);
    $this->artisan('belvedere:orphans', ['--kill' => true, '--signal' => 'SIGKILL'])->assertExitCode(0);
    $this->artisan('belvedere:orphans', ['--kill' => true, '--signal' => 'SIGNOPE'])->assertExitCode(1);

    expect($recorder->sent)->toBe([[1235, SIGTERM], [1241, SIGTERM], [1235, SIGKILL], [1241, SIGKILL]])
        ->and(app(SystemProcesses::class)->countWorkersNamed('web-1-abcd:supervisor-1'))->toBe(1)
        ->and(app(SystemProcesses::class)->countWorkersNamed('web-1-abcd:reports'))->toBe(2);
});

it('refuses to start next to a master of this machine, forgets the row of a dead one, and refuses an impossible configuration', function (): void {
    // Another master of this machine, whose process (this one) is alive.
    masterRow('web-1-zz99', (int) getmypid());

    $this->artisan('belvedere')->assertExitCode(1)->expectsOutputToContain('already running on this machine as [web-1-zz99]');

    // The row of a master that died without a word, and a broken configuration found right after.
    app(Storage::class)->query('masters')->delete();
    masterRow('web-1-zz99', 2 ** 22 - 1, supervisors: ['web-1-zz99:supervisor-1']);
    supervisorRow('web-1-zz99:supervisor-1', 2 ** 22 - 2);
    config()->set('belvedere.supervisor.environments.testing.supervisor-1.minProcesses', 0);

    $this->artisan('belvedere')->assertExitCode(1)->expectsOutputToContain('belvedere.supervisor.environments.testing.supervisor-1.minProcesses must be at least 1.');

    expect(app(Storage::class)->query('masters')->count())->toBe(0)
        ->and(app(Storage::class)->query('supervisors')->count())->toBe(0);
})->skip(ProcessSignaller::unsupported() !== null, 'No pcntl here.');

it('exits with the duplicate code when a supervisor with the same name is alive', function (): void {
    supervisorRow('web-1-abcd:supervisor-1', 777);

    expect(Artisan::call('belvedere:supervisor', ['name' => 'web-1-abcd:supervisor-1', 'connection' => 'database']))->toBe(13)
        ->and(app(SupervisorRepository::class)->find('web-1-abcd:supervisor-1'))->not->toBeNull();
})->skip(ProcessSignaller::unsupported() !== null, 'No pcntl here.');
