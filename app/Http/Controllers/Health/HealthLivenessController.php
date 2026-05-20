<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\DTO\Health\LivenessResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class HealthLivenessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dto = new LivenessResponse(
            status: 'pass',
            version: '1.0.0',
            serviceId: config('app.service_id'),
        );

        return response()->json($dto)
            ->header('Content-Type', 'application/health+json')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->setStatusCode(Response::HTTP_OK);
    }
}
