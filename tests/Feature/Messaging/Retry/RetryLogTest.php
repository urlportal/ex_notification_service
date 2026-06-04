<?php

namespace Tests\Feature\Messaging\Retry;

use App\Enums\NotificationChannel;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Logging\RequestIdProcessor;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;

/**
 * При принятии решения о retry sender записывает один warning-лог
 * `notification retry scheduled` со всеми обязательными полями.
 */
class RetryLogTest extends RetryTestCase
{
    public function test_retry_decision_writes_warning_log_with_required_fields(): void
    {
        $logHandler = new TestHandler;
        $monolog = Log::getLogger();
        $monolog->pushHandler($logHandler);
        $monolog->pushProcessor(new RequestIdProcessor);

        $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new TemporaryGatewayException('boom'),
        );

        $notification = $this->createQueuedNotification(['delivery_profile' => 'transactional']);
        $requestId = 'req-retrylog';
        $this->publishSend(
            (string) $notification->id,
            self::QUEUE_TRANSACTIONAL,
            headers: ['request_id' => $requestId],
        );

        $this->consume('transactional', 1);

        $retryLogs = array_values(array_filter(
            $logHandler->getRecords(),
            static fn ($record): bool => $record->message === 'notification retry scheduled',
        ));
        $this->assertCount(1, $retryLogs, 'ожидается ровно один retry-лог');

        $record = $retryLogs[0];
        $this->assertSame(Level::Warning, $record->level);
        $this->assertSame((string) $notification->id, $record->context['notification_id']);
        $this->assertSame('email', $record->context['channel']);
        $this->assertSame('transactional', $record->context['delivery_profile']);
        $this->assertSame(1, $record->context['attempts']);
        $this->assertSame('30s', $record->context['next_level']);
        $this->assertSame('boom', $record->context['last_error']);
        $this->assertSame($requestId, $record->extra['request_id'] ?? null);

        // Для retry-сценария info-логов sender-скрипта быть не должно.
        $infoSent = array_filter(
            $logHandler->getRecords(),
            static fn ($record): bool => $record->message === 'notification sent',
        );
        $this->assertEmpty($infoSent);
    }
}
