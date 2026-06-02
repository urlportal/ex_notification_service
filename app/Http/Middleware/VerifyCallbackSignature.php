<?php

namespace App\Http\Middleware;

use App\Services\Callbacks\CallbackAdapterRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCallbackSignature
{
    public function __construct(private readonly CallbackAdapterRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $adapter = $request->route('adapter');

        $config = $this->registry->getConfig($adapter);
        $authenticator = $this->registry->getAuthenticator($adapter);

        $authenticator->verify($request, array_merge($config, ['adapter' => $adapter]));

        return $next($request);
    }
}
