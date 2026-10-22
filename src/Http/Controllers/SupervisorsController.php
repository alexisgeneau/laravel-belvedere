<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Supervision\SupervisionStatus;
use Belvedere\Supervision\SupervisorActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SupervisorsController extends Controller
{
    public function index(SupervisionStatus $status): JsonResponse
    {
        return new JsonResponse([
            'status' => $status->overall(),
            'processes' => $status->totalProcesses(),
            'masters' => $status->masters(),
        ]);
    }

    public function pauseMaster(Request $request, SupervisorActions $actions, string $master): JsonResponse
    {
        abort_unless($actions->pauseMaster($master, $this->actor($request)), 404, "No running master supervisor named [{$master}].");

        return new JsonResponse(['paused' => true]);
    }

    public function continueMaster(Request $request, SupervisorActions $actions, string $master): JsonResponse
    {
        abort_unless($actions->continueMaster($master, $this->actor($request)), 404, "No running master supervisor named [{$master}].");

        return new JsonResponse(['paused' => false]);
    }

    public function pauseSupervisor(Request $request, SupervisorActions $actions, string $master, string $supervisor): JsonResponse
    {
        abort_unless($actions->pauseSupervisor($master, $supervisor, $this->actor($request)), 404, "No running supervisor named [{$master}:{$supervisor}].");

        return new JsonResponse(['paused' => true]);
    }

    public function continueSupervisor(Request $request, SupervisorActions $actions, string $master, string $supervisor): JsonResponse
    {
        abort_unless($actions->continueSupervisor($master, $supervisor, $this->actor($request)), 404, "No running supervisor named [{$master}:{$supervisor}].");

        return new JsonResponse(['paused' => false]);
    }
}
