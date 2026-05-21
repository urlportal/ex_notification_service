<?php

use App\Http\Controllers\Health\HealthLivenessController;
use App\Http\Controllers\Subscribers\DestroySubscriberController;
use App\Http\Controllers\Subscribers\IndexSubscribersController;
use App\Http\Controllers\Subscribers\ShowSubscriberController;
use App\Http\Controllers\Subscribers\StoreSubscriberController;
use App\Http\Controllers\Subscribers\UpdateSubscriberController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', HealthLivenessController::class);

Route::prefix('v1')->group(function () {
    Route::middleware('api.token')->group(function () {
        Route::post('/subscribers', StoreSubscriberController::class);
        Route::get('/subscribers', IndexSubscribersController::class);
        Route::get('/subscribers/{id}', ShowSubscriberController::class);
        Route::patch('/subscribers/{id}', UpdateSubscriberController::class);
        Route::delete('/subscribers/{id}', DestroySubscriberController::class);
    });
});
