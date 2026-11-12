<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TelegramLinkChallenge extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['challenge_hash', 'telegram_identity'];

    protected function casts(): array
    {
        return ['telegram_identity' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime'];
    }
}
