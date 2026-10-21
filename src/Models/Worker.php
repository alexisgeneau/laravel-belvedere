<?php

declare(strict_types=1);

namespace Belvedere\Models;

use Belvedere\Enums\WorkerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $hostname
 * @property int $pid
 * @property string $connection
 * @property string $queues
 * @property WorkerStatus $status
 * @property int $jobs_processed
 * @property int|null $memory_kb
 * @property Carbon $started_at
 * @property Carbon $last_seen_at
 */
final class Worker extends Model
{
    protected const TABLE = 'workers';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pid' => 'integer',
            'status' => WorkerStatus::class,
            'jobs_processed' => 'integer',
            'memory_kb' => 'integer',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function isDead(int $deadAfterSeconds): bool
    {
        return $this->status !== WorkerStatus::Stopped
            && $this->last_seen_at->lt(Carbon::now()->subSeconds($deadAfterSeconds));
    }

    public function effectiveStatus(int $deadAfterSeconds): WorkerStatus
    {
        return $this->isDead($deadAfterSeconds) ? WorkerStatus::Dead : $this->status;
    }

    /** @return list<string> */
    public function queueNames(): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $this->queues))));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDead(Builder $query, int $deadAfterSeconds): Builder
    {
        return $query
            ->where('status', '!=', WorkerStatus::Stopped->value)
            ->where('last_seen_at', '<', Carbon::now()->subSeconds($deadAfterSeconds));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAlive(Builder $query, int $deadAfterSeconds): Builder
    {
        return $query
            ->where('status', '!=', WorkerStatus::Stopped->value)
            ->where('last_seen_at', '>=', Carbon::now()->subSeconds($deadAfterSeconds));
    }
}
