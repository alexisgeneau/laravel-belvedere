<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

final class BatchedJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public bool $fails = false) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->fails) {
            throw new RuntimeException('batched boom');
        }
    }
}
