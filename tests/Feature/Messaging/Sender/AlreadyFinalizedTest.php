<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationStatus;
use App\Models\Notification;

class AlreadyFinalizedTest extends SenderTestCase
{
    // Уведомление уже не в queued (например, delivered после callback) ->
    // gateway не вызывается, БД не меняется, ack.
    public function test_already_finalized_notification_is_skipped(): void
    {
        $spy = $this->bindSuccessGateway();

        $notification = $this->createQueuedNotification([
            'status' => NotificationStatus::Delivered,
            'sent_at' => now(),
            'provider_message_id' => 'prov-existing',
            'attempts' => 5,
        ]);

        $messageId = $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Delivered, $fresh->status);
        $this->assertSame('prov-existing', $fresh->provider_message_id);
        $this->assertSame(5, $fresh->attempts);

        // gateway НЕ вызывался.
        $this->assertSame(0, $spy->calls);

        // ack произошёл -> msg_inbox содержит запись для этого consumer'а.
        $this->assertDatabaseHas('msg_inbox', [
            'message_id' => $messageId,
            'consumer_name' => 'sender-transactional',
        ]);

        // В DLQ ничего не ушло.
        $this->assertNull($this->drainDlq());
    }
}
