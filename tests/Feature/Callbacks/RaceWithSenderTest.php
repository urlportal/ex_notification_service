<?php

namespace Tests\Feature\Callbacks;

use App\Enums\NotificationStatus;
use App\Repositories\NotificationRepositoryInterface;

/**
 * Последовательная симуляция гонки между sender и callback.
 * PHPUnit однопоточный; корректность при реальной конкурентности
 * обеспечивается lockForUpdate на уровне PostgreSQL.
 */
class RaceWithSenderTest extends CallbackTestCase
{
    public function test_sender_then_callback_results_in_delivered(): void
    {
        $notification = $this->createNotification([
            'status' => NotificationStatus::Queued,
            'sent_at' => null,
            'provider_message_id' => null,
            'attempts' => 0,
        ]);

        $messageId = '<race-test-msg@mailgun.org>';

        $repo = app(NotificationRepositoryInterface::class);
        $repo->updateOnSent($notification->id, $messageId, new \DateTimeImmutable, 1);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'sent',
        ]);

        $response = $this->postJson(
            '/api/callbacks/mailgun',
            $this->mailgunDeliveredPayload($messageId)
        );

        $response->assertNoContent();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => 'delivered',
        ]);
    }
}
