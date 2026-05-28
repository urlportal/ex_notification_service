<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Messaging\AmqpConnectionFactory;
use App\Messaging\Outbox\MsgOutbox;
use App\Messaging\Outbox\OutboxStatus;
use App\Models\Subscriber;
use App\Services\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Намеренно не используем RefreshDatabase: тест прогоняет настоящий
 * OutboxRelay (messaging:outbox-relay), а его SELECT...FOR UPDATE SKIP LOCKED
 * должен видеть committed-строки. Поэтому изоляция (ручной TRUNCATE в
 * setUp/tearDown (как в OutboxRelayTest)), а не транзакция-обёртка.
 */
class BulkSendNotificationTest extends TestCase
{
    private const TOKEN = 'phpunit-test-token';

    private const FANOUT_QUEUE = 'notifications.fanout';

    private AmqpConnectionFactory $readFactory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.api_token' => self::TOKEN]);
        $this->readFactory = new AmqpConnectionFactory;
        $this->truncateTables();
        $this->drainFanout();
        Redis::flushdb();
    }

    protected function tearDown(): void
    {
        $this->drainFanout();
        $this->readFactory->close();
        $this->truncateTables();
        parent::tearDown();
    }

    private function truncateTables(): void
    {
        DB::statement('TRUNCATE TABLE notifications, msg_outbox, subscribers RESTART IDENTITY CASCADE');
    }

    private function drainFanout(): void
    {
        $channel = $this->readFactory->connection()->channel();
        while ($channel->basic_get(self::FANOUT_QUEUE, no_ack: true) !== null) {
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

    /** @return array<int, string> */
    private function nonExistentIds(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Uuid::uuid7()->toString();
        }

        return $ids;
    }

    /** Запускает одну итерацию OutboxRelay */
    private function runRelay(): void
    {
        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--batch-size' => 100,
            '--max-iterations' => 1,
        ]);
        $this->assertSame(0, $exitCode);
    }

    /** @return array<int, AMQPMessage> */
    private function readFanout(int $max): array
    {
        $channel = $this->readFactory->connection()->channel();
        $messages = [];
        for ($i = 0; $i < $max; $i++) {
            $message = $channel->basic_get(self::FANOUT_QUEUE, no_ack: true);
            if ($message !== null) {
                $messages[] = $message;
            }
        }
        $channel->close();

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function postBulk(array $body, array $headers = []): TestResponse
    {
        $defaults = [
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::Transactional->value,
            'message' => 'Hello, subscriber!',
        ];

        return $this->withHeaders(array_merge($this->auth(), [
            'Idempotency-Key' => Uuid::uuid4()->toString(),
        ], $headers))->postJson('/api/v1/notifications/bulk', array_merge($defaults, $body));
    }

    // Асинхронный приём через outbox

    public function test_bulk_send_accepts_and_enqueues_single_outbox_row(): void
    {
        $subscriberIds = $this->makeSubscriberIds(3);

        $response = $this->postBulk(['subscriber_ids' => $subscriberIds]);

        $response->assertStatus(202)
            ->assertJsonStructure(['bulk_request_id', 'accepted', 'invalid_subscriber_ids'])
            ->assertJsonPath('accepted', 3)
            ->assertJsonPath('invalid_subscriber_ids', []);

        // notifications не создаются в этой фиче
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('msg_outbox', 1);

        /** @var MsgOutbox $row */
        $row = MsgOutbox::query()->firstOrFail();
        $this->assertSame(OutboxStatus::Pending, $row->status);
        $this->assertSame('notifications.fanout.exchange', $row->exchange);
        $this->assertSame('fanout', $row->routing_key);
        $this->assertNull($row->published_at);

        $this->assertSame($response->json('bulk_request_id'), $row->payload['bulk_request_id']);
        $this->assertSame(NotificationChannel::Email->value, $row->payload['channel']);
        $this->assertSame(NotificationPriority::Transactional->value, $row->payload['priority']);
        $this->assertSame('Hello, subscriber!', $row->payload['message']);
        $this->assertSame($subscriberIds, $row->payload['subscriber_ids']);
        $this->assertArrayHasKey('idempotency_key', $row->payload);
        $this->assertArrayHasKey('request_id', $row->headers);
        $this->assertSame(0, $row->headers['retry_attempt']);

        // До relay очередь пуста
        $this->assertCount(0, $this->readFanout(3));

        // Публикуем через relay
        $this->runRelay();

        $messages = $this->readFanout(3);
        $this->assertCount(1, $messages);

        $message = $messages[0];
        $this->assertSame($row->id, $message->get('message_id'));

        $headers = $message->get('application_headers')->getNativeData();
        $this->assertArrayHasKey('request_id', $headers);

        $payload = json_decode($message->body, true);
        $this->assertSame($subscriberIds, $payload['subscriber_ids']);
        $this->assertSame($response->json('bulk_request_id'), $payload['bulk_request_id']);

        // Строка теперь published
        $row->refresh();
        $this->assertSame(OutboxStatus::Published, $row->status);
        $this->assertNotNull($row->published_at);
    }

    public function test_accept_emits_single_info_log(): void
    {
        $logHandler = new TestHandler;
        Log::getLogger()->pushHandler($logHandler);

        $subscriberIds = $this->makeSubscriberIds(2);

        $this->postBulk(['subscriber_ids' => $subscriberIds])->assertStatus(202);

        $accepted = array_values(array_filter(
            $logHandler->getRecords(),
            static fn ($record): bool => $record->message === 'bulk notification accepted',
        ));

        $this->assertCount(1, $accepted);
        $context = $accepted[0]->context;
        $this->assertArrayHasKey('request_id', $context);
        $this->assertArrayHasKey('bulk_request_id', $context);
        $this->assertNotNull($context['outbox_id']);
        $this->assertSame(2, $context['accepted']);
        $this->assertSame(0, $context['invalid_count']);
    }

    // Tolerant ingest

    public function test_tolerant_ingest_accepts_existing_and_returns_invalid(): void
    {
        $existing = $this->makeSubscriberIds(5);
        $invalid = $this->nonExistentIds(2);

        // Перемешиваем порядок: существующие и несуществующие чередуются
        $mixed = [
            $existing[0], $invalid[0], $existing[1], $existing[2],
            $invalid[1], $existing[3], $existing[4],
        ];

        $response = $this->postBulk(['subscriber_ids' => $mixed]);

        $response->assertStatus(202)
            ->assertJsonPath('accepted', 5)
            ->assertJsonPath('invalid_subscriber_ids', $invalid);

        $this->assertDatabaseCount('msg_outbox', 1);

        /** @var MsgOutbox $row */
        $row = MsgOutbox::query()->firstOrFail();
        $this->assertSame($existing, $row->payload['subscriber_ids']);
    }

    public function test_all_invalid_subscribers_accepts_zero_and_writes_no_outbox(): void
    {
        $invalid = $this->nonExistentIds(3);

        $response = $this->postBulk(['subscriber_ids' => $invalid]);

        $response->assertStatus(202)
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('invalid_subscriber_ids', $invalid);

        $this->assertDatabaseCount('msg_outbox', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    // Идемпотентность

    public function test_idempotent_repeat_returns_cached_response_and_single_outbox_row(): void
    {
        $existing = $this->makeSubscriberIds(3);
        $invalid = $this->nonExistentIds(1);
        $key = Uuid::uuid4()->toString();

        $first = $this->postBulk(
            ['subscriber_ids' => array_merge($existing, $invalid)],
            ['Idempotency-Key' => $key],
        );
        $first->assertStatus(202);

        $second = $this->postBulk(
            ['subscriber_ids' => array_merge($existing, $invalid)],
            ['Idempotency-Key' => $key],
        );

        $second->assertStatus(202)
            ->assertJsonPath('bulk_request_id', $first->json('bulk_request_id'))
            ->assertJsonPath('accepted', $first->json('accepted'))
            ->assertJsonPath('invalid_subscriber_ids', $first->json('invalid_subscriber_ids'));

        $this->assertDatabaseCount('msg_outbox', 1);
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

    public function test_concurrent_request_with_same_key_returns_409(): void
    {
        $subscriberIds = $this->makeSubscriberIds(1);
        $key = Uuid::uuid4()->toString();

        // Имитируем параллельный запрос (вручную захватываем блокировку)
        app(IdempotencyService::class)->acquire($key, 60);

        $response = $this->postBulk(
            ['subscriber_ids' => $subscriberIds],
            ['Idempotency-Key' => $key],
        );

        $response->assertStatus(409)
            ->assertJson(['message' => 'Request is being processed']);

        $this->assertDatabaseCount('msg_outbox', 0);
    }

    // Сквозная трассировка request_id

    public function test_request_id_from_header_propagates_to_outbox_and_message(): void
    {
        $subscriberIds = $this->makeSubscriberIds(2);
        $requestId = Uuid::uuid7()->toString();

        $this->postBulk(
            ['subscriber_ids' => $subscriberIds],
            ['X-Request-ID' => $requestId],
        )->assertStatus(202);

        /** @var MsgOutbox $row */
        $row = MsgOutbox::query()->firstOrFail();
        $this->assertSame($requestId, $row->headers['request_id']);

        $this->runRelay();

        $messages = $this->readFanout(2);
        $this->assertCount(1, $messages);
        $headers = $messages[0]->get('application_headers')->getNativeData();
        $this->assertSame($requestId, $headers['request_id']);
    }

    public function test_request_id_generated_when_header_absent(): void
    {
        $subscriberIds = $this->makeSubscriberIds(2);

        $response = $this->postBulk(['subscriber_ids' => $subscriberIds]);
        $response->assertStatus(202);

        /** @var MsgOutbox $row */
        $row = MsgOutbox::query()->firstOrFail();
        $generated = $row->headers['request_id'];

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $generated,
        );
        // Тот же id вернулся клиенту в заголовке ответа
        $this->assertSame($response->headers->get('X-Request-ID'), $generated);
    }

    // Конфигурируемый лимит подписчиков

    public function test_exceeding_max_subscribers_returns_422(): void
    {
        config(['notifications.bulk.max_subscribers' => 5]);

        $tooMany = $this->nonExistentIds(6);

        $response = $this->postBulk(['subscriber_ids' => $tooMany]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['subscriber_ids']]);

        $this->assertDatabaseCount('msg_outbox', 0);
    }

    public function test_boundary_max_subscribers_is_accepted(): void
    {
        config(['notifications.bulk.max_subscribers' => 5]);

        $existing = $this->makeSubscriberIds(5);

        $this->postBulk(['subscriber_ids' => $existing])
            ->assertStatus(202)
            ->assertJsonPath('accepted', 5);
    }
}
