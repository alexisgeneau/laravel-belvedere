<?php

declare(strict_types=1);

namespace Belvedere\Collector;

use Belvedere\Alerts\AlertEvaluator;
use Illuminate\Contracts\Queue\Job as QueuedJob;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Queue\Events\WorkerStopping;

final readonly class QueueEventSubscriber
{
    public function __construct(
        private JobRecorder $recorder,
        private MetricsAggregator $metrics,
        private WorkerHeartbeat $heartbeat,
        private AlertEvaluator $alerts,
        private Sampler $sampler,
    ) {}

    /** @return array<class-string, string> */
    public function subscribe(): array
    {
        return [
            JobQueued::class => 'onQueued',
            JobProcessing::class => 'onProcessing',
            JobProcessed::class => 'onProcessed',
            JobExceptionOccurred::class => 'onExceptionOccurred',
            JobReleasedAfterException::class => 'onReleased',
            JobFailed::class => 'onFailed',
            JobTimedOut::class => 'onTimedOut',
            Looping::class => 'onLooping',
            WorkerStopping::class => 'onWorkerStopping',
            QueueBusy::class => 'onQueueBusy',
        ];
    }

    public function onQueued(JobQueued $event): void
    {
        $uuid = $event->payload()['uuid'] ?? null;

        if (is_string($uuid) && $this->sampler->accepts($uuid)) {
            $this->recorder->queued($event);
        }
    }

    public function onProcessing(JobProcessing $event): void
    {
        if ($this->sampled($event->job)) {
            $this->recorder->processing($event->connectionName, $event->job);
        }
    }

    public function onProcessed(JobProcessed $event): void
    {
        $this->heartbeat->jobProcessed();

        if ($this->sampled($event->job)) {
            $this->complete($this->recorder->processed($event->connectionName, $event->job));
        }
    }

    public function onExceptionOccurred(JobExceptionOccurred $event): void
    {
        if ($this->sampled($event->job)) {
            $this->recorder->exceptionOccurred($event->job, $event->exception);
        }
    }

    public function onReleased(JobReleasedAfterException $event): void
    {
        if ($this->sampled($event->job)) {
            $this->complete($this->recorder->released($event->connectionName, $event->job));
        }
    }

    public function onFailed(JobFailed $event): void
    {
        if ($this->sampled($event->job)) {
            $this->complete($this->recorder->failed($event->connectionName, $event->job, $event->exception));
        }
    }

    public function onTimedOut(JobTimedOut $event): void
    {
        if ($this->sampled($event->job)) {
            $this->complete($this->recorder->timedOut($event->connectionName, $event->job));
        }
    }

    public function onLooping(Looping $event): ?bool
    {
        $this->metrics->looping();

        return $this->heartbeat->beat($event) ? false : null;
    }

    public function onWorkerStopping(WorkerStopping $event): void
    {
        $this->metrics->stopping();
        $this->heartbeat->stopping();
    }

    public function onQueueBusy(QueueBusy $event): void
    {
        $properties = get_object_vars($event);

        $this->alerts->queueBusy(
            (string) ($properties['connectionName'] ?? $properties['connection'] ?? ''),
            (string) $event->queue,
            (int) $event->size,
        );
    }

    private function sampled(QueuedJob $job): bool
    {
        $uuid = $job->uuid();

        return $uuid !== null && $this->sampler->accepts($uuid);
    }

    private function complete(?Completion $completion): void
    {
        if ($completion !== null) {
            $this->metrics->record($completion);
        }
    }
}
