<?php

namespace App\Gateways;

readonly class GatewayResponse
{
    public function __construct(
        public string $providerMessageId,
        public \DateTimeImmutable $acceptedAt,
    ) {}
}
