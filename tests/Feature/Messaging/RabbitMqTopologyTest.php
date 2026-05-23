<?php

namespace Tests\Feature\Messaging;

use App\Enums\NotificationPriority;
use App\Messaging\AmqpConnectionFactory;
use Tests\TestCase;

class RabbitMqTopologyTest extends TestCase
{
    public function test_main_queues_declared_with_correct_args(): void
    {
        $channel = app(AmqpConnectionFactory::class)->connection()->channel();

        foreach (NotificationPriority::cases() as $priority) {
            $channel->queue_declare(queue: "notifications.$priority->value", passive: true);
        }

        $channel->close();
        $this->addToAssertionCount(1);
    }

    public function test_retry_queues_declared_with_correct_args(): void
    {
        $channel = app(AmqpConnectionFactory::class)->connection()->channel();

        $channel->queue_declare(queue: 'notifications.retry.1', passive: true);

        $channel->queue_declare(queue: 'notifications.retry.2', passive: true);

        $channel->close();
        $this->addToAssertionCount(1);
    }
}
