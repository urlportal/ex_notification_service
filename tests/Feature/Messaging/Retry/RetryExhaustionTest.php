<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Models\Notification;

/**
 * Четыре временных сбоя подряд -> dropped с attempts=4. Новая retry-копия
 * после 4-й попытки не создаётся, DLQ остаётся пустым.
 */
class RetryExhaustionTest extends RetryTestCase
{
    public function test_four_temporary_failures_lead_to_dropped(): void
    {
        $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new TemporaryGatewayException('persistent failure'),
        );

        $notification = $this->createQueuedNotification(['delivery_profile' => 'transactional']);

        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        // Исходная + 3 retry, всего 4 попытки.
        $this->consume('transactional', 1);
        $this->drainRetryQueueAndConsume('notifications.retry.30s.transactional', self::QUEUE_TRANSACTIONAL, 1);
        $this->drainRetryQueueAndConsume('notifications.retry.5m.transactional', self::QUEUE_TRANSACTIONAL, 1);
        $this->drainRetryQueueAndConsume('notifications.retry.30m.transactional', self::QUEUE_TRANSACTIONAL, 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Dropped, $fresh->status);
        $this->assertNotNull($fresh->dropped_at);
        $this->assertSame('persistent failure', $fresh->last_error);
        $this->assertSame(4, $fresh->attempts);

        // После 4-й попытки новая retry-копия не публикуется.
        $this->assertSame(3, $this->countMsgOutboxRetryCopies((string) $notification->id));

        // Все 4 сообщения подтверждены (ack), ни одно не ушло в DLQ.
        $this->assertNull($this->drainDlq());
    }
}
