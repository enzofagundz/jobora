<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCaptureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('capture.api_token');

        if ($expected === '') {
            return response()->json(['message' => 'API de captura não configurada (VAGAS_API_TOKEN).'], 503);
        }

        $provided = (string) $request->bearerToken();

        if (! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        return $next($request);
    }
}
