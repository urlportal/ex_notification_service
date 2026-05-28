<?php

namespace App\Messaging;

use App\Models\Notification;

/**
 * @deprecated Прямая публикация уведомлений из HTTP заменена на Transactional Outbox
 *             (приём пишет строку в msg_outbox, публикует OutboxRelay). Удаляется в F11.
 */
interface NotificationPublisherInterface
{
    public function publishForInitialSend(Notification $notification, string $requestId): void;

    public function publishForRetry(Notification $notification, int $nextAttempt, string $requestId): void;
}
