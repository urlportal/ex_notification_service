<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscribers\StoreSubscriberRequest;
use App\Http\Resources\SubscriberResource;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

#[OA\Post(
    path: '/api/v1/subscribers',
    description: 'Создаёт нового подписчика. Хотя бы одно из полей phone или email обязательно.',
    summary: 'Создать подписчика',
    security: [['bearerAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(ref: '#/components/schemas/StoreSubscriberRequest'),
            example: ['name' => 'Иван Петров', 'phone' => '+79001234567', 'email' => 'ivan@example.com']
        )
    ),
    tags: ['Subscribers'],
    responses: [
        new OA\Response(
            response: 201,
            description: 'Подписчик создан',
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
            response: 422,
            description: 'Ошибка валидации',
            content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')
        ),
    ]
)]
class StoreSubscriberController extends Controller
{
    public function __construct(
        private readonly SubscriberService $service,
    ) {}

    public function __invoke(StoreSubscriberRequest $request): JsonResponse
    {
        $subscriber = $this->service->create($request->toDto());

        return (new SubscriberResource($subscriber))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
