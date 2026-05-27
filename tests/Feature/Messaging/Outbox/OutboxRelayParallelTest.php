<?php

namespace Tests\Feature\Messaging\Outbox;

use App\Messaging\AmqpConnectionFactory;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Тест конкурентного доступа двух relay-процессов через SKIP LOCKED.
 *
 * Запускает два экземпляра команды как отдельные OS-процессы, поэтому НЕ использует
 * RefreshDatabase (транзакция тестового процесса не видна дочерним процессам).
 * Вместо этого setUp/tearDown вручную очищают таблицу.
 *
 * Чтобы исключить тест из обычного быстрого запуска (он занимает ~10-30 с),
 * помечен аннотацией @group parallel.
 *
 * @group parallel
 */
class OutboxRelayParallelTest extends TestCase
{
    private AmqpConnectionFactory $amqpFactory;

    private string $testExchange;

    private string $testQueue;

    private string $testRoutingKey = 'relay-parallel';

    protected function setUp(): void
    {
        parent::setUp();

        $this->amqpFactory = app(AmqpConnectionFactory::class);

        $suffix = substr(str_replace('-', '', Uuid::uuid4()->toString()), 0, 8);
        $this->testExchange = 'test.parallel.'.$suffix;
        $this->testQueue = 'test.parallel.'.$suffix;

        $channel = $this->amqpFactory->connection()->channel();
        $channel->exchange_declare($this->testExchange, 'direct', false, false, true);
        $channel->queue_declare($this->testQueue, false, false, false, true);
        $channel->queue_bind($this->testQueue, $this->testExchange, $this->testRoutingKey);
        $channel->close();

        DB::table('msg_outbox')->truncate();
    }

    protected function tearDown(): void
    {
        try {
            $channel = $this->amqpFactory->connection()->channel();
            while ($channel->basic_get($this->testQueue, true) !== null) {
            }
            $channel->queue_delete($this->testQueue);
            $channel->exchange_delete($this->testExchange);
            $channel->close();
        } catch (\Throwable) {
        }

        DB::table('msg_outbox')->truncate();

        parent::tearDown();
    }

    /**
     * 1000 строк, 2 процесса. Отсюда 1000 сообщений в очереди, 0 pending.
     */
    public function test_two_parallel_relays_do_not_duplicate_messages(): void
    {
        $totalRows = 1000;
        $exchange = $this->testExchange;
        $routingKey = $this->testRoutingKey;

        // Пакетная вставка 1000 строк (данные committed и видны дочерним процессам).
        $chunks = array_chunk(range(0, $totalRows - 1), 200);
        foreach ($chunks as $chunk) {
            $rows = array_map(static fn (int $i): array => [
                'id' => Uuid::uuid7()->toString(),
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'payload' => json_encode(['seq' => $i], JSON_THROW_ON_ERROR),
                'headers' => '{}',
                'status' => 'pending',
                'attempts' => 0,
            ], $chunk);

            DB::table('msg_outbox')->insert($rows);
        }

        $this->assertSame($totalRows, DB::table('msg_outbox')->where('status', 'pending')->count());

        // Запуск двух relay-процессов --max-iterations=20 с batch-size=100 (т.е. каждый
        // может обработать до 2000 строк). Завершатся, как только outbox опустеет.
        $artisan = base_path('artisan');
        $env = array_merge($_ENV, $_SERVER, ['APP_ENV' => 'testing']);

        $process1 = new Process(
            ['php', $artisan, 'messaging:outbox-relay', '--batch-size=100', '--max-iterations=20', '--idle-sleep-ms=50'],
            base_path(),
            $env,
        );
        $process2 = new Process(
            ['php', $artisan, 'messaging:outbox-relay', '--batch-size=100', '--max-iterations=20', '--idle-sleep-ms=50'],
            base_path(),
            $env,
        );

        $process1->start();
        $process2->start();

        // Ждём завершения обоих с таймаутом 60 с.
        $process1->wait();
        $process2->wait();

        $this->assertSame(0, $process1->getExitCode(), 'relay process 1 failed: '.$process1->getErrorOutput());
        $this->assertSame(0, $process2->getExitCode(), 'relay process 2 failed: '.$process2->getErrorOutput());

        // Проверка БД: 0 строк pending
        $this->assertSame(0, DB::table('msg_outbox')->where('status', 'pending')->count());

        // Проверка очереди: 1000 сообщений, без дублей
        $channel = $this->amqpFactory->connection()->channel();
        $receivedCount = 0;
        $receivedIds = [];

        while (($msg = $channel->basic_get($this->testQueue, true)) !== null) {
            $receivedCount++;
            $receivedIds[] = $msg->get('message_id');
        }
        $channel->close();

        // Уникальных message_id должно быть столько же, сколько получено (дублей нет).
        $this->assertSame($totalRows, $receivedCount, "Expected $totalRows messages, got $receivedCount");
        $this->assertCount($totalRows, array_unique($receivedIds), 'Duplicate message_ids detected');
    }
}
