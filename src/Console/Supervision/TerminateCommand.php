<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\MasterState;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\Repositories\MasterRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\InteractsWithTime;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'belvedere:terminate')]
final class TerminateCommand extends Command
{
    use InteractsWithTime;
    use SignalsLocalProcesses;

    protected $signature = 'belvedere:terminate {--wait : Reserved for fast termination; the master always waits for its workers in this version}';

    protected $description = 'Terminate the master supervisors of this machine once their workers have finished, so the process monitor restarts them';

    public function handle(MasterRepository $masters, ProcessSignaller $signaller, Cache $cache, Repository $config): int
    {
        $result = $this->signal(
            $signaller,
            $this->localMasters($masters)->map(static fn (MasterState $master): int => $master->pid),
            SIGTERM,
            'SIGTERM',
            'No master supervisor to terminate on this machine.',
        );

        // The workers started by hand restart too, as after queue:restart.
        $cache->store($config->get('belvedere.cache_store'))->forever('illuminate:queue:restart', $this->currentTime());

        return $result;
    }
}
