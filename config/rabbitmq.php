<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),
    'tls' => (bool) env('RABBITMQ_TLS', false),

    // Приоритетный exchange. Разводит send-команды по приоритетным очередям.
    // Имена очередей и параметры пулов воркеров в config/notifications.php
    // (ключ priorities); доступ через DeliveryProfile. ENV-переменные
    // RABBITMQ_QUEUE_TRANSACTIONAL / RABBITMQ_QUEUE_MARKETING читаются именно оттуда.
    'exchange' => env('RABBITMQ_EXCHANGE', 'notifications.exchange'),

    // Bulk-expand-топология приёма массовых рассылок.
    // Одно сообщение группы публикуется сюда из msg_outbox.
    // Consumer разворачивает его в индивидуальные уведомления.
    'bulk_expand' => [
        'exchange' => env('RABBITMQ_BULK_EXPAND_EXCHANGE', 'notifications.bulk-expand.exchange'),
        'queue' => env('RABBITMQ_BULK_EXPAND_QUEUE', 'notifications.bulk-expand'),
        'routing_key' => env('RABBITMQ_BULK_EXPAND_ROUTING_KEY', 'bulk-expand'),
    ],
];
