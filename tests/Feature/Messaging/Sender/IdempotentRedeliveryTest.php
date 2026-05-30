<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

class IdempotentRedeliveryTest extends SenderTestCase
{
    // Повторная доставка той же send-команды (тот же message_id) не вызывает
    // gateway повторно и не меняет status/attempts/provider_message_id.
    public function test_redelivery_skips_via_msg_inbox(): void
    {
        $spy = $this->bindSuccessGateway();

        $notification = $this->createQueuedNotification();
        $messageId = $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $providerMessageId = $fresh->provider_message_id;
        $sentAt = $fresh->sent_at;
        $this->assertSame(1, $spy->calls);

        // Повторно публикуем с тем же message_id.
        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL, $messageId);
        $this->consume('transactional', 1);

        // gateway не вызывался повторно.
        $this->assertSame(1, $spy->calls, 'redelivery не должен вызывать gateway повторно');

        $afterRedelivery = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Sent, $afterRedelivery->status);
        $this->assertSame(1, $afterRedelivery->attempts);
        $this->assertSame($providerMessageId, $afterRedelivery->provider_message_id);
        $this->assertEquals($sentAt, $afterRedelivery->sent_at);

        // Запись в msg_inbox по-прежнему одна.
        $this->assertSame(
            1,
            DB::table('msg_inbox')
                ->where('message_id', $messageId)
                ->where('consumer_name', 'sender-transactional')
                ->count(),
        );
    }
}
