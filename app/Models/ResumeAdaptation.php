<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResumeAdaptation extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'adapted_content',
        'changes',
        'covered_keywords',
        'missing_requirements',
        'alerts',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'covered_keywords' => 'array',
            'missing_requirements' => 'array',
            'alerts' => 'array',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
