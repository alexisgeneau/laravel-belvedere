<?php

declare(strict_types=1);

use Belvedere\Supervision\InvalidSupervisorConfiguration;
use Belvedere\Supervision\ProvisioningPlan;
use Illuminate\Contracts\Config\Repository;

function plan(array $environments, array $defaults = [], string $master = 'web-1-ab12'): ProvisioningPlan
{
    return new ProvisioningPlan($master, $environments, $defaults, app(Repository::class), '/var/www/app');
}

beforeEach(function (): void {
    // A queue connection on a server database: the SQLite cap of the test connection must not apply.
    config()->set('database.connections.server', ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'app']);
    config()->set('queue.connections.server', ['driver' => 'database', 'connection' => 'server', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
});

it('merges the defaults under every environment and names the supervisors after the master', function (): void {
    $plan = plan(
        ['production' => ['supervisor-1' => ['maxProcesses' => 10, 'balanceMaxShift' => 2]], 'local' => ['supervisor-1' => ['maxProcesses' => 3]]],
        ['supervisor-1' => ['connection' => 'server', 'queue' => ['default', 'media'], 'balance' => 'auto', 'maxProcesses' => 1, 'tries' => 2, 'timeout' => 30]],
    );

    $production = $plan->forEnvironment('production');

    expect(array_keys($production))->toBe(['supervisor-1'])
        ->and($production['supervisor-1']->name)->toBe('web-1-ab12:supervisor-1')
        ->and($production['supervisor-1']->queue)->toBe('default,media')
        ->and($production['supervisor-1']->maxProcesses)->toBe(10)
        ->and($production['supervisor-1']->balanceMaxShift)->toBe(2)
        ->and($production['supervisor-1']->tries)->toBe(2)
        ->and($production['supervisor-1']->timeout)->toBe(30)
        ->and($production['supervisor-1']->parentId)->toBe(getmypid())
        ->and($production['supervisor-1']->directory)->toBe('/var/www/app')
        ->and($plan->forEnvironment('local')['supervisor-1']->maxProcesses)->toBe(3)
        ->and($plan->environments())->toBe(['production', 'local']);
});

it('matches the environment exactly first, then by the wildcards in the order of the configuration', function (): void {
    $plan = plan([
        'production-*' => ['region' => ['connection' => 'server', 'maxProcesses' => 2]],
        'production-eu' => ['exact' => ['connection' => 'server', 'maxProcesses' => 3]],
        '*' => ['fallback' => ['connection' => 'server', 'maxProcesses' => 1]],
    ]);

    expect(array_keys($plan->forEnvironment('production-eu')))->toBe(['exact'])
        ->and(array_keys($plan->forEnvironment('production-us')))->toBe(['region'])
        ->and(array_keys($plan->forEnvironment('staging')))->toBe(['fallback'])
        ->and(plan(['production' => ['s' => ['connection' => 'server']]])->forEnvironment('local'))->toBe([]);
});

it('accepts the spellings Horizon accepts', function (): void {
    $options = plan(['production' => ['s' => ['connection' => 'server', 'queue' => ['a', 'b'], 'balance' => 'simple', 'processes' => 4, 'backoff' => [5, 30], 'max_tries' => 2]]])->forEnvironment('production')['s'];

    expect($options->maxProcesses)->toBe(4)
        ->and($options->backoff)->toBe('5,30')
        ->and($options->tries)->toBe(2)
        ->and($options->balance)->toBe('simple');
});

it('refuses a configuration that cannot work, naming the key', function (array $supervisor, string $message): void {
    expect(fn () => plan(['production' => ['supervisor-1' => ['connection' => 'server', ...$supervisor]]]))
        ->toThrow(InvalidSupervisorConfiguration::class, $message);
})->with([
    'minProcesses under 1' => [['minProcesses' => 0], 'belvedere.supervisor.environments.production.supervisor-1.minProcesses must be at least 1.'],
    'maxProcesses under the queues' => [['queue' => ['a', 'b', 'c'], 'minProcesses' => 2, 'maxProcesses' => 5], 'maxProcesses must be at least 6: minProcesses (2) for each of the 3 queues.'],
    'negative maxProcesses' => [['maxProcesses' => -1], 'maxProcesses must be 0 (supervisor disabled) or more.'],
    'unknown balance' => [['balance' => 'round-robin'], 'balance must be "auto", "simple" or false.'],
    'unknown strategy' => [['autoScalingStrategy' => 'log'], 'autoScalingStrategy must be "time" or "size".'],
    'empty queue' => [['queue' => ''], 'queue must name at least one queue.'],
    'unknown connection' => [['connection' => 'nope'], 'connection names a queue connection that does not exist: [nope].'],
    'negative cooldown' => [['balanceCooldown' => 0], 'balanceCooldown must be at least 1.'],
]);

it('refuses a queue connection that does not use the database driver', function (): void {
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);

    expect(fn () => plan(['production' => ['s' => ['connection' => 'redis']]]))
        ->toThrow(InvalidSupervisorConfiguration::class, 'must use the database driver; [redis] uses [redis]');
});

it('caps a supervisor on SQLite to one process and says so', function (): void {
    $plan = plan(['production' => [
        's' => ['connection' => 'database', 'queue' => ['default'], 'minProcesses' => 1, 'maxProcesses' => 4],
        // One process cannot be balanced between two pools: it takes both queues.
        'pooled' => ['connection' => 'database', 'queue' => ['default', 'media'], 'minProcesses' => 1, 'maxProcesses' => 4],
    ]]);

    $supervisors = $plan->forEnvironment('production');

    expect($supervisors['s']->maxProcesses)->toBe(1)
        ->and($supervisors['s']->balance)->toBe('auto')
        ->and($supervisors['pooled']->maxProcesses)->toBe(1)
        ->and($supervisors['pooled']->balance)->toBeFalse()
        ->and($plan->warnings('production'))->toBe([
            "[s] runs one process instead of 4: connection [database] is SQLite, which Laravel's database queue cannot pop from several processes at once.",
            "[pooled] runs one process instead of 4, on default,media without balancing: connection [database] is SQLite, which Laravel's database queue cannot pop from several processes at once.",
        ])
        ->and($plan->warnings('local'))->toBe([]);
});

it('reads the plan from the configuration file', function (): void {
    config()->set('belvedere.supervisor.defaults', ['supervisor-1' => ['connection' => 'server', 'queue' => ['default'], 'maxProcesses' => 1]]);
    config()->set('belvedere.supervisor.environments', ['testing' => ['supervisor-1' => ['maxProcesses' => 2]]]);

    $options = ProvisioningPlan::fromConfig(app(Repository::class), 'web-1-ab12', '/srv')->forEnvironment('testing')['supervisor-1'];

    expect($options->maxProcesses)->toBe(2)
        ->and($options->connection)->toBe('server')
        ->and($options->directory)->toBe('/srv');
});
