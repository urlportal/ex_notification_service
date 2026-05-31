<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Gateways\GatewayResponse;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Прогрессивный backoff 30s -> 5m -> 30m -> успех.
 *
 * После трёх временных сбоёв подряд и одного успеха уведомление в Sent,
 * attempts=4, в msg_outbox три retry-копии в порядке backoff;
 * request_id одинаков во всех retry-копиях.
 */
class RetryHappyTest extends RetryTestCase
{
    public function test_three_failures_then_success_produces_sent(): void
    {
        $callCounter = 0;
        $this->bindGateway(
            NotificationChannel::Email,
            static function (Notification $n) use (&$callCounter): GatewayResponse {
                $callCounter++;
                if ($callCounter < 4) {
                    throw new TemporaryGatewayException("attempt #{$callCounter} temp");
                }

                return new GatewayResponse(
                    providerMessageId: 'pmid-final',
                    acceptedAt: new \DateTimeImmutable,
                );
            },
        );

        $notification = $this->createQueuedNotification(['priority' => 'transactional']);
        $requestId = 'req-happy-R';

        $this->publishSend(
            (string) $notification->id,
            self::QUEUE_TRANSACTIONAL,
            headers: ['request_id' => $requestId],
        );

        // Попытка 1: первый temporary -> retry на уровне 30s.
        $this->consume('transactional', 1);
        $this->assertMsgOutboxRetryCopy((string) $notification->id, '30s.transactional');

        // Попытка 2: retry возвращается -> второй temporary -> retry на 5m.
        $this->waitForRetryReturnAndConsume(self::QUEUE_TRANSACTIONAL, 1);
        $this->assertMsgOutboxRetryCopy((string) $notification->id, '5m.transactional');

        // Попытка 3: третий temporary -> retry на 30m.
        $this->waitForRetryReturnAndConsume(self::QUEUE_TRANSACTIONAL, 1);
        $this->assertMsgOutboxRetryCopy((string) $notification->id, '30m.transactional');

        // Попытка 4: успех.
        $this->waitForRetryReturnAndConsume(self::QUEUE_TRANSACTIONAL, 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertSame('pmid-final', $fresh->provider_message_id);
        $this->assertSame(4, $fresh->attempts);
        $this->assertNull($fresh->last_error);

        $this->assertSame(3, $this->countMsgOutboxRetryCopies((string) $notification->id));

        // Порядок retry-копий по created_at и retry_attempt:
        // 1 -> 30s, 2 -> 5m, 3 -> 30m.
        $copies = DB::table('msg_outbox')
            ->where('exchange', self::RETRY_EXCHANGE)
            ->whereRaw("payload->>'notification_id' = ?", [(string) $notification->id])
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $copies);
        $expectedKeys = ['30s.transactional', '5m.transactional', '30m.transactional'];
        foreach ($copies as $index => $copy) {
            $this->assertSame($expectedKeys[$index], $copy->routing_key);
            $headers = json_decode((string) $copy->headers, true);
            $this->assertSame($index + 1, $headers['retry_attempt']);
            $this->assertSame($requestId, $headers['request_id']);
        }

        $this->assertSame(4, $callCounter);
        $this->assertNull($this->drainDlq());
    }
}
