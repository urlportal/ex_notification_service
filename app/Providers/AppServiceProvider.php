<?php

namespace App\Providers;

use App\Repositories\Eloquent\EloquentSubscriberRepository;
use App\Repositories\SubscriberRepositoryInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SubscriberRepositoryInterface::class, EloquentSubscriberRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $token = config('auth.api_token');

        if ($token === null || $token === '') {
            throw new \RuntimeException('API_TOKEN is not configured.');
        }
    }
}
