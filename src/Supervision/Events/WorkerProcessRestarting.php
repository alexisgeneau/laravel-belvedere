<?php

declare(strict_types=1);

namespace Belvedere\Supervision\Events;

use Belvedere\Supervision\WorkerProcess;

final readonly class WorkerProcessRestarting
{
    public function __construct(public WorkerProcess $process) {}
}
