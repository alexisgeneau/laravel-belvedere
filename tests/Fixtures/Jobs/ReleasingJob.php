<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class ReleasingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        if ($this->attempts() < 2) {
            $this->release(0);
        }
    }
}
