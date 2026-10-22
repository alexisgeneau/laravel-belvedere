<?php

declare(strict_types=1);

use Belvedere\Models\Job;
use Belvedere\Models\JobTag;
use Belvedere\Models\Worker;
use Belvedere\Support\Storage;
use Belvedere\Tests\Fixtures\Jobs\FailingJob;
use Belvedere\Tests\Fixtures\Jobs\TaggedJob;
use Illuminate\Support\Facades\Artisan;

it('deletes records older than the retention periods and keeps the rest', function (): void {
    TaggedJob::dispatch();
    FailingJob::dispatch();

    $this->workQueue();

    $this->travel(8)->days();

    TaggedJob::dispatch();
    $this->workQueue();

    Artisan::call('belvedere:prune');

    expect(Job::query()->where('name', TaggedJob::class)->count())->toBe(1)
        ->and(Job::query()->where('name', FailingJob::class)->count())->toBe(1)
        ->and(JobTag::query()->count())->toBe(2)
        ->and(app(Storage::class)->query('metrics')->count())->toBe(2);

    Artisan::call('belvedere:prune', ['--hours' => 1]);

    expect(Job::query()->count())->toBe(1)
        ->and(JobTag::query()->count())->toBe(2)
        ->and(app(Storage::class)->query('workers')->count())->toBe(1);
});

it('forgets the workers not seen for the retention period whatever their last status', function (): void {
    foreach ([['running', 2], ['paused', 2], ['stopped', 2], ['running', 0]] as [$status, $daysAgo]) {
        Worker::query()->create([
            'id' => "{$status}:{$daysAgo}", 'hostname' => 'web', 'pid' => $daysAgo, 'connection' => 'database', 'queues' => 'default',
            'status' => $status, 'started_at' => now()->subDays(3), 'last_seen_at' => now()->subDays($daysAgo),
        ]);
    }

    Artisan::call('belvedere:prune');

    expect(Worker::query()->pluck('id')->all())->toBe(['running:0']);
});

it('clears every table', function (): void {
    TaggedJob::dispatch();
    $this->workQueue();

    Artisan::call('belvedere:clear', ['--force' => true]);

    expect(Job::query()->count())->toBe(0)
        ->and(JobTag::query()->count())->toBe(0)
        ->and(app(Storage::class)->query('workers')->count())->toBe(0)
        ->and(app(Storage::class)->query('metrics')->count())->toBe(0);
});
