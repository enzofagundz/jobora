<?php

namespace App\Policies;

use App\Models\JobCapture;
use App\Models\User;

/**
 * Fronteira de autorização explícita do JobCapture.
 *
 * ponytail: single-user — todos os métodos permitem. O valor desta policy é
 * (a) tornar explícita a fronteira de autorização do Resource, das ações
 * custom (triagem e promoção) e das operações em lote do Filament (que
 * delegam a policies via `get_authorization_response`), e (b) permitir
 * testes de negação e evolução futura (multi-vaga/multi-usuário) sem mudar
 * a estrutura das superfícies.
 */
class JobCapturePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobCapture $capture): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JobCapture $capture): bool
    {
        return true;
    }

    public function delete(User $user, JobCapture $capture): bool
    {
        return true;
    }

    public function triage(User $user, JobCapture $capture): bool
    {
        return true;
    }

    public function promote(User $user, JobCapture $capture): bool
    {
        return true;
    }
}
