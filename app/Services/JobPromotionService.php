<?php

namespace App\Services;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\JobPostingStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Invariante único de promoção de captura → vaga (criação + análise +
 * vínculo), reutilizado pelas ações da listagem.
 */
final class JobPromotionService
{
    public function promote(User $user, JobCapture $capture): JobPosting
    {
        if ($capture->user_id !== $user->id) {
            throw new \LogicException('Captura não pertence ao usuário.');
        }

        Gate::forUser($user)->authorize('promote', $capture);

        if (mb_strlen((string) $capture->description) < JobAnalysisService::MIN_DESCRIPTION_LENGTH) {
            throw new \LogicException('Descrição insuficiente para criar vaga (mínimo de '.JobAnalysisService::MIN_DESCRIPTION_LENGTH.' caracteres).');
        }

        return DB::transaction(function () use ($user, $capture): JobPosting {
            $fresh = $user->jobCaptures()->whereKey($capture->id)->lockForUpdate()->first();

            if (! $fresh) {
                throw new \LogicException('Captura não encontrada.');
            }

            if ($fresh->job_posting_id !== null) {
                $existing = $user->jobPostings()->whereKey($fresh->job_posting_id)->first();

                if ($existing) {
                    return $existing;
                }
            }

            try {
                $posting = $user->jobPostings()->create([
                    'description' => $fresh->description,
                    'source_url' => $fresh->url,
                    'collected_at' => $fresh->captured_at ?? now(),
                    'status' => JobPostingStatus::Pending,
                ]);

                $fresh->update(['job_posting_id' => $posting->id]);
            } catch (UniqueConstraintViolationException $e) {
                // Race: another transaction promoted this capture
                $fresh->refresh();

                if ($fresh->job_posting_id !== null) {
                    $existing = $user->jobPostings()->whereKey($fresh->job_posting_id)->first();

                    if ($existing) {
                        return $existing;
                    }
                }

                throw $e;
            }

            AnalyzeJobPosting::dispatch($posting, $user->id);

            return $posting;
        });
    }

    /**
     * Promove todas as capturas elegíveis de uma seleção; retorna quantas
     * vagas foram criadas.
     *
     * @param  Collection<int, JobCapture>  $captures
     */
    public function promoteEligible(User $user, Collection $captures): int
    {
        $eligible = $captures->filter(fn (JobCapture $c): bool => $c->user_id === $user->id
            && $c->job_posting_id === null
            && $c->triage_status === JobCaptureTriageStatus::Completed
            && in_array($c->triage_result, [JobCaptureTriageResult::Relevant, JobCaptureTriageResult::Uncertain], true)
            && mb_strlen((string) $c->description) >= JobAnalysisService::MIN_DESCRIPTION_LENGTH);

        $promoted = 0;

        foreach ($eligible as $capture) {
            $this->promote($user, $capture);
            $promoted++;
        }

        return $promoted;
    }
}
