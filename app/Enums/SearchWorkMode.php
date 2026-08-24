<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SearchWorkMode: string implements HasLabel
{
    case Any = 'any';
    case Onsite = 'onsite';
    case Remote = 'remote';
    case Hybrid = 'hybrid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Any => 'Qualquer modalidade',
            self::Onsite => 'Presencial',
            self::Remote => 'Remoto',
            self::Hybrid => 'Híbrido',
        };
    }
}
