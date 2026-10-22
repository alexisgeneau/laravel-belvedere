<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Illuminate\Support\Carbon;

/** A supervisor as its last heartbeat described it. */
final readonly class SupervisorState
{
    /**
     * @param  array<string, int>  $processes  `connection:queue` => running workers
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $name,
        public string $master,
        public string $hostname,
        public int $pid,
        public string $status,
        public array $processes,
        public array $options,
        public Carbon $startedAt,
        public Carbon $lastSeenAt,
    ) {}

    public static function fromRow(object $row): self
    {
        $processes = json_decode((string) ($row->processes ?? '{}'), true);
        $options = json_decode((string) ($row->options ?? '{}'), true);

        return new self(
            name: (string) $row->name,
            master: (string) $row->master,
            hostname: (string) $row->hostname,
            pid: (int) $row->pid,
            status: (string) $row->status,
            processes: is_array($processes) ? array_map(intval(...), $processes) : [],
            options: is_array($options) ? $options : [],
            startedAt: Carbon::parse((string) $row->started_at),
            lastSeenAt: Carbon::parse((string) $row->last_seen_at),
        );
    }

    public function alive(int $inactiveAfterSeconds): bool
    {
        return $this->lastSeenAt->gte(Carbon::now()->subSeconds($inactiveAfterSeconds));
    }

    public function effectiveStatus(int $inactiveAfterSeconds): string
    {
        return $this->alive($inactiveAfterSeconds) ? $this->status : 'inactive';
    }

    /** The configuration key, without the master's name. */
    public function shortName(): string
    {
        $position = strrpos($this->name, ':');

        return $position === false ? $this->name : substr($this->name, $position + 1);
    }

    public function totalProcesses(): int
    {
        return (int) array_sum($this->processes);
    }

    public function timeout(): int
    {
        return (int) ($this->options['timeout'] ?? 60);
    }

    public function maxProcesses(): int
    {
        return (int) ($this->options['maxProcesses'] ?? 0);
    }
}
