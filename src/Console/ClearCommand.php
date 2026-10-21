<?php

declare(strict_types=1);

namespace Belvedere\Console;

use Belvedere\Support\Storage;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

final class ClearCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'belvedere:clear {--force : Run without confirmation in production}';

    protected $description = 'Delete every record stored by Belvedere';

    public function handle(Storage $storage): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        foreach (['job_tags', 'jobs', 'workers', 'metrics', 'actions', 'monitored_tags'] as $table) {
            $storage->query($table)->delete();
        }

        $this->components->info('Belvedere storage cleared.');

        return self::SUCCESS;
    }
}
