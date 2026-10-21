<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Queues\JobActions;
use Belvedere\Queues\NativeJob;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\NativeState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeJobsController extends Controller
{
    public function index(Request $request, NativeQueue $native, string $state): JsonResponse
    {
        $connection = $this->connection($request, $native);
        $nativeState = NativeState::tryFrom($state) ?? abort(404);

        abort_unless($native->supports($connection), 409, "Queue connection [{$connection}] does not expose pending jobs.");

        $jobs = $native->jobs(
            $connection,
            $nativeState,
            $this->optionalString($request, 'queue'),
            $this->limit($request),
            $this->optionalInt($request, 'before'),
        );

        return new JsonResponse([
            'jobs' => $jobs->map(static fn (NativeJob $job): array => $job->toArray())->values(),
            'next_before' => $jobs->last()?->id,
        ]);
    }

    public function destroy(Request $request, JobActions $actions, string $connection, int $id): JsonResponse
    {
        abort_unless($actions->delete($connection, $id, $this->actor($request)), 404);

        return new JsonResponse(['deleted' => true]);
    }

    public function runNow(Request $request, JobActions $actions, string $connection, int $id): JsonResponse
    {
        abort_unless($actions->runNow($connection, $id, $this->actor($request)), 404);

        return new JsonResponse(['scheduled' => true]);
    }

    public function release(Request $request, JobActions $actions, string $connection, int $id): JsonResponse
    {
        abort_unless($actions->release($connection, $id, $this->actor($request)), 404);

        return new JsonResponse(['released' => true]);
    }
}
