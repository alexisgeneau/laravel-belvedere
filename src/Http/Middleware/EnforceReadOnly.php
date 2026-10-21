<?php

declare(strict_types=1);

namespace Belvedere\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceReadOnly
{
    public function __construct(private Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('belvedere.read_only') && ! $request->isMethodSafe()) {
            abort(403, 'Belvedere is in read-only mode.');
        }

        return $next($request);
    }
}
