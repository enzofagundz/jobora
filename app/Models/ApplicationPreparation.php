<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ApplicationPreparation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'pending' => 'encrypted:array', 'source_versions' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('O snapshot final de preparação é imutável.');
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(DocumentArtifact::class, 'preparation_id');
    }
}
