<?php

namespace Tests\Feature\Messaging\Sender;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class NotFoundTest extends SenderTestCase
{
    // Send-команда с несуществующим notification_id -> warning, ack, БД без изменений.
    public function test_missing_notification_is_acked_without_db_changes(): void
    {
        $spy = $this->bindSuccessGateway();

        $ghostId = Uuid::uuid7()->toString();
        $messageId = $this->publishSend($ghostId, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);

        $this->assertSame(0, $spy->calls);
        $this->assertSame(0, DB::table('notifications')->count());

        $this->assertDatabaseHas('msg_inbox', [
            'message_id' => $messageId,
            'consumer_name' => 'sender-transactional',
        ]);

        $this->assertNull($this->drainDlq());
    }
}
