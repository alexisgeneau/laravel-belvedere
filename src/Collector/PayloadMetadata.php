<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Support\Payload;
use Belvedere\Support\Snapshot;
use Belvedere\Support\Tags;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Throwable;

final readonly class PayloadMetadata
{
    public function __construct(
        private Snapshot $snapshot,
        private bool $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function for(array $payload): array
    {
        $job = $payload['data']['command'] ?? null;

        if (! is_object($job)) {
            return [];
        }

        try {
            return [Payload::METADATA => [
                'tags' => Tags::of($job),
                'batch' => $this->batchId($job),
                'snapshot' => $this->snapshots && ! $job instanceof ShouldBeEncrypted ? $this->snapshot->of($job) : null,
            ]];
        } catch (Throwable) {
            return [];
        }
    }

    private function batchId(object $job): ?string
    {
        if (! property_exists($job, 'batchId')) {
            return null;
        }

        $batchId = $job->batchId;

        return is_string($batchId) && $batchId !== '' ? $batchId : null;
    }
}
