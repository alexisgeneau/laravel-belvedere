<?php

declare(strict_types=1);

namespace Belvedere\Http\Middleware;

use Belvedere\Belvedere;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Belvedere::check($request), 403);

        return $next($request);
    }
}
