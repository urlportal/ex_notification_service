<?php

namespace Tests\Feature\Callbacks;

class HappyPathDroppedTest extends CallbackTestCase
{
    public function test_mailgun_failed_event_transitions_notification_to_dropped(): void
    {
        $notification = $this->createNotification();

        $reason = 'bounce';

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunFailedPayload((string) $notification->provider_message_id, $reason)
        );

        $response->assertNoContent();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'dropped',
            'last_error' => $reason,
        ]);

        $notification->refresh();
        $this->assertNotNull($notification->dropped_at);
        $this->assertNull($notification->delivered_at);
    }
}
