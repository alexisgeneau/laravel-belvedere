<?php

declare(strict_types=1);

namespace Belvedere\Enums;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Released = 'released';
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            default => true,
        };
    }

    /** @return list<string> */
    public static function finalValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isFinal()),
        ));
    }
}
