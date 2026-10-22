<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Belvedere\Enums\WorkerStatus;
use Belvedere\Supervision\Events\SupervisorLooped;
use Belvedere\Supervision\Repositories\CommandQueue;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * One supervisor: a process of its own that keeps the worker pools of a group of
 * queues at the right size, Horizon's Supervisor with the database as its store.
 */
final class Supervisor
{
    use ListensForSignals;

    /** The exit code of a supervisor that found another one with its name. */
    public const DUPLICATE_EXIT_CODE = 13;

    /** The exit code of a supervisor that outgrew its memory: the master starts it again. */
    public const MEMORY_EXIT_CODE = 12;

    public bool $working = true;

    /** @var Collection<int, ProcessPool> */
    public Collection $processPools;

    public readonly CarbonImmutable $startedAt;

    private ?CarbonImmutable $lastAutoScaled = null;

    private ?CarbonImmutable $lastPersistedAt = null;

    private Closure $output;

    private Closure $exit;

    /** @param  Closure(SupervisorOptions): WorkerProcess  $processFactory */
    public function __construct(
        public readonly SupervisorOptions $options,
        private readonly SupervisorRepository $repository,
        private readonly CommandQueue $commands,
        private readonly AutoScaler $scaler,
        private readonly SystemProcesses $system,
        private readonly ExceptionHandler $exceptions,
        private readonly Closure $processFactory,
        private readonly int $heartbeatSeconds = 5,
    ) {
        $this->startedAt = CarbonImmutable::now();
        $this->output = static fn (): null => null;
        $this->exit = static fn (int $status): never => exit($status);
        $this->processPools = $this->createProcessPools();

        $this->commands->flush($options->name);
    }

    public function masterName(): string
    {
        $position = strrpos($this->options->name, ':');

        return $position === false ? $this->options->name : substr($this->options->name, 0, $position);
    }

    /** Runs until terminated. */
    public function monitor(): void
    {
        $this->ensureNoDuplicateSupervisors();
        $this->listenForSignals();
        $this->persist();

        for (; ;) {
            sleep(1);

            $this->loop();
        }
    }

    public function ensureNoDuplicateSupervisors(): void
    {
        if ($this->repository->find($this->options->name) !== null) {
            throw new RuntimeException("A supervisor named [{$this->options->name}] is already running.");
        }
    }

    public function loop(): void
    {
        try {
            $this->ensureParentIsRunning();
            $this->processPendingSignals();

            if ($this->shouldPersist()) {
                $this->processPendingCommands();
                $this->persist();
            }

            if ($this->working) {
                $this->autoScale();
                $this->processPools->each(static fn (ProcessPool $pool) => $pool->monitor());
            }

            if ($this->memoryUsage() > $this->options->memory) {
                $this->terminate(self::MEMORY_EXIT_CODE);
            }

            event(new SupervisorLooped($this));
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    /** Spreads processes evenly over the pools: the initial size, before the first balancing. */
    public function scale(int $processes): void
    {
        $perPool = intdiv(max(0, $processes), max(1, $this->processPools->count()));

        $this->balance($this->processPools->mapWithKeys(static fn (ProcessPool $pool): array => [$pool->queue() => $perPool])->all());
    }

    /** @param  array<string, int>  $sizes  queue => processes */
    public function balance(array $sizes): void
    {
        foreach ($sizes as $queue => $size) {
            $this->processPools->first(static fn (ProcessPool $pool): bool => $pool->queue() === $queue)?->scale((int) $size);
        }
    }

    /** Replaces every worker, pool by pool: the new processes run the code now deployed. */
    public function restart(): void
    {
        $this->working = true;
        $this->processPools->each(static fn (ProcessPool $pool) => $pool->restart());
    }

    public function pause(): void
    {
        $this->working = false;
        $this->processPools->each(static fn (ProcessPool $pool) => $pool->pause());
        $this->repository->markWorkers($this, WorkerStatus::Paused);
    }

    public function continue(): void
    {
        $this->working = true;
        $this->processPools->each(static fn (ProcessPool $pool) => $pool->continue());
        $this->repository->markWorkers($this, WorkerStatus::Running);
    }

    /**
     * Lets every worker finish its job and exits. Past the timeout, the workers
     * still running are stopped: the process monitor must not wait forever.
     */
    public function terminate(int $status = 0): void
    {
        $this->working = false;

        $this->repository->forget($this->options->name);

        $this->processPools->each(static function (ProcessPool $pool): void {
            $pool->processes()->each(static fn (WorkerProcess $process) => $process->terminate());
        });

        $deadline = CarbonImmutable::now()->addSeconds($this->options->timeout);

        while ($this->runningProcesses()->isNotEmpty()) {
            if (CarbonImmutable::now()->gte($deadline)) {
                $this->runningProcesses()->each(static fn (WorkerProcess $process) => $process->stop());

                break;
            }

            sleep(1);
        }

        ($this->exit)($status);
    }

    public function persist(): void
    {
        $this->repository->update($this);
        $this->lastPersistedAt = CarbonImmutable::now();
    }

    public function pruneTerminatingProcesses(): void
    {
        $this->processPools->each(static fn (ProcessPool $pool) => $pool->pruneTerminatingProcesses());
    }

    /** @return Collection<int, WorkerProcess> */
    public function processes(): Collection
    {
        return $this->processPools->flatMap(static fn (ProcessPool $pool): Collection => $pool->processes());
    }

    /** @return Collection<int, WorkerProcess> */
    public function runningProcesses(): Collection
    {
        return $this->processPools->flatMap(static fn (ProcessPool $pool): Collection => $pool->runningProcesses());
    }

    public function totalProcessCount(): int
    {
        return (int) $this->processPools->sum(static fn (ProcessPool $pool): int => $pool->totalProcessCount());
    }

    /** The workers of this machine carrying this supervisor's name, from `ps`: a previous life may have left some. */
    public function totalSystemProcessCount(): int
    {
        return $this->system->countWorkersNamed($this->options->name);
    }

    /** @return array<string, int> `connection:queue` => running workers */
    public function processCounts(): array
    {
        return $this->processPools
            ->mapWithKeys(fn (ProcessPool $pool): array => ["{$this->options->connection}:{$pool->queue()}" => $pool->count()])
            ->all();
    }

    public function pid(): int
    {
        return (int) getmypid();
    }

    /** In megabytes. */
    public function memoryUsage(): float
    {
        return memory_get_usage() / 1024 / 1024;
    }

    public function isPaused(): bool
    {
        return ! $this->working;
    }

    /** @param  Closure(string, string): void  $callback */
    public function handleOutputUsing(Closure $callback): self
    {
        $this->output = $callback;

        return $this;
    }

    /** @param  Closure(int): mixed  $callback  What stands for exit() once the process is done; the tests replace it. */
    public function exitUsing(Closure $callback): self
    {
        $this->exit = $callback;

        return $this;
    }

    public function output(string $type, string $line): void
    {
        ($this->output)($type, $line);
    }

    /** @return Collection<int, ProcessPool> */
    private function createProcessPools(): Collection
    {
        $output = fn (string $type, string $line) => $this->output($type, $line);

        if (! $this->options->balancing()) {
            return new Collection([new ProcessPool($this->options, $this->processFactory, $output)]);
        }

        return (new Collection($this->options->queues()))
            ->map(fn (string $queue): ProcessPool => new ProcessPool($this->options->withQueue($queue), $this->processFactory, $output));
    }

    private function ensureParentIsRunning(): void
    {
        if ($this->options->parentId > 1 && posix_getppid() <= 1) {
            $this->terminate();
        }
    }

    private function shouldPersist(): bool
    {
        return $this->lastPersistedAt === null || $this->lastPersistedAt->addSeconds($this->heartbeatSeconds)->lte(CarbonImmutable::now());
    }

    private function processPendingCommands(): void
    {
        foreach ($this->commands->pending($this->options->name) as $command) {
            match ($command['command']) {
                CommandQueue::PAUSE => $this->pause(),
                CommandQueue::CONTINUE => $this->continue(),
                CommandQueue::RESTART => $this->restart(),
                CommandQueue::TERMINATE => $this->terminate((int) ($command['payload']['status'] ?? 0)),
                'scale' => $this->scale((int) ($command['payload']['processes'] ?? 0)),
                'balance' => $this->balance(array_map(intval(...), (array) ($command['payload']['sizes'] ?? []))),
                default => null,
            };
        }
    }

    private function autoScale(): void
    {
        $this->lastAutoScaled ??= CarbonImmutable::now()->subSeconds($this->options->balanceCooldown + 1);

        if (CarbonImmutable::now()->subSeconds($this->options->balanceCooldown)->gte($this->lastAutoScaled)) {
            $this->lastAutoScaled = CarbonImmutable::now();

            $this->scaler->scale($this);
        }
    }
}
