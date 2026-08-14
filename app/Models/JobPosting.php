<?php

namespace App\Models;

use App\Enums\JobPostingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    use HasFactory;

    protected $fillable = [
        'description',
        'source_url',
        'collected_at',
        'status',
        'role',
        'company',
        'suggested_data',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'status' => JobPostingStatus::class,
            'suggested_data' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(JobPostingItem::class);
    }
}
