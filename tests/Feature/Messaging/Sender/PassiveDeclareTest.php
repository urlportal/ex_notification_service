<?php

namespace Tests\Feature\Messaging\Sender;

use App\Messaging\DeliveryProfile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * Три сценария:
 * - happy: валидный приоритет, очередь в брокере существует (exit 0).
 * - missing queue: валидное имя приоритета в профиле, но очередь
 *   в брокере не объявлена (exit 1), error-лог про "not declared in broker".
 * - unknown priority: имя приоритета отсутствует в профиле
 *   (exit 2 - INVALID), AMQP-соединение НЕ открывается.
 */
class PassiveDeclareTest extends SenderTestCase
{
    /** @var array<string, mixed> */
    private array $originalPriorities;

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPriorities = (array) config('notifications.delivery_profiles');

        // Захватываем все Monolog-записи через TestHandler, прицеплённый к
        // underlying-логгеру приложения.
        $this->logHandler = new TestHandler;
        $monolog = Log::getLogger();
        if ($monolog instanceof Logger) {
            $monolog->pushHandler($this->logHandler);
        }
    }

    protected function tearDown(): void
    {
        config(['notifications.delivery_profiles' => $this->originalPriorities]);
        $this->app->forgetInstance(DeliveryProfile::class);

        $monolog = Log::getLogger();
        if ($monolog instanceof Logger) {
            $monolog->popHandler();
        }

        parent::tearDown();
    }

    // Валидный приоритет, очередь существует. Sender стартует и успешно обрабатывает сообщение.
    public function test_existing_queue_passive_declare_succeeds(): void
    {
        $this->bindSuccessGateway();

        $notification = $this->createQueuedNotification([
            'priority' => 'transactional',
        ]);
        $this->publishSend((string) $notification->id, self::QUEUE_TRANSACTIONAL);

        $exitCode = Artisan::call('notifications:send', [
            '--queue' => 'transactional',
            '--max-messages' => 1,
        ]);
        $this->assertSame(0, $exitCode);
    }

    // Имя приоритета валидно, но очередь в брокере не объявлена.
    // Passive_declare ловит 404, exit 1, error-лог.
    public function test_missing_queue_in_broker_exits_with_failure(): void
    {
        config(['notifications.delivery_profiles.phantom' => [
            'queue' => 'nonexistent.queue',
            'workers' => 1,
            'prefetch' => 1,
        ]]);
        $this->app->forgetInstance(DeliveryProfile::class);

        $exitCode = Artisan::call('notifications:send', [
            '--queue' => 'phantom',
            '--max-messages' => 1,
        ]);
        $this->assertSame(1, $exitCode);

        $found = false;
        foreach ($this->logHandler->getRecords() as $r) {
            if (str_contains($r->message, 'not declared in broker')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'ожидался error-лог про "not declared in broker"');
    }

    // --queue=unknown_priority (exit 2 - INVALID), AMQP не открывается
    // (нет логов про probe queue или AMQP-соединение).
    public function test_unknown_priority_exits_with_invalid_without_opening_amqp(): void
    {
        $exitCode = Artisan::call('notifications:send', [
            '--queue' => 'no_such_priority',
            '--max-messages' => 1,
        ]);
        $this->assertSame(2, $exitCode);

        // В выводе artisan-команды должно быть сообщение про unknown priority.
        $output = Artisan::output();
        $this->assertStringContainsString("unknown priority 'no_such_priority'", $output);
        $this->assertStringContainsString('expected one of:', $output);

        // Нет записей про probe queue или not declared in broker.
        foreach ($this->logHandler->getRecords() as $r) {
            $this->assertStringNotContainsString('not declared in broker', $r->message);
            $this->assertStringNotContainsString('cannot probe queue', $r->message);
            $this->assertStringNotContainsString('passive_declare failed', $r->message);
        }
    }
}
