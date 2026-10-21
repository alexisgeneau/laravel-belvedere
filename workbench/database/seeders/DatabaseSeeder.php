<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Belvedere\Models\MonitoredTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Workbench\App\Jobs\GenerateReport;
use Workbench\App\Jobs\ProcessPayment;
use Workbench\App\Jobs\RenderThumbnail;
use Workbench\App\Jobs\SendInvoice;
use Workbench\App\Jobs\SyncCatalog;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        MonitoredTag::query()->firstOrCreate(['tag' => 'billing'], ['created_at' => Carbon::now()]);
        MonitoredTag::query()->firstOrCreate(['tag' => 'tenant:42'], ['created_at' => Carbon::now()->subDay()]);

        for ($i = 1; $i <= 30; $i++) {
            SendInvoice::dispatch(1000 + $i, "customer{$i}@example.com");
        }

        for ($i = 1; $i <= 6; $i++) {
            RenderThumbnail::dispatch(500 + $i)->onQueue('media');
        }

        GenerateReport::dispatch(42);
        GenerateReport::dispatch(42, 'xlsx');
        GenerateReport::dispatch(7);
        SyncCatalog::dispatch('ftp://supplier.example.com/catalog.csv');
        ProcessPayment::dispatch(2001, 4990);
        ProcessPayment::dispatch(2002, 12900);

        Bus::batch([new SendInvoice(3001, 'acme@example.com'), new SendInvoice(3002, 'globex@example.com'), new ProcessPayment(3003, 7500)])
            ->name('Nightly invoices')
            ->allowFailures()
            ->dispatch();

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default,media', '--stop-when-empty' => true, '--sleep' => 0]);

        for ($i = 1; $i <= 8; $i++) {
            GenerateReport::dispatch(100 + $i)->onQueue('reports');
        }

        SyncCatalog::dispatch('ftp://supplier.example.com/catalog.csv')->delay(Carbon::now()->addMinutes(30));
        SendInvoice::dispatch(1099, 'late@example.com')->delay(Carbon::now()->addHours(2));
    }
}
