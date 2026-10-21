<?php

declare(strict_types=1);

namespace Belvedere\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'belvedere:install';

    protected $description = 'Publish the Belvedere configuration and migration';

    public function handle(): int
    {
        foreach (['belvedere-config', 'belvedere-migrations'] as $tag) {
            $this->call('vendor:publish', ['--tag' => $tag]);
        }

        $this->components->info('Belvedere is installed. Next steps:');
        $this->components->bulletList([
            'php artisan migrate',
            'Define the viewBelvedere gate (or Belvedere::auth()) to control access outside the local environment.',
            'Schedule `belvedere:prune` daily and `belvedere:check` every minute.',
        ]);

        return self::SUCCESS;
    }
}
