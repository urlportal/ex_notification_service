<?php

return [
    'bulk' => [
        // Максимум subscriber_ids в одном запросе POST /api/v1/notifications/bulk.
        'max_subscribers' => (int) env('BULK_MAX_SUBSCRIBERS', 500),
    ],

    'bulk_expand' => [
        // Размер одного INSERT-батча при разворачивании bulk-expand-event.
        'chunk_size' => (int) env('BULK_EXPAND_CHUNK_SIZE', 100),
    ],
];
