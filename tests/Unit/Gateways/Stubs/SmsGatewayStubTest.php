<?php

namespace Tests\Unit\Gateways\Stubs;

use App\Gateways\Exceptions\PermanentGatewayException;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Gateways\GatewayResponse;
use App\Gateways\RandomProviderInterface;
use App\Gateways\Stubs\SmsGatewayStub;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SmsGatewayStubTest extends TestCase
{
    private function makeStub(float ...$chances): SmsGatewayStub
    {
        /** @var RandomProviderInterface&MockInterface $random */
        $random = Mockery::mock(RandomProviderInterface::class);
        $random->allows('nextFloat')->andReturn(...$chances);

        return new SmsGatewayStub($random);
    }

    private function makeNotification(): Notification
    {
        $n = new Notification;
        $n->id = 'test-notification-sms-id';

        return $n;
    }

    // --- US1: Success path ---

    public function test_returns_gateway_response_on_success(): void
    {
        // chance=0.5 — выше суммы дефолтных порогов (permanentFailureRate=0.02, temporaryFailureRate=0.10)
        $stub = $this->makeStub(0.5);
        $response = $stub->send($this->makeNotification());

        $this->assertInstanceOf(GatewayResponse::class, $response);
        $this->assertNotEmpty($response->providerMessageId);
        $this->assertInstanceOf(\DateTimeImmutable::class, $response->acceptedAt);
    }

    // --- US2: Temporary failure ---

    public function test_throws_temporary_exception_when_chance_in_temporary_failure_zone(): void
    {
        // chance=0.05: попадает в зону временного сбоя [0.02, 0.12) → TemporaryGatewayException
        $stub = $this->makeStub(0.05);

        $this->expectException(TemporaryGatewayException::class);
        $stub->send($this->makeNotification());
    }

    public function test_does_not_throw_temporary_exception_when_chance_above_temporary_failure_zone(): void
    {
        $stub = $this->makeStub(0.5);

        $this->expectNotToPerformAssertions();
        $stub->send($this->makeNotification());
    }

    // --- US3: Permanent failure ---

    public function test_throws_permanent_exception_when_chance_in_permanent_failure_zone(): void
    {
        // chance=0.01: попадает в зону постоянного сбоя [0, 0.02) → PermanentGatewayException
        $stub = $this->makeStub(0.01);

        $this->expectException(PermanentGatewayException::class);
        $stub->send($this->makeNotification());
    }

    public function test_does_not_throw_permanent_exception_when_chance_above_permanent_failure_zone(): void
    {
        $stub = $this->makeStub(0.5);

        $this->expectNotToPerformAssertions();
        $stub->send($this->makeNotification());
    }

    // --- US4: Logging ---

    public function test_logs_info_on_success(): void
    {
        Log::spy();

        $stub = $this->makeStub(0.5);
        $stub->send($this->makeNotification());

        Log::shouldHaveReceived('info')->withArgs(function (string $msg, array $ctx): bool {
            return isset($ctx['notification_id'], $ctx['channel'], $ctx['decision']);
        })->once();
    }

    public function test_logs_warning_on_temporary_failure(): void
    {
        Log::spy();

        $stub = $this->makeStub(0.05);

        try {
            $stub->send($this->makeNotification());
        } catch (TemporaryGatewayException) {
        }

        Log::shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx): bool {
            return isset($ctx['notification_id'], $ctx['channel'], $ctx['decision'])
                && $ctx['decision'] === 'temporary_failure';
        })->once();
    }

    public function test_logs_warning_on_permanent_failure(): void
    {
        Log::spy();

        $stub = $this->makeStub(0.01);

        try {
            $stub->send($this->makeNotification());
        } catch (PermanentGatewayException) {
        }

        Log::shouldHaveReceived('warning')->withArgs(function (string $msg, array $ctx): bool {
            return isset($ctx['notification_id'], $ctx['channel'], $ctx['decision'])
                && $ctx['decision'] === 'permanent_failure';
        })->once();
    }

    // --- T028: Clamp logic (FR-013) ---

    public function test_clamps_temp_rate_exceeding_one(): void
    {
        // temporaryFailureRate=1.5 → зажимается до (1.0 - permanentFailureRate=0.02) = 0.98
        // chance=0.5: попадает в зону временного сбоя [0.02, 1.0) → TemporaryGatewayException
        config(['gateway.stubs.temp_failure_rate' => 1.5]);

        $stub = $this->makeStub(0.5);

        $this->expectException(TemporaryGatewayException::class);
        $stub->send($this->makeNotification());
    }

    public function test_clamps_temp_rate_when_perm_plus_temp_exceeds_one(): void
    {
        // permanentFailureRate=0.8, temporaryFailureRate=0.5 → temp зажимается до (1.0 - 0.8) = 0.2
        // chance=0.85: попадает в зону временного сбоя [0.8, 1.0) → TemporaryGatewayException
        config([
            'gateway.stubs.perm_failure_rate' => 0.8,
            'gateway.stubs.temp_failure_rate' => 0.5,
        ]);

        $stub = $this->makeStub(0.85);

        $this->expectException(TemporaryGatewayException::class);
        $stub->send($this->makeNotification());
    }
}
