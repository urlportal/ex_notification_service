<?php

namespace Tests\Feature\Callbacks;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/test-callback/{adapter}', fn () => response()->json(['ok' => true]))
            ->middleware('callback.signature');
    }

    public function test_valid_mailgun_signature_passes(): void
    {
        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));
        $signingKey = 'test-mailgun-key';

        config(['notifications.callbacks.mailgun.signing_key' => $signingKey]);
        config(['notifications.callbacks.mailgun.replay_window_seconds' => 300]);

        $signature = hash_hmac('sha256', $timestamp.$token, $signingKey);

        $response = $this->postJson('/test-callback/mailgun', [
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $signature,
            ],
        ]);

        $response->assertStatus(200);
    }

    public function test_invalid_mailgun_signature_returns_401(): void
    {
        $response = $this->postJson('/test-callback/mailgun', [
            'signature' => [
                'timestamp' => (string) time(),
                'token' => 'some-token',
                'signature' => 'bad-signature',
            ],
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid signature']);
    }

    public function test_ip_not_in_whitelist_returns_403(): void
    {
        config(['notifications.callbacks.smsc.allowed_cidrs' => '10.0.0.0/8']);

        $response = $this->postJson('/test-callback/smsc', [
            'id' => '123',
            'status' => '1',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Forbidden']);
    }

    public function test_unknown_adapter_returns_404(): void
    {
        $response = $this->postJson('/test-callback/unknown-provider', []);

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource not found']);
    }
}
