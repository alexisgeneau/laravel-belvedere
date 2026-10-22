<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Enums\JobStatus;
use Belvedere\Models\Job;
use Belvedere\Models\Worker;
use Belvedere\Queues\JobActions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkersController extends Controller
{
    public function index(Request $request, Repository $config): JsonResponse
    {
        $deadAfter = (int) $config->get('belvedere.workers.dead_after_seconds', 90);
        $supervisor = $this->optionalString($request, 'supervisor');

        $workers = Worker::query()
            ->when($supervisor !== null, static fn ($query) => $query->where('supervisor', $supervisor))
            ->orderBy('hostname')
            ->orderByRaw('case when supervisor is null then 1 else 0 end')
            ->orderBy('supervisor')
            ->orderBy('queues')
            ->orderBy('pid')
            ->get();

        $current = Job::query()
            ->where('status', JobStatus::Running->value)
            ->whereIn('worker_id', $workers->modelKeys())
            ->orderByDesc('started_at')
            ->get()
            ->unique('worker_id')
            ->keyBy('worker_id');

        return new JsonResponse([
            'workers' => $workers->map(static function (Worker $worker) use ($current, $deadAfter): array {
                $job = $current->get($worker->id);

                return [
                    'id' => $worker->id,
                    'hostname' => $worker->hostname,
                    'pid' => $worker->pid,
                    'connection' => $worker->connection,
                    'queues' => $worker->queueNames(),
                    'supervisor' => $worker->supervisor,
                    'status' => $worker->effectiveStatus($deadAfter)->value,
                    'jobs_processed' => $worker->jobs_processed,
                    'memory_kb' => $worker->memory_kb,
                    'started_at' => $worker->started_at->toIso8601String(),
                    'last_seen_at' => $worker->last_seen_at->toIso8601String(),
                    'current_job' => $job !== null ? [
                        'uuid' => $job->uuid,
                        'name' => $job->name,
                        'queue' => $job->queue,
                        'started_at' => $job->started_at?->toIso8601String(),
                    ] : null,
                ];
            })->values(),
        ]);
    }

    public function destroy(Request $request, JobActions $actions, string $id): JsonResponse
    {
        abort_unless($actions->forgetWorker($id, $this->actor($request)), 404);

        return new JsonResponse(['deleted' => true]);
    }
}
