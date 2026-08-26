<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class CaptureTokenService
{
    /**
     * Gera um segredo aleatório para a extensão.
     * 64 caracteres hex (32 bytes) — compatível com a coluna 64.
     */
    public function generatePlainToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function maskedToken(?User $user): string
    {
        if (! $user || ! $user->capture_token_hash) {
            return '';
        }

        $lastFour = $user->capture_token_last_four;

        if ($lastFour) {
            return str_repeat('•', 8).$lastFour;
        }

        return str_repeat('•', 12);
    }

    /**
     * Cria ou substitui o token do usuário e retorna o segredo em texto puro.
     * Deve ser exibido uma única vez.
     */
    public function issueToken(User $user): string
    {
        if ($user->disabled_at !== null) {
            throw new RuntimeException('Usuário desativado não pode gerar credencial.');
        }

        $plain = $this->generatePlainToken();
        $hash = $this->hashToken($plain);

        // Garante unicidade do hash; colisão é extremamente improvável, mas tratamos.
        $attempts = 0;

        while (User::where('capture_token_hash', $hash)->where('id', '!=', $user->id)->exists()) {
            if ($attempts++ > 5) {
                throw new RuntimeException('Falha ao gerar token único. Tente novamente.');
            }

            $plain = $this->generatePlainToken();
            $hash = $this->hashToken($plain);
        }

        $user->forceFill([
            'capture_token_hash' => $hash,
            'capture_token_last_four' => substr($plain, -4),
        ])->save();

        return $plain;
    }

    public function revokeToken(User $user): void
    {
        $user->forceFill([
            'capture_token_hash' => null,
            'capture_token_last_four' => null,
        ])->save();
    }

    public function findUserByToken(string $plainToken): ?User
    {
        $plainToken = trim($plainToken);

        if ($plainToken === '') {
            return null;
        }

        $hash = $this->hashToken($plainToken);

        return User::where('capture_token_hash', $hash)
            ->whereNull('disabled_at')
            ->first();
    }

    public function tokenConfigured(User $user): bool
    {
        return $user->capture_token_hash !== null && $user->capture_token_hash !== '';
    }
}
