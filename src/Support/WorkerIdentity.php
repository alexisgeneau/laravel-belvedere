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

    /** The `--name` of the command line, when the process was started as `queue:work --name=...`. */
    public static function nameOption(): ?string
    {
        $argv = $_SERVER['argv'] ?? [];

        if (! is_array($argv)) {
            return null;
        }

        $arguments = array_values($argv);

        foreach ($arguments as $index => $argument) {
            if (! is_string($argument)) {
                continue;
            }

            if (str_starts_with($argument, '--name=')) {
                return substr($argument, 7);
            }

            if ($argument === '--name' && isset($arguments[$index + 1]) && is_string($arguments[$index + 1])) {
                return $arguments[$index + 1];
            }
        }

        return null;
    }
}
