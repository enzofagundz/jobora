<?php

use App\Http\Controllers\Api\DiscoveryRunResolveController;
use App\Http\Controllers\Api\ExtensionAuthController;
use App\Http\Controllers\Api\PageCaptureIngestController;
use App\Http\Controllers\DocumentArtifactController;
use App\Http\Middleware\AuthenticateAgentIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/document-artifacts/{artifact}', DocumentArtifactController::class)
    ->middleware(['auth:api', AuthenticateAgentIntegration::class, 'signed'])
    ->name('documents.api.download');

Route::post('/job-captures', fn (): JsonResponse => response()->json([
    'error' => 'legacy_capture_retired',
    'message' => 'A captura direta V1 foi aposentada. Use a extensão Jobora V2 e envie um envelope de página para /api/page-captures.',
    'replacement' => '/api/page-captures',
], 410))
    ->middleware(['throttle:60,1', 'auth.capture-token']);

Route::get('/extension/auth/validate', [ExtensionAuthController::class, 'validate'])
    ->middleware(['throttle:30,1', 'auth.capture-token']);

Route::post('/discovery-runs/resolve', DiscoveryRunResolveController::class)
    ->middleware(['throttle:60,1', 'auth.capture-token']);

Route::post('/page-captures', PageCaptureIngestController::class)
    ->middleware(['throttle:30,1', 'auth.capture-token']);
