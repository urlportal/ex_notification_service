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

    // Профили доставки. Ключ массива - имя (snake_case), используется как
    // routing_key в notifications.exchange, имя program supervisord (sender-<name>)
    // и path-параметр URL. Поле queue - имя AMQP-очереди в брокере (может не
    // совпадать с именем профиля). Поля workers/prefetch определяют пул
    // воркеров sender-<name>: workers - numprocs supervisord,
    // prefetch - basic_qos(prefetch_count).
    // Как добавить новый профиль: записать сюда, объявить очередь и binding
    // (routing_key=<name>) в deploy/dev/rabbitmq/definitions.json,
    // пересобрать/перезапустить контейнер workers (секция supervisord генерируется
    // автоматически), прогнать php artisan notifications:priorities:validate.
    'delivery_profiles' => [
        'transactional' => [
            'queue' => env('RABBITMQ_QUEUE_TRANSACTIONAL', 'notifications.transactional'),
            'workers' => (int) env('SENDER_TX_NUMPROCS', 2),
            'prefetch' => (int) env('SENDER_TX_PREFETCH', 10),
        ],
        'marketing' => [
            'queue' => env('RABBITMQ_QUEUE_MARKETING', 'notifications.marketing'),
            'workers' => (int) env('SENDER_MK_NUMPROCS', 4),
            'prefetch' => (int) env('SENDER_MK_PREFETCH', 10),
        ],
    ],

    // Retry-механизм при временных сбоях gateway.
    // max_attempts - общее число попыток доставки (1 исходная + retry). По умолчанию 4.
    // levels - упорядоченные уровни backoff. Индекс массива (1-based) обозначает
    // номер только что выполненной попытки: после первой неудачи берётся levels[1],
    // после второй - levels[2] и так далее. key - суффикс routing_key retry-копии
    // (<key>.<priority>) и часть имени retry-очереди (notifications.retry.<key>.<priority>).
    // ttl_ms дублирует фактическое значение x-message-ttl соответствующей retry-очереди
    // в deploy/dev/rabbitmq/definitions.json. Приложение TTL не применяет, его задаёт
    // брокер. Изменение ttl_ms без правки definitions.json не повлияет на backoff.
    // Инвариант: count(levels) + 1 === max_attempts. Иначе RetryConfigServiceProvider
    // валит boot приложения.
    'retry' => [
        'max_attempts' => (int) env('RETRY_MAX_ATTEMPTS', 4),
        'levels' => [
            1 => ['key' => '30s', 'ttl_ms' => (int) env('RETRY_30S_TTL_MS', 30000)],
            2 => ['key' => '5m', 'ttl_ms' => (int) env('RETRY_5M_TTL_MS', 300000)],
            3 => ['key' => '30m', 'ttl_ms' => (int) env('RETRY_30M_TTL_MS', 1800000)],
        ],
    ],
];
