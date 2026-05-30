<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IndexSubscriberNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'phpunit-test-token';

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.api_token' => self::TOKEN]);
        $this->subscriber = Subscriber::create(['email' => 'test@example.com']);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    /** @param array<string, mixed> $attrs */
    private function makeNotification(Subscriber $subscriber, array $attrs = []): Notification
    {
        return Notification::create(array_merge([
            'subscriber_id' => $subscriber->id,
            'channel' => NotificationChannel::Email->value,
            'priority' => 'transactional',
            'status' => NotificationStatus::Queued->value,
            'message' => 'Test message',
        ], $attrs));
    }

    public function test_returns_empty_list_for_subscriber_with_no_notifications(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications");

        $response->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_returns_paginated_list_with_notifications(): void
    {
        $this->makeNotification($this->subscriber);
        $this->makeNotification($this->subscriber);

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'subscriber_id', 'channel', 'status', 'message']]]);
    }

    public function test_filters_by_status(): void
    {
        $this->makeNotification($this->subscriber, ['status' => NotificationStatus::Sent->value]);
        $this->makeNotification($this->subscriber, ['status' => NotificationStatus::Delivered->value]);

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?status=sent");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'sent');
    }

    public function test_filters_by_channel(): void
    {
        $this->makeNotification($this->subscriber, ['channel' => NotificationChannel::Sms->value]);
        $this->makeNotification($this->subscriber, ['channel' => NotificationChannel::Email->value]);

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?channel=sms");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', 'sms');
    }

    public function test_filters_by_priority(): void
    {
        $this->makeNotification($this->subscriber, ['priority' => 'marketing']);
        $this->makeNotification($this->subscriber, ['priority' => 'transactional']);

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?priority=marketing");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.priority', 'marketing');
    }

    public function test_combined_filters_return_intersection(): void
    {
        $this->makeNotification($this->subscriber, [
            'status' => NotificationStatus::Sent->value,
            'channel' => NotificationChannel::Sms->value,
        ]);
        $this->makeNotification($this->subscriber, [
            'status' => NotificationStatus::Sent->value,
            'channel' => NotificationChannel::Email->value,
        ]);
        $this->makeNotification($this->subscriber, [
            'status' => NotificationStatus::Delivered->value,
            'channel' => NotificationChannel::Sms->value,
        ]);

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?status=sent&channel=sms");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_returns_404_for_nonexistent_subscriber(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/subscribers/00000000-0000-0000-0000-000000000000/notifications');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource not found']);
    }

    public function test_returns_401_without_token(): void
    {
        $response = $this->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications");

        $response->assertStatus(401);
    }

    public function test_returns_422_for_invalid_status_value(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?status=unknown");

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['status']]);
    }

    public function test_returns_422_when_status_is_array(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?status[]=sent&status[]=delivered");

        $response->assertStatus(422);
    }

    public function test_per_page_limits_results(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->makeNotification($this->subscriber);
        }

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?per_page=5");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5);
    }

    public function test_returns_422_when_per_page_is_zero(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?per_page=0");

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    }

    public function test_returns_422_when_per_page_exceeds_maximum(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?per_page=101");

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    }

    public function test_page_navigation(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->makeNotification($this->subscriber);
        }

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?per_page=5&page=2");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_default_per_page_is_15(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->makeNotification($this->subscriber);
        }

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications");

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonCount(15, 'data');
    }

    public function test_no_n_plus_one_queries(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->makeNotification($this->subscriber);
        }

        DB::enableQueryLog();

        $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications");

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(3, $queries, 'Expected exactly 3 queries (subscriber lookup + COUNT + SELECT), got '.count($queries));
    }

    // Фильтр priority вне DeliveryProfile отклоняется с 422.
    public function test_unknown_priority_filter_returns_422(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$this->subscriber->id}/notifications?priority=no_such_priority");

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['priority']]);
    }
}
