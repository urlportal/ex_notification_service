<?php

return [
    // TTL ключей идемпотентности в Redis
    'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),
];
