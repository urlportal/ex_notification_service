<?php

namespace Tests\Feature\Callbacks;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\Subscriber;
use App\Services\Callbacks\CallbackAdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

abstract class CallbackTestCase extends TestCase
{
    use RefreshDatabase;

    protected const string MAILGUN_SIGNING_KEY = 'test-mailgun-signing-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notifications.callbacks.mailgun.signing_key' => self::MAILGUN_SIGNING_KEY,
            'notifications.callbacks.mailgun.replay_window_seconds' => 300,
            'notifications.callbacks.smsc.allowed_cidrs' => '127.0.0.1/32',
        ]);

        // Синглтон захватывает конфиг при создании. Сбрасываем, чтобы следующий
        // resolve подхватил обновлённые ключи.
        app()->forgetInstance(CallbackAdapterRegistry::class);
    }

    /** @param array<string, mixed> $overrides */
    protected function createNotification(array $overrides = []): Notification
    {
        $subscriber = Subscriber::create([
            'email' => 'test-'.Uuid::uuid7()->toString().'@example.com',
        ]);

        $attributes = array_merge([
            'id' => Uuid::uuid7()->toString(),
            'subscriber_id' => $subscriber->id,
            'channel' => NotificationChannel::Email,
            'priority' => 'transactional',
            'status' => NotificationStatus::Sent,
            'message' => 'test message',
            'idempotency_key' => Uuid::uuid7()->toString(),
            'request_id' => Uuid::uuid7()->toString(),
            'provider_message_id' => '<msg-'.Uuid::uuid7()->toString().'@mailgun.org>',
            'attempts' => 1,
            'queued_at' => now(),
            'sent_at' => now(),
        ], $overrides);

        return Notification::create($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mailgunDeliveredPayload(string $messageId): array
    {
        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp.$token, self::MAILGUN_SIGNING_KEY);

        return [
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $signature,
            ],
            'event-data' => [
                'event' => 'delivered',
                'message' => [
                    'headers' => ['message-id' => $messageId],
                ],
                'timestamp' => time(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mailgunFailedPayload(string $messageId, string $reason = 'bounce'): array
    {
        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp.$token, self::MAILGUN_SIGNING_KEY);

        return [
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $signature,
            ],
            'event-data' => [
                'event' => 'failed',
                'reason' => $reason,
                'message' => [
                    'headers' => ['message-id' => $messageId],
                ],
                'timestamp' => time(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mailgunOpenedPayload(string $messageId): array
    {
        $timestamp = (string) time();
        $token = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $timestamp.$token, self::MAILGUN_SIGNING_KEY);

        return [
            'signature' => [
                'timestamp' => $timestamp,
                'token' => $token,
                'signature' => $signature,
            ],
            'event-data' => [
                'event' => 'opened',
                'message' => [
                    'headers' => ['message-id' => $messageId],
                ],
                'timestamp' => time(),
            ],
        ];
    }
}
