<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\MasterSupervisor;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Belvedere\Supervision\SupervisorState;
use Belvedere\Supervision\SystemProcesses;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Horizon's purge: the workers a killed supervisor left behind on this machine,
 * including those of a supervisor the master has since started again under the
 * same name.
 */
#[AsCommand(name: 'belvedere:orphans')]
final class OrphansCommand extends Command
{
    use SignalsLocalProcesses;

    protected $signature = 'belvedere:orphans
                            {--kill : Send the signal to the orphans instead of only listing them}
                            {--signal=SIGTERM : The signal to send with --kill}';

    protected $description = 'List the workers of this machine that no supervisor tracks any more, and terminate them with --kill';

    public function handle(SystemProcesses $system, SupervisorRepository $supervisors, ProcessSignaller $signaller): int
    {
        $alive = $supervisors->all()->mapWithKeys(static fn (SupervisorState $supervisor): array => [$supervisor->name => $supervisor->pid])->all();

        $orphans = new Collection($system->orphansNamed(MasterSupervisor::basename().'-', $alive));

        if ($orphans->isEmpty()) {
            $this->components->info('No orphan worker on this machine.');

            return self::SUCCESS;
        }

        $this->table(['PID', 'Supervisor'], $orphans->map(static fn (string $name, int $pid): array => [(string) $pid, $name])->values()->all());

        if (! $this->option('kill')) {
            $this->components->info('Run again with --kill to terminate them.');

            return self::SUCCESS;
        }

        $signal = $this->signalNumber((string) $this->option('signal'));

        if ($signal === null) {
            $this->components->error('Unknown signal ['.$this->option('signal').'].');

            return self::FAILURE;
        }

        return $this->signal($signaller, $orphans->keys()->map(static fn (int $pid): int => $pid), $signal, (string) $this->option('signal'), '');
    }

    private function signalNumber(string $name): ?int
    {
        if (is_numeric($name)) {
            return (int) $name;
        }

        $constant = strtoupper($name);

        return defined($constant) ? (int) constant($constant) : null;
    }
}
