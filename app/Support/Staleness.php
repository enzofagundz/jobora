<?php

namespace App\Support;

use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\ProfessionalCompetency;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\ScreeningPreference;
use Illuminate\Support\Facades\DB;

/**
 * Checks if derived AI results are stale relative to their inputs.
 * Uses updated_at timestamps for the single-user MVP — simple and robust,
 * no extra version column needed.
 */
final class Staleness
{
    /**
     * Resume adaptation is stale if the resume was updated after the adaptation
     * or if the vacancy's items changed after the adaptation.
     */
    public static function isAdaptationStale(ResumeAdaptation $adaptation): bool
    {
        $resume = Resume::query()->first();

        if ($resume !== null && $adaptation->updated_at->lt($resume->updated_at)) {
            return true;
        }

        $latestItem = $adaptation->jobPosting?->items()->max('updated_at');

        if ($latestItem !== null && $adaptation->updated_at->lt($latestItem)) {
            return true;
        }

        return false;
    }

    /**
     * Strategic classification (per row) is stale if the resume or the market
     * changed after the classification.
     */
    public static function isCompetencyStale(ProfessionalCompetency $competency): bool
    {
        $resume = Resume::query()->first();

        if ($resume !== null && $competency->updated_at->lt($resume->updated_at)) {
            return true;
        }

        $latestMarket = DB::table('job_posting_items')
            ->join('job_postings', 'job_postings.id', '=', 'job_posting_items.job_posting_id')
            ->where('job_postings.status', 'confirmed')
            ->max('job_posting_items.updated_at');

        if ($latestMarket !== null && $competency->updated_at->lt($latestMarket)) {
            return true;
        }

        // Also check if a new confirmed posting appeared after classification
        $latestPosting = JobPosting::where('status', 'confirmed')->max('updated_at');

        if ($latestPosting !== null && $competency->updated_at->lt($latestPosting)) {
            return true;
        }

        return false;
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

        $pref = $preference ?? ScreeningPreference::query()->first();

        if ($pref === null) {
            return false;
        }

        return $capture->updated_at->lt($pref->updated_at);
    }
}
