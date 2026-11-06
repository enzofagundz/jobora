<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class TelegramIdentityBinding extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['telegram_identity', 'active_identity_key'];

    protected function casts(): array
    {
        return ['telegram_identity' => 'encrypted:array', 'confirmed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $binding): void {
            if (array_diff(array_keys($binding->getDirty()), ['active_user_id', 'active_identity_key', 'revoked_at', 'updated_at']) !== []) {
                throw new LogicException('O histórico do vínculo Telegram é imutável.');
            }
        });
    }
}
