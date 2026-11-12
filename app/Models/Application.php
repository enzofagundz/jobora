<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_posting_id',
        'status',
        'current_preparation_id',
        'preparation_version',
    ];

    protected $attributes = [
        'status' => ApplicationStatus::Planned->value,
        'preparation_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'preparation_version' => 'integer',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class);
    }
}
