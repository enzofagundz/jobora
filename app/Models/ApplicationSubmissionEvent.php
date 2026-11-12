<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ApplicationSubmissionEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = ['request_hash', 'evidence', 'actor'];

    protected function casts(): array
    {
        return ['evidence' => 'encrypted:array', 'actor' => 'encrypted:array', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Os eventos de envio são imutáveis.');
        });
        static::deleting(function (): never {
            throw new LogicException('Os eventos de envio são imutáveis.');
        });
    }
}
