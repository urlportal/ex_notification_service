<?php

namespace App\Http\DTO\Subscribers;

readonly class SubscriberDto
{
    public function __construct(
        public ?string $name,
        public ?string $phone,
        public ?string $email,
    ) {}
}
