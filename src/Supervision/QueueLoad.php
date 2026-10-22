<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Belvedere\Support\Storage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * What the auto-scaler needs to know about a queue, read where Belvedere already
 * reads it: the jobs ready to run in the `jobs` table, the average runtime in
 * `belvedere_metrics`. Redis answers the same questions for Horizon.
 */
class QueueLoad
{
    private const RUNTIME_WINDOW_MINUTES = 10;

    private const RUNTIME_CACHE_SECONDS = 60;

    /** @var array<string, array{until: float, runtimes: array<string, float>}> */
    private array $runtimes = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly Storage $storage,
    ) {}

    /**
     * @param  list<string>  $queues
     * @return array<string, int> queue => jobs ready to run now
     */
    public function ready(string $connection, array $queues): array
    {
        if ($queues === []) {
            return [];
        }

        $database = $this->config->get("queue.connections.{$connection}.connection");
        $table = (string) $this->config->get("queue.connections.{$connection}.table", 'jobs');

        $rows = $this->db->connection($database)->table($table)
            ->selectRaw('queue, count(*) as ready')
            ->whereIn('queue', $queues)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', Carbon::now()->getTimestamp())
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $ready = [];

        foreach ($queues as $queue) {
            $ready[$queue] = (int) ($rows->get($queue)->ready ?? 0);
        }

        return $ready;
    }

    /**
     * @param  list<string>  $queues
     * @return array<string, float> queue => average runtime of the last minutes, in milliseconds (0 when unknown)
     */
    public function runtimes(string $connection, array $queues): array
    {
        $cached = $this->runtimes[$connection] ?? null;

        if ($cached === null || $cached['until'] < microtime(true)) {
            $rows = $this->storage->query('metrics')
                ->selectRaw('subject, sum(run_ms_sum) as run_ms_sum, sum(processed) + sum(failed) + sum(released) as total')
                ->where('connection', $connection)
                ->where('subject_type', 'queue')
                ->where('bucket', '>=', Carbon::now()->subMinutes(self::RUNTIME_WINDOW_MINUTES))
                ->groupBy('subject')
                ->get();

            $runtimes = [];

            foreach ($rows as $row) {
                $runtimes[(string) $row->subject] = (int) $row->total > 0 ? (float) $row->run_ms_sum / (int) $row->total : 0.0;
            }

            $this->runtimes[$connection] = $cached = ['until' => microtime(true) + self::RUNTIME_CACHE_SECONDS, 'runtimes' => $runtimes];
        }

        $result = [];

        foreach ($queues as $queue) {
            $result[$queue] = $cached['runtimes'][$queue] ?? 0.0;
        }

        return $result;
    }
}
