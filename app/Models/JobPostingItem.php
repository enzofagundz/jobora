<?php

namespace App\Models;

use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobPostingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'category',
        'name',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'category' => ConceptCategory::class,
            'source' => ItemSource::class,
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
