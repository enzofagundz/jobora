<?php

use App\Http\Controllers\Api\JobCaptureController;
use Illuminate\Support\Facades\Route;

Route::post('/job-captures', [JobCaptureController::class, 'store'])
    ->middleware('auth.capture-token');
