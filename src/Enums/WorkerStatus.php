<?php

declare(strict_types=1);

namespace Belvedere\Enums;

enum WorkerStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case Dead = 'dead';
}
