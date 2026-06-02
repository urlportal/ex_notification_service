<?php

namespace Tests\Feature\Callbacks;

class NotFoundTest extends CallbackTestCase
{
    public function test_unknown_provider_message_id_returns_404(): void
    {
        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunDeliveredPayload('<non-existent-msg@mailgun.org>')
        );

        $response->assertNotFound()
            ->assertJson(['message' => 'Resource not found']);
    }
}
