<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Models\Notification;

// TemporaryGatewayException ведёт себя как PermanentGatewayException
// (dropped, attempts=1).
class TemporaryBaselineTest extends SenderTestCase
{
    public function test_temporary_failure_baseline_matches_permanent(): void
    {
        $this->bindGateway(
            NotificationChannel::Email,
            static fn (Notification $n) => throw new TemporaryGatewayException('provider unavailable'),
        );

        $notification = $this->createQueuedNotification();
        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $this->consume('transactional', 1);

        $fresh = Notification::query()->findOrFail($notification->id);
        $this->assertSame(NotificationStatus::Dropped, $fresh->status);
        $this->assertNotNull($fresh->dropped_at);
        $this->assertSame('provider unavailable', $fresh->last_error);
        $this->assertSame(1, $fresh->attempts);
    }
}
