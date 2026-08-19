<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobCapture extends Model
{
    use HasFactory;

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
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
