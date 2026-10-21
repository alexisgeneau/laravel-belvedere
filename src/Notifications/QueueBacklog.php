<?php

declare(strict_types=1);

namespace Belvedere\Notifications;

final class QueueBacklog extends AlertNotification
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $size,
        public readonly int $threshold,
    ) {}

    public function title(): string
    {
        return "Queue backlog on {$this->queue}";
    }

    public function lines(): array
    {
        return [
            "Queue [{$this->queue}] on connection [{$this->connection}] has {$this->size} jobs waiting.",
            "The configured threshold is {$this->threshold}.",
        ];
    }
}
