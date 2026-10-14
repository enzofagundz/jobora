<?php

namespace App\Mcp\Support;

use App\Models\ResumeAdaptation;

/**
 * Representação pública de uma adaptação de currículo nas respostas MCP.
 */
final class ResumeAdaptationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(ResumeAdaptation $adaptation): array
    {
        return [
            'job_posting_id' => $adaptation->job_posting_id,
            'language' => $adaptation->language?->value,
            'adapted_content' => $adaptation->adapted_content,
            'changes' => $adaptation->changes ?? [],
            'covered_keywords' => $adaptation->covered_keywords ?? [],
            'missing_requirements' => $adaptation->missing_requirements ?? [],
            'alerts' => $adaptation->alerts ?? [],
            'is_ats_optimized' => (bool) $adaptation->is_ats_optimized,
            'ats_score' => $adaptation->ats_score,
            'ats_rationale' => $adaptation->ats_rationale,
            'updated_at' => $adaptation->updated_at?->toIso8601String(),
        ];
    }
}
