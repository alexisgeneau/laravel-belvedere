<?php

declare(strict_types=1);

namespace Belvedere\Queues;

use Belvedere\Support\Payload;
use Illuminate\Support\Carbon;

final readonly class FailedJob
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>|null  $snapshot
     */
    public function __construct(
        public string $id,
        public int $rowId,
        public ?string $uuid,
        public string $connection,
        public string $queue,
        public string $name,
        public array $tags,
        public ?string $batchId,
        public ?array $snapshot,
        public string $payload,
        public string $exception,
        public Carbon $failedAt,
    ) {}

    public static function fromRow(object $row, bool $identifiedByUuid): self
    {
        $payload = Payload::tryFromJson((string) $row->payload);
        $uuid = isset($row->uuid) ? (string) $row->uuid : $payload?->uuid();

        return new self(
            id: $identifiedByUuid && $uuid !== null ? $uuid : (string) $row->id,
            rowId: (int) $row->id,
            uuid: $uuid,
            connection: (string) $row->connection,
            queue: (string) $row->queue,
            name: $payload?->displayName() ?? 'unknown',
            tags: $payload?->tags() ?? [],
            batchId: $payload?->batchId(),
            snapshot: $payload?->snapshot(),
            payload: (string) $row->payload,
            exception: (string) $row->exception,
            failedAt: Carbon::parse((string) $row->failed_at),
        );
    }

    public function exceptionSummary(): string
    {
        $firstLine = strtok($this->exception, "\n");

        return mb_substr($firstLine === false ? $this->exception : $firstLine, 0, 500);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'name' => $this->name,
            'tags' => $this->tags,
            'batch_id' => $this->batchId,
            'exception_summary' => $this->exceptionSummary(),
            'failed_at' => $this->failedAt->toIso8601String(),
        ];
    }
}
