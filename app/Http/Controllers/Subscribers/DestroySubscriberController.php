<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Services\SubscriberService;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Delete(
    path: '/api/v1/subscribers/{id}',
    description: 'Удаляет подписчика по его UUID',
    summary: 'Удалить подписчика',
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
            response: 204,
            description: 'Подписчик удалён'
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
class DestroySubscriberController extends Controller
{
    public function __construct(
        private readonly SubscriberService $service,
    ) {}

    public function __invoke(string $id): Response
    {
        $this->service->delete($id);

        return response()->noContent();
    }
}
