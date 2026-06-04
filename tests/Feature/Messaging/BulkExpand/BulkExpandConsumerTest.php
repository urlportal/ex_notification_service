<?php

namespace Tests\Feature\Messaging\BulkExpand;

use App\Messaging\AmqpConnectionFactory;
use App\Models\Notification;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Feature-тесты воркера bulk-expand-consumer.
 *
 * Намеренно не используют RefreshDatabase: consumer-команда выполняет
 * транзакцию через DatabaseManager и должна видеть committed-данные.
 * Изоляция реализуется ручным TRUNCATE в setUp/tearDown.
 */
class BulkExpandConsumerTest extends TestCase
{
    private const string BULK_EXPAND_QUEUE = 'notifications.bulk-expand';

    private const string DLQ = 'notifications.dlq';

    private AmqpConnectionFactory $amqpFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->amqpFactory = new AmqpConnectionFactory;
        $this->truncateTables();
        $this->drainQueue(self::BULK_EXPAND_QUEUE);
        $this->drainQueue(self::DLQ);
    }

    protected function tearDown(): void
    {
        $this->drainQueue(self::BULK_EXPAND_QUEUE);
        $this->drainQueue(self::DLQ);
        $this->amqpFactory->close();
        $this->truncateTables();
        parent::tearDown();
    }

    private function truncateTables(): void
    {
        DB::statement('TRUNCATE TABLE notifications, msg_outbox, msg_inbox, subscribers RESTART IDENTITY CASCADE');
    }

    private function drainQueue(string $queue): void
    {
        try {
            $channel = $this->amqpFactory->connection()->channel();
            while ($channel->basic_get($queue, no_ack: true) !== null) {
            }
            $channel->close();
        } catch (\Throwable) {
            // Очередь может быть пустой или недоступной. Игнорируем при очистке.
        }
    }

    /**
     * Публикует bulk-expand-событие в очередь notifications.bulk-expand
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    private function publishBulkExpand(array $body, ?string $messageId = null, array $headers = []): void
    {
        if ($messageId === null) {
            $messageId = Uuid::uuid7()->toString();
        }

        if (! array_key_exists('request_id', $headers)) {
            $headers['request_id'] = Uuid::uuid7()->toString();
        }

        $props = [
            'message_id' => $messageId,
            'content_type' => 'application/json',
        ];

        $props['application_headers'] = new AMQPTable($headers);

        $channel = $this->amqpFactory->connection()->channel();
        $channel->basic_publish(
            new AMQPMessage(json_encode($body, JSON_THROW_ON_ERROR), $props),
            '',
            self::BULK_EXPAND_QUEUE,
        );
        $channel->close();
    }

    /** Запускает consumer на N сообщений через artisan-команду */
    private function consumeOnce(int $messageCount = 1): void
    {
        $exitCode = Artisan::call('notifications:consume-bulk-expand', ['--max-messages' => $messageCount]);
        $this->assertSame(0, $exitCode);
    }

    /** Извлекает одно сообщение из DLQ (без ack) */
    private function drainDlq(): ?AMQPMessage
    {
        $channel = $this->amqpFactory->connection()->channel();
        $message = $channel->basic_get(self::DLQ, no_ack: true);
        $channel->close();

        return $message instanceof AMQPMessage ? $message : null;
    }

    /** @return list<string> */
    private function createSubscribers(int $count, string $prefix = 'sub'): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Subscriber::create(['email' => "{$prefix}{$i}@example.com"])->id;
        }

        return $ids;
    }

    /**
     * @param  list<string>  $subscriberIds
     * @return array<string, mixed>
     */
    private function bulkExpandBody(array $subscriberIds, string $bulkRequestId, string $deliveryProfile = 'transactional'): array
    {
        return [
            'bulk_request_id' => $bulkRequestId,
            'channel' => 'email',
            'delivery_profile' => $deliveryProfile,
            'message' => 'Тестовое сообщение',
            'subscriber_ids' => $subscriberIds,
            'idempotency_key' => Uuid::uuid7()->toString(),
        ];
    }

    public function test_expands_event_into_notifications_and_send_commands(): void
    {
        $subscriberIds = $this->createSubscribers(3);
        $bulkRequestId = Uuid::uuid7()->toString();
        $requestId = Uuid::uuid7()->toString();
        $messageId = Uuid::uuid7()->toString();

        $this->publishBulkExpand(
            $this->bulkExpandBody($subscriberIds, $bulkRequestId),
            $messageId,
            ['request_id' => $requestId],
        );

        $this->consumeOnce();

        // Три уведомления в статусе queued с верными полями
        $this->assertDatabaseCount('notifications', 3);
        foreach ($subscriberIds as $subscriberId) {
            $this->assertDatabaseHas('notifications', [
                'subscriber_id' => $subscriberId,
                'bulk_request_id' => $bulkRequestId,
                'channel' => 'email',
                'delivery_profile' => 'transactional',
                'status' => 'queued',
                'request_id' => $requestId,
            ]);
        }

        // Три send-команды с верными полями
        $this->assertDatabaseCount('msg_outbox', 3);
        $notificationIds = Notification::pluck('id')->all();
        foreach ($notificationIds as $notificationId) {
            $outbox = DB::table('msg_outbox')
                ->whereJsonContains('payload->notification_id', $notificationId)
                ->first();
            $this->assertNotNull($outbox, "send-команда для notification_id={$notificationId} не найдена");
            $this->assertSame('notifications.exchange', $outbox->exchange);
            $this->assertSame('transactional', $outbox->routing_key);
        }

        // Запись в msg_inbox
        $this->assertDatabaseHas('msg_inbox', [
            'message_id' => $messageId,
            'consumer_name' => 'bulk-expand-consumer',
        ]);
    }

    // Tolerant-фильтрация удалённых подписчиков
    public function test_skips_deleted_subscribers(): void
    {
        $subscriberIds = $this->createSubscribers(2);
        $ghostId = Uuid::uuid7()->toString(); // несуществующий
        $allIds = [...$subscriberIds, $ghostId];

        $messageId = Uuid::uuid7()->toString();
        $this->publishBulkExpand(
            $this->bulkExpandBody($allIds, Uuid::uuid7()->toString()),
            $messageId,
        );

        $this->consumeOnce();

        // Только существующие подписчики
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('msg_outbox', 2);

        // В DLQ ничего нет
        $this->assertNull($this->drainDlq());

        // Запись msg_inbox создана
        $this->assertDatabaseHas('msg_inbox', [
            'message_id' => $messageId,
            'consumer_name' => 'bulk-expand-consumer',
        ]);
    }

    // Идемпотентность повторной доставки
    public function test_idempotent_redelivery_creates_no_duplicates(): void
    {
        $subscriberIds = $this->createSubscribers(3);
        $messageId = Uuid::uuid7()->toString();
        $body = $this->bulkExpandBody($subscriberIds, Uuid::uuid7()->toString());

        $this->publishBulkExpand($body, $messageId);
        $this->consumeOnce();

        $this->publishBulkExpand($body, $messageId);
        $this->consumeOnce();

        // Не 6, а 3 строки
        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('msg_outbox', 3);

        // Одна запись в msg_inbox для данного consumer
        $inboxCount = DB::table('msg_inbox')
            ->where('message_id', $messageId)
            ->where('consumer_name', 'bulk-expand-consumer')
            ->count();
        $this->assertSame(1, $inboxCount);
    }

    // ON CONFLICT возвращает существующий ID
    public function test_on_conflict_returns_existing_id(): void
    {
        $subscriberIds = $this->createSubscribers(2);
        $bulkRequestId = Uuid::uuid7()->toString();

        // Предварительно создаём строку для первого подписчика
        $existingId = Uuid::uuid7()->toString();
        DB::table('notifications')->insert([
            'id' => $existingId,
            'subscriber_id' => $subscriberIds[0],
            'bulk_request_id' => $bulkRequestId,
            'channel' => 'email',
            'delivery_profile' => 'transactional',
            'status' => 'queued',
            'message' => 'pre-existing',
            'idempotency_key' => Uuid::uuid7()->toString(),
            'request_id' => null,
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->publishBulkExpand(
            $this->bulkExpandBody($subscriberIds, $bulkRequestId),
            Uuid::uuid7()->toString(),
        );

        $this->consumeOnce();

        // Дубля для первого подписчика нет
        $this->assertDatabaseCount('notifications', 2);

        // Send-команда для первого подписчика ссылается на предсуществующий ID
        $outboxForExisting = DB::table('msg_outbox')
            ->whereJsonContains('payload->notification_id', $existingId)
            ->first();
        $this->assertNotNull($outboxForExisting);
    }

    // Чанкование больших рассылок
    public function test_chunks_large_event(): void
    {
        config(['notifications.bulk_expand.chunk_size' => 100]);
        $subscriberIds = $this->createSubscribers(250, 'chunk');
        $bulkRequestId = Uuid::uuid7()->toString();

        $this->publishBulkExpand(
            $this->bulkExpandBody($subscriberIds, $bulkRequestId),
            Uuid::uuid7()->toString(),
        );

        $this->consumeOnce();

        $this->assertDatabaseCount('notifications', 250);
        $this->assertDatabaseCount('msg_outbox', 250);

        // Все уведомления принадлежат одному bulk_request_id в статусе queued
        $this->assertSame(250, DB::table('notifications')
            ->where('bulk_request_id', $bulkRequestId)
            ->where('status', 'queued')
            ->count());
    }

    // Битый payload уходит в DLQ
    public function test_invalid_payload_goes_to_dlq(): void
    {
        $messageId = Uuid::uuid7()->toString();

        // Тело без обязательного ключа channel
        $this->publishBulkExpand(
            [
                'bulk_request_id' => Uuid::uuid7()->toString(),
                'delivery_profile' => 'transactional',
                'message' => 'missing channel',
                'subscriber_ids' => [],
                'idempotency_key' => Uuid::uuid7()->toString(),
            ],
            $messageId,
        );

        $this->consumeOnce();

        // Сообщение в DLQ
        $dlqMessage = $this->drainDlq();
        $this->assertNotNull($dlqMessage, 'ожидалось сообщение в DLQ');

        // БД без изменений
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('msg_outbox', 0);

        // Транзакция откатилась, поэтому запись inbox не создана
        $this->assertDatabaseMissing('msg_inbox', [
            'message_id' => $messageId,
            'consumer_name' => 'bulk-expand-consumer',
        ]);
    }

    // Невалидный JSON
    public function test_invalid_json_goes_to_dlq(): void
    {
        $messageId = Uuid::uuid7()->toString();
        $props = [
            'message_id' => $messageId,
            'content_type' => 'application/json',
        ];

        $channel = $this->amqpFactory->connection()->channel();
        $channel->basic_publish(
            new AMQPMessage('not valid json {{{', $props),
            '',
            self::BULK_EXPAND_QUEUE,
        );
        $channel->close();

        $this->consumeOnce();

        $dlqMessage = $this->drainDlq();
        $this->assertNotNull($dlqMessage, 'ожидалось сообщение в DLQ для невалидного JSON');
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('msg_outbox', 0);
    }

    // Graceful shutdown
    public function test_sigterm_exits_zero_after_current_event(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork / posix_kill недоступны в тестовом окружении');
        }

        $subscriberIds = $this->createSubscribers(1);
        $this->publishBulkExpand(
            $this->bulkExpandBody($subscriberIds, Uuid::uuid7()->toString()),
            Uuid::uuid7()->toString(),
        );

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork не удался');
        }

        if ($pid === 0) {
            // Дочерний процесс: запускаем consumer через exec, чтобы унаследованные
            // PHP-объекты (AMQP-соединения, БД и т.д.) не блокировали shutdown.
            pcntl_exec(PHP_BINARY, [base_path('artisan'), 'notifications:consume-bulk-expand']);
            exit(1);
        }

        // Родительский процесс: ждём обработки события, затем шлём SIGTERM.
        usleep(1_500_000); // 1.5 секунды достаточно для обработки одного события
        posix_kill($pid, SIGTERM);

        $status = 0;
        pcntl_waitpid($pid, $status);
        $exitCode = pcntl_wexitstatus($status);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('notifications', 1);
    }
}
