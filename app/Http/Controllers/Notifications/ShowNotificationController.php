<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Get(
    path: '/api/v1/notifications/{id}',
    description: 'Возвращает полное представление уведомления по его UUID.',
    summary: 'Получить уведомление по UUID',
    security: [['bearerAuth' => []]],
    tags: ['Notifications'],
    parameters: [
        new OA\Parameter(
            name: 'id',
            description: 'UUID уведомления',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid', example: '019600a2-7c3b-7000-9f3b-1234567890ab')
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Данные уведомления',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'data',
                        ref: '#/components/schemas/NotificationResource'
                    ),
                ]
            )
        ),
        new OA\Response(
            response: 401,
            description: 'Не авторизован',
            content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedResponse')
        ),
        new OA\Response(
            response: 404,
            description: 'Уведомление не найдено',
            content: new OA\JsonContent(ref: '#/components/schemas/NotFoundResponse')
        ),
    ]
)]
class ShowNotificationController extends Controller
{
    public function __construct(
        private readonly NotificationQueryService $service,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $notification = $this->service->getNotification($id);

        return (new NotificationResource($notification))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
