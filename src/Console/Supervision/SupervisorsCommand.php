<?php

declare(strict_types=1);

namespace Belvedere\Console\Supervision;

use Belvedere\Supervision\SupervisionStatus;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'belvedere:supervisors')]
final class SupervisorsCommand extends Command
{
    protected $signature = 'belvedere:supervisors';

    protected $description = 'List the master supervisors seen in the last day and their supervisors';

    public function handle(SupervisionStatus $status): int
    {
        $masters = $status->masters();

        if ($masters->isEmpty()) {
            $this->components->info('No master supervisor has reported in the last 24 hours.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($masters as $master) {
            foreach ($master['supervisors'] as $supervisor) {
                $rows[] = [
                    $master['name'].' ('.$master['hostname'].')',
                    $master['status'],
                    $supervisor['name'],
                    $supervisor['status'],
                    $this->processes($supervisor['processes_by_queue']),
                    $supervisor['balance'] === false ? 'false' : (string) $supervisor['balance'],
                    $supervisor['min_processes'].' to '.$supervisor['max_processes'].($supervisor['at_max'] ? ' (at max)' : ''),
                ];
            }

            if ($master['supervisors'] === []) {
                $rows[] = [$master['name'].' ('.$master['hostname'].')', $master['status'], '-', '-', '-', '-', '-'];
            }
        }

        $this->table(['Master', 'Status', 'Supervisor', 'Status', 'Processes', 'Balance', 'Range'], $rows);

        return self::SUCCESS;
    }

    /** @param  array<string, int>  $processes */
    private function processes(array $processes): string
    {
        $parts = [];

        foreach ($processes as $queue => $count) {
            $parts[] = substr((string) $queue, (int) strpos((string) $queue, ':') + 1).' '.$count;
        }

        return $parts === [] ? '0' : implode(', ', $parts);
    }
}
