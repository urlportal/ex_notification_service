<?php

namespace Tests\Feature\Messaging\Sender;

use Illuminate\Support\Facades\DB;

class MalformedPayloadTest extends SenderTestCase
{
    // Битый JSON -> InvalidSendCommandException -> nack(requeue=false) -> DLQ.
    public function test_invalid_json_goes_to_dlq(): void
    {
        $messageId = $this->publishRaw('not valid json {{{', self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);

        $this->assertNotNull($this->drainDlq(), 'ожидалось сообщение в DLQ');
        $this->assertDatabaseMissing('msg_inbox', [
            'message_id' => $messageId,
            'consumer_name' => 'sender-transactional',
        ]);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    // Тело без обязательного notification_id -> то же поведение.
    public function test_missing_notification_id_goes_to_dlq(): void
    {
        $messageId = $this->publishRaw(
            json_encode(['something_else' => 'value'], JSON_THROW_ON_ERROR),
            self::QUEUE_TRANSACTIONAL,
        );

        $this->consume('transactional', 1);

        $this->assertNotNull($this->drainDlq());
        $this->assertDatabaseMissing('msg_inbox', [
            'message_id' => $messageId,
            'consumer_name' => 'sender-transactional',
        ]);
        $this->assertSame(0, DB::table('notifications')->count());
    }
}
