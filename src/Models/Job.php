<?php

declare(strict_types=1);

namespace Belvedere\Models;

use Belvedere\Enums\JobStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;

/**
 * @property int $id
 * @property string $uuid
 * @property int $attempt
 * @property string $connection
 * @property string $queue
 * @property string $name
 * @property JobStatus $status
 * @property list<string>|null $tags
 * @property array<string, mixed>|null $snapshot
 * @property string|null $exception
 * @property string|null $batch_id
 * @property string|null $worker_id
 * @property \Illuminate\Support\Carbon|null $queued_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $wait_ms
 * @property int|null $run_ms
 * @property int|null $memory_kb
 */
final class Job extends Model
{
    public const DATE_FORMAT = 'Y-m-d H:i:s.v';

    protected const TABLE = 'jobs';

    protected $dateFormat = self::DATE_FORMAT;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => JobStatus::class,
            'tags' => 'array',
            'snapshot' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'wait_ms' => 'integer',
            'run_ms' => 'integer',
            'memory_kb' => 'integer',
        ];
    }

    /** @return HasMany<JobTag, $this> */
    public function tagRows(): HasMany
    {
        return $this->hasMany(JobTag::class, 'job_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUuid(Builder $query, string $uuid): Builder
    {
        return $query->where('uuid', $uuid);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithTag(Builder $query, string $tag): Builder
    {
        // Driven by the tag index and ordered along it, so the database stops after one page whether
        // the tag is on three executions or on half of them. With an IN subquery ordered by
        // finished_at, SQLite scanned the whole history for a rare tag (120 ms per million rows).
        $jobs = $this->getTable();
        $tags = (new JobTag)->getTable();

        return $query
            ->join($tags, static function (JoinClause $join) use ($jobs, $tags, $tag): void {
                $join->on("{$tags}.job_id", '=', "{$jobs}.id")->where("{$tags}.tag", '=', $tag);
            })
            ->select("{$jobs}.*", "{$tags}.job_id")
            ->orderByDesc("{$tags}.job_id");
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereIn('status', JobStatus::finalValues());
    }
}
