<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),
    'tls' => (bool) env('RABBITMQ_TLS', false),

    // Fanout-топология приёма массовых рассылок.
    // Одно сообщение группы публикуется сюда из msg_outbox.
    // Consumer разворачивает его в индивидуальные уведомления.
    'fanout' => [
        'exchange' => env('RABBITMQ_FANOUT_EXCHANGE', 'notifications.fanout.exchange'),
        'queue' => env('RABBITMQ_FANOUT_QUEUE', 'notifications.fanout'),
        'routing_key' => env('RABBITMQ_FANOUT_ROUTING_KEY', 'fanout'),
    ],
];
