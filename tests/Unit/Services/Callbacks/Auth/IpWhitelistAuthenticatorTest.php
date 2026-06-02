<?php

namespace Tests\Unit\Services\Callbacks\Auth;

use App\Exceptions\Callbacks\InvalidCallbackSignatureException;
use App\Services\Callbacks\Auth\IpWhitelistAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IpWhitelistAuthenticatorTest extends TestCase
{
    private IpWhitelistAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticator = new IpWhitelistAuthenticator;
    }

    public function test_ip_within_cidr_passes(): void
    {
        $request = $this->makeRequest('10.0.0.5');

        $this->authenticator->verify($request, [
            'allowed_cidrs' => '10.0.0.0/24',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_ip_outside_cidr_throws_exception(): void
    {
        $request = $this->makeRequest('192.168.1.1');

        $this->expectException(InvalidCallbackSignatureException::class);

        $this->authenticator->verify($request, [
            'allowed_cidrs' => '10.0.0.0/24',
        ]);
    }

    public function test_ip_outside_cidr_has_correct_reason(): void
    {
        $request = $this->makeRequest('192.168.1.1');

        try {
            $this->authenticator->verify($request, ['allowed_cidrs' => '10.0.0.0/24']);
            $this->fail('Ожидалось исключение');
        } catch (InvalidCallbackSignatureException $e) {
            $this->assertSame('ip_not_whitelisted', $e->reason);
        }
    }

    public function test_empty_allowed_cidrs_rejects_all(): void
    {
        $request = $this->makeRequest('10.0.0.5');

        $this->expectException(InvalidCallbackSignatureException::class);

        $this->authenticator->verify($request, ['allowed_cidrs' => '']);
    }

    public function test_null_allowed_cidrs_rejects_all(): void
    {
        $request = $this->makeRequest('10.0.0.5');

        $this->expectException(InvalidCallbackSignatureException::class);

        $this->authenticator->verify($request, ['allowed_cidrs' => null]);
    }

    public function test_missing_allowed_cidrs_key_rejects_all(): void
    {
        $request = $this->makeRequest('10.0.0.5');

        $this->expectException(InvalidCallbackSignatureException::class);

        $this->authenticator->verify($request, []);
    }

    public function test_cidr_slash24_accepts_correct_ip(): void
    {
        $request = $this->makeRequest('10.0.0.5');

        $this->authenticator->verify($request, ['allowed_cidrs' => '10.0.0.0/24']);

        $this->addToAssertionCount(1);
    }

    public function test_cidr_slash24_rejects_different_subnet(): void
    {
        $request = $this->makeRequest('10.0.1.5');

        $this->expectException(InvalidCallbackSignatureException::class);

        $this->authenticator->verify($request, ['allowed_cidrs' => '10.0.0.0/24']);
    }

    public function test_ipv6_within_cidr_passes(): void
    {
        $request = $this->makeRequest('2001:db8::1');

        $this->authenticator->verify($request, [
            'allowed_cidrs' => '2001:db8::/32',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_multiple_cidrs_accepts_matching(): void
    {
        $request = $this->makeRequest('172.16.0.5');

        $this->authenticator->verify($request, [
            'allowed_cidrs' => '10.0.0.0/24,172.16.0.0/24',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_stateless_consecutive_calls_use_own_config(): void
    {
        $authenticator = new IpWhitelistAuthenticator;

        // Первый вызов с подсетью 10.x
        $authenticator->verify($this->makeRequest('10.0.0.5'), ['allowed_cidrs' => '10.0.0.0/24']);

        // Второй вызов с другой подсетью — должен использовать свой конфиг, а не кэшированный
        $authenticator->verify($this->makeRequest('192.168.1.5'), ['allowed_cidrs' => '192.168.1.0/24']);

        $this->addToAssertionCount(1);
    }

    public function test_failed_auth_logs_warning_without_body(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return isset($context['adapter'], $context['reason'], $context['client_ip'])
                    && ! isset($context['body'])
                    && ! isset($context['content']);
            });

        $request = $this->makeRequest('192.168.1.1');
        $request->headers->set('X-Adapter', 'smsc');

        try {
            $this->authenticator->verify($request, [
                'adapter' => 'smsc',
                'allowed_cidrs' => '10.0.0.0/24',
            ]);
        } catch (InvalidCallbackSignatureException) {
        }
    }

    private function makeRequest(string $ip): Request
    {
        $request = Request::create('/', 'POST');
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }
}
