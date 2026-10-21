<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Queues\Batches;
use Belvedere\Queues\FailedJob;
use Belvedere\Queues\FailedJobs;
use Belvedere\Queues\JobActions;
use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BatchesController extends Controller
{
    private const FAILED_JOBS_SHOWN = 100;

    public function index(Request $request, Batches $batches): JsonResponse
    {
        $list = $batches->list($this->limit($request), $this->optionalString($request, 'before'));

        return new JsonResponse([
            'batches' => $list->map(static fn (Batch $batch): array => $batches->toArray($batch))->values(),
            'next_before' => $list->last()?->id,
        ]);
    }

    public function show(Batches $batches, FailedJobs $failed, string $id): JsonResponse
    {
        $batch = $batches->find($id) ?? abort(404);

        $failedJobs = $failed->findByUuids(array_values(array_slice($batch->failedJobIds, 0, self::FAILED_JOBS_SHOWN)));

        return new JsonResponse([
            'batch' => $batches->toArray($batch),
            'failed_jobs' => $failedJobs->map(static fn (FailedJob $job): array => $job->toArray())->values(),
        ]);
    }

    public function cancel(Request $request, JobActions $actions, string $id): JsonResponse
    {
        abort_unless($actions->cancelBatch($id, $this->actor($request)), 404);

        return new JsonResponse(['cancelled' => true]);
    }

    public function retry(Request $request, JobActions $actions, string $id): JsonResponse
    {
        return new JsonResponse(['retried' => $actions->retryBatch($id, $this->actor($request))]);
    }
}
