<?php

declare(strict_types=1);

namespace Belvedere\Models;

/**
 * @property string $tag
 * @property \Illuminate\Support\Carbon $created_at
 */
final class MonitoredTag extends Model
{
    protected const TABLE = 'monitored_tags';

    public $incrementing = false;

    protected $primaryKey = 'tag';

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
