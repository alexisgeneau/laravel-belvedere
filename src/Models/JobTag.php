<?php

declare(strict_types=1);

namespace Belvedere\Models;

/**
 * @property int $job_id
 * @property string $tag
 */
final class JobTag extends Model
{
    protected const TABLE = 'job_tags';

    public $incrementing = false;

    protected $primaryKey = null;
}
