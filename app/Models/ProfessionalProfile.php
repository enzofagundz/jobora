<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalProfile extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'draft' => 'encrypted:array',
            'confirmed_version' => 'integer',
            'confirmed_snapshot' => 'encrypted:array',
            'confirmed_at' => 'immutable_datetime',
            'confirmed_actor' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
