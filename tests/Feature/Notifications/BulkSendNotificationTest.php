<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Messaging\AmqpConnectionFactory;
use App\Messaging\NotificationPublisherInterface;
use App\Models\Notification;
use App\Models\Subscriber;
use App\Services\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class BulkSendNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'phpunit-test-token';

    private const QUEUES_TO_DRAIN = [
        'notifications.transactional',
        'notifications.marketing',
    ];

    /** Отдельное соединение для чтения из очередей в тестах — не разделяется с паблишером */
    private AmqpConnectionFactory $readFactory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.api_token' => self::TOKEN]);
        $this->readFactory = new AmqpConnectionFactory;
        $this->drainQueues();
        Redis::flushdb();
    }

    protected function tearDown(): void
    {
        $this->readFactory->close();
        parent::tearDown();
    }

    private function drainQueues(): void
    {
        $channel = $this->readFactory->connection()->channel();
        foreach (self::QUEUES_TO_DRAIN as $queue) {
            while ($channel->basic_get($queue, no_ack: true) !== null) {
            }
        }
        $channel->close();
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    /** @return array<int, string> */
    private function makeSubscriberIds(int $count, string $prefix = 'sub'): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Subscriber::create(['email' => "{$prefix}{$i}@example.com"])->id;
        }

        return $ids;
    }

    /** @return array<int, AMQPMessage> */
    private function readMessages(string $queue, int $expected): array
    {
        $channel = $this->readFactory->connection()->channel();
        $messages = [];
        for ($i = 0; $i < $expected; $i++) {
            $msg = $channel->basic_get($queue, no_ack: true);
            if ($msg !== null) {
                $messages[] = $msg;
            }
        }
        $channel->close();

        return $messages;
    }

    public function test_bulk_send_returns_202_with_correct_structure(): void
    {
        $subscriberIds = $this->makeSubscriberIds(3);
        $idempotencyKey = Uuid::uuid4()->toString();

        $response = $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello, subscriber!',
            'subscriber_ids' => $subscriberIds,
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['bulk_request_id', 'accepted', 'notification_ids'])
            ->assertJsonPath('accepted', 3)
            ->assertJsonCount(3, 'notification_ids');

        // Проверяем наличие записей в БД
        $this->assertDatabaseCount('notifications', 3);
        foreach ($response->json('notification_ids') as $notificationId) {
            $this->assertDatabaseHas('notifications', [
                'id' => $notificationId,
                'status' => NotificationStatus::Queued->value,
                'idempotency_key' => $idempotencyKey,
                'bulk_request_id' => $response->json('bulk_request_id'),
            ]);
        }

        // Проверяем сообщения в RabbitMQ
        $messages = $this->readMessages('notifications.transactional', 3);
        $this->assertCount(3, $messages);

        foreach ($messages as $message) {
            $body = json_decode($message->body, true);
            $this->assertArrayHasKey('notification_id', $body);
            $this->assertContains($body['notification_id'], $response->json('notification_ids'));

            $headers = $message->get('application_headers')->getNativeData();
            $this->assertSame(0, $headers['retry_attempt']);
        }
    }

    public function test_routing_key_matches_priority(): void
    {
        $transactionalIds = $this->makeSubscriberIds(2, 'trn');
        $marketingIds = $this->makeSubscriberIds(2, 'mkt');

        // Оба запроса отправляем до чтения очередей, чтобы не переиспользовать каналы между запросами
        $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => Uuid::uuid4()->toString(),
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Transactional message',
            'subscriber_ids' => $transactionalIds,
        ])->assertStatus(202);

        $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => Uuid::uuid4()->toString(),
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Sms->value,
            'priority' => NotificationPriority::Marketing->value,
            'message' => 'Marketing message',
            'subscriber_ids' => $marketingIds,
        ])->assertStatus(202);

        // Каждый приоритет должен попасть только в соответствующую очередь
        $transactionalMessages = $this->readMessages('notifications.transactional', 4);
        $marketingMessages = $this->readMessages('notifications.marketing', 4);

        $this->assertCount(2, $transactionalMessages);
        $this->assertCount(2, $marketingMessages);
    }

    public function test_idempotent_repeat_request_returns_cached_response(): void
    {
        $subscriberIds = $this->makeSubscriberIds(2);
        $idempotencyKey = Uuid::uuid4()->toString();

        $firstResponse = $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello!',
            'subscriber_ids' => $subscriberIds,
        ]);

        $firstResponse->assertStatus(202);
        $bulkRequestId = $firstResponse->json('bulk_request_id');

        // Сбрасываем очередь чтобы не считать уже опубликованные сообщения
        $this->drainQueues();

        $secondResponse = $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello!',
            'subscriber_ids' => $subscriberIds,
        ]);

        $secondResponse->assertStatus(202)
            ->assertJsonPath('bulk_request_id', $bulkRequestId);

        // Количество записей не изменилось
        $this->assertDatabaseCount('notifications', 2);

        // В очереди нет новых сообщений
        $messages = $this->readMessages('notifications.transactional', 5);
        $this->assertCount(0, $messages);
    }

    public function test_concurrent_request_with_same_key_returns_409(): void
    {
        $subscriberIds = $this->makeSubscriberIds(1);
        $idempotencyKey = Uuid::uuid4()->toString();

        // Имитируем параллельный запрос: вручную захватываем блокировку
        $idempotencyService = app(IdempotencyService::class);
        $idempotencyService->acquire($idempotencyKey, 60);

        $response = $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello!',
            'subscriber_ids' => $subscriberIds,
        ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'Request is being processed']);
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $subscriberIds = $this->makeSubscriberIds(1);

        $response = $this->withHeaders($this->auth())
            ->postJson('/api/v1/notifications/bulk', [
                'channel' => NotificationChannel::Email->value,
                'priority' => NotificationPriority::Transactional->value,
                'message' => 'Hello!',
                'subscriber_ids' => $subscriberIds,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['idempotency_key']]);
    }

    public function test_missing_subscriber_ids_returns_422_with_list(): void
    {
        $validSubscriber = Subscriber::create(['email' => 'valid@example.com']);
        $nonExistentId = Uuid::uuid4()->toString();

        $countBefore = Notification::count();

        $response = $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => Uuid::uuid4()->toString(),
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello!',
            'subscriber_ids' => [$validSubscriber->id, $nonExistentId],
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'missing_subscriber_ids'])
            ->assertJsonFragment(['missing_subscriber_ids' => [$nonExistentId]]);

        $this->assertSame($countBefore, Notification::count());
    }

    public function test_request_id_from_header_propagates(): void
    {
        $subscriberIds = $this->makeSubscriberIds(2);
        $requestId = Uuid::uuid7()->toString();

        $response = $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => Uuid::uuid4()->toString(),
            'X-Request-ID' => $requestId,
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello!',
            'subscriber_ids' => $subscriberIds,
        ]);

        $response->assertStatus(202);

        // request_id в каждой записи БД
        foreach ($response->json('notification_ids') as $notificationId) {
            $this->assertDatabaseHas('notifications', [
                'id' => $notificationId,
                'request_id' => $requestId,
            ]);
        }

        // request_id в application_headers сообщений RabbitMQ
        $messages = $this->readMessages('notifications.transactional', 2);
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $headers = $message->get('application_headers')->getNativeData();
            $this->assertSame($requestId, $headers['request_id']);
        }
    }

    public function test_request_id_generated_when_header_absent(): void
    {
        $subscriberIds = $this->makeSubscriberIds(2);

        $response = $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => Uuid::uuid4()->toString(),
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello!',
            'subscriber_ids' => $subscriberIds,
        ]);

        $response->assertStatus(202);

        // Все записи должны иметь одинаковый сгенерированный request_id
        $notificationIds = $response->json('notification_ids');
        $requestIds = Notification::whereIn('id', $notificationIds)
            ->pluck('request_id')
            ->unique()
            ->values()
            ->all();

        $this->assertCount(1, $requestIds);
        $this->assertNotNull($requestIds[0]);

        // UUID v7 — 36 символов в формате xxxxxxxx-xxxx-7xxx-xxxx-xxxxxxxxxxxx
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestIds[0]
        );
    }

    public function test_publish_failure_leaves_notifications_queued(): void
    {
        $subscriberIds = $this->makeSubscriberIds(2);
        $idempotencyKey = Uuid::uuid4()->toString();

        $mockPublisher = $this->createMock(NotificationPublisherInterface::class);
        $mockPublisher->expects($this->any())
            ->method('publishForInitialSend')
            ->willThrowException(new \RuntimeException('AMQP connection failed'));

        $this->app->instance(NotificationPublisherInterface::class, $mockPublisher);

        $logHandler = new TestHandler;
        Log::getLogger()->pushHandler($logHandler);

        $response = $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello!',
            'subscriber_ids' => $subscriberIds,
        ]);

        $response->assertStatus(202);

        // Записи в БД созданы и сохраняют статус queued
        $this->assertDatabaseCount('notifications', 2);
        foreach ($response->json('notification_ids') as $notificationId) {
            $this->assertDatabaseHas('notifications', [
                'id' => $notificationId,
                'status' => NotificationStatus::Queued->value,
            ]);
        }

        // В логах зафиксирована ошибка публикации
        $this->assertTrue($logHandler->hasRecords(Level::Error));
        $this->assertTrue(
            $logHandler->hasRecordThatContains('Ошибка публикации уведомления в RabbitMQ', Level::Error)
        );
    }
}
