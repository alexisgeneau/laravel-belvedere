<?php

declare(strict_types=1);

namespace Belvedere\Notifications;

use Carbon\CarbonInterval;

final class LongWait extends AlertNotification
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $waitSeconds,
        public readonly int $threshold,
        public readonly ?int $poolMaximum = null,
    ) {}

    public function title(): string
    {
        return "Long wait on {$this->queue}";
    }

    public function lines(): array
    {
        $lines = [
            "The oldest job ready to run on queue [{$this->queue}] (connection [{$this->connection}]) has been waiting for ".self::duration($this->waitSeconds).'.',
            'The configured threshold is '.self::duration($this->threshold).'.',
        ];

        if ($this->poolMaximum !== null) {
            $lines[] = "The supervisor pool of this queue is already at its maximum of {$this->poolMaximum} processes: the limit is the database, not the number of workers.";
        }

        return $lines;
    }

    public static function duration(int $seconds): string
    {
        return CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => false, 'parts' => 2]);
    }
}
