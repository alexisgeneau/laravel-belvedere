<?php

declare(strict_types=1);

namespace Belvedere\Queues;

final readonly class QueueCounts
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $pending,
        public int $delayed,
        public int $running,
        public int $stuck,
        public bool $paused,
    ) {}

    public function backlog(): int
    {
        return $this->pending + $this->delayed;
    }

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'pending' => $this->pending,
            'delayed' => $this->delayed,
            'running' => $this->running,
            'stuck' => $this->stuck,
            'paused' => $this->paused,
        ];
    }
}
