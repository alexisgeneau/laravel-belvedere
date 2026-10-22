<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Jobs\RenderThumbnail;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            // Thumbnails are noisy: keep them out of the Completed Jobs list of the demo.
            'belvedere.silenced' => [RenderThumbnail::class],
            // The seeded master and its workers have no process behind them: keep them alive for the demo.
            'belvedere.supervisor.inactive_after_seconds' => 86_400,
            'belvedere.workers.dead_after_seconds' => 3_600,
        ]);
    }
}
