<?php

declare(strict_types=1);

namespace Belvedere\Tests;

use Belvedere\Belvedere;
use Belvedere\BelvedereServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Belvedere::flushState();

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
