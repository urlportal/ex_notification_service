<?php

namespace Tests\Unit\Messaging\Handlers;

use App\Exceptions\Messaging\InvalidBulkExpandPayloadException;
use App\Logging\RequestIdContext;
use App\Messaging\DeliveryProfile;
use App\Messaging\Handlers\BulkExpandEventHandler;
use App\Messaging\Inbox\MsgInboxRepositoryInterface;
use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\MsgOutboxRepositoryInterface;
use App\Repositories\NotificationRepositoryInterface;
use App\Repositories\SubscriberRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class BulkExpandEventHandlerTest extends TestCase
{
    private MockInterface $subscriberRepo;

    private MockInterface $notificationRepo;

    private MockInterface $outboxRepo;

    private MockInterface $inboxRepo;

    private MockInterface $db;

    private MockInterface $logger;

    private AMQPChannel $channelMock;

    private DeliveryProfile $deliveryProfile;

    private BulkExpandEventHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriberRepo = Mockery::mock(SubscriberRepositoryInterface::class);
        $this->notificationRepo = Mockery::mock(NotificationRepositoryInterface::class);
        $this->outboxRepo = Mockery::mock(MsgOutboxRepositoryInterface::class);
        $this->inboxRepo = Mockery::mock(MsgInboxRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        // Мок DatabaseManager: transaction сразу выполняет closure.
        $this->db = Mockery::mock(DatabaseManager::class);
        $this->db->shouldReceive('transaction')
            ->andReturnUsing(function (callable $closure) {
                return $closure();
            });

        $requestIdContext = new RequestIdContext;

        // inbox.markProcessed возвращает true (первая обработка).
        $this->inboxRepo->shouldReceive('markProcessed')
            ->andReturn(true)
            ->byDefault();

        /** @var MsgInboxRepositoryInterface $inbox */
        $inbox = $this->inboxRepo;
        /** @var DatabaseManager $db */
        $db = $this->db;
        /** @var LoggerInterface $logger */
        $logger = $this->logger;
        /** @var SubscriberRepositoryInterface $subscriberRepo */
        $subscriberRepo = $this->subscriberRepo;
        /** @var NotificationRepositoryInterface $notificationRepo */
        $notificationRepo = $this->notificationRepo;
        /** @var MsgOutboxRepositoryInterface $outboxRepo */
        $outboxRepo = $this->outboxRepo;

        $this->deliveryProfile = new DeliveryProfile([
            'transactional' => ['queue' => 'notifications.transactional', 'workers' => 2, 'prefetch' => 10],
            'marketing' => ['queue' => 'notifications.marketing', 'workers' => 4, 'prefetch' => 10],
        ]);

        $this->handler = new BulkExpandEventHandler(
            inbox: $inbox,
            db: $db,
            logger: $logger,
            requestIdContext: $requestIdContext,
            subscriberRepo: $subscriberRepo,
            notificationRepo: $notificationRepo,
            outboxRepo: $outboxRepo,
            deliveryProfile: $this->deliveryProfile,
        );

        $channelMock = Mockery::mock(AMQPChannel::class);
        $channelMock->shouldReceive('basic_ack')->andReturnNull()->byDefault();
        $channelMock->shouldReceive('basic_nack')->andReturnNull()->byDefault();
        /** @var AMQPChannel $channelMock */
        $this->channelMock = $channelMock;

        $this->logger->shouldReceive('info')->byDefault();
        $this->logger->shouldReceive('warning')->byDefault();
        $this->logger->shouldReceive('error')->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Создаёт AMQPMessage с bulk-expand телом.
     *
     * @param  list<string>  $subscriberIds
     */
    private function makeMessage(
        array $subscriberIds,
        string $priority = 'transactional',
        ?string $messageId = null,
        ?string $requestId = null,
    ): AMQPMessage {
        $body = [
            'bulk_request_id' => Uuid::uuid7()->toString(),
            'channel' => 'email',
            'priority' => $priority,
            'message' => 'Тест',
            'subscriber_ids' => $subscriberIds,
            'idempotency_key' => Uuid::uuid7()->toString(),
        ];

        $props = ['message_id' => $messageId ?? Uuid::uuid7()->toString()];
        if ($requestId !== null) {
            $props['application_headers'] = new AMQPTable(['request_id' => $requestId]);
        }

        $message = new AMQPMessage(json_encode($body, JSON_THROW_ON_ERROR), $props);
        $message->setChannel($this->channelMock);
        $message->setDeliveryInfo(deliveryTag: 1, redelivered: false, exchange: '', routingKey: '');

        return $message;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function makeIdMap(array $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = Uuid::uuid7()->toString();
        }

        return $map;
    }

    // Порядок вызовов репозиториев: findExistingIds -> upsertBulkAndReturnIds -> enqueueMany
    public function test_calls_repos_in_order(): void
    {
        config(['notifications.bulk_expand.chunk_size' => 100, 'rabbitmq.exchange' => 'notifications.exchange']);

        $subIds = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        $idMap = $this->makeIdMap($subIds);

        $callOrder = [];

        $this->subscriberRepo->shouldReceive('findExistingIds')
            ->once()
            ->with($subIds)
            ->andReturnUsing(function () use ($subIds, &$callOrder) {
                $callOrder[] = 'findExistingIds';

                return $subIds;
            });

        $this->notificationRepo->shouldReceive('upsertBulkAndReturnIds')
            ->once()
            ->andReturnUsing(function () use ($idMap, &$callOrder) {
                $callOrder[] = 'upsertBulkAndReturnIds';

                return $idMap;
            });

        $this->outboxRepo->shouldReceive('enqueueMany')
            ->once()
            ->andReturnUsing(function () use (&$callOrder) {
                $callOrder[] = 'enqueueMany';
            });

        $this->handler->handle($this->makeMessage($subIds));

        $this->assertSame(['findExistingIds', 'upsertBulkAndReturnIds', 'enqueueMany'], $callOrder);
    }

    // Чанкование: 250 id при chunk_size=100. Получаем 3 вызова upsertBulkAndReturnIds и enqueueMany
    public function test_chunking(): void
    {
        config(['notifications.bulk_expand.chunk_size' => 100, 'rabbitmq.exchange' => 'notifications.exchange']);

        $subIds = [];
        for ($i = 0; $i < 250; $i++) {
            $subIds[] = Uuid::uuid7()->toString();
        }

        $this->subscriberRepo->shouldReceive('findExistingIds')
            ->once()
            ->andReturn($subIds);

        $this->notificationRepo->shouldReceive('upsertBulkAndReturnIds')
            ->times(3)
            ->andReturnUsing(fn (array $rows) => $this->makeIdMap(array_column($rows, 'subscriber_id')));

        // enqueueMany принимает аргументы MsgOutboxEntry
        $enqueueCallCounts = [];
        $this->outboxRepo->shouldReceive('enqueueMany')
            ->times(3)
            ->andReturnUsing(function (MsgOutboxEntry ...$entries) use (&$enqueueCallCounts) {
                $enqueueCallCounts[] = count($entries);
            });

        $this->handler->handle($this->makeMessage($subIds));

        sort($enqueueCallCounts);
        $this->assertSame([50, 100, 100], $enqueueCallCounts);
    }

    // Tolerant-фильтрация (отсутствующие подписчики не попадают в rows)
    public function test_filters_missing_subscribers(): void
    {
        config(['notifications.bulk_expand.chunk_size' => 100, 'rabbitmq.exchange' => 'notifications.exchange']);

        $existing = [Uuid::uuid7()->toString(), Uuid::uuid7()->toString()];
        $ghost = Uuid::uuid7()->toString();
        $allIds = [...$existing, $ghost];

        $this->subscriberRepo->shouldReceive('findExistingIds')
            ->once()
            ->with($allIds)
            ->andReturn($existing);

        $capturedRows = null;
        $this->notificationRepo->shouldReceive('upsertBulkAndReturnIds')
            ->once()
            ->andReturnUsing(function (array $rows) use (&$capturedRows, $existing) {
                $capturedRows = $rows;

                return $this->makeIdMap($existing);
            });

        $this->outboxRepo->shouldReceive('enqueueMany')->once();

        $this->handler->handle($this->makeMessage($allIds));

        $this->assertNotNull($capturedRows);
        $insertedSubscriberIds = array_column($capturedRows, 'subscriber_id');
        $this->assertNotContains($ghost, $insertedSubscriberIds);
        $this->assertCount(2, $insertedSubscriberIds);
    }

    // Пустой список. upsertBulkAndReturnIds и enqueueMany не вызываются. Только лог
    public function test_empty_list_writes_nothing(): void
    {
        config(['notifications.bulk_expand.chunk_size' => 100, 'rabbitmq.exchange' => 'notifications.exchange']);

        $this->subscriberRepo->shouldReceive('findExistingIds')
            ->once()
            ->andReturn([]);

        $this->notificationRepo->shouldNotReceive('upsertBulkAndReturnIds');
        $this->outboxRepo->shouldNotReceive('enqueueMany');

        $logCalled = false;
        $this->logger->shouldReceive('info')
            ->once()
            ->withArgs(function (string $msg, array $ctx) use (&$logCalled) {
                if ($msg === 'bulk expand event processed' && $ctx['created_count'] === 0 && $ctx['chunk_count'] === 0) {
                    $logCalled = true;
                }

                return true;
            });

        $this->handler->handle($this->makeMessage([]));

        $this->assertTrue($logCalled, 'info-лог bulk expand event processed должен быть вызван с created_count=0');
    }

    public function test_unknown_priority_in_payload_routes_to_dlq(): void
    {
        // Payload содержит валидный по структуре, но отсутствующий в каталоге
        // priority. handleInternal бросает InvalidBulkExpandPayloadException;
        // базовый handle() логирует и делает nack(requeue=false), сообщение
        // уходит в DLQ через x-dead-letter-exchange.
        $errorLogged = false;
        $this->logger->shouldReceive('error')
            ->once()
            ->withArgs(function (string $msg, array $ctx) use (&$errorLogged): bool {
                if ($msg === 'unhandled exception in handler'
                    && ($ctx['exception'] ?? null) === InvalidBulkExpandPayloadException::class
                    && str_contains((string) ($ctx['message'] ?? ''), 'unknown profile: no_such_priority')) {
                    $errorLogged = true;
                }

                return true;
            });

        $message = $this->makeMessage(
            subscriberIds: [Uuid::uuid7()->toString()],
            priority: 'no_such_priority',
        );

        $this->handler->handle($message);

        $this->assertTrue($errorLogged, 'error-лог должен содержать InvalidBulkExpandPayloadException');
    }
}
