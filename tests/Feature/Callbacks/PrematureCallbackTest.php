<?php

namespace Tests\Feature\Callbacks;

use App\Enums\NotificationStatus;

class PrematureCallbackTest extends CallbackTestCase
{
    public function test_callback_on_queued_notification_returns_409(): void
    {
        $notification = $this->createNotification([
            'status' => NotificationStatus::Queued,
            'sent_at' => null,
            'provider_message_id' => '<queued-msg@mailgun.org>',
            'attempts' => 0,
        ]);

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunDeliveredPayload((string) $notification->provider_message_id)
        );

        $response->assertStatus(409)
            ->assertJson(['message' => 'Notification has not been sent yet']);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'queued',
        ]);
    }
}
