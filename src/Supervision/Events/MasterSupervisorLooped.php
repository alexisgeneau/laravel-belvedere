<?php

declare(strict_types=1);

namespace Belvedere\Supervision\Events;

use Belvedere\Supervision\MasterSupervisor;

final readonly class MasterSupervisorLooped
{
    public function __construct(public MasterSupervisor $master) {}
}
