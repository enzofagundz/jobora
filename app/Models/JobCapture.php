<?php

namespace App\Models;

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Hidden(['user_id', 'deleted_at'])]
class JobCapture extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'source',
        'external_id',
        'dedup_key',
        'url',
        'search_url',
        'title',
        'description',
        'company',
        'location',
        'author_name',
        'author_profile_url',
        'captured_at',
        'job_posting_id',
        'triage_status',
        'triage_result',
        'triage_reason',
        'triage_error',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'triage_status' => JobCaptureTriageStatus::class,
            'triage_result' => JobCaptureTriageResult::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
