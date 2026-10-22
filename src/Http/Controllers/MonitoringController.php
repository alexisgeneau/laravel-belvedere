<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Models\Job;
use Belvedere\Models\JobTag;
use Belvedere\Models\MonitoredTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class MonitoringController extends Controller
{
    public function index(): JsonResponse
    {
        $tags = MonitoredTag::query()->orderBy('tag')->get();

        $counts = JobTag::query()
            ->selectRaw('tag, count(*) as total')
            ->whereIn('tag', $tags->modelKeys())
            ->groupBy('tag')
            ->pluck('total', 'tag');

        return new JsonResponse([
            'tags' => $tags->map(static fn (MonitoredTag $tag): array => [
                'tag' => $tag->tag,
                'jobs' => (int) ($counts[$tag->tag] ?? 0),
                'created_at' => $tag->created_at->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tag = trim((string) $request->input('tag'));

        abort_if($tag === '' || mb_strlen($tag) > 191, 422, 'A tag between 1 and 191 characters is required.');

        MonitoredTag::query()->firstOrCreate(['tag' => $tag], ['created_at' => Carbon::now()]);

        return new JsonResponse(['tag' => $tag], 201);
    }

    public function destroy(string $tag): JsonResponse
    {
        abort_unless(MonitoredTag::query()->whereKey($tag)->delete() > 0, 404);

        return new JsonResponse(['deleted' => true]);
    }

    public function jobs(Request $request, string $tag): JsonResponse
    {
        $jobs = Job::query()
            ->withTag($tag)
            ->cursorPaginate($this->limit($request));

        return new JsonResponse([
            'tag' => $tag,
            'jobs' => collect($jobs->items())->map(static fn (Job $job): array => [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'attempt' => $job->attempt,
                'queue' => $job->queue,
                'name' => $job->name,
                'status' => $job->status->value,
                'queued_at' => $job->queued_at?->toIso8601String(),
                'finished_at' => $job->finished_at?->toIso8601String(),
                'run_ms' => $job->run_ms,
            ])->values(),
            'next_cursor' => $jobs->nextCursor()?->encode(),
        ]);
    }
}
