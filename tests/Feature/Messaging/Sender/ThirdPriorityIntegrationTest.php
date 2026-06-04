<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationChannel;
use App\Messaging\DeliveryProfile;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Wire\AMQPTable;
use Ramsey\Uuid\Uuid;

/**
 * "internal" включается одним конфиг-изменением плюс правкой топологии
 */
class ThirdPriorityIntegrationTest extends SenderTestCase
{
    private const string TOKEN = 'phpunit-test-token';

    private const string INTERNAL_PRIORITY = 'internal';

    private const string INTERNAL_QUEUE = 'notifications.internal.test';

    /** @var array<string, mixed> */
    private array $originalPriorities;

    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.api_token' => self::TOKEN]);

        // Сохраняем оригинальный профиль и добавляем третий приоритет через config.
        $this->originalPriorities = (array) config('notifications.delivery_profiles');
        config(['notifications.delivery_profiles.'.self::INTERNAL_PRIORITY => [
            'queue' => self::INTERNAL_QUEUE,
            'workers' => 1,
            'prefetch' => 5,
        ]]);
        $this->app->forgetInstance(DeliveryProfile::class);

        // Объявляем тестовую очередь и binding напрямую через amqplib,
        // с теми же arguments, что у transactional/marketing.
        $channel = $this->amqpFactory->connection()->channel();
        $channel->queue_declare(
            self::INTERNAL_QUEUE,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable([
                'x-queue-type' => 'quorum',
                'x-dead-letter-exchange' => 'notifications.dlx',
                'x-delivery-limit' => 5,
                'x-quorum-initial-group-size' => 1,
            ])
        );
        $channel->queue_bind(self::INTERNAL_QUEUE, 'notifications.exchange', self::INTERNAL_PRIORITY);
        $channel->close();

        $this->drainQueue(self::INTERNAL_QUEUE);
        $this->drainQueue('notifications.bulk-expand');
        Redis::flushdb();
    }

    protected function tearDown(): void
    {
        $this->drainQueue(self::INTERNAL_QUEUE);
        $this->drainQueue('notifications.bulk-expand');

        // Удаляем тестовую очередь, чтобы dev-broker остался чистым.
        try {
            $channel = $this->amqpFactory->connection()->channel();
            $channel->queue_delete(self::INTERNAL_QUEUE);
            $channel->close();
        } catch (\Throwable) {
            // Не падаем в tearDown, если очередь уже не существует.
        }

        // Восстанавливаем оригинальный профиль.
        config(['notifications.delivery_profiles' => $this->originalPriorities]);
        $this->app->forgetInstance(DeliveryProfile::class);

        parent::tearDown();
    }

    // Sender напрямую через AMQP. Проверяем, что команда работает с новым именем приоритета без правок PHP-кода.
    public function test_sender_processes_new_priority_via_amqp(): void
    {
        $this->bindSuccessGateway();

        $notification = $this->createQueuedNotification([
            'delivery_profile' => self::INTERNAL_PRIORITY,
        ]);
        $this->publishSend((string) $notification->id, self::INTERNAL_QUEUE);

        $exitCode = Artisan::call('notifications:send', [
            '--queue' => self::INTERNAL_PRIORITY,
            '--max-messages' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertSame(
            'sent',
            DB::table('notifications')->where('id', $notification->id)->value('status'),
        );
    }

    // HTTP-уровень. POST /bulk c delivery_profile=internal проходит валидацию
    // (catalog->names() содержит новое имя), проходит цепочка
    // relay -> bulk-expand -> relay -> sender, все уведомления sent.
    public function test_http_bulk_endpoint_accepts_and_processes_new_priority(): void
    {
        $this->bindSuccessGateway();

        $count = 3;
        $subscriberIds = [];
        for ($i = 0; $i < $count; $i++) {
            $subscriberIds[] = Subscriber::create(['email' => "internal{$i}@example.com"])->id;
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'Idempotency-Key' => Uuid::uuid4()->toString(),
            'X-Request-Id' => Uuid::uuid7()->toString(),
        ])->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'delivery_profile' => self::INTERNAL_PRIORITY,
            'message' => 'third-priority',
            'subscriber_ids' => $subscriberIds,
        ]);
        $response->assertStatus(202);
        $bulkRequestId = $response->json('bulk_request_id');

        $this->runRelay();
        $this->consumeBulkExpand(1);
        $this->runRelay();
        $this->consume(self::INTERNAL_PRIORITY, $count);

        $this->assertSame(
            $count,
            DB::table('notifications')
                ->where('bulk_request_id', $bulkRequestId)
                ->where('status', 'sent')
                ->count(),
        );

        // Каждое уведомление имеет delivery_profile=internal в БД (string-поле).
        $profiles = DB::table('notifications')
            ->where('bulk_request_id', $bulkRequestId)
            ->pluck('delivery_profile')
            ->all();
        foreach ($profiles as $p) {
            $this->assertSame(self::INTERNAL_PRIORITY, $p);
        }
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
