<?php

namespace Tests\Feature\Messaging\Sender;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Gateways\GatewayResponse;
use App\Models\Notification;
use Illuminate\Support\Facades\Artisan;

class GracefulShutdownTest extends SenderTestCase
{
    // SIGTERM во время обработки -> exit 0 в пределах stopwaitsecs; статус
    // консистентен (queued или sent, без промежуточного).
    public function test_sigterm_exits_zero_after_current_command(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork / posix_kill недоступны в тестовом окружении');
        }

        // Замедленный gateway: 2 секунды задержки, затем успех. SIGTERM придёт
        // во время usleep - команда должна доделать send и закоммитить.
        $this->bindGateway(
            NotificationChannel::Email,
            static function (Notification $n): GatewayResponse {
                usleep(2_000_000);

                return new GatewayResponse(
                    providerMessageId: 'prov-'.$n->id,
                    acceptedAt: new \DateTimeImmutable,
                );
            },
        );

        $notification = $this->createQueuedNotification();
        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        // Закроем родительские соединения перед fork - дочерний откроет свои.
        $this->amqpFactory->close();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork не удался');
        }

        if ($pid === 0) {
            // Дочерний процесс: бесконечный consumer (без --max-messages).
            $exitCode = Artisan::call('notifications:send', ['--queue' => 'transactional']);
            exit($exitCode);
        }

        // Родитель: ждём начала обработки, затем SIGTERM.
        usleep(500_000);
        posix_kill($pid, SIGTERM);

        $status = 0;
        pcntl_waitpid($pid, $status);
        $exitCode = pcntl_wexitstatus($status);

        $this->assertSame(0, $exitCode, 'sender должен завершиться exit 0 после SIGTERM');

        $fresh = Notification::query()->findOrFail($notification->id);
        // Статус либо queued (транзакция откатилась), либо sent (успели
        // закоммитить до SIGKILL). Промежуточного состояния быть не должно.
        $this->assertContains($fresh->status, [
            NotificationStatus::Queued,
            NotificationStatus::Sent,
        ], 'статус должен быть либо queued, либо sent');
    }
}
