<?php

namespace App\Providers;

use App\Logging\RequestIdContext;
use App\Messaging\AmqpConnectionFactory;
use App\Messaging\AmqpNotificationPublisher;
use App\Messaging\NotificationPublisherInterface;
use App\Repositories\Eloquent\EloquentNotificationRepository;
use App\Repositories\Eloquent\EloquentSubscriberRepository;
use App\Repositories\NotificationRepositoryInterface;
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
        $this->app->bind(NotificationRepositoryInterface::class, EloquentNotificationRepository::class);

        $this->app->singleton(RequestIdContext::class);
        $this->app->singleton(AmqpConnectionFactory::class);
        $this->app->singleton(NotificationPublisherInterface::class, AmqpNotificationPublisher::class);

        $this->app->terminating(function (): void {
            $this->app->make(AmqpConnectionFactory::class)->close();
        });
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
