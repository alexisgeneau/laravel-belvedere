<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Supervision;

use Belvedere\Supervision\ProcessSignaller;

/** Records the signals the commands send instead of sending them. */
final class SignalRecorder
{
    /** @var list<array{0: int, 1: int}> */
    public array $sent = [];

    public function signaller(): ProcessSignaller
    {
        return new ProcessSignaller(function (int $pid, int $signal): bool {
            $this->sent[] = [$pid, $signal];

            return true;
        });
    }
}
