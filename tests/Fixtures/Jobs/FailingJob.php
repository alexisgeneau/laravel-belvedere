<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

final class FailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}
