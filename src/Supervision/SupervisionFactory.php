<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Belvedere\Supervision\Repositories\CommandQueue;
use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Process\Process;

/** Builds masters and supervisors with the configuration and the processes they start. */
final readonly class SupervisionFactory
{
    public function __construct(
        private MasterRepository $masters,
        private SupervisorRepository $supervisors,
        private CommandQueue $commands,
        private AutoScaler $scaler,
        private SystemProcesses $system,
        private ExceptionHandler $exceptions,
        private Repository $config,
        private string $directory,
    ) {}

    public function master(?string $environment): MasterSupervisor
    {
        return new MasterSupervisor(
            $environment,
            $this->masters,
            $this->supervisors,
            $this->commands,
            $this->exceptions,
            fn (SupervisorOptions $options): Process => $this->process(CommandStrings::supervisor($options), $options),
            $this->heartbeatSeconds(),
            max(1, (int) $this->config->get('belvedere.supervisor.memory_limit', 64)),
        );
    }

    public function supervisor(SupervisorOptions $options): Supervisor
    {
        return new Supervisor(
            $options,
            $this->supervisors,
            $this->commands,
            $this->scaler,
            $this->system,
            $this->exceptions,
            fn (SupervisorOptions $options): WorkerProcess => new WorkerProcess($this->process(CommandStrings::worker($options), $options)),
            $this->heartbeatSeconds(),
        );
    }

    public function plan(string $master): ProvisioningPlan
    {
        return ProvisioningPlan::fromConfig($this->config, $master, $this->directory);
    }

    public function heartbeatSeconds(): int
    {
        return max(1, (int) $this->config->get('belvedere.supervisor.heartbeat_seconds', 5));
    }

    private function process(string $command, SupervisorOptions $options): Process
    {
        return Process::fromShellCommandline($command, $options->directory ?? $this->directory)
            ->setTimeout(null)
            ->disableOutput();
    }
}
