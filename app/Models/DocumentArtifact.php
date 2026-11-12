<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DocumentArtifact extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['path', 'user_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'requires_ats_authorization' => 'boolean', 'bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $artifact): void {
            if (array_diff(array_keys($artifact->getDirty()), ['revoked_at', 'updated_at']) !== []) {
                throw new LogicException('Os bytes e metadados do artefato são imutáveis.');
            }
        });
    }
}
