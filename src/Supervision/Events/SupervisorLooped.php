<?php

declare(strict_types=1);

namespace Belvedere\Supervision\Events;

use Belvedere\Supervision\Supervisor;

final readonly class SupervisorLooped
{
    public function __construct(public Supervisor $supervisor) {}
}
