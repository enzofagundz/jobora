<?php

use App\Http\Controllers\Api\DiscoveryRunResolveController;
use App\Http\Controllers\Api\ExtensionAuthController;
use App\Http\Controllers\Api\JobCaptureController;
use App\Http\Controllers\Api\PageCaptureIngestController;
use App\Http\Controllers\DocumentArtifactController;
use App\Http\Middleware\AuthenticateAgentIntegration;
use Illuminate\Support\Facades\Route;

Route::get('/document-artifacts/{artifact}', DocumentArtifactController::class)
    ->middleware(['auth:api', AuthenticateAgentIntegration::class, 'signed'])
    ->name('documents.api.download');

Route::post('/job-captures', [JobCaptureController::class, 'store'])
    ->middleware(['throttle:60,1', 'auth.capture-token']);

Route::get('/extension/auth/validate', [ExtensionAuthController::class, 'validate'])
    ->middleware(['throttle:30,1', 'auth.capture-token']);

Route::post('/discovery-runs/resolve', DiscoveryRunResolveController::class)
    ->middleware(['throttle:60,1', 'auth.capture-token']);

Route::post('/page-captures', PageCaptureIngestController::class)
    ->middleware(['throttle:30,1', 'auth.capture-token']);
