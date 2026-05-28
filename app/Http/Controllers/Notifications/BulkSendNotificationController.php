<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\BulkSendNotificationRequest;
use App\Services\BulkNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/notifications/bulk',
    description: 'Принимает массовую рассылку асинхронно: пишет одно сообщение в transactional outbox и сразу отвечает 202. Tolerant ingest — невалидные subscriber_ids не приводят к 422, а возвращаются в invalid_subscriber_ids. Доставка асинхронна; статус смотрите через GET /api/v1/subscribers/{id}/notifications?bulk_request_id=...',
    summary: 'Массовая рассылка уведомлений',
    security: [['bearerAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(ref: '#/components/schemas/BulkSendNotificationRequest'),
            example: [
                'channel' => 'email',
                'priority' => 'transactional',
                'message' => 'Ваш заказ подтверждён',
                'subscriber_ids' => [
                    '019600a2-7c3b-7000-9f3b-000000000001',
                    '019600a2-7c3b-7000-9f3b-000000000002',
                ],
            ]
        )
    ),
    tags: ['Notifications'],
    parameters: [
        new OA\Parameter(
            name: 'Idempotency-Key',
            description: 'UUID ключа идемпотентности (обязателен)',
            in: 'header',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid')
        ),
        new OA\Parameter(
            name: 'X-Request-ID',
            description: 'UUID для сквозной трассировки (опционален; иначе генерируется)',
            in: 'header',
            required: false,
            schema: new OA\Schema(type: 'string', format: 'uuid')
        ),
    ],
    responses: [
        new OA\Response(
            response: 202,
            description: 'Запрос принят к асинхронной обработке',
            content: new OA\JsonContent(ref: '#/components/schemas/BulkAcceptedResponse')
        ),
        new OA\Response(
            response: 401,
            description: 'Не авторизован',
            content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedResponse')
        ),
        new OA\Response(
            response: 409,
            description: 'Запрос с тем же Idempotency-Key ещё обрабатывается',
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Request is being processed'),
            ])
        ),
        new OA\Response(
            response: 422,
            description: 'Ошибка валидации (в т.ч. отсутствует Idempotency-Key или превышен лимит subscriber_ids)',
            content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')
        ),
    ]
)]
class BulkSendNotificationController extends Controller
{
    public function __invoke(
        BulkSendNotificationRequest $request,
        BulkNotificationService $service,
    ): JsonResponse {
        $result = $service->handle($request->toDto());

        return response()->json($result->toArray(), Response::HTTP_ACCEPTED);
    }
}
