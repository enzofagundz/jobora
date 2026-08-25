<?php

namespace App\Models;

use App\Enums\ApplicationMethodType;
use App\Enums\ItemSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'type',
        'platform',
        'url',
        'email',
        'phone',
        'instructions',
        'source',
        'dedup_key',
    ];

    protected function casts(): array
    {
        return [
            'type' => ApplicationMethodType::class,
            'source' => ItemSource::class,
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
