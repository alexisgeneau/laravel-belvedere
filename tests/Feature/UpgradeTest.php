<?php

declare(strict_types=1);

use Belvedere\Models\Worker;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Support\Storage;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;

/**
 * An application updated to 0.2.0 that has not run the new migration yet: the
 * supervision is off, everything from 0.1.0 keeps working.
 */
it('keeps the collector, the dashboard and the commands working without the supervision tables', function (): void {
    $migration = require __DIR__.'/../../database/migrations/create_belvedere_tables_for_supervisors.php';
    $migration->down();
    app()->forgetInstance(Storage::class);

    try {
        SucceedingJob::dispatch();
        $this->workQueue();

        expect(Worker::query()->count())->toBe(1)
            ->and(app(Storage::class)->hasSupervisionTables())->toBeFalse();

        $this->authorize();
        $this->getJson('/belvedere/api/overview')->assertOk()->assertJsonPath('supervision.status', 'none')->assertJsonPath('workers.supervised', 0);
        $this->getJson('/belvedere/api/supervisors')->assertOk()->assertJsonPath('masters', []);
        $this->getJson('/belvedere/api/workers?supervisor=none')->assertOk()->assertJsonCount(1, 'workers')->assertJsonPath('workers.0.supervisor', null);

        $this->artisan('belvedere:check')->assertExitCode(0);
        $this->artisan('belvedere:status')->assertExitCode(0)->expectsOutputToContain('not used');
        $this->artisan('belvedere:prune')->assertExitCode(0);
        $this->artisan('belvedere:supervisors')->assertExitCode(0);

        if (ProcessSignaller::unsupported() === null) {
            $this->artisan('belvedere')->assertExitCode(1)->expectsOutputToContain('run `php artisan belvedere:install`, then `php artisan migrate`');
        }

        $this->artisan('belvedere:clear', ['--force' => true])->assertExitCode(0);

        expect(Worker::query()->count())->toBe(0);
    } finally {
        $migration->up();
    }
});
