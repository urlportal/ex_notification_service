<?php

namespace App\Http\DTO\Health;

readonly class LivenessResponse
{
    public function __construct(
        public string $status,
        public string $version,
        public string $serviceId,
    ) {}
}
