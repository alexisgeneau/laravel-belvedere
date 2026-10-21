<?php

declare(strict_types=1);

namespace Belvedere\Alerts;

use Belvedere\Models\Worker;
use Belvedere\Notifications\FailureRateExceeded;
use Belvedere\Notifications\LongWait;
use Belvedere\Notifications\QueueBacklog;
use Belvedere\Notifications\WorkerDied;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\QueuePauser;
use Belvedere\Support\Storage;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class AlertEvaluator
{
    public function __construct(
        private NativeQueue $native,
        private QueuePauser $pauser,
        private Storage $storage,
        private Cache $cache,
        private Config $config,
    ) {}

    /** @return Collection<int, Alert> */
    public function evaluate(): Collection
    {
        $alerts = collect([...$this->backlogs(), ...$this->waits(), ...$this->failureRates(), ...$this->deadWorkers()]);

        $alerts->each(fn (Alert $alert) => $this->notify($alert));

        return $alerts;
    }

    public function queueBusy(string $connection, string $queue, int $size): void
    {
        $this->notify($this->backlogAlert($connection, $queue, $size, $size));
    }

    /** @return list<Alert> */
    private function backlogs(): array
    {
        $threshold = (int) $this->config->get('belvedere.alerts.queue_size', 0);

        if ($threshold <= 0) {
            return [];
        }

        $alerts = [];

        foreach ($this->native->connections() as $connection) {
            foreach ($this->native->counts($connection) as $counts) {
                if ($counts->backlog() >= $threshold) {
                    $alerts[] = $this->backlogAlert($connection, $counts->queue, $counts->backlog(), $threshold);
                }
            }
        }

        return $alerts;
    }

    /**
     * Queues whose oldest job ready to run has waited longer than allowed.
     * Paused queues are skipped: their backlog is expected.
     *
     * @return list<Alert>
     */
    private function waits(): array
    {
        $default = max(0, (int) $this->config->get('belvedere.alerts.wait_seconds', 0));
        $perQueue = (array) $this->config->get('belvedere.alerts.wait_seconds_per_queue', []);

        if ($default <= 0 && array_filter($perQueue) === []) {
            return [];
        }

        $alerts = [];

        foreach ($this->native->connections() as $connection) {
            foreach ($this->native->oldestWaits($connection) as $queue => $seconds) {
                $threshold = max(0, (int) ($perQueue["{$connection}:{$queue}"] ?? $default));

                if ($threshold <= 0 || $seconds < $threshold || $this->pauser->isPaused($connection, $queue)) {
                    continue;
                }

                $alerts[] = new Alert(
                    "wait:{$connection}:{$queue}",
                    "The oldest job on queue [{$queue}] ({$connection}) has been waiting for ".LongWait::duration($seconds).' (threshold '.LongWait::duration($threshold).').',
                    new LongWait($connection, $queue, $seconds, $threshold),
                );
            }
        }

        return $alerts;
    }

    /** @return list<Alert> */
    private function failureRates(): array
    {
        $threshold = (float) $this->config->get('belvedere.alerts.failure_rate_percent', 0);
        $minimum = (int) $this->config->get('belvedere.alerts.failure_rate_minimum_jobs', 20);
        $window = (int) $this->config->get('belvedere.alerts.window_minutes', 15);

        if ($threshold <= 0) {
            return [];
        }

        $rows = $this->storage->query('metrics')
            ->selectRaw('connection, subject, sum(processed) as processed, sum(failed) as failed')
            ->where('subject_type', 'queue')
            ->where('bucket', '>=', Carbon::now()->subMinutes($window))
            ->groupBy('connection', 'subject')
            ->get();

        $alerts = [];

        foreach ($rows as $row) {
            $total = (int) $row->processed + (int) $row->failed;

            if ($total < $minimum) {
                continue;
            }

            $rate = round((int) $row->failed / $total * 100, 1);

            if ($rate >= $threshold) {
                $alerts[] = new Alert(
                    "failure-rate:{$row->connection}:{$row->subject}",
                    "Failure rate on queue [{$row->subject}] ({$row->connection}) is {$rate}% over the last {$window} minutes.",
                    new FailureRateExceeded((string) $row->connection, (string) $row->subject, $rate, $window, $total),
                );
            }
        }

        return $alerts;
    }

    /** @return list<Alert> */
    private function deadWorkers(): array
    {
        if (! $this->config->get('belvedere.alerts.dead_workers', true)) {
            return [];
        }

        $deadAfter = (int) $this->config->get('belvedere.workers.dead_after_seconds', 90);

        return Worker::query()
            ->dead($deadAfter)
            ->get()
            ->map(static fn (Worker $worker): Alert => new Alert(
                "worker-dead:{$worker->id}",
                "Worker [{$worker->id}] on connection [{$worker->connection}] has not reported since {$worker->last_seen_at->toDateTimeString()}.",
                new WorkerDied($worker->id, $worker->connection, $worker->queues, $worker->last_seen_at),
            ))
            ->all();
    }

    private function backlogAlert(string $connection, string $queue, int $size, int $threshold): Alert
    {
        return new Alert(
            "backlog:{$connection}:{$queue}",
            "Queue [{$queue}] on connection [{$connection}] has {$size} jobs waiting (threshold {$threshold}).",
            new QueueBacklog($connection, $queue, $size, $threshold),
        );
    }

    private function notify(Alert $alert): void
    {
        $routes = array_filter((array) $this->config->get('belvedere.alerts.routes', []));

        if ($routes === []) {
            return;
        }

        $key = 'belvedere:alert:'.$alert->fingerprint;
        $cooldown = max(1, (int) $this->config->get('belvedere.alerts.cooldown_minutes', 30));

        if (! $this->cache->add($key, true, Carbon::now()->addMinutes($cooldown))) {
            return;
        }

        $notifiable = new AnonymousNotifiable;

        foreach ($routes as $channel => $route) {
            $notifiable->route((string) $channel, $route);
        }

        $notifiable->notify($alert->notification);
    }
}
