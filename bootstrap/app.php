<?php

use App\Exceptions\AlreadyExistsException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureNotProduction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);
        $middleware->alias([
            'api.token' => ApiTokenAuth::class,
            'not.production' => EnsureNotProduction::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (NotFoundException|ModelNotFoundException $e, Request $request) {
            return response()->json(['message' => 'Resource not found'], Response::HTTP_NOT_FOUND);
        });
        $exceptions->render(function (AlreadyExistsException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        });
        $exceptions->render(function (IdempotencyConflictException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        });
        $exceptions->render(function (RedisException $e, Request $request) {
            return response()->json(['message' => 'Service temporarily unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
        });
    })->create();
