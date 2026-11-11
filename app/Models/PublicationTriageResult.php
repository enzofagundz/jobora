<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PublicationTriageResult extends Model
{
    protected $fillable = [
        'ai_operation_id',
        'publication_id',
        'content_version',
        'run_token',
        'attempt_token',
        'attempt',
        'status',
        'provider',
        'model',
        'schema_version',
        'input_fingerprint',
        'output',
        'prompt_tokens',
        'completion_tokens',
        'error_code',
        'clef_result_id',
        'clef_status',
        'clef_bypassed',
        'clef_bypass_reason',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'content_version' => 'integer',
            'attempt' => 'integer',
            'output' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'clef_bypassed' => 'boolean',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(AiOperation::class, 'ai_operation_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function clefResult(): BelongsTo
    {
        return $this->belongsTo(PublicationClefResult::class, 'clef_result_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function jobPostings(): BelongsToMany
    {
        return $this->belongsToMany(JobPosting::class, 'opportunity_sources')
            ->withPivot(['publication_content_version', 'evidence_block_ids', 'evidence'])
            ->withTimestamps();
    }
}
