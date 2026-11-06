<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MatchingEvaluation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confirmed_version' => 'integer', 'opportunity_version' => 'integer',
            'frozen' => 'encrypted:array', 'result' => 'encrypted:array', 'provenance_warnings' => 'encrypted:array',
            'prompt_tokens' => 'integer', 'completion_tokens' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Uma avaliação de matching persistida é imutável.');
        });
    }
}
