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

        $schema->create($prefix.'masters', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->string('hostname');
            $table->unsignedInteger('pid');
            $table->string('environment', 64)->nullable();
            $table->string('status', 16);
            $table->json('supervisors');
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');

            $table->index('last_seen_at');
        });

        $schema->create($prefix.'supervisors', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->string('master');
            $table->string('hostname');
            $table->unsignedInteger('pid');
            $table->string('status', 16);
            $table->json('processes');
            $table->json('options');
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');

            $table->index('master');
            $table->index('last_seen_at');
        });

        $schema->create($prefix.'commands', function (Blueprint $table) {
            $table->id();
            $table->string('target');
            $table->string('command', 32);
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index('target');
        });

        $schema->table($prefix.'workers', function (Blueprint $table) {
            $table->string('supervisor')->nullable()->after('queues')->index();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $prefix = $this->prefix();

        $schema->table($prefix.'workers', function (Blueprint $table) {
            $table->dropIndex(['supervisor']);
            $table->dropColumn('supervisor');
        });

        foreach (['commands', 'supervisors', 'masters'] as $table) {
            $schema->dropIfExists($prefix.$table);
        }
    }

    private function prefix(): string
    {
        return (string) config('belvedere.storage.prefix', 'belvedere_');
    }
};
