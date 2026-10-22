<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Carbon\CarbonImmutable;
use Closure;
use Countable;
use Illuminate\Support\Collection;

/**
 * The worker processes of one queue (or of one ordered list of queues when the
 * supervisor does not balance). Scaling down keeps the leaving workers in a
 * terminating list until they have finished their job, or been stopped past the
 * timeout.
 */
final class ProcessPool implements Countable
{
    /** @var list<WorkerProcess> */
    private array $processes = [];

    /** @var list<array{process: WorkerProcess, terminatedAt: CarbonImmutable}> */
    private array $terminating = [];

    public bool $working = true;

    /**
     * @param  Closure(SupervisorOptions): WorkerProcess  $factory
     * @param  Closure(string, string): void  $output
     */
    public function __construct(
        public readonly SupervisorOptions $options,
        private readonly Closure $factory,
        private readonly Closure $output,
    ) {}

    public function queue(): string
    {
        return $this->options->queue;
    }

    public function scale(int $processes): void
    {
        $processes = max(0, $processes);

        if ($processes > count($this->processes)) {
            $this->scaleUp($processes);
        } elseif ($processes < count($this->processes)) {
            $this->scaleDown($processes);
        }
    }

    public function monitor(): void
    {
        foreach ($this->processes as $process) {
            $process->monitor();
        }
    }

    public function restart(): void
    {
        $count = count($this->processes);

        $this->scale(0);
        $this->scale($count);
    }

    public function pause(): void
    {
        $this->working = false;

        foreach ($this->processes as $process) {
            $process->pause();
        }
    }

    public function continue(): void
    {
        $this->working = true;

        foreach ($this->processes as $process) {
            $process->continue();
        }
    }

    /** @return Collection<int, WorkerProcess> */
    public function processes(): Collection
    {
        return new Collection($this->processes);
    }

    /** @return Collection<int, WorkerProcess> */
    public function terminatingProcesses(): Collection
    {
        $this->pruneTerminatingProcesses();

        return (new Collection($this->terminating))->map(static fn (array $entry): WorkerProcess => $entry['process']);
    }

    /** @return Collection<int, WorkerProcess> */
    public function runningProcesses(): Collection
    {
        return $this->processes()
            ->concat($this->terminatingProcesses())
            ->filter(static fn (WorkerProcess $process): bool => $process->isRunning())
            ->values();
    }

    /** Forgets the terminating processes that have exited, and kills the ones past the timeout. */
    public function pruneTerminatingProcesses(): void
    {
        foreach ($this->terminating as $entry) {
            if ($entry['terminatedAt']->addSeconds($this->options->timeout)->lte(CarbonImmutable::now())) {
                $entry['process']->stop();
            }
        }

        $this->terminating = array_values(array_filter(
            $this->terminating,
            static fn (array $entry): bool => $entry['process']->isRunning(),
        ));
    }

    /** Processes started, the ones still terminating included. */
    public function totalProcessCount(): int
    {
        return count($this->processes) + count($this->terminating);
    }

    public function count(): int
    {
        return count($this->processes);
    }

    private function scaleUp(int $processes): void
    {
        for ($i = count($this->processes); $i < $processes; $i++) {
            $this->processes[] = ($this->factory)($this->options)->start($this->output);
        }
    }

    private function scaleDown(int $processes): void
    {
        $leaving = array_splice($this->processes, 0, count($this->processes) - $processes);

        foreach ($leaving as $process) {
            $this->terminating[] = ['process' => $process, 'terminatedAt' => CarbonImmutable::now()];
            $process->terminate();
        }
    }
}
