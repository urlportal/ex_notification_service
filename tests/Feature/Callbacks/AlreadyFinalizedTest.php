<?php

namespace Tests\Feature\Callbacks;

use App\Enums\NotificationStatus;

class AlreadyFinalizedTest extends CallbackTestCase
{
    public function test_callback_on_delivered_notification_returns_200_already_finalized(): void
    {
        $notification = $this->createNotification([
            'status' => NotificationStatus::Delivered,
            'delivered_at' => now()->subMinutes(5),
        ]);

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunDeliveredPayload((string) $notification->provider_message_id)
        );

        $response->assertOk()
            ->assertJson(['message' => 'Already finalized']);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'delivered',
        ]);
    }

    public function test_callback_on_dropped_notification_returns_200_already_finalized(): void
    {
        $notification = $this->createNotification([
            'status' => NotificationStatus::Dropped,
            'dropped_at' => now()->subMinutes(5),
            'last_error' => 'previous error',
        ]);

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunDeliveredPayload((string) $notification->provider_message_id)
        );

        $response->assertOk()
            ->assertJson(['message' => 'Already finalized']);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'dropped',
        ]);
    }
}
