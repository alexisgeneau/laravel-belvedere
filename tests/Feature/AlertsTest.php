<?php

declare(strict_types=1);

use Belvedere\Alerts\AlertEvaluator;
use Belvedere\Notifications\FailureRateExceeded;
use Belvedere\Notifications\LongWait;
use Belvedere\Notifications\QueueBacklog;
use Belvedere\Notifications\WorkerDied;
use Belvedere\Queues\JobActions;
use Belvedere\Tests\Fixtures\Jobs\FailingJob;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Illuminate\Queue\Events\Looping;
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
