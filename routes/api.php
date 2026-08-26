<?php

use App\Http\Controllers\Api\ExtensionAuthController;
use App\Http\Controllers\Api\JobCaptureController;
use Illuminate\Support\Facades\Route;

Route::post('/job-captures', [JobCaptureController::class, 'store'])
    ->middleware(['throttle:60,1', 'auth.capture-token']);

Route::get('/extension/auth/validate', [ExtensionAuthController::class, 'validate'])
    ->middleware(['throttle:30,1', 'auth.capture-token']);
