<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriberResource;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
