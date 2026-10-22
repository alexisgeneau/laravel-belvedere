<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Belvedere\Support\Storage;
use Illuminate\Support\Collection;

/** What the masters say about the supervision, for the dashboard, the API and belvedere:status. */
final readonly class SupervisionStatus
{
    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const INACTIVE = 'inactive';

    /** No master seen in the last day: the workers are started by hand. */
    public const NONE = 'none';

    public function __construct(
        private MasterRepository $masters,
        private SupervisorRepository $supervisors,
        private Storage $storage,
    ) {}

    /** Whether the supervision tables exist: without them, nothing is supervised and nothing fails. */
    public function available(): bool
    {
        return $this->storage->hasSupervisionTables();
    }

    public function overall(): string
    {
        if (! $this->available()) {
            return self::NONE;
        }

        $recent = $this->masters->recent();

        if ($recent->isEmpty()) {
            return self::NONE;
        }

        $alive = $recent->filter(fn (MasterState $master): bool => $master->alive($this->masters->inactiveAfterSeconds()));

        if ($alive->isEmpty()) {
            return self::INACTIVE;
        }

        return $alive->contains(static fn (MasterState $master): bool => $master->status === self::PAUSED) ? self::PAUSED : self::RUNNING;
    }

    /**
     * The masters of the last day with their supervisors, alive or not.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function masters(): Collection
    {
        if (! $this->available()) {
            return new Collection;
        }

        $inactiveAfter = $this->masters->inactiveAfterSeconds();
        $supervisors = $this->supervisors->recent()->groupBy(static fn (SupervisorState $supervisor): string => $supervisor->master);

        return $this->masters->recent()
            ->map(fn (MasterState $master): array => $this->describeMaster($master, $supervisors->get($master->name, new Collection), $inactiveAfter))
            ->values();
    }

    public function totalProcesses(): int
    {
        if (! $this->available()) {
            return 0;
        }

        return (int) $this->supervisors->all()->sum(static fn (SupervisorState $supervisor): int => $supervisor->totalProcesses());
    }

    /**
     * @param  Collection<int, SupervisorState>  $supervisors
     * @return array<string, mixed>
     */
    private function describeMaster(MasterState $master, Collection $supervisors, int $inactiveAfter): array
    {
        $own = $supervisors->map(fn (SupervisorState $supervisor): array => $this->describeSupervisor($supervisor, $inactiveAfter))->values();

        return [
            'name' => $master->name,
            'hostname' => $master->hostname,
            'pid' => $master->pid,
            'environment' => $master->environment,
            'status' => $master->effectiveStatus($inactiveAfter),
            'processes' => (int) $own->sum('processes'),
            'supervisors' => $own->all(),
            'started_at' => $master->startedAt->toIso8601String(),
            'last_seen_at' => $master->lastSeenAt->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function describeSupervisor(SupervisorState $supervisor, int $inactiveAfter): array
    {
        return [
            'name' => $supervisor->shortName(),
            'full_name' => $supervisor->name,
            'pid' => $supervisor->pid,
            'status' => $supervisor->effectiveStatus($inactiveAfter),
            'processes' => $supervisor->totalProcesses(),
            'processes_by_queue' => $supervisor->processes,
            'queues' => array_values(array_filter(array_map(trim(...), explode(',', (string) ($supervisor->options['queue'] ?? ''))))),
            'balance' => $supervisor->options['balance'] ?? 'auto',
            'auto_scaling_strategy' => $supervisor->options['autoScalingStrategy'] ?? null,
            'min_processes' => (int) ($supervisor->options['minProcesses'] ?? 1),
            'max_processes' => (int) ($supervisor->options['maxProcesses'] ?? 1),
            'at_max' => $supervisor->maxProcesses() > 0 && $supervisor->totalProcesses() >= $supervisor->maxProcesses(),
            'started_at' => $supervisor->startedAt->toIso8601String(),
            'last_seen_at' => $supervisor->lastSeenAt->toIso8601String(),
        ];
    }
}
