<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Enums\JobStatus;
use Belvedere\Enums\WorkerStatus;
use Belvedere\Metrics\MetricsReader;
use Belvedere\Models\Job;
use Belvedere\Models\Worker;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\QueueCounts;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class OverviewController extends Controller
{
    public function __invoke(Request $request, NativeQueue $native, MetricsReader $metrics, Repository $config): JsonResponse
    {
        $connection = $this->connection($request, $native);
        $deadAfter = (int) $config->get('belvedere.workers.dead_after_seconds', 90);
        $recent = $metrics->queueTotals($connection, Carbon::now()->subHour());
        $daily = $metrics->queueTotals($connection, Carbon::now()->subDay());

        $queues = $native->counts($connection)->map(function (QueueCounts $counts) use ($recent, $daily): array {
            $hour = $recent->get($counts->queue);
            $day = $daily->get($counts->queue);

            return [
                ...$counts->toArray(),
                'processed_1h' => (int) ($hour->processed ?? 0),
                'failed_1h' => (int) ($hour->failed ?? 0),
                'failed_24h' => (int) ($day->failed ?? 0),
                'jobs_per_minute' => round((int) ($hour->processed ?? 0) / 60, 2),
                'wait_ms_avg_1h' => $this->average($hour?->wait_ms_sum, $hour?->total),
                'run_ms_avg_1h' => $this->average($hour?->run_ms_sum, $hour?->total),
                'failure_rate_1h' => $this->rate($hour?->failed, $hour?->total),
            ];
        })->values();

        $workers = Worker::query()->where('connection', $connection)->get();

        $lost = Job::query()
            ->where('status', JobStatus::Lost->value)
            ->where('finished_at', '>=', Carbon::now()->subDay())
            ->where('connection', $connection)
            ->count();

        return new JsonResponse([
            'connection' => $connection,
            'connections' => $native->connections(),
            'supports_database_inspection' => $native->supports($connection),
            'queues' => $queues,
            'workers' => [
                'alive' => $workers->filter(static fn (Worker $worker): bool => in_array($worker->effectiveStatus($deadAfter), [WorkerStatus::Running, WorkerStatus::Paused], true))->count(),
                'dead' => $workers->filter(static fn (Worker $worker): bool => $worker->isDead($deadAfter))->count(),
            ],
            'lost_24h' => $lost,
            'generated_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    private function average(mixed $sum, mixed $count): ?int
    {
        return (int) $count > 0 ? (int) round((int) $sum / (int) $count) : null;
    }

    private function rate(mixed $failed, mixed $total): ?float
    {
        return (int) $total > 0 ? round((int) $failed / (int) $total * 100, 1) : null;
    }
}
