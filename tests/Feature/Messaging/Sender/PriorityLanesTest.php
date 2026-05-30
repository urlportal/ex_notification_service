<?php

namespace Tests\Feature\Messaging\Sender;

use Illuminate\Support\Facades\DB;

class PriorityLanesTest extends SenderTestCase
{
    // Две независимые lane: sender-transactional обрабатывает только
    // notifications.transactional, sender-marketing - только notifications.marketing.
    public function test_each_lane_processes_only_its_own_queue(): void
    {
        $this->bindSuccessGateway();

        $transactionalIds = [];
        for ($i = 0; $i < 2; $i++) {
            $notification = $this->createQueuedNotification([
                'priority' => 'transactional',
            ]);
            $transactionalIds[] = (string) $notification->id;
            $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);
        }

        $marketingIds = [];
        for ($i = 0; $i < 5; $i++) {
            $notification = $this->createQueuedNotification([
                'priority' => 'marketing',
            ]);
            $marketingIds[] = (string) $notification->id;
            $this->publishSend((string) $notification->id, self::QUEUE_MARKETING);
        }

        // Прогон обеих lane (последовательно - проверяем разделение по
        // consumer_name в msg_inbox; параллелизм lanes - свойство архитектуры,
        // не логики тестового прогона).
        $this->consume('transactional', 2);
        $this->consume('marketing', 5);

        $inboxTx = DB::table('msg_inbox')->where('consumer_name', 'sender-transactional')->count();
        $inboxMk = DB::table('msg_inbox')->where('consumer_name', 'sender-marketing')->count();
        $this->assertSame(2, $inboxTx, 'sender-transactional должен зафиксировать 2 сообщения');
        $this->assertSame(5, $inboxMk, 'sender-marketing должен зафиксировать 5 сообщений');

        // Все уведомления стали sent (gateway success).
        $this->assertSame(7, DB::table('notifications')->where('status', 'sent')->count());
    }
}
