<?php

declare(strict_types=1);

namespace Belvedere\Metrics;

use Belvedere\Enums\MetricSubject;
use Belvedere\Support\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use stdClass;

final readonly class MetricsReader
{
    /** @var array<string, array{seconds: int, step: int}> */
    private const RANGES = [
        '1h' => ['seconds' => 3600, 'step' => 60],
        '24h' => ['seconds' => 86400, 'step' => 900],
        '7d' => ['seconds' => 604800, 'step' => 3600],
    ];

    public function __construct(private Storage $storage) {}

    /** @return Collection<int|string, stdClass> */
    public function queueTotals(string $connection, Carbon $since): Collection
    {
        return $this->totals($connection, MetricSubject::Queue, $since);
    }

    /** @return Collection<int|string, stdClass> */
    public function jobTotals(string $connection, Carbon $since): Collection
    {
        return $this->totals($connection, MetricSubject::Job, $since);
    }

    /** @return Collection<int|string, stdClass> */
    public function totals(string $connection, MetricSubject $type, Carbon $since): Collection
    {
        return $this->storage->query('metrics')
            ->selectRaw(
                'subject,'
                .' sum(processed) as processed,'
                .' sum(failed) as failed,'
                .' sum(released) as released,'
                .' sum(processed) + sum(failed) + sum(released) as total,'
                .' sum(wait_ms_sum) as wait_ms_sum,'
                .' sum(run_ms_sum) as run_ms_sum,'
                .' max(wait_ms_max) as wait_ms_max,'
                .' max(run_ms_max) as run_ms_max'
            )
            ->where('connection', $connection)
            ->where('subject_type', $type->value)
            ->where('bucket', '>=', $since)
            ->groupBy('subject')
            ->get()
            ->keyBy('subject');
    }

    /** @return list<array<string, mixed>> */
    public function series(string $connection, MetricSubject $type, string $subject, string $range): array
    {
        $definition = self::RANGES[$range] ?? throw new InvalidArgumentException("Unknown metrics range [{$range}].");
        $step = $definition['step'];
        $start = (int) (floor((Carbon::now()->getTimestamp() - $definition['seconds']) / $step) * $step);

        $rows = $this->storage->query('metrics')
            ->where('connection', $connection)
            ->where('subject_type', $type->value)
            ->where('subject', $subject)
            ->where('bucket', '>=', Carbon::createFromTimestamp($start))
            ->get(['bucket', 'processed', 'failed', 'released', 'wait_ms_sum', 'run_ms_sum', 'run_ms_max']);

        $buckets = [];

        for ($time = $start; $time <= Carbon::now()->getTimestamp(); $time += $step) {
            $buckets[$time] = ['processed' => 0, 'failed' => 0, 'released' => 0, 'wait_ms_sum' => 0, 'run_ms_sum' => 0, 'run_ms_max' => 0];
        }

        foreach ($rows as $row) {
            $time = (int) (floor((int) strtotime((string) $row->bucket) / $step) * $step);

            if (! isset($buckets[$time])) {
                continue;
            }

            $buckets[$time]['processed'] += (int) $row->processed;
            $buckets[$time]['failed'] += (int) $row->failed;
            $buckets[$time]['released'] += (int) $row->released;
            $buckets[$time]['wait_ms_sum'] += (int) $row->wait_ms_sum;
            $buckets[$time]['run_ms_sum'] += (int) $row->run_ms_sum;
            $buckets[$time]['run_ms_max'] = max($buckets[$time]['run_ms_max'], (int) $row->run_ms_max);
        }

        $series = [];

        foreach ($buckets as $time => $bucket) {
            $total = $bucket['processed'] + $bucket['failed'] + $bucket['released'];

            $series[] = [
                'time' => Carbon::createFromTimestamp($time)->toIso8601String(),
                'processed' => $bucket['processed'],
                'failed' => $bucket['failed'],
                'released' => $bucket['released'],
                'wait_ms_avg' => $total > 0 ? (int) round($bucket['wait_ms_sum'] / $total) : null,
                'run_ms_avg' => $total > 0 ? (int) round($bucket['run_ms_sum'] / $total) : null,
                'run_ms_max' => $total > 0 ? $bucket['run_ms_max'] : null,
            ];
        }

        return $series;
    }

    /** @return list<string> */
    public static function ranges(): array
    {
        return array_keys(self::RANGES);
    }
}
