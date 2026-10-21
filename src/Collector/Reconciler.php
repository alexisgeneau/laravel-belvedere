<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Enums\JobStatus;
use Belvedere\Enums\WorkerStatus;
use Belvedere\Models\Job;
use Belvedere\Models\Worker;
use Belvedere\Queues\NativeQueue;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Marks as lost the executions whose outcome will never be reported.
 *
 * A row stays "running" forever when the worker is killed before it can
 * report, and "queued" forever when the job leaves the queue table without
 * being processed. The reconciler runs from `belvedere:check`.
 */
final readonly class Reconciler
{
    private const CHUNK = 1000;

    /** Seconds a queued execution must be old before its absence from the queue table counts. */
    public const QUEUED_GRACE_SECONDS = 120;

    public function __construct(
        private NativeQueue $native,
        private Config $config,
    ) {}

    /** @return array<string, int> Number of executions marked as lost, by cause. */
    public function run(): array
    {
        return [
            'superseded attempts' => $this->superseded(),
            'dead workers' => $this->deadWorkers(),
            'expired reservations' => $this->expiredReservations(),
            'jobs missing from the queue table' => $this->vanished(),
        ];
    }

    /**
     * Mark the matching queued or running executions as lost.
     *
     * @param  Builder<Job>  $jobs
     */
    public function markLost(Builder $jobs, string $reason): int
    {
        $total = 0;
        $now = Carbon::now()->format(Job::DATE_FORMAT);

        do {
            $ids = (clone $jobs)
                ->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $total += Job::query()->whereIn('id', $ids)->update([
                'status' => JobStatus::Lost->value,
                'finished_at' => $now,
                'exception' => $reason,
            ]);
        } while (count($ids) === self::CHUNK);

        return $total;
    }

    /** A running attempt followed by a later attempt of the same job never finished. */
    private function superseded(): int
    {
        $table = (new Job)->getTable();
        $total = 0;

        $rows = Job::query()
            ->select(['id'])
            ->selectSub(
                Job::query()->getQuery()
                    ->from($table.' as later')
                    ->selectRaw('min(later.attempt)')
                    ->whereColumn('later.uuid', $table.'.uuid')
                    ->whereColumn('later.attempt', '>', $table.'.attempt'),
                'next_attempt',
            )
            ->where('status', JobStatus::Running->value)
            ->whereExists(fn (QueryBuilder $query): QueryBuilder => $query
                ->from($table.' as later')
                ->whereColumn('later.uuid', $table.'.uuid')
                ->whereColumn('later.attempt', '>', $table.'.attempt'))
            ->get();

        foreach ($rows as $row) {
            $next = (int) $row->getAttribute('next_attempt');

            $total += $this->markLost(
                Job::query()->whereKey($row->id),
                "Superseded by attempt {$next}: the worker never reported the outcome of this attempt.",
            );
        }

        return $total;
    }

    /** Running executions whose worker stopped or stopped responding. */
    private function deadWorkers(): int
    {
        $deadAfter = (int) $this->config->get('belvedere.workers.dead_after_seconds', 90);
        $total = 0;

        $workers = Worker::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('status', WorkerStatus::Stopped->value)
                ->orWhere('last_seen_at', '<', Carbon::now()->subSeconds($deadAfter)))
            ->whereIn('id', Job::query()->select('worker_id')->where('status', JobStatus::Running->value))
            ->get();

        foreach ($workers as $worker) {
            $reason = $worker->status === WorkerStatus::Stopped
                ? "Worker [{$worker->id}] stopped without reporting the outcome of this job."
                : "Worker [{$worker->id}] stopped responding while running this job.";

            $total += $this->markLost(
                Job::query()->where('status', JobStatus::Running->value)->where('worker_id', $worker->id),
                $reason,
            );
        }

        return $total;
    }

    /**
     * Running executions older than the connection's retry_after: the queue
     * has released the job by now, so no worker still holds it. A worker that
     * does finish late overrides this status when it reports.
     */
    private function expiredReservations(): int
    {
        $total = 0;

        $connections = Job::query()->where('status', JobStatus::Running->value)->distinct()->pluck('connection');

        foreach ($connections as $connection) {
            $retryAfter = $this->native->retryAfter($connection);

            $total += $this->markLost(
                Job::query()
                    ->where('status', JobStatus::Running->value)
                    ->where('connection', $connection)
                    ->where('started_at', '<', Carbon::now()->subSeconds($retryAfter)),
                "No outcome was reported within the connection's retry_after ({$retryAfter} s): the worker was probably killed.",
            );
        }

        return $total;
    }

    /**
     * Queued executions whose job is no longer in the queue table (queue
     * cleared, or job deleted outside the dashboard).
     *
     * Reading the uuids of a whole queue costs a scan of its rows, so a
     * queue is only compared when it holds more queued executions than
     * jobs: with every job recorded, that is a sure sign of orphans.
     */
    private function vanished(): int
    {
        $total = 0;

        foreach ($this->native->connections() as $connection) {
            if (! $this->native->supports($connection)) {
                continue;
            }

            $queued = Job::query()
                ->selectRaw('queue, count(*) as queued_executions')
                ->where('connection', $connection)
                ->where('status', JobStatus::Queued->value)
                ->groupBy('queue')
                ->pluck('queued_executions', 'queue');

            if ($queued->isEmpty()) {
                continue;
            }

            $counts = $this->native->counts($connection)->keyBy('queue');

            foreach ($queued as $queue => $executions) {
                $backlog = $counts->get($queue)?->backlog() ?? 0;

                if ((int) $executions > $backlog) {
                    $total += $this->vanishedFromQueue($connection, (string) $queue);
                }
            }
        }

        return $total;
    }

    private function vanishedFromQueue(string $connection, string $queue): int
    {
        $present = $this->native->uuids($connection, $queue);
        $table = (string) $this->config->get("queue.connections.{$connection}.table", 'jobs');
        $total = 0;

        Job::query()
            ->select(['id', 'uuid'])
            ->where('connection', $connection)
            ->where('queue', $queue)
            ->where('status', JobStatus::Queued->value)
            ->where('queued_at', '<', Carbon::now()->subSeconds(self::QUEUED_GRACE_SECONDS))
            ->chunkById(self::CHUNK, function (Collection $rows) use ($present, $table, &$total): void {
                $ids = $rows->reject(fn (Job $job): bool => isset($present[$job->uuid]))->modelKeys();

                if ($ids !== []) {
                    $total += $this->markLost(
                        Job::query()->whereIn('id', $ids),
                        "The job left the [{$table}] table without being processed: the queue was cleared, or the job was deleted outside the dashboard.",
                    );
                }
            });

        return $total;
    }
}
