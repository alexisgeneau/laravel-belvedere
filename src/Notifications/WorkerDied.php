<?php

declare(strict_types=1);

namespace Belvedere\Notifications;

use Illuminate\Support\Carbon;

final class WorkerDied extends AlertNotification
{
    public function __construct(
        public readonly string $workerId,
        public readonly string $connection,
        public readonly string $queues,
        public readonly Carbon $lastSeenAt,
    ) {}

    public function title(): string
    {
        return "Worker {$this->workerId} stopped responding";
    }

    public function lines(): array
    {
        return [
            "Worker [{$this->workerId}] consuming [{$this->queues}] on connection [{$this->connection}] has not reported since {$this->lastSeenAt->toDateTimeString()} UTC.",
        ];
    }
}
