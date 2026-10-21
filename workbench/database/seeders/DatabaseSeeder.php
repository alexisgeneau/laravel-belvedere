<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Belvedere\Models\MonitoredTag;
use Belvedere\Tests\Fixtures\Jobs\BatchedJob;
use Belvedere\Tests\Fixtures\Jobs\FailingJob;
use Belvedere\Tests\Fixtures\Jobs\ReleasingJob;
use Belvedere\Tests\Fixtures\Jobs\SucceedingJob;
use Belvedere\Tests\Fixtures\Jobs\TaggedJob;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        MonitoredTag::query()->firstOrCreate(['tag' => 'reports'], ['created_at' => Carbon::now()]);

        for ($i = 0; $i < 25; $i++) {
            SucceedingJob::dispatch();
        }

        TaggedJob::dispatch();
        ReleasingJob::dispatch();
        FailingJob::dispatch();
        SucceedingJob::dispatch()->onQueue('emails');

        Bus::batch([new BatchedJob, new BatchedJob, new BatchedJob(fails: true)])->name('Nightly invoices')->allowFailures()->dispatch();

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default,emails', '--stop-when-empty' => true, '--sleep' => 0]);

        for ($i = 0; $i < 5; $i++) {
            SucceedingJob::dispatch()->onQueue('reports');
        }

        TaggedJob::dispatch()->delay(Carbon::now()->addMinutes(30));
    }
}
