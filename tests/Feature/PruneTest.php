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

it('forgets the masters and supervisors not seen for the retention period, and the orders nobody read', function (): void {
    $storage = app(Storage::class);

    foreach ([['old', 2], ['live', 0]] as [$name, $daysAgo]) {
        $storage->query('masters')->insert([
            'name' => "web-{$name}", 'hostname' => 'web', 'pid' => 1, 'environment' => 'testing', 'status' => 'running', 'supervisors' => '[]',
            'started_at' => now()->subDays(3), 'last_seen_at' => now()->subDays($daysAgo),
        ]);
        $storage->query('supervisors')->insert([
            'name' => "web-{$name}:supervisor-1", 'master' => "web-{$name}", 'hostname' => 'web', 'pid' => 2, 'status' => 'running', 'processes' => '{}', 'options' => '{}',
            'started_at' => now()->subDays(3), 'last_seen_at' => now()->subDays($daysAgo),
        ]);
    }

    $storage->query('commands')->insert([
        ['target' => 'web-old', 'command' => 'pause', 'payload' => null, 'created_at' => now()->subHours(2)],
        ['target' => 'web-live', 'command' => 'pause', 'payload' => null, 'created_at' => now()],
    ]);

    Artisan::call('belvedere:prune');

    expect($storage->query('masters')->pluck('name')->all())->toBe(['web-live'])
        ->and($storage->query('supervisors')->pluck('name')->all())->toBe(['web-live:supervisor-1'])
        ->and($storage->query('commands')->pluck('target')->all())->toBe(['web-live'])
        ->and(Artisan::output())->toContain('Masters')->toContain('Supervisor commands');
});

it('clears every table', function (): void {
    TaggedJob::dispatch();
    $this->workQueue();

    app(Storage::class)->query('masters')->insert([
        'name' => 'web-abcd', 'hostname' => 'web', 'pid' => 1, 'environment' => 'testing', 'status' => 'running', 'supervisors' => '[]',
        'started_at' => now(), 'last_seen_at' => now(),
    ]);

    Artisan::call('belvedere:clear', ['--force' => true]);

    expect(Job::query()->count())->toBe(0)
        ->and(JobTag::query()->count())->toBe(0)
        ->and(app(Storage::class)->query('workers')->count())->toBe(0)
        ->and(app(Storage::class)->query('metrics')->count())->toBe(0)
        ->and(app(Storage::class)->query('masters')->count())->toBe(0);
});
