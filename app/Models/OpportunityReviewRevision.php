<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OpportunityReviewRevision extends Model
{
    protected $fillable = [
        'job_posting_id',
        'actor_id',
        'from_version',
        'to_version',
        'idempotency_key',
        'input_fingerprint',
        'changes',
        'before_snapshot',
        'after_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'from_version' => 'integer',
            'to_version' => 'integer',
            'changes' => 'array',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Opportunity review revisions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Opportunity review revisions are immutable.'));
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
