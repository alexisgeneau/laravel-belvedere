<?php

declare(strict_types=1);

namespace Belvedere\Tests;

use Belvedere\Belvedere;
use Belvedere\BelvedereServiceProvider;
use Belvedere\Supervision\AutoScaler;
use Belvedere\Supervision\MasterSupervisor;
use Belvedere\Supervision\QueueLoad;
use Belvedere\Supervision\Repositories\CommandQueue;
use Belvedere\Supervision\Repositories\MasterRepository;
use Belvedere\Supervision\Repositories\SupervisorRepository;
use Belvedere\Supervision\Supervisor;
use Belvedere\Supervision\SupervisorOptions;
use Belvedere\Supervision\SystemProcesses;
use Belvedere\Supervision\WorkerProcess;
use Belvedere\Tests\Fixtures\Supervision\ExitRecorder;
use Belvedere\Tests\Fixtures\Supervision\FakeProcess;
use Belvedere\Tests\Fixtures\Supervision\FakeQueueLoad;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Process\Process;

abstract class TestCase extends Orchestra
{
    protected ExitRecorder $exits;

    protected function setUp(): void
    {
        parent::setUp();

        Belvedere::flushState();
        $this->exits = new ExitRecorder;

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [BelvedereServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnection());
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('queue.batching', [
            'database' => 'testing',
            'table' => 'job_batches',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('belvedere.connections', ['database']);
    }

    protected function workQueue(string $queue = 'default'): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $queue,
            '--stop-when-empty' => true,
            '--sleep' => 0,
        ]);
    }

    protected function workOnce(string $queue = 'default'): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $queue,
            '--once' => true,
            '--sleep' => 0,
        ]);
    }

    protected function authorize(): void
    {
        Belvedere::auth(static fn (): bool => true);
    }

    /** @param  array<string, mixed>  $options */
    protected function supervisorOptions(array $options = []): SupervisorOptions
    {
        return SupervisorOptions::fromArray([
            'name' => 'test-master:supervisor-1',
            'connection' => 'database',
            'queue' => 'default',
            ...$options,
        ]);
    }

    /**
     * A supervisor whose workers are fake processes and whose exit throws.
     *
     * @param  array<string, mixed>  $options
     */
    protected function supervisor(array $options = [], ?QueueLoad $load = null, int $heartbeatSeconds = 5, bool $workersExitOnTerm = true): Supervisor
    {
        $supervisor = new Supervisor(
            $this->supervisorOptions($options),
            app(SupervisorRepository::class),
            app(CommandQueue::class),
            new AutoScaler($load ?? new FakeQueueLoad),
            new SystemProcesses(static fn (): array => []),
            app(ExceptionHandler::class),
            static fn (SupervisorOptions $options): WorkerProcess => new WorkerProcess(new FakeProcess($options->queue, $workersExitOnTerm)),
            $heartbeatSeconds,
        );

        return $supervisor->exitUsing($this->exits->__invoke(...));
    }

    /** The exit statuses the masters and supervisors built here asked for. */
    protected function exits(): array
    {
        return $this->exits->statuses;
    }

    /** A master whose supervisors are fake processes and whose exit throws. */
    protected function master(?string $environment = 'testing', int $heartbeatSeconds = 5, int $memoryLimit = 64): MasterSupervisor
    {
        $master = new MasterSupervisor(
            $environment,
            app(MasterRepository::class),
            app(SupervisorRepository::class),
            app(CommandQueue::class),
            app(ExceptionHandler::class),
            static fn (SupervisorOptions $options): Process => new FakeProcess($options->name, true),
            $heartbeatSeconds,
            $memoryLimit,
        );

        return $master->exitUsing($this->exits->__invoke(...));
    }

    /** @return array<string, mixed> */
    private function databaseConnection(): array
    {
        $driver = getenv('BELVEDERE_TEST_DB') ?: 'sqlite';

        return match ($driver) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('BELVEDERE_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('BELVEDERE_TEST_DB_PORT') ?: 3306),
                'database' => getenv('BELVEDERE_TEST_DB_DATABASE') ?: 'belvedere',
                'username' => getenv('BELVEDERE_TEST_DB_USERNAME') ?: 'root',
                'password' => getenv('BELVEDERE_TEST_DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('BELVEDERE_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('BELVEDERE_TEST_DB_PORT') ?: 5432),
                'database' => getenv('BELVEDERE_TEST_DB_DATABASE') ?: 'belvedere',
                'username' => getenv('BELVEDERE_TEST_DB_USERNAME') ?: 'belvedere',
                'password' => getenv('BELVEDERE_TEST_DB_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        };
    }
}
