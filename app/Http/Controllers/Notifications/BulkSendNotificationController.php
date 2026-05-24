<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\BulkSendNotificationRequest;
use App\Services\BulkNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class BulkSendNotificationController extends Controller
{
    public function __invoke(
        BulkSendNotificationRequest $request,
        BulkNotificationService $service,
    ): JsonResponse {
        $result = $service->handle($request->toDto());

        return response()->json($result, Response::HTTP_ACCEPTED);
    }
}
