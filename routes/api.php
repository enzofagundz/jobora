<?php

use App\Http\Controllers\Api\ExtensionAuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\JobCaptureController;

Route::post('/job-captures', [JobCaptureController::class, 'store'])
    ->middleware('auth.capture-token');

Route::get('/extension/auth/validate', [ExtensionAuthController::class, 'validate'])
    ->middleware('auth.capture-token');
