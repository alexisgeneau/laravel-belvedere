<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Illuminate\Console\Application as Artisan;

/**
 * The shell commands of the processes a master and a supervisor start: a plain
 * `queue:work` for every worker, `belvedere:supervisor` for every supervisor.
 */
final class CommandStrings
{
    public static function worker(SupervisorOptions $options): string
    {
        $parts = [
            self::artisan(),
            'queue:work',
            escapeshellarg($options->connection),
            '--name='.escapeshellarg($options->name),
            '--queue='.escapeshellarg($options->queue),
            '--backoff='.escapeshellarg($options->backoff),
            "--memory={$options->memory}",
            "--timeout={$options->timeout}",
            "--tries={$options->tries}",
            "--sleep={$options->sleep}",
            "--rest={$options->rest}",
            "--max-time={$options->maxTime}",
            "--max-jobs={$options->maxJobs}",
        ];

        if ($options->force) {
            $parts[] = '--force';
        }

        return implode(' ', $parts);
    }

    public static function supervisor(SupervisorOptions $options, bool $paused = false): string
    {
        $parts = [
            self::artisan(),
            'belvedere:supervisor',
            escapeshellarg($options->name),
            escapeshellarg($options->connection),
            '--queue='.escapeshellarg($options->queue),
            '--balance='.($options->balance === false ? 'false' : $options->balance),
            "--auto-scaling-strategy={$options->autoScalingStrategy}",
            "--min-processes={$options->minProcesses}",
            "--max-processes={$options->maxProcesses}",
            "--balance-max-shift={$options->balanceMaxShift}",
            "--balance-cooldown={$options->balanceCooldown}",
            "--memory={$options->memory}",
            "--tries={$options->tries}",
            "--timeout={$options->timeout}",
            '--backoff='.escapeshellarg($options->backoff),
            "--max-time={$options->maxTime}",
            "--max-jobs={$options->maxJobs}",
            "--sleep={$options->sleep}",
            "--rest={$options->rest}",
            "--nice={$options->nice}",
            "--parent-id={$options->parentId}",
        ];

        if ($options->force) {
            $parts[] = '--force';
        }

        if ($paused) {
            $parts[] = '--paused';
        }

        return implode(' ', $parts);
    }

    private static function artisan(): string
    {
        return 'exec '.PhpBinary::path().' '.Artisan::artisanBinary();
    }
}
