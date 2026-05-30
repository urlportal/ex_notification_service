<?php

namespace App\Messaging;

/**
 * - name: имя профиля доставки (snake_case), оно же routing_key и
 *   суффикс program supervisord (sender-<name>).
 * - queue: имя AMQP-очереди в брокере (может не совпадать с именем профиля).
 * - workers: numprocs для supervisord-секции.
 * - prefetch: basic_qos(prefetch_count) для sender-воркера.
 */
final readonly class DeliveryProfileConfig
{
    public function __construct(
        public string $name,
        public string $queue,
        public int $workers,
        public int $prefetch,
    ) {}
}
