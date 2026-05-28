<?php

namespace Tests\Unit\Messaging\Inbox;

use App\Messaging\Inbox\Eloquent\EloquentMsgInboxRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class EloquentMsgInboxRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentMsgInboxRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentMsgInboxRepository;
    }

    public function test_first_call_inserts_and_returns_true(): void
    {
        $messageId = Uuid::uuid7()->toString();

        $result = $this->repository->markProcessed($messageId, 'stub-consumer');

        $this->assertTrue($result);
        $this->assertSame(1, DB::table('msg_inbox')->count());
        $this->assertSame(1, DB::table('msg_inbox')
            ->where('message_id', $messageId)
            ->where('consumer_name', 'stub-consumer')
            ->count());
    }

    public function test_second_call_with_same_pair_returns_false_and_does_not_duplicate(): void
    {
        $messageId = Uuid::uuid7()->toString();

        $this->repository->markProcessed($messageId, 'stub-consumer');
        $result = $this->repository->markProcessed($messageId, 'stub-consumer');

        $this->assertFalse($result);
        $this->assertSame(1, DB::table('msg_inbox')->count());
    }

    public function test_different_consumer_names_are_independent(): void
    {
        $messageId = Uuid::uuid7()->toString();

        $first = $this->repository->markProcessed($messageId, 'stub-consumer');
        $second = $this->repository->markProcessed($messageId, 'other-consumer');

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertSame(2, DB::table('msg_inbox')->where('message_id', $messageId)->count());
    }
}
