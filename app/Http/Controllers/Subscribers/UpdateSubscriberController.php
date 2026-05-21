<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscribers\UpdateSubscriberRequest;
use App\Http\Resources\SubscriberResource;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Patch(
    path: '/api/v1/subscribers/{id}',
    description: 'Обновляет указанные поля подписчика (PATCH). Итоговое состояние должно содержать хотя бы одно из phone или email.',
    summary: 'Частичное обновление подписчика',
    security: [['bearerAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(ref: '#/components/schemas/UpdateSubscriberRequest'),
            example: ['name' => 'Пётр Петров', 'phone' => '+79009876543', 'email' => 'petr@example.com']
        )
    ),
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
            description: 'Подписчик обновлён',
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
        new OA\Response(
            response: 422,
            description: 'Ошибка валидации',
            content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')
        ),
    ]
)]
class UpdateSubscriberController extends Controller
{
    public function __construct(
        private readonly SubscriberService $service,
    ) {}

    public function __invoke(UpdateSubscriberRequest $request, string $id): JsonResponse
    {
        $subscriber = $this->service->update($id, $request->toDto());

        return (new SubscriberResource($subscriber))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
