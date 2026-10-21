<?php

declare(strict_types=1);

namespace Belvedere\Console;

use Belvedere\Models\Worker;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\QueueCounts;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

final class StatusCommand extends Command
{
    protected $signature = 'belvedere:status';

    protected $description = 'Display the state of every queue and worker observed by Belvedere';

    public function handle(NativeQueue $native, Repository $config): int
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

        return self::SUCCESS;
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
