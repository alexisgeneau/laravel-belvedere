<?php

declare(strict_types=1);

namespace Belvedere\Http\Controllers;

use Belvedere\Belvedere;
use Belvedere\Queues\NativeQueue;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class Controller
{
    protected const DEFAULT_LIMIT = 50;

    protected const MAX_LIMIT = 200;

    protected function connection(Request $request, NativeQueue $native): string
    {
        $connections = $native->connections();
        $requested = $request->query('connection');

        if (is_string($requested) && $requested !== '') {
            abort_unless(in_array($requested, $connections, true), 404, "Unknown queue connection [{$requested}].");

            return $requested;
        }

        return $connections[0] ?? throw new NotFoundHttpException('No queue connection is configured for Belvedere.');
    }

    protected function limit(Request $request): int
    {
        $limit = (int) $request->query('limit', (string) static::DEFAULT_LIMIT);

        return max(1, min(static::MAX_LIMIT, $limit));
    }

    protected function optionalString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function optionalInt(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : null;
    }

    protected function actor(Request $request): ?string
    {
        return Belvedere::actor($request);
    }
}
