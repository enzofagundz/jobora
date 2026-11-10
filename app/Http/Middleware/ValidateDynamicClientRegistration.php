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
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $uri) === 1) {
            return false;
        }

        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = mb_strtolower($parts['scheme']);
        $host = mb_strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        if (($path !== '' && ! str_starts_with($path, '/')) || str_contains($path, '..')) {
            return false;
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return false;
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $host !== '' && in_array($scheme, config('mcp.custom_schemes', []), true);
        }

        if ($path === '') {
            return false;
        }

        foreach (config('mcp.redirect_domains', []) as $domain) {
            if ($domain === '*') {
                return true;
            }

            $allowed = parse_url($domain);

            if ($allowed === false || ! isset($allowed['scheme'], $allowed['host'])) {
                continue;
            }

            if ($scheme !== mb_strtolower($allowed['scheme']) || $host !== mb_strtolower($allowed['host'])) {
                continue;
            }

            if (in_array($host, self::HOSTS, true)) {
                return true;
            }

            $defaultPort = $scheme === 'https' ? 443 : 80;

            if (($parts['port'] ?? $defaultPort) === ($allowed['port'] ?? $defaultPort)) {
                return true;
            }
        }

        return false;
    }

    private function invalid(): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_redirect_uri',
            'error_description' => 'redirect_uris devem usar domínios ou esquemas permitidos, sem credenciais, fragmento ou host ambíguo.',
        ], 400);
    }
}
