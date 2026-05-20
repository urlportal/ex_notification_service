<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $header = $request->header('Authorization');

        if ($header === null) {
            Log::warning('API token authentication failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'reason' => 'missing_header',
            ]);

            return response()->json(['message' => 'Unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (! str_starts_with($header, 'Bearer ')) {
            Log::warning('API token authentication failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'reason' => 'invalid_format',
            ]);

            return response()->json(['message' => 'Unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $token = substr($header, 7);

        if (! hash_equals((string) config('auth.api_token'), $token)) {
            Log::warning('API token authentication failed', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'reason' => 'invalid_token',
            ]);

            return response()->json(['message' => 'Unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
