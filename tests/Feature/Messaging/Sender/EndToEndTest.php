<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationChannel;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Ramsey\Uuid\Uuid;

class EndToEndTest extends SenderTestCase
{
    private const string TOKEN = 'phpunit-test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.api_token' => self::TOKEN]);
        $this->drainQueue('notifications.bulk-expand');
        Redis::flushdb();
    }

    protected function tearDown(): void
    {
        $this->drainQueue('notifications.bulk-expand');
        parent::tearDown();
    }

    // Полный e2e: POST /bulk -> relay -> bulk-expand -> relay -> sender ->
    // все уведомления в БД имеют status=sent.
    public function test_full_chain_marks_all_notifications_as_sent(): void
    {
        $this->bindSuccessGateway();

        $count = 4;
        $subscriberIds = [];
        for ($i = 0; $i < $count; $i++) {
            $subscriberIds[] = Subscriber::create(['email' => "e2e{$i}@example.com"])->id;
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'Idempotency-Key' => Uuid::uuid4()->toString(),
            'X-Request-Id' => Uuid::uuid7()->toString(),
        ])->postJson('/api/v1/notifications/bulk', [
            'channel' => NotificationChannel::Email->value,
            'delivery_profile' => 'marketing',
            'message' => 'end-to-end',
            'subscriber_ids' => $subscriberIds,
        ]);
        $response->assertStatus(202);
        $bulkRequestId = $response->json('bulk_request_id');

        // Цепочка: relay -> bulk-expand -> relay -> sender (marketing).
        $this->runRelay();
        $this->consumeBulkExpand(1);
        $this->runRelay();
        $this->consume('marketing', $count);

        $this->assertSame(
            $count,
            DB::table('notifications')
                ->where('bulk_request_id', $bulkRequestId)
                ->where('status', 'sent')
                ->count(),
        );
    }

    private function runRelay(): void
    {
        $exitCode = Artisan::call('messaging:outbox-relay', [
            '--batch-size' => 100,
            '--max-iterations' => 1,
        ]);
        $this->assertSame(0, $exitCode);
    }

    private function consumeBulkExpand(int $max): void
    {
        $exitCode = Artisan::call('notifications:consume-bulk-expand', ['--max-messages' => $max]);
        $this->assertSame(0, $exitCode);
    }
}
