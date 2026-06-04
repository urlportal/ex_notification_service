<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Gateways\GatewayResponse;
use App\Logging\RequestIdProcessor;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/**
 * request_id из исходного сообщения наследуется через все retry-копии в
 * msg_outbox и присутствует в extra каждого лога sender-скрипта
 */
class RequestIdInheritanceTest extends RetryTestCase
{
    public function test_request_id_propagates_through_retry_chain(): void
    {
        $logHandler = new TestHandler;
        $monolog = Log::getLogger();
        $monolog->pushHandler($logHandler);
        $monolog->pushProcessor(new RequestIdProcessor);

        $callCounter = 0;
        $this->bindGateway(
            NotificationChannel::Email,
            static function (Notification $n) use (&$callCounter): GatewayResponse {
                $callCounter++;
                if ($callCounter < 4) {
                    throw new TemporaryGatewayException("temp #{$callCounter}");
                }

                return new GatewayResponse('pmid-trace', new \DateTimeImmutable);
            },
        );

        $notification = $this->createQueuedNotification(['delivery_profile' => 'transactional']);
        $requestId = 'trace-R';
        $this->publishSend(
            (string) $notification->id,
            self::QUEUE_TRANSACTIONAL,
            headers: ['request_id' => $requestId],
        );

        $this->consume('transactional', 1);
        $this->drainRetryQueueAndConsume('notifications.retry.30s.transactional', self::QUEUE_TRANSACTIONAL, 1);
        $this->drainRetryQueueAndConsume('notifications.retry.5m.transactional', self::QUEUE_TRANSACTIONAL, 1);
        $this->drainRetryQueueAndConsume('notifications.retry.30m.transactional', self::QUEUE_TRANSACTIONAL, 1);

        $copies = DB::table('msg_outbox')
            ->where('exchange', self::RETRY_EXCHANGE)
            ->whereRaw("payload->>'notification_id' = ?", [(string) $notification->id])
            ->orderBy('created_at')
            ->get();
        $this->assertCount(3, $copies);
        foreach ($copies as $copy) {
            $headers = json_decode((string) $copy->headers, true);
            $this->assertSame(
                $requestId,
                $headers['request_id'],
                'request_id должен наследоваться во всех retry-копиях msg_outbox',
            );
        }

        $relevant = array_values(array_filter(
            $logHandler->getRecords(),
            static fn ($record): bool => in_array(
                $record->message,
                ['notification retry scheduled', 'notification sent'],
                true,
            ),
        ));
        $this->assertCount(
            4,
            $relevant,
            'ожидаются 3 warning retry scheduled + 1 info notification sent',
        );

        foreach ($relevant as $record) {
            $this->assertSame(
                $requestId,
                $record->extra['request_id'] ?? null,
                'request_id ожидается в extra каждого лога sender\'а',
            );
        }
    }
}
