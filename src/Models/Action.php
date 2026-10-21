<?php

declare(strict_types=1);

namespace Belvedere\Models;

use Belvedere\Enums\ActionType;

/**
 * @property int $id
 * @property ActionType $action
 * @property string $subject
 * @property string|null $actor
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Action extends Model
{
    protected const TABLE = 'actions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => ActionType::class,
            'created_at' => 'datetime',
        ];
    }
}
