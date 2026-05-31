<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Gateways\GatewayResponse;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Приоритет сохраняется через retry: transactional retry-копия после
 * паузы возвращается в notifications.transactional и обрабатывается
 * sender-transactional; для marketing - симметрично.
 */
class RoutingKeyPreservationTest extends RetryTestCase
{
    public function test_transactional_retry_returns_to_transactional_lane(): void
    {
        $callCounter = 0;
        $this->bindGateway(
            NotificationChannel::Email,
            static function (Notification $n) use (&$callCounter): GatewayResponse {
                $callCounter++;
                if ($callCounter === 1) {
                    throw new TemporaryGatewayException('temp');
                }

                return new GatewayResponse('pmid-tx', new \DateTimeImmutable);
            },
        );

        $notification = $this->createQueuedNotification(['priority' => 'transactional']);
        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);
        $this->assertMsgOutboxRetryCopy((string) $notification->id, '30s.transactional');

        $this->waitForRetryReturnAndConsume(self::QUEUE_TRANSACTIONAL, 1);

        // В msg_inbox две записи (исходная и retry-копия). Обе под consumer_name = sender-transactional.
        $this->assertSame(
            2,
            DB::table('msg_inbox')
                ->where('consumer_name', 'sender-transactional')
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('msg_inbox')
                ->where('consumer_name', 'sender-marketing')
                ->count(),
        );

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertSame(2, $fresh->attempts);
    }

    public function test_marketing_retry_returns_to_marketing_lane(): void
    {
        $callCounter = 0;
        $this->bindGateway(
            NotificationChannel::Email,
            static function (Notification $n) use (&$callCounter): GatewayResponse {
                $callCounter++;
                if ($callCounter === 1) {
                    throw new TemporaryGatewayException('temp');
                }

                return new GatewayResponse('pmid-mk', new \DateTimeImmutable);
            },
        );

        $notification = $this->createQueuedNotification(['priority' => 'marketing']);
        $this->publishSend((string) $notification->id, self::QUEUE_MARKETING);

        $this->consume('marketing', 1);
        $this->assertMsgOutboxRetryCopy((string) $notification->id, '30s.marketing');

        $this->waitForRetryReturnAndConsume(self::QUEUE_MARKETING, 1);

        $this->assertSame(
            2,
            DB::table('msg_inbox')
                ->where('consumer_name', 'sender-marketing')
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('msg_inbox')
                ->where('consumer_name', 'sender-transactional')
                ->count(),
        );

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Sent, $fresh->status);
        $this->assertSame(2, $fresh->attempts);
    }
}
