<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\PermanentGatewayException;
use App\Models\Notification;

class PermanentErrorTest extends SenderTestCase
{
    // PermanentGatewayException -> dropped с заполненными dropped_at и last_error.
    public function test_permanent_failure_marks_notification_as_dropped(): void
    {
        $spy = $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new PermanentGatewayException('provider rejected'),
        );

        $notification = $this->createQueuedNotification();
        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Dropped, $fresh->status);
        $this->assertNotNull($fresh->dropped_at);
        $this->assertSame('provider rejected', $fresh->last_error);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNull($fresh->sent_at);
        $this->assertNull($fresh->provider_message_id);

        // Сообщение НЕ в DLQ - это финализация, а не битый payload.
        $this->assertNull($this->drainDlq());

        $this->assertSame(1, $spy->calls);
    }
}
