<?php

declare(strict_types=1);

namespace Belvedere\Notifications;

final class FailureRateExceeded extends AlertNotification
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly float $rate,
        public readonly int $windowMinutes,
        public readonly int $jobs,
    ) {}

    public function title(): string
    {
        return "Failure rate on {$this->queue} is {$this->rate}%";
    }

    public function lines(): array
    {
        return [
            "{$this->rate}% of the {$this->jobs} jobs processed on queue [{$this->queue}] ({$this->connection}) failed over the last {$this->windowMinutes} minutes.",
        ];
    }
}
