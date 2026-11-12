<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ApplicationApproval extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['telegram_identity', 'recorded_actor', 'revoked_actor', 'message_key', 'request_hash'];

    protected function casts(): array
    {
        return ['telegram_identity' => 'encrypted:array', 'recorded_actor' => 'encrypted:array', 'revoked_actor' => 'encrypted:array',
            'approved_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $approval): void {
            if (array_diff(array_keys($approval->getDirty()), ['revoked_at', 'revoked_actor', 'updated_at']) !== []) {
                throw new LogicException('O histórico da aprovação é imutável.');
            }
        });
    }
}
