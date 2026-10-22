<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Illuminate\Support\Carbon;

/** A master as its last heartbeat described it. */
final readonly class MasterState
{
    /** @param  list<string>  $supervisors */
    public function __construct(
        public string $name,
        public string $hostname,
        public int $pid,
        public ?string $environment,
        public string $status,
        public array $supervisors,
        public Carbon $startedAt,
        public Carbon $lastSeenAt,
    ) {}

    public static function fromRow(object $row): self
    {
        $supervisors = json_decode((string) ($row->supervisors ?? '[]'), true);

        return new self(
            name: (string) $row->name,
            hostname: (string) $row->hostname,
            pid: (int) $row->pid,
            environment: isset($row->environment) ? (string) $row->environment : null,
            status: (string) $row->status,
            supervisors: is_array($supervisors) ? array_values(array_map(strval(...), $supervisors)) : [],
            startedAt: Carbon::parse((string) $row->started_at),
            lastSeenAt: Carbon::parse((string) $row->last_seen_at),
        );
    }

    public function alive(int $inactiveAfterSeconds): bool
    {
        return $this->lastSeenAt->gte(Carbon::now()->subSeconds($inactiveAfterSeconds));
    }

    /** `running`, `paused`, or `inactive` once the heartbeats stopped. */
    public function effectiveStatus(int $inactiveAfterSeconds): string
    {
        return $this->alive($inactiveAfterSeconds) ? $this->status : 'inactive';
    }
}
