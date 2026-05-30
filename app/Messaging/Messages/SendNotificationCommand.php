<?php

namespace App\Messaging\Messages;

use App\Exceptions\Messaging\InvalidSendCommandException;

/**
 * DTO разобранного payload send-команды из приоритетной очереди
 * (notifications.transactional / notifications.marketing).
 */
readonly class SendNotificationCommand
{
    public function __construct(public string $notificationId) {}

    /**
     * @param  mixed  $payload  результат json_decode($body, true)
     */
    public static function fromArray(mixed $payload): self
    {
        if (! is_array($payload)) {
            throw new InvalidSendCommandException('send command payload is not a JSON object');
        }

        $notificationId = $payload['notification_id'] ?? null;

        if (! is_string($notificationId) || $notificationId === '') {
            throw new InvalidSendCommandException('send command payload: notification_id is missing or invalid');
        }

        return new self($notificationId);
    }
}
