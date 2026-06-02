<?php

namespace App\Dto;

use App\Enums\NotificationChannel;
use App\Enums\ProviderCallbackStatus;
use DateTimeImmutable;

readonly class ProviderCallbackDto
{
    public function __construct(
        public string $providerMessageId,
        public ProviderCallbackStatus $status,
        public NotificationChannel $channel,
        public ?string $error,
        public DateTimeImmutable $occurredAt,
    ) {}
}
