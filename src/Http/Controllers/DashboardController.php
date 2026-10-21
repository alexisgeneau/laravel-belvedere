<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Belvedere;
use Belvedere\Support\Assets;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Vite;

final class DashboardController extends Controller
{
    public function __invoke(Application $app, Repository $config, Assets $assets, Vite $vite): View
    {
        /** @var view-string $layout */
        $layout = 'belvedere::layout';

        return view($layout, [
            'assets' => $assets,
            'nonce' => $vite->cspNonce(),
            'appName' => (string) $config->get('app.name', 'Laravel'),
            'isDownForMaintenance' => $app->isDownForMaintenance(),
            'settings' => [
                'path' => trim((string) $config->get('belvedere.path', 'belvedere'), '/'),
                'refreshSeconds' => max(1, (int) $config->get('belvedere.refresh_seconds', 5)),
                'readOnly' => (bool) $config->get('belvedere.read_only', false),
                'silenced' => Belvedere::silencedJobs() !== [],
            ],
        ]);
    }
}
