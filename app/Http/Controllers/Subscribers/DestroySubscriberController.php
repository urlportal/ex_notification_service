<?php

namespace App\Http\Controllers\Subscribers;

use App\Http\Controllers\Controller;
use App\Services\SubscriberService;
use Illuminate\Http\Response;

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
