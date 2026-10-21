<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

final class ProcessPayment implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $orderId,
        public int $amountCents,
    ) {}

    /** @return list<string> */
    public function tags(): array
    {
        return ['order:'.$this->orderId, 'payments'];
    }

    public function handle(): void
    {
        throw new RuntimeException('Payment gateway timed out for order '.$this->orderId);
    }
}
