<?php

namespace Tests\Feature\Console;

use App\Messaging\DeliveryProfile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * supervisor:render-sender генерирует supervisord-секции sender-* по каталогу приоритетов.
 */
class SupervisorRenderSenderCommandTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = tempnam(sys_get_temp_dir(), 'sender').'.conf';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpPath)) {
            unlink($this->tmpPath);
        }

        parent::tearDown();
    }

    // Два профиля. Генерируются две секции с корректными параметрами (exit 0).
    public function test_renders_two_profiles(): void
    {
        $this->app->instance(DeliveryProfile::class, new DeliveryProfile([
            'transactional' => ['queue' => 'notifications.transactional', 'workers' => 2, 'prefetch' => 10],
            'marketing' => ['queue' => 'notifications.marketing',     'workers' => 4, 'prefetch' => 20],
        ]));

        $exitCode = Artisan::call('supervisor:render-sender', ['--output' => $this->tmpPath]);

        $this->assertSame(0, $exitCode);

        $content = (string) file_get_contents($this->tmpPath);

        $this->assertStringContainsString('[program:sender-transactional]', $content);
        $this->assertStringContainsString('[program:sender-marketing]', $content);

        $this->assertStringContainsString(
            'command=php /app/artisan notifications:send --queue=transactional --prefetch=10',
            $content,
        );
        $this->assertStringContainsString(
            'command=php /app/artisan notifications:send --queue=marketing --prefetch=20',
            $content,
        );

        $this->assertStringContainsString('numprocs=2', $content);
        $this->assertStringContainsString('numprocs=4', $content);

        // Обязательные ключи по правилу supervisord (stdout/stderr в контейнере).
        $this->assertStringContainsString('stdout_logfile=/dev/stdout', $content);
        $this->assertStringContainsString('stdout_logfile_maxbytes=0', $content);
        $this->assertStringContainsString('stderr_logfile=/dev/stderr', $content);
        $this->assertStringContainsString('stderr_logfile_maxbytes=0', $content);
        $this->assertStringContainsString('stopsignal=TERM', $content);
        $this->assertStringContainsString('stopwaitsecs=35', $content);
    }

    // Три профиля (добавлен internal). Кросс-функциональная проверка расширяемости.
    public function test_renders_three_profiles(): void
    {
        $this->app->instance(DeliveryProfile::class, new DeliveryProfile([
            'transactional' => ['queue' => 'notifications.transactional', 'workers' => 2, 'prefetch' => 10],
            'marketing' => ['queue' => 'notifications.marketing',     'workers' => 4, 'prefetch' => 10],
            'internal' => ['queue' => 'notifications.internal',      'workers' => 1, 'prefetch' => 5],
        ]));

        $exitCode = Artisan::call('supervisor:render-sender', ['--output' => $this->tmpPath]);

        $this->assertSame(0, $exitCode);

        $content = (string) file_get_contents($this->tmpPath);

        $this->assertStringContainsString('[program:sender-transactional]', $content);
        $this->assertStringContainsString('[program:sender-marketing]', $content);
        $this->assertStringContainsString('[program:sender-internal]', $content);
        $this->assertStringContainsString(
            'command=php /app/artisan notifications:send --queue=internal --prefetch=5',
            $content,
        );
        $this->assertStringContainsString('numprocs=1', $content);
    }

    // Директория вывода не существует. Команда создаёт её и пишет файл (exit 0).
    public function test_creates_output_directory_if_missing(): void
    {
        $dir = sys_get_temp_dir().'/speckit_'.uniqid('', true);
        $outputPath = $dir.'/sender.conf';

        $this->app->instance(DeliveryProfile::class, new DeliveryProfile([
            'transactional' => ['queue' => 'notifications.transactional', 'workers' => 1, 'prefetch' => 5],
        ]));

        $exitCode = Artisan::call('supervisor:render-sender', ['--output' => $outputPath]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($outputPath);

        // Cleanup.
        unlink($outputPath);
        rmdir($dir);
    }

    // Пустой каталог — команда завершается с exit 1, файл не записывается.
    // Симулируем: конфиг delivery_profiles пуст, синглтон сброшен. При следующем
    // вызове make() фабрика создаёт new DeliveryProfile([]), который бросает
    // InvalidDeliveryProfileException. Перехватывается в handle() и даёт FAILURE.
    public function test_empty_catalog_returns_failure_and_no_file(): void
    {
        $outputPath = sys_get_temp_dir().'/sender_empty_'.uniqid('', true).'.conf';

        config(['notifications.delivery_profiles' => []]);
        $this->app->forgetInstance(DeliveryProfile::class);

        $exitCode = Artisan::call('supervisor:render-sender', ['--output' => $outputPath]);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($outputPath);
    }
}
