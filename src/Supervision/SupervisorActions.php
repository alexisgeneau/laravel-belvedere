<?php

declare(strict_types=1);

namespace Belvedere\Supervision;

use Belvedere\Enums\ActionType;
use Belvedere\Models\Action;
use Belvedere\Supervision\Repositories\CommandQueue;
use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Illuminate\Support\Carbon;

/**
 * The orders the dashboard gives to a master or a supervisor, wherever it runs:
 * a row in the commands table, read at its next heartbeat, and a line in the
 * actions log like every other action.
 */
final readonly class SupervisorActions
{
    public function __construct(
        private MasterRepository $masters,
        private SupervisorRepository $supervisors,
        private CommandQueue $commands,
    ) {}

    public function pauseMaster(string $master, ?string $actor = null): bool
    {
        return $this->order($master, $this->masters->find($master) !== null, CommandQueue::PAUSE, ActionType::PauseSupervisor, $actor);
    }

    public function continueMaster(string $master, ?string $actor = null): bool
    {
        return $this->order($master, $this->masters->find($master) !== null, CommandQueue::CONTINUE, ActionType::ContinueSupervisor, $actor);
    }

    public function pauseSupervisor(string $master, string $supervisor, ?string $actor = null): bool
    {
        $name = "{$master}:{$supervisor}";

        return $this->order($name, $this->supervisors->find($name) !== null, CommandQueue::PAUSE, ActionType::PauseSupervisor, $actor);
    }

    public function continueSupervisor(string $master, string $supervisor, ?string $actor = null): bool
    {
        $name = "{$master}:{$supervisor}";

        return $this->order($name, $this->supervisors->find($name) !== null, CommandQueue::CONTINUE, ActionType::ContinueSupervisor, $actor);
    }

    private function order(string $target, bool $alive, string $command, ActionType $action, ?string $actor): bool
    {
        if (! $alive) {
            return false;
        }

        $this->commands->push($target, $command);

        Action::query()->create([
            'action' => $action,
            'subject' => mb_substr($target, 0, 255),
            'actor' => $actor !== null ? mb_substr($actor, 0, 255) : null,
            'created_at' => Carbon::now(),
        ]);

        return true;
    }
}
