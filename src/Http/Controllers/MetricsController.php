<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Enums\MetricSubject;
use Belvedere\Metrics\MetricsReader;
use Belvedere\Queues\NativeQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class MetricsController extends Controller
{
    public function index(Request $request, NativeQueue $native, MetricsReader $metrics, string $type): JsonResponse
    {
        $subject = MetricSubject::tryFrom($type) ?? abort(404);
        $connection = $this->connection($request, $native);
        $range = $this->range($request);

        $totals = $metrics->totals($connection, $subject, Carbon::now()->subSeconds($this->seconds($range)));

        return new JsonResponse([
            'connection' => $connection,
            'range' => $range,
            'subjects' => $totals->map(static fn (object $row): array => [
                'name' => (string) $row->subject,
                'processed' => (int) $row->processed,
                'failed' => (int) $row->failed,
                'released' => (int) $row->released,
                'wait_ms_avg' => (int) $row->total > 0 ? (int) round((int) $row->wait_ms_sum / (int) $row->total) : null,
                'run_ms_avg' => (int) $row->total > 0 ? (int) round((int) $row->run_ms_sum / (int) $row->total) : null,
                'run_ms_max' => (int) $row->run_ms_max,
            ])->sortBy('name')->values(),
        ]);
    }

    public function show(Request $request, NativeQueue $native, MetricsReader $metrics, string $type, string $name): JsonResponse
    {
        $subject = MetricSubject::tryFrom($type) ?? abort(404);
        $connection = $this->connection($request, $native);
        $range = $this->range($request);

        return new JsonResponse([
            'connection' => $connection,
            'type' => $subject->value,
            'name' => $name,
            'range' => $range,
            'series' => $metrics->series($connection, $subject, $name, $range),
        ]);
    }

    private function range(Request $request): string
    {
        $range = $this->optionalString($request, 'range') ?? '1h';

        abort_unless(in_array($range, MetricsReader::ranges(), true), 404);

        return $range;
    }

    private function seconds(string $range): int
    {
        return match ($range) {
            '1h' => 3600,
            '24h' => 86400,
            default => 604800,
        };
    }
}
