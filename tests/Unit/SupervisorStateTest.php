<?php

declare(strict_types=1);

use Belvedere\Supervision\SupervisorState;

it('lists the pools in the order of the queue list, whatever order the database kept the keys in', function (): void {
    // MySQL stores the keys of a JSON object by length, then by name.
    $state = SupervisorState::fromRow((object) [
        'name' => 'web-1-abcd:supervisor-1', 'master' => 'web-1-abcd', 'hostname' => 'web-1', 'pid' => 777, 'status' => 'running',
        'processes' => '{"database:media":1,"database:default":2,"database:mail":0}',
        'options' => '{"queue":"default, media","maxProcesses":4}',
        'started_at' => '2026-10-22 10:00:00', 'last_seen_at' => '2026-10-22 10:00:05',
    ]);

    expect($state->processes)->toBe(['database:default' => 2, 'database:media' => 1, 'database:mail' => 0])
        ->and($state->totalProcesses())->toBe(3)
        ->and($state->shortName())->toBe('supervisor-1')
        ->and($state->maxProcesses())->toBe(4);
});
