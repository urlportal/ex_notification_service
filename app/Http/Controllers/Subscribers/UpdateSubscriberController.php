<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscribers\UpdateSubscriberRequest;
use App\Http\Resources\SubscriberResource;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

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
