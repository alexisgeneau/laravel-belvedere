<?php

declare(strict_types=1);

use Belvedere\Supervision\CommandStrings;
use Belvedere\Supervision\PhpBinary;
use Belvedere\Supervision\SupervisorOptions;

it('round-trips through an array and derives a pool for one queue', function (): void {
    $options = SupervisorOptions::fromArray(['name' => 'web-1-ab12:supervisor-1', 'connection' => 'database', 'queue' => 'default,media', 'maxProcesses' => 6, 'balance' => 'auto']);

    expect(SupervisorOptions::fromArray($options->toArray())->toArray())->toBe($options->toArray())
        ->and($options->queues())->toBe(['default', 'media'])
        ->and($options->shortName())->toBe('supervisor-1')
        ->and($options->balancing())->toBeTrue()
        ->and($options->autoScaling())->toBeTrue()
        ->and($options->withQueue('media')->queue)->toBe('media')
        ->and($options->withQueue('media')->maxProcesses)->toBe(6);
});

it('reads the balance option the way the configuration spells it', function (): void {
    expect(SupervisorOptions::balanceFrom(false))->toBeFalse()
        ->and(SupervisorOptions::balanceFrom('false'))->toBeFalse()
        ->and(SupervisorOptions::balanceFrom('off'))->toBeFalse()
        ->and(SupervisorOptions::balanceFrom('simple'))->toBe('simple')
        ->and(SupervisorOptions::fromArray(['name' => 'm:s', 'connection' => 'database', 'balance' => 'simple'])->autoScaling())->toBeFalse()
        ->and(SupervisorOptions::fromArray(['name' => 'm:s', 'connection' => 'database', 'balance' => false])->balancing())->toBeFalse();
});

it('builds a plain queue:work command for the workers and a supervisor command for the master', function (): void {
    PhpBinary::use('/usr/bin/php');

    $options = SupervisorOptions::fromArray([
        'name' => 'web-1-ab12:supervisor-1',
        'connection' => 'database',
        'queue' => 'default',
        'memory' => 256,
        'timeout' => 90,
        'tries' => 3,
        'backoff' => '5,30',
        'maxTime' => 3600,
        'force' => true,
        'nice' => 5,
        'parentId' => 42,
    ]);

    $worker = CommandStrings::worker($options);
    $supervisor = CommandStrings::supervisor($options, paused: true);

    expect($worker)->toStartWith("exec /usr/bin/php 'artisan' queue:work 'database' --name='web-1-ab12:supervisor-1' --queue='default'")
        ->and($worker)->toContain("--backoff='5,30' --memory=256 --timeout=90 --tries=3 --sleep=3 --rest=0 --max-time=3600 --max-jobs=0 --force")
        ->and($supervisor)->toStartWith("exec /usr/bin/php 'artisan' belvedere:supervisor 'web-1-ab12:supervisor-1' 'database' --queue='default' --balance=auto")
        ->and($supervisor)->toContain('--min-processes=1 --max-processes=1 --balance-max-shift=1 --balance-cooldown=3 --memory=256 --tries=3 --timeout=90')
        ->and($supervisor)->toContain('--nice=5 --parent-id=42 --force --paused');

    PhpBinary::use(null);
});
