<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Ошибка публикации retry-копии в msg_outbox пробрасывается как
 * unhandled-исключение. Транзакция откатывается и сообщение уходит в DLQ.
 */
class OutboxPublishFailureTest extends RetryTestCase
{
    public function test_outbox_enqueue_failure_rolls_back_and_routes_to_dlq(): void
    {
        $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new TemporaryGatewayException('temp'),
        );

        $this->app->bind(MsgOutboxRepositoryInterface::class, function (): MsgOutboxRepositoryInterface {
            return new class implements MsgOutboxRepositoryInterface
            {
                public function enqueue(MsgOutboxEntry $entry): void
                {
                    throw new \RuntimeException('simulated constraint violation');
                }

                public function enqueueMany(MsgOutboxEntry ...$entries): void {}

                public function pullPendingBatch(int $limit): array
                {
                    return [];
                }

                public function markPublishedMany(array $ids): void {}

                public function markFailed(string $id, string $error): void {}

                public function incrementAttempts(string $id): void {}
            };
        });

        $notification = $this->createQueuedNotification(['delivery_profile' => 'transactional']);
        $messageId = $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);

        // Транзакция откатилась: attempts остался 0, last_error пуст,
        // msg_inbox не содержит исходный message_id, msg_outbox пуст.
        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->last_error);
        $this->assertSame(
            0,
            DB::table('msg_inbox')
                ->where('message_id', $messageId)
                ->where('consumer_name', 'sender-transactional')
                ->count(),
        );
        $this->assertSame(0, $this->countMsgOutboxRetryCopies((string) $notification->id));

        // Сообщение nack(requeue=false) -> DLQ.
        $dlqMessage = $this->drainDlq();
        $this->assertNotNull($dlqMessage);
        $this->assertSame($messageId, (string) $dlqMessage->get('message_id'));
    }
}
