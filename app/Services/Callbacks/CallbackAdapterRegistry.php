<?php

namespace App\Services\Callbacks;

use App\Enums\NotificationChannel;
use App\Exceptions\NotFoundException;
use App\Services\Callbacks\Auth\CallbackAuthenticatorInterface;
use App\Services\Callbacks\Parsing\CallbackPayloadParserInterface;

class CallbackAdapterRegistry
{
    /** @param array<string, array<string, mixed>> $config */
    public function __construct(private readonly array $config) {}

    public function getAuthenticator(string $adapter): CallbackAuthenticatorInterface
    {
        return app($this->adapterConfig($adapter)['authenticator']);
    }

    public function getParser(string $adapter): CallbackPayloadParserInterface
    {
        return app($this->adapterConfig($adapter)['parser']);
    }

    public function getChannel(string $adapter): NotificationChannel
    {
        return NotificationChannel::from($this->adapterConfig($adapter)['channel']);
    }

    /** @return array<string, mixed> */
    public function getConfig(string $adapter): array
    {
        return $this->adapterConfig($adapter);
    }

    /** @return array<string> */
    public function keys(): array
    {
        return array_keys($this->config);
    }

    /** @return array<string, mixed> */
    private function adapterConfig(string $adapter): array
    {
        if (! isset($this->config[$adapter])) {
            throw new NotFoundException("Callback adapter [{$adapter}] not found");
        }

        return $this->config[$adapter];
    }
}
