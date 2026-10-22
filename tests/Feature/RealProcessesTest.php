<?php

declare(strict_types=1);

use Belvedere\Supervision\PhpBinary;
use Belvedere\Supervision\ProcessPool;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\SupervisorOptions;
use Belvedere\Supervision\WorkerProcess;
use Symfony\Component\Process\Process;

/**
 * A pool of real processes: PHP scripts that sleep, in place of queue:work. The
 * timings are generous for slow CI runners.
 */
function realPool(int $timeout = 5): ProcessPool
{
    return new ProcessPool(
        test()->supervisorOptions(['timeout' => $timeout]),
        static fn (SupervisorOptions $options): WorkerProcess => new WorkerProcess(
            Process::fromShellCommandline('exec '.PhpBinary::path()." -r 'sleep(60);'")->setTimeout(null)->disableOutput(),
        ),
        static fn (string $type, string $line): null => null,
    );
}

function waitUntil(Closure $condition, float $seconds = 10): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if ($condition()) {
            return true;
        }

        usleep(100000);
    }

    return $condition();
}

it('starts, terminates and kills real processes', function (): void {
    $pool = realPool(timeout: 2);

    $pool->scale(2);

    expect(waitUntil(static fn (): bool => $pool->processes()->every(static fn (WorkerProcess $process): bool => $process->isRunning())))->toBeTrue()
        ->and($pool->processes()->map(static fn (WorkerProcess $process): ?int => $process->pid())->filter()->all())->toHaveCount(2);

    // A script that sleeps dies on SIGTERM at once, like a worker between two jobs.
    $pool->scale(1);

    expect(waitUntil(static fn (): bool => $pool->terminatingProcesses()->isEmpty()))->toBeTrue()
        ->and($pool->count())->toBe(1);

    $survivor = $pool->processes()->sole();
    $survivor->terminate();

    expect(waitUntil(static fn (): bool => ! $survivor->isRunning()))->toBeTrue();

    $pool->scale(0);
})->skip(ProcessSignaller::unsupported() !== null, 'No pcntl here.');

it('replaces a real process that was killed', function (): void {
    $pool = realPool();
    $pool->scale(1);

    $worker = $pool->processes()->sole();

    expect(waitUntil(static fn (): bool => $worker->isRunning()))->toBeTrue();

    $pid = $worker->pid();
    posix_kill((int) $pid, SIGKILL);

    expect(waitUntil(static fn (): bool => ! $worker->isRunning()))->toBeTrue();

    $relaunched = waitUntil(static function () use ($pool, $worker): bool {
        $pool->monitor();

        return $worker->isRunning();
    }, 5);

    expect($relaunched)->toBeTrue()
        ->and($worker->pid())->not->toBe($pid);

    $pool->scale(0);
})->skip(ProcessSignaller::unsupported() !== null, 'No pcntl here.');
