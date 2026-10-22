<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Models\Job;
use Belvedere\Queues\FailedJob;
use Belvedere\Queues\FailedJobs;
use Belvedere\Queues\JobActions;
use Belvedere\Queues\NativeQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FailedJobsController extends Controller
{
    public function index(Request $request, NativeQueue $native, FailedJobs $failed): JsonResponse
    {
        $connection = $this->connection($request, $native);

        $jobs = $failed->list(
            $connection,
            $this->optionalString($request, 'queue'),
            $this->optionalString($request, 'name'),
            $this->optionalString($request, 'search'),
            $this->limit($request),
            $this->optionalInt($request, 'before'),
        );

        $attempts = Job::query()
            ->selectRaw('uuid, max(attempt) as attempts')
            ->whereIn('uuid', $jobs->map(static fn (FailedJob $job): ?string => $job->uuid)->filter(static fn (?string $uuid): bool => $uuid !== null && Str::isUuid($uuid))->all())
            ->groupBy('uuid')
            ->pluck('attempts', 'uuid');

        return new JsonResponse([
            'total' => $failed->count($connection),
            'jobs' => $jobs->map(static fn (FailedJob $job): array => [
                ...$job->toArray(),
                'attempts' => $job->uuid !== null ? (int) ($attempts[$job->uuid] ?? 0) : null,
            ])->values(),
            'next_before' => $jobs->last()?->rowId,
        ]);
    }

    public function show(FailedJobs $failed, string $id): JsonResponse
    {
        $job = $failed->find($id) ?? abort(404);

        $attempts = $job->uuid !== null && Str::isUuid($job->uuid)
            ? (int) Job::query()->forUuid($job->uuid)->max('attempt')
            : null;

        return new JsonResponse([
            'job' => [
                ...$job->toArray(),
                'exception' => $job->exception,
                'snapshot' => $job->snapshot,
                'attempts' => $attempts,
            ],
        ]);
    }

    public function retry(Request $request, JobActions $actions, string $id): JsonResponse
    {
        abort_unless($actions->retry($id, $this->actor($request)), 404);

        return new JsonResponse(['retried' => 1]);
    }

    public function retryMany(Request $request, NativeQueue $native, FailedJobs $failed, JobActions $actions): JsonResponse
    {
        $ids = $request->input('ids');

        if (! is_array($ids)) {
            $ids = $failed->ids(
                $this->connection($request, $native),
                $this->optionalString($request, 'queue'),
                $this->optionalString($request, 'name'),
                $this->optionalString($request, 'search'),
            );
        }

        return new JsonResponse(['retried' => $actions->retryMany($ids, $this->actor($request))]);
    }

    public function destroy(Request $request, JobActions $actions, string $id): JsonResponse
    {
        abort_unless($actions->forget($id, $this->actor($request)), 404);

        return new JsonResponse(['deleted' => true]);
    }

    public function flush(Request $request, JobActions $actions): JsonResponse
    {
        $actions->flush($this->actor($request));

        return new JsonResponse(['flushed' => true]);
    }
}
