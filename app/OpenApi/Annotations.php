<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'REST API сервис уведомлений',
    title: 'Notification Service API'
)]
#[OA\Server(
    url: '{scheme}://{host}',
    description: 'Сервер API (можете изменить значение домена)',
    variables: [
        new OA\ServerVariable(serverVariable: 'scheme', default: 'http', enum: ['http', 'https']),
        new OA\ServerVariable(
            serverVariable: 'host',
            description: 'Хост и порт (например, example.com)',
            default: 'localhost:8080'
        ),
    ]
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    description: 'Статический Bearer-токен (см. API_TOKEN в конфиге)',
    scheme: 'bearer'
)]
#[OA\Schema(
    schema: 'UnauthorizedResponse',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated'),
    ]
)]
#[OA\Schema(
    schema: 'NotFoundResponse',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Resource not found'),
    ]
)]
#[OA\Schema(
    schema: 'ValidationErrorResponse',
    required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(
            property: 'errors',
            description: 'Ключ является названием поля, а значение массивом строк с ошибками',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))
        ),
    ]
)]
#[OA\Schema(
    schema: 'LivenessResponse',
    required: ['status', 'version', 'serviceId'],
    properties: [
        new OA\Property(property: 'status', type: 'string', example: 'pass'),
        new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
        new OA\Property(property: 'serviceId', type: 'string', example: 'notification-service'),
    ]
)]
#[OA\Schema(
    schema: 'SubscriberResource',
    required: ['id', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(
            property: 'id', description: 'UUID', type: 'string',
            format: 'uuid',
            example: '019600a2-7c3b-7000-9f3b-1234567890ab'
        ),
        new OA\Property(property: 'name', type: 'string', example: 'Иван Петров', nullable: true, maxLength: 120),
        new OA\Property(
            property: 'phone', description: 'Начинается с +, затем цифры', type: 'string', pattern: '^\+[1-9]\d{6,14}$',
            example: '+79001234567', nullable: true,
            maxLength: 32
        ),
        new OA\Property(
            property: 'email', type: 'string', format: 'email',
            example: 'ivan@example.com', nullable: true, maxLength: 255
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-21T10:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-05-21T10:00:00.000000Z'),
    ]
)]
#[OA\Schema(
    schema: 'PaginationLinks',
    properties: [
        new OA\Property(property: 'first', type: 'string', example: 'http://localhost:8080/api/v1/subscribers?page=1', nullable: true),
        new OA\Property(property: 'last', type: 'string', example: 'http://localhost:8080/api/v1/subscribers?page=1', nullable: true),
        new OA\Property(property: 'prev', type: 'string', example: null, nullable: true),
        new OA\Property(property: 'next', type: 'string', example: null, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PaginationMeta',
    required: ['current_page', 'last_page', 'per_page', 'total', 'path'],
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'from', type: 'integer', example: 1, nullable: true),
        new OA\Property(property: 'last_page', type: 'integer', example: 1),
        new OA\Property(
            property: 'links', type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'url', type: 'string', nullable: true),
                    new OA\Property(property: 'label', type: 'string', example: '1'),
                    new OA\Property(property: 'active', type: 'boolean', example: true),
                ]
            )
        ),
        new OA\Property(property: 'path', type: 'string', example: 'http://localhost:8080/api/v1/subscribers'),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'to', type: 'integer', example: 1, nullable: true),
        new OA\Property(property: 'total', type: 'integer', example: 1),
    ]
)]
#[OA\Schema(
    schema: 'PaginatedSubscribers',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(
            property: 'data', type: 'array',
            items: new OA\Items(ref: '#/components/schemas/SubscriberResource')
        ),
        new OA\Property(property: 'links', ref: '#/components/schemas/PaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
    ]
)]
#[OA\Schema(
    schema: 'StoreSubscriberRequest',
    description: 'Тело запроса для создания подписчика. Хотя бы одно из полей phone или email обязательно.',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Иван Петров', nullable: true, maxLength: 120),
        new OA\Property(
            property: 'phone', description: 'Начинается с +, затем цифры. Обязателен, если не указан email.', type: 'string', pattern: '^\+[1-9]\d{6,14}$',
            example: '+79001234567', nullable: true,
            maxLength: 32
        ),
        new OA\Property(
            property: 'email', description: 'Обязателен, если не указан phone.', type: 'string',
            format: 'email', example: 'ivan@example.com', nullable: true,
            maxLength: 255
        ),
    ],
    example: ['name' => 'Иван Петров', 'phone' => '+79001234567', 'email' => 'ivan@example.com']
)]
#[OA\Schema(
    schema: 'UpdateSubscriberRequest',
    description: 'Тело запроса для частичного обновления подписчика (PATCH). Передаются только изменяемые поля. Итоговое состояние должно содержать хотя бы одно из phone или email.',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Иван Иванов', nullable: true, maxLength: 120),
        new OA\Property(
            property: 'phone', description: 'Новый телефон. Начинается с +, затем цифры.', type: 'string', pattern: '^\+[1-9]\d{6,14}$',
            example: '+79001234567', nullable: true,
            maxLength: 32
        ),
        new OA\Property(
            property: 'email', description: 'Новый email-адрес.', type: 'string',
            format: 'email', example: 'ivan@example.com', nullable: true,
            maxLength: 255
        ),
    ],
    example: ['name' => 'Пётр Петров', 'phone' => '+79009876543', 'email' => 'petr@example.com']
)]
#[OA\Schema(
    schema: 'NotificationResource',
    required: ['id', 'subscriber_id', 'channel', 'delivery_profile', 'status', 'message', 'attempts', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '019600a2-7c3b-7000-9f3b-1234567890ab'),
        new OA\Property(property: 'subscriber_id', type: 'string', format: 'uuid', example: '019600a2-7c3b-7000-9f3b-000000000001'),
        new OA\Property(property: 'bulk_request_id', type: 'string', format: 'uuid', example: null, nullable: true),
        new OA\Property(property: 'channel', type: 'string', example: 'email', enum: ['sms', 'email']),
        // Внимание: enum профилей доставки дублирует config/notifications.php.
        // При добавлении нового профиля обновлять руками.
        new OA\Property(property: 'delivery_profile', type: 'string', example: 'transactional', enum: ['transactional', 'marketing']),
        new OA\Property(property: 'status', type: 'string', example: 'delivered', enum: ['queued', 'sent', 'delivered', 'dropped']),
        new OA\Property(property: 'message', type: 'string', example: 'Ваш заказ подтверждён'),
        new OA\Property(property: 'attempts', type: 'integer', example: 1),
        new OA\Property(property: 'last_error', type: 'string', example: null, nullable: true),
        new OA\Property(property: 'idempotency_key', type: 'string', example: null, nullable: true, maxLength: 64),
        new OA\Property(property: 'provider_message_id', type: 'string', example: 'msg_abc123', nullable: true, maxLength: 120),
        new OA\Property(property: 'queued_at', type: 'string', format: 'date-time', example: '2026-05-22T10:00:00.000000Z', nullable: true),
        new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', example: '2026-05-22T10:00:01.000000Z', nullable: true),
        new OA\Property(property: 'delivered_at', type: 'string', format: 'date-time', example: '2026-05-22T10:00:05.000000Z', nullable: true),
        new OA\Property(property: 'dropped_at', type: 'string', format: 'date-time', example: null, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-22T10:00:00.000000Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-05-22T10:00:05.000000Z'),
    ]
)]
#[OA\Schema(
    schema: 'PaginatedNotifications',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(
            property: 'data', type: 'array',
            items: new OA\Items(ref: '#/components/schemas/NotificationResource')
        ),
        new OA\Property(property: 'links', ref: '#/components/schemas/PaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
    ]
)]
#[OA\Schema(
    schema: 'BulkSendNotificationRequest',
    required: ['channel', 'delivery_profile', 'message', 'subscriber_ids'],
    properties: [
        new OA\Property(property: 'channel', type: 'string', example: 'email', enum: ['sms', 'email']),
        // Внимание: enum профилей доставки дублирует config/notifications.php.
        // При добавлении нового профиля обновлять руками.
        new OA\Property(property: 'delivery_profile', type: 'string', example: 'transactional', enum: ['transactional', 'marketing']),
        new OA\Property(property: 'message', type: 'string', example: 'Ваш заказ подтверждён', maxLength: 1000, minLength: 1),
        new OA\Property(
            property: 'subscriber_ids',
            description: 'Список UUID подписчиков (уникальные). Несуществующие не приводят к 422, а возвращаются в invalid_subscriber_ids.',
            type: 'array',
            items: new OA\Items(type: 'string', format: 'uuid'),
            maxItems: 500,
            minItems: 1
        ),
    ]
)]
#[OA\Schema(
    schema: 'BulkAcceptedResponse',
    required: ['bulk_request_id', 'accepted', 'invalid_subscriber_ids'],
    properties: [
        new OA\Property(property: 'bulk_request_id', type: 'string', format: 'uuid', example: '019600a2-7c3b-7000-9f3b-aaaaaaaaaaaa'),
        new OA\Property(property: 'accepted', description: 'Число принятых к рассылке (существующих) подписчиков', type: 'integer', example: 5),
        new OA\Property(
            property: 'invalid_subscriber_ids',
            description: 'Несуществующие subscriber_ids в порядке запроса',
            type: 'array',
            items: new OA\Items(type: 'string', format: 'uuid')
        ),
    ]
)]
class Annotations {}
