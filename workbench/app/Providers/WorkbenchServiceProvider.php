<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Jobs\RenderThumbnail;

final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Thumbnails are noisy: keep them out of the Completed Jobs list of the demo.
        config(['belvedere.silenced' => [RenderThumbnail::class]]);
    }
}
