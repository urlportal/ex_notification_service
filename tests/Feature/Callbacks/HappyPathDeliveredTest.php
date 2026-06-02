<?php

namespace Tests\Feature\Callbacks;

class HappyPathDeliveredTest extends CallbackTestCase
{
    public function test_mailgun_delivered_event_transitions_notification_to_delivered(): void
    {
        $notification = $this->createNotification();

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunDeliveredPayload((string) $notification->provider_message_id)
        );

        $response->assertNoContent();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'delivered',
        ]);

        $notification->refresh();
        $this->assertNotNull($notification->delivered_at);
        $this->assertNull($notification->dropped_at);
    }
}
