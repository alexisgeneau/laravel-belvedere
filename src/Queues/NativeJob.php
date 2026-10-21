<?php

declare(strict_types=1);

namespace Belvedere\Queues;

use Belvedere\Support\Payload;
use Illuminate\Support\Carbon;

final readonly class NativeJob
{
    /** @param list<string> $tags */
    public function __construct(
        public int $id,
        public string $connection,
        public string $queue,
        public ?string $uuid,
        public string $name,
        public int $attempts,
        public ?int $maxTries,
        public ?int $timeout,
        public array $tags,
        public ?string $batchId,
        public NativeState $state,
        public Carbon $createdAt,
        public Carbon $availableAt,
        public ?Carbon $reservedAt,
    ) {}

    public static function fromRow(object $row, string $connection, int $retryAfter, int $now): self
    {
        $payload = Payload::tryFromJson((string) $row->payload);
        $reservedAt = $row->reserved_at !== null ? (int) $row->reserved_at : null;
        $availableAt = (int) $row->available_at;

        $state = match (true) {
            $reservedAt !== null && $reservedAt < $now - $retryAfter => NativeState::Stuck,
            $reservedAt !== null => NativeState::Running,
            $availableAt > $now => NativeState::Delayed,
            default => NativeState::Pending,
        };

        return new self(
            id: (int) $row->id,
            connection: $connection,
            queue: (string) $row->queue,
            uuid: $payload?->uuid(),
            name: $payload?->displayName() ?? 'unknown',
            attempts: (int) $row->attempts,
            maxTries: $payload?->maxTries(),
            timeout: $payload?->timeout(),
            tags: $payload?->tags() ?? [],
            batchId: $payload?->batchId(),
            state: $state,
            createdAt: Carbon::createFromTimestamp((int) $row->created_at),
            availableAt: Carbon::createFromTimestamp($availableAt),
            reservedAt: $reservedAt !== null ? Carbon::createFromTimestamp($reservedAt) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'attempts' => $this->attempts,
            'max_tries' => $this->maxTries,
            'timeout' => $this->timeout,
            'tags' => $this->tags,
            'batch_id' => $this->batchId,
            'state' => $this->state->value,
            'created_at' => $this->createdAt->toIso8601String(),
            'available_at' => $this->availableAt->toIso8601String(),
            'reserved_at' => $this->reservedAt?->toIso8601String(),
        ];
    }
}
