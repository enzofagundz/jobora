<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ExtensionAuthController extends Controller
{
    /**
     * Validação da credencial da extensão. O middleware auth.capture-token
     * já rejeitou token inválido (401) e API não configurada (503); chegar
     * aqui significa Bearer correto.
     */
    public function validate(): JsonResponse
    {
        return response()->json(['valid' => true]);
    }
}
