<?php

namespace Tests\Feature\Console;

use App\Messaging\Outbox\MsgOutbox;
use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use App\Models\Notification;
use App\Models\Subscriber;
use App\Repositories\NotificationRepositoryInterface;
use App\Services\RescueSweepService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class RescueSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Отдельное соединение с БД вне транзакции RefreshDatabase. Через него данные коммитятся
     * и становятся видны сервису, а строки блокируются так, как это сделал бы второй процесс.
     */
    private const SIDE_CONNECTION = 'rescue_sweep_side';

    protected function tearDown(): void
    {
        DB::purge(self::SIDE_CONNECTION);

        parent::tearDown();
    }

    private function createSubscriber(?string $connection = null): Subscriber
    {
        return Subscriber::on($connection)->create([
            'email' => 'test-'.Uuid::uuid7()->toString().'@example.com',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createStuckNotification(array $overrides = [], ?string $connection = null): Notification
    {
        $subscriber = $this->createSubscriber($connection);

        $attributes = array_merge([
            'id' => Uuid::uuid7()->toString(),
            'subscriber_id' => $subscriber->id,
            'channel' => 'email',
            'delivery_profile' => 'transactional',
            'status' => 'queued',
            'message' => 'test message',
            'idempotency_key' => Uuid::uuid7()->toString(),
            'request_id' => Uuid::uuid7()->toString(),
            'attempts' => 1,
            'queued_at' => now()->subHours(3),
        ], $overrides);

        $notification = Notification::on($connection)->create($attributes);

        // Переводим updated_at в прошлое, минуя автоматическое проставление меток
        DB::connection($connection)->table('notifications')
            ->where('id', $notification->id)
            ->update(['updated_at' => now()->subHours(2)]);

        $fresh = $notification->fresh();
        $this->assertNotNull($fresh);

        return $fresh;
    }

    private function makeSweepService(): RescueSweepService
    {
        return app(RescueSweepService::class);
    }

    /**
     * Сервис с логгером, полученным в момент вызова. Нужен после Log::spy(), чтобы сервис писал в шпиона.
     */
    private function makeSweepServiceWithCurrentLogger(): RescueSweepService
    {
        return new RescueSweepService(
            app(NotificationRepositoryInterface::class),
            app(MsgOutboxRepositoryInterface::class),
            app(LoggerInterface::class),
        );
    }

    public function test_stuck_notification_is_requeued_at_same_level(): void
    {
        $notification = $this->createStuckNotification(['attempts' => 1]);

        $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $this->assertSame(1, $result->requeued);
        $this->assertSame(0, $result->dropped);

        $outbox = MsgOutbox::where('routing_key', '30s.transactional')->first();
        $this->assertNotNull($outbox);
        $this->assertSame('pending', $outbox->status->value);
        $this->assertSame((string) $notification->id, $outbox->payload['notification_id'] ?? null);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'queued',
        ]);
    }

    public function test_exhausted_notification_is_dropped(): void
    {
        // При max_attempts=3 и attempts=3 условие attempts >= max_attempts выполняется
        config(['notifications.retry.max_attempts' => 3]);

        $notification = $this->createStuckNotification(['attempts' => 3]);

        $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $this->assertSame(1, $result->dropped);
        $this->assertSame(0, $result->requeued);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'dropped',
        ]);
        $this->assertDatabaseCount('msg_outbox', 0);
    }

    public function test_notification_with_zero_attempts_is_skipped(): void
    {
        $subscriber = $this->createSubscriber();
        $notification = Notification::create([
            'id' => Uuid::uuid7()->toString(),
            'subscriber_id' => $subscriber->id,
            'channel' => 'email',
            'delivery_profile' => 'transactional',
            'status' => 'queued',
            'message' => 'test message',
            'idempotency_key' => Uuid::uuid7()->toString(),
            'request_id' => Uuid::uuid7()->toString(),
            'attempts' => 0,
            'queued_at' => now()->subHours(3),
        ]);
        DB::table('notifications')
            ->where('id', $notification->id)
            ->update(['updated_at' => now()->subHours(2)]);

        $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $this->assertSame(0, $result->found);
        $this->assertDatabaseCount('msg_outbox', 0);
    }

    public function test_fresh_notification_is_skipped(): void
    {
        $subscriber = $this->createSubscriber();
        // updated_at выставляется автоматически в NOW. Уведомление не является потерявшимся
        Notification::create([
            'id' => Uuid::uuid7()->toString(),
            'subscriber_id' => $subscriber->id,
            'channel' => 'email',
            'delivery_profile' => 'transactional',
            'status' => 'queued',
            'message' => 'test message',
            'idempotency_key' => Uuid::uuid7()->toString(),
            'request_id' => Uuid::uuid7()->toString(),
            'attempts' => 1,
            'queued_at' => now()->subMinutes(10),
        ]);

        $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $this->assertSame(0, $result->found);
        $this->assertDatabaseCount('msg_outbox', 0);
    }

    public function test_finalized_notification_is_skipped(): void
    {
        $subscriber = $this->createSubscriber();
        $notification = Notification::create([
            'id' => Uuid::uuid7()->toString(),
            'subscriber_id' => $subscriber->id,
            'channel' => 'email',
            'delivery_profile' => 'transactional',
            'status' => 'sent',
            'message' => 'test message',
            'idempotency_key' => Uuid::uuid7()->toString(),
            'request_id' => Uuid::uuid7()->toString(),
            'attempts' => 1,
            'queued_at' => now()->subHours(3),
            'sent_at' => now()->subHours(2),
        ]);
        DB::table('notifications')
            ->where('id', $notification->id)
            ->update(['updated_at' => now()->subHours(2)]);

        $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $this->assertSame(0, $result->found);
        $this->assertDatabaseCount('msg_outbox', 0);
    }

    public function test_max_process_limit_is_respected(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createStuckNotification(['attempts' => 1]);
        }

        $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 3);

        $this->assertSame(3, $result->found);
        $this->assertSame(2, $result->skipped);
        $this->assertDatabaseCount('msg_outbox', 3);
    }

    public function test_out_of_range_attempts_is_dropped(): void
    {
        $notification = $this->createStuckNotification(['attempts' => 99]);

        $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $this->assertSame(1, $result->dropped);
        $this->assertSame(0, $result->requeued);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'dropped',
        ]);
        $this->assertDatabaseCount('msg_outbox', 0);
    }

    public function test_request_id_propagated_to_outbox_headers(): void
    {
        $requestId = Uuid::uuid7()->toString();
        $this->createStuckNotification(['attempts' => 1, 'request_id' => $requestId]);

        $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $outbox = MsgOutbox::first();
        $this->assertNotNull($outbox);
        $this->assertSame($requestId, $outbox->headers['request_id'] ?? null);
    }

    public function test_summary_log_written_after_sweep(): void
    {
        $this->createStuckNotification(['attempts' => 1]);

        Log::spy();

        $this->makeSweepServiceWithCurrentLogger()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'завершён')
                && array_key_exists('found', $context)
                && array_key_exists('requeued', $context)
                && array_key_exists('dropped', $context)
            )
            ->once();
    }

    public function test_requeue_log_contains_action_and_retry_level(): void
    {
        $notification = $this->createStuckNotification(['attempts' => 2]);

        Log::spy();

        $this->makeSweepServiceWithCurrentLogger()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => ($context['notification_id'] ?? null) === (string) $notification->id
                && ($context['action'] ?? null) === 'requeued'
                && ($context['retry_level'] ?? null) === '5m'
                && ($context['attempts'] ?? null) === 2
                && ($context['request_id'] ?? null) === $notification->request_id
            )
            ->once();
    }

    public function test_drop_log_contains_action(): void
    {
        $notification = $this->createStuckNotification(['attempts' => 99]);

        Log::spy();

        $this->makeSweepServiceWithCurrentLogger()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => ($context['notification_id'] ?? null) === (string) $notification->id
                && ($context['action'] ?? null) === 'dropped'
                && ! array_key_exists('retry_level', $context)
            )
            ->once();
    }

    public function test_command_rejects_threshold_not_exceeding_max_retry_ttl(): void
    {
        $this->createStuckNotification(['attempts' => 1]);

        // Максимальный TTL retry-очередей 30 минут, порог 30 минут недостаточен
        $exitCode = Artisan::call('notifications:rescue-sweep', ['--threshold' => 30]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('должен превышать TTL самой долгой retry-очереди (30 мин)', Artisan::output());

        $this->assertDatabaseCount('msg_outbox', 0);
    }

    public function test_command_accepts_threshold_exceeding_max_retry_ttl(): void
    {
        $this->createStuckNotification(['attempts' => 1]);

        $exitCode = Artisan::call('notifications:rescue-sweep', ['--threshold' => 31]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $this->assertDatabaseCount('msg_outbox', 1);
    }

    public function test_repeated_run_does_not_duplicate_outbox_entries(): void
    {
        $this->createStuckNotification(['attempts' => 1]);

        $service = $this->makeSweepService();

        // Первый запуск обрабатывает уведомление и обновляет updated_at
        $service->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        // Второй запуск видит свежий updated_at и пропускает уведомление
        $service->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $this->assertDatabaseCount('msg_outbox', 1);
    }

    public function test_concurrent_run_skips_notification_locked_by_another_process(): void
    {
        config(['database.connections.'.self::SIDE_CONNECTION => config('database.connections.'.config('database.default'))]);

        $locked = $this->createStuckNotification(['attempts' => 1], self::SIDE_CONNECTION);
        $free = $this->createStuckNotification(['attempts' => 1], self::SIDE_CONNECTION);

        // Записи закоммичены через отдельное соединение, удаляем их после отката транзакции теста
        $this->beforeApplicationDestroyed(function () use ($locked, $free): void {
            $side = DB::connection(self::SIDE_CONNECTION);
            $side->table('notifications')->whereIn('id', [$locked->id, $free->id])->delete();
            $side->table('subscribers')->whereIn('id', [$locked->subscriber_id, $free->subscriber_id])->delete();
        });

        // Второй процесс держит блокировку строки, как параллельный sweep посреди обработки
        $side = DB::connection(self::SIDE_CONNECTION);
        $side->beginTransaction();
        $side->table('notifications')->where('id', $locked->id)->lockForUpdate()->first();

        // Без пропуска заблокированных строк сервис упал бы по таймауту, а не завис
        DB::statement("SET LOCAL lock_timeout = '2s'");

        try {
            $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);
        } finally {
            $side->rollBack();
        }

        $this->assertSame(1, $result->found);
        $this->assertSame(1, $result->requeued);
        $this->assertDatabaseCount('msg_outbox', 1);
        $this->assertSame((string) $free->id, MsgOutbox::first()?->payload['notification_id'] ?? null);
    }

    public function test_failure_of_one_notification_does_not_abort_others(): void
    {
        $broken = $this->createStuckNotification(['attempts' => 1]);
        $healthy = $this->createStuckNotification(['attempts' => 1]);
        $brokenUpdatedAt = $broken->updated_at;

        $result = $this->makeSweepServiceFailingFor((string) $broken->id)
            ->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 50);

        $this->assertSame(2, $result->found);
        $this->assertSame(1, $result->requeued);
        $this->assertSame(1, $result->failed);

        $this->assertDatabaseCount('msg_outbox', 1);
        $this->assertSame((string) $healthy->id, MsgOutbox::first()?->payload['notification_id'] ?? null);

        // Изменения упавшего уведомления откатились, следующий запуск снова его подхватит
        $this->assertEquals($brokenUpdatedAt, $broken->fresh()?->updated_at);
    }

    public function test_failed_notification_is_not_refetched_within_same_run(): void
    {
        $broken = $this->createStuckNotification(['attempts' => 1]);
        $healthy = $this->createStuckNotification(['attempts' => 1]);

        // Упавшая запись самая старая и без исключения попадала бы в каждый следующий батч
        DB::table('notifications')->where('id', $broken->id)->update(['updated_at' => now()->subHours(3)]);

        $result = $this->makeSweepServiceFailingFor((string) $broken->id)
            ->sweep(thresholdMinutes: 45, batchSize: 1, maxProcess: 5);

        $this->assertSame(2, $result->found);
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->requeued);
        $this->assertSame((string) $healthy->id, MsgOutbox::first()?->payload['notification_id'] ?? null);
    }

    public function test_oldest_notifications_are_processed_first(): void
    {
        $newer = $this->createStuckNotification(['attempts' => 1]);
        $oldest = $this->createStuckNotification(['attempts' => 1]);

        DB::table('notifications')->where('id', $newer->id)->update(['updated_at' => now()->subHours(1)]);
        DB::table('notifications')->where('id', $oldest->id)->update(['updated_at' => now()->subHours(5)]);

        // Чтение по индексу на updated_at вернуло бы нужный порядок и без сортировки в запросе,
        // при последовательном чтении самая старая запись лежит последней
        DB::statement('SET LOCAL enable_indexscan = off');
        DB::statement('SET LOCAL enable_bitmapscan = off');

        $result = $this->makeSweepService()->sweep(thresholdMinutes: 45, batchSize: 10, maxProcess: 1);

        $this->assertSame(1, $result->requeued);
        $this->assertSame((string) $oldest->id, MsgOutbox::first()?->payload['notification_id'] ?? null);
    }

    /**
     * Сервис с outbox, который падает при постановке указанного уведомления.
     */
    private function makeSweepServiceFailingFor(string $failingNotificationId): RescueSweepService
    {
        $realOutbox = app(MsgOutboxRepositoryInterface::class);

        $failingOutbox = new class($realOutbox, $failingNotificationId) implements MsgOutboxRepositoryInterface
        {
            public function __construct(
                private readonly MsgOutboxRepositoryInterface $inner,
                private readonly string $failingNotificationId,
            ) {}

            public function enqueue(MsgOutboxEntry $entry): void
            {
                if (($entry->payload['notification_id'] ?? null) === $this->failingNotificationId) {
                    throw new \RuntimeException('outbox недоступен');
                }

                $this->inner->enqueue($entry);
            }

            public function enqueueMany(MsgOutboxEntry ...$entries): void
            {
                $this->inner->enqueueMany(...$entries);
            }

            public function pullPendingBatch(int $limit): array
            {
                return $this->inner->pullPendingBatch($limit);
            }

            public function markPublishedMany(array $ids): void
            {
                $this->inner->markPublishedMany($ids);
            }

            public function markFailed(string $id, string $error): void
            {
                $this->inner->markFailed($id, $error);
            }

            public function incrementAttempts(string $id): void
            {
                $this->inner->incrementAttempts($id);
            }
        };

        return new RescueSweepService(
            app(NotificationRepositoryInterface::class),
            $failingOutbox,
            app(LoggerInterface::class),
        );
    }
}
