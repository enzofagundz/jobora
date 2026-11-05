<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionAuthController extends Controller
{
    /**
     * Validação da credencial da extensão. O middleware auth.capture-token
     * já rejeitou token inválido (401) e API não configurada (503); chegar
     * aqui significa Bearer correto.
     */
    public function validate(Request $request): JsonResponse
    {
        return response()->json([
            'valid' => true,
            'owner_user_id' => (int) $request->user()->id,
        ]);
    }
}
