<?php

namespace App\Http\DTO\Notifications;

use App\Enums\NotificationChannel;

readonly class BulkSendNotificationDto
{
    /**
     * @param  array<int, string>  $subscriberIds
     */
    public function __construct(
        public NotificationChannel $channel,
        public string $deliveryProfile,
        public string $message,
        public array $subscriberIds,
        public string $idempotencyKey,
    ) {}
}
