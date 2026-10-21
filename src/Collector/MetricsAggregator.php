<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Enums\JobStatus;
use Belvedere\Enums\MetricSubject;
use Belvedere\Support\Storage;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

final readonly class MetricsAggregator
{
    public function __construct(
        private Storage $storage,
        private bool $enabled,
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

        $bucket = Carbon::now()->startOfMinute();

        $this->bump($completion, MetricSubject::Queue, $completion->queue, $bucket, $counter);
        $this->bump($completion, MetricSubject::Job, $completion->name, $bucket, $counter);
    }

    private function bump(Completion $completion, MetricSubject $type, string $subject, Carbon $bucket, string $counter): void
    {
        $wait = max(0, (int) $completion->waitMs);
        $run = max(0, (int) $completion->runMs);

        $keys = [
            'connection' => $completion->connection,
            'subject_type' => $type->value,
            'subject' => $subject,
            'bucket' => $bucket,
        ];

        $increments = [
            $counter => new Expression("{$counter} + 1"),
            'wait_ms_sum' => new Expression("wait_ms_sum + {$wait}"),
            'wait_ms_max' => new Expression("CASE WHEN wait_ms_max < {$wait} THEN {$wait} ELSE wait_ms_max END"),
            'run_ms_sum' => new Expression("run_ms_sum + {$run}"),
            'run_ms_max' => new Expression("CASE WHEN run_ms_max < {$run} THEN {$run} ELSE run_ms_max END"),
        ];

        if ($this->storage->query('metrics')->where($keys)->update($increments) > 0) {
            return;
        }

        try {
            $this->storage->query('metrics')->insert([
                ...$keys,
                $counter => 1,
                'wait_ms_sum' => $wait,
                'wait_ms_max' => $wait,
                'run_ms_sum' => $run,
                'run_ms_max' => $run,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->storage->query('metrics')->where($keys)->update($increments);
        }
    }
}
