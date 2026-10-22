<?php

declare(strict_types=1);

namespace Belvedere\Supervision\Events;

use Belvedere\Supervision\WorkerProcess;

/** A process died within a minute of its launch; the next launch waits a minute. */
final readonly class UnableToLaunchProcess
{
    public function __construct(public WorkerProcess $process) {}
}
