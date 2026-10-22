<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\MasterState;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\Repositories\MasterRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'belvedere:pause')]
final class PauseCommand extends Command
{
    use SignalsLocalProcesses;

    protected $signature = 'belvedere:pause';

    protected $description = 'Pause the master supervisors of this machine: their workers stop taking jobs';

    public function handle(MasterRepository $masters, ProcessSignaller $signaller): int
    {
        return $this->signal(
            $signaller,
            $this->localMasters($masters)->map(static fn (MasterState $master): int => $master->pid),
            SIGUSR2,
            'SIGUSR2',
            'No master supervisor to pause on this machine.',
        );
    }
}
