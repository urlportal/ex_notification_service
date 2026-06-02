<?php

namespace Tests\Feature\Callbacks;

use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class TraceWithHeaderTest extends CallbackTestCase
{
    public function test_provided_request_id_is_echoed_in_response_and_logged(): void
    {
        $notification = $this->createNotification();
        $requestId = Uuid::uuid7()->toString();

        Log::spy();

        $response = $this->withHeader('X-Request-ID', $requestId)
            ->postJson(
                '/api/callbacks/mailgun',
                $this->mailgunDeliveredPayload((string) $notification->provider_message_id)
            );

        $response->assertNoContent()
            ->assertHeader('X-Request-ID', $requestId);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($notification) {
                return $message === 'Callback обработан'
                    && isset($context['notification_request_id'])
                    && $context['notification_request_id'] === $notification->request_id;
            });
    }
}
