<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Critérios de triagem do profissional (preferências e rejeições).
 *
 * Uma linha por usuário; as listas são texto livre, sem vocabulário
 * fixo — o conteúdo depende da área do profissional.
 */
class ScreeningPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'preferences',
        'rejections',
    ];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
            'rejections' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
