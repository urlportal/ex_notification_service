<?php

use App\Http\Controllers\Callbacks\ProviderCallbackController;
use App\Http\Controllers\Health\HealthLivenessController;
use App\Http\Controllers\Notifications\BulkSendNotificationController;
use App\Http\Controllers\Notifications\IndexSubscriberNotificationsController;
use App\Http\Controllers\Notifications\ShowNotificationController;
use App\Http\Controllers\Subscribers\DestroySubscriberController;
use App\Http\Controllers\Subscribers\IndexSubscribersController;
use App\Http\Controllers\Subscribers\ShowSubscriberController;
use App\Http\Controllers\Subscribers\StoreSubscriberController;
use App\Http\Controllers\Subscribers\UpdateSubscriberController;
use App\Services\Callbacks\CallbackAdapterRegistry;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', HealthLivenessController::class);

Route::post('/callbacks/{adapter}', ProviderCallbackController::class)
    ->middleware('callback.signature')
    ->whereIn('adapter', app(CallbackAdapterRegistry::class)->keys());

Route::prefix('v1')->group(function () {
    Route::middleware('api.token')->group(function () {
        Route::post('/subscribers', StoreSubscriberController::class);
        Route::get('/subscribers', IndexSubscribersController::class);
        Route::get('/subscribers/{id}', ShowSubscriberController::class);
        Route::patch('/subscribers/{id}', UpdateSubscriberController::class);
        Route::delete('/subscribers/{id}', DestroySubscriberController::class);

        Route::get('/subscribers/{id}/notifications', IndexSubscriberNotificationsController::class);
        Route::get('/notifications/{id}', ShowNotificationController::class);
        Route::post('/notifications/bulk', BulkSendNotificationController::class);
    });
});
