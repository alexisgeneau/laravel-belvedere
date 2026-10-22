<?php

declare(strict_types=1);

namespace Belvedere\Console;

use Belvedere\Enums\JobStatus;
use Belvedere\Support\Storage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

final class PruneCommand extends Command
{
    private const CHUNK = 1000;

    protected $signature = 'belvedere:prune {--hours= : Override every retention period with the same number of hours}';

    protected $description = 'Delete Belvedere records older than the configured retention periods';

    public function handle(Storage $storage, Repository $config): int
    {
        $override = $this->option('hours') !== null ? max(1, (int) $this->option('hours')) : null;
        $hours = fn (string $key, int $default): int => $override ?? (int) $config->get("belvedere.retention.{$key}", $default);

        $completed = [JobStatus::Completed->value, JobStatus::Released->value, JobStatus::TimedOut->value, JobStatus::Lost->value];

        $deleted = [
            'completed jobs' => $this->pruneJobs(
                $storage,
                $storage->query('jobs')->whereIn('status', $completed),
                $hours('completed_hours', 168),
            ),
            'failed jobs' => $this->pruneJobs(
                $storage,
                $storage->query('jobs')->where('status', JobStatus::Failed->value),
                $hours('failed_hours', 720),
            ),
            'metrics' => $this->prune($storage->query('metrics'), 'bucket', $hours('metrics_hours', 168)),
            'actions' => $this->prune($storage->query('actions'), 'created_at', $hours('actions_hours', 720)),
            // A worker not seen for that long is gone, whatever status it last reported.
            'workers' => $this->prune($storage->query('workers'), 'last_seen_at', $hours('workers_hours', 24)),
        ];

        if ($storage->hasSupervisionTables()) {
            $deleted += [
                'supervisors' => $this->prune($storage->query('supervisors'), 'last_seen_at', $hours('workers_hours', 24)),
                'masters' => $this->prune($storage->query('masters'), 'last_seen_at', $hours('workers_hours', 24)),
                // Orders for a supervisor that never came to read them.
                'supervisor commands' => $this->prune($storage->query('commands'), 'created_at', 1),
            ];
        }

        foreach ($deleted as $label => $count) {
            $this->components->twoColumnDetail(ucfirst($label), (string) $count);
        }

        return self::SUCCESS;
    }

    private function pruneJobs(Storage $storage, Builder $query, int $hours): int
    {
        $before = Carbon::now()->subHours($hours);
        $total = 0;

        do {
            $ids = (clone $query)->where('finished_at', '<', $before)->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            // One transaction per chunk: the tags go with their executions, and the chunk costs
            // a single commit (on SQLite, every autocommit statement is a sync to disk).
            $total += (int) $storage->connection()->transaction(function () use ($storage, $ids): int {
                $storage->query('job_tags')->whereIn('job_id', $ids)->delete();

                return $storage->query('jobs')->whereIn('id', $ids)->delete();
            });
        } while (count($ids) === self::CHUNK);

        return $total;
    }

    private function prune(Builder $query, string $column, int $hours): int
    {
        $before = Carbon::now()->subHours($hours);
        $total = 0;

        do {
            $deleted = (clone $query)->where($column, '<', $before)->limit(self::CHUNK)->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK);

        return $total;
    }
}
