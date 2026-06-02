<?php

namespace Tests\Feature\Callbacks;

use App\Enums\NotificationChannel;

class CrossChannelTest extends CallbackTestCase
{
    public function test_mailgun_callback_does_not_match_sms_notification_with_same_message_id(): void
    {
        $messageId = '<shared-id@mailgun.org>';

        $this->createNotification([
            'channel' => NotificationChannel::Sms,
            'provider_message_id' => $messageId,
        ]);

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunDeliveredPayload($messageId)
        );

        $response->assertNotFound()
            ->assertJson(['message' => 'Resource not found']);
    }
}
