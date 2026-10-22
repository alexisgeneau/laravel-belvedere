<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Closure;
use Symfony\Component\Process\Process;

/**
 * A supervisor seen from the master: a child process that is started again with
 * its options when it dies, unless it exited on purpose.
 */
final class SupervisorProcess extends WorkerProcess
{
    /** The supervisor stopped as asked, failed its arguments, or found a duplicate: no restart. */
    public const DONT_RESTART_ON = [0, 2, 13];

    public bool $dead = false;

    /** @param  Closure(SupervisorOptions): void  $reprovision */
    public function __construct(
        public readonly SupervisorOptions $options,
        Process $process,
        private readonly Closure $reprovision,
    ) {
        parent::__construct($process);
    }

    public function monitor(): void
    {
        if (! $this->process->isStarted()) {
            $this->restart();

            return;
        }

        if (! $this->process->isRunning() && $this->process->getExitCode() === 13) {
            $this->dead = true;

            return;
        }

        if ($this->process->isRunning() || $this->coolingDown()) {
            return;
        }

        $exitCode = $this->process->getExitCode();
        $this->dead = true;

        if (! in_array($exitCode, self::DONT_RESTART_ON, true)) {
            ($this->reprovision)($this->options);
        }
    }
}
