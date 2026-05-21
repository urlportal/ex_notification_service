<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscribers\StoreSubscriberRequest;
use App\Http\Resources\SubscriberResource;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

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
