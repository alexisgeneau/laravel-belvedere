<?php

declare(strict_types=1);

namespace Belvedere\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

final readonly class Storage
{
    public function __construct(
        private DatabaseManager $db,
        private ?string $connection,
        private string $prefix,
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
}
