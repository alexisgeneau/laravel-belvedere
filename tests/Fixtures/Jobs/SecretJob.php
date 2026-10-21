<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class SecretJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $password = 'hunter2',
        public string $apiToken = 'abc123',
        public string $email = 'jane@example.com',
    ) {}

    public function handle(): void {}
}
