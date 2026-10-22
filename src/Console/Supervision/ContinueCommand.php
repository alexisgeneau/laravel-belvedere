<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\MasterState;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\Repositories\MasterRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'belvedere:continue')]
final class ContinueCommand extends Command
{
    use SignalsLocalProcesses;

    protected $signature = 'belvedere:continue';

    protected $description = 'Resume the paused master supervisors of this machine';

    public function handle(MasterRepository $masters, ProcessSignaller $signaller): int
    {
        return $this->signal(
            $signaller,
            $this->localMasters($masters)->map(static fn (MasterState $master): int => $master->pid),
            SIGCONT,
            'SIGCONT',
            'No master supervisor to resume on this machine.',
        );
    }
}
