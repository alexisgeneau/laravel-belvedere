<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('belvedere.storage.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $prefix = $this->prefix();

        $schema->create($prefix.'jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('connection', 64);
            $table->string('queue');
            $table->string('name');
            $table->string('status', 16);
            $table->json('tags')->nullable();
            $table->json('snapshot')->nullable();
            $table->longText('exception')->nullable();
            $table->string('batch_id', 36)->nullable();
            $table->string('worker_id')->nullable();
            $table->timestamp('queued_at', 3)->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('wait_ms')->nullable();
            $table->unsignedInteger('run_ms')->nullable();
            $table->unsignedInteger('memory_kb')->nullable();

            $table->unique(['uuid', 'attempt']);
            $table->index(['connection', 'queue', 'status', 'finished_at']);
            $table->index(['status', 'finished_at']);
            $table->index(['name', 'finished_at']);
            $table->index('batch_id');
            $table->index('worker_id');
        });

        $schema->create($prefix.'job_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('job_id');
            $table->string('tag', 191);

            $table->primary(['job_id', 'tag']);
            $table->index(['tag', 'job_id']);
        });

        $schema->create($prefix.'workers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('hostname');
            $table->unsignedInteger('pid');
            $table->string('connection', 64);
            $table->string('queues');
            $table->string('status', 16);
            $table->unsignedInteger('jobs_processed')->default(0);
            $table->unsignedInteger('memory_kb')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');

            $table->index(['status', 'last_seen_at']);
        });

        $schema->create($prefix.'metrics', function (Blueprint $table) {
            $table->id();
            $table->string('connection', 64);
            $table->string('subject_type', 8);
            $table->string('subject');
            $table->timestamp('bucket');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('released')->default(0);
            $table->unsignedBigInteger('wait_ms_sum')->default(0);
            $table->unsignedInteger('wait_ms_max')->default(0);
            $table->unsignedBigInteger('run_ms_sum')->default(0);
            $table->unsignedInteger('run_ms_max')->default(0);

            $table->unique(['connection', 'subject_type', 'subject', 'bucket']);
            $table->index('bucket');
        });

        $schema->create($prefix.'actions', function (Blueprint $table) {
            $table->id();
            $table->string('action', 32);
            $table->string('subject');
            $table->string('actor')->nullable();
            $table->timestamp('created_at');

            $table->index('created_at');
        });

        $schema->create($prefix.'monitored_tags', function (Blueprint $table) {
            $table->string('tag', 191)->primary();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $prefix = $this->prefix();

        foreach (['monitored_tags', 'actions', 'metrics', 'workers', 'job_tags', 'jobs'] as $table) {
            $schema->dropIfExists($prefix.$table);
        }
    }

    private function prefix(): string
    {
        return (string) config('belvedere.storage.prefix', 'belvedere_');
    }
};
