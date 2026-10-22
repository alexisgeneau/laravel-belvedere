<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

/**
 * Horizon's signal contract: SIGTERM terminates, SIGUSR1 restarts the workers,
 * SIGUSR2 pauses, SIGCONT continues. A signal only raises a flag; the loop acts
 * on it between two steps, never in the middle of a write.
 */
trait ListensForSignals
{
    /** @var array<string, true> */
    private array $pendingSignals = [];

    protected function listenForSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function (): void {
            $this->pendingSignals['terminate'] = true;
        });

        pcntl_signal(SIGUSR1, function (): void {
            $this->pendingSignals['restart'] = true;
        });

        pcntl_signal(SIGUSR2, function (): void {
            $this->pendingSignals['pause'] = true;
        });

        pcntl_signal(SIGCONT, function (): void {
            $this->pendingSignals['continue'] = true;
        });
    }

    protected function processPendingSignals(): void
    {
        while ($this->pendingSignals !== []) {
            $signal = array_key_first($this->pendingSignals);
            unset($this->pendingSignals[$signal]);

            $this->{$signal}();
        }
    }

    abstract public function terminate(int $status = 0): void;

    abstract public function restart(): void;

    abstract public function pause(): void;

    abstract public function continue(): void;
}
