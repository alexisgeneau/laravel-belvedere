<?php

declare(strict_types=1);

namespace Belvedere\Supervision\Repositories;

use Belvedere\Enums\WorkerStatus;
use Belvedere\Supervision\Supervisor;
use Belvedere\Supervision\SupervisorState;
use Belvedere\Supervision\WorkerProcess;
use Belvedere\Support\Storage;
use Belvedere\Support\WorkerIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** The `belvedere_supervisors` table: one row per supervisor, written at every heartbeat. */
final readonly class SupervisorRepository
{
    public function __construct(
        private Storage $storage,
        private int $inactiveAfterSeconds,
    ) {}

    /** @return Collection<int, SupervisorState> The supervisors whose heartbeat is recent. */
    public function all(): Collection
    {
        return $this->recent()->filter(fn (SupervisorState $supervisor): bool => $supervisor->alive($this->inactiveAfterSeconds))->values();
    }

    /** @return Collection<int, SupervisorState> */
    public function recent(int $hours = 24): Collection
    {
        return $this->storage->query('supervisors')
            ->where('last_seen_at', '>=', Carbon::now()->subHours($hours))
            ->orderBy('name')
            ->get()
            ->map(static fn (object $row): SupervisorState => SupervisorState::fromRow($row))
            ->values();
    }

    /** @return Collection<int, SupervisorState> */
    public function forMaster(string $master): Collection
    {
        return $this->recent()->filter(static fn (SupervisorState $supervisor): bool => $supervisor->master === $master)->values();
    }

    public function find(string $name): ?SupervisorState
    {
        $row = $this->storage->query('supervisors')->where('name', $name)->first();

        if ($row === null) {
            return null;
        }

        $supervisor = SupervisorState::fromRow($row);

        return $supervisor->alive($this->inactiveAfterSeconds) ? $supervisor : null;
    }

    /** The longest `timeout` of the supervisors alive: how long a termination may take. */
    public function longestActiveTimeout(): int
    {
        return (int) $this->all()->max(static fn (SupervisorState $supervisor): int => $supervisor->timeout());
    }

    public function update(Supervisor $supervisor): void
    {
        $this->storage->query('supervisors')->upsert(
            [[
                'name' => $supervisor->options->name,
                'master' => $supervisor->masterName(),
                'hostname' => WorkerIdentity::hostname(),
                'pid' => $supervisor->pid(),
                'status' => $supervisor->working ? 'running' : 'paused',
                'processes' => json_encode($supervisor->processCounts(), JSON_THROW_ON_ERROR),
                'options' => json_encode($supervisor->options->toArray(), JSON_THROW_ON_ERROR),
                'started_at' => $supervisor->startedAt,
                'last_seen_at' => Carbon::now(),
            ]],
            ['name'],
            ['master', 'hostname', 'pid', 'status', 'processes', 'options', 'last_seen_at'],
        );

        if (! $supervisor->working) {
            $this->markWorkers($supervisor, WorkerStatus::Paused);
        }
    }

    /**
     * A paused worker no longer loops, so it stops reporting: its supervisor reports
     * for it, until it resumes and takes over again.
     */
    public function markWorkers(Supervisor $supervisor, WorkerStatus $status): void
    {
        $pids = $supervisor->runningProcesses()->map(static fn (WorkerProcess $process): ?int => $process->pid())->filter()->values()->all();

        if ($pids === []) {
            return;
        }

        $this->storage->query('workers')
            ->where('hostname', WorkerIdentity::hostname())
            ->whereIn('pid', $pids)
            ->update(['status' => $status->value, 'last_seen_at' => Carbon::now()]);
    }

    /** @param  list<string>|string  $names */
    public function forget(array|string $names): void
    {
        $names = (array) $names;

        if ($names === []) {
            return;
        }

        $this->storage->query('supervisors')->whereIn('name', $names)->delete();
    }

    public function inactiveAfterSeconds(): int
    {
        return $this->inactiveAfterSeconds;
    }
}
