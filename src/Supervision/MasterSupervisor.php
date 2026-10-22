<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Belvedere\Supervision\Events\MasterSupervisorLooped;
use Belvedere\Supervision\Repositories\CommandQueue;
use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The process `php artisan belvedere` runs: starts the supervisors of the
 * environment, keeps them alive, and relays the orders it receives to them.
 */
final class MasterSupervisor
{
    use ListensForSignals;

    /** The exit code of a master that outgrew its memory: the process monitor starts it again. */
    public const MEMORY_EXIT_CODE = 12;

    private static ?Closure $nameResolver = null;

    private static ?string $token = null;

    public readonly string $name;

    public bool $working = true;

    /** @var Collection<int, SupervisorProcess> */
    public Collection $supervisors;

    public readonly CarbonImmutable $startedAt;

    private ?CarbonImmutable $lastPersistedAt = null;

    private Closure $output;

    private Closure $exit;

    /** @param  Closure(SupervisorOptions): Process  $processFactory */
    public function __construct(
        public readonly ?string $environment,
        private readonly MasterRepository $repository,
        private readonly SupervisorRepository $supervisorRepository,
        private readonly CommandQueue $commands,
        private readonly ExceptionHandler $exceptions,
        private readonly Closure $processFactory,
        private readonly int $heartbeatSeconds = 5,
        private readonly int $memoryLimit = 64,
    ) {
        $this->name = self::name();
        $this->startedAt = CarbonImmutable::now();
        $this->supervisors = new Collection;
        $this->output = static fn (): null => null;
        $this->exit = static fn (int $status): never => exit($status);

        $this->commands->flush($this->name);
    }

    /** The host's slug and four random characters, fixed for the life of the process. */
    public static function name(): string
    {
        self::$token ??= Str::lower(Str::random(4));

        return self::basename().'-'.self::$token;
    }

    /** What every master of this machine starts with: the commands find theirs by it. */
    public static function basename(): string
    {
        $hostname = gethostname();

        return self::$nameResolver !== null
            ? (string) (self::$nameResolver)()
            : Str::slug(is_string($hostname) && $hostname !== '' ? $hostname : 'unknown');
    }

    public static function determineNameUsing(?Closure $callback): void
    {
        self::$nameResolver = $callback;
        self::$token = null;
    }

    public function provision(SupervisorOptions $options): void
    {
        $process = new SupervisorProcess(
            $options,
            ($this->processFactory)($options),
            fn (SupervisorOptions $options) => $this->reprovision($options),
        );

        $this->supervisors->push($process->start(fn (string $type, string $line) => $this->output($type, $line)));
    }

    /** Runs until terminated. */
    public function monitor(): void
    {
        $this->ensureNoOtherMasterSupervisors();
        $this->listenForSignals();
        $this->persist();

        for (; ;) {
            sleep(1);

            $this->loop();
        }
    }

    public function ensureNoOtherMasterSupervisors(): void
    {
        if ($this->repository->find($this->name) !== null) {
            throw new RuntimeException('A master supervisor is already running on this machine.');
        }
    }

    public function loop(): void
    {
        try {
            $this->processPendingSignals();

            if ($this->shouldPersist()) {
                $this->processPendingCommands();
                $this->persist();
            }

            if ($this->working) {
                $this->monitorSupervisors();
            }

            if ($this->memoryUsage() > $this->memoryLimit) {
                $this->terminate(self::MEMORY_EXIT_CODE);
            }

            event(new MasterSupervisorLooped($this));
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    /** Every supervisor exits with the code 1, and is started again with the code now deployed. */
    public function restart(): void
    {
        $this->working = true;

        $this->supervisors->each(fn (SupervisorProcess $supervisor) => $this->commands->push($supervisor->options->name, CommandQueue::TERMINATE, ['status' => 1]));
    }

    public function pause(): void
    {
        $this->working = false;
        $this->supervisors->each(static fn (SupervisorProcess $supervisor) => $supervisor->pause());
    }

    public function continue(): void
    {
        $this->working = true;
        $this->supervisors->each(static fn (SupervisorProcess $supervisor) => $supervisor->continue());
    }

    /**
     * Forgets its own row at once, so that a new master can start in its place,
     * then waits for the supervisors as long as the longest worker timeout.
     */
    public function terminate(int $status = 0): void
    {
        $this->working = false;

        $longest = $this->supervisorRepository->longestActiveTimeout();

        $this->supervisors->each(static fn (SupervisorProcess $supervisor) => $supervisor->terminate());

        $this->repository->forget($this->name);

        $deadline = CarbonImmutable::now()->addSeconds($longest + 5);

        while ($this->supervisors->contains(static fn (SupervisorProcess $supervisor): bool => $supervisor->isRunning())) {
            if (CarbonImmutable::now()->gte($deadline)) {
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

    /** @return list<string> */
    public function supervisorNames(): array
    {
        return $this->supervisors->map(static fn (SupervisorProcess $supervisor): string => $supervisor->options->name)->values()->all();
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

    private function reprovision(SupervisorOptions $options): void
    {
        $this->supervisorRepository->forget($options->name);
        $this->provision($options);
    }

    private function monitorSupervisors(): void
    {
        $this->supervisors->each(static fn (SupervisorProcess $supervisor) => $supervisor->monitor());

        $this->supervisors = $this->supervisors->reject(static fn (SupervisorProcess $supervisor): bool => $supervisor->dead)->values();
    }

    private function shouldPersist(): bool
    {
        return $this->lastPersistedAt === null || $this->lastPersistedAt->addSeconds($this->heartbeatSeconds)->lte(CarbonImmutable::now());
    }

    private function processPendingCommands(): void
    {
        foreach ($this->commands->pending($this->name) as $command) {
            match ($command['command']) {
                CommandQueue::PAUSE => $this->pause(),
                CommandQueue::CONTINUE => $this->continue(),
                CommandQueue::RESTART => $this->restart(),
                CommandQueue::TERMINATE => $this->terminate((int) ($command['payload']['status'] ?? 0)),
                default => null,
            };
        }
    }
}
