<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'belvedere:pause-supervisor')]
final class PauseSupervisorCommand extends Command
{
    use SignalsLocalProcesses;

    protected $signature = 'belvedere:pause-supervisor {name : The supervisor, by its configuration key}';

    protected $description = 'Pause one supervisor of this machine';

    public function handle(SupervisorRepository $supervisors, ProcessSignaller $signaller): int
    {
        $supervisor = $this->localSupervisor($supervisors, (string) $this->argument('name'));

        if ($supervisor === null) {
            $this->components->error('No running supervisor named ['.$this->argument('name').'] on this machine.');

            return self::FAILURE;
        }

        return $this->signal($signaller, new Collection([$supervisor->pid]), SIGUSR2, 'SIGUSR2', '');
    }
}
