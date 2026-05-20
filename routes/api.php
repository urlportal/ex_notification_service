<?php

use App\Http\Controllers\Health\HealthLivenessController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', HealthLivenessController::class);
