<?php

namespace App\Http\Middleware;

use App\Services\CaptureTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCaptureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = trim((string) $request->bearerToken());

        if ($provided === '') {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        $user = app(CaptureTokenService::class)->findUserByToken($provided);

        if (! $user) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        $request->setUserResolver(fn (): mixed => $user);
        $request->attributes->set('capture_user', $user);

        return $next($request);
    }
}
