<?php

declare(strict_types=1);

namespace Belvedere;

use Belvedere\Alerts\AlertEvaluator;
use Belvedere\Collector\JobRecorder;
use Belvedere\Collector\MetricsAggregator;
use Belvedere\Collector\PayloadMetadata;
use Belvedere\Collector\QueueEventSubscriber;
use Belvedere\Collector\Reconciler;
use Belvedere\Collector\Sampler;
use Belvedere\Collector\WorkerHeartbeat;
use Belvedere\Console\CheckCommand;
use Belvedere\Console\ClearCommand;
use Belvedere\Console\InstallCommand;
use Belvedere\Console\PruneCommand;
use Belvedere\Console\StatusCommand;
use Belvedere\Queues\FailedJobs;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\QueuePauser;
use Belvedere\Support\Assets;
use Belvedere\Support\Redactor;
use Belvedere\Support\Snapshot;
use Belvedere\Support\Storage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Queue;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class BelvedereServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('belvedere')
            ->hasConfigFile()
            ->hasViews()
            ->hasRoute('web')
            ->hasMigration('create_belvedere_tables')
            ->hasCommands([
                InstallCommand::class,
                PruneCommand::class,
                ClearCommand::class,
                CheckCommand::class,
                StatusCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Assets::class, static fn (): Assets => new Assets(dirname(__DIR__).'/resources/dist'));

        $this->app->singleton(Storage::class, function (Application $app): Storage {
            $config = $app->make(Repository::class);

            return new Storage(
                $app->make('db'),
                $config->get('belvedere.storage.connection'),
                (string) $config->get('belvedere.storage.prefix', 'belvedere_'),
            );
        });

        $this->app->singleton(Redactor::class, function (Application $app): Redactor {
            return new Redactor((array) $app->make(Repository::class)->get('belvedere.snapshot.hidden_keys', []));
        });

        $this->app->singleton(Snapshot::class, function (Application $app): Snapshot {
            return new Snapshot(
                $app->make(Redactor::class),
                (int) $app->make(Repository::class)->get('belvedere.snapshot.max_bytes', 16384),
            );
        });

        $this->app->singleton(Sampler::class, function (Application $app): Sampler {
            return new Sampler((int) $app->make(Repository::class)->get('belvedere.sampling', 1));
        });

        $this->app->singleton(QueuePauser::class, function (Application $app): QueuePauser {
            $store = $app->make(Repository::class)->get('belvedere.cache_store');

            return new QueuePauser($app->make('cache')->store($store));
        });

        $this->app->singleton(PayloadMetadata::class, function (Application $app): PayloadMetadata {
            return new PayloadMetadata(
                $app->make(Snapshot::class),
                (bool) $app->make(Repository::class)->get('belvedere.snapshot.enabled', true),
            );
        });

        $this->app->singleton(JobRecorder::class);

        $this->app->singleton(MetricsAggregator::class, function (Application $app): MetricsAggregator {
            return new MetricsAggregator(
                $app->make(Storage::class),
                (bool) $app->make(Repository::class)->get('belvedere.metrics', true),
            );
        });

        $this->app->singleton(WorkerHeartbeat::class, function (Application $app): WorkerHeartbeat {
            return new WorkerHeartbeat(
                $app->make(Storage::class),
                $app->make(QueuePauser::class),
                (int) $app->make(Repository::class)->get('belvedere.workers.heartbeat_seconds', 15),
            );
        });

        $this->app->singleton(FailedJobs::class, function (Application $app): FailedJobs {
            return new FailedJobs($app->make('db'), $app->make(Repository::class), $app->make('queue.failer'));
        });

        $this->app->singleton(AlertEvaluator::class, function (Application $app): AlertEvaluator {
            $config = $app->make(Repository::class);

            return new AlertEvaluator(
                $app->make(NativeQueue::class),
                $app->make(QueuePauser::class),
                $app->make(Storage::class),
                $app->make('cache')->store($config->get('belvedere.cache_store')),
                $config,
            );
        });

        $this->app->singleton(NativeQueue::class);
        $this->app->singleton(Reconciler::class);
        $this->app->singleton(QueueEventSubscriber::class);
    }

    public function packageBooted(): void
    {
        if (! $this->app->make(Repository::class)->get('belvedere.enabled', true)) {
            return;
        }

        $this->app->make(Dispatcher::class)->subscribe(QueueEventSubscriber::class);

        Queue::createPayloadUsing(
            static fn (?string $connection, ?string $queue, array $payload): array => app(PayloadMetadata::class)->for($payload),
        );
    }
}
