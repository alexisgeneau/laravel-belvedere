<?php

declare(strict_types=1);

use Belvedere\Belvedere;
use Belvedere\Models\Job;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\NativeState;
use Belvedere\Tests\Fixtures\Jobs\TaggedJob;
use Illuminate\Support\Facades\DB;

it('collects nothing while disabled but keeps the dashboard available', function (): void {
    TaggedJob::dispatch();

    $pending = app(NativeQueue::class)->jobs('database', NativeState::Pending, null, 10, null)->first();

    expect($pending->tags)->toBe([])
        ->and(Job::query()->count())->toBe(0);

    $this->workQueue();

    expect(Job::query()->count())->toBe(0)
        ->and(DB::table('belvedere_metrics')->count())->toBe(0)
        ->and(DB::table('belvedere_workers')->count())->toBe(0);

    Belvedere::auth(static fn (): bool => true);

    $this->getJson('/belvedere/api/overview')->assertOk()->assertJsonPath('queues.0.queue', 'default');
    $this->get('/belvedere')->assertOk();
});
