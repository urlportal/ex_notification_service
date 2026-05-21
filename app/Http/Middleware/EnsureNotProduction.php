<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class EnsureNotProduction
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (app()->environment('production')) {
            return response()->json(['message' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
