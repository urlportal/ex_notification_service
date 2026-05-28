<?php

namespace App\Providers;

use App\Logging\RequestIdContext;
use App\Messaging\AmqpConnectionFactory;
use App\Messaging\AmqpNotificationPublisher;
use App\Messaging\Inbox\Eloquent\EloquentMsgInboxRepository;
use App\Messaging\Inbox\MsgInboxRepositoryInterface;
use App\Messaging\NotificationPublisherInterface;
use App\Messaging\Outbox\Eloquent\EloquentMsgOutboxRepository;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use App\Messaging\Outbox\OutboxMessagePublisher;
use App\Repositories\Eloquent\EloquentNotificationRepository;
use App\Repositories\Eloquent\EloquentSubscriberRepository;
use App\Repositories\NotificationRepositoryInterface;
use App\Repositories\SubscriberRepositoryInterface;
use App\Services\IdempotencyService;
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
        $this->app->singleton(IdempotencyService::class, function (): IdempotencyService {
            return new IdempotencyService((int) config('idempotency.ttl'));
        });
        $this->app->singleton(AmqpConnectionFactory::class);
        $this->app->singleton(NotificationPublisherInterface::class, AmqpNotificationPublisher::class);

        // Outbox-инфраструктура
        $this->app->bind(MsgOutboxRepositoryInterface::class, EloquentMsgOutboxRepository::class);
        $this->app->singleton(OutboxMessagePublisher::class);

        // Inbox-инфраструктура
        $this->app->bind(MsgInboxRepositoryInterface::class, EloquentMsgInboxRepository::class);

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
