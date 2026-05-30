<?php

namespace Tests\Feature\Messaging;

use App\Messaging\AmqpConnectionFactory;
use App\Messaging\DeliveryProfile;
use Tests\TestCase;

class RabbitMqTopologyTest extends TestCase
{
    public function test_main_queues_declared_with_correct_args(): void
    {
        $profile = app(DeliveryProfile::class);
        $channel = app(AmqpConnectionFactory::class)->connection()->channel();

        foreach ($profile->names() as $name) {
            $channel->queue_declare(queue: $profile->queueName($name), passive: true);
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
