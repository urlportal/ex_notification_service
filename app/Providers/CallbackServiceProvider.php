<?php

namespace App\Providers;

use App\Services\Callbacks\CallbackAdapterRegistry;
use Illuminate\Support\ServiceProvider;

class CallbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            CallbackAdapterRegistry::class,
            fn () => new CallbackAdapterRegistry(
                config: config('notifications.callbacks', []),
            ),
        );
    }
}
