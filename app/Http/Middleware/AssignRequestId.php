<?php

namespace App\Http\Middleware;

use App\Logging\RequestIdContext;
use Closure;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-ID', '');
        $id = ($incoming !== '' && strlen($incoming) === 36)
            ? $incoming
            : Uuid::uuid7()->toString();

        app(RequestIdContext::class)->set($id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        app(RequestIdContext::class)->forget();
    }
}
