<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\EmailGatewayInterface;
use App\Gateways\GatewayResponse;
use App\Gateways\NotificationGatewayInterface;
use App\Gateways\SmsGatewayInterface;
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
 * Базовый класс для Feature-тестов sender-consumer'а.
 *
 * Намеренно без RefreshDatabase: команда выполняет транзакцию через
 * DatabaseManager и должна видеть committed-данные. Изоляция — ручной
 * TRUNCATE и drain очередей в setUp/tearDown.
 */
abstract class SenderTestCase extends TestCase
{
    protected const string QUEUE_TRANSACTIONAL = 'notifications.transactional';

    protected const string QUEUE_MARKETING = 'notifications.marketing';

    protected const string DLQ = 'notifications.dlq';

    protected const string RETRY_EXCHANGE = 'notifications.retry.exchange';

    // Шесть retry-очередей вида notifications.retry.<level>.<priority>.
    // Их нужно очищать в setUp/tearDown, чтобы остатки от предыдущего теста
    // не вернулись в основную очередь через DL routing-key и не нарушили изоляцию.
    protected const array RETRY_QUEUES = [
        'notifications.retry.30s.transactional',
        'notifications.retry.30s.marketing',
        'notifications.retry.5m.transactional',
        'notifications.retry.5m.marketing',
        'notifications.retry.30m.transactional',
        'notifications.retry.30m.marketing',
    ];

    protected AmqpConnectionFactory $amqpFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->amqpFactory = new AmqpConnectionFactory;
        $this->truncateTables();
        $this->drainQueue(self::QUEUE_TRANSACTIONAL);
        $this->drainQueue(self::QUEUE_MARKETING);
        $this->drainQueue(self::DLQ);
        foreach (self::RETRY_QUEUES as $retryQueue) {
            $this->drainQueue($retryQueue);
        }
    }

    protected function tearDown(): void
    {
        $this->drainQueue(self::QUEUE_TRANSACTIONAL);
        $this->drainQueue(self::QUEUE_MARKETING);
        $this->drainQueue(self::DLQ);
        foreach (self::RETRY_QUEUES as $retryQueue) {
            $this->drainQueue($retryQueue);
        }
        $this->amqpFactory->close();
        $this->truncateTables();
        parent::tearDown();
    }

    protected function truncateTables(): void
    {
        DB::statement('TRUNCATE TABLE notifications, msg_outbox, msg_inbox, subscribers RESTART IDENTITY CASCADE');
    }

    protected function drainQueue(string $queue): void
    {
        try {
            $channel = $this->amqpFactory->connection()->channel();
            while ($channel->basic_get($queue, no_ack: true) !== null) {
            }
            $channel->close();
        } catch (\Throwable) {
            // Очередь может быть недоступна; для очистки игнорируем.
        }
    }

    /**
     * Публикует send-команду в указанную приоритетную очередь.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function publishSend(
        string $notificationId,
        string $queue,
        ?string $messageId = null,
        array $headers = [],
    ): string {
        $messageId ??= Uuid::uuid7()->toString();
        if (! array_key_exists('request_id', $headers)) {
            $headers['request_id'] = Uuid::uuid7()->toString();
        }

        $props = [
            'message_id' => $messageId,
            'content_type' => 'application/json',
            'application_headers' => new AMQPTable($headers),
        ];

        $channel = $this->amqpFactory->connection()->channel();
        $channel->basic_publish(
            new AMQPMessage(json_encode(['notification_id' => $notificationId], JSON_THROW_ON_ERROR), $props),
            '',
            $queue,
        );
        $channel->close();

        return $messageId;
    }

    /**
     * Кладёт произвольный (в т.ч. невалидный) body в очередь.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function publishRaw(
        string $body,
        string $queue,
        ?string $messageId = null,
        array $headers = [],
    ): string {
        $messageId ??= Uuid::uuid7()->toString();
        if (! array_key_exists('request_id', $headers)) {
            $headers['request_id'] = Uuid::uuid7()->toString();
        }
        $props = [
            'message_id' => $messageId,
            'content_type' => 'application/json',
            'application_headers' => new AMQPTable($headers),
        ];
        $channel = $this->amqpFactory->connection()->channel();
        $channel->basic_publish(new AMQPMessage($body, $props), '', $queue);
        $channel->close();

        return $messageId;
    }

    /**
     * Прогоняет команду notifications:send на $maxMessages сообщений.
     *
     * @param  string  $queue  имя рассылки из DeliveryProfile
     */
    protected function consume(string $queue, int $maxMessages): void
    {
        $exitCode = Artisan::call('notifications:send', [
            '--queue' => $queue,
            '--max-messages' => $maxMessages,
        ]);
        $this->assertSame(0, $exitCode);
    }

    protected function drainDlq(): ?AMQPMessage
    {
        $channel = $this->amqpFactory->connection()->channel();
        $message = $channel->basic_get(self::DLQ, no_ack: true);
        $channel->close();

        return $message instanceof AMQPMessage ? $message : null;
    }

    /**
     * Создаёт уведомление со статусом queued (или переопределённым).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createQueuedNotification(array $overrides = []): Notification
    {
        $subscriber = Subscriber::create([
            'email' => 'sub-'.Uuid::uuid7()->toString().'@example.com',
        ]);

        $attributes = array_merge([
            'id' => Uuid::uuid7()->toString(),
            'subscriber_id' => $subscriber->id,
            'channel' => NotificationChannel::Email,
            'priority' => 'transactional',
            'status' => NotificationStatus::Queued,
            'message' => 'test message',
            'idempotency_key' => Uuid::uuid7()->toString(),
            'request_id' => Uuid::uuid7()->toString(),
            'attempts' => 0,
            'queued_at' => now(),
        ], $overrides);

        return Notification::create($attributes);
    }

    /**
     * Подменяет канальный gateway тестовым double и возвращает spy.
     *
     * @param  callable(Notification): GatewayResponse  $sendBehavior  поведение send()
     */
    protected function bindGateway(NotificationChannel $channel, callable $sendBehavior): GatewaySpy
    {
        $spy = new GatewaySpy($sendBehavior);

        $double = new class($spy) implements NotificationGatewayInterface
        {
            public function __construct(private readonly GatewaySpy $spy) {}

            public function send(Notification $notification): GatewayResponse
            {
                return $this->spy->record($notification);
            }
        };

        $iface = match ($channel) {
            NotificationChannel::Email => EmailGatewayInterface::class,
            NotificationChannel::Sms => SmsGatewayInterface::class,
        };
        $this->app->bind($iface, fn () => $double);

        return $spy;
    }

    /**
     * success-double: возвращает GatewayResponse с сгенерированным
     * provider_message_id.
     */
    protected function bindSuccessGateway(NotificationChannel $channel = NotificationChannel::Email): GatewaySpy
    {
        return $this->bindGateway($channel, static fn (Notification $n): GatewayResponse => new GatewayResponse(
            providerMessageId: 'prov-'.$n->id,
            acceptedAt: new \DateTimeImmutable,
        ));
    }
}
