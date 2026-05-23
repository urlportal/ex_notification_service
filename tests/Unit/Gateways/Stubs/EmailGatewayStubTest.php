<?php

namespace Tests\Unit\Gateways\Stubs;

use App\Gateways\Exceptions\PermanentGatewayException;
use App\Gateways\Exceptions\TemporaryGatewayException;
use App\Gateways\GatewayResponse;
use App\Gateways\RandomProviderInterface;
use App\Gateways\Stubs\EmailGatewayStub;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailGatewayStubTest extends TestCase
{
    private function makeStub(float ...$chances): EmailGatewayStub
    {
        /** @var RandomProviderInterface&MockInterface $random */
        $random = Mockery::mock(RandomProviderInterface::class);
        $random->allows('nextFloat')->andReturn(...$chances);

        return new EmailGatewayStub($random);
    }

    private function makeNotification(): Notification
    {
        $n = new Notification;
        $n->id = 'test-notification-email-id';

        return $n;
    }

    // Success

    public function test_returns_gateway_response_on_success(): void
    {
        $stub = $this->makeStub(0.5);
        $response = $stub->send($this->makeNotification());

        $this->assertInstanceOf(GatewayResponse::class, $response);
        $this->assertNotEmpty($response->providerMessageId);
        $this->assertInstanceOf(\DateTimeImmutable::class, $response->acceptedAt);
    }

    // Temporary failure

    public function test_throws_temporary_exception_when_chance_in_temporary_failure_zone(): void
    {
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

    // Permanent failure

    public function test_throws_permanent_exception_when_chance_in_permanent_failure_zone(): void
    {
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

    // Logging

    public function test_logs_info_on_success(): void
    {
        Log::spy();

        $stub = $this->makeStub(0.5);
        $stub->send($this->makeNotification());

        Log::shouldHaveReceived('info')->withArgs(function (string $msg, array $ctx): bool {
            return isset($ctx['notification_id'], $ctx['channel'], $ctx['decision'])
                && $ctx['channel'] === 'email';
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
}
