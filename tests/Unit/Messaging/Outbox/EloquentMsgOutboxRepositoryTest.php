<?php

namespace Tests\Unit\Messaging\Outbox;

use App\Messaging\Outbox\Eloquent\EloquentMsgOutboxRepository;
use App\Messaging\Outbox\MsgOutbox;
use App\Messaging\Outbox\MsgOutboxEntry;
use App\Messaging\Outbox\OutboxStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class EloquentMsgOutboxRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentMsgOutboxRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentMsgOutboxRepository;
    }

    // enqueue

    public function test_enqueue_saves_all_fields_correctly(): void
    {
        $requestId = Uuid::uuid7()->toString();

        $entry = new MsgOutboxEntry(
            id: Uuid::uuid7()->toString(),
            exchange: 'notifications.exchange',
            routingKey: 'notifications.transactional',
            payload: ['type' => 'sms', 'body' => 'Hello'],
            headers: ['request_id' => $requestId],
        );

        $this->repository->enqueue($entry);

        $this->assertDatabaseHas('msg_outbox', [
            'id' => $entry->id,
            'exchange' => 'notifications.exchange',
            'routing_key' => 'notifications.transactional',
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'published_at' => null,
        ]);

        /** @var MsgOutbox|null $model */
        $model = MsgOutbox::find($entry->id);
        $this->assertNotNull($model);
        $this->assertEquals(['type' => 'sms', 'body' => 'Hello'], $model->payload);
        $this->assertEquals(['request_id' => $requestId], $model->headers);
        $this->assertSame(OutboxStatus::Pending, $model->status);
        $this->assertNotNull($model->created_at);
    }

    // enqueueMany

    public function test_enqueue_many_inserts_all_rows(): void
    {
        $entries = [
            new MsgOutboxEntry(Uuid::uuid7()->toString(), 'ex', 'rk1', ['n' => 1], []),
            new MsgOutboxEntry(Uuid::uuid7()->toString(), 'ex', 'rk2', ['n' => 2], []),
            new MsgOutboxEntry(Uuid::uuid7()->toString(), 'ex', 'rk3', ['n' => 3], []),
        ];

        $this->repository->enqueueMany(...$entries);

        $this->assertSame(3, MsgOutbox::count());
        foreach ($entries as $entry) {
            $this->assertDatabaseHas('msg_outbox', ['id' => $entry->id, 'status' => 'pending']);
        }
    }

    public function test_enqueue_many_with_empty_list_does_not_throw(): void
    {
        $this->repository->enqueueMany();
        $this->assertSame(0, MsgOutbox::count());
    }

    // pullPendingBatch

    public function test_pull_pending_batch_returns_no_more_than_limit_rows(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->enqueue(
                new MsgOutboxEntry(Uuid::uuid7()->toString(), 'ex', 'rk', ['i' => $i], [])
            );
        }

        DB::beginTransaction();
        $rows = $this->repository->pullPendingBatch(3);
        DB::rollBack();

        $this->assertCount(3, $rows);
        $this->assertContainsOnlyInstancesOf(MsgOutbox::class, $rows); // @phpstan-ignore method.alreadyNarrowedType
    }

    public function test_pull_pending_batch_returns_rows_ordered_by_created_at(): void
    {
        // Вставляем с явным created_at для контроля порядка.
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $id = Uuid::uuid7()->toString();
            $ids[] = $id;
            DB::table('msg_outbox')->insert([
                'id' => $id,
                'exchange' => 'ex',
                'routing_key' => 'rk',
                'payload' => '{}',
                'headers' => '{}',
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => now()->addSeconds($i),
            ]);
        }

        DB::beginTransaction();
        $rows = $this->repository->pullPendingBatch(10);
        DB::rollBack();

        $this->assertCount(3, $rows);
        $this->assertSame($ids[0], $rows[0]->id);
        $this->assertSame($ids[1], $rows[1]->id);
        $this->assertSame($ids[2], $rows[2]->id);
    }

    public function test_pull_pending_batch_does_not_return_non_pending_rows(): void
    {
        $pendingId = Uuid::uuid7()->toString();
        $this->repository->enqueue(
            new MsgOutboxEntry($pendingId, 'ex', 'rk', [], [])
        );

        DB::table('msg_outbox')->insert([
            ['id' => Uuid::uuid7()->toString(), 'exchange' => 'ex', 'routing_key' => 'rk',
                'payload' => '{}', 'headers' => '{}', 'status' => 'published', 'attempts' => 0],
            ['id' => Uuid::uuid7()->toString(), 'exchange' => 'ex', 'routing_key' => 'rk',
                'payload' => '{}', 'headers' => '{}', 'status' => 'failed', 'attempts' => 1],
        ]);

        DB::beginTransaction();
        $rows = $this->repository->pullPendingBatch(10);
        DB::rollBack();

        $this->assertCount(1, $rows);
        $this->assertSame($pendingId, $rows[0]->id);
    }

    // markPublishedMany

    public function test_mark_published_many_updates_status_and_published_at(): void
    {
        $id1 = Uuid::uuid7()->toString();
        $id2 = Uuid::uuid7()->toString();

        $this->repository->enqueueMany(
            new MsgOutboxEntry($id1, 'ex', 'rk', [], []),
            new MsgOutboxEntry($id2, 'ex', 'rk', [], []),
        );

        $this->repository->markPublishedMany([$id1, $id2]);

        foreach ([$id1, $id2] as $id) {
            /** @var MsgOutbox|null $model */
            $model = MsgOutbox::find($id);
            $this->assertNotNull($model);
            $this->assertSame(OutboxStatus::Published, $model->status);
            $this->assertNotNull($model->published_at);
        }
    }

    public function test_mark_published_many_is_idempotent_on_repeated_call(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->repository->enqueue(new MsgOutboxEntry($id, 'ex', 'rk', [], []));

        // Первый вызов.
        $this->repository->markPublishedMany([$id]);
        // Повторный вызов не должен выбрасывать исключений.
        $this->repository->markPublishedMany([$id]);

        $this->assertDatabaseHas('msg_outbox', ['id' => $id, 'status' => 'published']);
    }

    public function test_mark_published_many_with_empty_list_does_nothing(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->repository->enqueue(new MsgOutboxEntry($id, 'ex', 'rk', [], []));

        $this->repository->markPublishedMany([]);

        $this->assertDatabaseHas('msg_outbox', ['id' => $id, 'status' => 'pending']);
    }

    // markFailed

    public function test_mark_failed_sets_status_and_increments_attempts(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->repository->enqueue(new MsgOutboxEntry($id, 'ex', 'rk', [], []));

        $this->repository->markFailed($id, 'unrouted or nack');

        /** @var MsgOutbox|null $model */
        $model = MsgOutbox::find($id);
        $this->assertNotNull($model);
        $this->assertSame(OutboxStatus::Failed, $model->status);
        $this->assertSame('unrouted or nack', $model->last_error);
        $this->assertSame(1, $model->attempts);
    }

    // incrementAttempts

    public function test_increment_attempts_increments_counter(): void
    {
        $id = Uuid::uuid7()->toString();
        $this->repository->enqueue(new MsgOutboxEntry($id, 'ex', 'rk', [], []));

        $this->repository->incrementAttempts($id);
        $this->repository->incrementAttempts($id);

        $this->assertDatabaseHas('msg_outbox', ['id' => $id, 'attempts' => 2]);
    }
}
