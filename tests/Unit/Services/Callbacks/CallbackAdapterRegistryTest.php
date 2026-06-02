<?php

namespace Tests\Unit\Services\Callbacks;

use App\Enums\NotificationChannel;
use App\Exceptions\NotFoundException;
use App\Services\Callbacks\Auth\IpWhitelistAuthenticator;
use App\Services\Callbacks\Auth\MailgunSignatureAuthenticator;
use App\Services\Callbacks\CallbackAdapterRegistry;
use App\Services\Callbacks\Parsing\MailgunCallbackParser;
use App\Services\Callbacks\Parsing\SmscCallbackParser;
use Tests\TestCase;

class CallbackAdapterRegistryTest extends TestCase
{
    public function test_existing_adapter_returns_authenticator(): void
    {
        $registry = $this->makeRegistry();

        $this->assertInstanceOf(IpWhitelistAuthenticator::class, $registry->getAuthenticator('smsc'));
        $this->assertInstanceOf(MailgunSignatureAuthenticator::class, $registry->getAuthenticator('mailgun'));
    }

    public function test_existing_adapter_returns_parser(): void
    {
        $registry = $this->makeRegistry();

        $this->assertInstanceOf(SmscCallbackParser::class, $registry->getParser('smsc'));
        $this->assertInstanceOf(MailgunCallbackParser::class, $registry->getParser('mailgun'));
    }

    public function test_existing_adapter_returns_channel(): void
    {
        $registry = $this->makeRegistry();

        $this->assertSame(NotificationChannel::Sms, $registry->getChannel('smsc'));
        $this->assertSame(NotificationChannel::Email, $registry->getChannel('mailgun'));
    }

    public function test_existing_adapter_returns_config(): void
    {
        $registry = $this->makeRegistry();

        $config = $registry->getConfig('smsc');

        $this->assertArrayHasKey('allowed_cidrs', $config);
    }

    public function test_unknown_adapter_throws_not_found(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(NotFoundException::class);

        $registry->getAuthenticator('unknown-provider');
    }

    public function test_unknown_adapter_throws_not_found_for_parser(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(NotFoundException::class);

        $registry->getParser('unknown-provider');
    }

    public function test_keys_returns_all_registered_adapters(): void
    {
        $registry = $this->makeRegistry();

        $keys = $registry->keys();

        $this->assertContains('smsc', $keys);
        $this->assertContains('mailgun', $keys);
        $this->assertCount(2, $keys);
    }

    private function makeRegistry(): CallbackAdapterRegistry
    {
        return new CallbackAdapterRegistry(
            config: [
                'smsc' => [
                    'channel' => 'sms',
                    'authenticator' => IpWhitelistAuthenticator::class,
                    'parser' => SmscCallbackParser::class,
                    'allowed_cidrs' => '10.0.0.0/8',
                ],
                'mailgun' => [
                    'channel' => 'email',
                    'authenticator' => MailgunSignatureAuthenticator::class,
                    'parser' => MailgunCallbackParser::class,
                    'signing_key' => 'test-key',
                    'replay_window_seconds' => 300,
                ],
            ],
        );
    }
}
