<?php

namespace App\Support;

use App\Enums\ResumeLanguage;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\ScreeningPreference;
use App\Services\AiInputSnapshotService;
use Illuminate\Support\Facades\DB;

/**
 * Checks if derived AI results are stale relative to their inputs.
 * Uses input fingerprints for asynchronous results and timestamps as a legacy
 * fallback for results created before fingerprints were introduced.
 */
final class Staleness
{
    /**
     * Resume adaptation is stale if the resume was updated after the adaptation
     * or if the vacancy's items changed after the adaptation.
     */
    public static function isAdaptationStale(ResumeAdaptation $adaptation): bool
    {
        $posting = $adaptation->jobPosting;
        $user = $posting?->user;

        if (filled($adaptation->source_fingerprint) && $posting !== null && $user !== null) {
            try {
                $current = app(AiInputSnapshotService::class)->resumeAdaptation($user, $posting, $adaptation->language);

                return ! hash_equals($adaptation->source_fingerprint, $current['fingerprint']);
            } catch (\Throwable) {
                return true;
            }
        }

        $resume = $user?->resumes()->where('language', $adaptation->language->value)->first();

        // Fallback: if adaptation has no posting/user, fallback to null check
        if ($resume !== null && $adaptation->updated_at->lt($resume->updated_at)) {
            return true;
        }

        $latestItem = $posting?->items()->max('updated_at');

        if ($latestItem !== null && $adaptation->updated_at->lt($latestItem)) {
            return true;
        }

        return false;
    }

    /**
     * The EN version is stale when the canonical PT-BR resume changed after
     * the translation that produced it. Non-EN rows are never stale.
     */
    public static function isResumeTranslationStale(Resume $resume): bool
    {
        if ($resume->language !== ResumeLanguage::En) {
            return false;
        }

        $user = $resume->user;

        if ($user === null) {
            return false;
        }

        if (filled($resume->source_fingerprint)) {
            try {
                $current = app(AiInputSnapshotService::class)->resumeTranslation($user);

                return ! hash_equals($resume->source_fingerprint, $current['fingerprint']);
            } catch (\Throwable) {
                return true;
            }
        }

        $ptBr = $user->resumes()->where('language', ResumeLanguage::PtBr->value)->first();

        return $ptBr !== null && $resume->updated_at->lt($ptBr->updated_at);
    }

    /**
     * Strategic classification (per row) is stale if the resume or the market
     * changed after the classification.
     */
    public static function isCompetencyStale(ProfessionalCompetency $competency): bool
    {
        $user = $competency->user;

        if (filled($competency->source_fingerprint) && $user !== null) {
            try {
                $current = app(AiInputSnapshotService::class)->strategicCompetency($user);

                return ! hash_equals($competency->source_fingerprint, $current['fingerprint']);
            } catch (\Throwable) {
                return true;
            }
        }

        $resume = $user?->resumes()->where('language', ResumeLanguage::PtBr->value)->first();

        if ($resume !== null && $competency->updated_at->lt($resume->updated_at)) {
            return true;
        }

        $userId = $competency->user_id;

        $latestMarket = DB::table('job_posting_items')
            ->join('job_postings', 'job_postings.id', '=', 'job_posting_items.job_posting_id')
            ->where('job_postings.status', 'confirmed')
            ->where('job_postings.user_id', $userId)
            ->max('job_posting_items.updated_at');

        if ($latestMarket !== null && $competency->updated_at->lt($latestMarket)) {
            return true;
        }

        // Also check if a new confirmed posting appeared after classification
        $latestPosting = JobPosting::where('status', 'confirmed')->where('user_id', $userId)->max('updated_at');

        if ($latestPosting !== null && $competency->updated_at->lt($latestPosting)) {
            return true;
        }

        return false;
    }

    public static function isAtsScoreStale(ResumeAdaptation $adaptation): bool
    {
        if ($adaptation->ats_score === null) {
            return false;
        }

        $posting = $adaptation->jobPosting;

        if (filled($adaptation->ats_source_fingerprint) && $posting !== null) {
            try {
                $current = app(AiInputSnapshotService::class)->atsScore($posting, $adaptation->language);

                return ! hash_equals($adaptation->ats_source_fingerprint, $current['fingerprint']);
            } catch (\Throwable) {
                return true;
            }
        }

        return self::isLegacyAtsResultStale($adaptation);
    }

    public static function isAtsPromptStale(ResumeAdaptation $adaptation): bool
    {
        if (! $adaptation->is_ats_optimized || blank($adaptation->ats_prompt)) {
            return false;
        }

        $posting = $adaptation->jobPosting;
        $user = $posting?->user;

        if ($posting === null || $user === null) {
            return true;
        }

        if (filled($adaptation->ats_prompt_fingerprint)) {
            try {
                $current = app(AiInputSnapshotService::class)->atsOptimization($user, $posting, true, $adaptation->language);

                return ! hash_equals($adaptation->ats_prompt_fingerprint, $current['fingerprint']);
            } catch (\Throwable) {
                return true;
            }
        }

        return self::isLegacyAtsResultStale($adaptation);
    }

    private static function isLegacyAtsResultStale(ResumeAdaptation $adaptation): bool
    {
        $posting = $adaptation->jobPosting;
        $user = $posting?->user;

        if ($posting === null || $user === null) {
            return true;
        }

        $resume = $user->resumes()->where('language', $adaptation->language->value)->first();

        if ($resume !== null && $adaptation->updated_at->lt($resume->updated_at)) {
            return true;
        }

        if ($adaptation->updated_at->lt($posting->updated_at)) {
            return true;
        }

        $latestItem = $posting->items()->max('updated_at');

        return $latestItem !== null && $adaptation->updated_at->lt($latestItem);
    }

    /**
     * Triage result is stale if preferences were updated after the triage.
     * Capture must be completed; otherwise not applicable.
     */
    public static function isTriageStale(JobCapture $capture, ?ScreeningPreference $preference = null): bool
    {
        if ($capture->triage_status->value !== 'completed') {
            return false;
        }

        $pref = $preference ?? $capture->user?->screeningPreference;

        if ($pref === null) {
            return false;
        }

        return $capture->updated_at->lt($pref->updated_at);
    }
}
