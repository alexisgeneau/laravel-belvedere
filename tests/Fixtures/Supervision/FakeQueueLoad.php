<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Supervision;

use Belvedere\Supervision\QueueLoad;

/** The load the auto-scaler sees, set by the test instead of read from the database. */
final class FakeQueueLoad extends QueueLoad
{
    /**
     * @param  array<string, int>  $ready  queue => jobs ready to run
     * @param  array<string, float>  $runtimes  queue => average runtime in milliseconds
     */
    public function __construct(public array $ready = [], public array $runtimes = [])
    {
        // The parent reads the database; this one answers from memory.
    }

    public function ready(string $connection, array $queues): array
    {
        $ready = [];

        foreach ($queues as $queue) {
            $ready[$queue] = $this->ready[$queue] ?? 0;
        }

        return $ready;
    }

    public function runtimes(string $connection, array $queues): array
    {
        $runtimes = [];

        foreach ($queues as $queue) {
            $runtimes[$queue] = $this->runtimes[$queue] ?? 0.0;
        }

        return $runtimes;
    }
}
