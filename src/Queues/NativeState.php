<?php

declare(strict_types=1);

namespace Belvedere\Queues;

enum NativeState: string
{
    case Pending = 'pending';
    case Delayed = 'delayed';
    case Running = 'running';
    case Stuck = 'stuck';
}
