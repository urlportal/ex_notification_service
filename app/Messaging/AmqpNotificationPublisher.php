<?php

namespace App\Messaging;

use App\Models\Notification;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * @deprecated Прямая публикация уведомлений из HTTP заменена на Transactional Outbox
 *             (приём пишет строку в msg_outbox, публикует OutboxRelay). Удаляется в F11.
 */
class AmqpNotificationPublisher implements NotificationPublisherInterface
{
    private ?AMQPChannel $channel = null;

    public function __construct(private readonly AmqpConnectionFactory $factory) {}

    public function publishForInitialSend(Notification $notification, string $requestId): void
    {
        $this->publish(
            exchange: 'notifications.exchange',
            routingKey: $notification->priority->value,
            body: json_encode(['notification_id' => $notification->id], JSON_THROW_ON_ERROR),
            headers: ['request_id' => $requestId, 'retry_attempt' => 0],
        );
    }

    public function publishForRetry(Notification $notification, int $nextAttempt, string $requestId): void
    {
        $this->publish(
            exchange: 'notifications.retry.exchange',
            routingKey: (string) $nextAttempt,
            body: json_encode(['notification_id' => $notification->id], JSON_THROW_ON_ERROR),
            headers: ['request_id' => $requestId, 'retry_attempt' => $nextAttempt],
        );
    }

    /** @param array<string, int|string> $headers */
    private function publish(string $exchange, string $routingKey, string $body, array $headers): void
    {
        $message = new AMQPMessage($body, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'application_headers' => new AMQPTable($headers),
        ]);

        $this->channel()->basic_publish($message, $exchange, $routingKey);
        // Ждём подтверждения регистрации сообщения у брокера
        $this->channel()->wait_for_pending_acks(timeout: 5);
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel === null || ! $this->channel->is_open()) {
            $this->channel = $this->factory->connection()->channel();
            // Включаем publisher confirms, чтобы wait_for_pending_acks() работал.
            $this->channel->confirm_select();
        }

        return $this->channel;
    }
}
