<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class GenerateReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $tenantId,
        public string $format = 'pdf',
    ) {}

    /** @return list<string> */
    public function tags(): array
    {
        return ['tenant:'.$this->tenantId, 'reports'];
    }

    public function handle(): void
    {
        usleep(random_int(5_000, 60_000));
    }
}
