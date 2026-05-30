<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'phpunit-test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.api_token' => self::TOKEN]);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    public function test_returns_notification_with_all_fields(): void
    {
        $subscriber = Subscriber::create(['email' => 'test@example.com']);

        $notification = Notification::create([
            'subscriber_id' => $subscriber->id,
            'channel' => NotificationChannel::Email->value,
            'priority' => 'transactional',
            'status' => NotificationStatus::Delivered->value,
            'message' => 'Your order is confirmed',
            'idempotency_key' => 'key-abc-123',
            'provider_message_id' => 'msg_xyz',
            'attempts' => 2,
        ]);

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'id', 'subscriber_id', 'bulk_request_id', 'channel', 'priority', 'status',
                'message', 'attempts', 'last_error', 'idempotency_key', 'provider_message_id',
                'queued_at', 'sent_at', 'delivered_at', 'dropped_at', 'created_at', 'updated_at',
            ]])
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.priority', 'transactional')
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.idempotency_key', 'key-abc-123')
            ->assertJsonPath('data.provider_message_id', 'msg_xyz')
            ->assertJsonPath('data.attempts', 2);
    }

    public function test_nullable_fields_return_null_when_not_set(): void
    {
        $subscriber = Subscriber::create(['email' => 'test@example.com']);

        $notification = Notification::create([
            'subscriber_id' => $subscriber->id,
            'channel' => NotificationChannel::Sms->value,
            'priority' => 'marketing',
            'status' => NotificationStatus::Queued->value,
            'message' => 'Hello',
        ]);

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.bulk_request_id', null)
            ->assertJsonPath('data.last_error', null)
            ->assertJsonPath('data.idempotency_key', null)
            ->assertJsonPath('data.provider_message_id', null)
            ->assertJsonPath('data.sent_at', null)
            ->assertJsonPath('data.delivered_at', null)
            ->assertJsonPath('data.dropped_at', null);
    }

    public function test_returns_404_for_nonexistent_notification(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/notifications/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource not found']);
    }

    public function test_returns_401_without_token(): void
    {
        $response = $this->getJson('/api/v1/notifications/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(401);
    }
}
