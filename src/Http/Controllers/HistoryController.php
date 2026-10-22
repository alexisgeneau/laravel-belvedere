<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Belvedere;
use Belvedere\Enums\JobStatus;
use Belvedere\Models\Job;
use Belvedere\Queues\NativeQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class HistoryController extends Controller
{
    public function index(Request $request, NativeQueue $native): JsonResponse
    {
        $connection = $this->connection($request, $native);
        $status = $this->optionalString($request, 'status');
        $queue = $this->optionalString($request, 'queue');
        $name = $this->optionalString($request, 'name');
        $tag = $this->optionalString($request, 'tag');
        $search = $this->optionalString($request, 'search');
        $from = $this->optionalString($request, 'from');
        $to = $this->optionalString($request, 'to');

        abort_if($status !== null && ! in_array($status, JobStatus::finalValues(), true), 404);

        // Silenced job classes are kept out of the completed list unless asked for explicitly.
        $silenced = Belvedere::silencedJobs();
        $onlySilenced = $request->boolean('silenced');
        $hideSilenced = ! $onlySilenced && $silenced !== [] && $status === JobStatus::Completed->value;

        $jobs = Job::query()
            ->where('connection', $connection)
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status), fn (Builder $query): Builder => $query->finished())
            ->when($onlySilenced, fn (Builder $query): Builder => $query->whereIn('name', $silenced))
            ->when($hideSilenced, fn (Builder $query): Builder => $query->whereNotIn('name', $silenced))
            ->when($queue !== null, fn (Builder $query): Builder => $query->where('queue', $queue))
            ->when($name !== null, fn (Builder $query): Builder => $query->where('name', $name))
            // The tag scope sets its own order; the other lists come newest finished first.
            ->when($tag !== null, fn (Builder $query): Builder => $query->withTag($tag), fn (Builder $query): Builder => $query->orderByDesc('finished_at')->orderByDesc('id'))
            ->when($search !== null, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                // The uuid column is a native uuid on PostgreSQL: only compare it with a uuid.
                $query->when(Str::isUuid($search), fn (Builder $query): Builder => $query->where('uuid', $search))
                    ->orWhere('name', 'like', '%'.addcslashes($search, '%_\\').'%');
            }))
            ->when($from !== null, fn (Builder $query): Builder => $query->where('finished_at', '>=', Carbon::parse($from)))
            ->when($to !== null, fn (Builder $query): Builder => $query->where('finished_at', '<=', Carbon::parse($to)))
            ->cursorPaginate($this->limit($request));

        return new JsonResponse([
            'jobs' => collect($jobs->items())->map(fn (Job $job): array => $this->summary($job))->values(),
            'next_cursor' => $jobs->nextCursor()?->encode(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        abort_unless(Str::isUuid($uuid), 404);

        $attempts = Job::query()->forUuid($uuid)->orderBy('attempt')->get();

        abort_if($attempts->isEmpty(), 404);

        $latest = $attempts->last();

        return new JsonResponse([
            'job' => [
                ...$this->summary($latest),
                'snapshot' => $latest->snapshot,
            ],
            'attempts' => $attempts->map(fn (Job $job): array => [
                ...$this->summary($job),
                'exception' => $job->exception,
            ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Job $job): array
    {
        return [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'attempt' => $job->attempt,
            'connection' => $job->connection,
            'queue' => $job->queue,
            'name' => $job->name,
            'status' => $job->status->value,
            'tags' => $job->tags ?? [],
            'batch_id' => $job->batch_id,
            'worker_id' => $job->worker_id,
            'queued_at' => $job->queued_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'wait_ms' => $job->wait_ms,
            'run_ms' => $job->run_ms,
            'memory_kb' => $job->memory_kb,
            'exception_summary' => $job->exception !== null ? mb_substr(strtok($job->exception, "\n") ?: $job->exception, 0, 500) : null,
        ];
    }
}
