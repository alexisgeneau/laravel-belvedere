<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Enums\WorkerStatus;
use Belvedere\Queues\QueuePauser;
use Belvedere\Support\Storage;
use Belvedere\Support\WorkerIdentity;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Carbon;

final class WorkerHeartbeat
{
    private ?float $lastBeat = null;

    private ?WorkerStatus $lastStatus = null;

    private ?Carbon $startedAt = null;

    private int $processed = 0;

    private ?string $supervisor = null;

    public function __construct(
        private readonly Storage $storage,
        private readonly QueuePauser $pauser,
        private readonly int $interval,
    ) {}

    /** The supervisor that started this worker named it; a worker started by hand keeps Laravel's default name. */
    public function starting(WorkerStarting $event): void
    {
        $this->supervisor = self::supervisorFromName((string) $event->workerOptions->name);
    }

    /**
     * Laravel dispatches WorkerStarting since 12.18; before, the name is read from the
     * command line the supervisor built.
     */
    public static function supervisorFromName(?string $name): ?string
    {
        $name ??= WorkerIdentity::nameOption();

        return $name !== null && $name !== '' && $name !== 'default' ? $name : null;
    }

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
        $this->supervisor ??= self::supervisorFromName(null);

        $columns = ['connection', 'queues', 'supervisor', 'status', 'jobs_processed', 'memory_kb', 'last_seen_at'];

        // The first beat of a process may land on the row of a previous one with the same id: a
        // restarted container keeps its hostname and runs the worker as pid 1 again. Start over.
        if ($this->lastBeat === null) {
            $columns[] = 'started_at';
        }

        $this->storage->query('workers')->upsert(
            [[
                'id' => WorkerIdentity::id(),
                'hostname' => WorkerIdentity::hostname(),
                'pid' => WorkerIdentity::pid(),
                'connection' => $event->connectionName,
                'queues' => $event->queue,
                'supervisor' => $this->supervisor,
                'status' => $status->value,
                'jobs_processed' => $this->processed,
                'memory_kb' => (int) (memory_get_usage(true) / 1024),
                'started_at' => $this->startedAt,
                'last_seen_at' => $now,
            ]],
            ['id'],
            $columns,
        );

        $this->lastBeat = microtime(true);
        $this->lastStatus = $status;
    }
}
