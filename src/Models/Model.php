<?php

declare(strict_types=1);

namespace Belvedere\Models;

use Belvedere\Support\Storage;
use Illuminate\Database\Eloquent\Model as Eloquent;

abstract class Model extends Eloquent
{
    protected const TABLE = '';

    public $timestamps = false;

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return app(Storage::class)->connectionName();
    }

    public function getTable(): string
    {
        return app(Storage::class)->table(static::TABLE);
    }
}
