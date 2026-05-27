<?php

namespace App\Messaging\Outbox;

/**
 * DTO для постановки одного сообщения в outbox.
 *
 * Передаётся в MsgOutboxRepositoryInterface::enqueue() / enqueueMany().
 */
readonly class MsgOutboxEntry
{
    /**
     * @param  string  $id  UUID v7
     * @param  string  $exchange  Имя AMQP-exchange
     * @param  string  $routingKey  Routing key
     * @param  array<string, mixed>  $payload  Тело сообщения (будет сериализовано в JSON)
     * @param  array<string, mixed>  $headers  Transport-заголовки (request_id, retry_attempt и пр.)
     */
    public function __construct(
        public string $id,
        public string $exchange,
        public string $routingKey,
        public array $payload,
        public array $headers = [],
    ) {}
}
