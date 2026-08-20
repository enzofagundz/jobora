<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProfessionalCompetencySource: string implements HasLabel
{
    case Ai = 'ai';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ai => 'IA',
            self::Manual => 'Manual',
        };
    }
}
