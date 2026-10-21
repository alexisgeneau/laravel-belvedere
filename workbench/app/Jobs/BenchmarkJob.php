<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class BenchmarkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public int $orderId,
        public string $reference,
    ) {}

    /** @return list<string> */
    public function tags(): array
    {
        return ['order:'.$this->orderId];
    }

    public function handle(): void {}
}
