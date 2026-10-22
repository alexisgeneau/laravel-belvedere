<?php

declare(strict_types=1);

namespace Belvedere\Supervision\Repositories;

use Belvedere\Supervision\MasterState;
use Belvedere\Supervision\MasterSupervisor;
use Belvedere\Support\Storage;
use Belvedere\Support\WorkerIdentity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** The `belvedere_masters` table: one row per master, written at every heartbeat. */
final readonly class MasterRepository
{
    public function __construct(
        private Storage $storage,
        private SupervisorRepository $supervisors,
        private int $inactiveAfterSeconds,
    ) {}

    /** @return Collection<int, MasterState> The masters whose heartbeat is recent. */
    public function all(): Collection
    {
        return $this->recent()->filter(fn (MasterState $master): bool => $master->alive($this->inactiveAfterSeconds))->values();
    }

    /** @return Collection<int, MasterState> The masters seen in the last hours, alive or not. */
    public function recent(int $hours = 24): Collection
    {
        return $this->storage->query('masters')
            ->where('last_seen_at', '>=', Carbon::now()->subHours($hours))
            ->orderBy('name')
            ->get()
            ->map(static fn (object $row): MasterState => MasterState::fromRow($row))
            ->values();
    }

    public function find(string $name): ?MasterState
    {
        $row = $this->storage->query('masters')->where('name', $name)->first();

        if ($row === null) {
            return null;
        }

        $master = MasterState::fromRow($row);

        return $master->alive($this->inactiveAfterSeconds) ? $master : null;
    }

    public function update(MasterSupervisor $master): void
    {
        $now = Carbon::now();

        $this->storage->query('masters')->upsert(
            [[
                'name' => $master->name,
                'hostname' => WorkerIdentity::hostname(),
                'pid' => $master->pid(),
                'environment' => $master->environment,
                'status' => $master->working ? 'running' : 'paused',
                'supervisors' => json_encode($master->supervisorNames(), JSON_THROW_ON_ERROR),
                'started_at' => $master->startedAt,
                'last_seen_at' => $now,
            ]],
            ['name'],
            ['hostname', 'pid', 'environment', 'status', 'supervisors', 'last_seen_at'],
        );
    }

    public function forget(string $name): void
    {
        $row = $this->storage->query('masters')->where('name', $name)->first();

        if ($row === null) {
            return;
        }

        $this->supervisors->forget(MasterState::fromRow($row)->supervisors);
        $this->storage->query('masters')->where('name', $name)->delete();
    }

    public function inactiveAfterSeconds(): int
    {
        return $this->inactiveAfterSeconds;
    }
}
