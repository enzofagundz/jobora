<?php

namespace App\Http\Middleware;

use App\Mcp\Support\AgentIntegrationContext;
use App\Models\User;
use App\Services\AgentIntegrationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentIntegration
{
    public function __construct(private readonly AgentIntegrationService $integrations) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->denied('invalid_token', 'Token de acesso inválido.');
        }

        if ($user->disabled_at !== null) {
            return $this->denied('invalid_token', 'Conta desabilitada.');
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof AccessToken || ! $token->can('mcp:use')) {
            return $this->denied('insufficient_scope', 'A integração não possui o escopo mcp:use.', 403);
        }

        $integration = $this->integrations->for($user, (string) $token->oauth_client_id);

        if ($integration === null || $integration->isRevoked()) {
            return $this->denied('insufficient_scope', 'Integração inexistente ou revogada.', 403);
        }

        app()->instance(
            AgentIntegrationContext::class,
            new AgentIntegrationContext($user, $integration),
        );

        return $next($request);
    }

    private function denied(string $error, string $description, int $status = 401): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'error_description' => $description,
        ], $status);
    }
}
