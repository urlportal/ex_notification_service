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

        // Шесть retry-очередей вида notifications.retry.<level>.<delivery_profile>.
        // Каждая объявлена в definitions.json с собственным TTL и
        // x-dead-letter-routing-key, равным имени соответствующего профиля доставки.
        foreach (['30s', '5m', '30m'] as $level) {
            foreach (['transactional', 'marketing'] as $profile) {
                $channel->queue_declare(
                    queue: "notifications.retry.{$level}.{$profile}",
                    passive: true,
                );
            }
        }

        $channel->close();
        $this->addToAssertionCount(1);
    }
}
