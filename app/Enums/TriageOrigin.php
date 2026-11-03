<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TriageOrigin: string implements HasLabel
{
    case Prefilter = 'prefilter';
    case Llm = 'llm';

    public function getLabel(): string
    {
        return match ($this) {
            self::Prefilter => 'Pré-filtro Clef',
            self::Llm => 'Triagem por IA',
        };
    }
}
