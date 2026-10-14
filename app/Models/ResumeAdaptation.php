<?php

namespace App\Models;

use App\Enums\ResumeLanguage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResumeAdaptation extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'language',
        'adapted_content',
        'changes',
        'covered_keywords',
        'missing_requirements',
        'alerts',
        'source_fingerprint',
        'ats_prompt',
        'ats_prompt_fingerprint',
        'is_ats_optimized',
        'ats_score',
        'ats_rationale',
        'ats_source_fingerprint',
    ];

    protected $attributes = [
        'language' => 'pt-BR',
        'is_ats_optimized' => false,
    ];

    protected function casts(): array
    {
        return [
            'language' => ResumeLanguage::class,
            'changes' => 'array',
            'covered_keywords' => 'array',
            'missing_requirements' => 'array',
            'alerts' => 'array',
            'is_ats_optimized' => 'boolean',
            'ats_score' => 'integer',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
