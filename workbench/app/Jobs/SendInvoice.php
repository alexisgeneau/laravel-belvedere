<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class SendInvoice implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $invoiceId,
        public string $customerEmail,
    ) {}

    /** @return list<string> */
    public function tags(): array
    {
        return ['invoice:'.$this->invoiceId, 'billing'];
    }

    public function handle(): void
    {
        usleep(random_int(5_000, 60_000));
    }
}
