<?php

namespace Tests\Feature\Subscribers;

use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriberCrudTest extends TestCase
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

    // Store

    public function test_creates_subscriber_with_email(): void
    {
        $response = $this->withHeaders($this->auth())
            ->postJson('/api/v1/subscribers', ['email' => 'user@example.com']);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'email', 'name', 'phone', 'created_at', 'updated_at']])
            ->assertJsonPath('data.email', 'user@example.com');

        $this->assertDatabaseHas('subscribers', ['email' => 'user@example.com']);
    }

    public function test_creates_subscriber_with_phone_only(): void
    {
        $response = $this->withHeaders($this->auth())
            ->postJson('/api/v1/subscribers', ['phone' => '+79991234567']);

        $response->assertStatus(201)
            ->assertJsonPath('data.phone', '+79991234567');

        $this->assertDatabaseHas('subscribers', ['phone' => '+79991234567']);
    }

    public function test_returns_422_when_both_phone_and_email_missing(): void
    {
        $response = $this->withHeaders($this->auth())
            ->postJson('/api/v1/subscribers', ['name' => 'John']);

        $response->assertStatus(422);
    }

    public function test_returns_409_on_duplicate_phone(): void
    {
        Subscriber::create(['phone' => '+79991234567']);

        $response = $this->withHeaders($this->auth())
            ->postJson('/api/v1/subscribers', ['phone' => '+79991234567']);

        $response->assertStatus(409);
    }

    public function test_returns_422_for_invalid_phone_format(): void
    {
        $response = $this->withHeaders($this->auth())
            ->postJson('/api/v1/subscribers', ['phone' => 'invalid']);

        $response->assertStatus(422);
    }

    public function test_returns_422_for_invalid_email_format(): void
    {
        $response = $this->withHeaders($this->auth())
            ->postJson('/api/v1/subscribers', ['email' => 'not-an-email']);

        $response->assertStatus(422);
    }

    public function test_returns_401_without_token(): void
    {
        $response = $this->postJson('/api/v1/subscribers', ['email' => 'user@example.com']);

        $response->assertStatus(401);
    }

    public function test_returns_401_for_get_list_without_token(): void
    {
        $response = $this->getJson('/api/v1/subscribers');

        $response->assertStatus(401);
    }

    // Show

    public function test_returns_subscriber_by_id(): void
    {
        $subscriber = Subscriber::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$subscriber->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $subscriber->id)
            ->assertJsonPath('data.email', 'alice@example.com')
            ->assertJsonPath('data.name', 'Alice');
    }

    public function test_returns_404_for_nonexistent_subscriber(): void
    {
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/subscribers/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource not found']);
    }

    // Index

    public function test_lists_subscribers_with_pagination(): void
    {
        Subscriber::create(['email' => 'first@example.com']);
        Subscriber::create(['email' => 'second@example.com']);

        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/subscribers');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('meta.total', 2);
    }

    public function test_filters_by_phone(): void
    {
        $phone = '+79991234567';
        Subscriber::create(['phone' => $phone]);
        Subscriber::create(['email' => 'other@example.com']);

        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/subscribers?phone='.urlencode($phone));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.phone', $phone);
    }

    public function test_filters_by_email(): void
    {
        $email = 'a@example.com';
        Subscriber::create(['email' => $email]);
        Subscriber::create(['email' => 'b@example.com']);

        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/subscribers?email='.urlencode($email));

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', $email);
    }

    // Update

    public function test_updates_name_only(): void
    {
        $subscriber = Subscriber::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'phone' => '+79991234567',
        ]);

        $response = $this->withHeaders($this->auth())
            ->patchJson("/api/v1/subscribers/{$subscriber->id}", ['name' => 'Bob']);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Bob')
            ->assertJsonPath('data.email', 'alice@example.com')
            ->assertJsonPath('data.phone', '+79991234567');

        $this->assertDatabaseHas('subscribers', ['id' => $subscriber->id, 'name' => 'Bob']);
    }

    public function test_returns_404_on_update_nonexistent(): void
    {
        $response = $this->withHeaders($this->auth())
            ->patchJson('/api/v1/subscribers/00000000-0000-0000-0000-000000000000', ['name' => 'Bob']);

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource not found']);
    }

    public function test_returns_422_when_patch_would_clear_last_contact(): void
    {
        $subscriber = Subscriber::create(['phone' => '+79991234567']);

        $response = $this->withHeaders($this->auth())
            ->patchJson("/api/v1/subscribers/{$subscriber->id}", ['phone' => null]);

        $response->assertStatus(422);
    }

    public function test_can_clear_phone_when_email_exists(): void
    {
        $subscriber = Subscriber::create([
            'phone' => '+79991234567',
            'email' => 'alice@example.com',
        ]);

        $response = $this->withHeaders($this->auth())
            ->patchJson("/api/v1/subscribers/{$subscriber->id}", ['phone' => null]);

        $response->assertStatus(200)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.email', 'alice@example.com');

        $this->assertDatabaseHas('subscribers', ['id' => $subscriber->id, 'phone' => null]);
    }

    public function test_returns_409_on_update_duplicate_phone(): void
    {
        Subscriber::create(['phone' => '+79991234567']);
        $subscriber = Subscriber::create(['email' => 'other@example.com']);

        $response = $this->withHeaders($this->auth())
            ->patchJson("/api/v1/subscribers/{$subscriber->id}", ['phone' => '+79991234567']);

        $response->assertStatus(409);
    }

    // Delete

    public function test_deletes_subscriber(): void
    {
        $subscriber = Subscriber::create(['email' => 'test@example.com']);

        $response = $this->withHeaders($this->auth())
            ->deleteJson("/api/v1/subscribers/{$subscriber->id}");

        $response->assertStatus(204)->assertNoContent();
        $this->assertDatabaseMissing('subscribers', ['id' => $subscriber->id]);
    }

    public function test_returns_404_on_delete_nonexistent(): void
    {
        $response = $this->withHeaders($this->auth())
            ->deleteJson('/api/v1/subscribers/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource not found']);
    }

    public function test_get_returns_404_after_delete(): void
    {
        $subscriber = Subscriber::create(['email' => 'test@example.com']);

        $this->withHeaders($this->auth())
            ->deleteJson("/api/v1/subscribers/{$subscriber->id}");

        $response = $this->withHeaders($this->auth())
            ->getJson("/api/v1/subscribers/{$subscriber->id}");

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource not found']);
    }
}
