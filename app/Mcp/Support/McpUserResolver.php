<?php

namespace App\Mcp\Support;

use App\Models\User;

/**
 * Resolve o usuário dono dos dados acessados pelo MCP a partir de
 * config('jobora.mcp.user') — e-mail ou id numérico. Todas as tools operam
 * como esse usuário; nenhuma tool aceita user_id.
 */
final class McpUserResolver
{
    private ?User $resolved = null;

    private ?string $resolvedKey = null;

    public function resolve(): User
    {
        $key = trim((string) config('jobora.mcp.user'));

        if ($this->resolved !== null && $this->resolvedKey === $key) {
            return $this->resolved;
        }

        if ($key === '') {
            throw ToolException::configuration(
                'JOBORA_MCP_USER não está definido.',
                'Defina JOBORA_MCP_USER com o e-mail ou o id do usuário dono dos dados.',
            );
        }

        $user = ctype_digit($key)
            ? User::query()->whereKey((int) $key)->first()
            : User::query()->where('email', $key)->first();

        if ($user === null) {
            throw ToolException::configuration(
                'JOBORA_MCP_USER não corresponde a um usuário existente.',
            );
        }

        if ($user->disabled_at !== null) {
            throw ToolException::configuration(
                'O usuário configurado em JOBORA_MCP_USER está desabilitado.',
            );
        }

        $this->resolvedKey = $key;

        return $this->resolved = $user;
    }

    public function forget(): void
    {
        $this->resolved = null;
        $this->resolvedKey = null;
    }
}
