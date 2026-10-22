<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Belvedere\Supervision\Events\UnableToLaunchProcess;
use Belvedere\Supervision\Events\WorkerProcessRestarting;
use Carbon\CarbonImmutable;
use Closure;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * One child process and the rules that keep it alive: restarted a second after it
 * exits, a minute after when it crashes right after its launch. A worker that
 * exits on purpose (max-jobs, max-time, queue:restart) comes back at once.
 */
class WorkerProcess
{
    private const CRASH_LOOP_SECONDS = 60;

    protected ?Closure $output = null;

    protected ?CarbonImmutable $startedAt = null;

    protected ?CarbonImmutable $restartAgainAt = null;

    public function __construct(public readonly Process $process) {}

    public function start(Closure $output): static
    {
        $this->output = $output;
        $this->startedAt = CarbonImmutable::now();
        $this->restartAgainAt = $this->startedAt->addSecond();
        $this->process->start($output);

        return $this;
    }

    public function monitor(): void
    {
        if ($this->process->isRunning() || $this->coolingDown()) {
            return;
        }

        $this->restart();
    }

    public function pause(): void
    {
        $this->signal(SIGUSR2);
    }

    public function continue(): void
    {
        $this->signal(SIGCONT);
    }

    /** Asks the process to finish its current job and quit. */
    public function terminate(): void
    {
        $this->signal(SIGTERM);
    }

    /** Kills the process, for the ones that ignored terminate() past the timeout. */
    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function isStarted(): bool
    {
        return $this->process->isStarted();
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function pid(): ?int
    {
        return $this->process->getPid();
    }

    public function coolingDown(): bool
    {
        return $this->restartAgainAt !== null && CarbonImmutable::now()->lt($this->restartAgainAt);
    }

    protected function restart(): void
    {
        $crashed = $this->process->isStarted()
            && $this->process->getExitCode() !== 0
            && $this->startedAt !== null
            && $this->startedAt->addSeconds(self::CRASH_LOOP_SECONDS)->isFuture();

        if ($this->process->isStarted()) {
            event(new WorkerProcessRestarting($this));
        }

        $this->start($this->output ?? static fn (): null => null);

        if ($crashed) {
            $this->restartAgainAt = CarbonImmutable::now()->addSeconds(self::CRASH_LOOP_SECONDS);

            event(new UnableToLaunchProcess($this));
        }
    }

    protected function signal(int $signal): void
    {
        try {
            $this->process->signal($signal);
        } catch (ExceptionInterface $exception) {
            if ($this->process->isRunning()) {
                throw $exception;
            }
        }
    }
}
