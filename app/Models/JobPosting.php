<?php

namespace App\Models;

use App\Enums\JobPostingStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Hidden(['user_id'])]
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(JobPostingItem::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(JobIaAttempt::class)->orderBy('attempt_no');
    }

    public function latestAttempt(): ?JobIaAttempt
    {
        return $this->attempts()->latest('id')->first();
    }

    public function latestAttemptNo(): int
    {
        return (int) ($this->attempts()->max('attempt_no') ?? 0);
    }

    public function nextAttemptNo(): int
    {
        return $this->latestAttemptNo() + 1;
    }

    public function lastAttemptAt(): ?CarbonInterface
    {
        return $this->latestAttempt()?->created_at;
    }

    public function applicationMethods(): HasMany
    {
        return $this->hasMany(ApplicationMethod::class);
    }

    public function resumeAdaptation(): HasOne
    {
        return $this->hasOne(ResumeAdaptation::class);
    }

    public function application(): HasOne
    {
        return $this->hasOne(Application::class);
    }
}
