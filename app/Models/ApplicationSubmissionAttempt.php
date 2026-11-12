<?php

namespace App\Models;

use App\Enums\ApplicationSubmissionState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ApplicationSubmissionAttempt extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['request_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['state' => ApplicationSubmissionState::class, 'revision' => 'integer',
            'reserved_at' => 'immutable_datetime', 'reservation_expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime',
            'execute_before' => 'immutable_datetime', 'result_due_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationSubmissionEvent::class, 'attempt_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            $mutable = ['state', 'revision', 'active_application_id', 'consumed_approval_id', 'consumed_at', 'execute_before', 'result_due_at', 'resolved_at', 'updated_at'];
            if (array_diff(array_keys($attempt->getDirty()), $mutable) !== []
                || ($attempt->getOriginal('consumed_approval_id') !== null && $attempt->isDirty('consumed_approval_id'))) {
                throw new LogicException('A identidade e o consumo da tentativa são imutáveis.');
            }
        });
    }
}
