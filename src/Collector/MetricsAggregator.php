<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Enums\JobStatus;
use Belvedere\Enums\MetricSubject;
use Belvedere\Support\Storage;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

final class MetricsAggregator
{
    private const FLUSH_INTERVAL_SECONDS = 5;

    /** @var array<string, array{connection: string, subject_type: string, subject: string, bucket: string, processed: int, failed: int, released: int, wait_ms_sum: int, wait_ms_max: int, run_ms_sum: int, run_ms_max: int}> */
    private array $buffer = [];

    private bool $daemon = false;

    private float $flushedAt = 0.0;

    public function __construct(
        private readonly Storage $storage,
        private readonly bool $enabled,
    ) {}

    public function record(Completion $completion): void
    {
        $counter = match ($completion->status) {
            JobStatus::Completed => 'processed',
            JobStatus::Failed => 'failed',
            JobStatus::Released => 'released',
            default => null,
        };

        if (! $this->enabled || $counter === null) {
            return;
        }

        $bucket = Carbon::now()->startOfMinute()->format('Y-m-d H:i:s');

        $this->accumulate($completion, MetricSubject::Queue, $completion->queue, $bucket, $counter);
        $this->accumulate($completion, MetricSubject::Job, $completion->name, $bucket, $counter);

        if (! $this->daemon) {
            $this->flush();
        }
    }

    public function looping(): void
    {
        $this->daemon = true;

        if ($this->buffer !== [] && microtime(true) - $this->flushedAt >= self::FLUSH_INTERVAL_SECONDS) {
            $this->flush();
        }
    }

    public function stopping(): void
    {
        $this->flush();
        $this->daemon = false;
    }

    public function flush(): void
    {
        $entries = $this->buffer;
        $this->buffer = [];
        $this->flushedAt = microtime(true);

        foreach ($entries as $entry) {
            $this->bump($entry);
        }
    }

    private function accumulate(Completion $completion, MetricSubject $type, string $subject, string $bucket, string $counter): void
    {
        $key = implode('|', [$completion->connection, $type->value, $subject, $bucket]);
        $wait = max(0, (int) $completion->waitMs);
        $run = max(0, (int) $completion->runMs);

        $entry = $this->buffer[$key] ?? [
            'connection' => $completion->connection,
            'subject_type' => $type->value,
            'subject' => $subject,
            'bucket' => $bucket,
            'processed' => 0,
            'failed' => 0,
            'released' => 0,
            'wait_ms_sum' => 0,
            'wait_ms_max' => 0,
            'run_ms_sum' => 0,
            'run_ms_max' => 0,
        ];

        $entry[$counter]++;
        $entry['wait_ms_sum'] += $wait;
        $entry['wait_ms_max'] = max($entry['wait_ms_max'], $wait);
        $entry['run_ms_sum'] += $run;
        $entry['run_ms_max'] = max($entry['run_ms_max'], $run);

        $this->buffer[$key] = $entry;
    }

    /** @param array{connection: string, subject_type: string, subject: string, bucket: string, processed: int, failed: int, released: int, wait_ms_sum: int, wait_ms_max: int, run_ms_sum: int, run_ms_max: int} $entry */
    private function bump(array $entry): void
    {
        $keys = [
            'connection' => $entry['connection'],
            'subject_type' => $entry['subject_type'],
            'subject' => $entry['subject'],
            'bucket' => $entry['bucket'],
        ];

        $increments = [
            'processed' => new Expression("processed + {$entry['processed']}"),
            'failed' => new Expression("failed + {$entry['failed']}"),
            'released' => new Expression("released + {$entry['released']}"),
            'wait_ms_sum' => new Expression("wait_ms_sum + {$entry['wait_ms_sum']}"),
            'wait_ms_max' => new Expression("CASE WHEN wait_ms_max < {$entry['wait_ms_max']} THEN {$entry['wait_ms_max']} ELSE wait_ms_max END"),
            'run_ms_sum' => new Expression("run_ms_sum + {$entry['run_ms_sum']}"),
            'run_ms_max' => new Expression("CASE WHEN run_ms_max < {$entry['run_ms_max']} THEN {$entry['run_ms_max']} ELSE run_ms_max END"),
        ];

        if ($this->storage->query('metrics')->where($keys)->update($increments) > 0) {
            return;
        }

        try {
            $this->storage->query('metrics')->insert([...$keys, ...array_diff_key($entry, $keys)]);
        } catch (UniqueConstraintViolationException) {
            $this->storage->query('metrics')->where($keys)->update($increments);
        }
    }
}
