<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Supervision;

use Symfony\Component\Process\Process;

/** A process that never runs: the tests decide when it lives, dies and with which code. */
final class FakeProcess extends Process
{
    private static int $nextPid = 1000;

    private bool $started = false;

    private bool $running = false;

    private ?int $exitCode = null;

    private ?int $pid = null;

    public int $starts = 0;

    /** @var list<int> */
    public array $signals = [];

    public bool $stopped = false;

    public function __construct(public readonly string $label = 'fake', public bool $exitsOnTerm = false)
    {
        parent::__construct(['true']);
    }

    public function start(?callable $callback = null, array $env = []): void
    {
        $this->started = true;
        $this->running = true;
        $this->exitCode = null;
        $this->pid = self::$nextPid++;
        $this->starts++;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function signal(int $signal): static
    {
        $this->signals[] = $signal;

        if ($signal === SIGTERM && $this->exitsOnTerm) {
            $this->exitWith(0);
        }

        return $this;
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        $this->stopped = true;
        $this->running = false;
        $this->exitCode ??= 137;

        return $this->exitCode;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getPid(): ?int
    {
        return $this->running ? $this->pid : null;
    }

    /** The process ends by itself. */
    public function exitWith(int $code): void
    {
        $this->running = false;
        $this->exitCode = $code;
    }
}
