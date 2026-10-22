<?php

declare(strict_types=1);

namespace Belvedere;

use Belvedere\Supervision\MasterSupervisor;
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

    /** @return list<string> Job classes kept out of the Completed Jobs list. */
    public static function silencedJobs(): array
    {
        return array_values(array_filter(
            (array) config('belvedere.silenced', []),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));
    }

    /**
     * What the master supervisors of this machine are named after instead of its
     * hostname, for the machines whose hostname is not stable.
     */
    public static function nameMasterUsing(?Closure $callback): void
    {
        MasterSupervisor::determineNameUsing($callback);
    }

    public static function flushState(): void
    {
        self::$authUsing = null;
        MasterSupervisor::determineNameUsing(null);
    }
}
