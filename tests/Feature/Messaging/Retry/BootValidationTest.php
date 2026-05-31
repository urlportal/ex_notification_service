<?php

namespace Tests\Feature\Messaging\Retry;

use App\Providers\RetryConfigServiceProvider;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Разность между max_attempts и count(levels) "обвалит" boot
 * приложения. Реализовано в RetryConfigServiceProvider::boot().
 */
class BootValidationTest extends TestCase
{
    public function test_boot_fails_when_levels_fewer_than_expected(): void
    {
        Config::set('notifications.retry.max_attempts', 4);
        Config::set('notifications.retry.levels', [
            1 => ['key' => '30s', 'ttl_ms' => 30000],
            2 => ['key' => '5m', 'ttl_ms' => 300000],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_attempts=4');
        $this->expectExceptionMessage('expects 3 levels');
        $this->expectExceptionMessage('found 2');

        (new RetryConfigServiceProvider($this->app))->boot();
    }

    public function test_boot_fails_when_levels_fewer_than_expected_5_vs_3(): void
    {
        Config::set('notifications.retry.max_attempts', 5);
        Config::set('notifications.retry.levels', [
            1 => ['key' => '30s', 'ttl_ms' => 30000],
            2 => ['key' => '5m', 'ttl_ms' => 300000],
            3 => ['key' => '30m', 'ttl_ms' => 1800000],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects 4 levels');
        $this->expectExceptionMessage('found 3');

        (new RetryConfigServiceProvider($this->app))->boot();
    }

    public function test_boot_passes_with_consistent_configuration(): void
    {
        Config::set('notifications.retry.max_attempts', 4);
        Config::set('notifications.retry.levels', [
            1 => ['key' => '30s', 'ttl_ms' => 30000],
            2 => ['key' => '5m', 'ttl_ms' => 300000],
            3 => ['key' => '30m', 'ttl_ms' => 1800000],
        ]);

        (new RetryConfigServiceProvider($this->app))->boot();

        $this->expectNotToPerformAssertions();
    }
}
