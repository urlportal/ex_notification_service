<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Атомарность retry-публикации. Если процесс упал между commit и ack
 * исходного сообщения, при следующей доставке того же message_id consumer-side
 * idempotency через msg_inbox отбросит обработку и не добавит вторую
 * retry-копию.
 */
class RetryAtomicityTest extends RetryTestCase
{
    public function test_redelivery_of_same_message_id_does_not_duplicate_retry(): void
    {
        $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new TemporaryGatewayException('temp'),
        );

        $notification = $this->createQueuedNotification(['priority' => 'transactional']);

        $duplicateMessageId = Uuid::uuid7()->toString();
        $this->publishSend(
            (string) $notification->id,
            self::QUEUE_TRANSACTIONAL,
            messageId: $duplicateMessageId,
            headers: ['retry_attempt' => 0],
        );

        // Первая обработка: temporary -> retry-копия + запись msg_inbox.
        $this->consume('transactional', 1);
        $this->assertSame(1, $this->countMsgOutboxRetryCopies((string) $notification->id));

        // Имитация повторной доставки того же message_id (kill между commit и ack).
        $this->publishSend(
            (string) $notification->id,
            self::QUEUE_TRANSACTIONAL,
            messageId: $duplicateMessageId,
            headers: ['retry_attempt' => 0],
        );
        $this->consume('transactional', 1);

        // Атомарность сохранилась: новой retry-копии нет, attempts не вырос.
        $this->assertSame(1, $this->countMsgOutboxRetryCopies((string) $notification->id));

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Queued, $fresh->status);
        $this->assertSame(1, $fresh->attempts);

        // msg_inbox ровно одна запись для исходного message_id.
        $this->assertSame(
            1,
            DB::table('msg_inbox')
                ->where('message_id', $duplicateMessageId)
                ->where('consumer_name', 'sender-transactional')
                ->count(),
        );
    }
}
