<?php

namespace Tests\Feature\Console;

use App\Messaging\DeliveryProfile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * notifications:priorities:check-broker сверяет профиль доставки
 * с реально объявленными в брокере очередями.
 */
class PrioritiesCheckBrokerCommandTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalPriorities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPriorities = (array) config('notifications.delivery_profiles');
    }

    protected function tearDown(): void
    {
        config(['notifications.delivery_profiles' => $this->originalPriorities]);
        $this->app->forgetInstance(DeliveryProfile::class);
        parent::tearDown();
    }

    // Dev-брокер содержит все очереди профиля доставки (exit 0)
    public function test_happy_path_against_dev_broker_returns_success(): void
    {
        $exitCode = Artisan::call('notifications:priorities:check-broker');
        $this->assertSame(0, $exitCode, Artisan::output());
    }

    // Добавлен phantom-приоритет с очередью, которой в брокере нет (exit 1).
    // В выводе есть phantom и missing.
    public function test_phantom_priority_marked_as_missing(): void
    {
        config(['notifications.delivery_profiles.phantom' => [
            'queue' => 'notifications.phantom.test',
            'workers' => 1,
            'prefetch' => 1,
        ]]);
        $this->app->forgetInstance(DeliveryProfile::class);

        $exitCode = Artisan::call('notifications:priorities:check-broker');
        $this->assertSame(1, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('phantom', $output);
        $this->assertStringContainsString('missing', $output);
    }
}
