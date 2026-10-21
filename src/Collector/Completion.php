<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Enums\JobStatus;

final readonly class Completion
{
    public function __construct(
        public string $connection,
        public string $queue,
        public string $name,
        public JobStatus $status,
        public ?int $waitMs,
        public ?int $runMs,
    ) {}
}
