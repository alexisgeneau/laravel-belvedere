<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Jobs;

use Belvedere\Tests\Fixtures\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SucceedingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $label = 'ok',
        public ?Product $product = null,
    ) {}

    public function handle(): void {}
}
