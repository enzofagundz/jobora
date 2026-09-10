<?php

namespace App\Services;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Criação e exclusão manuais de vagas (fora do fluxo de captura). Mantém as
 * mesmas regras do cadastro pelo painel: status pending e collected_at = now.
 */
final class JobPostingService
{
    public function create(User $user, string $description, ?string $sourceUrl = null): JobPosting
    {
        $sourceUrl = $sourceUrl !== null ? trim($sourceUrl) : null;

        return $user->jobPostings()->create([
            'description' => $description,
            'source_url' => $sourceUrl === '' ? null : $sourceUrl,
            'collected_at' => now(),
            'status' => JobPostingStatus::Pending,
        ]);
    }

    public function delete(User $user, JobPosting $posting): void
    {
        Gate::forUser($user)->authorize('delete', $posting);

        $posting->delete();
    }
}
