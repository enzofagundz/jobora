<?php

namespace App\Policies;

use App\Models\JobPosting;
use App\Models\User;

/**
 * Fronteira de autorização explícita do JobPosting.
 *
 * ponytail: single-user — todos os métodos permitem. O valor desta policy é
 * (a) tornar explícita a fronteira de autorização do Resource, das ações e
 * das páginas custom do Filament (que delegam a policies via
 * `get_authorization_response`), e (b) permitir testes de negação e evolução
 * futura (multi-vaga/multi-usuário) sem mudar a estrutura das superfícies.
 */
class JobPostingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobPosting $posting): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JobPosting $posting): bool
    {
        return true;
    }

    public function delete(User $user, JobPosting $posting): bool
    {
        return true;
    }
}
