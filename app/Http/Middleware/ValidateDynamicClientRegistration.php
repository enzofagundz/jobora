<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateDynamicClientRegistration
{
    private const HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        $redirectUris = $request->input('redirect_uris');

        if (! is_array($redirectUris) || $redirectUris === []) {
            return $this->invalid();
        }

        foreach ($redirectUris as $redirectUri) {
            if (! is_string($redirectUri) || ! $this->isAllowedRedirect($redirectUri)) {
                return $this->invalid();
            }
        }

        return $next($request);
    }

    private function isAllowedRedirect(string $uri): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            return false;
        }

        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(mb_strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $host = mb_strtolower($parts['host']);

        if (! in_array($host, self::HOSTS, true)) {
            return false;
        }

        $path = $parts['path'] ?? '';

        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, '..')) {
            return false;
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return false;
        }

        return true;
    }

    private function invalid(): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_redirect_uri',
            'error_description' => 'redirect_uris devem ser loopback HTTP(S), sem credenciais, fragmento ou host ambíguo.',
        ], 400);
    }
}
