<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Enums\WorkerStatus;
use Belvedere\Queues\QueuePauser;
use Belvedere\Support\Storage;
use Belvedere\Support\WorkerIdentity;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;

final class WorkerHeartbeat
{
    private ?float $lastBeat = null;

    private ?WorkerStatus $lastStatus = null;

    private ?Carbon $startedAt = null;

    private int $processed = 0;

    public function __construct(
        private readonly Storage $storage,
        private readonly QueuePauser $pauser,
        private readonly int $interval,
    ) {}

    public function beat(Looping $event): bool
    {
        $sleeping = $this->pauser->shouldSleep($event->connectionName, $event->queue);
        $status = $sleeping ? WorkerStatus::Paused : WorkerStatus::Running;

        if ($status !== $this->lastStatus || $this->lastBeat === null || microtime(true) - $this->lastBeat >= $this->interval) {
            $this->write($event, $status);
        }

        return $sleeping;
    }

    public function jobProcessed(): void
    {
        $this->processed++;
    }

    public function stopping(): void
    {
        if ($this->lastBeat === null) {
            return;
        }

        $this->storage->query('workers')->where('id', WorkerIdentity::id())->update([
            'status' => WorkerStatus::Stopped->value,
            'jobs_processed' => $this->processed,
            'last_seen_at' => Carbon::now(),
        ]);
    }

    private function write(Looping $event, WorkerStatus $status): void
    {
        $now = Carbon::now();
        $this->startedAt ??= $now;

        $this->storage->query('workers')->upsert(
            [[
                'id' => WorkerIdentity::id(),
                'hostname' => WorkerIdentity::hostname(),
                'pid' => WorkerIdentity::pid(),
                'connection' => $event->connectionName,
                'queues' => $event->queue,
                'status' => $status->value,
                'jobs_processed' => $this->processed,
                'memory_kb' => (int) (memory_get_usage(true) / 1024),
                'started_at' => $this->startedAt,
                'last_seen_at' => $now,
            ]],
            ['id'],
            ['connection', 'queues', 'status', 'jobs_processed', 'memory_kb', 'last_seen_at'],
        );

        $this->lastBeat = microtime(true);
        $this->lastStatus = $status;
    }
}
