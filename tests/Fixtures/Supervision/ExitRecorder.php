<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Supervision;

/** Stands in for exit() in the masters and supervisors under test. */
final class ExitRecorder
{
    /** @var list<int> */
    public array $statuses = [];

    public function __invoke(int $status): void
    {
        $this->statuses[] = $status;
    }
}
