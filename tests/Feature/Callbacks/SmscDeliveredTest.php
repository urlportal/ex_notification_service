<?php

namespace Tests\Feature\Callbacks;

use App\Enums\NotificationChannel;

class SmscDeliveredTest extends CallbackTestCase
{
    public function test_smsc_status_one_transitions_notification_to_delivered(): void
    {
        $messageId = 'smsc-msg-12345';

        $notification = $this->createNotification([
            'channel' => NotificationChannel::Sms,
            'provider_message_id' => $messageId,
        ]);

        $response = $this->postJson('/api/callbacks/smsc', [
            'id' => $messageId,
            'status' => '1',
            'last_date' => date('Y-m-d H:i:s'),
        ]);

        $response->assertNoContent();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'delivered',
        ]);

        $this->assertNotNull($notification->refresh()->delivered_at);
    }
}
