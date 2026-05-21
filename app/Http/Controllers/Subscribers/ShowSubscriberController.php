<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriberResource;
use App\Services\SubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

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
