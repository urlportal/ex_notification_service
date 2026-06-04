<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Models\Notification;
use App\Repositories\NotificationRepositoryInterface;

/**
 * Retry-копия, обрабатываемая для уже финализированного уведомления,
 * проходит «already finalized» путь без публикации новой retry-копии и
 * без изменений состояния.
 */
class AlreadyFinalizedOnRetryTest extends RetryTestCase
{
    public function test_retry_for_already_sent_notification_is_no_op(): void
    {
        $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new TemporaryGatewayException('temp'),
        );

        $notification = $this->createQueuedNotification(['delivery_profile' => 'transactional']);
        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);
        $this->assertSame(1, $this->countMsgOutboxRetryCopies((string) $notification->id));

        // Имитация финализации извне до возврата retry-копии.
        $repo = $this->app->make(NotificationRepositoryInterface::class);
        $repo->updateOnSent(
            id: (string) $notification->id,
            providerMessageId: 'external-pmid',
            sentAt: new \DateTimeImmutable,
            attempts: 1,
        );

        // Возврат retry-копии: обработчик увидит status=sent и отдаст ack.
        $this->drainRetryQueueAndConsume('notifications.retry.30s.transactional', self::QUEUE_TRANSACTIONAL, 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertSame('external-pmid', $fresh->provider_message_id);

        // Новая retry-копия не опубликована.
        $this->assertSame(1, $this->countMsgOutboxRetryCopies((string) $notification->id));
        $this->assertNull($this->drainDlq());
    }
}
