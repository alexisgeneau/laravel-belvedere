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
use Belvedere\Console\Supervision\BelvedereCommand;
use Belvedere\Console\Supervision\ContinueCommand;
use Belvedere\Console\Supervision\ContinueSupervisorCommand;
use Belvedere\Console\Supervision\OrphansCommand;
use Belvedere\Console\Supervision\PauseCommand;
use Belvedere\Console\Supervision\PauseSupervisorCommand;
use Belvedere\Console\Supervision\SupervisorCommand;
use Belvedere\Console\Supervision\SupervisorsCommand;
use Belvedere\Console\Supervision\TerminateCommand;
use Belvedere\Queues\FailedJobs;
use Belvedere\Queues\NativeQueue;
use Belvedere\Queues\QueuePauser;
use Belvedere\Supervision\AutoScaler;
use Belvedere\Supervision\ProcessSignaller;
use Belvedere\Supervision\QueueLoad;
use Belvedere\Supervision\Repositories\CommandQueue;
use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Belvedere\Supervision\SupervisionFactory;
use Belvedere\Supervision\SupervisionStatus;
use Belvedere\Supervision\SystemProcesses;
use Belvedere\Support\Assets;
use Belvedere\Support\Redactor;
use Belvedere\Support\Snapshot;
use Belvedere\Support\Storage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
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
            ->hasMigrations(['create_belvedere_tables', 'create_belvedere_tables_for_supervisors'])
            ->hasCommands([
                InstallCommand::class,
                PruneCommand::class,
                ClearCommand::class,
                CheckCommand::class,
                StatusCommand::class,
                BelvedereCommand::class,
                SupervisorCommand::class,
                TerminateCommand::class,
                PauseCommand::class,
                ContinueCommand::class,
                PauseSupervisorCommand::class,
                ContinueSupervisorCommand::class,
                SupervisorsCommand::class,
                OrphansCommand::class,
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
                $app->make(MasterRepository::class),
                $app->make(SupervisorRepository::class),
            );
        });

        $this->app->singleton(NativeQueue::class);
        $this->app->singleton(Reconciler::class);
        $this->app->singleton(QueueEventSubscriber::class);

        $this->registerSupervision();
    }

    private function registerSupervision(): void
    {
        $inactiveAfter = fn (): int => max(1, (int) $this->app->make(Repository::class)->get('belvedere.supervisor.inactive_after_seconds', 30));

        $this->app->singleton(SupervisorRepository::class, fn (Application $app): SupervisorRepository => new SupervisorRepository($app->make(Storage::class), $inactiveAfter()));
        $this->app->singleton(MasterRepository::class, fn (Application $app): MasterRepository => new MasterRepository($app->make(Storage::class), $app->make(SupervisorRepository::class), $inactiveAfter()));
        $this->app->singleton(CommandQueue::class);
        $this->app->singleton(QueueLoad::class);
        $this->app->singleton(AutoScaler::class);
        $this->app->singleton(SystemProcesses::class, static fn (): SystemProcesses => new SystemProcesses);
        $this->app->singleton(ProcessSignaller::class, static fn (): ProcessSignaller => new ProcessSignaller);
        $this->app->singleton(SupervisionStatus::class);

        $this->app->singleton(SupervisionFactory::class, fn (Application $app): SupervisionFactory => new SupervisionFactory(
            $app->make(MasterRepository::class),
            $app->make(SupervisorRepository::class),
            $app->make(CommandQueue::class),
            $app->make(AutoScaler::class),
            $app->make(SystemProcesses::class),
            $app->make(ExceptionHandler::class),
            $app->make(Repository::class),
            $app->basePath(),
        ));
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
