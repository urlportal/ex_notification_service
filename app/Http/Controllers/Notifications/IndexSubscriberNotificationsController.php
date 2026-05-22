<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\IndexSubscriberNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationQueryService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Get(
    path: '/api/v1/subscribers/{id}/notifications',
    description: 'Возвращает постраничный список уведомлений подписчика с опциональной фильтрацией по статусу, каналу и приоритету.',
    summary: 'Получить уведомления подписчика',
    security: [['bearerAuth' => []]],
    tags: ['Notifications'],
    parameters: [
        new OA\Parameter(
            name: 'id',
            description: 'UUID подписчика',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid', example: '019600a2-7c3b-7000-9f3b-000000000001')
        ),
        new OA\Parameter(
            name: 'status',
            description: 'Фильтр по статусу (одно значение)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string', enum: ['queued', 'sent', 'delivered', 'dropped'], example: 'sent')
        ),
        new OA\Parameter(
            name: 'channel',
            description: 'Фильтр по каналу (одно значение)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string', enum: ['sms', 'email'], example: 'email')
        ),
        new OA\Parameter(
            name: 'priority',
            description: 'Фильтр по приоритету (одно значение)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string', enum: ['transactional', 'marketing'], example: 'transactional')
        ),
        new OA\Parameter(
            name: 'per_page',
            description: 'Количество записей на странице (1–100)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15, example: 15)
        ),
        new OA\Parameter(
            name: 'page',
            description: 'Номер страницы',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'integer', minimum: 1, default: 1, example: 1)
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Список уведомлений',
            content: new OA\JsonContent(ref: '#/components/schemas/PaginatedNotifications')
        ),
        new OA\Response(
            response: 401,
            description: 'Не авторизован',
            content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedResponse')
        ),
        new OA\Response(
            response: 404,
            description: 'Подписчик не найден',
            content: new OA\JsonContent(ref: '#/components/schemas/NotFoundResponse')
        ),
        new OA\Response(
            response: 422,
            description: 'Ошибка валидации',
            content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')
        ),
    ]
)]
class IndexSubscriberNotificationsController extends Controller
{
    public function __construct(
        private readonly NotificationQueryService $service,
    ) {}

    public function __invoke(IndexSubscriberNotificationsRequest $request, string $id): JsonResponse
    {
        $perPage = $request->integer('per_page', 15);
        $filters = array_filter($request->only(['status', 'channel', 'priority']));

        $paginator = $this->service->paginateForSubscriber($id, $filters, $perPage);

        return NotificationResource::collection($paginator)->response();
    }
}
