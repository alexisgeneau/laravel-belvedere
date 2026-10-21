<?php

declare(strict_types=1);

namespace Belvedere\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
