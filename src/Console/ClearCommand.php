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

        $tables = ['job_tags', 'jobs', 'workers', 'metrics', 'actions', 'monitored_tags'];

        // The masters and supervisors still running write themselves back at their next heartbeat.
        if ($storage->hasSupervisionTables()) {
            $tables = [...$tables, 'commands', 'supervisors', 'masters'];
        }

        foreach ($tables as $table) {
            $storage->query($table)->delete();
        }

        $this->components->info('Belvedere storage cleared.');

        return self::SUCCESS;
    }
}
