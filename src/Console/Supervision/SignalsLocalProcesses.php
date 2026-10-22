<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\MasterState;
use Belvedere\Supervision\MasterSupervisor;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Belvedere\Supervision\SupervisorState;
use Illuminate\Support\Collection;

/**
 * The commands that act on the masters and supervisors of this machine, found by
 * their name (every master of a host starts with the host's slug), reached by pid.
 */
trait SignalsLocalProcesses
{
    /** @return Collection<int, MasterState> */
    private function localMasters(MasterRepository $masters): Collection
    {
        return $masters->all()->filter(static fn (MasterState $master): bool => str_starts_with($master->name, MasterSupervisor::basename().'-'))->values();
    }

    private function localSupervisor(SupervisorRepository $supervisors, string $name): ?SupervisorState
    {
        return $supervisors->all()->first(static fn (SupervisorState $supervisor): bool => str_starts_with($supervisor->name, MasterSupervisor::basename().'-')
            && ($supervisor->name === $name || $supervisor->shortName() === $name));
    }

    /** @param  Collection<int, int>  $pids */
    private function signal(ProcessSignaller $signaller, Collection $pids, int $signal, string $label, string $nothing): int
    {
        if ($pids->isEmpty()) {
            $this->components->info($nothing);

            return self::SUCCESS;
        }

        $this->components->info("Sending {$label} to ".($pids->count() > 1 ? 'processes' : 'process').' '.$pids->implode(', ').'.');

        $failed = $pids->reject(fn (int $pid): bool => $this->send($signaller, $pid, $signal));

        return $failed->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function send(ProcessSignaller $signaller, int $pid, int $signal): bool
    {
        if ($signaller->send($pid, $signal)) {
            return true;
        }

        $this->components->error("Failed to signal process {$pid}: ".$signaller->lastError());

        return false;
    }
}
