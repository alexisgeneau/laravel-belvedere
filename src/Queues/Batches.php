<?php

declare(strict_types=1);

namespace Belvedere\Queues;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Collection;

final readonly class Batches
{
    public function __construct(private BatchRepository $repository) {}

    /** @return Collection<int, Batch> */
    public function list(int $limit, ?string $beforeId): Collection
    {
        return collect($this->repository->get($limit, $beforeId));
    }

    public function find(string $id): ?Batch
    {
        return $this->repository->find($id);
    }

    /** @return array<string, mixed> */
    public function toArray(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'failed_jobs' => $batch->failedJobs,
            'failed_job_ids' => $batch->failedJobIds,
            'processed_jobs' => $batch->processedJobs(),
            'progress' => $batch->progress(),
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
            'created_at' => $batch->createdAt->toIso8601String(),
            'cancelled_at' => $batch->cancelledAt?->toIso8601String(),
            'finished_at' => $batch->finishedAt?->toIso8601String(),
        ];
    }
}
