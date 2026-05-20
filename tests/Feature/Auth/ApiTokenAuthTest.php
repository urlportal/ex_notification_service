<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiTokenAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.api_token' => 'test-secret-token']);

        Route::middleware(['api.token'])->get('/api/_ping', fn () => response()->json(['pong' => true]));
    }

    public function test_rejects_request_without_authorization_header(): void
    {
        $response = $this->getJson('/api/_ping');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated']);
    }

    public function test_rejects_request_with_wrong_token(): void
    {
        Log::spy();

        $response = $this->withHeader('Authorization', 'Bearer wrong-token')
            ->getJson('/api/_ping');

        $response->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => $context['reason'] === 'invalid_token');
    }

    public function test_allows_request_with_valid_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-secret-token')
            ->getJson('/api/_ping');

        $response->assertStatus(200)
            ->assertJson(['pong' => true]);
    }

    public function test_health_live_accessible_without_authorization_header(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertStatus(200);
    }
}
