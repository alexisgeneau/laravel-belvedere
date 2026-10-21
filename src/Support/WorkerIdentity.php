<?php

declare(strict_types=1);

namespace Belvedere\Support;

final class WorkerIdentity
{
    public static function id(): string
    {
        return self::hostname().':'.self::pid();
    }

    public static function hostname(): string
    {
        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';
    }

    public static function pid(): int
    {
        return (int) getmypid();
    }
}
