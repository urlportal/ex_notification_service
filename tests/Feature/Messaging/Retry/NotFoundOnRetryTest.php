<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Models\Notification;

/**
 * Retry-копия для удалённого уведомления проходит «not found» путь
 * без публикации новой retry-копии.
 */
class NotFoundOnRetryTest extends RetryTestCase
{
    public function test_retry_for_deleted_notification_is_no_op(): void
    {
        $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new TemporaryGatewayException('temp'),
        );

        $notification = $this->createQueuedNotification(['priority' => 'transactional']);
        $notificationId = (string) $notification->id;
        $this->publishSend($notificationId, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);
        $this->assertSame(1, $this->countMsgOutboxRetryCopies($notificationId));

        Notification::query()->whereKey($notificationId)->delete();

        $this->drainRetryQueueAndConsume('notifications.retry.30s.transactional', self::QUEUE_TRANSACTIONAL, 1);

        // Уведомление по-прежнему отсутствует, новой retry-копии нет, DLQ пуст.
        $this->assertNull(Notification::query()->find($notificationId));
        $this->assertSame(1, $this->countMsgOutboxRetryCopies($notificationId));
        $this->assertNull($this->drainDlq());
    }
}
