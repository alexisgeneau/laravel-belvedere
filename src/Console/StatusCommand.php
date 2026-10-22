<?php

declare(strict_types=1);

namespace Belvedere\Console;

use Belvedere\Models\Worker;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\QueueCounts;
use Belvedere\Supervision\SupervisionStatus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class StatusCommand extends Command
{
    protected $signature = 'belvedere:status';

    protected $description = 'Display the state of every queue and worker observed by Belvedere, and of its supervisors (exit code 0 running, 1 paused, 2 inactive)';

    public function handle(NativeQueue $native, Repository $config, SupervisionStatus $supervision): int
    {
        $deadAfter = (int) $config->get('belvedere.workers.dead_after_seconds', 90);
        $rows = [];

        foreach ($native->connections() as $connection) {
            foreach ($native->counts($connection) as $counts) {
                $rows[] = $this->row($counts);
            }
        }

        $this->table(['Connection', 'Queue', 'Pending', 'Delayed', 'Running', 'Stuck', 'Paused'], $rows);

        $workers = Worker::query()->get();

        $this->components->twoColumnDetail('Workers alive', (string) $workers->reject(fn (Worker $worker): bool => $worker->isDead($deadAfter))->count());
        $this->components->twoColumnDetail('Workers dead', (string) $workers->filter(fn (Worker $worker): bool => $worker->isDead($deadAfter))->count());

        return $this->supervision($supervision);
    }

    private function supervision(SupervisionStatus $supervision): int
    {
        $status = $supervision->overall();

        if ($status === SupervisionStatus::NONE) {
            $this->components->twoColumnDetail('Supervisor', 'not used (workers started by hand)');

            return self::SUCCESS;
        }

        foreach ($supervision->masters() as $master) {
            $this->components->twoColumnDetail(
                "Master {$master['name']} on {$master['hostname']}",
                "{$master['status']}, {$master['processes']} processes, ".count($master['supervisors']).' supervisors',
            );
        }

        return match ($status) {
            SupervisionStatus::RUNNING => $this->report('Belvedere is running.', self::SUCCESS),
            SupervisionStatus::PAUSED => $this->report('Belvedere is paused.', 1),
            default => $this->report('Belvedere is inactive: no master supervisor has reported recently.', 2),
        };
    }

    private function report(string $message, int $code): int
    {
        match ($code) {
            self::SUCCESS => $this->components->info($message),
            1 => $this->components->warn($message),
            default => $this->components->error($message),
        };

        return $code;
    }

    /** @return list<string> */
    private function row(QueueCounts $counts): array
    {
        return [
            $counts->connection,
            $counts->queue,
            (string) $counts->pending,
            (string) $counts->delayed,
            (string) $counts->running,
            (string) $counts->stuck,
            $counts->paused ? 'yes' : 'no',
        ];
    }
}
