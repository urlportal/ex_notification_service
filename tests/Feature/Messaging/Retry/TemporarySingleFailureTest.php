<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * При одном TemporaryGatewayException sender НЕ финализирует уведомление,
 * а публикует retry-копию в msg_outbox с уровнем 30s и тем же приоритетом.
 */
class TemporarySingleFailureTest extends RetryTestCase
{
    public function test_single_temporary_failure_schedules_retry_copy(): void
    {
        $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new TemporaryGatewayException('provider unavailable'),
        );

        $notification = $this->createQueuedNotification([
            'delivery_profile' => 'transactional',
        ]);

        $requestId = 'req-single-temp';
        $originalMessageId = $this->publishSend(
            (string) $notification->id,
            self::QUEUE_TRANSACTIONAL,
            headers: ['request_id' => $requestId],
        );

        $this->consume('transactional', 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Queued, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertSame('provider unavailable', $fresh->last_error);
        $this->assertNull($fresh->dropped_at);
        $this->assertNull($fresh->sent_at);

        $this->assertSame(1, $this->countMsgOutboxRetryCopies((string) $notification->id));
        $row = $this->assertMsgOutboxRetryCopy((string) $notification->id, '30s.transactional');

        $this->assertNotSame($originalMessageId, (string) $row['id']);

        $headers = json_decode((string) $row['headers'], true);
        $this->assertSame($requestId, $headers['request_id']);
        $this->assertSame(1, $headers['retry_attempt']);

        $this->assertDatabaseHas('msg_inbox', [
            'message_id' => $originalMessageId,
            'consumer_name' => 'sender-transactional',
        ]);

        $this->assertNull($this->drainDlq());
        $this->assertSame(0, DB::table('msg_outbox')
            ->where('id', $originalMessageId)
            ->count(), 'исходный message_id не должен попадать в msg_outbox как retry-копия');
    }
}
