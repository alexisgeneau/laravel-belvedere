<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\InvalidSupervisorConfiguration;
use Belvedere\Supervision\MasterState;
use Belvedere\Supervision\MasterSupervisor;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\SupervisionFactory;
use Belvedere\Supervision\SupervisorOptions;
use Belvedere\Supervision\SystemProcesses;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'belvedere')]
final class BelvedereCommand extends Command
{
    protected $signature = 'belvedere {--environment= : The environment whose supervisors to start (APP_ENV by default)}';

    protected $description = 'Start a master supervisor in the foreground: it starts, balances and restarts the queue workers of this machine';

    public function handle(SupervisionFactory $factory, MasterRepository $masters, SystemProcesses $system, Repository $config): int
    {
        if (($unsupported = ProcessSignaller::unsupported()) !== null) {
            $this->components->error($unsupported);

            return self::FAILURE;
        }

        if (($running = $this->runningMaster($masters, $system)) !== null) {
            $this->components->warn("A master supervisor is already running on this machine as [{$running->name}] (pid {$running->pid}).");

            return self::FAILURE;
        }

        $environment = (string) ($this->option('environment') ?: $config->get('app.env', 'production'));

        try {
            $plan = $factory->plan(MasterSupervisor::name());
            $supervisors = $plan->forEnvironment($environment);
        } catch (InvalidSupervisorConfiguration $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($plan->warnings($environment) as $warning) {
            $this->components->warn($warning);
        }

        if ($supervisors === []) {
            $this->components->warn("No supervisor is configured for the [{$environment}] environment (belvedere.supervisor.environments). Nothing to start.");
        }

        $master = $factory->master($environment)->handleOutputUsing(function (string $type, string $line): void {
            $this->output->write($line);
        });

        foreach ($supervisors as $options) {
            if ($options->maxProcesses > 0) {
                $master->provision($options);
            }
        }

        $this->components->info(sprintf(
            'Belvedere started as [%s] with %s.',
            $master->name,
            $this->describe($supervisors),
        ));

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use ($master): void {
            $this->output->writeln('');
            $this->components->info('Shutting down.');

            $master->terminate();
        });

        $master->monitor();

        return self::SUCCESS;
    }

    /**
     * One master per machine. A row whose process is gone was left by a master that
     * died without a word: it is forgotten rather than waited for.
     */
    private function runningMaster(MasterRepository $masters, SystemProcesses $system): ?MasterState
    {
        $prefix = MasterSupervisor::basename().'-';

        foreach ($masters->all() as $master) {
            if (! str_starts_with($master->name, $prefix)) {
                continue;
            }

            if ($system->isAlive($master->pid)) {
                return $master;
            }

            $masters->forget($master->name);
        }

        return null;
    }

    /** @param  array<string, SupervisorOptions>  $supervisors */
    private function describe(array $supervisors): string
    {
        $started = array_filter($supervisors, static fn (SupervisorOptions $options): bool => $options->maxProcesses > 0);

        if ($started === []) {
            return 'no supervisor';
        }

        return count($started).' supervisor'.(count($started) > 1 ? 's' : '').' ('.implode(', ', array_map(
            static fn (SupervisorOptions $options): string => $options->shortName().': '.$options->queue.', up to '.$options->maxProcesses,
            $started,
        )).')';
    }
}
