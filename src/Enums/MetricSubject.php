<?php

declare(strict_types=1);

namespace Belvedere\Enums;

enum MetricSubject: string
{
    case Queue = 'queue';
    case Job = 'job';
}
