<?php

declare(strict_types=1);

use Belvedere\Alerts\AlertEvaluator;
use Belvedere\Notifications\FailureRateExceeded;
use Belvedere\Notifications\LongWait;
use Belvedere\Notifications\OrphanWorkers;
use Belvedere\Notifications\QueueBacklog;
use Belvedere\Notifications\SupervisorDown;
use Belvedere\Notifications\WorkerDied;
use Belvedere\Queues\JobActions;
use Belvedere\Support\Storage;
use Belvedere\Tests\Fixtures\Jobs\FailingJob;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    config()->set('belvedere.alerts.routes', ['mail' => 'ops@example.com']);
    config()->set('belvedere.alerts.queue_size', 2);
    config()->set('belvedere.alerts.failure_rate_minimum_jobs', 2);
});

it('notifies a queue backlog once per cooldown period', function (): void {
    SucceedingJob::dispatch();
    SucceedingJob::dispatch();

    $alerts = app(AlertEvaluator::class)->evaluate();

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->fingerprint)->toBe('backlog:database:default');

    app(AlertEvaluator::class)->evaluate();

    Notification::assertSentOnDemandTimes(QueueBacklog::class, 1);
});

it('notifies when the failure rate exceeds the threshold', function (): void {
    $this->freezeTime();

    FailingJob::dispatch();
    SucceedingJob::dispatch();

    $this->workQueue();

    Artisan::call('belvedere:check');

    Notification::assertSentOnDemand(FailureRateExceeded::class, function (FailureRateExceeded $notification): bool {
        return $notification->queue === 'default' && $notification->rate === 50.0;
    });
});

it('notifies when the oldest job ready to run has waited too long', function (): void {
    $this->freezeTime();

    config()->set('belvedere.alerts.queue_size', 0);

    SucceedingJob::dispatch();
    SucceedingJob::dispatch()->onQueue('reports');
    SucceedingJob::dispatch()->onQueue('later')->delay(3600);

    expect(app(AlertEvaluator::class)->evaluate())->toHaveCount(0);

    $this->travel(2)->minutes();

    $alerts = app(AlertEvaluator::class)->evaluate();

    expect($alerts->pluck('fingerprint')->all())->toBe(['wait:database:default', 'wait:database:reports'])
        ->and($alerts->first()->message)->toBe('The oldest job on queue [default] (database) has been waiting for 2 minutes (threshold 1 minute).');

    Notification::assertSentOnDemand(LongWait::class, function (LongWait $notification): bool {
        return $notification->queue === 'default' && $notification->waitSeconds >= 120 && $notification->threshold === 60;
    });
});

it('applies per-queue wait thresholds and skips paused queues', function (): void {
    config()->set('belvedere.alerts.queue_size', 0);
    config()->set('belvedere.alerts.wait_seconds_per_queue', ['database:reports' => 0, 'database:exports' => 600]);

    SucceedingJob::dispatch();
    SucceedingJob::dispatch()->onQueue('reports');
    SucceedingJob::dispatch()->onQueue('exports');
    SucceedingJob::dispatch()->onQueue('paused');
    app(JobActions::class)->pause('database', 'paused');

    $this->travel(2)->minutes();

    expect(app(AlertEvaluator::class)->evaluate()->pluck('fingerprint')->all())->toBe(['wait:database:default']);

    $this->travel(10)->minutes();

    expect(app(AlertEvaluator::class)->evaluate()->pluck('fingerprint')->all())->toBe(['wait:database:default', 'wait:database:exports']);
});

it('notifies dead workers', function (): void {
    Event::until(new Looping('database', 'default'));

    $this->travel(5)->minutes();

    app(AlertEvaluator::class)->evaluate();

    Notification::assertSentOnDemand(WorkerDied::class);
});

it('sends nothing when no route is configured', function (): void {
    config()->set('belvedere.alerts.routes', []);

    SucceedingJob::dispatch();
    SucceedingJob::dispatch();

    expect(app(AlertEvaluator::class)->evaluate())->toHaveCount(1);

    Notification::assertNothingSent();
});

it('says when the pool serving a slow queue is already at its maximum', function (): void {
    $this->freezeTime();
    config()->set('belvedere.alerts.queue_size', 0);

    SucceedingJob::dispatch();
    $this->travel(2)->minutes();

    app(Storage::class)->query('supervisors')->insert([
        'name' => 'web-1-abcd:supervisor-1',
        'master' => 'web-1-abcd',
        'hostname' => 'web-1',
        'pid' => 777,
        'status' => 'running',
        'processes' => json_encode(['database:default' => 3]),
        'options' => json_encode(['queue' => 'default', 'maxProcesses' => 3, 'timeout' => 60]),
        'started_at' => Carbon::now(),
        'last_seen_at' => Carbon::now(),
    ]);

    $alert = app(AlertEvaluator::class)->evaluate()->sole();

    expect($alert->message)->toEndWith('The supervisor pool is already at its maximum of 3 processes.');

    Notification::assertSentOnDemand(LongWait::class, static fn (LongWait $notification): bool => $notification->poolMaximum === 3);
});

it('notifies a master gone silent with nobody taking over on its host, and the workers a dead supervisor left', function (): void {
    config()->set('belvedere.alerts.queue_size', 0);

    foreach ([['web-1-dead', 'web-1', 120], ['web-1-new', 'web-1', 0], ['db-1-dead', 'db-1', 120]] as [$name, $host, $age]) {
        app(Storage::class)->query('masters')->insert([
            'name' => $name,
            'hostname' => $host,
            'pid' => 1,
            'environment' => 'testing',
            'status' => 'running',
            'supervisors' => '[]',
            'started_at' => Carbon::now()->subHour(),
            'last_seen_at' => Carbon::now()->subSeconds($age),
        ]);
    }

    // web-1-new:reports was started a minute ago: worker 13 is from the one it replaced, 14 is its own.
    app(Storage::class)->query('supervisors')->insert([
        'name' => 'web-1-new:reports',
        'master' => 'web-1-new',
        'hostname' => 'web-1',
        'pid' => 778,
        'status' => 'running',
        'processes' => json_encode(['database:reports' => 1]),
        'options' => json_encode(['queue' => 'reports', 'maxProcesses' => 1, 'timeout' => 10]),
        'started_at' => Carbon::now()->subMinute(),
        'last_seen_at' => Carbon::now(),
    ]);

    app(Storage::class)->query('workers')->insert([
        ['id' => 'db-1:10', 'hostname' => 'db-1', 'pid' => 10, 'connection' => 'database', 'queues' => 'default', 'supervisor' => 'db-1-dead:supervisor-1', 'status' => 'running', 'jobs_processed' => 0, 'started_at' => Carbon::now(), 'last_seen_at' => Carbon::now()],
        ['id' => 'db-1:11', 'hostname' => 'db-1', 'pid' => 11, 'connection' => 'database', 'queues' => 'default', 'supervisor' => 'db-1-dead:supervisor-1', 'status' => 'running', 'jobs_processed' => 0, 'started_at' => Carbon::now(), 'last_seen_at' => Carbon::now()],
        ['id' => 'web-1:12', 'hostname' => 'web-1', 'pid' => 12, 'connection' => 'database', 'queues' => 'default', 'supervisor' => null, 'status' => 'running', 'jobs_processed' => 0, 'started_at' => Carbon::now(), 'last_seen_at' => Carbon::now()],
        ['id' => 'web-1:13', 'hostname' => 'web-1', 'pid' => 13, 'connection' => 'database', 'queues' => 'reports', 'supervisor' => 'web-1-new:reports', 'status' => 'running', 'jobs_processed' => 0, 'started_at' => Carbon::now()->subHour(), 'last_seen_at' => Carbon::now()],
        ['id' => 'web-1:14', 'hostname' => 'web-1', 'pid' => 14, 'connection' => 'database', 'queues' => 'reports', 'supervisor' => 'web-1-new:reports', 'status' => 'running', 'jobs_processed' => 0, 'started_at' => Carbon::now()->subMinute(), 'last_seen_at' => Carbon::now()],
    ]);

    $alerts = app(AlertEvaluator::class)->evaluate();

    expect($alerts->pluck('fingerprint')->all())->toBe(['supervisor-down:db-1', 'orphans:db-1', 'orphans:web-1'])
        ->and($alerts[1]->message)->toBe('2 orphan workers on [db-1]: run belvedere:orphans --kill there.')
        ->and($alerts[2]->message)->toBe('1 orphan worker on [web-1]: run belvedere:orphans --kill there.');

    Notification::assertSentOnDemandTimes(SupervisorDown::class, 1);
    Notification::assertSentOnDemand(OrphanWorkers::class, static fn (OrphanWorkers $notification): bool => $notification->hostname === 'db-1' && $notification->count === 2);
    Notification::assertSentOnDemand(OrphanWorkers::class, static fn (OrphanWorkers $notification): bool => $notification->hostname === 'web-1' && $notification->count === 1);

    config()->set('belvedere.alerts.supervisor', false);

    expect(app(AlertEvaluator::class)->evaluate())->toHaveCount(0);
});
