<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriberResource;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Get(
    path: '/api/v1/subscribers/{id}',
    description: 'Возвращает данные конкретного подписчика по его UUID.',
    summary: 'Получить подписчика по UUID',
    security: [['bearerAuth' => []]],
    tags: ['Subscribers'],
    parameters: [
        new OA\Parameter(
            name: 'id',
            description: 'UUID подписчика',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid', example: '019600a2-7c3b-7000-9f3b-1234567890ab')
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Данные подписчика',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'data',
                        ref: '#/components/schemas/SubscriberResource'
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
            description: 'Подписчик не найден',
            content: new OA\JsonContent(ref: '#/components/schemas/NotFoundResponse')
        ),
    ]
)]
class ShowSubscriberController extends Controller
{
    public function __construct(
        private readonly SubscriberService $service,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        $subscriber = $this->service->findById($id);

        return (new SubscriberResource($subscriber))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
