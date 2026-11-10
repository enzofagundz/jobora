<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectOAuthConsentFrames
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');

        $current = trim((string) $response->headers->get('Content-Security-Policy'));
        $directive = "frame-ancestors 'none'";

        if (! str_contains($current, 'frame-ancestors')) {
            $response->headers->set(
                'Content-Security-Policy',
                $current === '' ? $directive : $current.'; '.$directive,
            );
        }

        return $response;
    }
}
