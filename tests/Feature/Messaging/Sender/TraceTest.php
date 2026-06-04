<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationChannel;
use App\Logging\RequestIdProcessor;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Monolog\Handler\TestHandler;
use Ramsey\Uuid\Uuid;

class TraceTest extends SenderTestCase
{
    private const string TOKEN = 'phpunit-test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.api_token' => self::TOKEN]);
        $this->drainQueue('notifications.bulk-expand');
        Redis::flushdb();
    }

    protected function tearDown(): void
    {
        $this->drainQueue('notifications.bulk-expand');
        parent::tearDown();
    }

    // POST /bulk с X-Request-Id -> цепочка bulk-expand + sender -> в логах
    // sender'а есть success-запись с этим request_id в extra.
    public function test_request_id_propagates_to_sender_success_log(): void
    {
        $this->bindSuccessGateway();

        // Подключаем TestHandler и RequestIdProcessor ДО прогона цепочки.
        // В тестовом env LOG_CHANNEL=null, поэтому tap AppendRequestId не
        // применяется автоматически; процессор нужно добавить явно (паттерн
        // RequestIdProcessorTest).
        $logHandler = new TestHandler;
        $monolog = Log::getLogger();
        $monolog->pushHandler($logHandler);
        $monolog->pushProcessor(new RequestIdProcessor);

        $requestId = Uuid::uuid7()->toString();

        // Создаём 2 подписчика.
        $subscriberIds = [];
        for ($i = 0; $i < 2; $i++) {
            $subscriberIds[] = Subscriber::create(['email' => "trace{$i}@example.com"])->id;
        }

        // POST /bulk с X-Request-Id.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'Idempotency-Key' => Uuid::uuid4()->toString(),
            'X-Request-Id' => $requestId,
        ])->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'delivery_profile' => 'transactional',
            'message' => 'trace test',
            'subscriber_ids' => $subscriberIds,
        ]);
        $response->assertStatus(202);

        // Прогон цепочки: relay -> bulk-expand -> relay -> sender.
        $this->runRelay();
        $this->consumeBulkExpand(1);
        $this->runRelay();
        $this->consume('transactional', 2);

        // Все уведомления sent.
        $this->assertSame(2, DB::table('notifications')->where('status', 'sent')->count());

        // В логах sender'а есть success-записи с искомым request_id в extra.
        $sent = array_values(array_filter(
            $logHandler->getRecords(),
            static fn ($record): bool => $record->message === 'notification sent',
        ));
        $this->assertNotEmpty($sent, 'ожидался хотя бы один success-лог sender\'а');

        $withRequestId = array_filter(
            $sent,
            static fn ($record): bool => ($record->extra['request_id'] ?? null) === $requestId,
        );
        $this->assertNotEmpty(
            $withRequestId,
            "ни один success-лог не содержит request_id={$requestId} в extra",
        );
    }

    private function runRelay(): void
    {
        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--batch-size' => 100,
            '--max-iterations' => 1,
        ]);
        $this->assertSame(0, $exitCode);
    }

    private function consumeBulkExpand(int $max): void
    {
        $exitCode = Artisan::call('notifications:consume-bulk-expand', ['--max-messages' => $max]);
        $this->assertSame(0, $exitCode);
    }
}
