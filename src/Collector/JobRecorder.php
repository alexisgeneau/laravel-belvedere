<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Enums\JobStatus;
use Belvedere\Models\Job;
use Belvedere\Models\JobTag;
use Belvedere\Support\Payload;
use Belvedere\Support\WorkerIdentity;
use Illuminate\Contracts\Queue\Job as QueuedJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class JobRecorder
{
    private const EXCEPTION_MAX_LENGTH = 200_000;

    /** @var array<string, array{started: float, wait: int|null, name: string, queue: string}> */
    private array $running = [];

    /** @var array<string, string> */
    private array $exceptions = [];

    public function queued(JobQueued $event): void
    {
        try {
            $payload = new Payload($event->payload());
        } catch (JsonException) {
            return;
        }

        $uuid = $payload->uuid();

        if ($uuid === null) {
            return;
        }

        $tags = $payload->tags();
        $attributes = [
            'uuid' => $uuid,
            'attempt' => 1,
            'connection' => $event->connectionName,
            'queue' => $event->queue ?? $this->defaultQueue($event->connectionName),
            'name' => $payload->displayName(),
            'status' => JobStatus::Queued,
            'tags' => $tags,
            'snapshot' => $payload->snapshot(),
            'batch_id' => $payload->batchId(),
            'queued_at' => $payload->queuedAt() ?? Carbon::now(),
        ];

        try {
            if ($tags === []) {
                Job::query()->create($attributes);

                return;
            }

            // One commit for the execution and its tags. Inside a transaction of the application
            // this is a savepoint, so a duplicate uuid never aborts that transaction on PostgreSQL.
            Job::query()->getConnection()->transaction(function () use ($attributes, $tags): void {
                $this->storeTags(Job::query()->create($attributes)->id, $tags);
            });
        } catch (UniqueConstraintViolationException) {
            return;
        }
    }

    public function processing(string $connection, QueuedJob $job): void
    {
        $uuid = self::uuidOf($job);

        if ($uuid === null) {
            return;
        }

        $now = Carbon::now();
        $payload = new Payload($job->payload());
        $queuedAt = $payload->queuedAt();
        $waitMs = $queuedAt !== null ? $this->millisecondsBetween($queuedAt, $now) : null;

        if ($waitMs === null || ! $this->startQueuedAttempt($uuid, $now, $waitMs)) {
            $waitMs = $this->startNextAttempt($connection, $job, $payload, $now);
        }

        $this->running[$uuid] = [
            'started' => microtime(true),
            'wait' => $waitMs,
            'name' => $job->resolveName(),
            'queue' => $job->getQueue(),
        ];
    }

    public function exceptionOccurred(QueuedJob $job, Throwable $exception): void
    {
        $uuid = self::uuidOf($job);

        if ($uuid !== null && isset($this->running[$uuid])) {
            $this->exceptions[$uuid] = $this->summary($exception);
        }
    }

    public function processed(string $connection, QueuedJob $job): ?Completion
    {
        if ($job->hasFailed()) {
            return null;
        }

        return $this->finish($connection, $job, $job->isReleased() ? JobStatus::Released : JobStatus::Completed, null);
    }

    public function released(string $connection, QueuedJob $job): ?Completion
    {
        $uuid = self::uuidOf($job);
        $exception = $uuid !== null ? ($this->exceptions[$uuid] ?? null) : null;

        return $this->finish($connection, $job, JobStatus::Released, $exception);
    }

    public function failed(string $connection, QueuedJob $job, Throwable $exception): ?Completion
    {
        return $this->finish($connection, $job, JobStatus::Failed, $this->describe($exception));
    }

    public function timedOut(string $connection, QueuedJob $job): ?Completion
    {
        return $this->finish($connection, $job, JobStatus::TimedOut, 'The job exceeded its timeout.');
    }

    private function finish(string $connection, QueuedJob $job, JobStatus $status, ?string $exception): ?Completion
    {
        $uuid = self::uuidOf($job);

        if ($uuid === null) {
            return null;
        }

        $running = $this->running[$uuid] ?? null;
        unset($this->running[$uuid], $this->exceptions[$uuid]);

        $queue = $running['queue'] ?? $job->getQueue();
        $name = $running['name'] ?? $job->resolveName();
        $runMs = $running !== null ? (int) round((microtime(true) - $running['started']) * 1000) : null;
        $now = Carbon::now();

        $query = Job::query()->forUuid($uuid)->orderByDesc('attempt')->limit(1);

        if ($running !== null || $status === JobStatus::TimedOut) {
            // This worker ran the attempt: its outcome wins over a "lost" verdict of the reconciler.
            $query->whereIn('status', [JobStatus::Running->value, JobStatus::Lost->value]);
        }

        $updated = $query->update([
            'status' => $status->value,
            'finished_at' => $now->format(Job::DATE_FORMAT),
            'run_ms' => $runMs,
            'memory_kb' => (int) (memory_get_usage(true) / 1024),
            'exception' => $exception,
        ]);

        if ($updated === 0 && $status !== JobStatus::TimedOut) {
            Job::query()->insertOrIgnore([
                'uuid' => $uuid,
                'attempt' => 1,
                'connection' => $connection,
                'queue' => $queue,
                'name' => $name,
                'status' => $status->value,
                'worker_id' => WorkerIdentity::id(),
                'finished_at' => $now->format(Job::DATE_FORMAT),
                'run_ms' => $runMs,
                'exception' => $exception,
            ]);
        }

        return new Completion($connection, $queue, $name, $status, $running['wait'] ?? null, $runMs);
    }

    private function startQueuedAttempt(string $uuid, Carbon $now, int $waitMs): bool
    {
        return Job::query()
            ->forUuid($uuid)
            ->where(function (Builder $query): void {
                // A queued execution the reconciler gave up on is revived when the job runs after all.
                $query->where('status', JobStatus::Queued->value)
                    ->orWhere(fn (Builder $query): Builder => $query->where('status', JobStatus::Lost->value)->whereNull('started_at'));
            })
            ->update([
                'status' => JobStatus::Running->value,
                'started_at' => $now->format(Job::DATE_FORMAT),
                'finished_at' => null,
                'worker_id' => WorkerIdentity::id(),
                'wait_ms' => $waitMs,
                'exception' => null,
            ]) > 0;
    }

    private function startNextAttempt(string $connection, QueuedJob $job, Payload $payload, Carbon $now): ?int
    {
        $uuid = (string) self::uuidOf($job);
        $latest = Job::query()->forUuid($uuid)->orderByDesc('attempt')->first();

        if ($latest !== null && ($latest->status === JobStatus::Queued || ($latest->status === JobStatus::Lost && $latest->started_at === null))) {
            $waitMs = $latest->queued_at !== null ? $this->millisecondsBetween($latest->queued_at, $now) : null;

            $latest->forceFill([
                'status' => JobStatus::Running,
                'started_at' => $now,
                'finished_at' => null,
                'worker_id' => WorkerIdentity::id(),
                'wait_ms' => $waitMs,
                'exception' => null,
            ])->save();

            return $waitMs;
        }

        $queuedAt = $latest->finished_at ?? $payload->queuedAt();
        $waitMs = $queuedAt !== null ? $this->millisecondsBetween($queuedAt, $now) : null;
        $tags = $payload->tags() ?: ($latest->tags ?? []);

        $model = Job::query()->create([
            'uuid' => $uuid,
            'attempt' => $latest !== null ? $latest->attempt + 1 : 1,
            'connection' => $connection,
            'queue' => $job->getQueue(),
            'name' => $job->resolveName(),
            'status' => JobStatus::Running,
            'tags' => $tags,
            'snapshot' => $payload->snapshot() ?? $latest?->snapshot,
            'batch_id' => $payload->batchId() ?? $latest?->batch_id,
            'worker_id' => WorkerIdentity::id(),
            'queued_at' => $queuedAt,
            'started_at' => $now,
            'wait_ms' => $waitMs,
        ]);

        $this->storeTags($model->id, $tags);

        return $waitMs;
    }

    /** @param  list<string>  $tags */
    private function storeTags(int $jobId, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        JobTag::query()->insertOrIgnore(array_map(
            static fn (string $tag): array => ['job_id' => $jobId, 'tag' => mb_substr($tag, 0, 191)],
            $tags,
        ));
    }

    /** The job uuid, or null when the payload carries none or one PostgreSQL's uuid column would reject. */
    private static function uuidOf(QueuedJob $job): ?string
    {
        $uuid = $job->uuid();

        return $uuid !== null && Str::isUuid($uuid) ? $uuid : null;
    }

    private function defaultQueue(string $connection): string
    {
        return (string) config("queue.connections.{$connection}.queue", 'default');
    }

    private function millisecondsBetween(Carbon $from, Carbon $to): int
    {
        return max(0, (int) $from->diffInMilliseconds($to, false));
    }

    private function summary(Throwable $exception): string
    {
        return mb_substr($exception::class.': '.$exception->getMessage(), 0, 2000);
    }

    private function describe(Throwable $exception): string
    {
        return mb_substr(
            $exception::class.': '.$exception->getMessage()."\n\n".$exception->getTraceAsString(),
            0,
            self::EXCEPTION_MAX_LENGTH,
        );
    }
}
