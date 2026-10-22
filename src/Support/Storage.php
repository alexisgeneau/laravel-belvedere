<?php

declare(strict_types=1);

namespace Belvedere\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

final class Storage
{
    private ?bool $supervisionTables = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ?string $connection,
        private readonly string $prefix,
    ) {}

    public function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }

    public function connectionName(): ?string
    {
        return $this->connection;
    }

    public function table(string $name): string
    {
        return $this->prefix.$name;
    }

    public function query(string $name): Builder
    {
        return $this->connection()->table($this->table($name));
    }

    /**
     * Whether the tables 0.2.0 added exist. An application updated without running
     * the new migration keeps its dashboard and its workers, without supervision.
     */
    public function hasSupervisionTables(): bool
    {
        return $this->supervisionTables ??= Schema::connection($this->connection)->hasTable($this->table('masters'));
    }
}
