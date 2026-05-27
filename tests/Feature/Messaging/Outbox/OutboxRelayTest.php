<?php

namespace Tests\Feature\Messaging\Outbox;

use App\Messaging\AmqpConnectionFactory;
use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use App\Messaging\Outbox\OutboxMessagePublisher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Feature-тесты для OutboxRelayCommand.
 *
 * Намеренно НЕ используют RefreshDatabase: relay-команда выполняет
 * SELECT...FOR UPDATE SKIP LOCKED внутри собственной транзакции,
 * которая должна видеть committed-данные. RefreshDatabase оборачивает
 * тест в outer-транзакцию, и строки, вставленные в ней, не проходят
 * «snapshot barrier» для SKIP LOCKED в nested savepoint.
 *
 * setUp/tearDown вручную очищают msg_outbox, AMQP-инфраструктура
 * (exchange/queue) создаётся и удаляется через amqplib.
 */
class OutboxRelayTest extends TestCase
{
    private AmqpConnectionFactory $amqpFactory;

    private MsgOutboxRepositoryInterface $repo;

    /** Имя тестового AMQP-exchange, уникальное для каждого теста. */
    private string $testExchange;

    /** Имя тестовой очереди, уникальное для каждого теста. */
    private string $testQueue;

    /** Routing key, которым очередь привязана к exchange. */
    private string $testRoutingKey = 'relay-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->amqpFactory = app(AmqpConnectionFactory::class);
        $this->repo = app(MsgOutboxRepositoryInterface::class);

        // Уникальные имена на каждый тест-ран (для отсутствия коллизий при параллельном запуске)
        $suffix = substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 8);
        $this->testExchange = 'test.outbox.'.$suffix;
        $this->testQueue = 'test.outbox.'.$suffix;

        $channel = $this->amqpFactory->connection()->channel();
        $channel->exchange_declare($this->testExchange, 'direct', false, false, true);
        $channel->queue_declare($this->testQueue, false, false, false, true);
        $channel->queue_bind($this->testQueue, $this->testExchange, $this->testRoutingKey);
        $channel->close();

        DB::table('msg_outbox')->truncate();
    }

    protected function tearDown(): void
    {
        // "Осушаем" очередь и удаляем AMQP-инфраструктуру теста.
        try {
            $channel = $this->amqpFactory->connection()->channel();
            while ($channel->basic_get($this->testQueue, true) !== null) {
            }
            $channel->queue_delete($this->testQueue);
            $channel->exchange_delete($this->testExchange);
            $channel->close();
        } catch (\Throwable) {
            // Если соединение уже закрыто, tearDown не должен падать.
        }

        DB::table('msg_outbox')->truncate();

        parent::tearDown();
    }

    /**
     * Happy path: одна pending-строка, одна итерация relay, сообщение в очереди,
     * строка имеет status=published, published_at заполнен.
     */
    public function test_happy_path_message_is_published_and_marked_published(): void
    {
        $id = Uuid::uuid7()->toString();
        $requestId = Uuid::uuid7()->toString();

        $this->repo->enqueue(new MsgOutboxEntry(
            id: $id,
            exchange: $this->testExchange,
            routingKey: $this->testRoutingKey,
            payload: ['event' => 'test', 'value' => 42],
            headers: ['request_id' => $requestId],
        ));

        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--batch-size' => 1,
            '--max-iterations' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        // Проверка сообщения в RabbitMQ
        $channel = $this->amqpFactory->connection()->channel();
        /** @var AMQPMessage|null $message */
        $message = $channel->basic_get($this->testQueue, true);
        $channel->close();

        $this->assertNotNull($message);

        $body = json_decode($message->body, true);
        $this->assertEquals(['event' => 'test', 'value' => 42], $body);

        // message_id = outbox.id
        $this->assertSame($id, $message->get('message_id'));

        // delivery_mode = persistent
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));

        // application_headers
        /** @var AMQPTable $headers */
        $headers = $message->get('application_headers');
        $this->assertSame($requestId, $headers->getNativeData()['request_id']);

        // Проверка БД
        $row = DB::table('msg_outbox')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('published', $row->status);
        $this->assertNotNull($row->published_at);
    }

    /**
     * Когда outbox пуст, relay завершает все итерации без ошибок.
     */
    public function test_idle_with_empty_outbox_command_completes_successfully(): void
    {
        // outbox пуст, т.к. truncate в setUp гарантирует это.
        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--idle-sleep-ms' => 10, // маленький sleep, чтобы тест не тормозил
            '--max-iterations' => 2,
        ]);
        $this->assertSame(0, $exitCode);

        $this->assertSame(0, DB::table('msg_outbox')->count());
    }

    /**
     * Когда wait_for_pending_acks_returns бросает AMQPTimeoutException,
     * сообщения попадают в unconfirmedIds и остаются в статусе pending (at-least-once).
     * Поведение "recovery" - публикация при следующей итерации.
     */
    public function test_publisher_confirms_timeout_leaves_rows_pending(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->repo->enqueue(new MsgOutboxEntry(
            id: $id,
            exchange: $this->testExchange,
            routingKey: $this->testRoutingKey,
            payload: ['event' => 'timeout-test'],
            headers: [],
        ));

        // Stub-канал, который бросает AMQPTimeoutException при ожидании подтверждений.
        $timeoutChannel = new class
        {
            public function confirm_select(bool $nowait = false): void {}

            public function set_ack_handler(mixed $callback): void {}

            public function set_nack_handler(mixed $callback): void {}

            public function set_return_listener(mixed $callback): void {}

            public function basic_publish(mixed ...$args): void {}

            public function wait_for_pending_acks_returns(float $timeout = 0): void
            {
                throw new AMQPTimeoutException('Simulated publisher confirms timeout');
            }

            public function is_open(): bool
            {
                // false - close() в finally не будет вызван
                return false;
            }

            /** @param  array<mixed>  $methodSig */
            public function close(int $replyCode = 0, string $replyText = '', array $methodSig = [0, 0]): void {}
        };

        // Mock-соединение, возвращающее stub-канал.
        $mockConnection = $this->createMock(AbstractConnection::class);
        $mockConnection->method('channel')->willReturn($timeoutChannel);

        // Mock-фабрика, возвращающая mock-соединение.
        $timeoutFactory = $this->createMock(AmqpConnectionFactory::class);
        $timeoutFactory->method('connection')->willReturn($mockConnection);

        // Переопределяем publisher напрямую, чтобы избежать кэширования команды Artisan.
        $this->app->singleton(OutboxMessagePublisher::class, fn () => new OutboxMessagePublisher(
            $timeoutFactory,
            app(LoggerInterface::class),
        ));

        // Итерация с timeout-фабрикой: строки должны остаться pending.
        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--batch-size' => 1,
            '--max-iterations' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        $row = DB::table('msg_outbox')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
    }

    /**
     * Сообщение с routing_key, не привязанным к очереди, возвращается брокером
     * (basic.return, mandatory=true). Строка должна получить status=failed,
     * а в логах появится error-запись с exchange/routing_key/message_id.
     */
    public function test_mandatory_return_marks_row_as_failed(): void
    {
        $id = Uuid::uuid7()->toString();
        $requestId = Uuid::uuid7()->toString();

        // Вставляем строку с routing_key без привязки к очереди.
        $this->repo->enqueue(new MsgOutboxEntry(
            id: $id,
            exchange: $this->testExchange,
            routingKey: 'nonexistent.key',  // нет binding, значит в basic.return
            payload: ['event' => 'mandatory-test'],
            headers: ['request_id' => $requestId],
        ));

        // Шпионим за логами до запуска relay.
        Log::spy();

        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--batch-size' => 1,
            '--max-iterations' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        // Строка должна быть failed.
        $row = DB::table('msg_outbox')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('unrouted or nack', $row->last_error);

        // В логах должен быть error с exchange, routing_key, message_id.
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                return str_contains($message, 'Unrouted AMQP message')
                    && isset($context['exchange'], $context['routing_key'], $context['message_id']);
            });
    }

    /**
     * Первое сообщение из батча получает nack через mock-канал.
     * Оно должно получить status=failed, остальные - status=published.
     */
    public function test_nack_from_broker_marks_first_row_as_failed_rest_published(): void
    {
        // Вставляем 3 строки
        $ids = [
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
            Uuid::uuid7()->toString(),
        ];

        foreach ($ids as $id) {
            $this->repo->enqueue(new MsgOutboxEntry(
                id: $id,
                exchange: $this->testExchange,
                routingKey: $this->testRoutingKey,
                payload: ['id' => $id],
                headers: [],
            ));
        }

        // Stub-канал: вызывает nack для первого сообщения, ack для остальных.
        $nackChannel = new class
        {
            /** @var list<string> */
            private array $publishedMessageIds = [];

            private ?\Closure $ackHandler = null;

            private ?\Closure $nackHandler = null;

            public function confirm_select(bool $nowait = false): void {}

            public function set_ack_handler(\Closure $callback): void
            {
                $this->ackHandler = $callback;
            }

            public function set_nack_handler(\Closure $callback): void
            {
                $this->nackHandler = $callback;
            }

            public function set_return_listener(mixed $callback): void {}

            public function basic_publish(AMQPMessage $msg, string $exchange = '', string $routingKey = '', bool $mandatory = false, bool $immediate = false, ?int $ticket = null): void
            {
                $this->publishedMessageIds[] = (string) $msg->get('message_id');
            }

            public function wait_for_pending_acks_returns(float $timeout = 0): void
            {
                $isFirst = true;
                foreach ($this->publishedMessageIds as $messageId) {
                    $ackMsg = new AMQPMessage('', ['message_id' => $messageId]);
                    if ($isFirst && $this->nackHandler !== null) {
                        ($this->nackHandler)($ackMsg);
                        $isFirst = false;
                    } elseif (! $isFirst && $this->ackHandler !== null) {
                        ($this->ackHandler)($ackMsg);
                    }
                }
            }

            public function is_open(): bool
            {
                return false;
            }

            /** @param  array<mixed>  $methodSig */
            public function close(int $replyCode = 0, string $replyText = '', array $methodSig = [0, 0]): void {}
        };

        $mockConnection = $this->createMock(AbstractConnection::class);
        $mockConnection->method('channel')->willReturn($nackChannel);

        $nackFactory = $this->createMock(AmqpConnectionFactory::class);
        $nackFactory->method('connection')->willReturn($mockConnection);

        $this->app->instance(AmqpConnectionFactory::class, $nackFactory);
        $this->app->singleton(OutboxMessagePublisher::class, fn () => new OutboxMessagePublisher(
            $nackFactory,
            app(LoggerInterface::class),
        ));

        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--batch-size' => 3,
            '--max-iterations' => 1,
        ]);
        $this->assertSame(0, $exitCode);

        // Первая строка "failed" (nack).
        $firstRow = DB::table('msg_outbox')->where('id', $ids[0])->first();
        $this->assertNotNull($firstRow);
        $this->assertSame('failed', $firstRow->status);
        $this->assertSame('unrouted or nack', $firstRow->last_error);

        // Остальные строки "published" (ack).
        foreach (array_slice($ids, 1) as $ackId) {
            $ackRow = DB::table('msg_outbox')->where('id', $ackId)->first();
            $this->assertNotNull($ackRow);
            $this->assertSame('published', $ackRow->status);
        }

        // Восстанавливаем реальные bindings.
        $this->app->forgetInstance(AmqpConnectionFactory::class);
        $this->app->forgetInstance(OutboxMessagePublisher::class);
        $this->app->singleton(AmqpConnectionFactory::class);
        $this->app->singleton(OutboxMessagePublisher::class);
    }

    /**
     * Relay-процесс, запущенный с бесконечным циклом, корректно завершается
     * по SIGTERM: выходит с кодом 0, все ранее опубликованные строки в статусе published.
     *
     * @group graceful-shutdown
     */
    public function test_graceful_shutdown_on_sigterm(): void
    {
        $totalRows = 5;
        $exchange = $this->testExchange;
        $routingKey = $this->testRoutingKey;

        // Вставляем строки, которые relay обработает до перехода в idle-режим.
        for ($rowIndex = 0; $rowIndex < $totalRows; $rowIndex++) {
            $this->repo->enqueue(new MsgOutboxEntry(
                id: Uuid::uuid7()->toString(),
                exchange: $exchange,
                routingKey: $routingKey,
                payload: ['seq' => $rowIndex],
                headers: [],
            ));
        }

        $artisan = base_path('artisan');
        $env = array_merge($_ENV, $_SERVER, ['APP_ENV' => 'testing']);

        // Длинный idle-sleep-ms (10000 мс) гарантирует, что после обработки строк
        // процесс засыпает. Именно в этот момент посылаем SIGTERM.
        $process = new Process(
            ['php', $artisan, 'messaging:outbox-relay', '--batch-size=5', '--idle-sleep-ms=10000', '--max-iterations=0'],
            base_path(),
            $env,
        );
        $process->setTimeout(30);
        $process->start();

        // Ждём, пока relay обработает строки и войдёт в idle-sleep
        sleep(2);

        // Посылаем SIGTERM
        $process->signal(SIGTERM);

        // Ждём завершения
        $process->wait();

        $this->assertFalse($process->isRunning(), 'Процесс должен завершиться после SIGTERM');
        $this->assertSame(0, $process->getExitCode(), 'Exit code должен быть 0: '.$process->getErrorOutput());

        // Все строки должны быть опубликованы до того, как relay ушёл в idle-sleep.
        $pendingCount = DB::table('msg_outbox')->where('status', 'pending')->count();
        $publishedCount = DB::table('msg_outbox')->where('status', 'published')->count();
        $this->assertSame(0, $pendingCount, 'После graceful shutdown не должно остаться pending строк');
        $this->assertSame($totalRows, $publishedCount, 'Все строки должны быть published');
    }
}
