<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Queues\JobActions;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\QueueCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QueuesController extends Controller
{
    public function index(Request $request, NativeQueue $native): JsonResponse
    {
        $connection = $this->connection($request, $native);

        return new JsonResponse([
            'connection' => $connection,
            'queues' => $native->counts($connection)->map(static fn (QueueCounts $counts): array => $counts->toArray())->values(),
        ]);
    }

    public function pause(Request $request, JobActions $actions, string $connection, string $queue): JsonResponse
    {
        $actions->pause($connection, $queue, $this->actor($request));

        return new JsonResponse(['paused' => true]);
    }

    public function resume(Request $request, JobActions $actions, string $connection, string $queue): JsonResponse
    {
        $actions->resume($connection, $queue, $this->actor($request));

        return new JsonResponse(['paused' => false]);
    }

    public function purge(Request $request, JobActions $actions, string $connection, string $queue): JsonResponse
    {
        return new JsonResponse(['purged' => $actions->purge($connection, $queue, $this->actor($request))]);
    }
}
