<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriberResource;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Get(
    path: '/api/v1/subscribers',
    description: 'Возвращает постраничный список подписчиков с возможностью фильтрации по номеру телефона и email.',
    summary: 'Получить список подписчиков',
    security: [['bearerAuth' => []]],
    tags: ['Subscribers'],
    parameters: [
        new OA\Parameter(
            name: 'phone',
            description: 'Фильтр по номеру телефона (точное совпадение)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string', example: '+79001234567')
        ),
        new OA\Parameter(
            name: 'email',
            description: 'Фильтр по email-адресу (точное совпадение)',
            in: 'query',
            required: false,
            schema: new OA\Schema(type: 'string', format: 'email', example: 'ivan@example.com')
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
            description: 'Список подписчиков',
            content: new OA\JsonContent(ref: '#/components/schemas/PaginatedSubscribers')
        ),
        new OA\Response(
            response: 401,
            description: 'Не авторизован',
            content: new OA\JsonContent(ref: '#/components/schemas/UnauthorizedResponse')
        ),
    ]
)]
class IndexSubscribersController extends Controller
{
    public function __construct(
        private readonly SubscriberService $service,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filters = array_filter([
            'phone' => $request->query('phone'),
            'email' => $request->query('email'),
        ]);

        $paginator = $this->service->paginate($filters);

        return SubscriberResource::collection($paginator)->response();
    }
}
