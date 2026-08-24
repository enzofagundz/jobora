<?php

namespace App\Services;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\JobPostingStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobCapture;
use App\Models\JobPosting;
use Illuminate\Support\Collection;

/**
 * Invariante único de promoção de captura → vaga (criação + análise +
 * vínculo), reutilizado pela página de view e pelo bulk action da listagem.
 */
final class JobPromotionService
{
    public function promote(JobCapture $capture, int $userId): JobPosting
    {
        if (mb_strlen((string) $capture->description) < JobAnalysisService::MIN_DESCRIPTION_LENGTH) {
            throw new \LogicException('Descrição insuficiente para criar vaga (mínimo de '.JobAnalysisService::MIN_DESCRIPTION_LENGTH.' caracteres).');
        }

        $posting = JobPosting::create([
            'description' => $capture->description,
            'source_url' => $capture->url,
            'collected_at' => $capture->captured_at ?? now(),
            'status' => JobPostingStatus::Pending,
        ]);

        AnalyzeJobPosting::dispatch($posting, $userId);

        $capture->update(['job_posting_id' => $posting->id]);

        return $posting;
    }

    /**
     * Promove todas as capturas elegíveis de uma seleção; retorna quantas
     * vagas foram criadas.
     *
     * @param  Collection<int, JobCapture>  $captures
     */
    public function promoteEligible(Collection $captures, int $userId): int
    {
        $eligible = $captures->filter(fn (JobCapture $c): bool => $c->job_posting_id === null
            && $c->triage_status === JobCaptureTriageStatus::Completed
            && in_array($c->triage_result, [JobCaptureTriageResult::Relevant, JobCaptureTriageResult::Uncertain], true)
            && mb_strlen((string) $c->description) >= JobAnalysisService::MIN_DESCRIPTION_LENGTH);

        $promoted = 0;

        foreach ($eligible as $capture) {
            $this->promote($capture, $userId);
            $promoted++;
        }

        return $promoted;
    }
}
