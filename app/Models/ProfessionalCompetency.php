<?php

namespace App\Models;

use App\Enums\ProfessionalCompetencySource;
use App\Enums\StrategicRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Papel estratégico de uma competência dentro do perfil profissional.
 *
 * O conceito é uma string canônica/normalizada, não uma FK: o projeto não
 * possui tabela de conceitos — o ConceptNormalizer é a autoridade.
 * Presença no currículo e lacuna são dimensões separadas, calculadas na
 * leitura pelo ResumeAnalyzer; nada disso é persistido aqui.
 */
class ProfessionalCompetency extends Model
{
    use HasFactory;

    public const MAX_NAME_LENGTH = 100;

    protected $fillable = [
        'user_id',
        'concept',
        'strategic_role',
        'source',
        'source_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'strategic_role' => StrategicRole::class,
            'source' => ProfessionalCompetencySource::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
