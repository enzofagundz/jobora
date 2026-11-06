<?php

use App\Http\Controllers\DocumentArtifactController;
use Illuminate\Support\Facades\Route;

Route::get('/document-artifacts/{artifact}', DocumentArtifactController::class)
    ->middleware(['auth', 'signed'])
    ->name('documents.session.download');

// A raiz é o painel Filament (path('') no AdminPanelProvider).
// A rota de download do PDF da F6 vive no painel (authenticatedRoutes do
// AdminPanelProvider) para herdar o middleware de autenticação do Filament.
