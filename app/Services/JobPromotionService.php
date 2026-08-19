<?php

namespace App\Services;

use App\Enums\JobPostingStatus;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobCapture;
use App\Models\JobPosting;

/**
 * Invariante único de promoção de captura → vaga (criação + análise +
 * vínculo), reutilizado pela página de view e pelo bulk action da listagem.
 */
final class JobPromotionService
{
    public function promote(JobCapture $capture): JobPosting
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

        AnalyzeJobPosting::dispatch($posting);

        $capture->update(['job_posting_id' => $posting->id]);

        return $posting;
    }
}
