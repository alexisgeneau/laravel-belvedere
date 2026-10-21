<?php

declare(strict_types=1);

use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\NativeState;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('counts pending, delayed, running and stuck jobs per queue', function (): void {
    SucceedingJob::dispatch();
    SucceedingJob::dispatch()->onQueue('mail');
    SucceedingJob::dispatch()->delay(600);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{"uuid":"stuck","displayName":"Stuck","attempts":1}',
        'attempts' => 1,
        'reserved_at' => time() - 3600,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    $counts = app(NativeQueue::class)->counts('database')->keyBy('queue');

    expect($counts['default']->pending)->toBe(1)
        ->and($counts['default']->delayed)->toBe(1)
        ->and($counts['default']->stuck)->toBe(1)
        ->and($counts['default']->running)->toBe(0)
        ->and($counts['mail']->pending)->toBe(1);
});

it('lists jobs by state with their decoded payload', function (): void {
    SucceedingJob::dispatch('first');
    SucceedingJob::dispatch('second')->delay(600);

    $native = app(NativeQueue::class);

    $pending = $native->jobs('database', NativeState::Pending, null, 50, null);
    $delayed = $native->jobs('database', NativeState::Delayed, null, 50, null);

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->name)->toBe(SucceedingJob::class)
        ->and($pending->first()->uuid)->not->toBeNull()
        ->and($pending->first()->state)->toBe(NativeState::Pending)
        ->and($delayed)->toHaveCount(1)
        ->and($delayed->first()->state)->toBe(NativeState::Delayed);
});

it('deletes, reschedules, releases and purges native jobs', function (): void {
    SucceedingJob::dispatch();
    SucceedingJob::dispatch()->delay(600);
    SucceedingJob::dispatch();

    $native = app(NativeQueue::class);
    [$first, $delayed, $third] = DB::table('jobs')->orderBy('id')->pluck('id')->all();

    expect($native->runNow('database', $delayed))->toBeTrue()
        ->and($native->counts('database')->firstWhere('queue', 'default')->pending)->toBe(3)
        ->and($native->delete('database', $first))->toBeTrue()
        ->and($native->delete('database', $first))->toBeFalse();

    DB::table('jobs')->where('id', $third)->update(['reserved_at' => time() - 3600]);

    expect($native->release('database', $third))->toBeTrue()
        ->and(DB::table('jobs')->where('id', $third)->value('reserved_at'))->toBeNull()
        ->and($native->purge('database', 'default'))->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('collects the uuids of a queue in chunks', function (): void {
    $rows = [];

    for ($i = 1; $i <= 1203; $i++) {
        $rows[] = [
            'queue' => $i % 2 === 0 ? 'default' : 'mail',
            'payload' => sprintf('{"uuid":"%s","displayName":"Job"}', Str::uuid()),
            'attempts' => 0,
            'reserved_at' => $i % 100 === 0 ? time() : null,
            'available_at' => time(),
            'created_at' => time(),
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('jobs')->insert($chunk);
    }

    $native = app(NativeQueue::class);

    expect($native->uuids('database', 'default'))->toHaveCount(601)
        ->and($native->uuids('database', 'default', unreservedOnly: true))->toHaveCount(589)
        ->and($native->uuids('database', 'mail'))->toHaveCount(602)
        ->and($native->uuids('database', 'reports'))->toBe([]);
});

it('rejects inspection of connections that do not use the database driver', function (): void {
    config()->set('queue.connections.sync', ['driver' => 'sync']);

    app(NativeQueue::class)->jobs('sync', NativeState::Pending, null, 10, null);
})->throws(InvalidArgumentException::class);
