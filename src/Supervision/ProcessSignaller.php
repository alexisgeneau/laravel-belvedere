<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Closure;

/** Sends a signal to a process of this machine; replaceable in tests. */
final class ProcessSignaller
{
    /** @var Closure(int, int): bool */
    private readonly Closure $kill;

    /** @param  (Closure(int, int): bool)|null  $kill */
    public function __construct(?Closure $kill = null)
    {
        $this->kill = $kill ?? static fn (int $pid, int $signal): bool => posix_kill($pid, $signal);
    }

    public function send(int $pid, int $signal): bool
    {
        return ($this->kill)($pid, $signal);
    }

    public function lastError(): string
    {
        return function_exists('posix_strerror') ? posix_strerror(posix_get_last_error()) : 'unknown error';
    }

    /** What stops the supervisor on this machine, if anything: the dashboard needs none of it. */
    public static function unsupported(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'The Belvedere supervisor needs the pcntl and posix extensions, which do not exist on Windows. The dashboard works; start your workers with queue:work.';
        }

        foreach (['pcntl', 'posix'] as $extension) {
            if (! extension_loaded($extension)) {
                return "The Belvedere supervisor needs the {$extension} extension. The dashboard works; start your workers with queue:work.";
            }
        }

        return null;
    }
}
