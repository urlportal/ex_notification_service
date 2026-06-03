<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\PermanentGatewayException;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Models\Notification;

/**
 * Permanent на retry-попытке моментально финализирует уведомление в dropped
 * без публикации новой retry-копии.
 */
class MixedFailureTest extends RetryTestCase
{
    public function test_permanent_on_retry_finalizes_without_new_copy(): void
    {
        $callCounter = 0;
        $this->bindGateway(
            NotificationChannel::Email,
            static function (Notification $n) use (&$callCounter): void {
                $callCounter++;
                if ($callCounter === 1) {
                    throw new TemporaryGatewayException('first temp');
                }
                throw new PermanentGatewayException('permanent on retry');
            },
        );

        $notification = $this->createQueuedNotification(['priority' => 'transactional']);
        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        // Попытка 1: temporary -> retry на 30s, status=Queued, attempts=1.
        $this->consume('transactional', 1);
        $afterFirst = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Queued, $afterFirst->status);
        $this->assertSame(1, $afterFirst->attempts);
        $this->assertSame(1, $this->countMsgOutboxRetryCopies((string) $notification->id));
        $this->assertMsgOutboxRetryCopy((string) $notification->id, '30s.transactional');

        // Попытка 2: permanent -> dropped, новой retry-копии нет.
        $this->drainRetryQueueAndConsume('notifications.retry.30s.transactional', self::QUEUE_TRANSACTIONAL, 1);
        $afterSecond = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Dropped, $afterSecond->status);
        $this->assertNotNull($afterSecond->dropped_at);
        $this->assertSame('permanent on retry', $afterSecond->last_error);
        $this->assertSame(2, $afterSecond->attempts);

        $this->assertSame(1, $this->countMsgOutboxRetryCopies((string) $notification->id));
        $this->assertNull($this->drainDlq());
    }
}
