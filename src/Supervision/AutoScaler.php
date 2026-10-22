<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Illuminate\Support\Collection;

/**
 * Horizon's AutoScaler: gives every pool of a supervisor a share of maxProcesses
 * proportional to the load of its queue, moving at most balanceMaxShift
 * processes per call.
 */
final readonly class AutoScaler
{
    public function __construct(private QueueLoad $load) {}

    /**
     * The pools that shrink go first, then the ones that grow, the busiest first: when
     * every pool rounds up to one more process than it has, the last free slot must
     * go to the queue with the most work, not to the first pool in line.
     */
    public function scale(Supervisor $supervisor): void
    {
        $pools = $supervisor->processPools->keyBy(static fn (ProcessPool $pool): string => $pool->queue());

        $workers = $this->numberOfWorkersPerQueue($supervisor, $this->timeToClearPerQueue($supervisor, $pools))
            ->filter(static fn (float $count, string $queue): bool => $pools->has($queue));

        [$growing, $shrinking] = $workers->partition(static fn (float $count, string $queue): bool => (int) ceil($count) > $pools[$queue]->totalProcessCount());

        foreach ($shrinking->sort()->keys()->merge($growing->sortDesc()->keys()) as $queue) {
            $this->scalePool($supervisor, $pools[$queue], $workers[$queue]);
        }
    }

    /**
     * @param  Collection<string, ProcessPool>  $pools
     * @return Collection<string, array{size: int, time: float}> milliseconds needed to clear each pool's queues
     */
    private function timeToClearPerQueue(Supervisor $supervisor, Collection $pools): Collection
    {
        $connection = $supervisor->options->connection;
        $queues = $pools->keys()->flatMap(static fn (string $queue): array => explode(',', $queue))->unique()->values()->all();

        $ready = $this->load->ready($connection, $queues);
        $runtimes = $supervisor->options->autoScalesBySize() ? [] : $this->load->runtimes($connection, $queues);

        return $pools->map(static function (ProcessPool $pool, string $queue) use ($ready, $runtimes): array {
            $size = 0;
            $time = 0.0;

            foreach (explode(',', $queue) as $name) {
                $size += $ready[$name] ?? 0;
                $time += ($ready[$name] ?? 0) * ($runtimes[$name] ?? 0.0);
            }

            return ['size' => $size, 'time' => $time];
        });
    }

    /**
     * @param  Collection<string, array{size: int, time: float}>  $queues
     * @return Collection<string, float>
     */
    private function numberOfWorkersPerQueue(Supervisor $supervisor, Collection $queues): Collection
    {
        $options = $supervisor->options;
        $timeToClearAll = $queues->sum('time');
        $totalJobs = $queues->sum('size');

        return $queues->map(static function (array $queue) use ($supervisor, $options, $timeToClearAll, $totalJobs): float {
            if (! $options->balancing()) {
                return (float) min($options->maxProcesses, max($options->minProcesses, $queue['size']));
            }

            if ($options->autoScaling()) {
                $total = $options->autoScalesBySize() ? $totalJobs : $timeToClearAll;

                if ($total > 0) {
                    return ($options->autoScalesBySize() ? $queue['size'] : $queue['time']) / $total * $options->maxProcesses;
                }

                // Nothing to clear, or no runtime known yet: the queues with jobs get everything.
                return (float) ($queue['size'] > 0 ? $options->maxProcesses : $options->minProcesses);
            }

            return $options->maxProcesses / max(1, $supervisor->processPools->count());
        })->sort();
    }

    private function scalePool(Supervisor $supervisor, ProcessPool $pool, float $workers): void
    {
        $supervisor->pruneTerminatingProcesses();

        $options = $supervisor->options;
        $current = $pool->totalProcessCount();
        $desired = max((int) ceil($workers), $options->minProcesses);

        if ($desired > $current) {
            $maxUpShift = min(max(0, $options->maxProcesses - $supervisor->totalProcessCount()), $options->balanceMaxShift);
            $othersMinimum = ($supervisor->processPools->count() - 1) * $options->minProcesses;

            $pool->scale((int) min(
                $current + $maxUpShift,
                max($options->minProcesses, $options->maxProcesses - $othersMinimum),
                $desired,
            ));
        } elseif ($desired < $current) {
            $maxDownShift = min($supervisor->totalProcessCount() - $options->minProcesses, $options->balanceMaxShift);

            $pool->scale((int) max($current - $maxDownShift, $options->minProcesses, $desired));
        }
    }
}
