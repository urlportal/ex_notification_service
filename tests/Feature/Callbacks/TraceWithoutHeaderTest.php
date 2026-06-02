<?php

namespace Tests\Feature\Callbacks;

use Illuminate\Support\Facades\Log;

class TraceWithoutHeaderTest extends CallbackTestCase
{
    public function test_missing_request_id_generates_uuid_v7_and_logs_notification_request_id(): void
    {
        $notification = $this->createNotification();

        Log::spy();

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunDeliveredPayload((string) $notification->provider_message_id)
        );

        $response->assertNoContent();

        $responseRequestId = $response->headers->get('X-Request-ID');
        $this->assertNotNull($responseRequestId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $responseRequestId,
            'X-Request-ID в ответе должен быть UUID v7'
        );

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($notification) {
                return $message === 'Callback обработан'
                    && isset($context['notification_request_id'])
                    && $context['notification_request_id'] === $notification->request_id;
            });
    }
}
