<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class HappyPathTest extends SenderTestCase
{
    // Базовый happy-path: queued -> sent, заполняются sent_at, provider_message_id,
    // attempts, last_error сбрасывается; в msg_inbox - запись для sender-transactional.
    public function test_send_command_marks_notification_as_sent(): void
    {
        $spy = $this->bindSuccessGateway();

        $notification = $this->createQueuedNotification([
            'channel' => NotificationChannel::Email,
            'priority' => 'transactional',
            // last_error не пустой - проверим, что updateOnSent его сбросит
            'last_error' => 'previous attempt error',
        ]);

        $messageId = $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame('prov-'.$notification->id, $fresh->provider_message_id);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNull($fresh->last_error);

        $this->assertDatabaseHas('msg_inbox', [
            'message_id' => $messageId,
            'consumer_name' => 'sender-transactional',
        ]);

        $this->assertSame(1, $spy->calls, 'gateway должен быть вызван один раз');
    }

    // retry_attempt в заголовках -> attempts = retry_attempt + 1.
    public function test_attempts_reflect_retry_attempt_header(): void
    {
        $this->bindSuccessGateway();

        $notification = $this->createQueuedNotification();

        $this->publishSend(
            (string) $notification->id,
            self::QUEUE_TRANSACTIONAL,
            headers: ['retry_attempt' => 3],
        );

        $this->consume('transactional', 1);

        $this->assertSame(4, DB::table('notifications')->where('id', $notification->id)->value('attempts'));
    }

    // Полная проверка unknown profile вынесена в PassiveDeclareTest;
    // здесь сохраняется проверка, что неизвестное имя рассылки НЕ
    // открывает AMQP-соединение и возвращает INVALID (2).
    public function test_invalid_queue_option_exits_with_invalid(): void
    {
        $exitCode = Artisan::call('notifications:send', [
            '--queue' => 'bogus',
            '--max-messages' => 1,
        ]);
        $this->assertSame(2, $exitCode);
    }
}
