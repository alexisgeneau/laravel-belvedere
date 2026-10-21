<?php

declare(strict_types=1);

namespace Belvedere\Queues;

use Belvedere\Collector\Reconciler;
use Belvedere\Enums\ActionType;
use Belvedere\Enums\JobStatus;
use Belvedere\Models\Action;
use Belvedere\Models\Job;
use Belvedere\Models\JobTag;
use Belvedere\Models\Worker;
use Belvedere\Support\Payload;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Carbon;

final readonly class JobActions
{
    public function __construct(
        private NativeQueue $native,
        private FailedJobs $failed,
        private Batches $batches,
        private QueuePauser $pauser,
        private QueueFactory $queues,
        private Reconciler $reconciler,
    ) {}

    public function retry(string $failedJobId, ?string $actor = null): bool
    {
        $failed = $this->failed->find($failedJobId);

        if ($failed === null) {
            return false;
        }

        $payload = Payload::tryFromJson($failed->payload);

        if ($payload === null) {
            return false;
        }

        $this->queues->connection($failed->connection)->pushRaw($payload->forRetry()->toJson(), $failed->queue);
        $this->failed->forget($failedJobId);

        if ($payload->uuid() !== null) {
            $this->recordQueuedAttempt($payload, $failed);
        }

        $this->log(ActionType::Retry, $failed->uuid ?? $failedJobId, $actor);

        return true;
    }

    /** @param  iterable<string>  $failedJobIds */
    public function retryMany(iterable $failedJobIds, ?string $actor = null): int
    {
        $retried = 0;

        foreach ($failedJobIds as $id) {
            if ($this->retry((string) $id, $actor)) {
                $retried++;
            }
        }

        return $retried;
    }

    public function forget(string $failedJobId, ?string $actor = null): bool
    {
        $forgotten = $this->failed->forget($failedJobId);

        if ($forgotten) {
            $this->log(ActionType::Forget, $failedJobId, $actor);
        }

        return $forgotten;
    }

    public function flush(?string $actor = null): void
    {
        $this->failed->flush();
        $this->log(ActionType::Flush, 'failed_jobs', $actor);
    }

    public function delete(string $connection, int $id, ?string $actor = null): bool
    {
        $uuid = $this->native->find($connection, $id)?->uuid;
        $deleted = $this->native->delete($connection, $id);

        if ($deleted) {
            if ($uuid !== null) {
                $this->reconciler->markLost(
                    Job::query()->forUuid($uuid)->where('status', JobStatus::Queued->value),
                    'Deleted from the dashboard'.$this->by($actor).'.',
                );
            }

            $this->log(ActionType::Delete, "{$connection}:{$id}", $actor);
        }

        return $deleted;
    }

    public function runNow(string $connection, int $id, ?string $actor = null): bool
    {
        $updated = $this->native->runNow($connection, $id);

        if ($updated) {
            $this->log(ActionType::RunNow, "{$connection}:{$id}", $actor);
        }

        return $updated;
    }

    public function release(string $connection, int $id, ?string $actor = null): bool
    {
        $released = $this->native->release($connection, $id);

        if ($released) {
            $this->log(ActionType::Release, "{$connection}:{$id}", $actor);
        }

        return $released;
    }

    public function purge(string $connection, string $queue, ?string $actor = null): int
    {
        $uuids = array_keys($this->native->uuids($connection, $queue, unreservedOnly: true));
        $purged = $this->native->purge($connection, $queue);

        foreach (array_chunk($uuids, 500) as $chunk) {
            $this->reconciler->markLost(
                Job::query()->where('connection', $connection)->where('status', JobStatus::Queued->value)->whereIn('uuid', $chunk),
                "Purged with queue [{$queue}] from the dashboard".$this->by($actor).'.',
            );
        }

        $this->log(ActionType::Purge, "{$connection}:{$queue}", $actor);

        return $purged;
    }

    public function pause(string $connection, string $queue, ?string $actor = null): void
    {
        $this->pauser->pause($connection, $queue);
        $this->log(ActionType::Pause, "{$connection}:{$queue}", $actor);
    }

    public function resume(string $connection, string $queue, ?string $actor = null): void
    {
        $this->pauser->resume($connection, $queue);
        $this->log(ActionType::Resume, "{$connection}:{$queue}", $actor);
    }

    public function cancelBatch(string $batchId, ?string $actor = null): bool
    {
        $batch = $this->batches->find($batchId);

        if ($batch === null || $batch->cancelled()) {
            return false;
        }

        $batch->cancel();
        $this->log(ActionType::CancelBatch, $batchId, $actor);

        return true;
    }

    public function retryBatch(string $batchId, ?string $actor = null): int
    {
        $batch = $this->batches->find($batchId);

        if ($batch === null) {
            return 0;
        }

        $retried = $this->retryMany($batch->failedJobIds, $actor);
        $this->log(ActionType::RetryBatch, $batchId, $actor);

        return $retried;
    }

    public function forgetWorker(string $workerId, ?string $actor = null): bool
    {
        $deleted = Worker::query()->whereKey($workerId)->delete() > 0;

        if ($deleted) {
            $this->reconciler->markLost(
                Job::query()->where('status', JobStatus::Running->value)->where('worker_id', $workerId),
                "Worker [{$workerId}] was forgotten from the dashboard".$this->by($actor).' before reporting the outcome of this job.',
            );

            $this->log(ActionType::ForgetWorker, $workerId, $actor);
        }

        return $deleted;
    }

    private function by(?string $actor): string
    {
        return $actor !== null ? " by {$actor}" : '';
    }

    private function recordQueuedAttempt(Payload $payload, FailedJob $failed): void
    {
        $uuid = (string) $payload->uuid();
        $latest = Job::query()->forUuid($uuid)->orderByDesc('attempt')->first();
        $tags = $payload->tags() ?: ($latest->tags ?? []);

        $job = Job::query()->create([
            'uuid' => $uuid,
            'attempt' => $latest !== null ? $latest->attempt + 1 : 1,
            'connection' => $failed->connection,
            'queue' => $failed->queue,
            'name' => $payload->displayName(),
            'status' => JobStatus::Queued,
            'tags' => $tags,
            'snapshot' => $payload->snapshot() ?? $latest?->snapshot,
            'batch_id' => $payload->batchId() ?? $latest?->batch_id,
            'queued_at' => Carbon::now(),
        ]);

        if ($tags !== []) {
            JobTag::query()->insertOrIgnore(array_map(
                static fn (string $tag): array => ['job_id' => $job->id, 'tag' => $tag],
                $tags,
            ));
        }
    }

    private function log(ActionType $action, string $subject, ?string $actor): void
    {
        Action::query()->create([
            'action' => $action,
            'subject' => mb_substr($subject, 0, 255),
            'actor' => $actor !== null ? mb_substr($actor, 0, 255) : null,
            'created_at' => Carbon::now(),
        ]);
    }
}
