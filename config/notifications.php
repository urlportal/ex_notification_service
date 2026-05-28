<?php

return [
    'bulk' => [
        // Максимум subscriber_ids в одном запросе POST /api/v1/notifications/bulk.
        'max_subscribers' => (int) env('BULK_MAX_SUBSCRIBERS', 500),
    ],
];
