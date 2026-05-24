<?php

namespace App\Http\DTO\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;

readonly class BulkSendNotificationDto
{
    /**
     * @param  array<int, string>  $subscriberIds
     */
    public function __construct(
        public NotificationChannel $channel,
        public NotificationPriority $priority,
        public string $message,
        public array $subscriberIds,
        public string $idempotencyKey,
    ) {}
}
