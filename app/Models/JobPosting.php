<?php

namespace App\Models;

use App\Enums\JobPostingStatus;
use App\Enums\ResumeLanguage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

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
        'readiness',
        'opportunity_version',
        'location',
        'work_mode',
        'employment_type',
        'salary_min',
        'salary_max',
        'salary_currency',
        'salary_period',
        'seniority',
        'requirements',
        'technologies',
        'evidence',
        'review_reasons',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'status' => JobPostingStatus::class,
            'suggested_data' => 'array',
            'opportunity_version' => 'integer',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'requirements' => 'array',
            'technologies' => 'array',
            'evidence' => 'array',
            'review_reasons' => 'array',
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

    public function resumeAdaptations(): HasMany
    {
        return $this->hasMany(ResumeAdaptation::class);
    }

    /**
     * A adequação de um idioma. Toda leitura declara o idioma explicitamente
     * (ADR 0001); o default pt-BR é resolvido nos pontos de entrada MCP.
     */
    public function resumeAdaptation(ResumeLanguage $language): HasOne
    {
        return $this->hasOne(ResumeAdaptation::class)->where('language', $language->value);
    }

    public function application(): HasOne
    {
        return $this->hasOne(Application::class);
    }

    public function publications(): BelongsToMany
    {
        return $this->belongsToMany(Publication::class, 'opportunity_sources')
            ->withPivot('is_legacy')
            ->withTimestamps();
    }

    public function sources(): HasMany
    {
        return $this->hasMany(OpportunitySource::class);
    }

    public function reviewRevisions(): HasMany
    {
        return $this->hasMany(OpportunityReviewRevision::class)->orderBy('to_version');
    }

    public function isStructuredOpportunity(): bool
    {
        return $this->readiness !== null;
    }

    public function isEligibleForApplicationDocuments(): bool
    {
        return $this->readiness !== null
            ? $this->readiness === 'ready'
            : $this->status === JobPostingStatus::Confirmed;
    }

    public function assertLegacyWorkflow(): void
    {
        if ($this->isStructuredOpportunity()) {
            throw new LogicException('Oportunidades estruturadas não aceitam operações de revisão V1.');
        }
    }
}
