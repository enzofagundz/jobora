<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SearchOperator: string implements HasLabel
{
    case All = 'and';
    case Any = 'or';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'Todos os termos',
            self::Any => 'Qualquer termo',
        };
    }
}
