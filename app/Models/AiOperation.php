<?php

namespace App\Models;

use App\Enums\AiOperationStatus;
use App\Enums\AiOperationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiOperation extends Model
{
    protected $fillable = [
        'user_id',
        'operation_key',
        'operation_type',
        'target_type',
        'target_id',
        'job_posting_id',
        'run_token',
        'attempt_token',
        'status',
        'attempts',
        'queued_at',
        'started_at',
        'last_attempt_started_at',
        'completed_at',
        'failed_at',
        'expires_at',
        'input_snapshot',
        'input_fingerprint',
        'failure_code',
        'notified_at',
    ];

    protected $hidden = [
        'input_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'operation_type' => AiOperationType::class,
            'status' => AiOperationStatus::class,
            'input_snapshot' => 'encrypted:array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'last_attempt_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
            'notified_at' => 'datetime',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AiOperationStatus::Queued->value,
            AiOperationStatus::Running->value,
        ]);
    }

    public function isActive(): bool
    {
        return $this->status?->isActive() ?? false;
    }
}
