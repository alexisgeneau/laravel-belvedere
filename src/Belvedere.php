<?php

declare(strict_types=1);

namespace Belvedere;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class Belvedere
{
    private static ?Closure $authUsing = null;

    public static function auth(Closure $callback): void
    {
        self::$authUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        if (self::$authUsing !== null) {
            return (bool) (self::$authUsing)($request);
        }

        return app()->environment('local') || Gate::check('viewBelvedere', [$request->user()]);
    }

    public static function actor(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return $user->email ?? $user->name ?? (string) $user->getAuthIdentifier();
    }

    public static function flushState(): void
    {
        self::$authUsing = null;
    }
}
