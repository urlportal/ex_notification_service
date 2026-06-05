<?php

namespace Tests\Feature\Console;

use App\Providers\RescueSweepConfigServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Проверки конфигурации rescue sweep при старте приложения и построение расписания.
 */
class RescueSweepConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Максимальный TTL retry-очередей 30 минут
        Config::set('notifications.retry.levels', [
            1 => ['key' => '30s', 'ttl_ms' => 30000],
            2 => ['key' => '5m', 'ttl_ms' => 300000],
            3 => ['key' => '30m', 'ttl_ms' => 1800000],
        ]);
        Config::set('notifications.rescue_sweep.schedule_minutes', 15);
    }

    public function test_threshold_is_calculated_from_max_retry_ttl_when_not_set(): void
    {
        Config::set('notifications.rescue_sweep.threshold_minutes', null);

        (new RescueSweepConfigServiceProvider($this->app))->boot();

        $this->assertSame(45, config('notifications.rescue_sweep.threshold_minutes'));
    }

    public function test_boot_fails_when_threshold_does_not_exceed_max_retry_ttl(): void
    {
        Config::set('notifications.rescue_sweep.threshold_minutes', 30);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RESCUE_SWEEP_THRESHOLD_MINUTES (30)');

        (new RescueSweepConfigServiceProvider($this->app))->boot();
    }

    #[DataProvider('invalidScheduleMinutesProvider')]
    public function test_boot_fails_when_schedule_minutes_is_not_divisor_of_hour(int $scheduleMinutes): void
    {
        Config::set('notifications.rescue_sweep.threshold_minutes', 45);
        Config::set('notifications.rescue_sweep.schedule_minutes', $scheduleMinutes);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("RESCUE_SWEEP_SCHEDULE_MINUTES ({$scheduleMinutes})");

        (new RescueSweepConfigServiceProvider($this->app))->boot();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidScheduleMinutesProvider(): array
    {
        return [
            'zero' => [0],
            'not divisor' => [45],
            'over hour' => [90],
        ];
    }

    #[DataProvider('scheduleExpressionProvider')]
    public function test_schedule_uses_uniform_cron_expression(int $scheduleMinutes, string $expectedExpression): void
    {
        Config::set('notifications.rescue_sweep.schedule_minutes', $scheduleMinutes);

        // Расписание регистрируется при старте Artisan, поэтому проверяем через schedule:list
        $exitCode = Artisan::call('schedule:list');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString($expectedExpression.'  php artisan notifications:rescue-sweep', Artisan::output());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function scheduleExpressionProvider(): array
    {
        return [
            'every 15 minutes' => [15, '*/15 * * * *'],
            'hourly' => [60, '0 * * * *'],
        ];
    }
}
