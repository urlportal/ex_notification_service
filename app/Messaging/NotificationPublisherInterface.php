<?php

namespace App\Messaging;

use App\Models\Notification;

interface NotificationPublisherInterface
{
    public function publishForInitialSend(Notification $notification, string $requestId): void;

    public function publishForRetry(Notification $notification, int $nextAttempt, string $requestId): void;
}
