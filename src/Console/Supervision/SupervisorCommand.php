<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\SupervisionFactory;
use Belvedere\Supervision\Supervisor;
use Belvedere\Supervision\SupervisorOptions;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/** Started by the master for every supervisor of the configuration; not meant to be run by hand. */
#[AsCommand(name: 'belvedere:supervisor')]
final class SupervisorCommand extends Command
{
    protected $signature = 'belvedere:supervisor
                            {name : The name of the supervisor}
                            {connection : The queue connection to work}
                            {--queue= : The queues to work, comma separated}
                            {--balance=auto : auto, simple or false}
                            {--auto-scaling-strategy=time : time or size}
                            {--min-processes=1 : The minimum number of workers per queue}
                            {--max-processes=1 : The maximum number of workers in total}
                            {--balance-max-shift=1 : Workers added or removed at most per balancing}
                            {--balance-cooldown=3 : Seconds between two balancings}
                            {--memory=128 : The memory limit of the workers and of the supervisor, in megabytes}
                            {--tries=1 : Attempts before a job is marked failed}
                            {--timeout=60 : Seconds a job may run}
                            {--backoff=0 : Seconds before retrying a job that threw}
                            {--max-time=0 : Seconds a worker runs before it exits}
                            {--max-jobs=0 : Jobs a worker processes before it exits}
                            {--sleep=3 : Seconds a worker sleeps when no job is available}
                            {--rest=0 : Seconds a worker rests between jobs}
                            {--nice=0 : The process priority}
                            {--parent-id=0 : The pid of the master}
                            {--force : Work even in maintenance mode}
                            {--paused : Start paused}';

    protected $description = 'Start a supervisor (the belvedere command does it for you)';

    protected $hidden = true;

    public function handle(SupervisionFactory $factory): int
    {
        if (($unsupported = ProcessSignaller::unsupported()) !== null) {
            $this->components->error($unsupported);

            return self::FAILURE;
        }

        $supervisor = $factory->supervisor($this->supervisorOptions());

        try {
            $supervisor->ensureNoDuplicateSupervisors();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return Supervisor::DUPLICATE_EXIT_CODE;
        }

        if ($supervisor->options->nice !== 0 && function_exists('proc_nice')) {
            proc_nice($supervisor->options->nice);
        }

        $supervisor->handleOutputUsing(function (string $type, string $line): void {
            $this->output->write($line);
        });

        $supervisor->working = ! $this->option('paused');

        // Half way between the minimum and the maximum to start with, minus the workers a
        // previous life of this supervisor may still be running on the machine.
        $balanced = intdiv($supervisor->options->minProcesses + $supervisor->options->maxProcesses, 2);
        $supervisor->scale(max(0, $balanced - $supervisor->totalSystemProcessCount()));

        $supervisor->monitor();

        return self::SUCCESS;
    }

    private function supervisorOptions(): SupervisorOptions
    {
        return SupervisorOptions::fromArray([
            'name' => (string) $this->argument('name'),
            'connection' => (string) $this->argument('connection'),
            'queue' => (string) ($this->option('queue') ?: $this->laravel['config']->get('queue.connections.'.$this->argument('connection').'.queue', 'default')),
            'balance' => $this->option('balance'),
            'autoScalingStrategy' => (string) $this->option('auto-scaling-strategy'),
            'minProcesses' => (int) $this->option('min-processes'),
            'maxProcesses' => (int) $this->option('max-processes'),
            'balanceMaxShift' => (int) $this->option('balance-max-shift'),
            'balanceCooldown' => (int) $this->option('balance-cooldown'),
            'memory' => (int) $this->option('memory'),
            'tries' => (int) $this->option('tries'),
            'timeout' => (int) $this->option('timeout'),
            'backoff' => (string) $this->option('backoff'),
            'maxTime' => (int) $this->option('max-time'),
            'maxJobs' => (int) $this->option('max-jobs'),
            'sleep' => (int) $this->option('sleep'),
            'rest' => (int) $this->option('rest'),
            'nice' => (int) $this->option('nice'),
            'parentId' => (int) $this->option('parent-id'),
            'force' => (bool) $this->option('force'),
            'directory' => $this->laravel->basePath(),
        ]);
    }
}
